"""Tests for the per-host `domain` override (`host.yml`) — 2026-09-22
"share one fleet.yml across several servers, each keeping its own
fleet.domain" design.

`Registry.load()`'s `host_config_path` parameter and `_load_host_domain()`
are the unit under test here; `fleet.core.instances.load_registry()` (the
one constructor every CLI/daemon call site uses) is covered by
`test_instances_paths.py`/`test_daemon.py`'s own host.yml test.
"""

from pathlib import Path

import pytest

from fleet.core.errors import RegistryError
from fleet.core.registry import Registry


def _write(path: Path, text: str) -> Path:
    path.write_text(text, encoding="utf-8")
    return path


_REGISTRY_WITH_DOMAIN = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default: {}
"""

_REGISTRY_WITHOUT_DOMAIN = """\
fleet: {}

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default: {}
"""


# --- backward compatibility: no host_config_path given at all ---


def test_load_without_host_config_path_behaves_as_before(fleet_home, sample_registry_text):
    path = _write(fleet_home / "fleet.yml", sample_registry_text)

    registry = Registry.load(path)

    assert registry.domain == "fleet.example.test"


def test_load_without_host_config_path_still_requires_fleet_domain(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITHOUT_DOMAIN)

    with pytest.raises(RegistryError, match="fleet.domain"):
        Registry.load(path)


# --- precedence ---


def test_fleet_yml_domain_used_when_host_yml_absent(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITH_DOMAIN)
    host_config = fleet_home / "host.yml"  # never created

    registry = Registry.load(path, host_config_path=host_config)

    assert registry.domain == "fleet.example.test"


def test_host_yml_domain_used_when_fleet_yml_has_none(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITHOUT_DOMAIN)
    host_config = _write(fleet_home / "host.yml", "domain: fleet.host-a.test\n")

    registry = Registry.load(path, host_config_path=host_config)

    assert registry.domain == "fleet.host-a.test"


def test_host_yml_domain_wins_when_both_present_and_differ(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITH_DOMAIN)
    host_config = _write(fleet_home / "host.yml", "domain: fleet.host-a.test\n")

    registry = Registry.load(path, host_config_path=host_config)

    assert registry.domain == "fleet.host-a.test"


def test_host_yml_without_domain_key_falls_back_to_fleet_yml(fleet_home):
    """host.yml may exist but carry only OTHER host-level keys — the schema
    is an open mapping, `domain` is just one key in it."""
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITH_DOMAIN)
    host_config = _write(fleet_home / "host.yml", "some_future_key: 1\n")

    registry = Registry.load(path, host_config_path=host_config)

    assert registry.domain == "fleet.example.test"


def test_neither_fleet_yml_nor_host_yml_domain_raises_naming_both_locations(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITHOUT_DOMAIN)
    host_config = fleet_home / "host.yml"  # never created

    with pytest.raises(RegistryError) as excinfo:
        Registry.load(path, host_config_path=host_config)

    message = str(excinfo.value)
    assert str(path) in message
    assert str(host_config) in message


# --- host.yml validation ---


def test_host_yml_unknown_keys_are_ignored(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITHOUT_DOMAIN)
    host_config = _write(
        fleet_home / "host.yml", "domain: fleet.host-a.test\nsome_future_key: [1, 2]\n"
    )

    registry = Registry.load(path, host_config_path=host_config)

    assert registry.domain == "fleet.host-a.test"


def test_host_yml_empty_domain_raises(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITH_DOMAIN)
    host_config = _write(fleet_home / "host.yml", "domain: ''\n")

    with pytest.raises(RegistryError, match="non-empty"):
        Registry.load(path, host_config_path=host_config)


def test_host_yml_domain_with_scheme_raises(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITH_DOMAIN)
    host_config = _write(fleet_home / "host.yml", "domain: https://fleet.host-a.test\n")

    with pytest.raises(RegistryError, match="bare hostname"):
        Registry.load(path, host_config_path=host_config)


def test_host_yml_domain_with_slash_raises(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITH_DOMAIN)
    host_config = _write(fleet_home / "host.yml", "domain: fleet.host-a.test/\n")

    with pytest.raises(RegistryError, match="bare hostname"):
        Registry.load(path, host_config_path=host_config)


def test_host_yml_domain_non_string_raises(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITH_DOMAIN)
    host_config = _write(fleet_home / "host.yml", "domain: 12345\n")

    with pytest.raises(RegistryError, match="non-empty"):
        Registry.load(path, host_config_path=host_config)


def test_host_yml_not_a_mapping_raises(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITH_DOMAIN)
    host_config = _write(fleet_home / "host.yml", "just a plain string\n")

    with pytest.raises(RegistryError, match="mapping"):
        Registry.load(path, host_config_path=host_config)


def test_host_yml_empty_file_falls_back_to_fleet_yml(fleet_home):
    path = _write(fleet_home / "fleet.yml", _REGISTRY_WITH_DOMAIN)
    host_config = _write(fleet_home / "host.yml", "")

    registry = Registry.load(path, host_config_path=host_config)

    assert registry.domain == "fleet.example.test"
