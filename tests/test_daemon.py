import json
import re
import time
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


def test_unregistered_alias_prefix_returns_404(fleet_home):
    """A `<prefix>-<instance_id>` label is NOT authorized unless `prefix` is
    one of that instance's project's registered `additional_hostnames` —
    this replaced the old generic `label.endswith(f"-{instance_id}")`
    acceptance (security bug: it let anyone mint a cert for an arbitrary,
    unregistered alias pointed at a real instance)."""
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get(
        "/api/tls-authorize", params={"domain": "es-demo--develop.fleet.example.test"}
    )
    assert response.status_code == 404


def test_registered_alias_hostname_returns_200(fleet_home):
    """A label matching `<h>-<instance_id>`, for an `h` listed in the
    instance's project's `additional_hostnames`, IS authorized — the
    flattened alias form (`core.instances.alias_fqdns`)."""
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    additional_hostnames:
      - es
    templates:
      default: {}
""",
        encoding="utf-8",
    )
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)
    client = TestClient(create_app(fleet_home))

    response = client.get(
        "/api/tls-authorize", params={"domain": "es-demo--develop.fleet.example.test"}
    )
    assert response.status_code == 200


def test_alias_hostname_for_unknown_project_returns_404(fleet_home):
    """An instance whose recorded/derived project no longer exists in the
    registry authorizes only its bare instance label — never an alias,
    since there is no `additional_hostnames` list to check it against."""
    fleet_home_paths = FleetPaths.from_home(fleet_home)
    fleet_home_paths.registry.parent.mkdir(parents=True, exist_ok=True)
    fleet_home_paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  other:
    git: git@example.test:org/other.git
    templates:
      default: {}
""",
        encoding="utf-8",
    )
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)
    client = TestClient(create_app(fleet_home))

    bare = client.get("/api/tls-authorize", params={"domain": "demo--develop.fleet.example.test"})
    assert bare.status_code == 200

    alias = client.get(
        "/api/tls-authorize", params={"domain": "es-demo--develop.fleet.example.test"}
    )
    assert alias.status_code == 404


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


def test_ui_bulk_start_returns_bulk_job_panel(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    (fleet_home / "instances" / "demo--other").mkdir(parents=True)
    from fleet import daemon as daemon_mod

    monkeypatch.setattr(daemon_mod.instances_mod, "start", lambda paths, registry, iid, **kw: None)

    # `with` (not a bare TestClient()) is required here: /ui/bulk/start's
    # job body calls bulk_mod.run_concurrent, which spins up its own nested
    # ThreadPoolExecutor. A bare TestClient() gives every single request its
    # own throwaway anyio blocking portal/event loop (see
    # starlette.testclient.TestClient._portal_factory) — the background
    # asyncio.create_task from JobManager.submit() is scheduled on the POST
    # request's portal loop, and once that portal closes (right after the
    # response is sent) an in-flight asyncio.to_thread() future can no
    # longer report its result back, orphaning the task forever regardless
    # of how many follow-up GETs poll it. run_concurrent's extra thread-pool
    # spin-up is reliably slower than that portal's lifetime, so this
    # deadlocks 100% of the time on a bare TestClient(); a single `with`
    # block keeps one portal/loop alive across the whole poll, matching
    # test_startup_real_sync_honours_isolated_snippet_dir's pattern above.
    with TestClient(create_app(fleet_home)) as client:
        response = client.post(
            "/ui/bulk/start", data={"instance_id": ["demo--develop", "demo--other"]}
        )

        assert response.status_code == 200
        job_id = re.search(r"job-panel-(\w+)", response.text).group(1)

        final = None
        for _ in range(50):
            panel = client.get(f"/ui/jobs/{job_id}/panel")
            if "succeeded" in panel.text:
                final = panel.text
                break
            time.sleep(0.02)

    assert final is not None
    assert "demo--develop" in final
    assert "demo--other" in final


def test_ui_bulk_destroy_confirm_count_mismatch_returns_400_and_destroys_nothing(
    fleet_home, monkeypatch
):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    calls = []
    monkeypatch.setattr(daemon_mod.instances_mod, "destroy", lambda *a, **kw: calls.append(True))

    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)
    response = client.post(
        "/ui/bulk/destroy", data={"instance_id": ["demo--develop"], "confirm_count": 2}
    )

    assert response.status_code == 400
    assert calls == []


