"""FLE-31: the scheduler's tick logic (injected clock + fake runner), state and history."""

import asyncio
import logging
import threading
from datetime import datetime, timedelta, timezone
from types import SimpleNamespace

import pytest

from fleet.core.errors import FleetError, RegistryError, ScheduleStateError
from fleet.core.locks import instance_lock
from fleet.core.registry import Schedule
from fleet.core.schedules import (
    CATCHUP_WINDOW,
    JobState,
    Scheduler,
    ScheduleStore,
    StartOutcome,
    build_views,
    iso,
    precheck_instance,
    run_blocking,
)

UTC = timezone.utc
DOMAIN = "ddev3.fleet.pm"


def at(*args) -> datetime:
    return datetime(*args, tzinfo=UTC)


def sched(name="nightly", cron="0 3 * * *", tz="UTC", instance="demo--main", **kw) -> Schedule:
    return Schedule(
        name=name,
        cron=cron,
        timezone=tz,
        action=kw.pop("action", "redeploy"),
        instance=instance,
        servers=kw.pop("servers", ()),
        enabled=kw.pop("enabled", True),
    )


class FakeRunner:
    """Starts nothing; remembers what it was asked and what each job is doing."""

    def __init__(self):
        self.starts: list[str] = []
        self.jobs: dict[str, JobState] = {}
        self.outcomes: dict[str, StartOutcome] = {}
        self.raises: dict[str, Exception] = {}
        self.on_start = None
        self._n = 0

    async def start(self, schedule):
        if self.on_start:
            self.on_start(schedule)
        self.starts.append(schedule.name)
        if schedule.name in self.raises:
            raise self.raises[schedule.name]
        if schedule.name in self.outcomes:
            return self.outcomes[schedule.name]
        self._n += 1
        job_id = f"job{self._n}"
        self.jobs[job_id] = JobState("running")
        return StartOutcome("started", None, job_id)

    def job_state(self, job_id):
        return self.jobs.get(job_id)


class Env:
    def __init__(self, tmp_path, schedules, runner=None):
        self.paths = SimpleNamespace(
            schedules=tmp_path / "schedules",
            instances=tmp_path / "instances",
            locks=tmp_path / "locks",
        )
        self.paths.instances.mkdir()
        self.registry = SimpleNamespace(schedules=list(schedules), domain=DOMAIN)
        self.runner = runner or FakeRunner()
        self.now = at(2026, 10, 18, 2, 0)
        self.store = ScheduleStore(self.paths.schedules)
        self.scheduler = self.new_scheduler()

    def new_scheduler(self):
        """A fresh Scheduler on the same disk state = a daemon restart."""
        return Scheduler(
            load=lambda: (self.paths, self.registry), runner=self.runner, clock=lambda: self.now
        )

    def tick(self, when):
        self.now = when
        asyncio.run(self.scheduler.tick(when))

    def history(self, schedule=None, claimed=False):
        """History lines; the `claimed` trace lines are left out unless asked for."""
        entries = self.store.tail_history(100, schedule)
        return entries if claimed else [h for h in entries if h["result"] != "claimed"]

    def results(self, schedule=None, claimed=False):
        return [h["result"] for h in self.history(schedule, claimed)]


@pytest.fixture
def env(tmp_path):
    return Env(tmp_path, [sched()])


# --- first sight, firing, no double fire -----------------------------------------------


def test_first_sight_records_the_occurrence_without_running(env):
    env.tick(at(2026, 10, 18, 3, 0, 10))  # an occurrence just passed, but we never saw it
    assert env.runner.starts == []
    assert env.history() == []
    state = env.store.load_state()
    assert state["nightly"]["last_fired"] == "2026-10-18T03:00:00Z"


def test_fires_on_the_next_occurrence(env):
    env.tick(at(2026, 10, 18, 2, 59, 40))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    assert env.runner.starts == ["nightly"]
    (entry,) = env.history()
    assert entry["result"] == "started"
    assert entry["trigger"] == "cron"
    assert entry["instance"] == "demo--main"
    assert entry["job_id"] == "job1"
    state = env.store.load_state()["nightly"]
    assert state["last_fired"] == "2026-10-18T03:00:00Z"
    assert state["last_run"]["result"] == "started"
    assert state["last_run"]["job_id"] == "job1"


def test_no_double_fire_on_following_ticks_or_after_a_restart(env):
    env.tick(at(2026, 10, 18, 2, 59, 40))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    env.runner.jobs["job1"] = JobState("succeeded")
    env.tick(at(2026, 10, 18, 3, 0, 40))
    env.scheduler = env.new_scheduler()  # daemon restart
    env.tick(at(2026, 10, 18, 3, 0, 50))
    env.tick(at(2026, 10, 18, 3, 30, 0))
    assert env.runner.starts == ["nightly"]


