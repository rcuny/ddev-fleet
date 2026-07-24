from pathlib import Path

import pytest
from fastapi import WebSocketDisconnect
from fastapi.testclient import TestClient

from fleet import daemon
from fleet.core import caddyports
from fleet.core.errors import CaddyPortsError
from fleet.core.instances import FleetPaths
from fleet.daemon import create_app, mint_ws_token, verify_ws_token
from fleet.jobs import Job


def _setup_fleet_home(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default: {}
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
    log_path = FleetPaths.from_home(fleet_home).logs / "demo--develop" / "deploy.log"
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
    log_path = FleetPaths.from_home(fleet_home).logs / "demo--develop" / "deploy.log"

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
    log_path = FleetPaths.from_home(fleet_home).logs / "demo--develop" / "deploy.log"
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


def test_instance_log_route_serves_central_log_as_plain_text(fleet_home):
    _setup_fleet_home(fleet_home)
    log_path = FleetPaths.from_home(fleet_home).logs / "demo--develop" / "deploy.log"
    log_path.parent.mkdir(parents=True, exist_ok=True)
    log_path.write_text("[2026-07-16T00:00:00Z] deploy start\n", encoding="utf-8")

    client = TestClient(create_app(fleet_home))
    response = client.get("/ui/instances/demo--develop/log")

    assert response.status_code == 200
    assert response.text == "[2026-07-16T00:00:00Z] deploy start\n"
    assert response.headers["content-type"].startswith("text/plain")


def test_instance_log_route_returns_404_when_no_log_yet(fleet_home):
    _setup_fleet_home(fleet_home)
    # A syntactically valid instance id with nothing ever logged for it.
    client = TestClient(create_app(fleet_home))

    response = client.get("/ui/instances/demo--develop/log")

    assert response.status_code == 404


def test_instance_log_route_rejects_invalid_instance_id_without_touching_disk(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/ui/instances/Bad_Id/log")

    assert response.status_code == 404


def test_instance_log_route_rejects_path_traversal_attempt(fleet_home):
    """A malicious instance_id must never be able to escape `paths.logs` and
    read an arbitrary file off disk. Starlette's default path converter for
    `{instance_id}` already refuses to route a segment containing `/` (or
    its `%2F` escape) to this endpoint at all — belt-and-braces with the
    explicit `_INSTANCE_ID_RE` check for any single-segment id that isn't a
    plain lowercase/alnum/hyphen instance id."""
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    for attempt in (
        "/ui/instances/../../etc/passwd/log",
        "/ui/instances/..%2F..%2Fetc%2Fpasswd/log",
    ):
        response = client.get(attempt)
        assert response.status_code == 404


def test_startup_syncs_caddy_ports(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    calls = []

    def fake_sync(registry, **kwargs):
        calls.append(registry.domain)
        return caddyports.SyncResult(written=[], removed=[])

    monkeypatch.setattr(daemon.caddyports, "sync", fake_sync)

    with TestClient(create_app(fleet_home)) as _client:
        pass

    assert calls == ["fleet.example.test"]


def test_startup_port_sync_failure_does_not_crash_app(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)

    def failing_sync(registry, **kwargs):
        raise CaddyPortsError("boom")

    monkeypatch.setattr(daemon.caddyports, "sync", failing_sync)

    with TestClient(create_app(fleet_home)) as client:
        response = client.get(
            "/api/tls-authorize", params={"domain": "demo--develop.fleet.example.test"}
        )
    assert response.status_code == 200


def test_startup_port_sync_survives_non_caddyports_error(fleet_home, monkeypatch):
    """IMPORTANT regression: `sync()` does `snippet_dir.mkdir()`/`.glob()`
    before its own internal try/except (core/caddyports.py), so a bare
    `OSError`/`PermissionError` (or anything else unanticipated) can escape
    `CaddyPortsError`'s wrapping. The startup lifespan hook must never let
    ANY exception from the port sync stop the app from booting — a fleet
    manager that refuses to start over one bad port snippet is worse than
    one that boots and reports the problem."""
    _setup_fleet_home(fleet_home)

    def failing_sync(registry, **kwargs):
        raise OSError("permission denied: /etc/caddy/fleet/ports")

    monkeypatch.setattr(daemon.caddyports, "sync", failing_sync)

    with TestClient(create_app(fleet_home)) as client:
        response = client.get(
            "/api/tls-authorize", params={"domain": "demo--develop.fleet.example.test"}
        )
    assert response.status_code == 200


def test_startup_real_sync_honours_isolated_snippet_dir(fleet_home):
    """CRITICAL regression: the lifespan hook must pass `snippet_dir=` to
    `caddyports.sync()` explicitly, not rely on `sync()`'s bound default.
    Every other startup test in this module mocks `daemon.caddyports.sync`
    wholesale, so none would notice a regression to the bare
    `caddyports.sync(registry)` call — `sync()`'s own
    `snippet_dir=DEFAULT_PORTS_SNIPPET_DIR` default is bound at import time,
    so conftest's autouse monkeypatch of the module attribute would silently
    stop applying and the real (unmocked) `sync()` would `mkdir()` the real
    `/etc/caddy/fleet/ports` on the host. This test lets the REAL `sync()`
    run (no mock) and asserts it landed in the isolated tmp_path directory."""
    _setup_fleet_home(fleet_home)
    real_default = Path("/etc/caddy/fleet/ports")
    assert not real_default.exists(), "precondition: real Caddy dir must not pre-exist"

    with TestClient(create_app(fleet_home)) as _client:
        pass

    assert caddyports.DEFAULT_PORTS_SNIPPET_DIR.exists()
    assert caddyports.DEFAULT_PORTS_SNIPPET_DIR.is_relative_to(fleet_home.parent)
    assert not real_default.exists()
