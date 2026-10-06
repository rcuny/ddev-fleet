import inspect
import re
import time

from fastapi.testclient import TestClient

from fleet.core import instances as real_instances_mod
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


def _setup_two_project_fleet_home(fleet_home):
    """Two projects with DISTINCT template names, so "only that project's
    templates" is actually provable (rather than trivially true because
    every project happens to share a template name)."""
    from fleet.core.instances import FleetPaths

    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    default_template: oak-default
    default_branch: main
    templates:
      oak-default: {}
      oak-other: {}
  acme:
    git: git@example.test:org/acme.git
    default_template: acme-only
    default_branch: main
    templates:
      acme-only: {}
""",
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
    assert "<th>Commit</th>" in body  # short-HEAD column after Branch
    assert 'hx-post="/ui/deploy"' in body
    assert "unpkg.com" not in body
    assert "cdn." not in body
    # Basic auth checkbox defaults CHECKED (auth ON by default) and the
    # password field defaults to the documented distribution default.
    assert '<input type="checkbox" name="auth" value="true" checked>' in body
    assert 'name="auth_password" value="fleet"' in body


def test_index_footer_shows_server_memory_and_disk(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    assert 'class="sys-stats"' in body
    assert "RAM free" in body
    assert "disk free" in body
    # disk is always reportable via shutil.disk_usage on the instances mount
    assert re.search(r"disk free.*(GiB|TiB|MiB|KiB|B)", body)


def test_index_shows_reboot_badge_when_pending(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet.core import sysinfo

    monkeypatch.setattr(
        sysinfo,
        "read_reboot_status",
        lambda *a, **k: sysinfo.RebootStatus(pending=True, since=1000.0, packages=["libc6"]),
    )
    client = TestClient(create_app(fleet_home))
    response = client.get("/")
    assert "reboot-badge" in response.text
    assert "libc6" in response.text


def test_index_omits_reboot_badge_when_not_pending(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet.core import sysinfo

    monkeypatch.setattr(
        sysinfo,
        "read_reboot_status",
        lambda *a, **k: sysinfo.RebootStatus(pending=False, since=None, packages=[]),
    )
    client = TestClient(create_app(fleet_home))
    response = client.get("/")
    assert "reboot-badge" not in response.text


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
        replace=False,
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


def test_ui_deploy_call_args_match_real_deploy_signature(fleet_home, monkeypatch):
    """Regression guard for the 2026-07-27 breakage where daemon.py kept
    passing `fresh=` after core/instances.py's deploy() renamed that
    parameter to `replace` — every /ui/deploy job raised TypeError inside
    the job runner (never surfaced to the caller as an HTTP error, just a
    'failed' job) and the existing test above didn't catch it because its
    fake_deploy declares its own explicit signature rather than checking
    against the real one.

    Binds the daemon's actual call args against
    `inspect.signature(instances_mod.deploy)` — the REAL function, imported
    before any monkeypatching — so a future kwarg rename/removal fails this
    test loudly instead of just marking a job 'failed' silently."""
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    real_sig = inspect.signature(real_instances_mod.deploy)

    def fake_deploy(*args, **kwargs):
        real_sig.bind(*args, **kwargs)
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
        if "succeeded" in panel.text or "failed" in panel.text:
            final_state = "terminal"
            break
        time.sleep(0.05)

    assert final_state == "terminal"
    assert "succeeded" in panel.text


def test_ui_deploy_never_lets_the_daemon_create_the_tmux_session(fleet_home, monkeypatch):
    """FLE-6: a tmux server spawned by fleet.service would die on every
    `systemctl restart fleet`, so the web-UI deploy must not ask for it."""
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    seen = {}

    def fake_deploy(*args, **kwargs):
        seen.update(kwargs)
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "deploy", fake_deploy)

    client = TestClient(create_app(fleet_home))
    response = client.post(
        "/ui/deploy",
        data={"project": "demo", "template": "default", "branch": "main", "label": "develop"},
    )
    assert response.status_code == 200
    for _ in range(50):
        if seen:
            break
        time.sleep(0.05)

    assert not seen.get("create_tmux_session", False)


def test_ui_deploy_checkbox_checked_enables_auth_with_given_password(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    captured = {}

    def fake_deploy(paths, registry, project, template, *, auth_enabled, auth_password, **kw):
        captured["auth_enabled"] = auth_enabled
        captured["auth_password"] = auth_password
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "deploy", fake_deploy)

    client = TestClient(create_app(fleet_home))
    client.post(
        "/ui/deploy",
        data={
            "project": "demo",
            "template": "default",
            "branch": "main",
            "label": "develop",
            "auth": "true",
            "auth_password": "s3cret",
        },
    )
    for _ in range(50):
        if "auth_enabled" in captured:
            break
        time.sleep(0.02)

    assert captured == {"auth_enabled": True, "auth_password": "s3cret"}


def test_ui_deploy_unchecked_auth_checkbox_disables_auth_not_default_on(fleet_home, monkeypatch):
    """HTML checkboxes submit NOTHING when unchecked, so the `auth` field is
    simply absent from the form body in that case (simulated here by
    omitting it entirely, exactly like a real unchecked-checkbox submit).
    This must resolve to auth OFF — not silently fall back to the
    default-ON behavior, which is the classic checkbox-handling bug."""
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    captured = {}

    def fake_deploy(paths, registry, project, template, *, auth_enabled, auth_password, **kw):
        captured["auth_enabled"] = auth_enabled
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "deploy", fake_deploy)

    client = TestClient(create_app(fleet_home))
    client.post(
        "/ui/deploy",
        data={
            "project": "demo",
            "template": "default",
            "branch": "main",
            "label": "develop",
            # no "auth" key at all — mirrors an unchecked HTML checkbox
        },
    )
    for _ in range(50):
        if "auth_enabled" in captured:
            break
        time.sleep(0.02)

    assert captured["auth_enabled"] is False


def test_ui_deploy_empty_password_field_falls_back_to_default(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    captured = {}

    def fake_deploy(paths, registry, project, template, *, auth_enabled, auth_password, **kw):
        captured["auth_password"] = auth_password
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "deploy", fake_deploy)

    client = TestClient(create_app(fleet_home))
    client.post(
        "/ui/deploy",
        data={
            "project": "demo",
            "template": "default",
            "branch": "main",
            "label": "develop",
            "auth": "true",
            "auth_password": "",
        },
    )
    for _ in range(50):
        if "auth_password" in captured:
            break
        time.sleep(0.02)

    assert captured["auth_password"] == "fleet"


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


def test_instances_table_has_checkbox_column_and_select_all(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    assert '<input type="checkbox" id="select-all">' in body
    assert 'name="instance_id" value="demo--develop" class="row-select"' in body


def test_bulk_action_bar_renders_below_the_table(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    table_pos = body.index('id="instances-table"')
    actions_pos = body.index('id="bulk-actions"')
    assert actions_pos > table_pos
    assert 'hx-post="/ui/bulk/start"' in body
    assert 'hx-post="/ui/bulk/stop"' in body


def test_deploy_form_count_input_has_expected_bounds(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    assert '<input type="number" name="count" min="0" max="20" step="1" value="1" required>' in body


def test_deploy_form_has_skip_disk_check_checkbox(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    assert 'name="skip_disk_check"' in body


def test_deploy_form_no_longer_renders_fresh_checkbox(fleet_home):
    """`--fresh`/the "Fresh" checkbox is gone (design decision 3,
    2026-07-27-fleet-redeploy-and-no-overwrite-design.md) — `redeploy`
    replaces it."""
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    assert 'name="fresh"' not in body


def test_ui_redeploy_creates_job_and_returns_job_panel(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    calls = []

    def fake_redeploy(paths, registry, instance_id, **kw):
        calls.append(instance_id)
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "redeploy", fake_redeploy)

    client = TestClient(create_app(fleet_home))
    response = client.post("/ui/instances/demo--develop/redeploy")

    assert response.status_code == 200
    assert "job-panel-" in response.text
    job_id = re.search(r"job-panel-(\w+)", response.text).group(1)

    final_state = None
    for _ in range(50):
        panel = client.get(f"/ui/jobs/{job_id}/panel")
        if "succeeded" in panel.text:
            final_state = "succeeded"
            break
        time.sleep(0.05)

    assert final_state == "succeeded"
    assert calls == ["demo--develop"]


def test_ui_redeploy_invalid_instance_id_returns_400(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)

    # Uppercase fails `_INSTANCE_ID_RE` (`^[a-z0-9][a-z0-9-]*$`) — same
    # validate-before-touching-anything pattern as ui_stop/ui_start/etc.
    response = client.post("/ui/instances/Bad_ID/redeploy")

    assert response.status_code == 400


def test_ui_bulk_redeploy_creates_job(fleet_home, monkeypatch):
    _setup_fleet_home(fleet_home)
    from fleet import daemon as daemon_mod

    calls = []

    def fake_redeploy(paths, registry, instance_id, **kw):
        calls.append(instance_id)
        return f"https://{instance_id}.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "redeploy", fake_redeploy)

    client = TestClient(create_app(fleet_home))
    response = client.post("/ui/bulk/redeploy", data={"instance_id": ["demo--develop"]})

    assert response.status_code == 200
    job_id = re.search(r"job-panel-(\w+)", response.text).group(1)

    final_state = None
    for _ in range(50):
        panel = client.get(f"/ui/jobs/{job_id}/panel")
        if "succeeded" in panel.text or "failed" in panel.text:
            final_state = "terminal"
            break
        time.sleep(0.05)

    assert final_state == "terminal"
    assert calls == ["demo--develop"]


def test_instance_row_renders_redeploy_button_targeting_job_panel_slot(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    assert 'hx-post="/ui/instances/demo--develop/redeploy"' in body
    # Must target the job-panel slot, NOT the row — a redeploy is
    # long-running and needs the live log, exactly like a deploy.
    redeploy_btn = body[body.index('hx-post="/ui/instances/demo--develop/redeploy"') :]
    assert 'hx-target="#job-panel-slot"' in redeploy_btn[:400]
    assert "hx-confirm=" in redeploy_btn[:400]


def test_bulk_action_bar_renders_redeploy_selected_button(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    assert 'hx-post="/ui/bulk/redeploy"' in body


def test_bulk_js_is_served(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/static/bulk.js")

    assert response.status_code == 200


def test_bulk_js_closes_socket_on_before_cleanup_element(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/static/bulk.js").text

    assert "htmx:beforeCleanupElement" in body
    assert "_wsSocket" in body
    assert ".close()" in body


def test_bulk_js_wires_select_all_checkbox(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/static/bulk.js").text

    assert "select-all" in body
    assert "row-select" in body


def test_ws_log_js_exposes_socket_reference_for_bulk_js_to_close(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/static/ws-log.js").text

    assert "_wsSocket" in body


def test_ws_log_js_implements_stick_to_bottom_scroll_behaviour(fleet_home):
    # Regression guard for the "log jumps to the top every 2s, then back to
    # the bottom" bug: the fix needs a stickiness threshold, a handler that
    # preserves scroll position across the htmx polling swap, and a guard so
    # the restore doesn't clobber its own state via the scroll event it
    # triggers. Assert the pieces are present rather than trying to drive an
    # actual browser (none is available in this environment).
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/static/ws-log.js")
    body = response.text

    assert response.status_code == 200
    assert "htmx:beforeSwap" in body
    assert "STICK_THRESHOLD" in body
    assert "_wsRestoring" in body
    assert "requestAnimationFrame" in body


def test_ws_log_js_onmessage_autoscroll_is_conditional_on_stuck_state(fleet_home):
    # The original bug's other half: socket.onmessage unconditionally did
    # `el.scrollTop = el.scrollHeight;`, yanking the view to the bottom on
    # every line even if the user had scrolled up. Assert that bare,
    # unconditional form no longer appears in the message handler — the
    # fixed version gates it behind an `if (el._wsStuck)` check. Match on the
    # statement with any leading whitespace so this doesn't break on
    # reformatting, but still fail if the guard is ever removed.
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/static/ws-log.js").text

    assert re.search(r"onmessage\s*=\s*function", body)
    assert not re.search(r"\n\s*el\.scrollTop = el\.scrollHeight;\s*\n", body)
    assert "if (el._wsStuck) el.scrollTop = el.scrollHeight;" in body


def test_base_html_includes_bulk_js_script_tag(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    assert '<script src="/static/bulk.js" defer></script>' in body


def test_bulk_js_wires_destroy_confirm_reveal(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/static/bulk.js").text

    assert "bulk-destroy-trigger" in body
    assert "bulk-destroy-confirm" in body
    assert "bulk-destroy-confirm-count-field" in body
    assert "bulk-destroy-confirm-input" in body
    assert "bulk-destroy-confirm-btn" in body


def test_bulk_js_guards_against_empty_selection_post(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/static/bulk.js").text

    # A bulk button firing with nothing selected must be cancelled before
    # the request is sent, not surfaced as a 422 from the daemon.
    assert "htmx:beforeRequest" in body
    assert "preventDefault" in body
    assert "/ui/bulk/" in body


def test_bulk_js_no_longer_has_its_own_response_error_handler(fleet_home):
    """bulk.js's `htmx:responseError` alert() (superseded 2026-07-26 by the
    page-wide `htmx:beforeSwap` fix in ui-errors.js, which renders the same
    error inline for every hx-target on the page, not just `/ui/bulk/*`)
    must be gone — keeping both would double-report the same failure."""
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/static/bulk.js").text

    assert 'addEventListener("htmx:responseError"' not in body


def test_ui_errors_js_is_served(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/static/ui-errors.js")

    assert response.status_code == 200


def test_ui_errors_js_swaps_4xx_bodies_via_before_swap_hook(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/static/ui-errors.js").text

    # htmx 1.9.12 (src/fleet/static/htmx.min.js) has no `htmx.config.
    # responseHandling` (that's 2.x-only) — `htmx:beforeSwap` + shouldSwap/
    # isError is the documented 1.x way to render a 4xx body into hx-target.
    assert "htmx:beforeSwap" in body
    assert "shouldSwap" in body
    assert "isError" in body


def test_base_html_includes_ui_errors_js_script_tag(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    assert '<script src="/static/ui-errors.js" defer></script>' in body


# --- Deploy-error visibility (htmx 1.9.12 doesn't swap non-2xx responses) ---


def test_ui_deploy_normalises_a_non_dns_safe_label_instead_of_rejecting_it(fleet_home, monkeypatch):
    """A ticket key typed into the Label field is slugified, not refused.

    `OAKS-1781` is the natural thing to type, and it used to 400 with a regex
    the operator had to decode. It is now normalised to `oaks-1781` — see
    `core/registry.py:resolve`. The uppercase form still reaches the tty
    commands, because `[[issue-id]]` matching is case-insensitive and
    uppercases its result (`core/tokens.py:extract_issue_id`)."""
    from fleet import daemon as daemon_mod

    _setup_fleet_home(fleet_home)

    def fake_deploy(*args, **kwargs):
        return "https://demo--oaks-1781.fleet.example.test"

    monkeypatch.setattr(daemon_mod.instances_mod, "deploy", fake_deploy)

    client = TestClient(create_app(fleet_home))
    response = client.post(
        "/ui/deploy",
        data={
            "project": "demo",
            "template": "default",
            "branch": "main",
            "label": "OAKS-1781",
        },
        headers={"HX-Request": "true"},
    )

    assert response.status_code == 200
    assert "demo--oaks-1781" in response.text


def test_ui_deploy_unslugifiable_label_hx_request_returns_html_error(fleet_home):
    """A label that survives slugification as an empty string is still a hard
    error — normalisation rescues odd input, it does not invent a name."""
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)

    response = client.post(
        "/ui/deploy",
        data={
            "project": "demo",
            "template": "default",
            "branch": "main",
            "label": "!!!",
        },
        headers={"HX-Request": "true"},
    )

    assert response.status_code == 400
    # Jinja2 autoescapes the message into the HTML body (partials/error.html
    # has no `|safe`), so the apostrophes come back as `&#39;` entities —
    # assert on the un-quoted substrings rather than the raw message string.
    assert "cannot slugify" in response.text
    assert "!!!" in response.text
    assert "application/json" not in response.headers["content-type"]


def test_ui_deploy_unslugifiable_label_without_hx_request_returns_json(fleet_home):
    _setup_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)

    response = client.post(
        "/ui/deploy",
        data={
            "project": "demo",
            "template": "default",
            "branch": "main",
            "label": "!!!",
        },
    )

    assert response.status_code == 400
    assert "cannot slugify '!!!'" in response.json()["error"]


def test_ui_deploy_mismatched_project_template_hx_request_returns_html_error(fleet_home):
    _setup_two_project_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)

    response = client.post(
        "/ui/deploy",
        data={"project": "oak", "template": "demo", "branch": "main"},
        headers={"HX-Request": "true"},
    )

    assert response.status_code == 400
    # Same autoescaping caveat as the invalid-label HX test above.
    assert "unknown template" in response.text
    assert "demo" in response.text
    assert "oak" in response.text
    assert "application/json" not in response.headers["content-type"]


def test_ui_deploy_mismatched_project_template_without_hx_request_returns_json(fleet_home):
    _setup_two_project_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)

    response = client.post(
        "/ui/deploy",
        data={"project": "oak", "template": "demo", "branch": "main"},
    )

    assert response.status_code == 400
    assert "unknown template 'demo' for project 'oak'" in response.json()["error"]


def test_index_still_returns_200_after_error_partial_wiring(fleet_home):
    _setup_two_project_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/")

    assert response.status_code == 200


# --- Project-scoped template select (/ui/deploy/templates) ---


def test_ui_deploy_templates_returns_only_selected_projects_templates(fleet_home):
    _setup_two_project_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/ui/deploy/templates", params={"project": "acme"})

    assert response.status_code == 200
    assert "acme-only" in response.text
    assert "oak-default" not in response.text
    assert "oak-other" not in response.text


def test_ui_deploy_templates_other_project_returns_its_own_templates_only(fleet_home):
    _setup_two_project_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    response = client.get("/ui/deploy/templates", params={"project": "oak"})

    assert response.status_code == 200
    assert "oak-default" in response.text
    assert "oak-other" in response.text
    assert "acme-only" not in response.text


def test_ui_deploy_templates_unknown_project_returns_400(fleet_home):
    _setup_two_project_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)

    response = client.get("/ui/deploy/templates", params={"project": "nonexistent"})

    assert response.status_code == 400


def test_index_initial_template_select_shows_first_project_templates_only(fleet_home):
    """Regression test for the flattening bug: the initial Template <select>
    used to loop over ALL projects and list every template flat, unlinked
    to the selected project. It must instead show only the first project's
    templates (matching the Project select's default selection)."""
    _setup_two_project_fleet_home(fleet_home)
    client = TestClient(create_app(fleet_home))

    body = client.get("/").text

    template_select = re.search(
        r'<select name="template"[^>]*>.*?</select>', body, re.DOTALL
    ).group(0)
    assert "oak-default" in template_select
    assert "oak-other" in template_select
    assert "acme-only" not in template_select


def test_index_page_renders_authelia_deploy_form_without_password_field(fleet_home):
    from fleet.core.instances import FleetPaths

    _setup_fleet_home(fleet_home)
    paths = FleetPaths.from_home(fleet_home)
    paths.host_config.write_text("auth_mode: authelia\n", encoding="utf-8")

    client = TestClient(create_app(fleet_home))
    body = client.get("/").text

    assert "Basic auth with Authelia" in body
    assert '<input type="checkbox" name="auth" value="true" checked>' in body
    assert 'name="auth_password"' not in body


def test_index_page_basic_mode_still_renders_password_field(fleet_home):
    _setup_fleet_home(fleet_home)

    client = TestClient(create_app(fleet_home))
    body = client.get("/").text

    assert 'name="auth_password" value="fleet"' in body
    assert "Basic auth with Authelia" not in body
