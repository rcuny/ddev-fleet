"""Tests for `instances.redeploy()` (design decision 2,
2026-07-27-fleet-redeploy-and-no-overwrite-design.md).

Kept in its own file (not tests/test_instances_deploy.py, already 40KB+)
since redeploy is a distinct operation built on top of `deploy(replace=True)`
— see the module docstring on `redeploy()` itself for how the two relate.
"""

import pytest

from fleet.core import caddyauth, instances
from fleet.core.errors import FleetError
from fleet.core.registry import Registry
from fleet.core.secrets import write_secret
from tests.test_instances_deploy import HybridRunner


def _registry_text(git_url):
    """Two templates (unlike test_instances_deploy.py's single-template
    fixture) so redeploy's --template override and "picks up a registry
    edit" behaviour have something real to switch between."""
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
      custom:
        post_deploy:
          - echo instance-override
"""


def _make_paths_and_registry(fleet_home, git_url):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_registry_text(git_url), encoding="utf-8")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    registry = Registry.load(paths.registry)
    return paths, registry


def _write_raw_instance_yaml(instance_dir, content):
    """Fabricate a `.fleet/instance.yml` by hand (not via a real deploy) so
    tests can exercise recorded-parameter edge cases (missing keys, an
    unknown project, a corrupt file) without running the whole deploy
    pipeline first."""
    fleet_dir = instance_dir / ".fleet"
    fleet_dir.mkdir(parents=True, exist_ok=True)
    (fleet_dir / "instance.yml").write_text(content, encoding="utf-8")


# --- happy path / end-to-end (real deploy(), no monkeypatching) ---


def test_redeploy_happy_path_lands_on_same_id_and_url(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    first_url = instances.deploy(
        paths, registry, "demo", "default", branch="main", label="dev-13", runner=HybridRunner()
    )
    (paths.instances / "demo--dev-13" / "stray-file.txt").write_text("leftover\n", encoding="utf-8")

    second_url = instances.redeploy(paths, registry, "demo--dev-13", runner=HybridRunner())

    assert second_url == first_url == "https://demo--dev-13.fleet.example.test"
    assert (paths.instances / "demo--dev-13").exists()
    assert not (paths.instances / "demo--dev-13-1").exists()
    assert not (paths.instances / "demo--dev-13" / "stray-file.txt").exists()


def test_redeploy_auth_disabled_is_preserved_end_to_end(fleet_home, git_repo):
    """A recorded auth-enabled: false must survive a redeploy that passes no
    auth arguments at all — it must NOT silently flip back to the default
    (auth on)."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths,
        registry,
        "demo",
        "default",
        branch="main",
        label="dev-13",
        auth_enabled=False,
        runner=HybridRunner(),
    )

    instances.redeploy(paths, registry, "demo--dev-13", runner=HybridRunner())

    snippet_path = caddyauth.DEFAULT_INSTANCE_SNIPPET_DIR / "demo--dev-13.conf"
    assert not snippet_path.exists()


def test_redeploy_picks_up_template_edit_since_original_deploy(fleet_home, git_repo):
    """ "Same parameters" means the same project/template/branch/label
    identity, not a frozen copy of the recipe — the registry is re-read, so
    editing the template's post_deploy between deploy and redeploy changes
    what runs."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instances.deploy(
        paths, registry, "demo", "default", branch="main", label="dev-13", runner=HybridRunner()
    )

    paths.registry.write_text(
        _registry_text(str(git_repo["origin"])).replace(
            "echo project-default", "echo edited-post-deploy"
        ),
        encoding="utf-8",
    )
    edited_registry = Registry.load(paths.registry)

    runner = HybridRunner()
    instances.redeploy(paths, edited_registry, "demo--dev-13", runner=runner)

    bash_calls = [c for c in runner.calls if c["cmd"][0] == "bash"]
    assert bash_calls[0]["cmd"] == ["bash", "-c", "echo edited-post-deploy"]


# --- redeploy() calls through to deploy(replace=True) (monkeypatched) ---


def test_redeploy_calls_deploy_with_replace_true_and_recorded_params(
    fleet_home, git_repo, monkeypatch
):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instance_dir = paths.instances / "demo--dev-13"
    _write_raw_instance_yaml(
        instance_dir,
        """\
