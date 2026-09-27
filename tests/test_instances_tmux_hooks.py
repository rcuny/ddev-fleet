from fleet.core import instances
from fleet.core.runner import RunResult


def test_destroy_kills_tmux_window_first_and_swallows_errors(monkeypatch, tmp_path):
    calls = []

    def boom(instance_id, *, runner=None):
        calls.append(instance_id)
        raise RuntimeError("tmux down")

    monkeypatch.setattr(instances.tmux, "kill_instance_window", boom)
    # Stub the rest of _destroy_locked's real collaborators as no-ops.
    monkeypatch.setattr(instances.ddev, "delete", lambda *a, **k: RunResult(0, []))
    monkeypatch.setattr(instances, "_remove_instance_dir", lambda *a, **k: None)
    monkeypatch.setattr(instances.caddyauth, "disable_instance_auth", lambda *a, **k: None)
    monkeypatch.setattr(instances.caddyports, "sync", lambda *a, **k: None)

    (tmp_path / "instances" / "oak--x").mkdir(parents=True)
    paths = instances.FleetPaths.from_home(tmp_path)

    instances._destroy_locked(paths, "oak--x", _fake_registry())

    assert calls == ["oak--x"]  # hook ran despite raising


def test_destroy_kills_tmux_window_before_ddev_delete(monkeypatch, tmp_path):
    order = []

    monkeypatch.setattr(
        instances.tmux,
        "kill_instance_window",
        lambda instance_id, *, runner=None: order.append("tmux"),
    )
    monkeypatch.setattr(
        instances.ddev,
        "delete",
        lambda *a, **k: order.append("ddev") or RunResult(0, []),
    )
    monkeypatch.setattr(instances, "_remove_instance_dir", lambda *a, **k: order.append("rmdir"))
    monkeypatch.setattr(
        instances.caddyauth,
        "disable_instance_auth",
        lambda *a, **k: order.append("caddyauth"),
    )
    monkeypatch.setattr(instances.caddyports, "sync", lambda *a, **k: None)

    (tmp_path / "instances" / "oak--x").mkdir(parents=True)
    paths = instances.FleetPaths.from_home(tmp_path)

    instances._destroy_locked(paths, "oak--x", _fake_registry())

    assert order[0] == "tmux"
    assert order.index("tmux") < order.index("ddev")


