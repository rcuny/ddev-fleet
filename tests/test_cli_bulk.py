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


class _FakeStdin:
    def __init__(self, is_tty):
        self._is_tty = is_tty

    def isatty(self):
        return self._is_tty


def test_destroy_single_id_still_confirmation_free(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    recorder = []
    monkeypatch.setattr(
        cli.instances_mod, "destroy", lambda paths, registry, iid, **kw: recorder.append(iid)
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "destroy", "oak--a"])

    assert exit_code == 0
    assert recorder == ["oak--a"]


def test_destroy_multi_without_yes_non_tty_refuses(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(cli.sys, "stdin", _FakeStdin(is_tty=False))
    boom_called = []
    monkeypatch.setattr(cli.instances_mod, "destroy", lambda *a, **kw: boom_called.append(True))

    exit_code = cli.main(["--fleet-home", str(fleet_home), "destroy", "oak--a", "oak--b"])

    assert exit_code == 1
    assert "refusing to destroy 2 instances without --yes" in capsys.readouterr().err
    assert boom_called == []


def test_destroy_multi_yes_flag_skips_prompt(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    recorder = []
    monkeypatch.setattr(
        cli.instances_mod, "destroy", lambda paths, registry, iid, **kw: recorder.append(iid)
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "destroy", "oak--a", "oak--b", "--yes"])

    assert exit_code == 0
    assert recorder == ["oak--a", "oak--b"]


def test_destroy_multi_typed_confirmation_match_proceeds(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(cli.sys, "stdin", _FakeStdin(is_tty=True))
    monkeypatch.setattr(cli, "input", lambda prompt: "2", raising=False)
    recorder = []
    monkeypatch.setattr(
        cli.instances_mod, "destroy", lambda paths, registry, iid, **kw: recorder.append(iid)
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "destroy", "oak--a", "oak--b"])

    assert exit_code == 0
    assert recorder == ["oak--a", "oak--b"]


def test_destroy_multi_typed_confirmation_mismatch_aborts(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(cli.sys, "stdin", _FakeStdin(is_tty=True))
    monkeypatch.setattr(cli, "input", lambda prompt: "wrong", raising=False)
    boom_called = []
    monkeypatch.setattr(cli.instances_mod, "destroy", lambda *a, **kw: boom_called.append(True))

    exit_code = cli.main(["--fleet-home", str(fleet_home), "destroy", "oak--a", "oak--b"])

    assert exit_code == 1
    assert "confirmation did not match; aborted, nothing destroyed" in capsys.readouterr().err
    assert boom_called == []


def test_destroy_bulk_partial_failure_exit_code_2(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)

    def fake_destroy(paths, registry, iid, **kw):
        if iid == "oak--b":
            from fleet.core.errors import FleetError

            raise FleetError("still running")

    monkeypatch.setattr(cli.instances_mod, "destroy", fake_destroy)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "destroy", "oak--a", "oak--b", "--yes"])

    assert exit_code == 2
    out, err = capsys.readouterr()
    assert "oak--a: OK" in out
    assert "oak--b: FAILED — still running" in err


def test_deploy_count_default_is_single_deploy_unchanged(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)

    monkeypatch.setattr(
        cli.instances_mod, "deploy", lambda *a, **kw: "https://oak--develop.fleet.example.test"
    )

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "deploy", "oak", "default", "--branch=main"]
    )

    assert exit_code == 0
    assert "https://oak--develop.fleet.example.test" in capsys.readouterr().out


def test_deploy_count_one_explicit_takes_single_deploy_path(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)

    def boom(*a, **kw):
        raise AssertionError("multi_deploy must not run for --count=1")

    monkeypatch.setattr(cli.bulk_mod, "multi_deploy", boom)
    monkeypatch.setattr(
        cli.instances_mod, "deploy", lambda *a, **kw: "https://oak--develop.fleet.example.test"
    )

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "deploy",
            "oak",
            "default",
            "--branch=main",
            "--count=1",
        ]
    )

    assert exit_code == 0


