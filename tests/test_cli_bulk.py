"""Bulk CLI surface: nargs="*" positional + --all/--project/--state
selectors for start/stop/destroy, multi-deploy --count, and destroy
confirmation (spec §1-§3, 2026-07-24 fleet-bulk-actions design)."""

from fleet import cli
from fleet.core.instances import FleetPaths, InstanceStatus


def _write_two_project_registry(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    templates:
      default: {}
  other:
    git: git@example.test:org/other.git
    templates:
      default: {}
""",
        encoding="utf-8",
    )


def _fake_statuses():
    return [
        InstanceStatus(
            instance_id="oak--a",
            project="oak",
            instance="a",
            branch="main",
            state="running",
            url="https://oak--a.fleet.example.test",
            ram_mib=100,
        ),
        InstanceStatus(
            instance_id="oak--b",
            project="oak",
            instance="b",
            branch="main",
            state="deployed",
            url="https://oak--b.fleet.example.test",
            ram_mib=None,
        ),
        InstanceStatus(
            instance_id="other--c",
            project="other",
            instance="c",
            branch="main",
            state="running",
            url="https://other--c.fleet.example.test",
            ram_mib=50,
        ),
    ]


def test_start_explicit_ids_plus_selector_errors(fleet_home, capsys):
    _write_two_project_registry(fleet_home)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "oak--a", "--all"])

    assert exit_code == 1
    assert "cannot combine explicit instance ids with" in capsys.readouterr().err


def test_start_all_plus_project_errors(fleet_home, capsys):
    _write_two_project_registry(fleet_home)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "--all", "--project=oak"])

    assert exit_code == 1
    assert "--all cannot be combined with" in capsys.readouterr().err


def test_start_no_ids_no_selector_errors(fleet_home, capsys):
    _write_two_project_registry(fleet_home)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start"])

    assert exit_code == 1
    assert "at least one instance id" in capsys.readouterr().err


def test_start_selector_matches_nothing_exits_0(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(cli.instances_mod, "list_instances", lambda paths, registry, **kw: [])

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "--all"])

    assert exit_code == 0
    assert "no instances matched the given selector" in capsys.readouterr().err


def test_start_project_selector_resolves_matching_ids(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )
    recorder = []

    def fake_run_concurrent(paths, registry, instance_ids, op, *, kind, **kw):
        recorder.append((kind, list(instance_ids)))
        return cli.bulk_mod.BulkOutcome(
            kind=kind,
            results=[
                cli.bulk_mod.BulkResult(instance_id=i, ok=True, error=None, duration_s=0.0)
                for i in instance_ids
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "run_concurrent", fake_run_concurrent)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "--project=oak"])

    assert exit_code == 0
    assert recorder == [("start", ["oak--a", "oak--b"])]


def test_start_state_selector_resolves_matching_ids(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )
    recorder = []

    def fake_run_concurrent(paths, registry, instance_ids, op, *, kind, **kw):
        recorder.append(list(instance_ids))
        return cli.bulk_mod.BulkOutcome(kind=kind, results=[])

    monkeypatch.setattr(cli.bulk_mod, "run_concurrent", fake_run_concurrent)

    cli.main(["--fleet-home", str(fleet_home), "start", "--state=running"])

    assert recorder == [["oak--a", "other--c"]]


def test_start_project_and_state_are_and_combined(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )
    recorder = []

    def fake_run_concurrent(paths, registry, instance_ids, op, *, kind, **kw):
        recorder.append(list(instance_ids))
        return cli.bulk_mod.BulkOutcome(kind=kind, results=[])

    monkeypatch.setattr(cli.bulk_mod, "run_concurrent", fake_run_concurrent)

    cli.main(["--fleet-home", str(fleet_home), "start", "--project=oak", "--state=deployed"])

    assert recorder == [["oak--b"]]


def test_start_bulk_exit_code_2_on_partial_failure(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)

    def fake_run_concurrent(paths, registry, instance_ids, op, *, kind, **kw):
        return cli.bulk_mod.BulkOutcome(
            kind=kind,
            results=[
                cli.bulk_mod.BulkResult(instance_id="oak--a", ok=True, error=None, duration_s=0.1),
                cli.bulk_mod.BulkResult(
                    instance_id="oak--b", ok=False, error="ddev start failed", duration_s=0.1
                ),
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "run_concurrent", fake_run_concurrent)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "oak--a", "oak--b"])

    assert exit_code == 2
    out, err = capsys.readouterr()
    assert "oak--a: OK" in out
    assert "oak--b: FAILED — ddev start failed" in err
    assert "1 succeeded, 1 failed" in out


def test_start_bulk_exit_code_1_when_all_fail(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)

    def fake_run_concurrent(paths, registry, instance_ids, op, *, kind, **kw):
        return cli.bulk_mod.BulkOutcome(
            kind=kind,
            results=[
                cli.bulk_mod.BulkResult(instance_id=i, ok=False, error="boom", duration_s=0.0)
                for i in instance_ids
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "run_concurrent", fake_run_concurrent)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "oak--a", "oak--b"])

    assert exit_code == 1


def test_start_bulk_exit_code_0_when_all_succeed(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)

    def fake_run_concurrent(paths, registry, instance_ids, op, *, kind, **kw):
        return cli.bulk_mod.BulkOutcome(
            kind=kind,
            results=[
                cli.bulk_mod.BulkResult(instance_id=i, ok=True, error=None, duration_s=0.0)
                for i in instance_ids
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "run_concurrent", fake_run_concurrent)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "oak--a", "oak--b"])

    assert exit_code == 0


def test_stop_uses_run_concurrent_for_multiple_ids(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    recorder = []

    def fake_run_concurrent(paths, registry, instance_ids, op, *, kind, **kw):
        recorder.append(kind)
        return cli.bulk_mod.BulkOutcome(
            kind=kind,
            results=[
                cli.bulk_mod.BulkResult(instance_id=i, ok=True, error=None, duration_s=0.0)
                for i in instance_ids
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "run_concurrent", fake_run_concurrent)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "stop", "oak--a", "oak--b"])

    assert exit_code == 0
    assert recorder == ["stop"]


def test_single_id_start_does_not_go_through_bulk_machinery(fleet_home, monkeypatch):
    """Regression guard: a single explicit id must call instances_mod.start
    directly (today's exact behaviour), never bulk.run_concurrent."""
    _write_two_project_registry(fleet_home)
    (fleet_home / "instances" / "oak--a").mkdir(parents=True)

    def boom(*a, **kw):
        raise AssertionError("run_concurrent must not be called for a single explicit id")

    monkeypatch.setattr(cli.bulk_mod, "run_concurrent", boom)
    recorder = []
    monkeypatch.setattr(
        cli.instances_mod, "start", lambda paths, registry, iid, **kw: recorder.append(iid)
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "oak--a"])

    assert exit_code == 0
    assert recorder == ["oak--a"]
