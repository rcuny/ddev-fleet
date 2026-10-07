"""Webhook pure logic: signature check, payload parsing, rule matching, for
Jira (spec: 2026-10-06-FLE-3-jira-webhooks-design.md §2-§3) and Bitbucket
Cloud (spec: 2026-10-06-FLE-11-renovate-hands-off-design.md §4).

`POST /hooks/jira/{project}` and `POST /hooks/bitbucket/{project}` are exempted
from Caddy's interactive auth (Authelia / basic) because Jira and Bitbucket
cannot log in, so the HMAC-SHA256 signature over the raw body is the ONLY
authentication on those routes (Bitbucket signs exactly like Jira, so both
share `verify_signature`). Keep `verify_signature` strict: constant-time
compare, and any malformed header is a plain False so the route can answer 401
without ever raising.

Everything except `WebhookStore` is side-effect free and stdlib-only; the
store (secrets, dedupe markers, delivery log) lives alongside it and the HTTP
wiring in `fleet.daemon`.
"""

import hashlib
import hmac
import json
import os
import secrets
import tempfile
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from fnmatch import fnmatchcase
from pathlib import Path
from typing import TYPE_CHECKING

from fleet.core.errors import FleetError, ValidationError
from fleet.core.registry import BitbucketHookRule, JiraHookRule
from fleet.core.secrets import read_secrets

if TYPE_CHECKING:
    # Type hint only: instances.py is a heavy module and must stay free to
    # import this one later without creating a cycle.
    from fleet.core.instances import FleetPaths

# Daemon-side cap on the request body (Caddy enforces the same 1MB upstream).
MAX_BODY_BYTES = 1024 * 1024

_SIGNATURE_PREFIX = "sha256="
_ISSUE_EVENT_PREFIX = "jira:issue_"


def verify_signature(secret: str, body: bytes, header: str | None) -> bool:
    """True when `header` is `sha256=<hex HMAC-SHA256(secret, body)>` (what Jira
    sends in `X-Hub-Signature`). Hex is case-insensitive; None, a missing
    prefix, another algorithm or non-hex junk all give False, never an error."""
    if not header or not header.startswith(_SIGNATURE_PREFIX):
        return False
    expected = hmac.new(secret.encode("utf-8"), body, hashlib.sha256).hexdigest()
    # Compare as bytes: compare_digest raises TypeError on non-ASCII str, and
    # the header is attacker-controlled.
    received = header[len(_SIGNATURE_PREFIX) :].lower().encode("utf-8")
    return hmac.compare_digest(expected.encode("ascii"), received)


@dataclass(frozen=True)
class JiraEvent:
    """What the route needs from a Jira webhook body. For events that are not
    `jira:issue_*`, only `event` is set."""

    event: str
    issue_key: str | None
    from_status: str | None
    to_status: str | None


def _status_transition(payload: dict) -> tuple[str | None, str | None]:
    """(fromString, toString) of the first `status` changelog item, else
    (None, None). Jira lists every field changed by one edit, and status is not
    necessarily first, so scan. A missing/odd-shaped changelog is not an error:
    plain edits and non-update events simply carry no transition."""
    changelog = payload.get("changelog")
    items = changelog.get("items") if isinstance(changelog, dict) else None
    if not isinstance(items, list):
        return None, None
    for item in items:
        if isinstance(item, dict) and item.get("field") == "status":
            frm, to = item.get("fromString"), item.get("toString")
            return (
                frm if isinstance(frm, str) else None,
                to if isinstance(to, str) else None,
            )
    return None, None


def parse_jira_event(payload: object) -> JiraEvent:
    """Extract the event name, issue key and status transition from a decoded
    Jira webhook body. Raises ValidationError for a body that is not a JSON
    object, lacks a string `webhookEvent`, or is an issue event without a
    string `issue.key` (the route maps these to 400)."""
    if not isinstance(payload, dict):
        raise ValidationError("webhook payload must be a JSON object")
    event = payload.get("webhookEvent")
    if not isinstance(event, str):
        raise ValidationError("webhook payload has no string 'webhookEvent'")
    if not event.startswith(_ISSUE_EVENT_PREFIX):
        return JiraEvent(event, None, None, None)
    issue = payload.get("issue")
    key = issue.get("key") if isinstance(issue, dict) else None
    if not isinstance(key, str):
        raise ValidationError(f"{event} payload has no string 'issue.key'")
    from_status, to_status = _status_transition(payload)
    return JiraEvent(event, key, from_status, to_status)