def test_last_fired_is_persisted_before_the_run_starts(env):
    env.tick(at(2026, 10, 18, 2, 59, 40))
    seen = {}

    def peek(schedule):
        seen["state"] = ScheduleStore(env.paths.schedules).load_state()["nightly"]["last_fired"]

    env.runner.on_start = peek
    env.tick(at(2026, 10, 18, 3, 0, 10))
    assert seen["state"] == "2026-10-18T03:00:00Z"


def test_a_crash_while_starting_cannot_fire_the_occurrence_twice(env):
    env.tick(at(2026, 10, 18, 2, 59, 40))

    class Boom(BaseException):
        pass

    env.runner.raises["nightly"] = Boom()
    with pytest.raises(Boom):
        env.tick(at(2026, 10, 18, 3, 0, 10))
    env.runner.raises.clear()
    env.scheduler = env.new_scheduler()
    env.tick(at(2026, 10, 18, 3, 0, 40))
    assert env.runner.starts == ["nightly"]  # the crashed attempt only


def test_next_day_fires_again(env):
    env.tick(at(2026, 10, 18, 2, 59, 40))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    env.runner.jobs["job1"] = JobState("succeeded")
    env.tick(at(2026, 10, 19, 3, 0, 5))
    assert env.runner.starts == ["nightly", "nightly"]


def test_trigger_is_cron_within_two_ticks_and_catch_up_after(env):
    env.tick(at(2026, 10, 18, 2, 59, 40))
    env.tick(at(2026, 10, 18, 3, 0, 59))
    assert env.history()[-1]["trigger"] == "cron"
    env.runner.jobs["job1"] = JobState("succeeded")
    env.tick(at(2026, 10, 19, 3, 1, 5))
    assert env.history()[-1]["trigger"] == "catch-up"


# --- missed runs / catch-up window -----------------------------------------------------


def test_catch_up_inside_the_window_after_downtime(env):
    env.tick(at(2026, 10, 17, 12, 0))  # first sight yesterday
    env.tick(at(2026, 10, 18, 3, 40))  # the daemon was down at 03:00
    assert env.runner.starts == ["nightly"]
    assert env.history()[-1]["trigger"] == "catch-up"
    assert at(2026, 10, 18, 3, 40) - at(2026, 10, 18, 3, 0) <= CATCHUP_WINDOW


def test_older_than_the_window_is_recorded_as_missed_and_not_run(env):
    env.tick(at(2026, 10, 17, 12, 0))
    env.tick(at(2026, 10, 18, 5, 0))
    assert env.runner.starts == []
    (entry,) = env.history()
    assert entry["result"] == "missed"
    assert entry["job_id"] is None
    assert "2026-10-18T03:00:00Z" in entry["reason"]
    # recorded once, not on every tick
    env.tick(at(2026, 10, 18, 5, 0, 30))
    assert env.results() == ["missed"]
    assert env.store.load_state()["nightly"]["last_fired"] == "2026-10-18T03:00:00Z"


def test_the_window_boundary_is_inclusive(env):
    env.tick(at(2026, 10, 17, 12, 0))
    env.tick(at(2026, 10, 18, 4, 0, 0))
    assert env.runner.starts == ["nightly"]


# --- pause / resume / enabled / servers ------------------------------------------------


def test_paused_schedule_does_not_fire_and_resume_does_not_catch_up(env):
    env.tick(at(2026, 10, 18, 2, 0))
    env.store.set_paused("nightly", True)
    env.tick(at(2026, 10, 18, 3, 0, 10))
    assert env.runner.starts == []
    assert env.history() == []  # a pause is not a "missed" run
    env.store.set_paused("nightly", False)  # resumed without the helper: still no catch-up
    env.tick(at(2026, 10, 18, 3, 0, 40))
    assert env.runner.starts == []
    env.tick(at(2026, 10, 19, 3, 0, 10))
    assert env.runner.starts == ["nightly"]


def test_resume_with_fired_marks_the_occurrences_of_the_pause_as_seen(env):
    env.tick(at(2026, 10, 17, 12, 0))
    env.store.set_paused("nightly", True)
    # the daemon is down while paused; resume computes the latest occurrence itself
    env.store.set_paused("nightly", False, fired=at(2026, 10, 18, 3, 0))
    env.tick(at(2026, 10, 18, 3, 30))
    assert env.runner.starts == []


def test_disabled_schedule_never_fires_and_enabling_does_not_fire_immediately(env):
    env.registry.schedules = [sched(enabled=False)]
    env.tick(at(2026, 10, 18, 2, 0))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    env.tick(at(2026, 10, 19, 3, 0, 10))
    assert env.runner.starts == []
    env.registry.schedules = [sched(enabled=True)]
    env.tick(at(2026, 10, 19, 3, 0, 40))
    assert env.runner.starts == []
    env.tick(at(2026, 10, 20, 3, 0, 5))
    assert env.runner.starts == ["nightly"]