def test_deploy_count_zero_prints_noop_message_and_exits_0(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)

    def boom(*a, **kw):
        raise AssertionError("multi_deploy must not run for --count=0")

    monkeypatch.setattr(cli.bulk_mod, "multi_deploy", boom)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "deploy",
            "oak",
            "default",
            "--branch=main",
            "--count=0",
        ]
    )

    assert exit_code == 0
    assert "nothing to deploy (--count=0)" in capsys.readouterr().out


def test_deploy_count_21_rejected(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)
    from fleet.core.errors import ValidationError

    def fake_multi_deploy(paths, registry, project, template, *, count, **kw):
        raise ValidationError(f"--count must be between 0 and 20 (got {count})")

    monkeypatch.setattr(cli.bulk_mod, "multi_deploy", fake_multi_deploy)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "deploy",
            "oak",
            "default",
            "--branch=main",
            "--count=21",
        ]
    )

    assert exit_code == 1
    assert "--count must be between 0 and 20 (got 21)" in capsys.readouterr().err


def test_deploy_count_negative_one_rejected(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)
    from fleet.core.errors import ValidationError

    def fake_multi_deploy(paths, registry, project, template, *, count, **kw):
        raise ValidationError(f"--count must be between 0 and 20 (got {count})")

    monkeypatch.setattr(cli.bulk_mod, "multi_deploy", fake_multi_deploy)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "deploy",
            "oak",
            "default",
            "--branch=main",
            "--count=-1",
        ]
    )

    assert exit_code == 1
    assert "got -1" in capsys.readouterr().err


def test_deploy_dash_n_shortflag_equivalent_to_count(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    captured = {}

    def fake_multi_deploy(paths, registry, project, template, *, count, **kw):
        captured["count"] = count
        return cli.bulk_mod.BulkOutcome(kind="deploy", results=[])

    monkeypatch.setattr(cli.bulk_mod, "multi_deploy", fake_multi_deploy)

    cli.main(
        ["--fleet-home", str(fleet_home), "deploy", "oak", "default", "--branch=main", "-n", "5"]
    )

    assert captured["count"] == 5


def test_deploy_count_20_dispatches_multi_deploy_with_skip_disk_check_flag(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    captured = {}

    def fake_multi_deploy(paths, registry, project, template, *, count, skip_disk_check, **kw):
        captured["count"] = count
        captured["skip_disk_check"] = skip_disk_check
        return cli.bulk_mod.BulkOutcome(
            kind="deploy",
            results=[
                cli.bulk_mod.BulkResult(
                    instance_id=f"oak--generic-{n}", ok=True, error=None, duration_s=0.0
                )
                for n in range(1, count + 1)
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "multi_deploy", fake_multi_deploy)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "deploy",
            "oak",
            "default",
            "--branch=main",
            "--label=generic",
            "--count=20",
            "--skip-disk-check",
        ]
    )

    assert exit_code == 0
    assert captured == {"count": 20, "skip_disk_check": True}


def test_deploy_multi_deploy_partial_failure_exit_code_2(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)

    def fake_multi_deploy(paths, registry, project, template, *, count, **kw):
        return cli.bulk_mod.BulkOutcome(
            kind="deploy",
            results=[
                cli.bulk_mod.BulkResult(
                    instance_id="oak--generic-1", ok=True, error=None, duration_s=0.0
                ),
                cli.bulk_mod.BulkResult(
                    instance_id="oak--generic-2",
                    ok=False,
                    error="disk gate tripped",
                    duration_s=0.0,
                ),
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "multi_deploy", fake_multi_deploy)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "deploy",
            "oak",
            "default",
            "--branch=main",
            "--label=generic",
            "--count=2",
        ]
    )

    assert exit_code == 2
    out, err = capsys.readouterr()
    assert "oak--generic-1: OK" in out
    assert "oak--generic-2: FAILED — disk gate tripped" in err
