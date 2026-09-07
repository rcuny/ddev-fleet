"""Bulk CLI surface: nargs="*" positional + --all/--project/--state
selectors for start/stop/destroy, multi-deploy --count, and destroy
confirmation (spec §1-§3, 2026-07-24 fleet-bulk-actions design)."""

import functools

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


def test_start_all_sequential_routes_through_run_sequential(fleet_home, monkeypatch):
    """`fleet start --all --sequential` (the fleet-boot.service invocation)
    must dispatch via bulk_mod.run_sequential, one instance at a time, not
    the default run_concurrent."""
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )

    def boom(*a, **kw):
        raise AssertionError("run_concurrent must not be called when --sequential is passed")

    monkeypatch.setattr(cli.bulk_mod, "run_concurrent", boom)

    recorder = []

    def fake_run_sequential(paths, registry, instance_ids, op, *, kind, **kw):
        recorder.append((kind, list(instance_ids)))
        return cli.bulk_mod.BulkOutcome(
            kind=kind,
            results=[
                cli.bulk_mod.BulkResult(instance_id=i, ok=True, error=None, duration_s=0.0)
                for i in instance_ids
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "run_sequential", fake_run_sequential)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "--all", "--sequential"])

    assert exit_code == 0
    assert recorder == [("start", ["oak--a", "oak--b", "other--c"])]


def test_start_all_without_sequential_still_uses_run_concurrent(fleet_home, monkeypatch):
    """Default (flag absent) behaviour must be unchanged: run_concurrent,
    not run_sequential."""
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )

    def boom(*a, **kw):
        raise AssertionError("run_sequential must not be called without --sequential")

    monkeypatch.setattr(cli.bulk_mod, "run_sequential", boom)

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

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "--all"])

    assert exit_code == 0
    assert recorder == [("start", ["oak--a", "oak--b", "other--c"])]


def test_start_all_sequential_timeout_binds_partial_onto_op(fleet_home, monkeypatch):
    """`fleet start --all --sequential --timeout 1800` (fleet-boot.service)
    must bind the timeout onto the per-instance op via functools.partial,
    without breaking run_sequential's generic
    op(paths, registry, instance_id, runner=...) calling convention."""
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )

    captured_ops = []

    def fake_run_sequential(paths, registry, instance_ids, op, *, kind, **kw):
        captured_ops.append(op)
        return cli.bulk_mod.BulkOutcome(
            kind=kind,
            results=[
                cli.bulk_mod.BulkResult(instance_id=i, ok=True, error=None, duration_s=0.0)
                for i in instance_ids
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "run_sequential", fake_run_sequential)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "start", "--all", "--sequential", "--timeout", "1800"]
    )

    assert exit_code == 0
    assert isinstance(captured_ops[0], functools.partial)
    assert captured_ops[0].func is cli.instances_mod.start
    assert captured_ops[0].keywords == {"timeout": 1800.0}


def test_start_all_without_timeout_op_is_unwrapped(fleet_home, monkeypatch):
    """Default (flag absent) behaviour must be unchanged: op is passed
    through as-is, no functools.partial wrapping."""
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )

    captured_ops = []

    def fake_run_concurrent(paths, registry, instance_ids, op, *, kind, **kw):
        captured_ops.append(op)
        return cli.bulk_mod.BulkOutcome(
            kind=kind,
            results=[
                cli.bulk_mod.BulkResult(instance_id=i, ok=True, error=None, duration_s=0.0)
                for i in instance_ids
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "run_concurrent", fake_run_concurrent)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "--all"])

    assert exit_code == 0
    assert captured_ops[0] is cli.instances_mod.start


def test_start_single_explicit_id_honours_timeout(fleet_home, monkeypatch):
    """The single-explicit-id fast path (bypasses the bulk machinery
    entirely) must still honour --timeout."""
    _write_two_project_registry(fleet_home)
    captured = []

    def fake_start(paths, registry, instance_id, *, timeout=None, runner=None):
        captured.append((instance_id, timeout))

    monkeypatch.setattr(cli.instances_mod, "start", fake_start)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "oak--a", "--timeout", "1800"])

    assert exit_code == 0
    assert captured == [("oak--a", 1800.0)]


def test_stop_sequential_routes_through_run_sequential(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    recorder = []

    def fake_run_sequential(paths, registry, instance_ids, op, *, kind, **kw):
        recorder.append(kind)
        return cli.bulk_mod.BulkOutcome(
            kind=kind,
            results=[
                cli.bulk_mod.BulkResult(instance_id=i, ok=True, error=None, duration_s=0.0)
                for i in instance_ids
            ],
        )

    monkeypatch.setattr(cli.bulk_mod, "run_sequential", fake_run_sequential)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "stop", "oak--a", "oak--b", "--sequential"]
    )

    assert exit_code == 0
    assert recorder == ["stop"]


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


def test_destroy_selector_matching_one_instance_non_tty_refuses(fleet_home, monkeypatch, capsys):
    """A selector (--project/--all/--state) that resolves to exactly ONE
    instance must still require confirmation — the single-instance bypass
    only applies to an explicitly-typed positional id, never to a selector
    result. Under non-tty stdin without --yes it must refuse."""
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )
    monkeypatch.setattr(cli.sys, "stdin", _FakeStdin(is_tty=False))
    boom_called = []
    monkeypatch.setattr(cli.instances_mod, "destroy", lambda *a, **kw: boom_called.append(True))

    exit_code = cli.main(["--fleet-home", str(fleet_home), "destroy", "--project=other"])

    assert exit_code == 1
    assert "refusing to destroy 1 instances without --yes" in capsys.readouterr().err
    assert boom_called == []


