"""`fleet refresh-auth` — re-apply the fleet-wide basic-auth bypass list to
every deployed instance without redeploying (fleet.core.instances.sync_instance_auth)."""

from pathlib import Path

import pytest

from fleet.core import instances as instances_mod
from fleet.core.errors import FleetError
from fleet.core.registry import Registry
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner

_REGISTRY_YAML = """\
fleet:
  domain: fleet.example.test
  auth_bypass_cidrs:
    - 203.0.113.31/32

projects:
  demo:
    git: git@example.test:org/demo.git
"""


def _make_instance(paths, instance_id: str, *, auth_enabled=True, password="fleet") -> Path:
    fleet_dir = paths.instances / instance_id / ".fleet"
    fleet_dir.mkdir(parents=True)
    lines = ["project: demo", "branch: main", f"auth-enabled: {str(auth_enabled).lower()}"]
    if password is not None:
        lines.append(f"auth-password: {password}")
    (fleet_dir / "instance.yml").write_text("\n".join(lines) + "\n", encoding="utf-8")
    return paths.instances / instance_id


@pytest.fixture
def setup(fleet_home):
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    paths.registry.write_text(_REGISTRY_YAML, encoding="utf-8")
    registry = Registry.load(paths.registry)
    return paths, registry


def _runner(caddyfile_path, *, validate_rc=0):
    return FakeRunner(
        scripted={
            "caddy hash-password --plaintext fleet": RunResult(
                returncode=0, lines=["$2a$14$fleethash"]
            ),
            "caddy hash-password --plaintext fern": RunResult(
                returncode=0, lines=["$2a$14$fernhash"]
            ),
            f"caddy validate --config {caddyfile_path} --adapter caddyfile": RunResult(
                returncode=validate_rc, lines=["boom"] if validate_rc else []
            ),
            f"caddy reload --config {caddyfile_path}": RunResult(returncode=0, lines=[]),
        }
    )


def test_sync_rewrites_every_instance_with_current_bypass_list_and_reloads_once(setup, tmp_path):
    paths, registry = setup
    _make_instance(paths, "demo--main")
    _make_instance(paths, "demo--client", password="fern")
    snippet_dir = tmp_path / "instances"
    caddyfile_path = tmp_path / "Caddyfile"
    fake = _runner(caddyfile_path)

    result = instances_mod.sync_instance_auth(
        paths, registry, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
    )

    assert result.written == ["demo--client", "demo--main"]
    assert result.removed == []
    assert result.reloaded is True
    client = (snippet_dir / "demo--client.conf").read_text(encoding="utf-8")
    assert "fern $2a$14$fernhash" in client
    # ONE validate + ONE reload for the whole fleet, not one per instance.
    assert [c["cmd"][0:2] for c in fake.calls].count(["caddy", "reload"]) == 1
    assert [c["cmd"][0:2] for c in fake.calls].count(["caddy", "validate"]) == 1


def test_sync_includes_alias_hosts_in_matcher(tmp_path):
    """A project with `additional_hostnames` gets its alias FQDNs folded
    into the SAME `host` matcher as the bare instance FQDN — otherwise the
    alias would bypass basic auth entirely."""
    paths = instances_mod.FleetPaths.from_home(tmp_path / "fleet-home")
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    additional_hostnames:
      - es
      - news
""",
        encoding="utf-8",
    )
    registry = Registry.load(paths.registry)
    _make_instance(paths, "demo--main")
    snippet_dir = tmp_path / "instances"
    caddyfile_path = tmp_path / "Caddyfile"

    instances_mod.sync_instance_auth(
        paths,
        registry,
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=_runner(caddyfile_path),
    )

    content = (snippet_dir / "demo--main.conf").read_text(encoding="utf-8")
    assert "demo--main.fleet.example.test" in content
    assert "es-demo--main.fleet.example.test" in content
    assert "news-demo--main.fleet.example.test" in content
    # One matcher definition + one basic_auth reference to it — not a
    # separate matcher per alias.
    assert content.count("@auth-demo--main") == 2


def test_sync_falls_back_to_no_aliases_for_unknown_project(tmp_path):
    """An instance recorded against a project no longer in the registry
    must not crash `refresh-auth` — it just gets no alias hosts."""
    paths = instances_mod.FleetPaths.from_home(tmp_path / "fleet-home")
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  other:
    git: git@example.test:org/other.git
""",
        encoding="utf-8",
    )
    registry = Registry.load(paths.registry)
    fleet_dir = paths.instances / "demo--main" / ".fleet"
    fleet_dir.mkdir(parents=True)
    (fleet_dir / "instance.yml").write_text(
        "project: demo\nbranch: main\nauth-enabled: true\nauth-password: fleet\n",
        encoding="utf-8",
    )
    snippet_dir = tmp_path / "instances"
    caddyfile_path = tmp_path / "Caddyfile"

    result = instances_mod.sync_instance_auth(
        paths,
        registry,
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=_runner(caddyfile_path),
    )

    assert result.written == ["demo--main"]
    content = (snippet_dir / "demo--main.conf").read_text(encoding="utf-8")
    assert "demo--main.fleet.example.test" in content
    assert content.count(".fleet.example.test") == 1


def test_sync_removes_snippet_for_instance_with_auth_disabled(setup, tmp_path):
    paths, registry = setup
    _make_instance(paths, "demo--open", auth_enabled=False)
    snippet_dir = tmp_path / "instances"
    snippet_dir.mkdir()
    (snippet_dir / "demo--open.conf").write_text("stale\n", encoding="utf-8")
    caddyfile_path = tmp_path / "Caddyfile"

    result = instances_mod.sync_instance_auth(
        paths,
        registry,
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=_runner(caddyfile_path),
    )

    assert result.removed == ["demo--open"]
    assert result.written == []
    assert not (snippet_dir / "demo--open.conf").exists()


def test_sync_with_no_instances_does_not_touch_caddy(setup, tmp_path):
    paths, registry = setup
    caddyfile_path = tmp_path / "Caddyfile"
    fake = _runner(caddyfile_path)

    result = instances_mod.sync_instance_auth(
        paths,
        registry,
        snippet_dir=tmp_path / "instances",
        caddyfile_path=caddyfile_path,
        runner=fake,
    )

    assert (result.written, result.removed, result.reloaded) == ([], [], False)
    assert fake.calls == []


def test_sync_does_not_reload_when_validate_fails(setup, tmp_path):
    paths, registry = setup
    _make_instance(paths, "demo--main")
    caddyfile_path = tmp_path / "Caddyfile"
    fake = _runner(caddyfile_path, validate_rc=1)

    with pytest.raises(FleetError):
        instances_mod.sync_instance_auth(
            paths,
            registry,
            snippet_dir=tmp_path / "instances",
            caddyfile_path=caddyfile_path,
            runner=fake,
        )

    assert ["caddy", "reload"] not in [c["cmd"][0:2] for c in fake.calls]