def _norm(status: str) -> str:
    return status.strip().casefold()


def match_rule(rules: list[JiraHookRule], to_status: str | None) -> JiraHookRule | None:
    """The first rule whose `on_status` equals `to_status`, ignoring case and
    surrounding whitespace on both sides (Jira admins retype status names)."""
    if to_status is None:
        return None
    wanted = _norm(to_status)
    for rule in rules:
        if _norm(rule.on_status) == wanted:
            return rule
    return None


@dataclass(frozen=True)
class BitbucketEvent:
    """What the route needs from a Bitbucket Cloud webhook. `event` is the
    `X-Event-Key` header (e.g. `pullrequest:approved`). For keys that are not
    `pullrequest:*` / `repo:*` (such as `diagnostics:ping`) only `event` is
    set. `branch` is the PR's source branch, or for a commit status its
    `refname` (None when absent). `comment_lines` are the stripped, non-empty
    lines of a PR comment."""

    event: str
    repo: str | None = None
    pr_id: int | None = None
    branch: str | None = None
    state: str | None = None
    comment_lines: tuple[str, ...] = ()


def _dig(payload: object, *keys: str) -> object:
    """`payload[k1][k2]...`, or None as soon as a level is not a dict/missing."""
    for key in keys:
        if not isinstance(payload, dict):
            return None
        payload = payload.get(key)
    return payload


def _str_or_none(value: object) -> str | None:
    return value if isinstance(value, str) and value else None


def parse_bitbucket_event(event_key: str | None, payload: object) -> BitbucketEvent:
    """Extract repo, PR id, branch, commit-status state and comment lines from
    a decoded Bitbucket webhook body. Raises ValidationError for a missing
    `X-Event-Key`, a body that is not a JSON object, or a `pullrequest:*` /
    `repo:*` event lacking the fields its rules need (`repository.full_name`;
    `pullrequest.id` for PR events; `comment.content.raw` for comment events;
    `commit_status.state` for commit-status events) — the route maps these to
    400."""
    if not event_key:
        raise ValidationError("webhook request has no X-Event-Key header")
    if not isinstance(payload, dict):
        raise ValidationError("webhook payload must be a JSON object")
    if not event_key.startswith(("pullrequest:", "repo:")):
        return BitbucketEvent(event_key)
    repo = _str_or_none(_dig(payload, "repository", "full_name"))
    if repo is None:
        raise ValidationError(f"{event_key} payload has no string 'repository.full_name'")
    if event_key.startswith("repo:commit_status_"):
        state = _str_or_none(_dig(payload, "commit_status", "state"))
        if state is None:
            raise ValidationError(f"{event_key} payload has no string 'commit_status.state'")
        return BitbucketEvent(
            event_key,
            repo=repo,
            branch=_str_or_none(_dig(payload, "commit_status", "refname")),
            state=state,
        )
    pr_id = _dig(payload, "pullrequest", "id")
    # bool is an int subclass; a JSON `true` is not a PR id.
    if not isinstance(pr_id, int) or isinstance(pr_id, bool):
        raise ValidationError(f"{event_key} payload has no integer 'pullrequest.id'")
    lines: tuple[str, ...] = ()
    if event_key.startswith("pullrequest:comment_"):
        raw = _dig(payload, "comment", "content", "raw")
        if not isinstance(raw, str):
            raise ValidationError(f"{event_key} payload has no string 'comment.content.raw'")
        lines = tuple(line.strip() for line in raw.splitlines() if line.strip())
    return BitbucketEvent(
        event_key,
        repo=repo,
        pr_id=pr_id,
        branch=_str_or_none(_dig(payload, "pullrequest", "source", "branch", "name")),
        comment_lines=lines,
    )


def match_bitbucket_rule(
    rules: list[BitbucketHookRule], event: BitbucketEvent
) -> BitbucketHookRule | None:
    """The first rule matching `event`: same event key, same repo (ignoring
    case), and every optional filter the rule sets — `branch` (fnmatch glob;
    never matches an event without a branch), `state` (case-insensitive) and
    `comment` (some line of the comment equals it, case-insensitive)."""
    if event.repo is None:
        return None
    for rule in rules:
        if rule.on_event != event.event or rule.repo.casefold() != event.repo.casefold():
            continue
        if rule.branch is not None and (
            event.branch is None or not fnmatchcase(event.branch, rule.branch)
        ):
            continue
        if rule.state is not None and (
            event.state is None or _norm(rule.state) != _norm(event.state)
        ):
            continue
        if rule.comment is not None and _norm(rule.comment) not in {
            _norm(line) for line in event.comment_lines
        }:
            continue
        return rule
    return None


