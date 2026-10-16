"""POST /hooks/bitbucket/{project} with `repo:push` + `action: deploy` rules:
the Bitbucket push -> deploy job relay (CI on branch suffixes)."""

import hashlib
import hmac
import inspect
import json
import time

import pytest
from fastapi.testclient import TestClient

from fleet.core import instances as real_instances_mod
from fleet.core.errors import LockHeldError
from fleet.core.instances import FleetPaths
from fleet.core.webhooks import WebhookStore
from fleet.daemon import create_app

_REPO = "acme/website"
_SHA = "c" * 40

_REGISTRY = f"""\
fleet:
  domain: fleet.example.test

projects:
  p:
    git: git@example.test:org/p.git
    templates:
      ci-pw: {{}}
      ci-pw-ml: {{}}
      ci-baseline: {{}}
    bitbucket_hooks:
      - {{on_event: "repo:push", repo: {_REPO}, branch: "*--pw-run", action: deploy, template: ci-pw, label_prefix: "pw-", strip_suffix: "--pw-run"}}
      - {{on_event: "repo:push", repo: {_REPO}, branch: "*--pw-run-ml", action: deploy, template: ci-pw-ml, label_prefix: "pwml-", strip_suffix: "--pw-run-ml"}}
      - {{on_event: "repo:push", repo: {_REPO}, branch: develop, action: deploy, template: ci-baseline, label: pw-regression}}
      - {{on_event: "repo:push", repo: {_REPO}, branch: "*--bare", action: deploy, template: ci-pw, strip_suffix: "--bare"}}
  website:
    git: git@example.test:org/website.git
    templates:
      ci-pw: {{}}
    bitbucket_hooks:
      - {{on_event: "repo:push", repo: {_REPO}, branch: "*--pw-run", action: deploy, template: ci-pw, label_prefix: "pw-", strip_suffix: "--pw-run"}}
  prs:
    git: git@example.test:org/prs.git
    templates:
      default: {{}}
    bitbucket_hooks:
      - {{on_event: "pullrequest:approved", repo: {_REPO}, action: run-pipeline, pattern: x, ref: develop}}
"""  # noqa: E501


@pytest.fixture
def hook_env(fleet_home, monkeypatch):
    """App + Bitbucket secret for `p` (NO pipeline token: deploys need none),
    with `deploy` replaced by a recorder that validates every call against the
    real signature."""
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_REGISTRY, encoding="utf-8")
    store = WebhookStore.from_paths(paths)
    secret = store.create_secret("p", source="bitbucket")
    store.create_secret("prs", source="bitbucket")
    store.create_secret("website", source="bitbucket")

    from fleet import daemon as daemon_mod

    real_sig = inspect.signature(real_instances_mod.deploy)
    calls = []
    state = {"errors": []}

    def fake_deploy(*args, **kwargs):
        real_sig.bind(*args, **kwargs)
        calls.append((args, kwargs))
        if state["errors"]:
            raise state["errors"].pop(0)
        return "https://p--x.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "deploy", fake_deploy)
    monkeypatch.setattr(daemon_mod, "_PUSH_DEPLOY_LOCK_INTERVAL", 0.0)
    # `with`: one event loop for the whole test. Without it every request gets a
    # fresh loop that cancels still-pending job tasks when the request ends.
    with TestClient(create_app(fleet_home)) as client:
        yield client, store, secret, calls, state, daemon_mod


def _change(name, sha=_SHA, kind="branch"):
    return {"new": {"type": kind, "name": name, "target": {"hash": sha}}, "old": None}


_DELETE = {"new": None, "old": {"type": "branch", "name": "gone", "target": {"hash": _SHA}}}


def _push(*changes, repo=_REPO):
    return {"repository": {"full_name": repo}, "push": {"changes": list(changes)}}


def _post(client, payload, secret, project="p", delivery_id="u1", event="repo:push", sign=True):
    body = payload if isinstance(payload, bytes) else json.dumps(payload).encode()
    hdrs = {"Content-Type": "application/json", "X-Event-Key": event}
    if sign:
        digest = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
        hdrs["X-Hub-Signature"] = f"sha256={digest}"
    if delivery_id is not None:
        hdrs["X-Request-UUID"] = delivery_id
    return client.post(f"/hooks/bitbucket/{project}", content=body, headers=hdrs)


def _wait_job(client, job_id):
    for _ in range(100):
        state = client.get(f"/api/jobs/{job_id}").json()["state"]
        if state not in ("queued", "running"):
            return state
        time.sleep(0.05)
    raise AssertionError("job did not finish")