def test_schedule_for_another_server_never_fires(env):
    env.registry.schedules = [sched(servers=("ddev4.fleet.pm",))]
    env.tick(at(2026, 10, 18, 2, 0))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    assert env.runner.starts == []
    env.registry.schedules = [sched(servers=("DDEV3.fleet.pm", "ddev4.fleet.pm"))]
    env.tick(at(2026, 10, 19, 3, 0, 10))
    assert env.runner.starts == ["nightly"]  # domain compare is case-insensitive


# --- one run at a time -----------------------------------------------------------------


def test_previous_run_still_running_is_skipped_and_keeps_last_run(env):
    env.tick(at(2026, 10, 18, 2, 0))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    env.tick(at(2026, 10, 19, 3, 0, 10))  # job1 never finished
    assert env.runner.starts == ["nightly"]
    last = env.history()[-1]
    assert last["result"] == "skipped"
    assert last["reason"] == "previous run still running"
    state = env.store.load_state()["nightly"]
    assert state["last_run"]["result"] == "started"  # still points at the running job
    assert state["last_run"]["job_id"] == "job1"
    assert state["last_fired"] == "2026-10-19T03:00:00Z"


def test_run_now_while_running_is_skipped(env):
    env.tick(at(2026, 10, 18, 2, 0))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    record = asyncio.run(env.scheduler.run_now("nightly"))
    assert record["result"] == "skipped"
    assert record["reason"] == "previous run still running"
    assert env.runner.starts == ["nightly"]


# --- outcomes: missing instance, busy instance, errors ---------------------------------


def test_missing_instance_is_recorded_as_failed_and_other_schedules_still_run(tmp_path):
    runner = FakeRunner()
    runner.outcomes["ghost"] = StartOutcome("failed", "instance demo--ghost not found")
    env = Env(tmp_path, [sched("ghost", instance="demo--ghost"), sched("real")], runner)
    env.tick(at(2026, 10, 18, 2, 0))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    assert sorted(runner.starts) == ["ghost", "real"]
    (ghost,) = env.history("ghost")
    assert ghost["result"] == "failed"
    assert ghost["reason"] == "instance demo--ghost not found"
    assert ghost["job_id"] is None
    assert env.store.load_state()["ghost"]["last_run"]["result"] == "failed"
    assert env.results("real") == ["started"]


def test_busy_instance_is_skipped_never_queued(env):
    env.runner.outcomes["nightly"] = StartOutcome("skipped", "instance busy")
    env.tick(at(2026, 10, 18, 2, 0))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    (entry,) = env.history()
    assert (entry["result"], entry["reason"]) == ("skipped", "instance busy")
    state = env.store.load_state()["nightly"]
    assert state["last_run"]["result"] == "skipped"
    # the next occurrence is attempted normally
    env.runner.outcomes.clear()
    env.tick(at(2026, 10, 19, 3, 0, 10))
    assert env.results() == ["skipped", "started"]


def test_an_exception_in_one_schedule_does_not_stop_the_others(tmp_path):
    runner = FakeRunner()
    runner.raises["a"] = RuntimeError("kaboom")
    env = Env(tmp_path, [sched("a"), sched("b")], runner)
    env.tick(at(2026, 10, 18, 2, 0))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    assert runner.starts == ["a", "b"]
    (a,) = env.history("a")
    assert a["result"] == "failed"
    assert "RuntimeError: kaboom" in a["reason"]
    assert env.results("b") == ["started"]


def test_an_error_inside_the_tick_logic_is_recorded_once_and_isolated(tmp_path, caplog):
    broken = sched("broken", cron="not a cron")  # smuggled past registry validation
    env = Env(tmp_path, [broken, sched("good")])
    with caplog.at_level(logging.ERROR):
        env.tick(at(2026, 10, 18, 2, 0))
        env.tick(at(2026, 10, 18, 2, 0, 30))
        env.tick(at(2026, 10, 18, 3, 0, 10))
    assert env.results("broken") == ["failed"]  # not once per tick
    assert "scheduler error" in env.history("broken")[0]["reason"]
    assert env.results("good") == ["started"]


def test_registry_failure_is_survived(env):
    def boom():
        raise RegistryError("fleet.yml is broken")

    env.scheduler.load = boom
    asyncio.run(env.scheduler.tick(at(2026, 10, 18, 3, 0)))  # does not raise
    assert env.history() == []


def test_run_forever_survives_a_tick_crash(env, monkeypatch):
    calls = []

    async def flaky_tick(now=None):
        calls.append(1)
        if len(calls) == 1:
            raise RuntimeError("tick crashed")
        raise asyncio.CancelledError

    async def no_sleep(_):
        return None

    env.scheduler.tick = flaky_tick
    monkeypatch.setattr("fleet.core.schedules.asyncio.sleep", no_sleep)
    with pytest.raises(asyncio.CancelledError):
        asyncio.run(env.scheduler.run_forever())
    assert len(calls) == 2


# --- completion (reconcile) ------------------------------------------------------------


def _started(env):
    env.tick(at(2026, 10, 18, 2, 0))
    env.tick(at(2026, 10, 18, 3, 0, 10))


