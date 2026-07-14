import pytest

from fleet.core import instances
from fleet.core.errors import DeployError, TokenError
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

projects:
  demo:
    git: {git_url}
    default_template: default
    templates:
      default:
        post_deploy:
          - echo hi
"""


def _make_paths_and_registry(fleet_home, git_url):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_registry_text(fleet_home, git_url), encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    return paths, registry


def test_deploy_fresh_instance_runs_full_pipeline(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    url = instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    assert url == "https://demo--develop.fleet.example.test"

    instance_dir = paths.instances / "demo--develop"
    assert (instance_dir / "README.md").exists()

    command_names = [call["cmd"][0] for call in runner.calls]
    assert command_names == ["git", "ddev", "ddev", "bash"]
    assert runner.calls[0]["cmd"][:2] == ["git", "clone"]
    assert runner.calls[1]["cmd"] == ["ddev", "auth", "ssh", "-d", str(paths.push_key_dir)]
    assert runner.calls[2]["cmd"] == ["ddev", "start"]
    assert runner.calls[3]["cmd"] == ["bash", "-c", "echo hi"]
    assert runner.calls[3]["env"]["FLEET_INSTANCE_ID"] == "demo--develop"

    all_cmds = [call["cmd"] for call in runner.calls]
    auth_ssh_cmd = ["ddev", "auth", "ssh", "-d", str(paths.push_key_dir)]
    assert auth_ssh_cmd in all_cmds
    assert all_cmds.index(auth_ssh_cmd) < all_cmds.index(["ddev", "start"])

    config_path = instance_dir / ".ddev" / "config.fleet.yaml"
    assert config_path.exists()
    assert "sk-ant-oat01-test" in config_path.read_text(encoding="utf-8")

    exclude_path = instance_dir / ".git" / "info" / "exclude"
    exclude_content = exclude_path.read_text(encoding="utf-8")
    assert ".ddev/config.fleet.yaml" in exclude_content
    assert ".ddev/web-build/Dockerfile.fleet-claude" in exclude_content

    web_build_path = instance_dir / ".ddev" / "web-build" / "Dockerfile.fleet-claude"
    assert web_build_path.exists()
    assert "npm install -g @anthropic-ai/claude-code" in web_build_path.read_text(encoding="utf-8")

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


def test_deploy_label_defaults_to_slugified_branch(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    url = instances.deploy(paths, registry, "demo", "default", branch="main", runner=runner)

    assert url == "https://demo--main.fleet.example.test"
    assert (paths.instances / "demo--main").exists()


def test_deploy_missing_branch_without_default_raises(fleet_home, git_repo):
    """`demo` in _registry_text() sets default_template but no default_branch,
    so omitting branch (with template explicitly given) must exercise the
    missing-branch path specifically."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    with pytest.raises(DeployError):
        instances.deploy(paths, registry, "demo", "default", runner=runner)


def test_deploy_missing_template_without_default_raises(fleet_home, git_repo):
    """A project with a default_branch but no default_template must raise
    DeployError when no template is given — this is the path the old combined
    test never exercised, since its fixture always set default_template."""
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_branch: main
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    with pytest.raises(DeployError):
        instances.deploy(paths, registry, "demo", runner=runner)


def test_deploy_uses_project_default_template_and_branch(fleet_home, git_repo):
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    default_branch: main
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    url = instances.deploy(paths, registry, "demo", runner=runner)

    assert url == "https://demo--main.fleet.example.test"


def test_deploy_missing_claude_token_raises(fleet_home, git_repo):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_registry_text(fleet_home, str(git_repo["origin"])), encoding="utf-8")
    # Note: no write_secret() call — .secrets does not exist.
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    with pytest.raises(DeployError) as excinfo:
        instances.deploy(
            paths, registry, "demo", "default", branch="main", label="develop", runner=runner
        )

    message = str(excinfo.value)
    assert "CLAUDE_CODE_OAUTH_TOKEN" in message
    assert "fleet init" in message


def test_instance_yaml_created_at_survives_redeploy(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )
    info_path = paths.instances / "demo--develop" / ".fleet" / "instance.yml"
    first = info_path.read_text(encoding="utf-8")

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )
    second = info_path.read_text(encoding="utf-8")

    import re

    created_first = re.search(r"created-at: (\S+)", first).group(1)
    created_second = re.search(r"created-at: (\S+)", second).group(1)
    assert created_first == created_second


def test_deploy_git_excludes_asset_injected_files(fleet_home, git_repo):
    paths = instances.FleetPaths.from_home(fleet_home)
    assets_dir = paths.assets / "demo"
    assets_dir.mkdir(parents=True, exist_ok=True)
    (assets_dir / ".env").write_text("FOO=bar\n", encoding="utf-8")

    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    instance_dir = paths.instances / "demo--develop"
    assert (instance_dir / ".env").exists()

    exclude_path = instance_dir / ".git" / "info" / "exclude"
    exclude_content = exclude_path.read_text(encoding="utf-8")
    assert ".ddev/config.fleet.yaml" in exclude_content
    assert ".env" in exclude_content


def test_deploy_writes_additional_fqdns_from_project_hostnames(fleet_home, git_repo):
    """A project declaring `additional_hostnames` must have those hostnames
    resolved to full per-instance FQDNs and written into the instance's
    config.fleet.yaml as `additional_fqdns`."""
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    registry_text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_repo["origin"]}
    default_template: default
    additional_hostnames:
      - albania
    templates:
      default:
        post_deploy:
          - echo hi
"""
    paths.registry.write_text(registry_text, encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    runner = HybridRunner()

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    config_path = paths.instances / "demo--develop" / ".ddev" / "config.fleet.yaml"
    content = config_path.read_text(encoding="utf-8")
    assert "additional_fqdns:" in content
    assert "albania.demo--develop.fleet.example.test" in content


def test_deploy_independent_labels_do_not_clobber_each_other(fleet_home, git_repo):
    """Two deploys for different labels of the same project must both end up
    on disk as independent instances."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))

    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="one", runner=HybridRunner()
    )
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="two", runner=HybridRunner()
    )

    assert (paths.instances / "demo--one").exists()
    assert (paths.instances / "demo--two").exists()


def test_deploy_excludes_token_config_before_asset_injection_fails(fleet_home, git_repo):
    """If asset token substitution raises TokenError, the live token file
    written earlier in deploy() must already be git-excluded — closing the
    window where a TokenError between write_fleet_config() and the final
    ensure_git_exclude() would leave config.fleet.yaml committable."""
    paths = instances.FleetPaths.from_home(fleet_home)
    assets_dir = paths.assets / "demo"
    assets_dir.mkdir(parents=True, exist_ok=True)
    (assets_dir / "broken.txt").write_text("[[nope]]\n", encoding="utf-8")

    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    runner = HybridRunner()

    with pytest.raises(TokenError):
        instances.deploy(
            paths, registry, "demo", "default", branch="main", label="develop", runner=runner
        )

    instance_dir = paths.instances / "demo--develop"
    exclude_path = instance_dir / ".git" / "info" / "exclude"
    assert ".ddev/config.fleet.yaml" in exclude_path.read_text(encoding="utf-8")
