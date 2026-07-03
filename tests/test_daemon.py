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


def test_per_request_freshness(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    # Initially, newinst--x does not exist
    response = client.get("/api/tls-authorize", params={"domain": "newinst--x.fleet.example.test"})
    assert response.status_code == 404

    # Create the instance directory
    (fleet_home / "instances" / "newinst--x").mkdir(parents=True)

    # Request again, should now succeed (proves per-request freshness)
    response = client.get("/api/tls-authorize", params={"domain": "newinst--x.fleet.example.test"})
    assert response.status_code == 200


def test_hyphen_boundary_near_miss_returns_404(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    # demo--develop exists, but xdemo--develop (without hyphen prefix) must not match
    response = client.get(
        "/api/tls-authorize", params={"domain": "xdemo--develop.fleet.example.test"}
    )
    assert response.status_code == 404


def test_bare_apex_returns_404(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    # Request the fleet domain itself (no instance label)
    response = client.get("/api/tls-authorize", params={"domain": "fleet.example.test"})
    assert response.status_code == 404


def test_dotted_label_returns_404(fleet_home):
    """Spec §9.4: hostnames must be single-label. A label containing a dot
    (e.g. a bogus multi-level subdomain) must be rejected before any
    instance matching is attempted."""
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get(
        "/api/tls-authorize", params={"domain": "a.b-demo--develop.fleet.example.test"}
    )
    assert response.status_code == 404


def test_empty_domain_query_param_returns_404(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/api/tls-authorize", params={"domain": ""})
    assert response.status_code == 404


def test_tls_authorize_response_body_shape_consistent_on_success_and_failure(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    ok = client.get("/api/tls-authorize", params={"domain": "demo--develop.fleet.example.test"})
    fail = client.get("/api/tls-authorize", params={"domain": "unknown--x.fleet.example.test"})

    assert ok.json() == {"authorized": True}
    assert fail.json() == {"authorized": False}


def test_get_unknown_job_returns_404(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/api/jobs/does-not-exist")
    assert response.status_code == 404


def test_get_job_returns_state(fleet_home):
    _setup_fleet_home(fleet_home)
    app = create_app(fleet_home)
    client = TestClient(app)

    app.state.jobs._jobs["abc123"] = Job(
        id="abc123", kind="deploy", instance_id="demo--develop", state="succeeded"
    )

    response = client.get("/api/jobs/abc123")
    assert response.status_code == 200
    assert response.json()["state"] == "succeeded"