def test_a_finished_job_is_recorded_as_succeeded(env):
    _started(env)
    env.runner.jobs["job1"] = JobState("succeeded", "https://x.example")
    env.tick(at(2026, 10, 18, 3, 0, 40))
    assert env.results() == ["started", "succeeded"]
    last = env.store.load_state()["nightly"]["last_run"]
    assert last["result"] == "succeeded"
    assert last["job_id"] == "job1"
    assert last["finished"] == "2026-10-18T03:00:40Z"
    env.tick(at(2026, 10, 18, 3, 1, 10))
    assert env.results() == ["started", "succeeded"]  # recorded once


def test_a_failed_job_carries_the_job_error(env):
    _started(env)
    env.runner.jobs["job1"] = JobState("failed", "clone failed:\n  boom")
    env.tick(at(2026, 10, 18, 3, 0, 40))
    final = env.history()[-1]
    assert (final["result"], final["reason"]) == ("failed", "clone failed: boom")
    assert final["job_id"] == "job1"


def test_a_job_that_lost_the_lock_race_is_skipped_as_busy(env):
    _started(env)
    env.runner.jobs["job1"] = JobState("failed", "lock already held for instance 'demo--main' (x)")
    env.tick(at(2026, 10, 18, 3, 0, 40))
    final = env.history()[-1]
    assert (final["result"], final["reason"]) == ("skipped", "instance busy")


def test_a_job_lost_in_a_restart_is_failed_as_interrupted(env):
    _started(env)
    env.runner.jobs.clear()  # new daemon process: the JobManager is empty
    env.scheduler = env.new_scheduler()
    env.tick(at(2026, 10, 18, 3, 0, 40))
    final = env.history()[-1]
    assert final["result"] == "failed"
    assert "interrupted" in final["reason"]


def test_a_queued_or_running_job_stays_started(env):
    _started(env)
    env.runner.jobs["job1"] = JobState("queued")
    env.tick(at(2026, 10, 18, 3, 5))
    assert env.results() == ["started"]


# --- run now ---------------------------------------------------------------------------


def test_run_now_records_the_manual_trigger_and_works_when_paused_or_disabled(env):
    env.registry.schedules = [sched(enabled=False)]
    env.store.set_paused("nightly", True)
    record = asyncio.run(env.scheduler.run_now("nightly", "manual-ui"))
    assert record["result"] == "started"
    assert record["trigger"] == "manual-ui"
    assert env.history()[-1]["trigger"] == "manual-ui"


def test_run_now_rejects_unknown_and_foreign_schedules(env):
    env.registry.schedules = [sched(servers=("ddev4.fleet.pm",))]
    with pytest.raises(FleetError, match="unknown schedule 'nope'"):
        asyncio.run(env.scheduler.run_now("nope"))
    with pytest.raises(FleetError, match="not on this server"):
        asyncio.run(env.scheduler.run_now("nightly"))
    assert env.runner.starts == []


def test_run_now_does_not_disturb_last_fired(env):
    env.tick(at(2026, 10, 18, 2, 0))
    asyncio.run(env.scheduler.run_now("nightly"))
    env.runner.jobs["job1"] = JobState("succeeded")
    env.tick(at(2026, 10, 18, 3, 0, 10))
    assert env.runner.starts == ["nightly", "nightly"]  # the manual run and the 03:00 one


# --- DST at tick level -----------------------------------------------------------------


def test_nonexistent_local_time_does_not_fire_on_the_gap_day(tmp_path):
    env = Env(tmp_path, [sched(cron="30 2 * * *", tz="Europe/Paris")])
    env.tick(at(2026, 3, 28, 12, 0))
    for when in (at(2026, 3, 29, 0, 30), at(2026, 3, 29, 1, 30), at(2026, 3, 29, 2, 30)):
        env.tick(when)
    assert env.runner.starts == []
    env.tick(at(2026, 3, 30, 0, 30, 10))  # 02:30 CEST the next day
    assert env.runner.starts == ["nightly"]


def test_ambiguous_local_time_fires_once(tmp_path):
    env = Env(tmp_path, [sched(cron="30 2 * * *", tz="Europe/Paris")])
    env.tick(at(2026, 10, 24, 12, 0))
    env.tick(at(2026, 10, 25, 0, 30, 10))
    env.runner.jobs["job1"] = JobState("succeeded")
    env.tick(at(2026, 10, 25, 1, 30, 10))  # 02:30 again, CET
    assert env.runner.starts == ["nightly"]


# --- state file ------------------------------------------------------------------------


