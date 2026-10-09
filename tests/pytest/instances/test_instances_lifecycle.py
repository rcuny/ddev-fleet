import subprocess

import pytest

from fleet.core import caddyauth, instances
from fleet.core.errors import FleetError
from fleet.core.registry import Registry
from fleet.core.runner import RunResult
from fleet.core.secrets import write_secret
from tests.pytest.instances.test_instances_deploy import HybridRunner, _registry_text


def _run_git(cmd, cwd):
    subprocess.run(cmd, cwd=str(cwd), check=True, capture_output=True, text=True)


def _make_paths_and_registry(fleet_home, git_url):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_registry_text(fleet_home, git_url), encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    return paths, registry


# NOTE: two tests used to live here — `test_update_refuses_dirty_worktree_
# without_force` and `test_update_succeeds_with_force` — covering
# deploy()'s OLD "an existing instance dir is an update" behaviour
# (gitops.update(), honouring `force` and DirtyWorktreeError). That
# behaviour was retired by the 2026-07-27 "deploy never overwrites" design:
# a plain deploy() now always allocates a fresh id instead of landing on an
# existing one, so `force`/dirty-worktree-refusal are no longer reachable
# through deploy() at all (the only way to rebuild an existing id in place
# is `replace=True`, which unconditionally destroys — it never calls
# gitops.update()). The underlying gitops.update() dirty/force behaviour is
# still directly unit-tested in tests/pytest/integrations/test_gitops.py, independent of
# deploy() ever reaching it.


def test_deploy_replace_destroys_and_reclones(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )

    instance_dir = paths.instances / "demo--develop"
    (instance_dir / "stray-file.txt").write_text("leftover\n", encoding="utf-8")

    runner = HybridRunner()
    instances.deploy(
        paths,
        registry,
        "demo",
        "default",
        branch="main",
        label="develop",
        replace=True,
        runner=runner,
    )

    command_names = [call["cmd"][0] for call in runner.calls]
    # The replace destroy's tmux teardown (best-effort `tmux has-session`
    # check, no session in tests) runs first, then `ddev delete`.
    assert command_names[0] == "tmux"
    assert command_names[1] == "ddev"
    assert not (instance_dir / "stray-file.txt").exists()
    assert (instance_dir / "README.md").exists()


def test_destroy_removes_instance_dir_and_lock_file(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )

    instance_dir = paths.instances / "demo--develop"
    lock_path = paths.locks / "demo--develop.lock"
    assert instance_dir.exists()
    assert lock_path.exists()

    instances.destroy(paths, registry, "demo--develop", runner=HybridRunner())

    assert not instance_dir.exists()
    assert not lock_path.exists()


