"""Pure Bitbucket webhook logic (fleet.core.webhooks / fleet.core.bitbucket):
payload parsing, `bitbucket_hooks` rule matching, the store's Bitbucket
namespaces and the pipeline-trigger HTTP call (spec 2026-10-06-FLE-11 §4)."""

import http.client
import json
import os
import stat
import urllib.error

import pytest

from fleet.core import bitbucket as bitbucket_mod
from fleet.core.errors import FleetError, ValidationError
from fleet.core.registry import BitbucketHookRule
from fleet.core.webhooks import (
    BitbucketEvent,
    WebhookStore,
    match_bitbucket_rule,
    parse_bitbucket_event,
    parse_bitbucket_events,
)

_REPO = "renaud_cuny/ddev-fleet"


def _pr_payload(branch="renovate/ruff-0.x", pr_id=7, repo=_REPO, **extra):
    return {
        "actor": {"display_name": "Renaud"},
        "pullrequest": {
            "id": pr_id,
            "source": {"branch": {"name": branch}},
            "destination": {"branch": {"name": "develop"}},
        },
        "repository": {"full_name": repo},
        **extra,
    }


def _status_payload(state="SUCCESSFUL", refname="renovate/ruff-0.x", repo=_REPO):
    return {
        "repository": {"full_name": repo},
        "commit_status": {"state": state, "refname": refname, "key": "pipeline"},
    }


def _rule(**kw):
    base = dict(
        on_event="pullrequest:approved",
        repo=_REPO,
        action="run-pipeline",
        pattern="renovate-merge",
        ref="develop",
    )
    return BitbucketHookRule(**{**base, **kw})


# --- parsing ---------------------------------------------------------------


def test_parse_approved():
    event = parse_bitbucket_event("pullrequest:approved", _pr_payload(approval={}))
    assert event == BitbucketEvent(
        "pullrequest:approved", repo=_REPO, pr_id=7, branch="renovate/ruff-0.x"
    )


def test_parse_comment_created_lines():
    payload = _pr_payload(comment={"content": {"raw": "  looks good\r\n\n /merge  \nthanks"}})
    event = parse_bitbucket_event("pullrequest:comment_created", payload)
    assert event.comment_lines == ("looks good", "/merge", "thanks")
    assert event.pr_id == 7


@pytest.mark.parametrize("key", ["repo:commit_status_created", "repo:commit_status_updated"])
def test_parse_commit_status(key):
    event = parse_bitbucket_event(key, _status_payload())
    assert event == BitbucketEvent(key, repo=_REPO, branch="renovate/ruff-0.x", state="SUCCESSFUL")


def test_parse_commit_status_null_refname():
    event = parse_bitbucket_event("repo:commit_status_updated", _status_payload(refname=None))
    assert event.branch is None and event.state == "SUCCESSFUL"


def test_parse_pr_without_source_branch_is_not_an_error():
    payload = _pr_payload()
    del payload["pullrequest"]["source"]
    assert parse_bitbucket_event("pullrequest:approved", payload).branch is None


def test_parse_other_event_only_sets_event():
    assert parse_bitbucket_event("diagnostics:ping", {}) == BitbucketEvent("diagnostics:ping")


@pytest.mark.parametrize(
    "key,payload",
    [
        pytest.param(None, {}, id="no-event-key"),
        pytest.param("", {}, id="empty-event-key"),
        pytest.param("pullrequest:approved", [], id="not-an-object"),
        pytest.param("pullrequest:approved", "x", id="string-body"),
        pytest.param("pullrequest:approved", {"pullrequest": {"id": 1}}, id="no-repository"),
        pytest.param(
            "pullrequest:approved",
            {"repository": {"full_name": 3}, "pullrequest": {"id": 1}},
            id="repo-not-a-string",
        ),
        pytest.param("pullrequest:approved", {"repository": {"full_name": "a/b"}}, id="no-pr"),
        pytest.param(
            "pullrequest:approved",
            {"repository": {"full_name": "a/b"}, "pullrequest": {"id": "7"}},
            id="pr-id-not-int",
        ),
        pytest.param(
            "pullrequest:approved",
            {"repository": {"full_name": "a/b"}, "pullrequest": {"id": True}},
            id="pr-id-bool",
        ),
        pytest.param("pullrequest:comment_created", _pr_payload(), id="comment-without-content"),
        pytest.param(
            "pullrequest:comment_created",
            _pr_payload(comment={"content": {"raw": 3}}),
            id="comment-raw-not-a-string",
        ),
        pytest.param(
            "repo:commit_status_updated",
            {"repository": {"full_name": "a/b"}, "commit_status": {}},
            id="status-without-state",
        ),
    ],
)
def test_parse_malformed(key, payload):
    with pytest.raises(ValidationError):
        parse_bitbucket_event(key, payload)