def test_corrupt_state_fails_closed_and_is_never_overwritten(env, caplog):
    env.paths.schedules.mkdir(parents=True)
    env.store.state_file.write_text("{ not json", encoding="utf-8")
    with caplog.at_level(logging.WARNING):
        env.tick(at(2026, 10, 18, 3, 0, 10))
        env.tick(at(2026, 10, 18, 3, 0, 40))
        env.tick(at(2026, 10, 18, 3, 1, 10))
    # fired nothing, recorded nothing, and the original is still there, untouched
    assert env.runner.starts == []
    assert env.history(claimed=True) == []
    assert env.store.state_file.read_text(encoding="utf-8") == "{ not json"
    # logged ONCE for the same error, naming the path and the fix
    warnings = [r.message for r in caplog.records if "is corrupt" in r.message]
    assert len(warnings) == 1
    assert str(env.store.state_file) in warnings[0] and "delete the file" in warnings[0]
    # a copy is kept, once (not one more per tick)
    copies = list(env.paths.schedules.glob("state.json.corrupt-*"))
    assert len(copies) == 1 and copies[0].read_text(encoding="utf-8") == "{ not json"


def test_corrupt_state_recovers_by_deleting_the_file(env):
    env.paths.schedules.mkdir(parents=True)
    env.store.state_file.write_text("[1, 2]", encoding="utf-8")
    env.tick(at(2026, 10, 18, 3, 0, 10))
    env.store.state_file.unlink()
    env.tick(at(2026, 10, 18, 3, 0, 40))  # first sight again: re-initialised, nothing fires
    assert env.runner.starts == []
    assert env.store.load_state()["nightly"]["last_fired"] == "2026-10-18T03:00:00Z"


@pytest.mark.parametrize(
    "content",
    ["{ not json", "[1, 2]", "null", '{"nightly": [1]}', '{"nightly": "x"}'],
)
def test_wrong_shape_or_invalid_json_raises_a_typed_error(env, content):
    env.paths.schedules.mkdir(parents=True)
    env.store.state_file.write_text(content, encoding="utf-8")
    with pytest.raises(ScheduleStateError) as exc:
        env.store.load_state()
    assert str(env.store.state_file) in exc.value.message
    assert "Fix:" in exc.value.message
    for write in (
        lambda: env.store.patch("x", {"paused": True}),
        lambda: env.store.set_paused("x", True),
        lambda: env.store.ensure_writable(),
    ):
        with pytest.raises(ScheduleStateError):
            write()
    assert env.store.state_file.read_text(encoding="utf-8") == content  # never overwritten


def test_unreadable_state_fails_closed(env, caplog):
    # a directory where the file should be: unreadable even for root
    env.paths.schedules.mkdir(parents=True)
    env.store.state_file.mkdir()
    with caplog.at_level(logging.WARNING):
        env.tick(at(2026, 10, 18, 3, 0, 10))
        env.tick(at(2026, 10, 18, 3, 0, 40))
    assert env.runner.starts == []
    assert len([r for r in caplog.records if "cannot be read" in r.message]) == 1
    with pytest.raises(ScheduleStateError, match="cannot be read"):
        env.store.load_state()
    assert env.store.state_file.is_dir()


def test_unwritable_lock_file_fails_closed(env, caplog):
    env.paths.schedules.mkdir(parents=True)
    (env.paths.schedules / ".state.lock").mkdir()  # cannot be opened as a file
    with caplog.at_level(logging.WARNING):
        env.tick(at(2026, 10, 18, 3, 0, 10))
        env.tick(at(2026, 10, 18, 3, 0, 40))
    assert env.runner.starts == []
    assert len([r for r in caplog.records if "lock file" in r.message]) == 1
    with pytest.raises(ScheduleStateError, match="lock file"):
        env.store.load_state()


def test_unwritable_state_fails_closed_and_keeps_the_old_file(env, monkeypatch):
    env.store.patch("nightly", {"paused": True})
    before = env.store.state_file.read_text(encoding="utf-8")

    def denied(*_a, **_k):
        raise PermissionError(13, "Permission denied")

    monkeypatch.setattr("fleet.core.schedules.os.replace", denied)
    with pytest.raises(ScheduleStateError, match="cannot be written"):
        env.store.patch("nightly", {"paused": False})
    assert env.store.state_file.read_text(encoding="utf-8") == before
    assert [p.name for p in env.paths.schedules.iterdir() if p.name.endswith(".tmp")] == []


def test_unwritable_state_stops_a_run_before_any_job_is_submitted(env, monkeypatch):
    env.store.patch("nightly", {"paused": False})

    def denied(*_a, **_k):
        raise PermissionError(13, "Permission denied")

    monkeypatch.setattr("fleet.core.schedules.os.replace", denied)
    with pytest.raises(ScheduleStateError):
        asyncio.run(env.scheduler.run_now("nightly"))
    assert env.runner.starts == []


def test_a_state_error_does_not_stop_the_tick_loop_or_repeat_forever(env, caplog):
    env.paths.schedules.mkdir(parents=True)
    env.store.state_file.write_text("garbage", encoding="utf-8")
    with caplog.at_level(logging.WARNING):
        for i in range(5):
            env.tick(at(2026, 10, 18, 3, 0, i))
    assert len([r for r in caplog.records if "is corrupt" in r.message]) == 1
    # and run_forever keeps going: the tick returned normally every time
    assert env.runner.starts == []


