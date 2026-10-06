"""POST /hooks/jira/{project}: the Jira webhook route (spec FLE-3 §3/§5)."""

import hashlib
import hmac
import inspect
import json
import time

import pytest
from fastapi.testclient import TestClient

from fleet.core import instances as real_instances_mod
from fleet.core.instances import FleetPaths
from fleet.core.webhooks import MAX_BODY_BYTES, WebhookStore
from fleet.daemon import create_app

_REGISTRY = """\
fleet:
  domain: fleet.example.test

projects:
  p:
    git: git@example.test:org/p.git
    default_template: jira-work
    default_branch: main
    issue_id_regexp: "FLE-[0-9]+"
    templates:
      jira-work: {}
    jira_hooks:
      - {on_status: Dispatched, action: deploy, template: jira-work}
  noh:
    git: git@example.test:org/noh.git
    default_template: default
    default_branch: main
    templates:
      default: {}
  nobranch:
    git: git@example.test:org/nobranch.git
    templates:
      jira-work: {}
    jira_hooks:
      - {on_status: Dispatched, action: deploy, template: jira-work}
"""


@pytest.fixture
def hook_env(fleet_home, monkeypatch):
    """App + secrets for `p` and `nobranch`, with `deploy` replaced by a
    recorder that validates its args against the real signature."""
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_REGISTRY, encoding="utf-8")
    store = WebhookStore.from_paths(paths)
    secrets = {"p": store.create_secret("p"), "nobranch": store.create_secret("nobranch")}

    from fleet import daemon as daemon_mod

    real_sig = inspect.signature(real_instances_mod.deploy)
    calls = []

    def fake_deploy(*args, **kwargs):
        real_sig.bind(*args, **kwargs)
        calls.append((args, kwargs))
        return "https://p--fle-3.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "deploy", fake_deploy)
    client = TestClient(create_app(fleet_home))
    return client, store, secrets, calls


def _issue_updated(key="FLE-3", items=None):
    if items is None:
        items = [{"field": "status", "fromString": "To Do", "toString": "Dispatched"}]
    return {
        "webhookEvent": "jira:issue_updated",
        "timestamp": 1760000000000,
        "issue": {"key": key, "fields": {"status": {"name": "Dispatched"}}},
        "changelog": {"items": items},
    }


def _post(client, project, payload, secret, delivery_id="d1", sign=True, headers=None):
    body = payload if isinstance(payload, bytes) else json.dumps(payload).encode()
    hdrs = {"Content-Type": "application/json"}
    if sign:
        digest = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
        hdrs["X-Hub-Signature"] = f"sha256={digest}"
    if delivery_id is not None:
        hdrs["X-Atlassian-Webhook-Identifier"] = delivery_id
    hdrs.update(headers or {})
    return client.post(f"/hooks/jira/{project}", content=body, headers=hdrs)


def _wait_job(client, job_id):
    for _ in range(100):
        state = client.get(f"/api/jobs/{job_id}").json()["state"]
        if state not in ("queued", "running"):
            return state
        time.sleep(0.05)
    raise AssertionError("job did not finish")


def test_hook_unknown_project_404(hook_env):
    client, _, secrets, calls = hook_env
    resp = _post(client, "ghost", _issue_updated(), secrets["p"])
    assert resp.status_code == 404
    assert resp.json() == {"error": "not found"}
    assert not calls


def test_hook_project_without_hooks_404(hook_env):
    client, _, secrets, _ = hook_env
    assert _post(client, "noh", _issue_updated(), secrets["p"]).status_code == 404


def test_hook_no_secret_404(hook_env, fleet_home):
    client, store, secrets, _ = hook_env
    # A project with hooks but no secret: drop the file so none is configured.
    (fleet_home / "webhooks" / "secrets.env").unlink()
    assert _post(client, "p", _issue_updated(), secrets["p"]).status_code == 404
    assert store.tail_log() == []  # 404s are never logged


def test_hook_bad_signature_401(hook_env):
    client, store, _, calls = hook_env
    resp = _post(client, "p", _issue_updated(), "wrong-secret")
    assert resp.status_code == 401
    assert resp.json() == {"error": "unauthorized"}
    assert not calls
    assert store.tail_log()[-1]["result"] == "unauthorized"


def test_hook_missing_signature_401(hook_env):
    client, store, secrets, calls = hook_env
    resp = _post(client, "p", _issue_updated(), secrets["p"], sign=False)
    assert resp.status_code == 401
    assert not calls
    assert store.tail_log()[-1]["result"] == "unauthorized"


def test_hook_too_large_413(hook_env):
    client, _, secrets, calls = hook_env
    resp = _post(client, "p", b"x" * (MAX_BODY_BYTES + 1), secrets["p"])
    assert resp.status_code == 413
    assert resp.json() == {"error": "payload too large"}
    assert not calls


