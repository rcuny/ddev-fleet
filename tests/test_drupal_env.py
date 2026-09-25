"""`drupal_env` (template-level) overriding DRUPAL_ENV in an instance's own .env."""

import pytest

from fleet.core.errors import RegistryError
from fleet.core.fleetconfig import set_env_file_var
from fleet.core.registry import Registry

_REGISTRY = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default:
        drupal_env: dev
        post_deploy:
          - ddev init --no-interactive
      staging:
        drupal_env: staging
        post_deploy:
          - ddev init --db=staging.sql --no-interactive
      plain:
        post_deploy:
          - ddev init
"""


def _registry(fleet_home, text=_REGISTRY):
    path = fleet_home / "fleet.yml"
    path.write_text(text, encoding="utf-8")
    return Registry.load(path)


def test_resolve_carries_template_drupal_env(fleet_home):
    registry = _registry(fleet_home)

    assert registry.resolve("demo", "default", "main").drupal_env == "dev"
    assert registry.resolve("demo", "staging", "main").drupal_env == "staging"


def test_template_without_drupal_env_resolves_to_none(fleet_home):
    """None means 'leave the project's .env alone' — not 'write dev'."""
    registry = _registry(fleet_home)

    assert registry.resolve("demo", "plain", "main").drupal_env is None


@pytest.mark.parametrize("bad", ["", "two words", "42", "true"])
def test_registry_rejects_non_word_drupal_env(fleet_home, bad):
    text = _REGISTRY.replace("drupal_env: dev", f"drupal_env: {bad}" if bad else "drupal_env: ''")

    with pytest.raises(RegistryError) as exc:
        _registry(fleet_home, text)

    assert "drupal_env" in str(exc.value)


# --- set_env_file_var: in-place upsert that preserves the rest of the file ---


def test_set_env_file_var_replaces_in_place_and_preserves_everything_else(tmp_path):
    env_path = tmp_path / ".env"
    env_path.write_text(
        "# Project env\nDRUPAL_ENV=dev\n\nJIRA_TOKEN=secret-value\n# trailing comment\n",
        encoding="utf-8",
    )

    set_env_file_var(env_path, "DRUPAL_ENV", "staging")

    assert env_path.read_text(encoding="utf-8") == (
        "# Project env\nDRUPAL_ENV=staging\n\nJIRA_TOKEN=secret-value\n# trailing comment\n"
    )


def test_set_env_file_var_appends_when_key_absent(tmp_path):
    env_path = tmp_path / ".env"
    env_path.write_text("JIRA_TOKEN=secret-value\n", encoding="utf-8")

    set_env_file_var(env_path, "DRUPAL_ENV", "staging")

    assert env_path.read_text(encoding="utf-8") == "JIRA_TOKEN=secret-value\nDRUPAL_ENV=staging\n"


def test_set_env_file_var_creates_missing_file(tmp_path):
    env_path = tmp_path / ".env"

    set_env_file_var(env_path, "DRUPAL_ENV", "staging")

    assert env_path.read_text(encoding="utf-8") == "DRUPAL_ENV=staging\n"


def test_set_env_file_var_replaces_only_the_first_assignment(tmp_path):
    """A duplicate assignment is a malformed file; we don't silently rewrite
    both, so the duplicate stays visible."""
    env_path = tmp_path / ".env"
    env_path.write_text("DRUPAL_ENV=dev\nOTHER=1\nDRUPAL_ENV=prod\n", encoding="utf-8")

    set_env_file_var(env_path, "DRUPAL_ENV", "staging")

    assert env_path.read_text(encoding="utf-8") == "DRUPAL_ENV=staging\nOTHER=1\nDRUPAL_ENV=prod\n"


def test_set_env_file_var_does_not_match_a_key_with_the_same_prefix(tmp_path):
    env_path = tmp_path / ".env"
    env_path.write_text("DRUPAL_ENVIRONMENT=keep\n", encoding="utf-8")

    set_env_file_var(env_path, "DRUPAL_ENV", "staging")

    assert env_path.read_text(encoding="utf-8") == "DRUPAL_ENVIRONMENT=keep\nDRUPAL_ENV=staging\n"
