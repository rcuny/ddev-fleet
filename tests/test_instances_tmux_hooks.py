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


def _recorder(monkeypatch):
    """Record tmux hook calls; session state is set by the caller."""
    calls = {"ensure_session": [], "ensure_window": []}

    def fake_ensure_session(home, *, runner=None):
        calls["ensure_session"].append(home)

    def fake_ensure_window(instance_id, instance_dir, *, tty=None, runner=None):
        calls["ensure_window"].append((instance_id, instance_dir, tty))
        return True

    monkeypatch.setattr(instances.tmux, "ensure_session", fake_ensure_session)
    monkeypatch.setattr(instances.tmux, "ensure_instance_window", fake_ensure_window)
    return calls


def _deploy_log(paths) -> str:
    return (paths.logs / "demo--develop" / "deploy.log").read_text(encoding="utf-8")


def test_deploy_adds_tmux_tab_when_session_exists(monkeypatch, tmp_path):
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)
    calls = _recorder(monkeypatch)
    _stub_deploy_collaborators(monkeypatch, instances)

    paths = instances.FleetPaths.from_home(tmp_path)

    url = instances.deploy(paths, _fake_registry(), "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"
    # No tty commands in the template: tty must be None (not an empty tuple),
    # the sentinel for "plain shells"; an existing session is only joined.
    assert calls["ensure_window"] == [("demo--develop", paths.instances / "demo--develop", None)]
    assert calls["ensure_session"] == []


def test_deploy_types_tty_commands_from_the_deploy_time_template(monkeypatch, tmp_path):
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)
    calls = _recorder(monkeypatch)
    _stub_deploy_collaborators(
        monkeypatch, instances, tty1=["ddev exec claude /jira"], tty2=["ddev drush watchdog:tail"]
    )

    paths = instances.FleetPaths.from_home(tmp_path)

    # _fake_registry().resolve() raises: the plan must come from `resolved`.
    url = instances.deploy(paths, _fake_registry(), "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"
    assert calls["ensure_window"][0][2] == (
        ["ddev exec claude /jira"],
        ["ddev drush watchdog:tail"],
    )


def test_redeploy_types_tty_commands_again(monkeypatch, tmp_path):
    """deploy(replace=True) — what redeploy() calls — is a normal deploy as
    far as the tmux hook goes: it types again (the destroy killed the old
    window first)."""
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)
    calls = _recorder(monkeypatch)
    _stub_deploy_collaborators(monkeypatch, instances, tty2=["echo hi"])
    paths = instances.FleetPaths.from_home(tmp_path)
    registry = _fake_registry()

    instances.deploy(paths, registry, "demo", runner=_fake_runner)
    instances.deploy(paths, registry, "demo", replace=True, runner=_fake_runner)

    assert [c[2] for c in calls["ensure_window"]] == [([], ["echo hi"])] * 2


def test_deploy_logs_skipped_tty_commands_and_still_succeeds(monkeypatch, tmp_path):
    """An unresolvable tty command (spec's lenient path, decision 2) must
    NOT fail an otherwise-complete deploy — it's dropped, with a WARNING
    line in the deploy log, leaving that pane a plain bash shell."""
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)
    calls = _recorder(monkeypatch)
    _stub_deploy_collaborators(monkeypatch, instances, tty1=["ddev exec claude [[issue-id]]"])

    paths = instances.FleetPaths.from_home(tmp_path)
    # No issue_id_regexp configured for this fake project, so [[issue-id]]
    # can never resolve — this tty1 command must be skipped.
    url = instances.deploy(paths, _fake_registry(), "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"
    assert (
        "WARNING: skipped tty1 (unresolved [[issue-id]]): ddev exec claude [[issue-id]]"
        in _deploy_log(paths)
    )
    assert calls["ensure_window"][0][2] is None  # plain shells


def test_deploy_swallows_tty_resolution_errors(monkeypatch, tmp_path):
    """A failure while RESOLVING the tty plan (not just ensure_instance_window
    raising) must still only warn."""
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)

    def boom(*a, **k):
        raise RuntimeError("secrets blew up")

    monkeypatch.setattr(instances.ttycmds, "plan_from_resolved", boom)
    calls = _recorder(monkeypatch)
    _stub_deploy_collaborators(monkeypatch, instances, tty1=["echo hi"])

    paths = instances.FleetPaths.from_home(tmp_path)
    url = instances.deploy(paths, _fake_registry(), "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"
    assert calls["ensure_window"] == []
    assert "WARNING: tmux tab update failed" in _deploy_log(paths)


def test_deploy_no_session_no_tty_does_nothing(monkeypatch, tmp_path):
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: False)
    calls = _recorder(monkeypatch)
    _stub_deploy_collaborators(monkeypatch, instances)

    paths = instances.FleetPaths.from_home(tmp_path)
    url = instances.deploy(
        paths, _fake_registry(), "demo", create_tmux_session=True, runner=_fake_runner
    )

    assert url == "https://demo--develop.fleet.example.test"
    assert calls == {"ensure_session": [], "ensure_window": []}


