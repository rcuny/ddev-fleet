"""POST /hooks/bitbucket/{project}: the Bitbucket webhook route (spec FLE-11 §4)."""

import hashlib
import hmac
import json
import logging

import pytest
from fastapi.testclient import TestClient

from fleet.core import bitbucket as bitbucket_mod
from fleet.core.instances import FleetPaths
from fleet.core.webhooks import MAX_BODY_BYTES, WebhookStore
from fleet.daemon import create_app

_REPO = "renaud_cuny/ddev-fleet"

_REGISTRY = f"""\
fleet:
  domain: fleet.example.test

projects:
  p:
    git: git@example.test:org/p.git
    templates:
      default: {{}}
    bitbucket_hooks:
      - {{on_event: "pullrequest:approved", repo: {_REPO}, branch: "renovate/*", action: run-pipeline, pattern: renovate-merge, ref: develop}}
      - {{on_event: "pullrequest:comment_created", repo: {_REPO}, branch: "renovate/*", comment: "/merge", action: run-pipeline, pattern: renovate-merge, ref: develop}}
      - {{on_event: "repo:commit_status_updated", repo: {_REPO}, branch: "renovate/*", state: SUCCESSFUL, action: run-pipeline, pattern: renovate-merge, ref: develop}}
  noh:
    git: git@example.test:org/noh.git
    templates:
  jiraonly:
    git: git@example.test:org/j.git
    templates:
      default: {{}}
    jira_hooks:
      - {{on_status: Dispatched, action: deploy, template: default}}
"""  # noqa: E501


@pytest.fixture
def hook_env(fleet_home, monkeypatch):
    """App + Bitbucket secret and pipeline token for `p`, with the pipeline
    trigger replaced by a recorder (tests never touch the network)."""
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_REGISTRY, encoding="utf-8")
    store = WebhookStore.from_paths(paths)
    secret = store.create_secret("p", source="bitbucket")
    store.write_pipeline_token("p", "pipeline-token-xyz")
    # `jiraonly` has a Jira secret only: it must not open the Bitbucket route.
    store.create_secret("jiraonly")

    from fleet import daemon as daemon_mod

    calls = []
    state = {"result": 17}

    def fake_trigger(repo, token, ref, pattern):
        calls.append({"repo": repo, "token": token, "ref": ref, "pattern": pattern})
        if isinstance(state["result"], BaseException):
            raise state["result"]
        return state["result"]

    monkeypatch.setattr(daemon_mod.bitbucket_mod, "trigger_pipeline", fake_trigger)
    client = TestClient(create_app(fleet_home))
    return client, store, secret, calls, state


def _approved(branch="renovate/ruff-0.x", repo=_REPO):
    return {
        "actor": {"display_name": "Renaud"},
        "pullrequest": {
            "id": 7,
            "source": {"branch": {"name": branch}},
            "destination": {"branch": {"name": "develop"}},
        },
        "repository": {"full_name": repo},
        "approval": {"user": {}},
    }


def _comment(raw="/merge"):
    return {**_approved(), "comment": {"content": {"raw": raw}}}


def _status(state="SUCCESSFUL", refname="renovate/ruff-0.x"):
    return {
        "repository": {"full_name": _REPO},
        "commit_status": {"state": state, "refname": refname},
    }


def _post(
    client,
    project,
    payload,
    secret,
    event="pullrequest:approved",
    delivery_id="u1",
    sign=True,
    headers=None,
):
    body = payload if isinstance(payload, bytes) else json.dumps(payload).encode()
    hdrs = {"Content-Type": "application/json"}
    if sign:
        digest = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
        hdrs["X-Hub-Signature"] = f"sha256={digest}"
    if event is not None:
        hdrs["X-Event-Key"] = event
    if delivery_id is not None:
        hdrs["X-Request-UUID"] = delivery_id
    hdrs.update(headers or {})
    return client.post(f"/hooks/bitbucket/{project}", content=body, headers=hdrs)


def _log(store):
    return store.tail_log(source="bitbucket")


def test_unknown_project_404(hook_env):
    client, _, secret, calls, _ = hook_env
    resp = _post(client, "ghost", _approved(), secret)
    assert resp.status_code == 404
    assert resp.json() == {"error": "not found"}
    assert not calls


