"""Tests for `instances._preflight_rebuild()` — the pre-destroy safety net
added after incident 2026-09-22 (`fleet redeploy --all` destroyed 5
instances, then failed removing each one's basic-auth snippet because
`caddy validate` was failing for a reason unrelated to any of them; the
rebuild never ran, so the instances were simply gone).

Kept in its own file (like test_instances_redeploy.py) since it's a
distinct, narrowly-scoped concern: every check here must run BEFORE
anything is destroyed, and every test's core assertion is "the existing
instance was left completely untouched".
"""

import pytest

from fleet.core import bulk as bulk_mod
from fleet.core import caddyauth, instances
from fleet.core.errors import FleetError
from fleet.core.registry import Registry
from fleet.core.runner import RunResult, run_streamed
from fleet.core.secrets import write_secret
from tests.test_instances_deploy import HybridRunner

_LONG_HOST = "a" * 60  # composes an alias label that exceeds the 63-char DNS limit


def _registry_text(git_url, *, demo2_additional_hostnames=False):
    demo2_extra = ""
    if demo2_additional_hostnames:
        demo2_extra = f"    additional_hostnames: [{_LONG_HOST}]\n"
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
          - echo project-default
  demo2:
{demo2_extra}    git: {git_url}
    default_template: default
    templates:
      default:
        post_deploy:
          - echo project-default
"""


def _make_paths_and_registry(fleet_home, git_url):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_registry_text(git_url), encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    return paths, registry


class FailingValidateRunner(HybridRunner):
    """Like HybridRunner, but `caddy validate` always fails — simulating a
    fleet-wide Caddy problem unrelated to the instance being rebuilt."""

    def __call__(self, cmd, *, cwd=None, env=None, log_path=None, echo=True):
        if cmd[:2] == ["caddy", "validate"]:
            self.calls.append({"cmd": list(cmd), "cwd": cwd, "env": env, "log_path": log_path})
            return RunResult(returncode=1, lines=["adapting: unrelated Caddyfile error"])
        return super().__call__(cmd, cwd=cwd, env=env, log_path=log_path, echo=echo)


def _redeploy_op(paths, registry, instance_id, *, runner=run_streamed):
    return instances.redeploy(paths, registry, instance_id, runner=runner)


# --- redeploy(): registry no longer resolves the rebuild target ---


def test_preflight_rebuild_registry_no_longer_resolves_raises_deploy_error(tmp_path):
    """Unit-level: `_preflight_rebuild`'s check #1 in isolation. Through the
    public `deploy()`/`redeploy()` entry points this exact condition is
    already caught even earlier (by `deploy()`'s own unconditional
    `resolve_target()` call at the top, before the replace/destroy decision
    is even made) — so this scenario can never actually reach
    `_preflight_rebuild` via the public API, which is itself a good thing
    (nothing is destroyed either way). This test instead pins down the
    contract of the check itself, directly."""
    registry_path = tmp_path / "fleet.yml"
    registry_path.write_text(
        "fleet:\n  domain: fleet.example.test\nprojects: {}\n", encoding="utf-8"
    )
    registry = Registry.load(registry_path)

    with pytest.raises(FleetError, match="NOTHING WAS DESTROYED"):
        instances._preflight_rebuild(
            registry,
            "demo--dev-13",
            project="demo",
            template="default",
            branch="main",
            label="dev-13",
            snippet_dir=tmp_path / "caddy-instances",
            caddyfile_path=tmp_path / "Caddyfile",
            runner=HybridRunner(),
        )


# --- redeploy(): alias FQDN composition fails ---


def test_redeploy_preflight_alias_too_long_leaves_instance_untouched(
    fleet_home, git_repo, monkeypatch
):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo2", "default", branch="main", label="dev-13", runner=HybridRunner()
    )
    instance_dir = paths.instances / "demo2--dev-13"
    assert instance_dir.exists()

    destroyed = []
    monkeypatch.setattr(instances, "_destroy_locked", lambda *a, **k: destroyed.append(True))

    # demo2 now has an additional_hostnames entry that composes an
    # alias label over the 63-char DNS limit for this instance id.
    edited_text = _registry_text(str(git_repo["origin"]), demo2_additional_hostnames=True)
    paths.registry.write_text(edited_text, encoding="utf-8")
    edited_registry = Registry.load(paths.registry)

    with pytest.raises(FleetError, match="NOTHING WAS DESTROYED"):
        instances.redeploy(paths, edited_registry, "demo2--dev-13", runner=HybridRunner())

    assert destroyed == []
    assert instance_dir.exists()


# --- redeploy()/destroy(): the CURRENT Caddy config no longer validates ---


def test_redeploy_preflight_caddy_validate_failure_leaves_instance_untouched(
    fleet_home, git_repo, monkeypatch
):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="dev-13", runner=HybridRunner()
    )
    instance_dir = paths.instances / "demo--dev-13"

    # _isolate_caddy_paths (conftest, autouse) redirects this to a tmp_path
    # file — it must EXIST for the preflight's validate check to run at all
    # (see _preflight_rebuild's "skip if caddyfile_path doesn't exist" step).
    caddyauth.DEFAULT_CADDYFILE_PATH.write_text("bogus\n", encoding="utf-8")

    destroyed = []
    monkeypatch.setattr(instances, "_destroy_locked", lambda *a, **k: destroyed.append(True))

    with pytest.raises(FleetError, match="NOTHING WAS DESTROYED"):
        instances.redeploy(paths, registry, "demo--dev-13", runner=FailingValidateRunner())

    assert destroyed == []
    assert instance_dir.exists()


def test_destroy_preflight_caddy_validate_failure_leaves_instance_untouched(
    fleet_home, git_repo, monkeypatch
):
    """A plain `destroy()` gets the lighter Caddy-only preflight too — this
    is the exact incident shape (destroy tears the instance down, THEN
    fails removing its auth snippet because caddy validate was already
    broken) but caught before the directory is ever removed."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )
    instance_dir = paths.instances / "demo--develop"

    caddyauth.DEFAULT_CADDYFILE_PATH.write_text("bogus\n", encoding="utf-8")

    destroyed = []
    monkeypatch.setattr(instances, "_destroy_locked", lambda *a, **k: destroyed.append(True))

    with pytest.raises(FleetError, match="NOTHING WAS DESTROYED"):
        instances.destroy(paths, registry, "demo--develop", runner=FailingValidateRunner())

    assert destroyed == []
    assert instance_dir.exists()


