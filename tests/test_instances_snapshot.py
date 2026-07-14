import pytest

from fleet.core import instances
from fleet.core.errors import FleetError
from fleet.core.registry import Registry
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def _registry(fleet_home):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default: {}
""",
        encoding="utf-8",
    )
    return Registry.load(paths.registry)


def test_snapshot_missing_instance_dir_raises(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    with pytest.raises(FleetError):
        instances.snapshot(paths, registry, "demo--develop", runner=FakeRunner())


def test_snapshot_runs_ddev_export_db_under_lock_and_derives_project(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    instance_dir = paths.instances / "demo--develop"
    fleet_dir = instance_dir / ".fleet"
    fleet_dir.mkdir(parents=True)
    (fleet_dir / "instance.yml").write_text(
        "project: demo\ninstance: develop\nbranch: main\n"
        "created-at: '2026-07-01T00:00:00Z'\nlast-deployed-at: '2026-07-01T00:00:00Z'\n",
        encoding="utf-8",
    )
    fake = FakeRunner()

    dest = instances.snapshot(paths, registry, "demo--develop", runner=fake)

    expected_dest = paths.assets / "demo" / "dumps" / "db.sql.gz"
    assert dest == expected_dest
    assert fake.calls[0]["cmd"] == ["ddev", "export-db", f"--file={expected_dest}"]
    assert fake.calls[0]["cwd"] == instance_dir

    lock_path = paths.locks / "demo--develop.lock"
    assert lock_path.exists()


def test_snapshot_falls_back_to_id_split_without_instance_yml(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    (paths.instances / "demo--develop").mkdir(parents=True)
    fake = FakeRunner()

    dest = instances.snapshot(paths, registry, "demo--develop", runner=fake)

    assert dest == paths.assets / "demo" / "dumps" / "db.sql.gz"


def test_snapshot_raises_on_nonzero_export_db(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    (paths.instances / "demo--develop").mkdir(parents=True)
    fake = FakeRunner(default=RunResult(returncode=1, lines=["boom"]))

    with pytest.raises(FleetError):
        instances.snapshot(paths, registry, "demo--develop", runner=fake)


def test_snapshot_accepts_custom_dest_rel(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    (paths.instances / "demo--develop").mkdir(parents=True)
    fake = FakeRunner()

    dest = instances.snapshot(
        paths, registry, "demo--develop", dest_rel="custom/dump.sql.gz", runner=fake
    )

    assert dest == paths.assets / "demo" / "custom" / "dump.sql.gz"