def test_ui_bulk_destroy_confirm_count_match_dispatches_bulk_destroy(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    (fleet_home / "instances" / "demo--other").mkdir(parents=True)
    from fleet import daemon as daemon_mod

    calls = []
    monkeypatch.setattr(
        daemon_mod.instances_mod, "destroy", lambda paths, registry, iid, **kw: calls.append(iid)
    )

    client = TestClient(create_app(fleet_home))
    response = client.post(
        "/ui/bulk/destroy",
        data={"instance_id": ["demo--develop", "demo--other"], "confirm_count": 2},
    )

    assert response.status_code == 200
    job_id = re.search(r"job-panel-(\w+)", response.text).group(1)

    for _ in range(50):
        panel = client.get(f"/ui/jobs/{job_id}/panel")
        if "succeeded" in panel.text:
            break
        time.sleep(0.02)

    assert set(calls) == {"demo--develop", "demo--other"}


def test_ui_deploy_count_greater_than_1_dispatches_multi_deploy(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    captured = {}

    def fake_multi_deploy(paths, registry, project, template, *, count, **kw):
        captured["count"] = count
        return daemon_mod.bulk_mod.BulkOutcome(
            kind="deploy",
            results=[
                daemon_mod.bulk_mod.BulkResult(
                    instance_id=f"demo--generic-{n}", ok=True, error=None, duration_s=0.0
                )
                for n in range(1, count + 1)
            ],
        )

    monkeypatch.setattr(daemon_mod.bulk_mod, "multi_deploy", fake_multi_deploy)

    client = TestClient(create_app(fleet_home))
    response = client.post(
        "/ui/deploy",
        data={
            "project": "demo",
            "template": "default",
            "branch": "main",
            "label": "generic",
            "count": 3,
        },
    )

    assert response.status_code == 200
    job_id = re.search(r"job-panel-(\w+)", response.text).group(1)

    for _ in range(50):
        panel = client.get(f"/ui/jobs/{job_id}/panel")
        if "succeeded" in panel.text:
            break
        time.sleep(0.02)

    assert captured["count"] == 3


def test_ui_deploy_disk_gate_tripped_returns_400(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod
    from fleet.core.errors import DiskSpaceError

    def failing_check(instances_dir, **kw):
        raise DiskSpaceError("refusing to deploy 5 instances: only 2.0% free")

    monkeypatch.setattr(daemon_mod.sysinfo, "check_disk_headroom", failing_check)

    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)
    response = client.post(
        "/ui/deploy",
        data={"project": "demo", "template": "default", "branch": "main", "count": 5},
    )

    assert response.status_code == 400
    assert "refusing to deploy 5 instances" in response.text


def test_ui_deploy_count_1_path_unchanged(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    def boom(*a, **kw):
        raise AssertionError("multi_deploy must not run for count=1")

    monkeypatch.setattr(daemon_mod.bulk_mod, "multi_deploy", boom)
    monkeypatch.setattr(
        daemon_mod.instances_mod,
        "deploy",
        lambda *a, **kw: "https://demo--develop.fleet.example.test",
    )

    client = TestClient(create_app(fleet_home))
    response = client.post(
        "/ui/deploy",
        data={"project": "demo", "template": "default", "branch": "main", "label": "develop"},
    )

    assert response.status_code == 200


def test_bulk_job_panel_omits_hx_preserve_and_uses_current_instance_ws_url(fleet_home):
    _setup_fleet_home(fleet_home)
    app = create_app(fleet_home)
    client = TestClient(app)

    app.state.jobs._jobs["bulk1"] = Job(
        id="bulk1",
        kind="bulk-start",
        instance_id="",
        state="running",
        instance_ids=["demo--develop", "demo--other"],
        detail=json.dumps(
            {
                "total": 2,
                "done": 1,
                "failed": 0,
                "current": "demo--other",
                "results": [{"instance_id": "demo--develop", "ok": True, "error": None}],
            }
        ),
    )

    response = client.get("/ui/jobs/bulk1/panel")

    assert response.status_code == 200
    assert 'hx-preserve="true"' not in response.text
    assert "/ws/instances/demo--other/log" in response.text


def _extract_ws_token(body: str) -> str:
    match = re.search(r"token=([^\"&]+)", body)
    assert match, f"no ws token found in panel body: {body!r}"
    return match.group(1)


def test_bulk_job_panel_ws_token_verifies_for_current_instance(fleet_home):
    # Regression for the multi-deploy live-log bug: bulk/multi-deploy jobs
    # are submitted with instance_id="" (the real ids live in
    # job.instance_ids / job.detail's "current"), so minting the panel's
    # WS token from job.instance_id signs a token for "" — it can never
    # verify against the instance the socket URL actually points at
    # (progress.current), and the live log silently never connects.
    _setup_fleet_home(fleet_home)
    app = create_app(fleet_home)
    client = TestClient(app)

    app.state.jobs._jobs["bulk1"] = Job(
        id="bulk1",
        kind="bulk-start",
        instance_id="",
        state="running",
        instance_ids=["demo--develop", "demo--other"],
        detail=json.dumps(
            {
                "total": 2,
                "done": 1,
                "failed": 0,
                "current": "demo--other",
                "results": [{"instance_id": "demo--develop", "ok": True, "error": None}],
            }
        ),
    )

    response = client.get("/ui/jobs/bulk1/panel")
    assert response.status_code == 200

    token = _extract_ws_token(response.text)
    assert verify_ws_token(app.state.ws_secret, token, "demo--other") is True


def test_single_deploy_job_panel_ws_token_still_verifies_for_own_instance_id(fleet_home):
    # Regression guard: a single-instance job must keep minting its token
    # for job.instance_id exactly as before — only bulk/multi-deploy jobs
    # should switch to progress.current.
    _setup_fleet_home(fleet_home)
    app = create_app(fleet_home)
    client = TestClient(app)

    app.state.jobs._jobs["solo1"] = Job(
        id="solo1", kind="deploy", instance_id="demo--develop", state="running"
    )

    response = client.get("/ui/jobs/solo1/panel")
    assert response.status_code == 200

    token = _extract_ws_token(response.text)
    assert verify_ws_token(app.state.ws_secret, token, "demo--develop") is True


def test_bulk_job_panel_with_no_current_instance_omits_log_element(fleet_home):
    # Boundary: between bulk-job steps (or right after submit, before the
    # background thread's first on_progress call), progress.current can be
    # empty/None. The panel must not render a log element pointing at an
    # empty instance id (which would mint/verify against "" and never
    # connect) — job_panel.html's `{% if progress.current %}` guard already
    # skips the log div in that case; the 2s poll will pick it up once
    # progress.current is set.
    _setup_fleet_home(fleet_home)
    app = create_app(fleet_home)
    client = TestClient(app)

    app.state.jobs._jobs["bulk2"] = Job(
        id="bulk2",
        kind="multi-deploy",
        instance_id="",
        state="running",
        instance_ids=["demo--develop", "demo--other"],
        detail=None,
    )

    response = client.get("/ui/jobs/bulk2/panel")
    assert response.status_code == 200
    assert "job-log" not in response.text
    assert "/ws/instances//log" not in response.text


# --- per-host domain (host.yml) — 2026-09-22 multi-server shared-config design ---


def test_tls_authorize_uses_host_yml_domain_override(fleet_home):
    """`host.yml`'s `domain` must win over `fleet.yml`'s `fleet.domain` end
    to end through the daemon: `/api/tls-authorize` authorizes hostnames
    under the host.yml domain, not the (different) one recorded in the
    shared fleet.yml."""
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
    paths.host_config.write_text("domain: fleet.other-host.test\n", encoding="utf-8")
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)

    client = TestClient(create_app(fleet_home))

    # host.yml's domain is authorized...
    response = client.get(
        "/api/tls-authorize", params={"domain": "demo--develop.fleet.other-host.test"}
    )
    assert response.status_code == 200

    # ...fleet.yml's own (overridden) domain is not.
    response = client.get(
        "/api/tls-authorize", params={"domain": "demo--develop.fleet.example.test"}
    )
    assert response.status_code == 404
