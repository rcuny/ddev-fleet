from pathlib import Path

import pytest

from fleet.core.errors import RegistryError
from fleet.core.registry import Registry


def _write(path: Path, text: str) -> Path:
    path.write_text(text, encoding="utf-8")
    return path


def test_load_resolves_project_default_post_deploy(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "develop")

    assert resolved.project == "demo"
    assert resolved.instance == "develop"
    assert resolved.branch == "main"
    assert resolved.post_deploy == ["echo project-default"]
    assert resolved.instance_id == "demo--develop"


def test_load_resolves_instance_post_deploy_replaces_default(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "custom")

    assert resolved.branch == "feature-x"
    assert resolved.post_deploy == ["echo instance-override"]


def test_registry_properties(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    assert registry.domain == "fleet.example.test"
    assert registry.assets_path == fleet_home / "assets"
    assert registry.instances_path == fleet_home / "instances"
    assert registry.project_keys() == ["demo"]
    assert registry.has_project("demo") is True
    assert registry.has_project("nope") is False
    assert registry.has_instance("demo", "develop") is True
    assert registry.has_instance("demo", "nonexistent") is False
    assert registry.git_url("demo") == "git@example.test:org/demo.git"


def test_load_missing_git_key_names_the_key(fleet_home, sample_registry_text):
    broken = sample_registry_text.replace(
        "    git: git@example.test:org/demo.git\n", ""
    )
    path = _write(fleet_home / "fleet.yml", broken)

    with pytest.raises(RegistryError) as exc_info:
        Registry.load(path)
    assert "projects.demo.git" in str(exc_info.value)


def test_load_invalid_project_key_names_the_key(fleet_home, sample_registry_text):
    broken = sample_registry_text.replace("  demo:\n", "  Bad_Project:\n").replace(
        "    git: git@example.test:org/demo.git\n",
        "    git: git@example.test:org/demo.git\n",
    )
    path = _write(fleet_home / "fleet.yml", broken)

    with pytest.raises(RegistryError) as exc_info:
        Registry.load(path)
    assert "Bad_Project" in str(exc_info.value)


def test_resolve_unknown_project_raises(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError):
        registry.resolve("nonexistent", "develop")


def test_register_instance_and_save_round_trip_preserves_comment(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    registry.register_instance("demo", "newinst", "release-1")
    registry.save()

    assert registry.has_instance("demo", "newinst") is True
    saved_text = path.read_text(encoding="utf-8")
    assert "# Wildcard DNS root" in saved_text

    reloaded = Registry.load(path)
    resolved = reloaded.resolve("demo", "newinst")
    assert resolved.branch == "release-1"
    assert resolved.post_deploy == ["echo project-default"]


def test_add_project_and_save_round_trip(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    registry.add_project("newproj", "git@example.test:org/newproj.git", ["echo hi"])
    registry.save()

    reloaded = Registry.load(path)
    assert reloaded.has_project("newproj") is True
    assert reloaded.git_url("newproj") == "git@example.test:org/newproj.git"


def test_add_project_duplicate_raises(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError):
        registry.add_project("demo", "git@example.test:org/demo.git")


def test_resolve_with_no_post_deploy_returns_empty_list(fleet_home):
    """When neither project nor instance defines post_deploy, resolve returns empty list."""
    registry_text = """\
fleet:
  domain: fleet.example.test
  assets_path: {assets_path}
  instances_path: {instances_path}

projects:
  testproj:
    git: git@example.test:org/testproj.git
    instances:
      no-deploy:
        branch: main
"""
    registry_text = registry_text.format(
        assets_path=str(fleet_home / "assets"),
        instances_path=str(fleet_home / "instances"),
    )
    path = _write(fleet_home / "fleet.yml", registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("testproj", "no-deploy")

    assert resolved.post_deploy == []


def test_register_instance_invalid_name_raises_registry_error(fleet_home, sample_registry_text):
    """register_instance with invalid instance name raises RegistryError, not ValidationError."""
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError) as exc_info:
        registry.register_instance("demo", "Bad Name", "main")
    assert "invalid instance name" in str(exc_info.value)


def test_add_project_invalid_key_raises_registry_error(fleet_home, sample_registry_text):
    """add_project with invalid key raises RegistryError, not ValidationError."""
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError) as exc_info:
        registry.add_project("Bad_Key", "git@example.test:org/bad.git")
    assert "invalid project key" in str(exc_info.value)