def test_destroy_selector_matching_one_instance_yes_flag_proceeds(fleet_home, monkeypatch):
    """Same selector-resolves-to-one-instance case, but with --yes: it must
    proceed and actually destroy the resolved instance."""
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )
    recorder = []
    monkeypatch.setattr(
        cli.instances_mod, "destroy", lambda paths, registry, iid, **kw: recorder.append(iid)
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "destroy", "--project=other", "--yes"])

    assert exit_code == 0
    assert recorder == ["other--c"]


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


# --- redeploy: mirrors destroy's confirmation ceremony exactly (spec §2,
# 2026-07-27-fleet-redeploy-and-no-overwrite-design.md) since redeploy
# destroys before rebuilding. ---


def test_redeploy_single_explicit_id_no_confirmation_prints_url(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)
    recorder = []

    def fake_redeploy(paths, registry, iid, **kw):
        recorder.append((iid, kw))
        return f"https://{iid}.fleet.example.test"

    monkeypatch.setattr(cli.instances_mod, "redeploy", fake_redeploy)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "redeploy", "oak--a"])

    assert exit_code == 0
    assert recorder[0][0] == "oak--a"
    assert "https://oak--a.fleet.example.test" in capsys.readouterr().out


def test_redeploy_selector_matching_one_instance_non_tty_refuses(fleet_home, monkeypatch, capsys):
    """Same rule as destroy: a selector that resolves to exactly one
    instance is NOT the same as an explicit single id — it still requires
    confirmation."""
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )
    monkeypatch.setattr(cli.sys, "stdin", _FakeStdin(is_tty=False))
    boom_called = []
    monkeypatch.setattr(cli.instances_mod, "redeploy", lambda *a, **kw: boom_called.append(True))

    exit_code = cli.main(["--fleet-home", str(fleet_home), "redeploy", "--project=other"])

    assert exit_code == 1
    assert "refusing to redeploy 1 instances without --yes" in capsys.readouterr().err
    assert boom_called == []


def test_redeploy_selector_matching_one_instance_yes_flag_proceeds(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: _fake_statuses()
    )
    recorder = []
    monkeypatch.setattr(
        cli.instances_mod,
        "redeploy",
        lambda paths, registry, iid, **kw: recorder.append(iid) or f"https://{iid}",
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "redeploy", "--project=other", "--yes"])

    assert exit_code == 0
    assert recorder == ["other--c"]


def test_redeploy_multi_without_yes_non_tty_refuses(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)
    monkeypatch.setattr(cli.sys, "stdin", _FakeStdin(is_tty=False))
    boom_called = []
    monkeypatch.setattr(cli.instances_mod, "redeploy", lambda *a, **kw: boom_called.append(True))

    exit_code = cli.main(["--fleet-home", str(fleet_home), "redeploy", "oak--a", "oak--b"])

    assert exit_code == 1
    assert "refusing to redeploy 2 instances without --yes" in capsys.readouterr().err
    assert boom_called == []


def test_redeploy_multi_yes_flag_runs_sequentially(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    recorder = []

    def fake_redeploy(paths, registry, iid, **kw):
        recorder.append(iid)
        return f"https://{iid}"

    monkeypatch.setattr(cli.instances_mod, "redeploy", fake_redeploy)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "redeploy", "oak--a", "oak--b", "--yes"])

    assert exit_code == 0
    # run_sequential (not run_concurrent) preserves target order.
    assert recorder == ["oak--a", "oak--b"]


def test_redeploy_template_and_auth_password_reach_instances_mod(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    captured = {}

    def fake_redeploy(paths, registry, iid, *, template=None, auth_password=None, **kw):
        captured["template"] = template
        captured["auth_password"] = auth_password
        return f"https://{iid}"

    monkeypatch.setattr(cli.instances_mod, "redeploy", fake_redeploy)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "redeploy",
            "oak--a",
            "--template=custom",
            "--auth-password=s3cret",
        ]
    )

    assert exit_code == 0
    assert captured == {"template": "custom", "auth_password": "s3cret"}


def test_redeploy_template_applies_to_every_target_in_multi_selection(fleet_home, monkeypatch):
    _write_two_project_registry(fleet_home)
    captured = []

    def fake_redeploy(paths, registry, iid, *, template=None, **kw):
        captured.append((iid, template))
        return f"https://{iid}"

    monkeypatch.setattr(cli.instances_mod, "redeploy", fake_redeploy)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "redeploy",
            "oak--a",
            "oak--b",
            "--yes",
            "--template=custom",
        ]
    )

    assert exit_code == 0
    assert captured == [("oak--a", "custom"), ("oak--b", "custom")]


def test_redeploy_bulk_partial_failure_exit_code_2(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)

    def fake_redeploy(paths, registry, iid, **kw):
        if iid == "oak--b":
            from fleet.core.errors import FleetError

            raise FleetError("no template recorded")
        return f"https://{iid}"

    monkeypatch.setattr(cli.instances_mod, "redeploy", fake_redeploy)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "redeploy", "oak--a", "oak--b", "--yes"])

    assert exit_code == 2
    out, err = capsys.readouterr()
    assert "oak--a: OK" in out
    assert "oak--b: FAILED — no template recorded" in err


def test_redeploy_bulk_all_fail_exit_code_1(fleet_home, monkeypatch, capsys):
    _write_two_project_registry(fleet_home)

    def fake_redeploy(paths, registry, iid, **kw):
        from fleet.core.errors import FleetError

        raise FleetError("boom")

    monkeypatch.setattr(cli.instances_mod, "redeploy", fake_redeploy)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "redeploy", "oak--a", "oak--b", "--yes"])

    assert exit_code == 1


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
