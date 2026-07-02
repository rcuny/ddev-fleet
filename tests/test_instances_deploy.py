from pathlib import Path

import pytest

from fleet.core import instances
from fleet.core.errors import DeployError
from fleet.core.registry import Registry
from fleet.core.runner import RunResult, run_streamed
from fleet.core.secrets import write_secret


class HybridRunner:
    """Runs real `git` commands against the test fixture repo; fakes
    everything else (ddev, bash post_deploy) so tests never touch Docker."""

    def __init__(self):
        self.calls: list[dict] = []

    def __call__(self, cmd, *, cwd=None, env=None, log_path=None, echo=True):
        self.calls.append({"cmd": list(cmd), "cwd": cwd, "env": env, "log_path": log_path})
        if cmd[0] in ("git", "rsync"):
            return run_streamed(cmd, cwd=cwd, env=env, log_path=log_path, echo=False)
        return RunResult(returncode=0, lines=[])


def _registry_text(fleet_home, git_url):
    return f"""\
fleet:
  domain: fleet.example.test
  assets_path: {fleet_home / "assets"}
  instances_path: {fleet_home / "instances"}

projects:
  demo:
    git: {git_url}
    post_deploy:
      - echo hi
    instances: {{}}
"""


def _make_paths_and_registry(fleet_home, git_url):
    registry_path = fleet_home / "fleet.yml"
    registry_path.write_text(_registry_text(fleet_home, git_url), encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    paths = instances.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    return paths, registry


def test_deploy_fresh_instance_runs_full_pipeline(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    url = instances.deploy(
        paths, registry, "demo", "develop", branch="main", runner=runner
    )

    assert url == "https://demo--develop.fleet.example.test"

    instance_dir = fleet_home / "instances" / "demo--develop"
    assert (instance_dir / "README.md").exists()

    command_names = [call["cmd"][0] for call in runner.calls]
    assert command_names == ["git", "ddev", "bash"]
    assert runner.calls[0]["cmd"][:2] == ["git", "clone"]
    assert runner.calls[1]["cmd"] == ["ddev", "start"]
    assert runner.calls[2]["cmd"] == ["bash", "-c", "echo hi"]
    assert runner.calls[2]["env"]["FLEET_INSTANCE_ID"] == "demo--develop"

    config_path = instance_dir / ".ddev" / "config.fleet.yaml"
    assert config_path.exists()
    assert "sk-ant-oat01-test" in config_path.read_text(encoding="utf-8")

    exclude_path = instance_dir / ".git" / "info" / "exclude"
    assert ".ddev/config.fleet.yaml" in exclude_path.read_text(encoding="utf-8")

    instance_yaml = instance_dir / ".fleet" / "instance.yml"
    assert instance_yaml.exists()
    content = instance_yaml.read_text(encoding="utf-8")
    assert "project: demo" in content
    assert "instance: develop" in content
    assert "branch: main" in content
    assert "created-at" in content
    assert "last-deployed-at" in content

    deploy_log = instance_dir / ".fleet" / "deploy.log"
    assert deploy_log.exists()
    assert deploy_log.stat().st_size > 0


def test_deploy_auto_registers_unknown_instance(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    instances.deploy(paths, registry, "demo", "newinst", branch="main", runner=runner)

    reloaded = Registry.load(paths.registry)
    assert reloaded.has_instance("demo", "newinst") is True
    assert reloaded.resolve("demo", "newinst").branch == "main"


def test_deploy_unknown_instance_without_branch_raises(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    with pytest.raises(DeployError):
        instances.deploy(paths, registry, "demo", "newinst", runner=runner)


def test_deploy_missing_claude_token_raises(fleet_home, git_repo):
    registry_path = fleet_home / "fleet.yml"
    registry_path.write_text(_registry_text(fleet_home, str(git_repo["origin"])), encoding="utf-8")
    # Note: no write_secret() call — .secrets does not exist.
    paths = instances.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    with pytest.raises(DeployError) as excinfo:
        instances.deploy(paths, registry, "demo", "develop", branch="main", runner=runner)

    message = str(excinfo.value)
    assert "CLAUDE_CODE_OAUTH_TOKEN" in message
    assert "fleet init" in message


def test_instance_yaml_created_at_survives_redeploy(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(paths, registry, "demo", "develop", branch="main", runner=HybridRunner())
    info_path = fleet_home / "instances" / "demo--develop" / ".fleet" / "instance.yml"
    first = info_path.read_text(encoding="utf-8")

    instances.deploy(paths, registry, "demo", "develop", branch="main", runner=HybridRunner())
    second = info_path.read_text(encoding="utf-8")

    import re
    created_first = re.search(r"created-at: (\S+)", first).group(1)
    created_second = re.search(r"created-at: (\S+)", second).group(1)
    assert created_first == created_second


def test_deploy_git_excludes_asset_injected_files(fleet_home, git_repo):
    assets_dir = fleet_home / "assets" / "demo"
    assets_dir.mkdir(parents=True, exist_ok=True)
    (assets_dir / ".env").write_text("FOO=bar\n", encoding="utf-8")

    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    instances.deploy(paths, registry, "demo", "develop", branch="main", runner=runner)

    instance_dir = fleet_home / "instances" / "demo--develop"
    assert (instance_dir / ".env").exists()

    exclude_path = instance_dir / ".git" / "info" / "exclude"
    exclude_content = exclude_path.read_text(encoding="utf-8")
    assert ".ddev/config.fleet.yaml" in exclude_content
    assert ".env" in exclude_content
