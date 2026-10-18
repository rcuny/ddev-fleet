"""Scheduled jobs screen (FLE-31): /schedules, the table partial, Run now, Pause/Resume."""

import re
import time

import pytest
from fastapi.testclient import TestClient

from fleet import daemon as daemon_mod
from fleet.core.instances import FleetPaths
from fleet.core.locks import instance_lock
from fleet.core.schedules import ScheduleStore
from fleet.daemon import create_app

from .test_csp_templates import scan

REGISTRY = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    default_template: default
    default_branch: main
    templates:
      default: {}

schedules:
  nightly:
    cron: "0 3 * * *"
    timezone: Europe/Paris
    action: redeploy
    instance: demo--develop
  weekly-off:
    cron: "30 4 * * 1"
    timezone: UTC
    action: redeploy
    instance: demo--ghost
    enabled: false
  elsewhere:
    cron: "0 5 * * *"
    timezone: UTC
    action: redeploy
    instance: demo--develop
    servers: [ddev9.fleet.pm]
"""

HX = {"HX-Request": "true"}


@pytest.fixture
def env(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(REGISTRY, encoding="utf-8")
    (paths.instances / "demo--develop").mkdir(parents=True)
    app = create_app(fleet_home)
    return paths, ScheduleStore(paths.schedules), TestClient(app), app


def _row(html: str, name: str) -> str:
    return re.search(rf'<tr class="schedule-row" id="schedule-{name}">.*?</tr>', html, re.S).group(
        0
    )


def _wait_for(predicate, timeout=5.0):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if predicate():
            return True
        time.sleep(0.05)
    return False


def test_nav_has_scheduled_jobs_after_webhooks(env):
    _, _, client, _ = env
    for url in ("/", "/schedules"):
        nav = re.search(r'<nav class="main-nav">(.*?)</nav>', client.get(url).text, re.S)
        links = re.findall(r'<a href="([^"]+)">([^<]+)</a>', nav.group(1))
        assert links == [
            ("/", "Instances"),
            ("/secrets", "Secrets"),
            ("/webhooks", "Webhooks"),
            ("/schedules", "Scheduled jobs"),
        ]


def test_page_lists_the_declared_schedules(env):
    _, _, client, _ = env
    resp = client.get("/schedules")
    assert resp.status_code == 200
    assert resp.headers["content-type"].startswith("text/html")
    assert "Content-Security-Policy" in resp.headers
    body = resp.text
    assert len(re.findall(r'<tr class="schedule-row"', body)) == 3
    nightly = _row(body, "nightly")
    assert "<code>0 3 * * *</code>" in nightly and "Europe/Paris" in nightly
    assert "redeploy" in nightly
    # the instance exists: linked to its URL
    assert 'href="https://demo--develop.fleet.example.test"' in nightly
    assert "schedule-state-active" in nightly and ">active<" in nightly
    assert re.search(r"20\d\d-\d\d-\d\d 03:00 CES?T", nightly)  # next run in local time
    assert re.search(r">in \d+[dhm]", nightly)  # the relative hint
    assert 'hx-post="/ui/schedules/nightly/run"' in nightly
    assert 'hx-post="/ui/schedules/nightly/pause"' in nightly
    assert "hx-confirm=" in nightly

    off = _row(body, "weekly-off")
    assert "disabled in fleet.yml" in off
    assert "demo--ghost" in off and "https://demo--ghost" not in body  # no such instance: no link

    other = _row(body, "elsewhere")
    assert "not on this server" in other and "ddev9.fleet.pm" in other
    assert "hx-post" not in other  # nothing to act on from here
    assert "No scheduled run has been recorded yet." in body


def test_partial_is_the_bare_table(env):
    _, _, client, _ = env
    resp = client.get("/ui/schedules/table")
    assert resp.status_code == 200
    assert resp.text.lstrip().startswith('<div id="schedule-table">')
    assert "<html" not in resp.text and "schedule-row" in resp.text
    assert "Content-Security-Policy" in resp.headers


def test_no_schedules_shows_the_empty_state(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(REGISTRY.split("schedules:")[0], encoding="utf-8")
    client = TestClient(create_app(fleet_home))
    for url in ("/schedules", "/ui/schedules/table"):
        resp = client.get(url)
        assert resp.status_code == 200
        assert "No schedule is declared" in resp.text
        assert "schedule-row" not in resp.text


def test_rendered_pages_are_csp_clean_and_escape_logged_values(env):
    paths, store, client, _ = env
    store.append_history(
        {
            "schedule": "nightly",
            "ts": "2026-10-18T03:00:00Z",
            "trigger": "cron",
            "instance": "demo--develop",
            "result": "failed",
            "reason": "<script>alert(1)</script>",
            "job_id": None,
        }
    )
    store.patch(
        "nightly",
        {
            "last_run": {
                "ts": "2026-10-18T03:00:00Z",
                "trigger": "cron",
                "instance": "demo--develop",
                "result": "failed",
                "reason": "<img src=x>",
                "job_id": None,
            }
        },
    )
    for url in ("/schedules", "/ui/schedules/table"):
        body = client.get(url).text
        assert scan(body) == []
        assert "<script>alert" not in body and "<img src=x" not in body
        assert "&lt;script&gt;alert(1)&lt;/script&gt;" in body
        assert "2026-10-18 03:00:00 UTC" in body


def test_history_shows_the_last_20_newest_first(env):
    _, store, client, _ = env
    for i in range(25):
        store.append_history(
            {
                "schedule": "nightly",
                "ts": f"2026-10-{i + 1:02d}T03:00:00Z",
                "trigger": "cron",
                "instance": "demo--develop",
                "result": "succeeded",
                "reason": f"run-{i:02d}",
                "job_id": None,
            }
        )
    body = client.get("/ui/schedules/table").text
    assert len(re.findall(r'<tr class="schedule-history-row">', body)) == 20
    assert body.index("run-24") < body.index("run-23")
    assert "run-04" not in body and "run-05" in body


# --- Run now ---------------------------------------------------------------------------


def test_run_now_submits_a_normal_redeploy_job(env, monkeypatch):
    paths, store, _client, app = env
    calls = []

    def fake_redeploy(paths_, registry, instance_id, **kw):
        calls.append(instance_id)
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "redeploy", fake_redeploy)
    # a persistent event loop: the job task outlives the request that submitted it
    with TestClient(app) as client:
        resp = client.post("/ui/schedules/nightly/run", headers=HX)
        assert resp.status_code == 200
        assert "schedule-notice-ok" in resp.text
        job_id = re.search(r"\(job (\w+)\)", resp.text).group(1)
        # the same JobManager and job list as a UI deploy, with a log path
        job = client.get(f"/api/jobs/{job_id}").json()
        assert job["kind"] == "redeploy" and job["instance_id"] == "demo--develop"
        assert job["log_path"].endswith("logs/demo--develop/deploy.log")
        assert _wait_for(lambda: client.get(f"/api/jobs/{job_id}").json()["state"] == "succeeded")
        assert calls == ["demo--develop"]

        # the table shows the result live; the next tick persists it
        row = _row(client.get("/ui/schedules/table").text, "nightly")
        assert "schedule-result-succeeded" in row and "manual-ui" in row and job_id in row
        assert store.load_state()["nightly"]["last_run"]["result"] == "started"
        app.state.scheduler.reconcile(store)
        assert store.load_state()["nightly"]["last_run"]["result"] == "succeeded"
        assert [h["result"] for h in store.tail_history(10)] == ["started", "succeeded"]


def test_run_now_links_the_log_when_it_exists(env):
    paths, store, client, _ = env
    (paths.logs / "demo--develop").mkdir(parents=True)
    (paths.logs / "demo--develop" / "deploy.log").write_text("hello\n", encoding="utf-8")
    store.patch(
        "nightly",
        {
            "last_run": {
                "ts": "2026-10-18T03:00:00Z",
                "trigger": "cron",
                "instance": "demo--develop",
                "result": "succeeded",
                "reason": None,
                "job_id": "abc123",
            }
        },
    )
    row = _row(client.get("/ui/schedules/table").text, "nightly")
    assert 'href="/ui/instances/demo--develop/log"' in row and "abc123" in row


def test_run_now_on_a_missing_instance_fails_with_a_clear_reason(env):
    paths, store, client, _ = env
    (paths.instances / "demo--develop").rmdir()
    resp = client.post("/ui/schedules/nightly/run", headers=HX)
    assert resp.status_code == 200
    assert "instance demo--develop not found" in resp.text
    assert "schedule-notice-error" in resp.text
    last = store.load_state()["nightly"]["last_run"]
    assert last["result"] == "failed" and last["job_id"] is None


def test_run_now_on_a_busy_instance_is_skipped_not_queued(env, monkeypatch):
    paths, store, client, app = env
    called = []
    monkeypatch.setattr(
        daemon_mod.instances_mod, "redeploy", lambda *a, **k: called.append(a) or "x"
    )
    with instance_lock(paths.locks, "demo--develop"):
        resp = client.post("/ui/schedules/nightly/run", headers=HX)
    assert resp.status_code == 200
    assert "schedule-notice-warn" in resp.text and "instance busy" in resp.text
    assert called == []
    assert app.state.jobs.all() == []  # nothing was queued
    assert store.load_state()["nightly"]["last_run"]["result"] == "skipped"


def test_run_now_unknown_and_foreign_schedules(env):
    _, store, client, _ = env
    resp = client.post("/ui/schedules/ghost/run", headers=HX)
    assert resp.status_code == 404
    assert "unknown schedule" in resp.text and 'id="schedule-table"' in resp.text
    resp = client.post("/ui/schedules/elsewhere/run", headers=HX)
    assert resp.status_code == 400
    assert "not on this server" in resp.text
    assert store.load_state() == {}


def test_run_now_is_post_only_and_cross_origin_writes_are_refused(env):
    _, store, client, _ = env
    assert client.get("/ui/schedules/nightly/run").status_code == 405
    assert client.get("/ui/schedules/nightly/pause").status_code == 405
    for action in ("run", "pause", "resume"):
        resp = client.post(
            f"/ui/schedules/nightly/{action}",
            headers={**HX, "Sec-Fetch-Site": "cross-site"},
        )
        assert resp.status_code == 403
        assert "cross-origin request refused" in resp.text
    resp = client.post(
        "/ui/schedules/nightly/pause", headers={"Origin": "https://evil.example", **HX}
    )
    assert resp.status_code == 403
    assert store.load_state() == {}


# --- Pause / Resume --------------------------------------------------------------------


def test_pause_and_resume_toggle_the_state(env):
    paths, store, client, _ = env
    resp = client.post("/ui/schedules/nightly/pause", headers=HX)
    assert resp.status_code == 200 and "Paused nightly." in resp.text
    assert store.load_state()["nightly"]["paused"] is True
    row = _row(resp.text, "nightly")
    assert ">paused<" in row and "schedule-state-paused" in row
    assert 'hx-post="/ui/schedules/nightly/resume"' in row
    assert "/pause" not in row
    assert not re.search(r">in \d+[dhm]", row)  # a paused schedule has no next run

    resp = client.post("/ui/schedules/nightly/resume", headers=HX)
    assert resp.status_code == 200 and "Resumed nightly." in resp.text
    entry = store.load_state()["nightly"]
    assert entry["paused"] is False
    assert entry["last_fired"]  # resume marks the latest occurrence as seen: no catch-up
    row = _row(resp.text, "nightly")
    assert ">active<" in row and 'hx-post="/ui/schedules/nightly/pause"' in row


def test_pause_unknown_or_foreign_schedule(env):
    _, store, client, _ = env
    assert client.post("/ui/schedules/ghost/pause", headers=HX).status_code == 404
    assert client.post("/ui/schedules/ghost/resume", headers=HX).status_code == 404
    assert client.post("/ui/schedules/elsewhere/pause", headers=HX).status_code == 400
    assert client.post("/ui/schedules/elsewhere/resume", headers=HX).status_code == 400
    assert store.load_state() == {}


def test_a_paused_schedule_with_a_disabled_flag_keeps_both_visible(env):
    _, store, client, _ = env
    store.set_paused("weekly-off", True)
    row = _row(client.get("/ui/schedules/table").text, "weekly-off")
    assert "disabled in fleet.yml" in row and "(paused)" in row
    assert 'hx-post="/ui/schedules/weekly-off/resume"' in row


# --- daemon wiring ---------------------------------------------------------------------


def test_the_scheduler_starts_with_the_app_and_stops_cleanly(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(REGISTRY, encoding="utf-8")
    app = create_app(fleet_home)
    store = ScheduleStore(paths.schedules)
    with TestClient(app):
        # the first tick runs at startup: first sight records the schedules, runs nothing
        assert _wait_for(lambda: "nightly" in store.load_state())
        assert app.state.jobs.all() == []
        task = app.state.scheduler_task
        assert not task.done()
    assert task.cancelled() or task.done()


def test_a_crashed_scheduler_task_is_restarted(fleet_home, caplog):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(REGISTRY, encoding="utf-8")
    app = create_app(fleet_home)
    calls = []

    async def flaky():
        import asyncio

        calls.append(1)
        if len(calls) == 1:
            raise RuntimeError("scheduler crashed")
        await asyncio.sleep(3600)

    app.state.scheduler.run_forever = flaky
    with TestClient(app):
        assert _wait_for(lambda: len(calls) == 2)
    assert "restarting" in caplog.text


def test_ui_redeploy_still_goes_through_the_shared_helper(fleet_home, monkeypatch):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(REGISTRY, encoding="utf-8")
    seen = []

    async def fake_submit(jobs, paths_, registry, instance_id):
        seen.append(instance_id)
        return await jobs.submit("redeploy", instance_id, lambda: "ok", log_path="x")

    monkeypatch.setattr(daemon_mod, "_submit_redeploy_job", fake_submit)
    client = TestClient(create_app(fleet_home))
    assert client.post("/ui/instances/demo--develop/redeploy").status_code == 200
    assert seen == ["demo--develop"]


# --- fail closed on a bad state (I1) -----------------------------------------------------


def _corrupt(store: ScheduleStore) -> str:
    store.root.mkdir(parents=True, exist_ok=True)
    store.state_file.write_text("{ not json", encoding="utf-8")
    return str(store.state_file)


def _unreadable(store: ScheduleStore) -> str:
    store.root.mkdir(parents=True, exist_ok=True)
    store.state_file.mkdir()  # a directory: cannot be read, even by root
    return str(store.state_file)


@pytest.mark.parametrize("make_bad", [_corrupt, _unreadable])
def test_bad_state_renders_the_page_with_a_banner_not_a_500(env, make_bad):
    _, store, client, _ = env
    path = make_bad(store)
    for url in ("/schedules", "/ui/schedules/table"):
        resp = client.get(url)
        assert resp.status_code == 200
        body = resp.text
        assert 'role="alert"' in body and path in body and "Fix:" in body
        # the definitions from fleet.yml are still listed, state shown as unreadable
        assert len(re.findall(r'<tr class="schedule-row"', body)) == 3
        nightly = _row(body, "nightly")
        assert "state unreadable" in nightly
        assert "hx-post" not in nightly  # no action is offered while the state is bad
        assert "disabled in fleet.yml" in _row(body, "weekly-off")
        assert "not on this server" in _row(body, "elsewhere")
        assert scan(body) == []


@pytest.mark.parametrize("make_bad", [_corrupt, _unreadable])
@pytest.mark.parametrize("action", ["run", "pause", "resume"])
def test_bad_state_refuses_run_pause_and_resume(env, monkeypatch, make_bad, action):
    _, store, client, app = env
    called = []
    monkeypatch.setattr(daemon_mod.instances_mod, "redeploy", lambda *a, **k: called.append(a))
    path = make_bad(store)
    before = store.state_file.read_text() if store.state_file.is_file() else None
    resp = client.post(f"/ui/schedules/nightly/{action}", headers=HX)
    assert resp.status_code == 400
    assert path in resp.text and "Fix:" in resp.text
    assert resp.text.count("Fix:") == 1  # the banner is not repeated as a notice
    assert called == [] and app.state.jobs.all() == []
    if before is not None:
        assert store.state_file.read_text() == before  # never overwritten


def test_an_unwritable_state_refuses_the_actions_with_the_banner(env, monkeypatch):
    _, store, client, app = env
    store.patch("nightly", {"paused": False})

    def denied(*_a, **_k):
        raise PermissionError(13, "Permission denied")

    monkeypatch.setattr("fleet.core.schedules.os.replace", denied)
    for action in ("run", "pause", "resume"):
        resp = client.post(f"/ui/schedules/nightly/{action}", headers=HX)
        assert resp.status_code == 400
        assert "cannot be written" in resp.text and "Fix:" in resp.text
    assert app.state.jobs.all() == []
    # reading still works: the page renders normally
    assert client.get("/schedules").status_code == 200


def test_claimed_is_shown_in_the_history_like_the_others(env):
    _, store, client, _ = env
    store.append_history(
        {
            "schedule": "nightly",
            "ts": "2026-10-18T03:00:00Z",
            "trigger": "cron",
            "instance": "demo--develop",
            "result": "claimed",
            "reason": "occurrence 2026-10-18T03:00:00Z",
            "job_id": None,
        }
    )
    body = client.get("/ui/schedules/table").text
    assert "schedule-result-claimed" in body and ">claimed<" in body


def test_resume_records_the_definition(env):
    _, store, client, _ = env
    client.post("/ui/schedules/nightly/resume", headers=HX)
    entry = store.load_state()["nightly"]
    assert entry["cron"] == "0 3 * * *" and entry["timezone"] == "Europe/Paris"