def _log(store):
    return store.tail_log(source="bitbucket")


def test_pw_run_push_deploys_the_pushed_branch_in_place(hook_env):
    client, store, secret, calls, _, _ = hook_env
    resp = _post(client, _push(_change("feature/ABC-1-x--pw-run")), secret)
    assert resp.status_code == 200
    body = resp.json()
    assert body["result"] == "accepted"
    assert body["instance"] == "p--pw-feature-abc-1-x"
    assert len(body["job"]) == 12 and int(body["job"], 16) >= 0
    assert body["deploys"] == [{"instance": "p--pw-feature-abc-1-x", "job": body["job"]}]

    assert _wait_job(client, body["job"]) == "succeeded"
    ((args, kwargs),) = calls
    assert args[2:4] == ("p", "ci-pw")
    assert kwargs == {
        "branch": "feature/ABC-1-x--pw-run",
        "label": "pw-feature-abc-1-x",
        "replace": True,
        "auth_enabled": True,
    }

    last = _log(store)[-1]
    assert last["result"] == "accepted"
    assert last["event"] == "repo:push" and last["repo"] == _REPO
    assert last["branch"] == "feature/ABC-1-x--pw-run" and last["sha"] == _SHA
    assert last["instance"] == "p--pw-feature-abc-1-x" and last["job"] == body["job"]
    assert last["delivery_id"] == "u1"


def test_user_prefixed_uppercase_branch_deploys_the_slugified_instance(hook_env):
    client, store, _, calls, _, _ = hook_env
    branch = "jdoe/hotfix/ABC-1896-Font-patch--pw-run"
    secret = store.read_secret("website", "bitbucket")
    resp = _post(client, _push(_change(branch)), secret, project="website")
    assert resp.json()["instance"] == "website--pw-jdoe-hotfix-abc-1896-font-patch"
    assert _wait_job(client, resp.json()["job"]) == "succeeded"
    ((args, kwargs),) = calls
    assert args[2:4] == ("website", "ci-pw")
    assert kwargs["branch"] == branch  # the real, case-preserved branch is what is cloned
    assert kwargs["label"] == "pw-jdoe-hotfix-abc-1896-font-patch"


def test_pw_run_ml_push_matches_only_the_ml_rule(hook_env):
    client, _, secret, calls, _, _ = hook_env
    resp = _post(client, _push(_change("feature/x--pw-run-ml")), secret)
    assert resp.json()["instance"] == "p--pwml-feature-x"
    _wait_job(client, resp.json()["job"])
    assert [a[3] for a, _ in calls] == ["ci-pw-ml"]


def test_develop_push_deploys_the_baseline_instance(hook_env):
    client, _, secret, calls, _, _ = hook_env
    resp = _post(client, _push(_change("develop")), secret)
    assert resp.json()["instance"] == "p--pw-regression"
    _wait_job(client, resp.json()["job"])
    ((args, kwargs),) = calls
    assert args[3] == "ci-baseline" and kwargs["branch"] == "develop"
    assert kwargs["label"] == "pw-regression" and kwargs["replace"] is True


def test_long_branch_name_is_fitted_to_63_chars(hook_env):
    client, _, secret, calls, _, _ = hook_env
    branch = "feature/" + "very-long-name-" * 6 + "--pw-run"
    resp = _post(client, _push(_change(branch)), secret)
    instance = resp.json()["instance"]
    assert len(instance) == 63 and instance.startswith("p--pw-feature-very-long-name")
    _wait_job(client, resp.json()["job"])
    assert calls[0][1]["label"] == instance.removeprefix("p--")
    assert calls[0][1]["branch"] == branch  # the real branch is still what is cloned


def test_several_matching_branches_submit_one_job_each(hook_env):
    client, store, secret, calls, _, _ = hook_env
    payload = _push(
        _change("a--pw-run", sha="1" * 40),
        _DELETE,
        _change("tag-1", kind="tag"),
        _change("b--pw-run-ml", sha="2" * 40),
        _change("unrelated", sha="3" * 40),
    )
    resp = _post(client, payload, secret)
    body = resp.json()
    assert body["result"] == "accepted"
    assert [d["instance"] for d in body["deploys"]] == ["p--pw-a", "p--pwml-b"]
    assert "instance" not in body and "job" not in body  # top-level only for exactly one
    for deploy in body["deploys"]:
        assert _wait_job(client, deploy["job"]) == "succeeded"
    assert sorted(a[3] for a, _ in calls) == ["ci-pw", "ci-pw-ml"]
    lines = _log(store)[-2:]
    assert [(ln["branch"], ln["sha"], ln["instance"]) for ln in lines] == [
        ("a--pw-run", "1" * 40, "p--pw-a"),
        ("b--pw-run-ml", "2" * 40, "p--pwml-b"),
    ]