_SEEN_MAX_AGE_SECONDS = 7 * 24 * 3600

# Which webhook a secret / dedupe marker / log belongs to. Jira is the
# original and keeps its on-disk names unchanged (existing files keep working);
# every other source is namespaced so it cannot collide with a Jira project
# name (project names are DNS-label-safe: no ':') or a Jira delivery id.
SOURCE_JIRA = "jira"
SOURCE_BITBUCKET = "bitbucket"
_SOURCES = (SOURCE_JIRA, SOURCE_BITBUCKET)
_PIPELINE_TOKEN_PREFIX = "bitbucket-token:"


class WebhookStore:
    """On-disk state for webhooks: per-project secrets, delivery-id dedupe
    markers and the delivery logs (spec §4.2, §5.1, §5.3). Jira is the default
    `source` everywhere; Bitbucket (FLE-11) shares the secrets file and the
    `seen/` dir under namespaced keys, and has its own `bitbucket.jsonl` log
    next to the Jira one.

    `root` is deliberately neither `$FLEET_HOME/.secrets` (that file is
    fleet.service's EnvironmentFile, so its keys would leak into every
    subprocess) nor `secrets/` (which holds `<project>.env`, so a project named
    `webhooks` would collide). Secrets are read per call so rotation needs no
    daemon restart.
    """

    def __init__(self, root: Path, log_path: Path):
        self.root = root
        self.log_path = log_path

    @classmethod
    def from_paths(cls, paths: "FleetPaths") -> "WebhookStore":
        return cls(paths.webhooks, paths.webhook_log)

    @property
    def _secrets_file(self) -> Path:
        return self.root / "secrets.env"

    @property
    def _seen_dir(self) -> Path:
        return self.root / "seen"

    @property
    def bitbucket_log_path(self) -> Path:
        return self.log_path.with_name("bitbucket.jsonl")

    def _log_file(self, source: str) -> Path:
        if source not in _SOURCES:
            raise ValueError(f"unknown webhook source {source!r}")
        return self.log_path if source == SOURCE_JIRA else self.bitbucket_log_path

    @staticmethod
    def _secret_key(project: str, source: str) -> str:
        """Key of the webhook secret in secrets.env: the bare project name for
        Jira (unchanged), `bitbucket:<project>` for Bitbucket."""
        if source == SOURCE_JIRA:
            return project
        if source == SOURCE_BITBUCKET:
            return f"{SOURCE_BITBUCKET}:{project}"
        raise ValueError(f"unknown webhook source {source!r}")

    @staticmethod
    def _check_project(project: str) -> None:
        if not project or "=" in project or any(c.isspace() for c in project):
            raise ValidationError(f"invalid project name for a webhook secret: {project!r}")

    # --- secrets -----------------------------------------------------------

    def read_secret(self, project: str, source: str = SOURCE_JIRA) -> str | None:
        return read_secrets(self._secrets_file).get(self._secret_key(project, source)) or None

    def create_secret(
        self, project: str, *, rotate: bool = False, source: str = SOURCE_JIRA
    ) -> str:
        """Generate and store a new secret for `project`, keeping the other
        projects' lines. Refuses to replace an existing one unless `rotate`,
        because the old value is configured in Jira (or Bitbucket) and
        replacing it silently would break delivery until that is updated."""
        self._check_project(project)
        key = self._secret_key(project, source)
        existing = read_secrets(self._secrets_file)
        if key in existing and not rotate:
            raise FleetError(
                f"a webhook secret already exists for {project!r}; pass --rotate to replace it"
            )
        secret = secrets.token_urlsafe(32)
        existing[key] = secret
        self._write_secrets(existing)
        return secret

    def read_pipeline_token(self, project: str) -> str | None:
        """The project's Bitbucket access token (scope `pipeline:write` only)
        used by the `run-pipeline` action, or None when none is stored."""
        key = f"{_PIPELINE_TOKEN_PREFIX}{project}"
        return read_secrets(self._secrets_file).get(key) or None

    def write_pipeline_token(self, project: str, token: str) -> None:
        """Store (or replace) the project's Bitbucket pipeline token, keeping
        every other line. Unlike a webhook secret it is issued by Bitbucket,
        so replacing it needs no `--rotate` guard."""
        self._check_project(project)
        if not token or any(c.isspace() for c in token):
            raise ValidationError("the Bitbucket token must be non-empty and contain no whitespace")
        existing = read_secrets(self._secrets_file)
        existing[f"{_PIPELINE_TOKEN_PREFIX}{project}"] = token
        self._write_secrets(existing)

    def _write_secrets(self, entries: dict[str, str]) -> None:
        self.root.mkdir(parents=True, exist_ok=True)
        # mkdir's mode is masked by the umask and ignored for an existing dir.
        os.chmod(self.root, 0o700)
        text = "".join(f"{key}={value}\n" for key, value in entries.items())
        # Temp file in the same directory so os.replace is an atomic rename:
        # the daemon reads this file per request and must never see a partial
        # write. mkstemp creates 0600 already; chmod keeps that explicit.
        fd, tmp_name = tempfile.mkstemp(dir=self.root, prefix=".secrets.env.")
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                fh.write(text)
            os.chmod(tmp_name, 0o600)
            os.replace(tmp_name, self._secrets_file)
        except BaseException:
            Path(tmp_name).unlink(missing_ok=True)
            raise

    # --- dedupe ------------------------------------------------------------

    def _seen_marker(self, project: str, delivery_id: str, source: str) -> Path:
        if source == SOURCE_JIRA:
            key = f"{project}:{delivery_id}"  # unchanged: existing markers stay valid
        elif source in _SOURCES:
            key = f"{source}\0{project}:{delivery_id}"
        else:
            raise ValueError(f"unknown webhook source {source!r}")
        return self._seen_dir / hashlib.sha256(key.encode()).hexdigest()

    def claim_delivery(self, project: str, delivery_id: str, source: str = SOURCE_JIRA) -> bool:
        """True if this (source, project, delivery id) is seen for the first
        time. O_EXCL makes the claim atomic, so two concurrent retries cannot
        both win, and the marker survives daemon restarts."""
        self._seen_dir.mkdir(parents=True, exist_ok=True)
        os.chmod(self._seen_dir, 0o700)
        marker = self._seen_marker(project, delivery_id, source)
        try:
            fd = os.open(marker, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        except FileExistsError:
            return False
        os.close(fd)
        return True

    def release_delivery(self, project: str, delivery_id: str, source: str = SOURCE_JIRA) -> None:
        """Forget a claim, so the sender's retry of a delivery whose action
        failed upstream is processed again instead of answered `duplicate`."""
        self._seen_marker(project, delivery_id, source).unlink(missing_ok=True)

    def prune_seen(
        self, max_age_seconds: int = _SEEN_MAX_AGE_SECONDS, now: float | None = None
    ) -> int:
        """Delete dedupe markers older than `max_age_seconds`; returns how many.
        Jira retries within hours, so a week of memory is ample."""
        cutoff = (time.time() if now is None else now) - max_age_seconds
        removed = 0
        try:
            entries = list(os.scandir(self._seen_dir))
        except FileNotFoundError:
            return 0
        for entry in entries:
            try:
                if entry.stat().st_mtime < cutoff:
                    os.unlink(entry.path)
                    removed += 1
            except FileNotFoundError:
                continue  # pruned concurrently by another request
        return removed

    # --- delivery log ------------------------------------------------------

    def append_log(self, entry: dict, source: str = SOURCE_JIRA) -> None:
        """Append one JSON line. Callers must not pass secrets or request bodies."""
        record = dict(entry)
        record.setdefault("ts", datetime.now(timezone.utc).isoformat(timespec="seconds"))
        log_file = self._log_file(source)
        log_file.parent.mkdir(parents=True, exist_ok=True)
        with log_file.open("a", encoding="utf-8") as fh:
            fh.write(json.dumps(record) + "\n")

    def tail_log(
        self, n: int = 20, project: str | None = None, source: str = SOURCE_JIRA
    ) -> list[dict]:
        """The last `n` entries (oldest first), optionally for one project.
        Lines that are not JSON objects (e.g. a torn write) are skipped."""
        try:
            lines = self._log_file(source).read_text(encoding="utf-8").splitlines()
        except FileNotFoundError:
            return []
        entries: list[dict] = []
        for line in lines:
            try:
                entry = json.loads(line)
            except ValueError:
                continue
            if not isinstance(entry, dict):
                continue
            if project is not None and entry.get("project") != project:
                continue
            entries.append(entry)
        return entries[-n:] if n > 0 else []