# --- repo:push parsing -------------------------------------------------------

_SHA_A = "a" * 40
_SHA_B = "b" * 40


def _change(name="feature/x--pw-run", sha=_SHA_A, kind="branch", created=True):
    return {
        "new": {"type": kind, "name": name, "target": {"type": "commit", "hash": sha}},
        "old": None if created else {"type": kind, "name": name, "target": {"hash": _SHA_B}},
        "created": created,
        "closed": False,
    }


_DELETE = {
    "new": None,
    "old": {"type": "branch", "name": "gone", "target": {"hash": _SHA_B}},
    "created": False,
    "closed": True,
}


def _push_payload(*changes, repo=_REPO):
    return {"repository": {"full_name": repo}, "push": {"changes": list(changes)}}


def test_parse_push_branch_created():
    events = parse_bitbucket_events("repo:push", _push_payload(_change()))
    assert events == [
        BitbucketEvent("repo:push", repo=_REPO, branch="feature/x--pw-run", sha=_SHA_A)
    ]


def test_parse_push_branch_updated():
    events = parse_bitbucket_events("repo:push", _push_payload(_change(created=False)))
    assert [(e.branch, e.sha) for e in events] == [("feature/x--pw-run", _SHA_A)]


def test_parse_push_skips_deletions_and_tags():
    payload = _push_payload(_DELETE, _change(name="v1.0.0", kind="tag"), _change(name="main"))
    events = parse_bitbucket_events("repo:push", payload)
    assert [e.branch for e in events] == ["main"]


def test_parse_push_delete_only_gives_no_events():
    assert parse_bitbucket_events("repo:push", _push_payload(_DELETE)) == []
    assert parse_bitbucket_events("repo:push", _push_payload()) == []


def test_parse_push_multiple_changes_keep_payload_order():
    payload = _push_payload(_change("b-branch", _SHA_B), _change("a-branch", _SHA_A))
    events = parse_bitbucket_events("repo:push", payload)
    assert [(e.branch, e.sha) for e in events] == [("b-branch", _SHA_B), ("a-branch", _SHA_A)]
    assert {e.repo for e in events} == {_REPO}


def test_parse_push_ignores_non_object_changes():
    payload = _push_payload("junk", 3, None, _change())
    assert [e.branch for e in parse_bitbucket_events("repo:push", payload)] == ["feature/x--pw-run"]


@pytest.mark.parametrize(
    "payload",
    [
        pytest.param([], id="not-an-object"),
        pytest.param({"push": {"changes": []}}, id="no-repository"),
        pytest.param({"repository": {"full_name": "a/b"}}, id="no-push"),
        pytest.param({"repository": {"full_name": "a/b"}, "push": {}}, id="no-changes"),
        pytest.param(
            {"repository": {"full_name": "a/b"}, "push": {"changes": {}}}, id="changes-not-a-list"
        ),
        pytest.param(_push_payload({"new": {"type": "branch", "name": "x"}}), id="no-target"),
        pytest.param(
            _push_payload({"new": {"type": "branch", "target": {"hash": _SHA_A}}}), id="no-name"
        ),
        pytest.param(_push_payload(_change(sha=3)), id="hash-not-a-string"),
    ],
)
def test_parse_push_malformed(payload):
    with pytest.raises(ValidationError):
        parse_bitbucket_events("repo:push", payload)


def test_parse_events_wraps_every_other_key_in_a_one_item_list():
    events = parse_bitbucket_events("pullrequest:approved", _pr_payload(approval={}))
    assert events == [parse_bitbucket_event("pullrequest:approved", _pr_payload(approval={}))]
    assert parse_bitbucket_events("diagnostics:ping", {}) == [BitbucketEvent("diagnostics:ping")]


def test_parse_events_still_rejects_a_missing_event_key():
    with pytest.raises(ValidationError):
        parse_bitbucket_events(None, {})


def test_single_event_parser_refuses_push():
    with pytest.raises(ValidationError, match="parse_bitbucket_events"):
        parse_bitbucket_event("repo:push", _push_payload(_change()))


def test_event_sha_defaults_to_none():
    assert BitbucketEvent("pullrequest:approved").sha is None


# --- matching --------------------------------------------------------------


def _ev(**kw):
    base = dict(event="pullrequest:approved", repo=_REPO, pr_id=7, branch="renovate/x")
    return BitbucketEvent(**{**base, **kw})


