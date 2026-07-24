import time

from fleet.core import bulk


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