# --- success path is unchanged when the preflight passes ---


def test_redeploy_preflight_passes_with_valid_caddyfile_still_redeploys(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="dev-13", runner=HybridRunner()
    )
    # Present on disk, but HybridRunner returns success for any unscripted
    # `caddy validate` call — exercises the "file exists, actually
    # validated" branch, not just the skip-when-missing one.
    caddyauth.DEFAULT_CADDYFILE_PATH.write_text("fine\n", encoding="utf-8")

    url = instances.redeploy(paths, registry, "demo--dev-13", runner=HybridRunner())

    assert url == "https://demo--dev-13.fleet.example.test"
    assert (paths.instances / "demo--dev-13").exists()


def test_deploy_replace_preflight_passes_still_destroys_and_reclones(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="develop", runner=HybridRunner()
    )
    instance_dir = paths.instances / "demo--develop"
    (instance_dir / "stray-file.txt").write_text("leftover\n", encoding="utf-8")

    instances.deploy(
        paths,
        registry,
        "demo",
        "default",
        branch="main",
        label="develop",
        replace=True,
        runner=HybridRunner(),
    )

    assert not (instance_dir / "stray-file.txt").exists()
    assert (instance_dir / "README.md").exists()


# --- bulk redeploy: a failing preflight fails only that instance ---


def test_bulk_redeploy_preflight_failure_marks_one_instance_failed_and_continues(
    fleet_home, git_repo
):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="dev-13", runner=HybridRunner()
    )
    instances.deploy(
        paths, registry, "demo2", "default", branch="main", label="dev-13", runner=HybridRunner()
    )

    # demo2 now has a too-long alias hostname — its redeploy must fail
    # preflight; demo's redeploy must be unaffected.
    edited_text = _registry_text(str(git_repo["origin"]), demo2_additional_hostnames=True)
    paths.registry.write_text(edited_text, encoding="utf-8")
    edited_registry = Registry.load(paths.registry)

    outcome = bulk_mod.run_sequential(
        paths,
        edited_registry,
        ["demo--dev-13", "demo2--dev-13"],
        _redeploy_op,
        kind="redeploy",
        runner=HybridRunner(),
    )

    results = {r.instance_id: r for r in outcome.results}
    assert results["demo--dev-13"].ok is True
    assert results["demo2--dev-13"].ok is False
    assert "NOTHING WAS DESTROYED" in results["demo2--dev-13"].error

    assert (paths.instances / "demo--dev-13").exists()
    assert (paths.instances / "demo2--dev-13").exists()