def test_state_write_is_atomic_and_leaves_no_temp_files(env):
    env.store.patch("nightly", {"paused": True})
    env.store.patch("nightly", {"last_fired": "2026-10-18T03:00:00Z"})
    assert env.store.load_state() == {
        "nightly": {"paused": True, "last_fired": "2026-10-18T03:00:00Z"}
    }
    assert [p.name for p in env.paths.schedules.iterdir() if p.name.endswith(".tmp")] == []


def test_history_reader_skips_torn_and_foreign_lines(env):
    env.paths.schedules.mkdir(parents=True)
    env.store.history_file.write_text(
        '{"schedule": "a", "result": "started"}\n'
        "{torn\n"
        "[1]\n"
        "\n"
        '{"schedule": "b", "result": "failed"}\n'
        '{"schedule": "a", "result": "succeeded"}\n',
        encoding="utf-8",
    )
    assert [h["result"] for h in env.store.tail_history(10)] == ["started", "failed", "succeeded"]
    assert [h["result"] for h in env.store.tail_history(10, "a")] == ["started", "succeeded"]
    assert [h["result"] for h in env.store.tail_history(2)] == ["failed", "succeeded"]
    assert env.store.tail_history(0) == []


def test_history_of_a_missing_file_is_empty(env):
    assert env.store.tail_history() == []


# --- views -----------------------------------------------------------------------------


def test_views_states_and_next_run(tmp_path):
    runner = FakeRunner()
    env = Env(
        tmp_path,
        [
            sched("act", cron="0 3 * * *", tz="Europe/Paris"),
            sched("pau"),
            sched("dis", enabled=False),
            sched("far", servers=("ddev4.fleet.pm",)),
        ],
        runner,
    )
    env.store.set_paused("pau", True)
    views = {v.schedule.name: v for v in build_views(env.registry, env.store, runner, env.now)}
    assert views["act"].state == "active"
    assert views["act"].next_run == at(2026, 10, 19, 1, 0)  # 03:00 CEST tomorrow
    assert views["act"].next_local == "2026-10-19 03:00 CEST"
    assert views["act"].next_hint == "in 23h 0m"
    assert views["pau"].state == "paused" and views["pau"].next_run is None
    assert views["dis"].state == "disabled in fleet.yml"
    assert views["far"].state == "not on this server" and not views["far"].on_this_server


def test_views_show_a_finished_job_before_the_tick_persists_it(env):
    _started(env)
    env.runner.jobs["job1"] = JobState("failed", "nope")
    (view,) = build_views(env.registry, env.store, env.runner, env.now)
    assert view.last_run["result"] == "failed"
    assert view.last_run["reason"] == "nope"
    assert env.store.load_state()["nightly"]["last_run"]["result"] == "started"  # untouched


# --- shared pre-checks and the blocking runner (CLI) -----------------------------------


def test_precheck_missing_busy_and_free(tmp_path):
    env = Env(tmp_path, [sched()])
    missing = precheck_instance(env.paths, "demo--main")
    assert missing == StartOutcome("failed", "instance demo--main not found")
    (env.paths.instances / "demo--main").mkdir()
    assert precheck_instance(env.paths, "demo--main") is None
    with instance_lock(env.paths.locks, "demo--main"):
        assert precheck_instance(env.paths, "demo--main") == StartOutcome(
            "skipped", "instance busy"
        )
    assert precheck_instance(env.paths, "demo--main") is None  # the probe released its lock


def test_run_blocking_success_failure_and_skips(tmp_path):
    env = Env(tmp_path, [sched()])
    s = env.registry.schedules[0]
    calls = []
    clock = lambda: at(2026, 10, 18, 3, 0)  # noqa: E731

    rec = run_blocking(
        env.store, env.paths, env.registry, s, lambda *a: calls.append(a), clock=clock
    )
    assert (rec["result"], rec["reason"]) == ("failed", "instance demo--main not found")
    assert calls == []

    (env.paths.instances / "demo--main").mkdir()
    rec = run_blocking(
        env.store, env.paths, env.registry, s, lambda *a: calls.append(a), clock=clock
    )
    assert rec["result"] == "succeeded" and rec["trigger"] == "manual-cli"
    assert rec["job_id"] is None and rec["finished"] == "2026-10-18T03:00:00Z"
    assert calls[0][2] == "demo--main"

    def fail(*_):
        raise FleetError("redeploy exploded")

    rec = run_blocking(env.store, env.paths, env.registry, s, fail, clock=clock)
    assert (rec["result"], rec["reason"]) == ("failed", "redeploy exploded")

    def crash(*_):
        raise ValueError("bad")

    rec = run_blocking(env.store, env.paths, env.registry, s, crash, clock=clock)
    assert rec["reason"] == "ValueError: bad"

    with instance_lock(env.paths.locks, "demo--main"):
        rec = run_blocking(env.store, env.paths, env.registry, s, fail, clock=clock)
    assert (rec["result"], rec["reason"]) == ("skipped", "instance busy")

    assert env.results() == ["failed", "succeeded", "failed", "failed", "skipped"]
    assert env.store.load_state()["nightly"]["last_run"]["result"] == "skipped"


