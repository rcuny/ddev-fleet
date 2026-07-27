import time

import pytest

from fleet.core import bulk, instances
from fleet.core.errors import DeployError, DiskSpaceError, ValidationError
from fleet.core.registry import Registry
from fleet.core.secrets import write_secret


def _make_op(behavior):
    """behavior: dict[instance_id] -> an Exception instance to raise, or
    absent -> succeeds."""
    calls = []

    def op(paths, registry, instance_id, *, runner=None):
        calls.append(instance_id)
        outcome = behavior.get(instance_id)
        if isinstance(outcome, Exception):
            raise outcome

    op.calls = calls
    return op


def test_run_sequential_continues_past_failing_instance():
    from fleet.core.errors import FleetError

    op = _make_op({"b": FleetError("boom")})

    outcome = bulk.run_sequential(None, None, ["a", "b", "c"], op, kind="stop")

    assert op.calls == ["a", "b", "c"]
    assert [r.instance_id for r in outcome.results] == ["a", "b", "c"]
    assert outcome.results[0].ok is True
    assert outcome.results[1].ok is False
    assert outcome.results[1].error == "boom"
    assert outcome.results[2].ok is True


def test_run_sequential_continues_past_bare_os_error():
    """A bare OSError (e.g. destroy()'s filesystem cleanup hitting a
    root-owned leftover file) must not abort the batch — it should be
    caught and turned into a failed BulkResult just like a FleetError."""
    op = _make_op({"b": OSError("[Errno 13] Permission denied: '/x'")})

    outcome = bulk.run_sequential(None, None, ["a", "b", "c"], op, kind="destroy")

    assert op.calls == ["a", "b", "c"]
    assert [r.instance_id for r in outcome.results] == ["a", "b", "c"]
    assert outcome.results[0].ok is True
    assert outcome.results[1].ok is False
    assert "Permission denied" in outcome.results[1].error
    assert outcome.results[2].ok is True


def test_run_concurrent_continues_past_bare_os_error():
    """Same regression guard as above, but for the ThreadPoolExecutor path
    — a bare OSError raised inside a worker must not propagate out of
    executor.map() and discard the other instances' already-collected
    results."""
    op = _make_op({"b": OSError("[Errno 13] Permission denied: '/x'")})

    outcome = bulk.run_concurrent(None, None, ["a", "b", "c"], op, kind="destroy", max_workers=2)

    assert [r.instance_id for r in outcome.results] == ["a", "b", "c"]
    assert outcome.results[0].ok is True
    assert outcome.results[1].ok is False
    assert "Permission denied" in outcome.results[1].error
    assert outcome.results[2].ok is True


def test_bulk_outcome_succeeded_failed_all_ok():
    from fleet.core.errors import FleetError

    op = _make_op({"b": FleetError("boom")})
    outcome = bulk.run_sequential(None, None, ["a", "b", "c"], op, kind="stop")

    assert [r.instance_id for r in outcome.succeeded] == ["a", "c"]
    assert [r.instance_id for r in outcome.failed] == ["b"]
    assert outcome.all_ok is False

    ok_outcome = bulk.run_sequential(None, None, ["a"], _make_op({}), kind="stop")
    assert ok_outcome.all_ok is True


def test_run_sequential_on_progress_reports_expected_shape():
    from fleet.core.errors import FleetError

    op = _make_op({"b": FleetError("boom")})
    snapshots = []

    bulk.run_sequential(None, None, ["a", "b"], op, kind="stop", on_progress=snapshots.append)

    # One callback right before each instance starts, plus one final callback.
    assert len(snapshots) == 3
    assert snapshots[0] == {"total": 2, "done": 0, "failed": 0, "current": "a", "results": []}
    assert snapshots[1]["current"] == "b"
    assert snapshots[1]["done"] == 1
    assert snapshots[1]["results"] == [{"instance_id": "a", "ok": True, "error": None}]
    final = snapshots[2]
    assert final["current"] is None
    assert final["done"] == 2
    assert final["failed"] == 1
    assert final["results"][1] == {"instance_id": "b", "ok": False, "error": "boom"}