def test_destroy_does_not_delete_the_central_deploy_log(fleet_home, git_repo):
    """The whole point of moving deploy logs to `paths.logs/` (outside
    `instances/`) is that `destroy()` — which `shutil.rmtree`s the entire
    instance directory via `_remove_instance_dir` — must not take the log
    down with it."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )

    deploy_log = paths.logs / "demo--develop" / "deploy.log"
    assert deploy_log.exists()
    logged_content = deploy_log.read_text(encoding="utf-8")
    assert "deploy complete" in logged_content

    instances.destroy(paths, registry, "demo--develop", runner=HybridRunner())

    assert not (paths.instances / "demo--develop").exists()
    assert deploy_log.exists()
    assert deploy_log.read_text(encoding="utf-8") == logged_content


def test_destroy_tolerates_ddev_delete_failure(fleet_home, git_repo):

    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )

    class FailingDeleteRunner(HybridRunner):
        def __call__(self, cmd, *, cwd=None, env=None, log_path=None, echo=True):
            if cmd[:2] == ["ddev", "delete"]:
                self.calls.append({"cmd": list(cmd), "cwd": cwd, "env": env, "log_path": log_path})
                raise RuntimeError("ddev delete exploded")
            return super().__call__(cmd, cwd=cwd, env=env, log_path=log_path, echo=echo)

    instance_dir = paths.instances / "demo--develop"
    instances.destroy(paths, registry, "demo--develop", runner=FailingDeleteRunner())

    assert not instance_dir.exists()


def test_destroy_unknown_instance_raises(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    with pytest.raises(FleetError):
        instances.destroy(paths, registry, "demo--nonexistent", runner=HybridRunner())


def test_remove_instance_dir_removes_populated_dir(tmp_path):
    instance_dir = tmp_path / "instance"
    (instance_dir / "sub").mkdir(parents=True)
    (instance_dir / "file.txt").write_text("x\n", encoding="utf-8")
    (instance_dir / "sub" / "nested.txt").write_text("y\n", encoding="utf-8")

    instances._remove_instance_dir(instance_dir)

    assert not instance_dir.exists()


def test_remove_instance_dir_raises_fleet_error_when_removal_fails(tmp_path, monkeypatch):
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir()
    (instance_dir / "file.txt").write_text("x\n", encoding="utf-8")

    monkeypatch.setattr(instances.shutil, "rmtree", lambda *args, **kwargs: None)

    with pytest.raises(FleetError) as excinfo:
        instances._remove_instance_dir(instance_dir)

    assert str(instance_dir) in str(excinfo.value)


def test_destroy_raises_when_directory_removal_fails(fleet_home, git_repo, monkeypatch):
    """A destroy that cannot fully remove the instance directory must fail
    loudly (FleetError), never silently leave a partial stub on disk."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )

    monkeypatch.setattr(instances.shutil, "rmtree", lambda *args, **kwargs: None)

    with pytest.raises(FleetError):
        instances.destroy(paths, registry, "demo--develop", runner=HybridRunner())


def test_start_and_stop_compose_correct_argv(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )

    instance_dir = paths.instances / "demo--develop"

    start_runner = HybridRunner()
    instances.start(paths, registry, "demo--develop", runner=start_runner)
    assert start_runner.calls[0]["cmd"] == ["ddev", "start"]
    assert start_runner.calls[0]["cwd"] == instance_dir

    stop_runner = HybridRunner()
    instances.stop(paths, registry, "demo--develop", runner=stop_runner)
    assert stop_runner.calls[0]["cmd"] == ["ddev", "stop"]
    assert stop_runner.calls[0]["cwd"] == instance_dir


