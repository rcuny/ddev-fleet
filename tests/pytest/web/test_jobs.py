import asyncio
import time

from fleet.jobs import Job, JobManager


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
        # Wait for ALL three to reach a terminal state. Polling only job_c races:
        # job_c starting means a slot freed (one of a/b finished), but the other
        # can still be transitioning "running"->"succeeded" when we assert.
        for _ in range(200):
            states = [manager.get(j.id).state for j in (job_a, job_b, job_c)]
            if all(s == "succeeded" for s in states):
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


def test_job_manager_caps_terminal_jobs_and_keeps_newest():
    async def scenario():
        manager = JobManager(concurrency=2, max_jobs=3)

        def fast():
            return "ok"

        jobs = []
        for _ in range(5):
            job = await manager.submit("deploy", "demo--x", fast)
            jobs.append(job)
            for _ in range(200):
                if manager.get(job.id).state == "succeeded":
                    break
                await asyncio.sleep(0.01)

        assert len(manager._jobs) <= 3
        assert jobs[-1].id in manager._jobs  # newest survives

    asyncio.run(scenario())


def test_job_manager_prune_never_evicts_non_terminal_oldest():
    async def scenario():
        manager = JobManager(concurrency=2, max_jobs=2)

        # Inject a fake running job as the oldest entry directly, bypassing
        # submit(), so pruning must refuse to touch it.
        stale = Job(id="stale-running", kind="deploy", instance_id="demo--old", state="running")
        manager._jobs[stale.id] = stale
        manager._order.append(stale.id)

        def fast():
            return "ok"

        for _ in range(3):
            job = await manager.submit("deploy", "demo--x", fast)
            for _ in range(200):
                if manager.get(job.id).state == "succeeded":
                    break
                await asyncio.sleep(0.01)

        # Pruning must stop at the non-terminal oldest job — it is never evicted.
        assert "stale-running" in manager._jobs
        assert len(manager._jobs) > 2

    asyncio.run(scenario())


def test_job_instance_ids_defaults_to_none():
    job = Job(id="x", kind="deploy", instance_id="demo--a")
    assert job.instance_ids is None


def test_job_manager_submit_accepts_instance_ids_for_bulk_jobs():
    async def scenario():
        manager = JobManager(concurrency=2)
        job = await manager.submit("bulk-start", "", lambda: "done", instance_ids=["a", "b", "c"])
        assert job.instance_ids == ["a", "b", "c"]
        assert job.instance_id == ""

    asyncio.run(scenario())


def test_job_manager_submit_single_instance_job_leaves_instance_ids_none():
    async def scenario():
        manager = JobManager(concurrency=2)
        job = await manager.submit("deploy", "demo--a", lambda: "ok")
        assert job.instance_ids is None

    asyncio.run(scenario())
