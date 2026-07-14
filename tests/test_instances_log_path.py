from fleet.core import instances
from fleet.core.registry import Registry
from fleet.core.secrets import write_secret
from tests.test_instances_deploy import HybridRunner


def _registry_text(fleet_home, git_url):
    return f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_url}
    templates:
      default:
        post_deploy:
          - echo hi
"""


def test_deploy_threads_deploy_log_path_to_git_and_ddev_calls(fleet_home, git_repo):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_registry_text(fleet_home, str(git_repo["origin"])), encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    expected_log = paths.instances / "demo--develop" / ".fleet" / "deploy.log"

    # Fresh deploy: the clone itself must NOT stream into the deploy log
    # (creating .fleet/ inside the clone target would break `git clone`);
    # its captured output is appended to the log after the fact instead.
    runner = HybridRunner()
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    clone_calls = [c for c in runner.calls if c["cmd"][:2] == ["git", "clone"]]
    assert clone_calls and all(c["log_path"] is None for c in clone_calls)
    assert "Cloning into" in expected_log.read_text(encoding="utf-8")
    ddev_start_calls = [c for c in runner.calls if c["cmd"] == ["ddev", "start"]]
    assert ddev_start_calls and all(c["log_path"] == expected_log for c in ddev_start_calls)

    # Redeploy (update path): git fetch/checkout/reset stream into the log live.
    runner = HybridRunner()
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=runner
    )

    git_calls = [c for c in runner.calls if c["cmd"][0] == "git"]
    ddev_start_calls = [c for c in runner.calls if c["cmd"] == ["ddev", "start"]]
    assert git_calls and all(c["log_path"] == expected_log for c in git_calls)
    assert ddev_start_calls and all(c["log_path"] == expected_log for c in ddev_start_calls)
