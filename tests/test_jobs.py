import asyncio
import time

from fleet.jobs import JobManager


def test_job_manager_end_to_end_and_concurrency_limit():
    async def scenario():
        manager = JobManager(concurrency=2)
        blocker = asyncio.Event()

        def blocking():
            while not blocker.is_set():
                time.sleep(0.01)
            return "released"

        def fast():
            return "ok"

        job_a = await manager.submit("deploy", "demo--a", blocking)
        job_b = await manager.submit("deploy", "demo--b", blocking)
        job_c = await manager.submit("deploy", "demo--c", fast)

        await asyncio.sleep(0.05)
        assert manager.get(job_a.id).state == "running"
        assert manager.get(job_b.id).state == "running"
        assert manager.get(job_c.id).state == "queued"

        blocker.set()
        for _ in range(200):
            if manager.get(job_c.id).state == "succeeded":
                break
            await asyncio.sleep(0.01)

        assert manager.get(job_a.id).state == "succeeded"
        assert manager.get(job_b.id).state == "succeeded"
        assert manager.get(job_c.id).state == "succeeded"
        assert manager.all()[0].id == job_c.id  # newest first

    asyncio.run(scenario())


def test_job_manager_records_failure_detail():
    async def scenario():
        manager = JobManager(concurrency=2)

        def exploding():
            raise ValueError("kaboom")

        job = await manager.submit("deploy", "demo--x", exploding)
        for _ in range(200):
            if manager.get(job.id).state == "failed":
                break
            await asyncio.sleep(0.01)
        assert manager.get(job.id).state == "failed"
        assert "kaboom" in manager.get(job.id).detail

    asyncio.run(scenario())


def test_job_manager_get_returns_none_for_unknown_id():
    manager = JobManager()
    assert manager.get("nonexistent") is None