@pytest.mark.parametrize("project", ["noh", "jiraonly"])
def test_project_without_bitbucket_hooks_or_secret_404(hook_env, project):
    client, store, secret, _, _ = hook_env
    assert _post(client, project, _approved(), secret).status_code == 404
    assert _log(store) == []  # 404s are never logged


def test_hooks_but_no_bitbucket_secret_404(hook_env, fleet_home):
    client, store, secret, _, _ = hook_env
    # Only the Jira-style key left: a Jira secret must not unlock this route.
    (fleet_home / "webhooks" / "secrets.env").write_text(f"p={secret}\n")
    assert _post(client, "p", _approved(), secret).status_code == 404
    assert _log(store) == []


def test_jira_secret_does_not_sign_bitbucket(hook_env):
    client, store, _, calls, _ = hook_env
    jira_secret = store.read_secret("jiraonly")
    assert _post(client, "p", _approved(), jira_secret).status_code == 401
    assert not calls


def test_too_large_413(hook_env):
    client, store, secret, calls, _ = hook_env
    resp = _post(client, "p", b"x" * (MAX_BODY_BYTES + 1), secret)
    assert resp.status_code == 413
    assert resp.json() == {"error": "payload too large"}
    assert not calls


def test_bad_signature_401_journal_only(hook_env, caplog):
    client, store, _, calls, _ = hook_env
    with caplog.at_level(logging.WARNING, logger="fleet.daemon"):
        resp = _post(client, "p", _approved(), "wrong-secret", delivery_id="D" * 500)
    assert resp.status_code == 401
    assert resp.json() == {"error": "unauthorized"}
    assert _log(store) == []  # unauthenticated callers must not grow the log
    assert not calls
    messages = " ".join(r.getMessage() for r in caplog.records)
    assert "rejected signature" in messages and "D" * 64 in messages
    assert "D" * 65 not in messages and "wrong-secret" not in messages


def test_missing_signature_401(hook_env):
    client, store, secret, calls, _ = hook_env
    assert _post(client, "p", _approved(), secret, sign=False).status_code == 401
    assert _log(store) == [] and not calls


@pytest.mark.parametrize(
    "body",
    [
        pytest.param(b"not json", id="garbage"),
        pytest.param(b"\xff\xfe", id="not-utf8"),
        pytest.param(b"[]", id="not-an-object"),
        pytest.param(b"[" * 5000 + b"]" * 5000, id="deeply-nested"),
        pytest.param(b"{}", id="no-repository"),
    ],
)
def test_bad_body_400_logged(hook_env, body):
    client, store, secret, calls, _ = hook_env
    resp = _post(client, "p", body, secret)
    assert resp.status_code == 400
    assert "error" in resp.json()
    assert _log(store)[-1]["result"] == "bad_request"
    assert not calls


def test_missing_event_key_400(hook_env):
    client, store, secret, calls, _ = hook_env
    resp = _post(client, "p", _approved(), secret, event=None)
    assert resp.status_code == 400
    assert "X-Event-Key" in resp.json()["error"]
    assert _log(store)[-1]["result"] == "bad_request"


@pytest.mark.parametrize(
    "event,payload,reason",
    [
        pytest.param("diagnostics:ping", {}, "event diagnostics:ping", id="other-event"),
        pytest.param(
            "pullrequest:fulfilled", _approved(), "event pullrequest:fulfilled", id="no-rule-event"
        ),
        pytest.param(
            "pullrequest:approved",
            _approved(branch="feature/FLE-1-x"),
            "no matching rule",
            id="branch-not-matching",
        ),
        pytest.param(
            "pullrequest:approved",
            _approved(repo="someone/else"),
            "no matching rule",
            id="other-repo",
        ),
        pytest.param(
            "pullrequest:comment_created",
            _comment("lgtm"),
            "no matching rule",
            id="comment-not-matching",
        ),
        pytest.param(
            "repo:commit_status_updated",
            _status(state="FAILED"),
            "no matching rule",
            id="state-not-matching",
        ),
        pytest.param(
            "repo:commit_status_updated",
            _status(refname=None),
            "no matching rule",
            id="status-without-branch",
        ),
    ],
)
def test_ignored(hook_env, event, payload, reason):
    client, store, secret, calls, _ = hook_env
    resp = _post(client, "p", payload, secret, event=event)
    assert resp.status_code == 200
    assert resp.json() == {"result": "ignored", "reason": reason}
    assert _log(store)[-1]["result"] == "ignored"
    assert not calls