project: demo
instance: dev-13
template: custom
branch: feature/x
auth-enabled: true
auth-password: origpass
""",
    )

    captured = {}

    def fake_deploy(paths_arg, registry_arg, project, template, **kwargs):
        captured["project"] = project
        captured["template"] = template
        captured.update(kwargs)
        return "https://demo--dev-13.fleet.example.test"

    monkeypatch.setattr(instances, "deploy", fake_deploy)

    url = instances.redeploy(paths, registry, "demo--dev-13", runner=HybridRunner())

    assert url == "https://demo--dev-13.fleet.example.test"
    assert captured["project"] == "demo"
    assert captured["template"] == "custom"
    assert captured["branch"] == "feature/x"
    assert captured["label"] == "dev-13"
    assert captured["replace"] is True
    assert captured["force"] is False
    assert captured["auth_enabled"] is True
    assert captured["auth_password"] == "origpass"


def test_redeploy_template_argument_overrides_recorded(fleet_home, git_repo, monkeypatch):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instance_dir = paths.instances / "demo--dev-13"
    _write_raw_instance_yaml(
        instance_dir,
        """\
project: demo
instance: dev-13
template: default
branch: main
""",
    )

    captured = {}
    monkeypatch.setattr(
        instances,
        "deploy",
        lambda paths_arg, registry_arg, project, template, **kwargs: captured.update(
            template=template
        )
        or "https://demo--dev-13.fleet.example.test",
    )

    instances.redeploy(paths, registry, "demo--dev-13", template="custom", runner=HybridRunner())

    assert captured["template"] == "custom"


def test_redeploy_auth_password_argument_overrides_recorded(fleet_home, git_repo, monkeypatch):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instance_dir = paths.instances / "demo--dev-13"
    _write_raw_instance_yaml(
        instance_dir,
        """\
project: demo
instance: dev-13
template: default
branch: main
auth-enabled: false
auth-password: origpass
""",
    )

    captured = {}
    monkeypatch.setattr(
        instances,
        "deploy",
        lambda paths_arg, registry_arg, project, template, **kwargs: captured.update(kwargs)
        or "https://demo--dev-13.fleet.example.test",
    )

    instances.redeploy(
        paths, registry, "demo--dev-13", auth_password="newpass", runner=HybridRunner()
    )

    assert captured["auth_password"] == "newpass"
    # The password argument overrides the password only — the recorded
    # auth-enabled: false must NOT be flipped back to true just because a
    # password was supplied.
    assert captured["auth_enabled"] is False


# --- failure modes ---


def test_redeploy_missing_instance_directory_raises(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    with pytest.raises(FleetError, match="demo--nonexistent"):
        instances.redeploy(paths, registry, "demo--nonexistent", runner=HybridRunner())


def test_redeploy_missing_instance_yaml_raises(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    (paths.instances / "demo--dev-13").mkdir(parents=True)

    with pytest.raises(FleetError, match="demo--dev-13"):
        instances.redeploy(paths, registry, "demo--dev-13", runner=HybridRunner())


def test_redeploy_corrupt_instance_yaml_raises_fleet_error_not_yaml_error(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instance_dir = paths.instances / "demo--dev-13"
    _write_raw_instance_yaml(instance_dir, "foo: [1, 2\n  bar: unterminated")

    with pytest.raises(FleetError):
        instances.redeploy(paths, registry, "demo--dev-13", runner=HybridRunner())


def test_redeploy_instance_yaml_not_a_mapping_raises(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instance_dir = paths.instances / "demo--dev-13"
    _write_raw_instance_yaml(instance_dir, "just a plain string, not a mapping\n")

    with pytest.raises(FleetError):
        instances.redeploy(paths, registry, "demo--dev-13", runner=HybridRunner())


def test_redeploy_no_recorded_template_refuses_with_actionable_message(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instance_dir = paths.instances / "demo--dev-13"
    _write_raw_instance_yaml(
        instance_dir,
        """\
project: demo
instance: dev-13
branch: main
""",
    )

    with pytest.raises(FleetError) as excinfo:
        instances.redeploy(paths, registry, "demo--dev-13", runner=HybridRunner())

    message = str(excinfo.value)
    assert "demo--dev-13" in message
    assert "--template" in message


def test_redeploy_missing_branch_refuses(fleet_home, git_repo):
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instance_dir = paths.instances / "demo--dev-13"
    _write_raw_instance_yaml(
        instance_dir,
        """\
project: demo
instance: dev-13
template: default
""",
    )

    with pytest.raises(FleetError, match="demo--dev-13"):
        instances.redeploy(paths, registry, "demo--dev-13", runner=HybridRunner())


def test_redeploy_recorded_project_no_longer_in_registry_raises_clear_error(fleet_home, git_repo):
    """A project deleted from fleet.yml must produce a clear FleetError, not
    a RegistryError raised three layers down inside registry.resolve()."""
    paths, registry = _make_paths_and_registry(fleet_home, str(git_repo["origin"]))
    instance_dir = paths.instances / "ghost--dev-13"
    _write_raw_instance_yaml(
        instance_dir,
        """\
project: ghost
instance: dev-13
template: default
branch: main
""",
    )

    with pytest.raises(FleetError) as excinfo:
        instances.redeploy(paths, registry, "ghost--dev-13", runner=HybridRunner())

    assert "ghost" in str(excinfo.value)
