"""Pure webhook logic (fleet.core.webhooks): HMAC signature check, Jira payload
parsing and `jira_hooks` rule matching (spec 2026-10-06-FLE-3 §2-§3)."""

import hashlib
import json
import os
import stat
import time

import pytest

from fleet.core.errors import FleetError, ValidationError
from fleet.core.instances import FleetPaths
from fleet.core.registry import JiraHookRule
from fleet.core.webhooks import (
    MAX_BODY_BYTES,
    JiraEvent,
    WebhookStore,
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


# --- WebhookStore: secrets, dedupe, delivery log (spec §4.2, §5.1, §5.3) ---


def _store(tmp_path):
    return WebhookStore(tmp_path / "webhooks", tmp_path / "logs" / "webhooks" / "jira.jsonl")


def _mode(path):
    return stat.S_IMODE(os.stat(path).st_mode)


def test_secret_create_read(tmp_path):
    store = _store(tmp_path)
    secret = store.create_secret("p")
    assert isinstance(secret, str) and len(secret) >= 40
    assert store.read_secret("p") == secret
    assert store.read_secret("q") is None
    assert _mode(tmp_path / "webhooks" / "secrets.env") == 0o600
    assert _mode(tmp_path / "webhooks") == 0o700


def test_secret_refuses_overwrite(tmp_path):
    store = _store(tmp_path)
    first = store.create_secret("p")
    with pytest.raises(FleetError, match="--rotate"):
        store.create_secret("p")
    assert store.read_secret("p") == first
    second = store.create_secret("p", rotate=True)
    assert second != first
    assert store.read_secret("p") == second


def test_rotate_keeps_other_projects(tmp_path):
    store = _store(tmp_path)
    store.create_secret("a")
    b = store.create_secret("b")
    new_a = store.create_secret("a", rotate=True)
    assert store.read_secret("b") == b
    assert store.read_secret("a") == new_a
    assert _mode(tmp_path / "webhooks" / "secrets.env") == 0o600
    assert _mode(tmp_path / "webhooks") == 0o700
    # No temp files left behind by the atomic write.
    assert sorted(p.name for p in (tmp_path / "webhooks").iterdir()) == ["secrets.env"]


def test_read_secret_missing_file(tmp_path):
    assert _store(tmp_path).read_secret("p") is None


def test_claim_delivery_is_exclusive(tmp_path):
    store = _store(tmp_path)
    assert store.claim_delivery("p", "id1") is True
    assert store.claim_delivery("p", "id1") is False
    assert store.claim_delivery("q", "id1") is True
    assert _mode(tmp_path / "webhooks" / "seen") == 0o700


def test_prune_seen(tmp_path):
    store = _store(tmp_path)
    assert store.claim_delivery("p", "old")
    assert store.claim_delivery("p", "new")
    now = time.time()
    seen = tmp_path / "webhooks" / "seen"
    old_marker = seen / hashlib.sha256(b"p:old").hexdigest()
    os.utime(old_marker, (now - 8 * 24 * 3600, now - 8 * 24 * 3600))
    assert store.prune_seen(now=now) == 1
    assert store.claim_delivery("p", "old") is True
    assert store.claim_delivery("p", "new") is False


def test_prune_seen_without_directory(tmp_path):
    assert _store(tmp_path).prune_seen() == 0


def test_log_append_and_tail(tmp_path):
    store = _store(tmp_path)
    for i in range(3):
        store.append_log({"project": "p", "n": i})
    store.append_log({"project": "q", "n": 9})
    log = tmp_path / "logs" / "webhooks" / "jira.jsonl"
    with log.open("a", encoding="utf-8") as fh:
        fh.write("not json\n")
    store.append_log({"project": "p", "n": 3, "ts": "fixed"})
    assert [e["n"] for e in store.tail_log(2)] == [9, 3]
    assert [e["n"] for e in store.tail_log(2, project="p")] == [2, 3]
    assert [e["n"] for e in store.tail_log(project="q")] == [9]
    entries = store.tail_log(100)
    assert [e["n"] for e in entries] == [0, 1, 2, 9, 3]
    assert all("ts" in e for e in entries)
    assert entries[-1]["ts"] == "fixed"
    assert json.loads(log.read_text().splitlines()[0])["project"] == "p"


def test_tail_log_missing_file(tmp_path):
    assert _store(tmp_path).tail_log() == []


def test_from_paths(tmp_path):
    paths = FleetPaths.from_home(tmp_path)
    store = WebhookStore.from_paths(paths)
    secret = store.create_secret("p")
    assert (tmp_path / "webhooks" / "secrets.env").is_file()
    assert store.read_secret("p") == secret
    store.append_log({"project": "p"})
    assert (tmp_path / "logs" / "webhooks" / "jira.jsonl").is_file()


def test_fleet_paths_webhook_fields(tmp_path):
    paths = FleetPaths.from_home(tmp_path)
    assert paths.webhooks == tmp_path / "webhooks"
    assert paths.webhook_log == tmp_path / "logs" / "webhooks" / "jira.jsonl"