@pytest.mark.parametrize(
    "event,payload",
    [
        ("pullrequest:approved", _approved()),
        ("pullrequest:comment_created", _comment("thanks\n/merge")),
        ("repo:commit_status_updated", _status()),
    ],
)
def test_triggered(hook_env, event, payload):
    client, store, secret, calls, _ = hook_env
    resp = _post(client, "p", payload, secret, event=event, headers={"X-Attempt-Number": "1"})
    assert resp.status_code == 200
    assert resp.json() == {"result": "triggered", "pipeline": 17}
    assert calls == [
        {
            "repo": _REPO,
            "token": "pipeline-token-xyz",
            "ref": "develop",
            "pattern": "renovate-merge",
        }
    ]
    last = _log(store)[-1]
    assert last["result"] == "triggered"
    assert last["pipeline"] == 17
    assert last["event"] == event
    assert last["repo"] == _REPO
    assert last["branch"] == "renovate/ruff-0.x"
    assert last["delivery_id"] == "u1"
    assert last["attempt"] == "1"
    assert set(last) == {
        "ts", "project", "delivery_id", "attempt", "event", "repo", "pr", "branch", "state",
        "result", "reason", "pipeline",
    }  # fmt: skip
    # Nothing secret ever reaches the log or the response.
    assert "pipeline-token-xyz" not in json.dumps(last) + resp.text


def test_triggered_logs_pr_and_state(hook_env):
    client, store, secret, _, _ = hook_env
    _post(client, "p", _approved(), secret)
    assert _log(store)[-1]["pr"] == 7
    _post(client, "p", _status(), secret, event="repo:commit_status_updated", delivery_id="u2")
    assert _log(store)[-1]["state"] == "SUCCESSFUL"


def test_repo_matches_case_insensitively(hook_env):
    client, _, secret, calls, _ = hook_env
    resp = _post(client, "p", _approved(repo=_REPO.upper()), secret)
    assert resp.json()["result"] == "triggered"
    assert calls[0]["repo"] == _REPO  # the rule's spelling is used for the API call


def test_pipeline_uuid_is_passed_through(hook_env):
    client, _, secret, _, state = hook_env
    state["result"] = "{1234-uuid}"
    assert _post(client, "p", _approved(), secret).json()["pipeline"] == "{1234-uuid}"


def test_no_token_422_does_not_claim(hook_env, fleet_home):
    client, store, secret, calls, _ = hook_env
    # Rewrite the secrets file without the token line.
    (fleet_home / "webhooks" / "secrets.env").write_text(f"bitbucket:p={secret}\n")
    resp = _post(client, "p", _approved(), secret)
    assert resp.status_code == 422
    assert "no Bitbucket token" in resp.json()["error"]
    assert _log(store)[-1]["result"] == "error"
    assert not calls
    # Once the token exists, the same delivery id is processed (not "duplicate").
    store.write_pipeline_token("p", "tok")
    assert _post(client, "p", _approved(), secret).json()["result"] == "triggered"


@pytest.mark.parametrize(
    "error,reason",
    [
        (bitbucket_mod.BitbucketError("Bitbucket answered HTTP 403", status=403), "HTTP 403"),
        (bitbucket_mod.BitbucketError("Bitbucket request failed (TimeoutError)"), "TimeoutError"),
    ],
)
def test_upstream_failure_502_does_not_burn_the_delivery(hook_env, caplog, error, reason):
    client, store, secret, calls, state = hook_env
    state["result"] = error
    with caplog.at_level(logging.WARNING, logger="fleet.daemon"):
        resp = _post(client, "p", _approved(), secret)
    assert resp.status_code == 502
    assert resp.json() == {"error": "pipeline trigger failed"}
    last = _log(store)[-1]
    assert last["result"] == "error" and reason in last["reason"]
    assert "pipeline-token-xyz" not in json.dumps(last) + resp.text + caplog.text

    # Bitbucket's retry (same X-Request-UUID) succeeds once upstream recovers.
    state["result"] = 18
    retry = _post(client, "p", _approved(), secret, headers={"X-Attempt-Number": "2"})
    assert retry.status_code == 200
    assert retry.json() == {"result": "triggered", "pipeline": 18}
    assert len(calls) == 2


