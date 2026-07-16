import os
from pathlib import Path

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

    expected_dest = paths.assets / "demo" / "dumps" / "default-demo--develop.sql"
    assert dest == expected_dest
    assert fake.calls[0]["cmd"] == [
        "ddev",
        "export-db",
        f"--file={expected_dest}",
        "--gzip=false",
    ]
    assert fake.calls[0]["cwd"] == instance_dir

    lock_path = paths.locks / "demo--develop.lock"
    assert lock_path.exists()


def test_snapshot_falls_back_to_id_split_without_instance_yml(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    (paths.instances / "demo--develop").mkdir(parents=True)
    fake = FakeRunner()

    dest = instances.snapshot(paths, registry, "demo--develop", runner=fake)

    assert dest == paths.assets / "demo" / "dumps" / "default-demo--develop.sql"


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
        paths, registry, "demo--develop", dest_rel="custom/dump.sql", runner=fake
    )

    assert dest == paths.assets / "demo" / "custom" / "dump.sql"


def test_snapshot_gzip_false_passed_to_ddev_export_db(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    (paths.instances / "demo--develop").mkdir(parents=True)
    fake = FakeRunner()

    instances.snapshot(paths, registry, "demo--develop", runner=fake)

    assert "--gzip=false" in fake.calls[0]["cmd"]


def test_snapshot_refuses_to_write_shared_default_dump(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    (paths.instances / "demo--develop").mkdir(parents=True)
    fake = FakeRunner()

    with pytest.raises(FleetError, match="shared project dump"):
        instances.snapshot(
            paths, registry, "demo--develop", dest_rel="dumps/default.sql", runner=fake
        )

    # Must fail before ever invoking ddev export-db.
    assert fake.calls == []


def test_snapshot_unlinks_preexisting_dest_so_other_hard_link_survives_untouched(fleet_home):
    """Simulates the real-world hazard: `_link_shared_dir` (core/assets.py)
    hard-links every file under `<assets>/<project>/dumps/` into each
    instance, so a pre-existing dest at snapshot time may share an inode with
    a copy already linked into another instance (or the config repo's own
    checkout). snapshot() must unlink dest before ddev writes to it, so the
    write lands on a fresh inode and the other link's content is untouched —
    this is a real inode-level check, not a mock.
    """
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    (paths.instances / "demo--develop").mkdir(parents=True)

    dest = paths.assets / "demo" / "dumps" / "default-demo--develop.sql"
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_text("ORIGINAL SHARED CONTENT", encoding="utf-8")

    # A second directory entry hard-linked to the same inode, standing in for
    # another instance's already-deployed copy.
    other_link = paths.instances / "other-instance-copy.sql"
    os.link(dest, other_link)
    assert dest.stat().st_ino == other_link.stat().st_ino
    assert dest.stat().st_nlink == 2

    class WritingFakeRunner(FakeRunner):
        """Actually writes to --file=<dest> like real `ddev export-db` would,
        so we can observe whether the write happened through the shared
        inode (corrupting other_link) or a fresh one (leaving it intact)."""

        def __call__(self, cmd, *, cwd=None, env=None, log_path=None, echo=True):
            for arg in cmd:
                if isinstance(arg, str) and arg.startswith("--file="):
                    Path(arg[len("--file=") :]).write_text("NEW SNAPSHOT", encoding="utf-8")
            return super().__call__(cmd, cwd=cwd, env=env, log_path=log_path, echo=echo)

    fake = WritingFakeRunner()

    instances.snapshot(paths, registry, "demo--develop", runner=fake)

    # dest now holds the fresh export ...
    assert dest.read_text(encoding="utf-8") == "NEW SNAPSHOT"
    # ... but the other hard link still holds the original content untouched,
    # proving the write landed on a fresh inode rather than the shared one.
    assert other_link.read_text(encoding="utf-8") == "ORIGINAL SHARED CONTENT"
    assert dest.stat().st_ino != other_link.stat().st_ino
