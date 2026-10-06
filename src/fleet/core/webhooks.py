"""Jira webhook pure logic: signature check, payload parsing, rule matching
(spec: 2026-10-06-FLE-3-jira-webhooks-design.md §2-§3).

`POST /hooks/jira/{project}` is exempted from Caddy's interactive auth
(Authelia / basic) because Jira Cloud cannot log in, so the HMAC-SHA256
signature over the raw body is the ONLY authentication on that route. Keep
`verify_signature` strict: constant-time compare, and any malformed header is
a plain False so the route can answer 401 without ever raising.

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
from pathlib import Path
from typing import TYPE_CHECKING

from fleet.core.errors import FleetError, ValidationError
from fleet.core.registry import JiraHookRule
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


_SEEN_MAX_AGE_SECONDS = 7 * 24 * 3600


class WebhookStore:
    """On-disk state for Jira webhooks: per-project secrets, delivery-id dedupe
    markers and the delivery log (spec §4.2, §5.1, §5.3).

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

    # --- secrets -----------------------------------------------------------

    def read_secret(self, project: str) -> str | None:
        return read_secrets(self._secrets_file).get(project) or None

    def create_secret(self, project: str, *, rotate: bool = False) -> str:
        """Generate and store a new secret for `project`, keeping the other
        projects' lines. Refuses to replace an existing one unless `rotate`,
        because the old value is configured in Jira and replacing it silently
        would break delivery until Jira is updated."""
        if not project or "=" in project or any(c.isspace() for c in project):
            raise ValidationError(f"invalid project name for a webhook secret: {project!r}")
        existing = read_secrets(self._secrets_file)
        if project in existing and not rotate:
            raise FleetError(
                f"a webhook secret already exists for {project!r}; pass --rotate to replace it"
            )
        secret = secrets.token_urlsafe(32)
        existing[project] = secret
        self._write_secrets(existing)
        return secret

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

    def claim_delivery(self, project: str, delivery_id: str) -> bool:
        """True if this (project, delivery id) is seen for the first time.
        O_EXCL makes the claim atomic, so two concurrent retries cannot both
        win, and the marker survives daemon restarts."""
        self._seen_dir.mkdir(parents=True, exist_ok=True)
        os.chmod(self._seen_dir, 0o700)
        name = hashlib.sha256(f"{project}:{delivery_id}".encode()).hexdigest()
        try:
            fd = os.open(self._seen_dir / name, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        except FileExistsError:
            return False
        os.close(fd)
        return True

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

    def append_log(self, entry: dict) -> None:
        """Append one JSON line. Callers must not pass secrets or request bodies."""
        record = dict(entry)
        record.setdefault("ts", datetime.now(timezone.utc).isoformat(timespec="seconds"))
        self.log_path.parent.mkdir(parents=True, exist_ok=True)
        with self.log_path.open("a", encoding="utf-8") as fh:
            fh.write(json.dumps(record) + "\n")

    def tail_log(self, n: int = 20, project: str | None = None) -> list[dict]:
        """The last `n` entries (oldest first), optionally for one project.
        Lines that are not JSON objects (e.g. a torn write) are skipped."""
        try:
            lines = self.log_path.read_text(encoding="utf-8").splitlines()
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
