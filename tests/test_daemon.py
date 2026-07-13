import pytest
from fastapi import WebSocketDisconnect
from fastapi.testclient import TestClient

from fleet.daemon import create_app, mint_ws_token, verify_ws_token
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


def test_ws_log_sends_existing_content_then_appended_content(fleet_home):
    _setup_fleet_home(fleet_home)
    instance_dir = fleet_home / "instances" / "demo--develop"
    log_path = instance_dir / ".fleet" / "deploy.log"
    log_path.parent.mkdir(parents=True, exist_ok=True)
    log_path.write_text("line one\n", encoding="utf-8")

    app = create_app(fleet_home)
    client = TestClient(app)
    token = mint_ws_token(app.state.ws_secret, "demo--develop")
    with client.websocket_connect(f"/ws/instances/demo--develop/log?token={token}") as ws:
        first = ws.receive_text()
        assert first == "line one\n"

        log_path.write_text("line one\nline two\n", encoding="utf-8")

        second = ws.receive_text()
        assert second == "line two\n"


def test_ws_log_waits_when_log_file_missing_then_streams_once_created(fleet_home):
    _setup_fleet_home(fleet_home)
    instance_dir = fleet_home / "instances" / "demo--develop"
    log_path = instance_dir / ".fleet" / "deploy.log"

    app = create_app(fleet_home)
    client = TestClient(app)
    token = mint_ws_token(app.state.ws_secret, "demo--develop")
    with client.websocket_connect(f"/ws/instances/demo--develop/log?token={token}") as ws:
        first = ws.receive_text()
        assert first == "waiting for log...\n"

        log_path.parent.mkdir(parents=True, exist_ok=True)
        log_path.write_text("now it exists\n", encoding="utf-8")

        second = ws.receive_text()
        assert second == "now it exists\n"


def test_ws_log_invalid_instance_id_closes_without_server_error(fleet_home):
    _setup_fleet_home(fleet_home)
    app = create_app(fleet_home)
    client = TestClient(app)
    token = mint_ws_token(app.state.ws_secret, "Bad_Id")

    with client.websocket_connect(f"/ws/instances/Bad_Id/log?token={token}") as ws:
        with pytest.raises(WebSocketDisconnect):
            ws.receive_text()


def test_ws_log_missing_token_disconnects(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    with client.websocket_connect("/ws/instances/demo--develop/log") as ws:
        with pytest.raises(WebSocketDisconnect):
            ws.receive_text()


def test_ws_log_garbage_token_disconnects(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    with client.websocket_connect("/ws/instances/demo--develop/log?token=notarealtoken") as ws:
        with pytest.raises(WebSocketDisconnect):
            ws.receive_text()


def test_ws_log_expired_token_disconnects(fleet_home):
    _setup_fleet_home(fleet_home)
    app = create_app(fleet_home)
    client = TestClient(app)
    token = mint_ws_token(app.state.ws_secret, "demo--develop", ttl=-1)

    with client.websocket_connect(f"/ws/instances/demo--develop/log?token={token}") as ws:
        with pytest.raises(WebSocketDisconnect):
            ws.receive_text()


def test_ws_log_token_for_different_instance_disconnects(fleet_home):
    _setup_fleet_home(fleet_home)
    (fleet_home / "instances" / "demo--other").mkdir(parents=True)
    app = create_app(fleet_home)
    client = TestClient(app)
    token = mint_ws_token(app.state.ws_secret, "demo--other")

    with client.websocket_connect(f"/ws/instances/demo--develop/log?token={token}") as ws:
        with pytest.raises(WebSocketDisconnect):
            ws.receive_text()


def test_verify_ws_token_roundtrip_and_mismatch():
    secret = b"x" * 32
    token = mint_ws_token(secret, "x--y")

    assert verify_ws_token(secret, token, "x--y") is True
    assert verify_ws_token(secret, token, "other--id") is False
    assert verify_ws_token(secret, None, "x--y") is False


def test_ui_stop_invalid_instance_id_returns_400(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)

    response = client.post("/ui/instances/Bad_Id/stop")

    assert response.status_code == 400


def test_ws_log_heartbeat_sends_empty_frame_when_idle(fleet_home):
    _setup_fleet_home(fleet_home)
    instance_dir = fleet_home / "instances" / "demo--develop"
    log_path = instance_dir / ".fleet" / "deploy.log"
    log_path.parent.mkdir(parents=True, exist_ok=True)
    log_path.write_text("line one\n", encoding="utf-8")

    app = create_app(fleet_home, heartbeat_every=0.4)
    client = TestClient(app)
    token = mint_ws_token(app.state.ws_secret, "demo--develop")

    with client.websocket_connect(f"/ws/instances/demo--develop/log?token={token}") as ws:
        first = ws.receive_text()
        assert first == "line one\n"

        heartbeat = ws.receive_text()
        assert heartbeat == ""
