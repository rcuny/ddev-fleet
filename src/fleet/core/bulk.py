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

from fleet.core import instances as instances_mod
from fleet.core import naming, sysinfo
from fleet.core.caddyauth import DEFAULT_INSTANCE_PASSWORD, validate_instance_credential
from fleet.core.errors import DiskSpaceError, FleetError, ValidationError
from fleet.core.locks import ALLOCATION_LOCK_ID, instance_lock
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
    except Exception as exc:
        error = exc.message if isinstance(exc, FleetError) else str(exc)
        return BulkResult(
            instance_id=instance_id,
            ok=False,
            error=error,
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


def multi_deploy(
    paths,
    registry,
    project: str,
    template: str | None = None,
    *,
    branch: str | None = None,
    label: str | None = None,
    count: int = 1,
    force: bool = False,
    auth_enabled: bool = True,
    auth_password: str = DEFAULT_INSTANCE_PASSWORD,
    skip_disk_check: bool = False,
    create_tmux_session: bool = False,
    on_progress: Callable[[dict], None] | None = None,
    runner=run_streamed,
) -> BulkOutcome:
    """Allocate `count` free instance ids for `project`/`template`/`branch`
    (base label = `label`, or the branch slug if omitted — see
    resolve_target) and deploy each one sequentially (spec §5). Validates
    `count`, gates on disk headroom, then holds the fleet-wide
    ALLOCATION_LOCK_ID advisory lock (core/locks.py) for the whole
    allocate-then-deploy loop so a concurrent multi-deploy — or a single
    deploy() doing its own allocation — can't allocate overlapping ids."""
    if not (0 <= count <= 20):
        raise ValidationError(f"--count must be between 0 and 20 (got {count})")
    if count == 0:
        return BulkOutcome(kind="deploy", results=[])

    if auth_enabled:
        validate_instance_credential(auth_password)

    if not skip_disk_check:
        try:
            sysinfo.check_disk_headroom(paths.instances)
        except DiskSpaceError as exc:
            raise DiskSpaceError(f"refusing to deploy {count} instances: {exc.message}") from exc

    resolved = instances_mod.resolve_target(registry, project, template, branch, label)
    resolved_template = resolved.template
    resolved_branch = resolved.branch
    base_label = resolved.label

    def _deploy_op(paths, registry, instance_id: str, *, runner=run_streamed):
        instance_dir = paths.instances / instance_id
        if instance_dir.exists():
            raise FleetError(
                f"{instance_id}: collided since allocation — an instance directory "
                "already exists at this id; refusing to treat it as an update"
            )
        inst_label = instance_id.split("--", 1)[1]
        instances_mod.deploy(
            paths,
            registry,
            project,
            resolved_template,
            branch=resolved_branch,
            label=inst_label,
            # This id was JUST allocated (and re-checked above) under our
            # own hold of ALLOCATION_LOCK_ID, below — deploy() must not try
            # to allocate (and thus re-lock the SAME lock id from the SAME
            # process, which flock() does not treat as reentrant) again.
            # `replace=True` here is not about destroying anything (the
            # existence check above already guarantees there is nothing to
            # destroy) — it is purely how a caller that has already
            # guaranteed a free id tells deploy() to skip allocation.
            replace=True,
            force=force,
            auth_enabled=auth_enabled,
            auth_password=auth_password,
            create_tmux_session=create_tmux_session,
            runner=runner,
        )

    with instance_lock(paths.locks, ALLOCATION_LOCK_ID):
        existing_ids = (
            {p.name for p in paths.instances.iterdir() if p.is_dir()}
            if paths.instances.exists()
            else set()
        )
        labels = naming.allocate_multi_deploy_labels(existing_ids, project, base_label, count)
        instance_ids = [f"{project}--{lbl}" for lbl in labels]
        return run_sequential(
            paths, registry, instance_ids, _deploy_op, kind="deploy", on_progress=on_progress
        )
