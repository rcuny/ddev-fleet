import re
import time

from fastapi.testclient import TestClient

from fleet.daemon import create_app
from fleet.jobs import Job


def _setup_fleet_home(fleet_home):
    (fleet_home / "fleet.yml").write_text(
        f"""\
fleet:
  domain: fleet.example.test
  assets_path: {fleet_home / "assets"}
  instances_path: {fleet_home / "instances"}

projects:
  demo:
    git: git@example.test:org/demo.git
    instances: {{}}
""",
        encoding="utf-8",
    )
    instance_dir = fleet_home / "instances" / "demo--develop"
    fleet_dir = instance_dir / ".fleet"
    fleet_dir.mkdir(parents=True)
    (fleet_dir / "instance.yml").write_text(
        "project: demo\ninstance: develop\nbranch: main\n"
        "created-at: '2026-07-01T00:00:00Z'\nlast-deployed-at: '2026-07-01T00:00:00Z'\n",
        encoding="utf-8",
    )
    return fleet_home


def test_index_page_renders_instance_list_and_deploy_form(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/")

    assert response.status_code == 200
    body = response.text
    assert "demo--develop" in body
    assert 'hx-post="/ui/deploy"' in body
    assert "unpkg.com" not in body
    assert "cdn." not in body


def test_static_htmx_is_served(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/static/htmx.min.js")
    assert response.status_code == 200


def test_ui_start_stop_destroy_actions(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    calls = []

    def fake_start(paths, registry, instance_id, **kw):
        calls.append(("start", instance_id))

    def fake_stop(paths, registry, instance_id, **kw):
        calls.append(("stop", instance_id))

    def fake_destroy(paths, registry, instance_id, **kw):
        calls.append(("destroy", instance_id))

    monkeypatch.setattr(daemon_mod.instances_mod, "start", fake_start)
    monkeypatch.setattr(daemon_mod.instances_mod, "stop", fake_stop)
    monkeypatch.setattr(daemon_mod.instances_mod, "destroy", fake_destroy)

    client = TestClient(create_app(fleet_home))

    r1 = client.post("/ui/instances/demo--develop/start")
    assert r1.status_code == 200
    assert "demo--develop" in r1.text

    r2 = client.post("/ui/instances/demo--develop/stop")
    assert r2.status_code == 200

    r3 = client.post("/ui/instances/demo--develop/destroy")
    assert r3.status_code == 200
    assert r3.text == ""

    assert ("start", "demo--develop") in calls
    assert ("stop", "demo--develop") in calls
    assert ("destroy", "demo--develop") in calls


def test_ui_deploy_job_progresses_to_succeeded(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    def fake_deploy(
        paths, registry, project, instance, *, branch=None, fresh=False, force=False, runner=None
    ):
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "deploy", fake_deploy)

    client = TestClient(create_app(fleet_home))
    response = client.post(
        "/ui/deploy", data={"project": "demo", "instance": "develop", "branch": "main"}
    )
    assert response.status_code == 200
    job_id = re.search(r"job-panel-(\w+)", response.text).group(1)

    final_state = None
    for _ in range(50):
        panel = client.get(f"/ui/jobs/{job_id}/panel")
        if "succeeded" in panel.text:
            final_state = "succeeded"
            break
        time.sleep(0.05)

    assert final_state == "succeeded"


def test_ui_job_panel_unknown_job_returns_404(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/ui/jobs/nonexistent/panel")
    assert response.status_code == 404


def test_ui_job_panel_known_job_renders_state(fleet_home):
    _setup_fleet_home(fleet_home)
    app = create_app(fleet_home)
    client = TestClient(app)

    app.state.jobs._jobs["abc123"] = Job(
        id="abc123", kind="deploy", instance_id="demo--develop", state="running"
    )

    response = client.get("/ui/jobs/abc123/panel")
    assert response.status_code == 200
    assert "running" in response.text
