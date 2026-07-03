from fastapi.testclient import TestClient

from fleet.daemon import create_app


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
