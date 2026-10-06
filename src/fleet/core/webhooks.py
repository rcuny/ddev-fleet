"""Jira webhook pure logic: signature check, payload parsing, rule matching
(spec: 2026-10-06-FLE-3-jira-webhooks-design.md §2-§3).

`POST /hooks/jira/{project}` is exempted from Caddy's interactive auth
(Authelia / basic) because Jira Cloud cannot log in, so the HMAC-SHA256
signature over the raw body is the ONLY authentication on that route. Keep
`verify_signature` strict: constant-time compare, and any malformed header is
a plain False so the route can answer 401 without ever raising.

Everything here is side-effect free and stdlib-only; secret/dedupe/log storage
lives alongside it (WebhookStore) and the HTTP wiring in `fleet.daemon`.
"""

import hashlib
import hmac
from dataclasses import dataclass

from fleet.core.errors import ValidationError
from fleet.core.registry import JiraHookRule

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