def test_branches_resolving_to_the_same_instance_deploy_once(hook_env):
    client, _, secret, calls, _, _ = hook_env
    payload = _push(_change("Feat/x--pw-run"), _change("feat-x--pw-run"))
    body = _post(client, payload, secret).json()
    assert [d["instance"] for d in body["deploys"]] == ["p--pw-feat-x"]
    _wait_job(client, body["deploys"][0]["job"])
    assert calls[0][1]["branch"] == "Feat/x--pw-run"


@pytest.mark.parametrize(
    "payload,reason",
    [
        pytest.param(_push(_DELETE), "no branch update", id="delete-only"),
        pytest.param(_push(), "no branch update", id="no-changes"),
        pytest.param(_push(_change("v1--pw-run", kind="tag")), "no branch update", id="tag-only"),
        pytest.param(_push(_change("feature/x")), "no matching rule", id="no-glob-match"),
        pytest.param(
            _push(_change("feature/x--pw-run-ml-extra")), "no matching rule", id="glob-anchored"
        ),
        pytest.param(
            _push(_change("feature/x--pw-pass"), _DELETE), "no matching rule", id="marker-push"
        ),
        pytest.param(
            _push(_change("feature/x--pw-run"), repo="someone/else"),
            "no matching rule",
            id="other-repo",
        ),
    ],
)
def test_push_ignored(hook_env, payload, reason):
    client, store, secret, calls, _, _ = hook_env
    resp = _post(client, payload, secret)
    assert resp.status_code == 200
    assert resp.json() == {"result": "ignored", "reason": reason}
    assert _log(store)[-1]["result"] == "ignored"
    assert not calls
    # Ignored deliveries never consume the id.
    assert store.claim_delivery("p", "u1", "bitbucket") is True


def test_project_without_a_push_rule_ignores_push_events(hook_env):
    client, store, _, calls, _, _ = hook_env
    resp = _post(client, _push(_change("x--pw-run")), store.read_secret("prs", "bitbucket"), "prs")
    assert resp.json() == {"result": "ignored", "reason": "event repo:push"}
    assert not calls


def test_unresolvable_branch_is_422_and_does_not_claim(hook_env):
    client, store, secret, calls, _, _ = hook_env
    resp = _post(client, _push(_change("--bare")), secret)  # slugifies to nothing
    assert resp.status_code == 422
    assert "cannot slugify" in resp.json()["error"]
    assert _log(store)[-1]["result"] == "error"
    assert not calls
    assert store.claim_delivery("p", "u1", "bitbucket") is True


def test_422_is_all_or_nothing(hook_env):
    client, _, secret, calls, _, _ = hook_env
    resp = _post(client, _push(_change("good--pw-run"), _change("--bare")), secret)
    assert resp.status_code == 422
    time.sleep(0.1)
    assert not calls


def test_duplicate_delivery_is_not_deployed_twice(hook_env):
    client, store, secret, calls, _, _ = hook_env
    first = _post(client, _push(_change("x--pw-run")), secret)
    _wait_job(client, first.json()["job"])
    second = _post(client, _push(_change("x--pw-run")), secret)
    assert second.status_code == 200 and second.json() == {"result": "duplicate"}
    assert _log(store)[-1]["result"] == "duplicate"
    assert len(calls) == 1
    third = _post(client, _push(_change("x--pw-run")), secret, delivery_id="u2")
    assert third.json()["result"] == "accepted"
    _wait_job(client, third.json()["job"])
    assert len(calls) == 2


def test_push_without_delivery_id_is_processed_every_time(hook_env):
    client, _, secret, _, _, _ = hook_env
    for _ in range(2):
        resp = _post(client, _push(_change("x--pw-run")), secret, delivery_id=None)
        assert resp.json()["result"] == "accepted"
        _wait_job(client, resp.json()["job"])


def test_bad_signature_401(hook_env):
    client, store, _, calls, _, _ = hook_env
    assert _post(client, _push(_change("x--pw-run")), "wrong").status_code == 401
    assert _post(client, _push(_change("x--pw-run")), "x", sign=False).status_code == 401
    assert _log(store) == [] and not calls


