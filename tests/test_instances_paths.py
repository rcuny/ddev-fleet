from pathlib import Path

from fleet.core.instances import FleetPaths


def test_from_home_derives_srv_fleet_config_layout():
    paths = FleetPaths.from_home(Path("/srv/fleet"))

    assert paths.home == Path("/srv/fleet")
    assert paths.registry == Path("/srv/fleet/config/fleet.yml")
    assert paths.assets == Path("/srv/fleet/config/assets")
    assert paths.instances == Path("/srv/fleet/instances")
    assert paths.secrets == Path("/srv/fleet/.secrets")
    assert paths.locks == Path("/srv/fleet/locks")
    assert paths.push_key_dir == Path("/srv/fleet/.push-key")
