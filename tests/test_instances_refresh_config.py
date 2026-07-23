"""Tests for `instances.refresh_instance_config()` — regenerate an
instance's fleet-owned DDEV config (`.ddev/config.fleet.yaml`, incl. the
Claude onboarding `post-start` hook) WITHOUT a full deploy (no
clone/git-update/ddev-start). See the design note referenced from
`.claude/user/memory/feedback-no-forced-bulk-operations.md`:
[[fleet-instance-config-only-on-deploy]]."""

import pytest

from fleet.core import instances
from fleet.core.errors import DeployError, FleetError
from fleet.core.registry import Registry
from fleet.core.runner import RunResult
from fleet.core.secrets import write_secret
from fleet.core.typesense import ensure_project_keys

PLAIN_REGISTRY = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    default_template: default
    templates:
      default:
        post_deploy:
          - echo hi
"""

TYPESENSE_REGISTRY = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    default_template: default
    typesense: true
    additional_hostnames:
      - albania
    templates:
      default:
        post_deploy:
          - echo hi
"""


def _make_paths_and_registry(fleet_home, registry_text=PLAIN_REGISTRY):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(registry_text, encoding="utf-8")
    registry = Registry.load(paths.registry)
    return paths, registry


def _write_instance_dir(
    fleet_home, instance_id, *, project="demo", branch="main", with_instance_yaml=True
):
    instance_dir = fleet_home / "instances" / instance_id
    instance_dir.mkdir(parents=True)
    if with_instance_yaml:
        label = instance_id.split("--", 1)[1]
        fleet_dir = instance_dir / ".fleet"
        fleet_dir.mkdir(parents=True)
        (fleet_dir / "instance.yml").write_text(
            f"project: {project}\ninstance: {label}\nbranch: {branch}\n"
            "created-at: '2026-07-01T00:00:00Z'\nlast-deployed-at: '2026-07-01T00:00:00Z'\n",
            encoding="utf-8",
        )
    return instance_dir


def test_refresh_instance_config_unknown_instance_raises(fleet_home):
    paths, registry = _make_paths_and_registry(fleet_home)
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")

    with pytest.raises(FleetError):
        instances.refresh_instance_config(paths, registry, "demo--nonexistent")


def test_refresh_instance_config_missing_token_raises(fleet_home):
    paths, registry = _make_paths_and_registry(fleet_home)
    _write_instance_dir(fleet_home, "demo--develop")

    with pytest.raises(DeployError):
        instances.refresh_instance_config(paths, registry, "demo--develop")


def test_refresh_instance_config_rewrites_config_with_hook_token_and_typesense(fleet_home):
    """Nothing dropped: the hook, the fresh token, git-bot env vars, and
    Typesense env vars must all be present in the rewritten config."""
    paths, registry = _make_paths_and_registry(fleet_home, TYPESENSE_REGISTRY)
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-refreshed")
    instance_dir = _write_instance_dir(fleet_home, "demo--develop")

    # Pre-seed typesense keys the way a prior deploy would have.
    admin_key, search_key = ensure_project_keys(paths.project_secrets / "demo.env")

    instances.refresh_instance_config(paths, registry, "demo--develop")

    config_path = instance_dir / ".ddev" / "config.fleet.yaml"
    assert config_path.exists()
    content = config_path.read_text(encoding="utf-8")

    assert "hooks:" in content
    assert "post-start:" in content
    assert "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-refreshed" in content
    assert "GIT_AUTHOR_NAME=" in content
    assert f"TYPESENSE_API_KEY={admin_key}" in content
    assert f"FLEET_TYPESENSE_SEARCH_KEY={search_key}" in content
    assert "FLEET_TYPESENSE_HOST=demo--develop.fleet.example.test" in content
    assert "FLEET_TYPESENSE_PORT=9108" in content
    assert "albania.demo--develop.fleet.example.test" in content

    web_build_path = instance_dir / ".ddev" / "web-build" / "Dockerfile.fleet-claude"
    assert web_build_path.exists()
    assert "npm install -g @anthropic-ai/claude-code" in web_build_path.read_text(encoding="utf-8")


def test_refresh_instance_config_default_does_not_restart(fleet_home):
    paths, registry = _make_paths_and_registry(fleet_home)
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    _write_instance_dir(fleet_home, "demo--develop")

    calls = []

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        calls.append(list(cmd))
        return RunResult(returncode=0, lines=[])

    instances.refresh_instance_config(paths, registry, "demo--develop", runner=fake_runner)

    assert [c for c in calls if c == ["ddev", "restart"]] == []


def test_refresh_instance_config_restart_calls_ddev_restart(fleet_home):
    paths, registry = _make_paths_and_registry(fleet_home)
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    instance_dir = _write_instance_dir(fleet_home, "demo--develop")

    calls = []

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        calls.append({"cmd": list(cmd), "cwd": cwd})
        return RunResult(returncode=0, lines=[])

    instances.refresh_instance_config(
        paths, registry, "demo--develop", restart=True, runner=fake_runner
    )

    restart_calls = [c for c in calls if c["cmd"] == ["ddev", "restart"]]
    assert len(restart_calls) == 1
    assert restart_calls[0]["cwd"] == instance_dir


def test_refresh_instance_config_restart_failure_raises(fleet_home):
    paths, registry = _make_paths_and_registry(fleet_home)
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    _write_instance_dir(fleet_home, "demo--develop")

    def failing_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        return RunResult(returncode=1, lines=["boom"])

    with pytest.raises(FleetError):
        instances.refresh_instance_config(
            paths, registry, "demo--develop", restart=True, runner=failing_runner
        )


def test_refresh_instance_config_resolves_project_via_dash_split_fallback(fleet_home):
    """An instance dir with no `.fleet/instance.yml` (e.g. a very old deploy)
    must still resolve its project from the `<project>--<label>` id, the
    same fallback `list_instances()` uses."""
    paths, registry = _make_paths_and_registry(fleet_home)
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    instance_dir = _write_instance_dir(fleet_home, "demo--develop", with_instance_yaml=False)

    instances.refresh_instance_config(paths, registry, "demo--develop")

    config_path = instance_dir / ".ddev" / "config.fleet.yaml"
    assert config_path.exists()
    assert "sk-ant-oat01-test" in config_path.read_text(encoding="utf-8")


def test_refresh_instance_config_locks_the_instance(fleet_home):
    """Concurrent refresh of the same instance must be rejected, same as
    deploy/start/stop/destroy — via `instance_lock`."""
    from fleet.core.errors import LockHeldError
    from fleet.core.locks import instance_lock

    paths, registry = _make_paths_and_registry(fleet_home)
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    _write_instance_dir(fleet_home, "demo--develop")

    with instance_lock(paths.locks, "demo--develop"):
        with pytest.raises(LockHeldError):
            instances.refresh_instance_config(paths, registry, "demo--develop")
