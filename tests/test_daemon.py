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
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)
    return fleet_home


def test_exact_label_match_returns_200(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get(
        "/api/tls-authorize", params={"domain": "demo--develop.fleet.example.test"}
    )
    assert response.status_code == 200


def test_flat_multidomain_prefix_returns_200(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get(
        "/api/tls-authorize", params={"domain": "es-demo--develop.fleet.example.test"}
    )
    assert response.status_code == 200


def test_unknown_label_returns_404(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get(
        "/api/tls-authorize", params={"domain": "unknown--thing.fleet.example.test"}
    )
    assert response.status_code == 404


def test_wrong_domain_suffix_returns_404(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get(
        "/api/tls-authorize", params={"domain": "demo--develop.other-domain.test"}
    )
    assert response.status_code == 404


def test_missing_domain_param_returns_422(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/api/tls-authorize")
    assert response.status_code == 422