def test_match_event_and_repo_case_insensitive():
    rules = [_rule()]
    assert match_bitbucket_rule(rules, _ev()) is rules[0]
    assert match_bitbucket_rule(rules, _ev(repo=_REPO.upper())) is rules[0]
    assert match_bitbucket_rule(rules, _ev(repo="other/repo")) is None
    assert match_bitbucket_rule(rules, _ev(event="pullrequest:fulfilled")) is None


def test_match_unparsed_event_never_matches():
    assert match_bitbucket_rule([_rule()], BitbucketEvent("diagnostics:ping")) is None


def test_match_branch_glob():
    rules = [_rule(branch="renovate/*")]
    assert match_bitbucket_rule(rules, _ev(branch="renovate/ruff-0.x")) is rules[0]
    assert match_bitbucket_rule(rules, _ev(branch="feature/FLE-1-x")) is None
    # Case-sensitive, like git refs.
    assert match_bitbucket_rule(rules, _ev(branch="Renovate/x")) is None


def test_match_branch_rule_needs_a_branch():
    assert match_bitbucket_rule([_rule(branch="*")], _ev(branch=None)) is None
    assert match_bitbucket_rule([_rule()], _ev(branch=None)) is not None


def test_match_state_case_insensitive():
    rules = [_rule(on_event="repo:commit_status_updated", state="SUCCESSFUL")]
    ev = _ev(event="repo:commit_status_updated", state="successful")
    assert match_bitbucket_rule(rules, ev) is rules[0]
    assert match_bitbucket_rule(rules, _ev(event=ev.event, state="FAILED")) is None
    assert match_bitbucket_rule(rules, _ev(event=ev.event, state=None)) is None


def test_match_comment_any_line_case_insensitive():
    rules = [_rule(on_event="pullrequest:comment_created", comment="/merge")]
    kw = dict(event="pullrequest:comment_created")
    assert match_bitbucket_rule(rules, _ev(**kw, comment_lines=("ok", "/MERGE"))) is rules[0]
    assert match_bitbucket_rule(rules, _ev(**kw, comment_lines=("please /merge",))) is None
    assert match_bitbucket_rule(rules, _ev(**kw, comment_lines=())) is None


def test_match_first_rule_wins():
    first, second = _rule(pattern="a"), _rule(pattern="b")
    assert match_bitbucket_rule([first, second], _ev()) is first
    only_second = [_rule(branch="release/*"), second]
    assert match_bitbucket_rule(only_second, _ev()) is second


# --- store -----------------------------------------------------------------


def _store(tmp_path):
    return WebhookStore(tmp_path / "webhooks", tmp_path / "logs" / "webhooks" / "jira.jsonl")


def _mode(path):
    return stat.S_IMODE(os.stat(path).st_mode)


def test_jira_secret_file_format_unchanged(tmp_path):
    store = _store(tmp_path)
    secret = store.create_secret("p")
    assert (tmp_path / "webhooks" / "secrets.env").read_text() == f"p={secret}\n"
    # A file written by an older release keeps working.
    (tmp_path / "webhooks" / "secrets.env").write_text("old=abc\n")
    assert store.read_secret("old") == "abc"
    assert store.read_secret("old", "bitbucket") is None


def test_bitbucket_secret_is_separate_from_jira(tmp_path):
    store = _store(tmp_path)
    jira = store.create_secret("p")
    bb = store.create_secret("p", source="bitbucket")
    assert bb != jira
    assert store.read_secret("p") == jira
    assert store.read_secret("p", "bitbucket") == bb
    text = (tmp_path / "webhooks" / "secrets.env").read_text()
    assert f"p={jira}\n" in text and f"bitbucket:p={bb}\n" in text
    with pytest.raises(FleetError, match="--rotate"):
        store.create_secret("p", source="bitbucket")
    rotated = store.create_secret("p", rotate=True, source="bitbucket")
    assert rotated != bb and store.read_secret("p") == jira
    assert _mode(tmp_path / "webhooks" / "secrets.env") == 0o600


def test_pipeline_token_roundtrip_keeps_other_lines(tmp_path):
    store = _store(tmp_path)
    jira = store.create_secret("p")
    bb = store.create_secret("p", source="bitbucket")
    assert store.read_pipeline_token("p") is None
    store.write_pipeline_token("p", "tok-1")
    store.write_pipeline_token("p", "tok-2")  # replacing needs no --rotate
    assert store.read_pipeline_token("p") == "tok-2"
    assert store.read_secret("p") == jira
    assert store.read_secret("p", "bitbucket") == bb
    assert _mode(tmp_path / "webhooks" / "secrets.env") == 0o600


@pytest.mark.parametrize("token", ["", "a b", "tok\nen"])
def test_pipeline_token_rejects_blank_or_whitespace(tmp_path, token):
    with pytest.raises(ValidationError):
        _store(tmp_path).write_pipeline_token("p", token)


