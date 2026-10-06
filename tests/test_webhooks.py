"""Pure webhook logic (fleet.core.webhooks): HMAC signature check, Jira payload
parsing and `jira_hooks` rule matching (spec 2026-10-06-FLE-3 §2-§3)."""

import pytest

from fleet.core.errors import ValidationError
from fleet.core.registry import JiraHookRule
from fleet.core.webhooks import (
    MAX_BODY_BYTES,
    JiraEvent,
    match_rule,
    parse_jira_event,
    verify_signature,
)

_SECRET = "It's a Secret to Everybody"
_BODY = b"Hello World!"
_SIG = "a4771c39fbe90f317c7824e83ddef3caae9cb3d976c214ace1f2937e133263c9"


def _status_item(frm="To Do", to="Dispatched"):
    return {"field": "status", "fieldtype": "jira", "fromString": frm, "toString": to}


def _issue_updated(key="FLE-3", items=None):
    """A realistic Jira `jira:issue_updated` body."""
    return {
        "webhookEvent": "jira:issue_updated",
        "timestamp": 1790000000000,
        "issue": {"key": key, "fields": {"status": {"name": "Dispatched"}}},
        "changelog": {"id": "10001", "items": [_status_item()] if items is None else items},
    }


def test_max_body_bytes():
    assert MAX_BODY_BYTES == 1024 * 1024


def test_verify_signature_atlassian_vector():
    assert verify_signature(_SECRET, _BODY, f"sha256={_SIG}") is True
    assert verify_signature(_SECRET, _BODY, f"sha256={_SIG.upper()}") is True


@pytest.mark.parametrize(
    "secret, header",
    [
        ("wrong", f"sha256={_SIG}"),
        (_SECRET, None),
        (_SECRET, ""),
        (_SECRET, _SIG),
        (_SECRET, f"sha1={_SIG}"),
        (_SECRET, "sha256=not-hex-at-all"),
        (_SECRET, "sha256="),
        (_SECRET, f"sha256={_SIG[:-2]}"),
    ],
)
def test_verify_signature_rejects(secret, header):
    assert verify_signature(secret, _BODY, header) is False


def test_verify_signature_rejects_tampered_body():
    assert verify_signature(_SECRET, b"Hello World?", f"sha256={_SIG}") is False


def test_parse_status_transition():
    assert parse_jira_event(_issue_updated()) == JiraEvent(
        "jira:issue_updated", "FLE-3", "To Do", "Dispatched"
    )


def test_parse_status_not_first_item():
    items = [
        {"field": "assignee", "fromString": "x", "toString": "y"},
        _status_item("A", "B"),
    ]
    assert parse_jira_event(_issue_updated(items=items)).to_status == "B"


def test_parse_update_without_status():
    event = parse_jira_event(_issue_updated(items=[{"field": "summary", "toString": "s"}]))
    assert event.to_status is None and event.from_status is None
    payload = _issue_updated()
    del payload["changelog"]
    assert parse_jira_event(payload).to_status is None


@pytest.mark.parametrize("changelog", [None, "x", [], {"items": "x"}, {"items": [3, None]}])
def test_parse_malformed_changelog_is_not_an_error(changelog):
    payload = _issue_updated()
    payload["changelog"] = changelog
    assert parse_jira_event(payload).to_status is None


def test_parse_non_issue_event():
    assert parse_jira_event({"webhookEvent": "comment_created"}) == JiraEvent(
        "comment_created", None, None, None
    )


@pytest.mark.parametrize(
    "payload",
    [
        [],
        "x",
        {},
        {"webhookEvent": 5},
        {"webhookEvent": "jira:issue_updated"},
        {"webhookEvent": "jira:issue_updated", "issue": {"key": 3}},
        {"webhookEvent": "jira:issue_updated", "issue": "FLE-3"},
    ],
)
def test_parse_malformed(payload):
    with pytest.raises(ValidationError):
        parse_jira_event(payload)


def test_match_rule_strips_and_ignores_case():
    rule = JiraHookRule("Dispatched", "deploy", "web")
    other = JiraHookRule("Ready", "deploy", "api")
    for status in ("dispatched", " Dispatched ", "DISPATCHED"):
        assert match_rule([rule], status) is rule
    assert match_rule([rule], "Done") is None
    assert match_rule([rule], None) is None
    assert match_rule([], "Dispatched") is None
    assert match_rule([rule, other], "ready") is other


def test_match_rule_strips_rule_side_and_returns_first():
    first = JiraHookRule("  dispatched ", "deploy", "a")
    second = JiraHookRule("Dispatched", "deploy", "b")
    assert match_rule([first, second], "Dispatched") is first