def test_unexpected_exception_type_still_releases_the_claim(hook_env, caplog):
    client, store, secret, calls, state = hook_env
    state["result"] = RuntimeError("secret detail pipeline-token-xyz")
    with caplog.at_level(logging.WARNING, logger="fleet.daemon"):
        resp = _post(client, "p", _approved(), secret)
    assert resp.status_code == 502
    last = _log(store)[-1]
    assert last["result"] == "error" and "RuntimeError" in last["reason"]
    assert "pipeline-token-xyz" not in json.dumps(last) + resp.text + caplog.text
    state["result"] = 19
    retry = _post(client, "p", _approved(), secret)
    assert retry.json() == {"result": "triggered", "pipeline": 19}
    assert len(calls) == 2


def test_prune_failure_does_not_fail_the_delivery(hook_env, monkeypatch):
    client, store, secret, calls, _ = hook_env

    def boom(*a, **k):
        raise OSError("disk")

    monkeypatch.setattr(WebhookStore, "prune_seen", boom)
    resp = _post(client, "p", _approved(), secret)
    assert resp.json()["result"] == "triggered" and len(calls) == 1


def test_duplicate_delivery(hook_env):
    client, store, secret, calls, _ = hook_env
    assert _post(client, "p", _approved(), secret).json()["result"] == "triggered"
    second = _post(client, "p", _approved(), secret)
    assert second.status_code == 200
    assert second.json() == {"result": "duplicate"}
    assert _log(store)[-1]["result"] == "duplicate"
    assert len(calls) == 1

    third = _post(client, "p", _approved(), secret, delivery_id="u2")
    assert third.json()["result"] == "triggered"
    assert len(calls) == 2


def test_jira_delivery_id_does_not_collide(hook_env):
    client, store, secret, calls, _ = hook_env
    assert store.claim_delivery("p", "u1")  # a Jira delivery with the same id
    assert _post(client, "p", _approved(), secret).json()["result"] == "triggered"


def test_without_delivery_id_processes_every_time(hook_env):
    client, store, secret, calls, _ = hook_env
    for _ in range(2):
        resp = _post(client, "p", _approved(), secret, delivery_id=None)
        assert resp.json()["result"] == "triggered"
    assert len(calls) == 2
    assert _log(store)[-1]["delivery_id"] is None


def test_log_truncates_headers(hook_env):
    client, store, secret, _, _ = hook_env
    _post(
        client,
        "p",
        _approved(),
        secret,
        delivery_id="D" * 500,
        headers={"X-Attempt-Number": "A" * 500},
    )
    last = _log(store)[-1]
    assert last["delivery_id"] == "D" * 64 and last["attempt"] == "A" * 64


def test_does_not_write_the_jira_log(hook_env):
    client, store, secret, _, _ = hook_env
    _post(client, "p", _approved(), secret)
    assert store.tail_log() == []


def test_invalid_registry_500_no_leak(hook_env, fleet_home):
    client, _, secret, calls, _ = hook_env
    FleetPaths.from_home(fleet_home).registry.write_text("projects: not-a-mapping\n")
    resp = _post(client, "p", _approved(), secret)
    assert resp.status_code == 500
    assert resp.json() == {"error": "internal error"}
    assert not calls


def test_log_write_failure_does_not_change_outcome(hook_env, monkeypatch, caplog):
    client, _, secret, calls, _ = hook_env

    def boom(self, entry, source="jira"):
        raise OSError("disk full")

    monkeypatch.setattr(WebhookStore, "append_log", boom)
    with caplog.at_level(logging.WARNING, logger="fleet.daemon"):
        resp = _post(client, "p", _approved(), secret)
    assert resp.status_code == 200
    assert resp.json()["result"] == "triggered"
    assert len(calls) == 1
    assert any("disk full" in r.getMessage() for r in caplog.records)
