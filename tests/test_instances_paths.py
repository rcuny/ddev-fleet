from pathlib import Path

from fleet.core.instances import FleetPaths, load_registry


def test_from_home_derives_srv_fleet_config_layout():
    paths = FleetPaths.from_home(Path("/srv/fleet"))

    assert paths.home == Path("/srv/fleet")
    assert paths.registry == Path("/srv/fleet/config/fleet.yml")
    assert paths.assets == Path("/srv/fleet/config/assets")
    assert paths.instances == Path("/srv/fleet/instances")
    assert paths.secrets == Path("/srv/fleet/.secrets")
    assert paths.project_secrets == Path("/srv/fleet/secrets")
    assert paths.gnupg == Path("/srv/fleet/gnupg")
    assert paths.locks == Path("/srv/fleet/locks")
    assert paths.push_key_dir == Path("/srv/fleet/.push-key")
    # host.yml is per-host — deliberately a SIBLING of config/ (the shared,
    # git-tracked fleet.yml registry repo), never inside it.
    assert paths.host_config == Path("/srv/fleet/host.yml")


def test_load_registry_uses_host_config_path(fleet_home):
    """`load_registry()` is THE constructor every CLI/daemon call site
    uses — it must always pass `paths.host_config` through to
    `Registry.load()`, so a shared fleet.yml (no fleet.domain of its own)
    still resolves via this host's host.yml."""
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        "fleet: {}\nprojects: {}\n",
        encoding="utf-8",
    )
    paths.host_config.write_text("domain: fleet.host-a.test\n", encoding="utf-8")

    registry = load_registry(paths)

    assert registry.domain == "fleet.host-a.test"
