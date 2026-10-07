#!/usr/bin/env python3
"""Merge ONE approved, green, up-to-date Renovate PR into the base branch (FLE-11).

Runs in Bitbucket Pipelines (``custom: renovate-merge``). The maintainer only
decides: Approve the PR, or comment ``/merge`` on it. This script then merges
the oldest eligible Renovate PR with a merge commit (``--no-ff``) and closes its
branch. The pipeline's next step runs Renovate, which recreates the sibling PRs
on the new base head, so they are tested again before they can be merged.

A PR is merged only when ALL of these hold:

* an allowed approver Approved it, or commented a line ``/merge`` (not deleted);
* every commit status on its CURRENT head is SUCCESSFUL (and there is one);
* its head contains the base branch head (it was tested on the latest base).

An approved PR whose gates are red on an up-to-date head is never merged: it gets
ONE PR comment per head commit saying it needs a human. An approved PR that is
BEHIND the base branch is never judged on its old head (Renovate will recreate
it on the new base, and the gates may then go green): it just waits. At most one
PR is merged per run.

The script also decides whether the pipeline's next step should run Renovate
(``--renovate-flag``): after a merge (the sibling PRs are now behind), or when
nothing was merged but an accepted PR is waiting because it is behind the base
branch (the base moved for another reason, e.g. a feature merge; Renovate's
``rebaseWhen: behind-base-branch`` then recreates it, its green build fires the
webhook and the next run merges it). Accepted PRs that are merely still running
their gates, red, or not accepted never request a Renovate run.

Environment (secrets come from secured repository variables, never printed):

* ``RENOVATE_USERNAME``  Atlassian account email (HTTP Basic user)
* ``RENOVATE_PASSWORD``  Atlassian API token: read/write repository,
  read/write pullrequest, read:user
* ``BITBUCKET_REPO_FULL_NAME``  ``workspace/slug`` (set by Pipelines; ``--repo``)
* ``RENOVATE_MERGE_APPROVERS``  optional, comma-separated Bitbucket account ids;
  default is the token's own account. Set it to the maintainer's account id
  whenever the Renovate token belongs to another (bot) account, otherwise the
  maintainer's Approve / ``/merge`` is ignored

Exit status: 0 on a normal run (including "nothing to merge"), 1 on missing
configuration, an API/auth error or a merge that did not complete.
Stdlib only, so the pipeline step needs no ``pip install``.
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from collections.abc import Callable, Mapping
from dataclasses import dataclass
from pathlib import Path

API_BASE = "https://api.bitbucket.org/2.0"
MERGE_COMMAND = "/merge"
COMMENT_MARKER = "[renovate-merge] Gates are red on"

# Bitbucket commit-status states
SUCCESSFUL = "SUCCESSFUL"
RED_STATES = frozenset({"FAILED", "STOPPED"})

BEHIND_REASON = "behind the base branch; Renovate will recreate it"

MERGE_POLL_INTERVAL = 3  # seconds between task-status polls
MERGE_POLL_TIMEOUT = 60  # give up polling a 202 merge task after this long


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """Never follow a redirect: urllib would replay the Authorization header to
    wherever the response points, defeating the host check in ``_url``."""

    def redirect_request(self, *args, **kwargs):
        return None


class ApiError(RuntimeError):
    """A Bitbucket API call failed (status + a short body; never headers)."""


class MergeError(RuntimeError):
    """The merge was accepted by Bitbucket but did not complete."""


@dataclass(frozen=True)
class Response:
    status: int
    data: dict
    location: str | None = None


class BitbucketClient:
    """Minimal Bitbucket Cloud REST client: Basic auth, JSON, pagination."""

    def __init__(
        self, username: str, password: str, base_url: str = API_BASE, timeout: float = 30
    ) -> None:
        token = base64.b64encode(f"{username}:{password}".encode()).decode()
        self._auth = f"Basic {token}"
        self.base_url = base_url
        self.timeout = timeout
        self._opener = urllib.request.build_opener(_NoRedirect)

    def _url(self, path: str, params: Mapping[str, str | int] | None) -> str:
        if path.startswith(("http://", "https://")):
            # `next` / task-status links are absolute: never send the token elsewhere
            if not path.startswith(self.base_url + "/"):
                raise ApiError(f"refusing to call a URL outside {self.base_url}")
            url = path
        else:
            url = self.base_url + path
        if params:
            url += ("&" if "?" in url else "?") + urllib.parse.urlencode(params)
        return url

    def request(
        self,
        method: str,
        path: str,
        params: Mapping[str, str | int] | None = None,
        body: dict | None = None,
    ) -> Response:
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(
            self._url(path, params),
            data=data,
            method=method,
            headers={
                "Authorization": self._auth,
                "Accept": "application/json",
                **({"Content-Type": "application/json"} if data is not None else {}),
            },
        )
        try:
            with self._opener.open(req, timeout=self.timeout) as resp:
                raw = resp.read()
                return Response(
                    resp.status,
                    json.loads(raw) if raw.strip() else {},
                    resp.headers.get("Location"),
                )
        except urllib.error.HTTPError as exc:
            raise ApiError(
                f"{method} {path.split('?')[0]} -> HTTP {exc.code}: {_error_text(exc)}"
            ) from None
        except urllib.error.URLError as exc:
            raise ApiError(f"{method} {path.split('?')[0]} failed: {exc.reason}") from None

    def get(self, path: str, params: Mapping[str, str | int] | None = None) -> dict:
        return self.request("GET", path, params).data

    def paginate(self, path: str, params: Mapping[str, str | int] | None = None) -> list[dict]:
        """All ``values`` of a paginated collection, following ``next`` links."""
        values: list[dict] = []
        page = self.get(path, params)
        while True:
            values.extend(page.get("values", []))
            if not page.get("next"):
                return values
            page = self.get(page["next"])

    def post(self, path: str, body: dict) -> Response:
        return self.request("POST", path, body=body)


def _error_text(exc: urllib.error.HTTPError) -> str:
    """A short, header-free description of an error response body."""
    try:
        raw = exc.read().decode("utf-8", "replace")
    except Exception:  # noqa: BLE001 - best effort, the status code is what matters
        return ""
    try:
        message = json.loads(raw).get("error", {}).get("message")
    except (ValueError, AttributeError):
        message = None
    return (message or raw).strip()[:200]


# --- pure decision logic (no I/O) -------------------------------------------


def has_merge_command(raw: str) -> bool:
    """True if some line of a comment is exactly ``/merge`` (case/space-insensitive)."""
    return any(line.strip().lower() == MERGE_COMMAND for line in (raw or "").splitlines())


def acceptance(pr: Mapping, comments: list[dict], approvers: set[str]) -> tuple[bool, str]:
    """Did an allowed approver Approve the PR or comment ``/merge``? -> (ok, how)."""
    for participant in pr.get("participants", []):
        account = (participant.get("user") or {}).get("account_id")
        if participant.get("approved") and account in approvers:
            return True, "approved"
    for comment in comments:
        account = (comment.get("user") or {}).get("account_id")
        if comment.get("deleted") or account not in approvers:
            continue
        if has_merge_command((comment.get("content") or {}).get("raw", "")):
            return True, "/merge comment"
    return False, ""


def decide(accepted: bool, statuses: list[str], up_to_date: bool) -> tuple[str, str]:
    """-> (verdict, reason). Verdicts: merge, red, wait, skip.

    Behind the base wins over every status: the statuses belong to a head that
    Renovate is about to replace, so even a red one is no reason to call a human."""
    if not accepted:
        return "skip", "not approved and no /merge comment from an allowed approver"
    if not up_to_date:
        return "wait", BEHIND_REASON
    bad = sorted(RED_STATES.intersection(statuses))
    if bad:
        return "red", f"gates {'/'.join(bad)} on the current head"
    if not statuses:
        return "wait", "no commit status on the current head yet"
    if any(state != SUCCESSFUL for state in statuses):
        return "wait", "gates still running"
    return "merge", "approved, gates green, up to date"


# --- orchestration -----------------------------------------------------------


def candidate_prs(client, repo: str, base: str, prefix: str) -> list[dict]:
    """Open PRs into ``base`` from ``<prefix>*`` branches, oldest (lowest id) first.

    The state goes INTO ``q``: Bitbucket ignores the ``state`` query parameter
    when ``q`` is present and then lists MERGED and DECLINED PRs too (seen live
    2026-10-07, pipeline #71). The client-side ``state`` check is a second guard."""
    prs = client.paginate(
        f"/repositories/{repo}/pullrequests",
        {"q": f'state="OPEN" AND destination.branch.name="{base}"', "pagelen": 50},
    )
    kept = [
        p
        for p in prs
        if p.get("state", "OPEN") == "OPEN" and p["source"]["branch"]["name"].startswith(prefix)
    ]
    return sorted(kept, key=lambda p: p["id"])


def resolve_approvers(client, configured: str) -> set[str]:
    """Account ids allowed to approve; default to the token's own account."""
    ids = {item.strip() for item in configured.split(",") if item.strip()}
    return ids or {client.get("/user")["account_id"]}


def base_head(client, repo: str, base: str) -> str:
    return client.get(f"/repositories/{repo}/refs/branches/{base}")["target"]["hash"]


def full_hash(client, repo: str, commit: str) -> str:
    """The 12-char hash in a PR's ``source.commit`` -> the full 40-char hash."""
    if len(commit) >= 40:
        return commit
    return client.get(f"/repositories/{repo}/commit/{commit}")["hash"]


def merge_pr(client, repo: str, pr_id: int, sleep: Callable[[float], None]) -> None:
    """Merge with a merge commit and close the source branch; poll a 202 task."""
    resp = client.post(
        f"/repositories/{repo}/pullrequests/{pr_id}/merge",
        {"type": "pullrequest", "merge_strategy": "merge_commit", "close_source_branch": True},
    )
    if resp.status != 202:
        return
    link = resp.location or ((resp.data.get("links") or {}).get("self") or {}).get("href")
    if not link:
        raise MergeError(f"merge of #{pr_id} is asynchronous but gave no task-status link")
    for _ in range(MERGE_POLL_TIMEOUT // MERGE_POLL_INTERVAL + 1):
        state = client.get(link).get("task_status")
        if state == "SUCCESS":
            return
        if state == "FAILED":
            raise MergeError(f"merge of #{pr_id} failed (task status FAILED)")
        sleep(MERGE_POLL_INTERVAL)
    raise MergeError(f"merge of #{pr_id} still pending after {MERGE_POLL_TIMEOUT}s")


def run(
    client,
    repo: str,
    *,
    base: str,
    prefix: str,
    approvers: set[str],
    dry_run: bool = False,
    renovate_flag: str | None = None,
    sleep: Callable[[float], None] = time.sleep,
    out: Callable[[str], None] = print,
) -> int | None:
    """Evaluate the candidates and merge at most one. Returns the merged PR id.

    ``renovate_flag`` is written (content = the reason, ``merged #12`` or
    ``behind #13, #14``) only when the pipeline should run Renovate next."""
    candidates = candidate_prs(client, repo, base, prefix)
    develop = base_head(client, repo, base)
    merged: int | None = None
    behind: list[int] = []  # accepted PRs waiting for Renovate to recreate them
    for pr in candidates:
        pr_id, branch = pr["id"], pr["source"]["branch"]["name"]
        if merged is not None:
            out(f"#{pr_id} {branch} wait: deferred, #{merged} was merged this run")
            continue
        detail = client.get(f"/repositories/{repo}/pullrequests/{pr_id}")
        comments = client.paginate(f"/repositories/{repo}/pullrequests/{pr_id}/comments")
        accepted, how = acceptance(detail, comments, approvers)
        if not accepted:
            verdict, reason = decide(False, [], False)
            out(f"#{pr_id} {branch} {verdict}: {reason}")
            continue

        head = full_hash(client, repo, pr["source"]["commit"]["hash"])
        statuses = [
            s.get("state", "")
            for s in client.paginate(f"/repositories/{repo}/commit/{head}/statuses")
        ]
        ancestor = client.get(f"/repositories/{repo}/merge-base/{develop}..{head}")
        up_to_date = ancestor.get("hash") == develop
        verdict, reason = decide(True, statuses, up_to_date)
        if verdict == "wait" and not up_to_date:
            behind.append(pr_id)

        if verdict == "red":
            marker = f"{COMMENT_MARKER} {head[:12]}"
            if any(
                marker in (c.get("content") or {}).get("raw", "") and not c.get("deleted")
                for c in comments
            ):
                reason += "; already commented"
            elif dry_run:
                reason += "; would comment"
            else:
                client.post(
                    f"/repositories/{repo}/pullrequests/{pr_id}/comments",
                    {"content": {"raw": f"{marker}; this approved PR needs a human."}},
                )
                reason += "; commented"
        elif verdict == "merge":
            if base_head(client, repo, base) != develop:
                # the base moved since the up-to-date check: this head is now behind
                verdict, reason = "wait", f"{base} moved during the run; retry on the next run"
                behind.append(pr_id)
            elif dry_run:
                reason += f" ({how}); dry-run, not merged"
                merged = pr_id
            else:
                merge_pr(client, repo, pr_id, sleep)
                reason = f"merged ({how})"
                merged = pr_id
        out(f"#{pr_id} {branch} {verdict}: {reason}")

    renovate = (
        f"merged #{merged}"
        if merged is not None
        else ("behind " + ", ".join(f"#{n}" for n in behind) if behind else None)
    )
    if renovate and not dry_run and renovate_flag:
        Path(renovate_flag).write_text(f"{renovate}\n")

    out(
        f"summary: {len(candidates)} candidate(s), "
        + (f"{'would merge' if dry_run else 'merged'} #{merged}" if merged else "nothing merged")
        + (f"; Renovate {'would be ' if dry_run else ''}requested ({renovate})" if renovate else "")
    )
    return merged


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        description="Merge one approved, green, up-to-date Renovate PR (FLE-11)."
    )
    p.add_argument("--repo", help="workspace/slug (default: $BITBUCKET_REPO_FULL_NAME)")
    p.add_argument("--base", default="develop", help="destination branch (default: develop)")
    p.add_argument(
        "--branch-prefix", default="renovate/", help="source branch prefix (default: renovate/)"
    )
    p.add_argument(
        "--renovate-flag",
        help="file written (with the reason) ONLY if Renovate should run next: "
        "a PR was merged, or an accepted PR is behind the base branch",
    )
    p.add_argument("--dry-run", action="store_true", help="decide and print; never merge/comment")
    return p


def main(
    argv: list[str] | None = None,
    env: Mapping[str, str] | None = None,
    client=None,
    sleep: Callable[[float], None] = time.sleep,
) -> int:
    args = build_parser().parse_args(argv)
    env = os.environ if env is None else env
    repo = args.repo or env.get("BITBUCKET_REPO_FULL_NAME", "")
    if client is None:
        user, password = env.get("RENOVATE_USERNAME", ""), env.get("RENOVATE_PASSWORD", "")
        missing = [
            name
            for name, value in (
                ("RENOVATE_USERNAME", user),
                ("RENOVATE_PASSWORD", password),
                ("BITBUCKET_REPO_FULL_NAME (or --repo)", repo),
            )
            if not value
        ]
        if missing:
            print(f"error: missing {', '.join(missing)}", file=sys.stderr)
            return 1
        client = BitbucketClient(user, password)
    elif not repo:
        print("error: missing BITBUCKET_REPO_FULL_NAME (or --repo)", file=sys.stderr)
        return 1
    try:
        approvers = resolve_approvers(client, env.get("RENOVATE_MERGE_APPROVERS", ""))
        run(
            client,
            repo,
            base=args.base,
            prefix=args.branch_prefix,
            approvers=approvers,
            dry_run=args.dry_run,
            renovate_flag=args.renovate_flag,
            sleep=sleep,
        )
    except (ApiError, MergeError) as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