def test_unknown_source_is_rejected(tmp_path):
    store = _store(tmp_path)
    with pytest.raises(ValueError):
        store.read_secret("p", "slack")
    with pytest.raises(ValueError):
        store.claim_delivery("p", "d", "slack")


def test_claim_delivery_is_namespaced_and_releasable(tmp_path):
    store = _store(tmp_path)
    assert store.claim_delivery("p", "id1") is True
    # Same id from Bitbucket does not collide with the Jira marker.
    assert store.claim_delivery("p", "id1", "bitbucket") is True
    assert store.claim_delivery("p", "id1", "bitbucket") is False
    store.release_delivery("p", "id1", "bitbucket")
    assert store.claim_delivery("p", "id1", "bitbucket") is True
    # The Jira marker was never touched, and releasing a missing one is a no-op.
    assert store.claim_delivery("p", "id1") is False
    store.release_delivery("p", "never-claimed", "bitbucket")


def test_bitbucket_log_is_separate_file(tmp_path):
    store = _store(tmp_path)
    store.append_log({"project": "p", "result": "jira"})
    store.append_log({"project": "p", "result": "triggered"}, "bitbucket")
    store.append_log({"project": "q", "result": "ignored"}, "bitbucket")
    assert (tmp_path / "logs" / "webhooks" / "bitbucket.jsonl").exists()
    assert [e["result"] for e in store.tail_log()] == ["jira"]
    assert [e["result"] for e in store.tail_log(source="bitbucket")] == ["triggered", "ignored"]
    assert [e["result"] for e in store.tail_log(project="q", source="bitbucket")] == ["ignored"]


# --- pipeline trigger ------------------------------------------------------


class _FakeResponse:
    def __init__(self, body: bytes):
        self._body = body

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def read(self):
        return self._body


class _FakeOpener:
    def __init__(self, result):
        self.result = result
        self.requests = []
        self.timeouts = []

    def open(self, request, timeout=None):
        self.requests.append(request)
        self.timeouts.append(timeout)
        if isinstance(self.result, BaseException):
            raise self.result
        return _FakeResponse(self.result)


def _patch_opener(monkeypatch, result):
    opener = _FakeOpener(result)
    monkeypatch.setattr(bitbucket_mod.urllib.request, "build_opener", lambda *a, **k: opener)
    return opener


def test_trigger_pipeline_request_shape(monkeypatch):
    opener = _patch_opener(monkeypatch, json.dumps({"build_number": 42, "uuid": "{u}"}).encode())
    result = bitbucket_mod.trigger_pipeline(_REPO, "s3cret", "develop", "renovate-merge")
    assert result == 42
    (request,) = opener.requests
    assert request.full_url == f"https://api.bitbucket.org/2.0/repositories/{_REPO}/pipelines/"
    assert request.get_method() == "POST"
    assert request.get_header("Authorization") == "Bearer s3cret"
    assert request.get_header("Content-type") == "application/json"
    assert json.loads(request.data) == {
        "target": {
            "type": "pipeline_ref_target",
            "ref_type": "branch",
            "ref_name": "develop",
            "selector": {"type": "custom", "pattern": "renovate-merge"},
        }
    }
    assert opener.timeouts == [bitbucket_mod.TIMEOUT_SECONDS]


@pytest.mark.parametrize(
    "body,expected",
    [
        (b'{"uuid": "{abc}"}', "{abc}"),
        (b"{}", None),
        (b"[]", None),
        (b"not json", None),
    ],
)
def test_trigger_pipeline_response_variants(monkeypatch, body, expected):
    _patch_opener(monkeypatch, body)
    assert bitbucket_mod.trigger_pipeline(_REPO, "t", "develop", "p") == expected


@pytest.mark.parametrize(
    "error,status",
    [
        (urllib.error.HTTPError("u", 403, "Forbidden", {}, None), 403),
        (urllib.error.URLError("dns failure"), None),
        (TimeoutError("timed out"), None),
        (http.client.IncompleteRead(b"x"), None),  # HTTPException, not an OSError
    ],
)
def test_trigger_pipeline_failure_is_a_bitbucket_error_without_secrets(monkeypatch, error, status):
    _patch_opener(monkeypatch, error)
    with pytest.raises(bitbucket_mod.BitbucketError) as excinfo:
        bitbucket_mod.trigger_pipeline(_REPO, "s3cret", "develop", "p")
    assert excinfo.value.status == status
    assert "s3cret" not in excinfo.value.message
    assert excinfo.value.__cause__ is None


def test_trigger_pipeline_never_follows_redirects():
    handler = bitbucket_mod._NoRedirect()
    assert handler.redirect_request(None, None, 302, "Found", {}, "http://evil") is None