def test_deploy_adds_tmux_tab_when_session_exists(monkeypatch, tmp_path):
    calls = {}

    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)

    def fake_ensure(instance_id, instance_dir, *, tty=None, runner=None):
        calls["instance_id"] = instance_id
        calls["instance_dir"] = instance_dir
        calls["tty"] = tty

    monkeypatch.setattr(instances.tmux, "ensure_instance_window", fake_ensure)

    _stub_deploy_collaborators(monkeypatch, instances)

    paths = instances.FleetPaths.from_home(tmp_path)
    registry = _fake_registry()

    url = instances.deploy(paths, registry, "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"
    assert calls["instance_id"] == "demo--develop"
    assert calls["instance_dir"] == paths.instances / "demo--develop"
    # The template resolved by _fake_registry() carries no tty1/tty2, so the
    # plan is empty — tty must be None (not an empty tuple), the sentinel
    # that keeps ensure_instance_window()'s existing no-tty code path intact.
    assert calls["tty"] is None


def test_deploy_passes_resolved_tty_commands_to_ensure_instance_window(monkeypatch, tmp_path):
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)

    calls = {}

    def fake_ensure(instance_id, instance_dir, *, tty=None, runner=None):
        calls["tty"] = tty

    monkeypatch.setattr(instances.tmux, "ensure_instance_window", fake_ensure)

    _stub_deploy_collaborators(monkeypatch, instances)

    paths = instances.FleetPaths.from_home(tmp_path)
    registry = _fake_registry(tty1=["ddev exec claude /jira"], tty2=["ddev drush watchdog:tail"])

    url = instances.deploy(paths, registry, "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"
    assert calls["tty"] == (["ddev exec claude /jira"], ["ddev drush watchdog:tail"])


def test_deploy_logs_skipped_tty_commands_and_still_succeeds(monkeypatch, tmp_path):
    """An unresolvable tty command (spec's lenient path, decision 2) must
    NOT fail an otherwise-complete deploy — it's dropped, with a WARNING
    line in the deploy log, leaving that pane a plain bash shell."""
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)
    monkeypatch.setattr(instances.tmux, "ensure_instance_window", lambda *a, **k: None)

    _stub_deploy_collaborators(monkeypatch, instances)

    paths = instances.FleetPaths.from_home(tmp_path)
    # No issue_id_regexp configured for this fake project, so [[issue-id]]
    # can never resolve — this tty1 command must be skipped.
    registry = _fake_registry(tty1=["ddev exec claude [[issue-id]]"])

    url = instances.deploy(paths, registry, "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"
    deploy_log = paths.logs / "demo--develop" / "deploy.log"
    log_content = deploy_log.read_text(encoding="utf-8")
    assert (
        "WARNING: skipped tty1 (unresolved [[issue-id]]): ddev exec claude [[issue-id]]"
        in log_content
    )


def test_deploy_swallows_tty_resolution_errors(monkeypatch, tmp_path):
    """A failure while RESOLVING the tty plan (not just ensure_instance_window
    raising) must still only warn — the whole plan_from_template() +
    ensure_instance_window() sequence lives inside the same best-effort
    try/except as the rest of the tmux hook."""
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)

    def fail_ensure(*a, **k):
        raise AssertionError("ensure_instance_window should not be reached")

    monkeypatch.setattr(instances.tmux, "ensure_instance_window", fail_ensure)

    _stub_deploy_collaborators(monkeypatch, instances)

    paths = instances.FleetPaths.from_home(tmp_path)
    registry = _fake_registry(resolve_raises=RuntimeError("registry blew up"))

    url = instances.deploy(paths, registry, "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"
    deploy_log = paths.logs / "demo--develop" / "deploy.log"
    assert "WARNING: tmux tab update failed" in deploy_log.read_text(encoding="utf-8")


def test_deploy_skips_tmux_tab_when_no_session(monkeypatch, tmp_path):
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: False)

    def fail_ensure(*a, **k):
        raise AssertionError("ensure_instance_window should not be called")

    monkeypatch.setattr(instances.tmux, "ensure_instance_window", fail_ensure)

    _stub_deploy_collaborators(monkeypatch, instances)

    paths = instances.FleetPaths.from_home(tmp_path)
    registry = _fake_registry()

    url = instances.deploy(paths, registry, "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"


def test_deploy_swallows_tmux_hook_errors(monkeypatch, tmp_path):
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)

    def boom(*a, **k):
        raise RuntimeError("tmux down")

    monkeypatch.setattr(instances.tmux, "ensure_instance_window", boom)

    _stub_deploy_collaborators(monkeypatch, instances)

    paths = instances.FleetPaths.from_home(tmp_path)
    registry = _fake_registry()

    # Must not raise despite the tmux hook blowing up.
    url = instances.deploy(paths, registry, "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"


def _fake_runner(cmd, **kwargs):
    return RunResult(0, [])


def _fake_registry(*, tty1=None, tty2=None, issue_id_regexp=None, resolve_raises=None):
    """Fake `Registry` used both by `deploy()`'s own pipeline AND (new in
    step 3) by `ttycmds.plan_from_template()`'s own `registry.resolve()`
    call — the tmux hook re-resolves the template independently of
    `resolve_target()` (which the tests below monkeypatch away), so this
    fake needs a real `resolve()`/`issue_id_regexp()` to feed it."""

    class _Registry:
        domain = "fleet.example.test"
        auth_bypass_cidrs: list[str] = []
        auth_mode = "basic"

        def git_url(self, project):
            return "git@example.test:org/demo.git"

        def additional_hostnames(self, project):
            return []

        def typesense_enabled(self, project):
            return False

        def git_bot(self, project=None):
            return None

        def port_profile(self, name):
            from fleet.core.registry import PortProfile

            if name == "typesense":
                return PortProfile(name="typesense", public=9108, router=8108)
            raise AssertionError(f"unexpected port_profile call: {name!r}")

        def project_ports(self, project):
            return []

        def all_port_profiles(self):
            return []

        def issue_id_regexp(self, project):
            return issue_id_regexp

        def resolve(self, project, template, branch, label=None):
            if resolve_raises is not None:
                raise resolve_raises
            from fleet.core.registry import ResolvedInstance

            return ResolvedInstance(
                project=project,
                template=template,
                branch=branch,
                label=label or branch,
                post_deploy=[],
                instance_id=f"{project}--{label or branch}",
                tty1=list(tty1 or []),
                tty2=list(tty2 or []),
            )

    return _Registry()


def _stub_deploy_collaborators(monkeypatch, mod):
    """Stub every real collaborator deploy() touches so it runs end-to-end
    against a tmp_path without a real git/ddev/caddy — mirrors the shape of
    the other deploy() tests in tests/test_instances.py."""

    class _Resolved:
        instance_id = "demo--develop"
        template = "default"
        label = "develop"
        branch = "develop"
        post_deploy = []
        drupal_env = None

    monkeypatch.setattr(mod, "resolve_target", lambda *a, **k: _Resolved())
    monkeypatch.setattr(mod.gitops, "clone", lambda *a, **k: RunResult(0, []))
    monkeypatch.setattr(mod.gitops, "update", lambda *a, **k: RunResult(0, []))
    monkeypatch.setattr(mod, "read_secrets", lambda *a, **k: {"CLAUDE_CODE_OAUTH_TOKEN": "tok"})
    monkeypatch.setattr(mod.caddyauth, "enable_instance_auth", lambda *a, **k: None)
    monkeypatch.setattr(mod.caddyauth, "disable_instance_auth", lambda *a, **k: None)
    monkeypatch.setattr(mod.caddyports, "sync", lambda *a, **k: None)
    monkeypatch.setattr(mod, "write_fleet_config", lambda *a, **k: None)
    monkeypatch.setattr(mod, "write_web_build", lambda *a, **k: None)
    monkeypatch.setattr(mod, "write_ddev_env", lambda *a, **k: None)
    monkeypatch.setattr(mod, "write_settings_local", lambda *a, **k: [])
    monkeypatch.setattr(mod, "ensure_git_exclude", lambda *a, **k: None)
    monkeypatch.setattr(mod, "build_context", lambda *a, **k: {})
    monkeypatch.setattr(mod, "secret_tokens", lambda *a, **k: {})
    monkeypatch.setattr(mod.assets_mod, "inject", lambda *a, **k: [])
    monkeypatch.setattr(mod.ddev, "start", lambda *a, **k: RunResult(0, []))
    monkeypatch.setattr(mod, "env_vars", lambda *a, **k: {})
    monkeypatch.setattr(mod, "_write_instance_yaml", lambda *a, **k: None)