def test_run_blocking_records_an_interrupt(tmp_path):
    env = Env(tmp_path, [sched()])
    (env.paths.instances / "demo--main").mkdir()

    def interrupted(*_):
        raise KeyboardInterrupt

    with pytest.raises(KeyboardInterrupt):
        run_blocking(env.store, env.paths, env.registry, env.registry.schedules[0], interrupted)
    assert env.history()[-1]["reason"] == "interrupted"


def test_run_blocking_unsupported_action(tmp_path):
    env = Env(tmp_path, [sched(action="explode")])
    rec = run_blocking(env.store, env.paths, env.registry, env.registry.schedules[0], lambda *a: 1)
    assert rec["result"] == "failed" and "unsupported action" in rec["reason"]


def test_iso_roundtrip():
    assert iso(at(2026, 10, 18, 3, 0, 5)) == "2026-10-18T03:00:05Z"
    assert iso(datetime(2026, 10, 18, 5, 0, tzinfo=timezone(timedelta(hours=2)))) == (
        "2026-10-18T03:00:00Z"
    )


# --- I2: no double start ---------------------------------------------------------------


class SlowRunner(FakeRunner):
    """A runner that really yields while starting (the instance probe runs in a thread)."""

    async def start(self, schedule):
        await asyncio.sleep(0.05)
        return await super().start(schedule)


def test_two_concurrent_run_now_calls_start_exactly_one_job(tmp_path):
    env = Env(tmp_path, [sched()], SlowRunner())

    async def both():
        return await asyncio.gather(
            env.scheduler.run_now("nightly"), env.scheduler.run_now("nightly")
        )

    first, second = asyncio.run(both())
    assert sorted([first["result"], second["result"]]) == ["skipped", "started"]
    skipped = first if first["result"] == "skipped" else second
    started = first if first["result"] == "started" else second
    assert skipped["reason"] == "previous run still running"
    assert env.runner.starts == ["nightly"]
    # last_run of the run in progress is not overwritten by the skipped attempt
    last = env.store.load_state()["nightly"]["last_run"]
    assert last["result"] == "started" and last["job_id"] == started["job_id"]
    assert env.results() == ["skipped", "started"] or env.results() == ["started", "skipped"]


def test_run_now_racing_a_cron_tick_does_not_double_start(tmp_path):
    env = Env(tmp_path, [sched()], SlowRunner())
    env.tick(at(2026, 10, 18, 2, 0))
    env.now = at(2026, 10, 18, 3, 0, 10)

    async def both():
        return await asyncio.gather(env.scheduler.tick(env.now), env.scheduler.run_now("nightly"))

    asyncio.run(both())
    assert env.runner.starts == ["nightly"]
    last = env.store.load_state()["nightly"]["last_run"]
    assert last["result"] == "started"
    assert "skipped" in env.results()


def test_the_starting_guard_is_released_after_a_failure(env):
    env.runner.raises["nightly"] = RuntimeError("boom")
    asyncio.run(env.scheduler.run_now("nightly"))
    env.runner.raises.clear()
    record = asyncio.run(env.scheduler.run_now("nightly"))
    assert record["result"] == "started"
    assert env.scheduler._starting == set()


# --- I3: claim (compare-and-set), then trace -------------------------------------------


def test_the_claim_is_traced_in_the_history_before_the_job_starts(env):
    env.tick(at(2026, 10, 18, 2, 59, 40))
    seen = {}
    env.runner.on_start = lambda s: seen.update(
        results=[h["result"] for h in env.store.tail_history(10)]
    )
    env.tick(at(2026, 10, 18, 3, 0, 10))
    assert seen["results"] == ["claimed"]
    assert env.results(claimed=True) == ["claimed", "started"]
    claimed = env.history(claimed=True)[0]
    assert claimed["schedule"] == "nightly" and claimed["trigger"] == "cron"
    assert claimed["job_id"] is None and "2026-10-18T03:00:00Z" in claimed["reason"]


def test_an_occurrence_lost_to_a_crash_before_the_submit_is_visible(env):
    env.tick(at(2026, 10, 18, 2, 59, 40))

    class Cancelled(BaseException):
        pass

    env.runner.raises["nightly"] = Cancelled()
    with pytest.raises(Cancelled):
        env.tick(at(2026, 10, 18, 3, 0, 10))
    env.runner.raises.clear()
    env.scheduler = env.new_scheduler()
    env.tick(at(2026, 10, 18, 3, 0, 40))
    # not fired twice, and the lost occurrence shows up as a claim without a start
    assert env.runner.starts == ["nightly"]
    assert env.results(claimed=True) == ["claimed"]