@pytest.mark.parametrize(
    "body",
    [
        pytest.param(b"not json", id="garbage"),
        pytest.param(b"[]", id="not-an-object"),
        pytest.param(b"{}", id="no-repository"),
        pytest.param(b'{"repository": {"full_name": "acme/website"}}', id="no-push-changes"),
        pytest.param(
            json.dumps(_push({"new": {"type": "branch", "name": "x"}})).encode(), id="no-hash"
        ),
    ],
)
def test_bad_push_body_400(hook_env, body):
    client, store, secret, calls, _, _ = hook_env
    resp = _post(client, body, secret)
    assert resp.status_code == 400
    assert _log(store)[-1]["result"] == "bad_request"
    assert not calls


def test_a_locked_instance_is_retried_until_free(hook_env):
    client, _, secret, calls, state, _ = hook_env
    state["errors"] = [LockHeldError("held"), LockHeldError("held")]
    resp = _post(client, _push(_change("x--pw-run")), secret)
    assert _wait_job(client, resp.json()["job"]) == "succeeded"
    assert len(calls) == 3


def test_a_lock_held_past_the_deadline_fails_the_job(hook_env, monkeypatch):
    client, _, secret, calls, state, daemon_mod = hook_env
    monkeypatch.setattr(daemon_mod, "_PUSH_DEPLOY_LOCK_MAX_WAIT", 0.0)
    state["errors"] = [LockHeldError("held")] * 5
    resp = _post(client, _push(_change("x--pw-run")), secret)
    assert _wait_job(client, resp.json()["job"]) == "failed"
    assert len(calls) == 1


def test_a_failing_deploy_is_a_failed_job_not_a_retry(hook_env):
    client, _, secret, calls, state, _ = hook_env
    state["errors"] = [RuntimeError("clone failed")]
    resp = _post(client, _push(_change("x--pw-run")), secret)
    assert _wait_job(client, resp.json()["job"]) == "failed"
    assert len(calls) == 1


_COLLISION_REGISTRY = f"""\
fleet:
  domain: fleet.example.test

projects:
  website:
    git: git@example.test:org/website.git
    templates:
      ci-pw: {{}}
      ci-baseline: {{}}
    bitbucket_hooks:
      - {{on_event: "repo:push", repo: {_REPO}, branch: "*--pw-run", action: deploy, template: ci-pw, label_prefix: "pw-", strip_suffix: "--pw-run"}}
      - {{on_event: "repo:push", repo: {_REPO}, branch: develop, action: deploy, template: ci-baseline, label: pw-regression}}
"""  # noqa: E501


@pytest.fixture
def collision_env(hook_env, fleet_home):
    client, store, _, calls, _, _ = hook_env
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.write_text(_COLLISION_REGISTRY, encoding="utf-8")
    return client, store, store.read_secret("website", "bitbucket"), calls


def test_derived_label_colliding_with_a_fixed_label_rule_is_ignored(collision_env):
    client, store, secret, calls = collision_env
    # `regression--pw-run` -> `pw-regression`: the baseline instance's id.
    resp = _post(client, _push(_change("regression--pw-run")), secret, project="website")
    assert resp.status_code == 200
    assert resp.json() == {"result": "ignored", "reason": "collides with a fixed-label rule"}
    time.sleep(0.1)
    assert not calls
    last = _log(store)[-1]
    assert last["result"] == "ignored" and last["reason"] == "collides with a fixed-label rule"
    assert last["branch"] == "regression--pw-run" and last["instance"] == "website--pw-regression"
    assert store.claim_delivery("website", "u1", "bitbucket") is True  # not consumed


def test_fixed_label_rule_still_deploys_its_own_instance(collision_env):
    client, _, secret, calls = collision_env
    resp = _post(client, _push(_change("develop")), secret, project="website")
    assert resp.json()["result"] == "accepted"
    assert resp.json()["instance"] == "website--pw-regression"
    _wait_job(client, resp.json()["job"])
    assert calls[0][1]["branch"] == "develop"


def test_colliding_branch_is_skipped_but_the_others_in_the_push_deploy(collision_env):
    client, store, secret, calls = collision_env
    payload = _push(_change("regression--pw-run"), _change("feature/x--pw-run"))
    body = _post(client, payload, secret, project="website").json()
    assert body["result"] == "accepted"
    assert [d["instance"] for d in body["deploys"]] == ["website--pw-feature-x"]
    _wait_job(client, body["deploys"][0]["job"])
    results = [(ln["branch"], ln["result"]) for ln in _log(store)[-2:]]
    assert ("regression--pw-run", "ignored") in results
    assert ("feature/x--pw-run", "accepted") in results
