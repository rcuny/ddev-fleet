"""Bulk orchestration over the existing single-instance primitives in
`core/instances.py` (spec §4, 2026-07-24 fleet-bulk-actions design). No new
state, no new locking beyond the `_multideploy` advisory lock added in
Task 4 — every per-instance call goes through `core/instances.py`'s own
`instance_lock()` exactly as it does today.
"""

import threading
import time
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from typing import Callable

from fleet.core.errors import FleetError
from fleet.core.runner import run_streamed

# Matches JobManager's own concurrency=2 (fleet/jobs.py) — a shared constant
# so the CLI's run_concurrent() and the daemon's JobManager can't drift on
# how many instances run in parallel.
DEFAULT_BULK_CONCURRENCY = 2


@dataclass
class BulkResult:
    instance_id: str
    ok: bool
    error: str | None
    duration_s: float


@dataclass
class BulkOutcome:
    kind: str  # "start" | "stop" | "destroy" | "deploy"
    results: list[BulkResult]

    @property
    def succeeded(self) -> list[BulkResult]:
        return [r for r in self.results if r.ok]

    @property
    def failed(self) -> list[BulkResult]:
        return [r for r in self.results if not r.ok]

    @property
    def all_ok(self) -> bool:
        return all(r.ok for r in self.results)


def _progress_dict(total: int, results: list[BulkResult], *, current: str | None) -> dict:
    return {
        "total": total,
        "done": len(results),
        "failed": sum(1 for r in results if not r.ok),
        "current": current,
        "results": [{"instance_id": r.instance_id, "ok": r.ok, "error": r.error} for r in results],
    }


def _call_op(op: Callable, paths, registry, instance_id: str, runner) -> BulkResult:
    start = time.monotonic()
    try:
        op(paths, registry, instance_id, runner=runner)
        return BulkResult(
            instance_id=instance_id, ok=True, error=None, duration_s=time.monotonic() - start
        )
    except FleetError as exc:
        return BulkResult(
            instance_id=instance_id,
            ok=False,
            error=exc.message,
            duration_s=time.monotonic() - start,
        )


def run_sequential(
    paths,
    registry,
    instance_ids: list[str],
    op: Callable,
    *,
    kind: str,
    on_progress: Callable[[dict], None] | None = None,
    runner=run_streamed,
) -> BulkOutcome:
    """Run `op(paths, registry, instance_id, runner=...)` once per instance
    id, in order, one at a time. A per-instance FleetError is caught and
    turned into a failed BulkResult — it never aborts the batch (spec §9)."""
    results: list[BulkResult] = []
    for instance_id in instance_ids:
        if on_progress is not None:
            on_progress(_progress_dict(len(instance_ids), results, current=instance_id))
        results.append(_call_op(op, paths, registry, instance_id, runner))
    if on_progress is not None:
        on_progress(_progress_dict(len(instance_ids), results, current=None))
    return BulkOutcome(kind=kind, results=results)


def run_concurrent(
    paths,
    registry,
    instance_ids: list[str],
    op: Callable,
    *,
    kind: str,
    max_workers: int = DEFAULT_BULK_CONCURRENCY,
    on_progress: Callable[[dict], None] | None = None,
    runner=run_streamed,
) -> BulkOutcome:
    """Same contract as run_sequential, but up to `max_workers` instances run
    at once via a thread pool. Results are returned in `instance_ids` order
    regardless of completion order."""
    results: list[BulkResult] = []
    lock = threading.Lock()

    def _worker(instance_id: str) -> None:
        result = _call_op(op, paths, registry, instance_id, runner)
        with lock:
            results.append(result)
            if on_progress is not None:
                on_progress(_progress_dict(len(instance_ids), results, current=None))

    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        list(executor.map(_worker, instance_ids))

    order = {instance_id: i for i, instance_id in enumerate(instance_ids)}
    results.sort(key=lambda r: order[r.instance_id])
    return BulkOutcome(kind=kind, results=results)