def test_run_concurrent_runs_all_instances_and_preserves_result_order():
    from fleet.core.errors import FleetError

    op = _make_op({"b": FleetError("nope")})

    outcome = bulk.run_concurrent(None, None, ["a", "b", "c"], op, kind="start", max_workers=2)

    assert [r.instance_id for r in outcome.results] == ["a", "b", "c"]
    assert [r.instance_id for r in outcome.succeeded] == ["a", "c"]
    assert [r.instance_id for r in outcome.failed] == ["b"]


def test_run_concurrent_respects_max_workers_limit():
    active = 0
    max_active = 0
    lock = __import__("threading").Lock()

    def op(paths, registry, instance_id, *, runner=None):
        nonlocal active, max_active
        with lock:
            active += 1
            max_active = max(max_active, active)
        time.sleep(0.05)
        with lock:
            active -= 1

    bulk.run_concurrent(None, None, ["a", "b", "c", "d"], op, kind="start", max_workers=2)

    assert max_active <= 2


def _make_paths_and_registry(fleet_home, git_url="git@example.test:org/demo.git"):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: {git_url}
    default_template: default
    templates:
      default: {{}}
""",
        encoding="utf-8",
    )
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-test")
    return paths, Registry.load(paths.registry)


def test_multi_deploy_gates_disk_before_allocation(fleet_home, monkeypatch):
    paths, registry = _make_paths_and_registry(fleet_home)

    def failing_check(instances_dir, **kw):
        raise DiskSpaceError("only 2.0% free on /x — need at least 10% free")

    monkeypatch.setattr(bulk.sysinfo, "check_disk_headroom", failing_check)
    deploy_calls = []
    monkeypatch.setattr(bulk.instances_mod, "deploy", lambda *a, **kw: deploy_calls.append(a))

    with pytest.raises(DiskSpaceError, match="refusing to deploy 3 instances"):
        bulk.multi_deploy(
            paths, registry, "demo", "default", branch="main", label="generic", count=3
        )

    assert deploy_calls == []


def test_multi_deploy_skip_disk_check_bypasses_gate(fleet_home, monkeypatch):
    paths, registry = _make_paths_and_registry(fleet_home)

    def failing_check(instances_dir, **kw):
        raise DiskSpaceError("boom")

    monkeypatch.setattr(bulk.sysinfo, "check_disk_headroom", failing_check)
    monkeypatch.setattr(
        bulk.instances_mod,
        "deploy",
        lambda paths, registry, project, template, **kw: f"https://demo--{kw['label']}.x",
    )

    outcome = bulk.multi_deploy(
        paths,
        registry,
        "demo",
        "default",
        branch="main",
        label="generic",
        count=2,
        skip_disk_check=True,
    )

    assert outcome.all_ok is True
    assert [r.instance_id for r in outcome.results] == ["demo--generic-1", "demo--generic-2"]


def test_multi_deploy_count_zero_is_a_noop(fleet_home):
    paths, registry = _make_paths_and_registry(fleet_home)

    outcome = bulk.multi_deploy(paths, registry, "demo", "default", branch="main", count=0)
    assert outcome.results == []


def test_multi_deploy_invalid_count_raises_validation_error(fleet_home):
    paths, registry = _make_paths_and_registry(fleet_home)

    with pytest.raises(ValidationError, match=r"--count must be between 0 and 20 \(got 21\)"):
        bulk.multi_deploy(paths, registry, "demo", "default", branch="main", count=21)


def test_multi_deploy_continues_past_one_failing_instance(fleet_home, monkeypatch):
    paths, registry = _make_paths_and_registry(fleet_home)
    monkeypatch.setattr(bulk.sysinfo, "check_disk_headroom", lambda *a, **kw: None)

    def fake_deploy(paths, registry, project, template, *, label, **kw):
        if label == "generic-2":
            raise DeployError("ddev start failed")
        return f"https://demo--{label}.x"

    monkeypatch.setattr(bulk.instances_mod, "deploy", fake_deploy)

    outcome = bulk.multi_deploy(
        paths, registry, "demo", "default", branch="main", label="generic", count=3
    )

    assert [r.instance_id for r in outcome.results] == [
        "demo--generic-1",
        "demo--generic-2",
        "demo--generic-3",
    ]
    assert outcome.results[0].ok is True
    assert outcome.results[1].ok is False
    assert "ddev start failed" in outcome.results[1].error
    assert outcome.results[2].ok is True


def test_multi_deploy_aborts_instance_that_collided_mid_batch(fleet_home, monkeypatch):
    """Simulates the race in spec §5: another process creates generic-2's
    directory between allocation and this instance's dispatch. The
    defense-in-depth re-check inside multi_deploy's per-instance op must
    abort just that one instance rather than letting deploy() silently
    treat it as an update."""
    paths, registry = _make_paths_and_registry(fleet_home)
    monkeypatch.setattr(bulk.sysinfo, "check_disk_headroom", lambda *a, **kw: None)

    def fake_deploy(paths, registry, project, template, *, label, **kw):
        if label == "generic-1":
            (paths.instances / "demo--generic-2").mkdir(parents=True)
        return f"https://demo--{label}.x"

    monkeypatch.setattr(bulk.instances_mod, "deploy", fake_deploy)

    outcome = bulk.multi_deploy(
        paths, registry, "demo", "default", branch="main", label="generic", count=3
    )

    assert [r.instance_id for r in outcome.results] == [
        "demo--generic-1",
        "demo--generic-2",
        "demo--generic-3",
    ]
    assert outcome.results[0].ok is True
    assert outcome.results[1].ok is False
    assert "collided since allocation" in outcome.results[1].error
    assert outcome.results[2].ok is True


def test_multi_deploy_no_longer_accepts_fresh_kwarg(fleet_home):
    """`fresh` was removed from multi_deploy() — the CLI flag that fed it
    is gone too (a later step). `force` is unaffected."""
    paths, registry = _make_paths_and_registry(fleet_home)

    with pytest.raises(TypeError):
        bulk.multi_deploy(paths, registry, "demo", "default", branch="main", count=1, fresh=True)


def test_multi_deploy_passes_replace_true_to_deploy_per_instance(fleet_home, monkeypatch):
    """multi_deploy() has already allocated a guaranteed-free id under its
    own hold of the allocation lock, so it must call deploy() with
    replace=True — passing the default (replace=False) would make deploy()
    try to re-acquire the SAME lock file from inside the same process,
    which is not reentrant."""
    paths, registry = _make_paths_and_registry(fleet_home)
    monkeypatch.setattr(bulk.sysinfo, "check_disk_headroom", lambda *a, **kw: None)

    captured_kwargs = []

    def fake_deploy(paths, registry, project, template, **kw):
        captured_kwargs.append(kw)
        return f"https://demo--{kw['label']}.x"

    monkeypatch.setattr(bulk.instances_mod, "deploy", fake_deploy)

    bulk.multi_deploy(paths, registry, "demo", "default", branch="main", label="generic", count=1)

    assert captured_kwargs[0]["replace"] is True


def test_multi_deploy_holds_multideploy_lock_during_allocation_and_dispatch(
    fleet_home, monkeypatch
):
    paths, registry = _make_paths_and_registry(fleet_home)
    monkeypatch.setattr(bulk.sysinfo, "check_disk_headroom", lambda *a, **kw: None)
    monkeypatch.setattr(
        bulk.instances_mod,
        "deploy",
        lambda paths, registry, project, template, **kw: f"https://demo--{kw['label']}.x",
    )

    lock_path = paths.locks / "_multideploy.lock"
    assert not lock_path.exists()

    bulk.multi_deploy(paths, registry, "demo", "default", branch="main", label="generic", count=2)

    assert lock_path.exists()
