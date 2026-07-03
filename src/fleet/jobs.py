"""In-memory job registry for long-running deploy operations (spec §13).

No database — job state lives only for the daemon process's lifetime.
`.fleet/deploy.log` on disk remains the durable record; this is a UI
convenience layered on top, not a system of record.
"""

import asyncio
import uuid
from dataclasses import dataclass


@dataclass
class Job:
    id: str
    kind: str
    instance_id: str
    state: str = "queued"  # "queued" | "running" | "succeeded" | "failed"
    detail: str | None = None
    log_path: str | None = None


class JobManager:
    def __init__(self, concurrency: int = 2) -> None:
        self._semaphore = asyncio.Semaphore(concurrency)
        self._jobs: dict[str, Job] = {}
        self._order: list[str] = []
        # Strong refs: asyncio.create_task results are weakly held by the
        # loop — without this set a pending job task can be GC'd mid-flight.
        self._tasks: set[asyncio.Task] = set()

    async def submit(self, kind: str, instance_id: str, fn, *, log_path: str | None = None) -> Job:
        job = Job(id=uuid.uuid4().hex[:12], kind=kind, instance_id=instance_id, log_path=log_path)
        self._jobs[job.id] = job
        self._order.append(job.id)
        task = asyncio.create_task(self._run(job, fn))
        self._tasks.add(task)
        task.add_done_callback(self._tasks.discard)
        return job

    async def _run(self, job: Job, fn) -> None:
        async with self._semaphore:
            job.state = "running"
            try:
                result = await asyncio.to_thread(fn)
                job.state = "succeeded"
                if result is not None:
                    job.detail = str(result)
            except Exception as exc:
                job.state = "failed"
                job.detail = str(exc)

    def get(self, job_id: str) -> Job | None:
        return self._jobs.get(job_id)

    def all(self) -> list[Job]:
        return [self._jobs[job_id] for job_id in reversed(self._order)]