def test_start_missing_instance_dir_raises(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    with pytest.raises(FleetError):
        instances.start(paths, registry, "demo--nonexistent", runner=HybridRunner())


def test_stop_missing_instance_dir_raises(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    with pytest.raises(FleetError):
        instances.stop(paths, registry, "demo--nonexistent", runner=HybridRunner())


# --- start(retry_port_conflict=True) — the fleet-boot.service self-heal ---


def _make_counting_runner(scripted_results):
    """Returns a `RunResult` per call, one entry of `scripted_results` per
    call in order (extra calls beyond the list repeat the last entry).
    Records the argv of every call for assertions."""
    calls: list[list[str]] = []

    def runner(cmd, *, cwd=None, env=None, log_path=None, echo=True, input_text=None, timeout=None):
        calls.append(list(cmd))
        idx = min(len(calls) - 1, len(scripted_results) - 1)
        returncode, lines = scripted_results[idx]
        return RunResult(returncode=returncode, lines=lines)

    runner.calls = calls
    return runner


def _deploy_demo_develop(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )
    return paths, registry


def test_start_retry_port_conflict_recovers_on_second_attempt(fleet_home, git_repo):
    """First `ddev start` FAST-FAILs with a port-conflict marker; with the
    flag on, `start()` must do exactly one `ddev stop` + `ddev start` and
    succeed without raising."""
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    runner = _make_counting_runner(
        [
            (1, ["Bind for 127.0.0.1:32839 failed: port is already allocated"]),
            (0, []),  # ddev stop
            (0, []),  # ddev start (retry) succeeds
        ]
    )

    instances.start(paths, registry, "demo--develop", retry_port_conflict=True, runner=runner)

    assert runner.calls == [["ddev", "start"], ["ddev", "stop"], ["ddev", "start"]]


def test_start_retry_port_conflict_fails_after_second_conflict(fleet_home, git_repo):
    """A SECOND consecutive port conflict (even after the stop+start retry)
    must still raise FleetError — no infinite retrying."""
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    runner = _make_counting_runner(
        [
            (1, ["port is already allocated"]),
            (0, []),  # ddev stop
            (1, ["port is already allocated"]),
        ]
    )

    with pytest.raises(FleetError):
        instances.start(paths, registry, "demo--develop", retry_port_conflict=True, runner=runner)

    assert runner.calls == [["ddev", "start"], ["ddev", "stop"], ["ddev", "start"]]


def test_start_retry_port_conflict_skips_non_port_conflict_failure(fleet_home, git_repo):
    """A non-port-conflict FleetError must propagate immediately — `ddev
    stop` must never be called."""
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    runner = _make_counting_runner([(1, ["some unrelated ddev start failure"])])

    with pytest.raises(FleetError):
        instances.start(paths, registry, "demo--develop", retry_port_conflict=True, runner=runner)

    assert runner.calls == [["ddev", "start"]]


def test_start_without_retry_flag_does_not_retry_port_conflict(fleet_home, git_repo):
    """Default (flag absent/False): a port-conflict failure behaves exactly
    like today — raises immediately, no stop+start retry."""
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    runner = _make_counting_runner([(1, ["port is already allocated"])])

    with pytest.raises(FleetError):
        instances.start(paths, registry, "demo--develop", runner=runner)

    assert runner.calls == [["ddev", "start"]]


# --- start() reloads the push key into the shared ddev ssh-agent (FLE-5) ---

SSH_ADD_CLEAR = ["ddev", "exec", "ssh-add", "-D"]


def _auth_ssh(paths):
    return ["ddev", "auth", "ssh", "-d", str(paths.push_key_dir)]


def test_start_loads_push_key_after_ddev_start(fleet_home, git_repo):
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    paths.push_key_dir.mkdir(parents=True)
    runner = _make_counting_runner([(0, [])])

    instances.start(paths, registry, "demo--develop", runner=runner)

    assert runner.calls == [["ddev", "start"], SSH_ADD_CLEAR, _auth_ssh(paths)]


def test_start_does_not_load_push_key_when_ddev_start_fails(fleet_home, git_repo):
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    paths.push_key_dir.mkdir(parents=True)
    runner = _make_counting_runner([(1, ["some unrelated ddev start failure"])])

    with pytest.raises(FleetError):
        instances.start(paths, registry, "demo--develop", runner=runner)

    assert runner.calls == [["ddev", "start"]]


def test_start_loads_push_key_after_port_conflict_retry_succeeds(fleet_home, git_repo):
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    paths.push_key_dir.mkdir(parents=True)
    runner = _make_counting_runner(
        [
            (1, ["Bind for 127.0.0.1:32839 failed: port is already allocated"]),
            (0, []),  # ddev stop, ddev start (retry), ssh-add -D, ddev auth ssh
        ]
    )

    instances.start(paths, registry, "demo--develop", retry_port_conflict=True, runner=runner)

    assert runner.calls == [
        ["ddev", "start"],
        ["ddev", "stop"],
        ["ddev", "start"],
        SSH_ADD_CLEAR,
        _auth_ssh(paths),
    ]


def test_start_does_not_load_push_key_when_retry_also_fails(fleet_home, git_repo):
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    paths.push_key_dir.mkdir(parents=True)
    runner = _make_counting_runner(
        [(1, ["port is already allocated"]), (0, []), (1, ["port is already allocated"])]
    )

    with pytest.raises(FleetError):
        instances.start(paths, registry, "demo--develop", retry_port_conflict=True, runner=runner)

    assert runner.calls == [["ddev", "start"], ["ddev", "stop"], ["ddev", "start"]]


def test_start_push_key_auth_failure_warns_but_start_succeeds(fleet_home, git_repo, capsys):
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    paths.push_key_dir.mkdir(parents=True)
    runner = _make_counting_runner([(0, []), (0, []), (1, ["no keys"])])

    instances.start(paths, registry, "demo--develop", runner=runner)  # must not raise

    assert runner.calls == [["ddev", "start"], SSH_ADD_CLEAR, _auth_ssh(paths)]
    assert "WARNING: ddev auth ssh returned 1" in capsys.readouterr().out


def test_start_push_key_runner_exception_warns_but_start_succeeds(fleet_home, git_repo, capsys):
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    paths.push_key_dir.mkdir(parents=True)
    calls: list[list[str]] = []

    def runner(cmd, *, cwd=None, env=None, log_path=None, echo=True, timeout=None):
        calls.append(list(cmd))
        if cmd[:2] == ["ddev", "exec"]:
            raise FleetError("web container not running")
        return RunResult(returncode=0, lines=[])

    instances.start(paths, registry, "demo--develop", runner=runner)  # must not raise

    assert calls == [["ddev", "start"], SSH_ADD_CLEAR]
    assert "WARNING: push-key load failed" in capsys.readouterr().out


def test_start_skips_push_key_when_push_key_dir_missing(fleet_home, git_repo, capsys):
    """A server with no push key must keep starting instances exactly as before."""
    paths, registry = _deploy_demo_develop(fleet_home, git_repo)
    assert not paths.push_key_dir.exists()
    runner = _make_counting_runner([(0, [])])

    instances.start(paths, registry, "demo--develop", runner=runner)

    assert runner.calls == [["ddev", "start"]]
    assert "skipping push-key load" in capsys.readouterr().out


def test_deploy_loads_push_key_exactly_once(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    cmds = [call["cmd"] for call in runner.calls]
    assert cmds.count(SSH_ADD_CLEAR) == 1
    assert cmds.count(_auth_ssh(paths)) == 1


def test_deploy_still_loads_push_key_when_push_key_dir_missing(fleet_home, git_repo):
    """deploy() keeps its pre-FLE-5 behaviour: it does not skip on a missing dir."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    assert not paths.push_key_dir.exists()
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    assert _auth_ssh(paths) in [call["cmd"] for call in runner.calls]


# --- destroy() cleans up the per-instance Caddy auth snippet ---


def test_destroy_removes_instance_auth_snippet(fleet_home, git_repo):
    """A destroyed instance must never leave a stale `@auth-<id>` matcher
    behind — it would either linger unused or collide with a later
    re-deploy of the same instance id."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )
    snippet_path = caddyauth.DEFAULT_INSTANCE_SNIPPET_DIR / "demo--develop.conf"
    assert snippet_path.exists()

    instances.destroy(paths, registry, "demo--develop", runner=HybridRunner())

    assert not snippet_path.exists()


def test_destroy_of_instance_with_auth_disabled_does_not_reload_caddy(fleet_home, git_repo):
    """No snippet ever existed for this instance, so destroy's removal step
    must be a no-op — no pointless validate/reload."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths,
        registry,
        "demo",
        "default",
        branch="main",
        label="develop",
        auth_enabled=False,
        runner=HybridRunner(),
    )

    runner = HybridRunner()
    instances.destroy(paths, registry, "demo--develop", runner=runner)

    reload_calls = [c for c in runner.calls if c["cmd"][:2] == ["caddy", "reload"]]
    assert reload_calls == []


def test_destroy_raises_fleet_error_when_auth_snippet_reload_fails(fleet_home, git_repo):
    """If removing the auth snippet can't be made live (Caddy reload fails),
    destroy() must fail loudly with a FleetError rather than silently
    leaving Caddy running an unknown config."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )

    class FailingReloadRunner(HybridRunner):
        def __call__(self, cmd, *, cwd=None, env=None, log_path=None, echo=True):
            if cmd[:2] == ["caddy", "reload"]:
                self.calls.append({"cmd": list(cmd), "cwd": cwd, "env": env, "log_path": log_path})
                return RunResult(returncode=1, lines=["admin API unreachable"])
            return super().__call__(cmd, cwd=cwd, env=env, log_path=log_path, echo=echo)

    with pytest.raises(FleetError, match="basic-auth"):
        instances.destroy(paths, registry, "demo--develop", runner=FailingReloadRunner())