@pytest.mark.parametrize(
    "body",
    [
        b"[]",
        b"\xff\xfe",
        b"",
        b'{"webhookEvent": "jira:issue_updated"}',
        b"[" * 100000,
    ],
)
def test_hook_garbage_body_400(hook_env, body):
    client, store, secrets, calls = hook_env
    resp = _post(client, "p", body, secrets["p"])
    assert resp.status_code == 400
    assert resp.headers["content-type"].startswith("application/json")
    assert "error" in resp.json()
    assert not calls
    assert store.tail_log()[-1]["result"] == "bad_request"


@pytest.mark.parametrize(
    "payload, reason",
    [
        ({"webhookEvent": "comment_created"}, "event comment_created"),
        (
            _issue_updated(items=[{"field": "summary", "fromString": "a", "toString": "b"}]),
            "no status change",
        ),
        (
            _issue_updated(
                items=[{"field": "status", "fromString": "Dispatched", "toString": "Done"}]
            ),
            "no rule for status Done",
        ),
        (_issue_updated(key="OTHER-1"), "issue_id_regexp"),
    ],
)
def test_hook_ignored_cases(hook_env, payload, reason):
    client, store, secrets, calls = hook_env
    resp = _post(client, "p", payload, secrets["p"])
    assert resp.status_code == 200
    body = resp.json()
    assert body["result"] == "ignored"
    assert reason in body["reason"]
    assert not calls
    assert store.tail_log()[-1]["result"] == "ignored"
    # Ignored events never consume the delivery id.
    assert store.claim_delivery("p", "d1") is True


def test_hook_accepted_submits_deploy(hook_env):
    client, store, secrets, calls = hook_env
    resp = _post(
        client, "p", _issue_updated(), secrets["p"], headers={"X-Atlassian-Webhook-Retry": "1"}
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["result"] == "accepted"
    assert body["instance"] == "p--fle-3"
    assert len(body["job"]) == 12 and int(body["job"], 16) >= 0

    assert _wait_job(client, body["job"]) == "succeeded"
    ((args, kwargs),) = calls
    assert args[2] == "p"
    assert args[3] == "jira-work"
    assert kwargs["label"] == "FLE-3"
    assert kwargs["branch"] is None
    assert kwargs["auth_enabled"] is True

    last = store.tail_log()[-1]
    assert last["result"] == "accepted"
    assert last["issue"] == "FLE-3"
    assert last["to"] == "Dispatched"
    assert last["from"] == "To Do"
    assert last["delivery_id"] == "d1"
    assert last["retry"] == "1"
    assert last["instance"] == "p--fle-3"
    assert last["job"] == body["job"]
    assert set(last) == {
        "ts", "project", "delivery_id", "retry", "event", "issue", "from", "to",
        "result", "reason", "instance", "job",
    }  # fmt: skip


def test_hook_duplicate_delivery(hook_env):
    client, store, secrets, calls = hook_env
    first = _post(client, "p", _issue_updated(), secrets["p"])
    assert first.json()["result"] == "accepted"
    _wait_job(client, first.json()["job"])

    second = _post(client, "p", _issue_updated(), secrets["p"])
    assert second.status_code == 200
    assert second.json() == {"result": "duplicate"}
    assert store.tail_log()[-1]["result"] == "duplicate"
    assert len(calls) == 1

    third = _post(client, "p", _issue_updated(), secrets["p"], delivery_id="d2")
    assert third.json()["result"] == "accepted"
    _wait_job(client, third.json()["job"])
    assert len(calls) == 2


def test_hook_without_delivery_id_processes(hook_env):
    client, store, secrets, calls = hook_env
    for _ in range(2):
        resp = _post(client, "p", _issue_updated(), secrets["p"], delivery_id=None)
        assert resp.json()["result"] == "accepted"
        _wait_job(client, resp.json()["job"])
    assert len(calls) == 2
    assert store.tail_log()[-1]["delivery_id"] is None


def test_hook_resolve_error_422(hook_env, fleet_home):
    client, store, secrets, calls = hook_env
    resp = _post(client, "nobranch", _issue_updated(), secrets["nobranch"])
    assert resp.status_code == 422
    assert resp.headers["content-type"].startswith("application/json")
    assert "error" in resp.json()
    assert not calls
    assert store.tail_log()[-1]["result"] == "error"

    # No marker was claimed: once the config is fixed the retry goes through.
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.write_text(
        _REGISTRY.replace(
            "    git: git@example.test:org/nobranch.git\n",
            "    git: git@example.test:org/nobranch.git\n    default_branch: main\n",
        ),
        encoding="utf-8",
    )
    retry = _post(client, "nobranch", _issue_updated(), secrets["nobranch"])
    assert retry.json()["result"] == "accepted"
    _wait_job(client, retry.json()["job"])


def test_hook_deploy_call_args_match_real_deploy_signature(hook_env):
    """Same guard as test_ui's: the fixture's fake binds every call against
    the REAL `deploy` signature, so a renamed kwarg fails the job (and this
    test) instead of silently failing a background job."""
    client, _, secrets, calls = hook_env
    resp = _post(client, "p", _issue_updated(), secrets["p"])
    assert _wait_job(client, resp.json()["job"]) == "succeeded"
    assert len(calls) == 1
