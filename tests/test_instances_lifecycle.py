import subprocess

import pytest

from fleet.core import instances
from fleet.core.errors import DirtyWorktreeError, FleetError
from fleet.core.registry import Registry
from fleet.core.secrets import write_secret
from tests.test_instances_deploy import HybridRunner, _registry_text


def _run_git(cmd, cwd):
    subprocess.run(cmd, cwd=str(cwd), check=True, capture_output=True, text=True)


def _make_paths_and_registry(fleet_home, git_url):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_registry_text(fleet_home, git_url), encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    return paths, registry


def test_update_refuses_dirty_worktree_without_force(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    instance_dir = paths.instances / "demo--develop"
    (instance_dir / "README.md").write_text("dirty\n", encoding="utf-8")

    with pytest.raises(DirtyWorktreeError):
        instances.deploy(
            paths,
            registry,
            "demo",
            "default",
            branch="main",
            label="develop",
            runner=HybridRunner(),
        )


def test_update_succeeds_with_force(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )

    instance_dir = paths.instances / "demo--develop"
    (instance_dir / "README.md").write_text("dirty\n", encoding="utf-8")

    url = instances.deploy(
        paths,
        registry,
        "demo",
        "default",
        branch="main",
        label="develop",
        force=True,
        runner=HybridRunner(),
    )
    assert url == "https://demo--develop.fleet.example.test"
    assert (instance_dir / "README.md").read_text(encoding="utf-8") == "hello\n"


def test_deploy_fresh_destroys_and_reclones(fleet_home, git_repo):
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
        fresh=True,
        runner=runner,
    )

    command_names = [call["cmd"][0] for call in runner.calls]
    assert command_names[0] == "ddev"  # ddev delete, from the fresh destroy, runs first
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
