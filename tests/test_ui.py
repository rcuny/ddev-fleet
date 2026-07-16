import re
import time

from fastapi.testclient import TestClient

from fleet.daemon import create_app
from fleet.jobs import Job


def _setup_fleet_home(fleet_home):
    from fleet.core.instances import FleetPaths

    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    default_template: default
    default_branch: main
    templates:
      default: {}
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
        paths,
        registry,
        project,
        template,
        *,
        branch=None,
        label=None,
        fresh=False,
        force=False,
        auth_enabled=True,
        auth_password="fleet",
        runner=None,
    ):
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "deploy", fake_deploy)

    client = TestClient(create_app(fleet_home))
    response = client.post(
        "/ui/deploy",
        data={"project": "demo", "template": "default", "branch": "main", "label": "develop"},
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


def test_ui_deploy_invalid_project_name_returns_400(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)

    response = client.post(
        "/ui/deploy",
        data={"project": "Bad_Name!", "template": "default", "branch": "main"},
    )

    assert response.status_code == 400
    assert "invalid" in response.text


def test_ui_stop_unknown_instance_returns_400(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)

    response = client.post("/ui/instances/nonexistent--x/stop")

    assert response.status_code == 400


def test_job_panel_terminal_state_does_not_poll(fleet_home):
    _setup_fleet_home(fleet_home)
    app = create_app(fleet_home)
    client = TestClient(app)

    app.state.jobs._jobs["term1"] = Job(
        id="term1", kind="deploy", instance_id="demo--develop", state="succeeded"
    )

    response = client.get("/ui/jobs/term1/panel")
    assert response.status_code == 200
    assert "every 2s" not in response.text
    assert 'hx-preserve="true"' in response.text
    assert 'id="log-term1"' in response.text


def test_job_panel_running_state_keeps_polling(fleet_home):
    _setup_fleet_home(fleet_home)
    app = create_app(fleet_home)
    client = TestClient(app)

    app.state.jobs._jobs["run1"] = Job(
        id="run1", kind="deploy", instance_id="demo--develop", state="running"
    )

    response = client.get("/ui/jobs/run1/panel")
    assert response.status_code == 200
    assert "every 2s" in response.text


def test_ui_start_vanished_instance_row_returns_400(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    def fake_start(paths, registry, instance_id, **kw):
        return None

    def fake_list_instances(paths, registry, **kw):
        return []

    monkeypatch.setattr(daemon_mod.instances_mod, "start", fake_start)
    monkeypatch.setattr(daemon_mod.instances_mod, "list_instances", fake_list_instances)

    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)
    response = client.post("/ui/instances/demo--develop/start")

    assert response.status_code == 400