def test_the_claim_is_a_compare_and_set_across_scheduler_processes(tmp_path):
    runner_a, runner_b = FakeRunner(), FakeRunner()
    env = Env(tmp_path, [sched()], runner_a)
    other = Scheduler(
        load=lambda: (env.paths, env.registry), runner=runner_b, clock=lambda: env.now
    )
    env.tick(at(2026, 10, 18, 2, 0))
    stale = env.store.load_state()["nightly"]  # both processes took their snapshot here
    now = at(2026, 10, 18, 3, 0, 10)
    schedule = env.registry.schedules[0]
    asyncio.run(env.scheduler._tick_one(env.store, stale, schedule, DOMAIN, now))
    asyncio.run(other._tick_one(env.store, stale, schedule, DOMAIN, now))
    assert runner_a.starts == ["nightly"] and runner_b.starts == []
    assert env.results(claimed=True) == ["claimed", "started"]


def test_claim_occurrence_semantics(env):
    s = env.registry.schedules[0]
    occ = at(2026, 10, 18, 3, 0)
    line = {"schedule": "nightly", "result": "claimed"}
    assert env.store.claim_occurrence(s, occ, line) is False  # unknown schedule: no baseline
    env.store.patch(
        "nightly",
        {"last_fired": iso(occ - timedelta(days=1)), "cron": s.cron, "timezone": s.timezone},
    )
    assert env.store.claim_occurrence(s, occ, line) is True
    assert env.store.claim_occurrence(s, occ, line) is False  # already claimed
    assert env.store.claim_occurrence(s, occ - timedelta(days=1), line) is False  # older
    assert len(env.store.tail_history(10)) == 1  # a refused claim writes nothing
    env.store.patch("nightly", {"cron": "1 1 * * *"})  # recorded for another definition
    assert env.store.claim_occurrence(s, occ + timedelta(days=1), line) is False


def test_a_missed_occurrence_is_claimed_with_its_trace_in_one_transaction(env):
    env.tick(at(2026, 10, 17, 12, 0))
    env.tick(at(2026, 10, 18, 5, 0))
    assert env.results(claimed=True) == ["missed"]
    env.tick(at(2026, 10, 18, 5, 0, 30))
    assert env.results(claimed=True) == ["missed"]


# --- M1: an edited definition is re-baselined, not caught up ---------------------------


def test_editing_the_cron_rebaselines_instead_of_catching_up(env):
    env.tick(at(2026, 10, 18, 2, 0))
    env.tick(at(2026, 10, 18, 3, 0, 10))
    env.runner.jobs["job1"] = JobState("succeeded")
    assert env.runner.starts == ["nightly"]
    # 04:20: the schedule is edited from 03:00 to 04:00 and the new expression's occurrence
    # (04:00) is only 20 minutes old: it must NOT fire as a catch-up
    env.registry.schedules = [sched(cron="0 4 * * *")]
    env.tick(at(2026, 10, 18, 4, 20))
    assert env.runner.starts == ["nightly"]
    state = env.store.load_state()["nightly"]
    assert state["cron"] == "0 4 * * *" and state["last_fired"] == "2026-10-18T04:00:00Z"
    env.tick(at(2026, 10, 19, 4, 0, 10))  # and it fires normally afterwards
    assert env.runner.starts == ["nightly", "nightly"]


def test_editing_the_timezone_rebaselines_too(env):
    env.tick(at(2026, 10, 18, 2, 0))
    env.registry.schedules = [sched(tz="Europe/Paris")]  # 03:00 Paris = 01:00Z, already passed
    env.tick(at(2026, 10, 18, 1, 30))
    assert env.runner.starts == []
    assert env.store.load_state()["nightly"]["timezone"] == "Europe/Paris"


def test_a_resume_records_the_definition_it_was_computed_for(env):
    s = env.registry.schedules[0]
    env.store.set_paused(
        "nightly", False, fired=at(2026, 10, 18, 3, 0), cron=s.cron, timezone=s.timezone
    )
    env.tick(at(2026, 10, 18, 3, 0, 20))
    assert env.runner.starts == []  # same definition, occurrence already seen


def test_an_old_state_without_a_definition_is_rebaselined(env):
    env.store.patch("nightly", {"last_fired": "2026-10-17T03:00:00Z"})  # no cron/timezone
    env.tick(at(2026, 10, 18, 3, 0, 10))
    assert env.runner.starts == []
    assert env.store.load_state()["nightly"]["cron"] == "0 3 * * *"


# --- the state lock --------------------------------------------------------------------


def test_concurrent_patches_do_not_lose_updates(env):
    names = [f"s{i}" for i in range(12)]

    def work(name):
        for n in range(15):
            env.store.patch(name, {"n": n})

    threads = [threading.Thread(target=work, args=(name,)) for name in names]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    state = env.store.load_state()
    assert sorted(state) == sorted(names) and all(v["n"] == 14 for v in state.values())
