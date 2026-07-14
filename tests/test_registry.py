from pathlib import Path

import pytest

from fleet.core.errors import RegistryError
from fleet.core.registry import Registry


def _write(path: Path, text: str) -> Path:
    path.write_text(text, encoding="utf-8")
    return path


def test_resolve_uses_project_default_template_post_deploy(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "default", "main")

    assert resolved.project == "demo"
    assert resolved.template == "default"
    assert resolved.branch == "main"
    assert resolved.label == "main"
    assert resolved.post_deploy == ["echo project-default"]
    assert resolved.instance_id == "demo--main"


def test_resolve_uses_named_template_post_deploy(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "custom", "feature-x")

    assert resolved.branch == "feature-x"
    assert resolved.label == "feature-x"
    assert resolved.post_deploy == ["echo instance-override"]
    assert resolved.instance_id == "demo--feature-x"


def test_resolve_label_defaults_to_slugified_branch(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "default", "feature/Foo Bar")

    assert resolved.label == "feature-foo-bar"
    assert resolved.instance_id == "demo--feature-foo-bar"


def test_resolve_explicit_label_overrides_slugified_branch(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("demo", "default", "feature/foo", label="mylabel")

    assert resolved.branch == "feature/foo"
    assert resolved.label == "mylabel"
    assert resolved.instance_id == "demo--mylabel"


def test_registry_properties(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    assert registry.domain == "fleet.example.test"
    assert registry.project_keys() == ["demo"]
    assert registry.has_project("demo") is True
    assert registry.has_project("nope") is False
    assert set(registry.template_keys("demo")) == {"default", "custom"}
    assert registry.git_url("demo") == "git@example.test:org/demo.git"
    assert registry.project_defaults("demo") == ("default", "main")
    assert registry.additional_hostnames("demo") == []


def test_project_defaults_are_none_when_not_configured(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  bare:
    git: git@example.test:org/bare.git
    templates:
      default: {}
"""
    path = _write(fleet_home / "fleet.yml", registry_text)
    registry = Registry.load(path)

    assert registry.project_defaults("bare") == (None, None)


def test_additional_hostnames_returns_configured_list(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    additional_hostnames:
      - www
      - api
    templates:
      default: {}
"""
    path = _write(fleet_home / "fleet.yml", registry_text)
    registry = Registry.load(path)

    assert registry.additional_hostnames("demo") == ["www", "api"]


def test_load_missing_git_key_names_the_key(fleet_home, sample_registry_text):
    broken = sample_registry_text.replace("    git: git@example.test:org/demo.git\n", "")
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


def test_load_template_with_branch_key_raises(fleet_home):
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default:
        branch: main
"""
    path = _write(fleet_home / "fleet.yml", registry_text)

    with pytest.raises(RegistryError) as exc_info:
        Registry.load(path)
    assert "projects.demo.templates.default.branch" in str(exc_info.value)


def test_resolve_unknown_project_raises(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError):
        registry.resolve("nonexistent", "default", "main")


def test_resolve_unknown_template_raises(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError):
        registry.resolve("demo", "nonexistent", "main")


def test_resolve_missing_branch_raises(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)
    registry = Registry.load(path)

    with pytest.raises(RegistryError):
        registry.resolve("demo", "default", "")


def test_resolve_with_no_post_deploy_returns_empty_list(fleet_home):
    """When the resolved template defines no post_deploy, resolve returns an
    empty list."""
    registry_text = """\
fleet:
  domain: fleet.example.test

projects:
  testproj:
    git: git@example.test:org/testproj.git
    templates:
      no-deploy: {}
"""
    path = _write(fleet_home / "fleet.yml", registry_text)
    registry = Registry.load(path)

    resolved = registry.resolve("testproj", "no-deploy", "main")

    assert resolved.post_deploy == []


def test_load_missing_file_raises_actionable_registry_error(fleet_home):
    """A nonexistent fleet.yml must raise RegistryError with guidance,
    not a raw FileNotFoundError traceback in the CLI."""
    path = fleet_home / "does-not-exist" / "fleet.yml"

    with pytest.raises(RegistryError) as exc_info:
        Registry.load(path)
    assert "fleet init" in str(exc_info.value)