def test_cli_deploy_with_tty_and_no_session_creates_the_session_then_types(monkeypatch, tmp_path):
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: False)
    calls = _recorder(monkeypatch)
    _stub_deploy_collaborators(monkeypatch, instances, tty1=["echo one"])

    paths = instances.FleetPaths.from_home(tmp_path)
    instances.deploy(paths, _fake_registry(), "demo", create_tmux_session=True, runner=_fake_runner)

    assert calls["ensure_session"] == [paths.home]
    assert calls["ensure_window"][0][2] == (["echo one"], [])


def test_daemon_deploy_with_tty_and_no_session_warns_and_never_creates_it(monkeypatch, tmp_path):
    """The daemon path (create_tmux_session defaults to False): a tmux server
    spawned by fleet.service would die on every `systemctl restart fleet`, so
    it must not create the session — it logs how to fix it instead, and the
    deploy still succeeds."""
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: False)
    calls = _recorder(monkeypatch)
    _stub_deploy_collaborators(monkeypatch, instances, tty1=["echo one"])

    paths = instances.FleetPaths.from_home(tmp_path)
    url = instances.deploy(paths, _fake_registry(), "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"
    assert calls == {"ensure_session": [], "ensure_window": []}
    assert (
        "WARNING: fleet-tmux.service not running: tty commands not typed; start it with "
        "`sudo systemctl start fleet-tmux` and redeploy"
    ) in _deploy_log(paths)


def test_deploy_warns_when_the_window_already_existed(monkeypatch, tmp_path):
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)
    monkeypatch.setattr(
        instances.tmux, "ensure_instance_window", lambda *a, tty=None, runner=None: False
    )
    _stub_deploy_collaborators(monkeypatch, instances, tty1=["echo one"])

    paths = instances.FleetPaths.from_home(tmp_path)
    instances.deploy(paths, _fake_registry(), "demo", runner=_fake_runner)

    assert "tty commands not typed" in _deploy_log(paths)


def test_deploy_swallows_tmux_hook_errors(monkeypatch, tmp_path):
    monkeypatch.setattr(instances.tmux, "session_exists", lambda *, runner=None: True)

    def boom(*a, **k):
        raise RuntimeError("tmux down")

    monkeypatch.setattr(instances.tmux, "ensure_instance_window", boom)

    _stub_deploy_collaborators(monkeypatch, instances)

    paths = instances.FleetPaths.from_home(tmp_path)

    # Must not raise despite the tmux hook blowing up.
    url = instances.deploy(paths, _fake_registry(), "demo", runner=_fake_runner)

    assert url == "https://demo--develop.fleet.example.test"


def _fake_runner(cmd, **kwargs):
    return RunResult(0, [])


def _fake_registry(*, issue_id_regexp=None):
    """Fake `Registry` for deploy()'s pipeline. `resolve()` deliberately
    raises: FLE-6 requires the tty plan to come from the template deploy()
    resolved AT DEPLOY TIME (the `_Resolved` the `resolve_target` stub
    returns), never from a later re-resolution against the live registry."""

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

        def resolve(self, *a, **k):
            raise AssertionError("tty plan must not re-resolve from the live registry")

    return _Registry()


def _stub_deploy_collaborators(monkeypatch, mod, *, tty1=(), tty2=()):
    """Stub every real collaborator deploy() touches so it runs end-to-end
    against a tmp_path without a real git/ddev/caddy — mirrors the shape of
    the other deploy() tests in tests/test_instances.py."""

    class _Resolved:
        project = "demo"
        instance_id = "demo--develop"
        template = "default"
        label = "develop"
        branch = "develop"
        post_deploy = []
        drupal_env = None

    _Resolved.tty1 = list(tty1)
    _Resolved.tty2 = list(tty2)

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
