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

    def fake_ensure(instance_id, instance_dir, *, runner=None):
        calls["instance_id"] = instance_id
        calls["instance_dir"] = instance_dir

    monkeypatch.setattr(instances.tmux, "ensure_instance_window", fake_ensure)

    _stub_deploy_collaborators(monkeypatch, instances)

    paths = instances.FleetPaths.from_home(tmp_path)
    registry = _fake_registry()

    url = instances.deploy(paths, registry, "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"
    assert calls["instance_id"] == "demo--develop"
    assert calls["instance_dir"] == paths.instances / "demo--develop"


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


def _fake_registry():
    class _Registry:
        domain = "fleet.example.test"

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
