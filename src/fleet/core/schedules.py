"""Scheduled jobs (FLE-31): cron expressions, runtime state/history and the tick logic.

Schedules are DECLARED in `fleet.yml` (`schedules:`, validated and exposed as
`Registry.schedules`); everything that changes at runtime lives under
`<fleet home>/schedules/` (`state.json`: pause flag, last fired occurrence, last run;
`history.jsonl`: one line per run outcome).

This module has four parts:

1. an in-house 5-field cron implementation (no dependency): `parse_cron`,
   `next_occurrence`, `latest_occurrence`, computed in the local wall time of an IANA
   timezone;
2. `ScheduleStore`: atomic state file + tolerant JSONL history;
3. `Scheduler`: the tick logic with an injectable clock and an injectable `Runner`
   (the daemon's runner submits the redeploy to the `JobManager`);
4. `build_views()`: what the web UI and `fleet schedule list` show.

Cron semantics (Vixie cron):

- five fields: minute (0-59), hour (0-23), day-of-month (1-31), month (1-12),
  day-of-week (0-7, 0 and 7 are Sunday);
- syntax: `*`, numbers, `a-b`, `*/n`, `a-b/n`, comma lists; no names, no `@daily` macros;
- when BOTH day-of-month and day-of-week are restricted (the field does not start with
  `*`), a day matches when EITHER matches; otherwise both must match.

DST rules (documented in docs/README-schedules.md):

- a local time that does not exist (spring-forward gap) is skipped: that day has no run;
- an ambiguous local time (fall-back overlap) fires once, at the first instance (fold=0).
"""

import asyncio
import bisect
import fcntl
import json
import logging
import os
import re
import shutil
import tempfile
from collections.abc import Callable, Iterator
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import date, datetime, timedelta, timezone
from functools import lru_cache
from pathlib import Path
from typing import Any, Protocol
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from fleet.core.errors import FleetError, LockHeldError, ScheduleStateError, ValidationError
from fleet.core.locks import instance_lock

logger = logging.getLogger(__name__)

TICK_SECONDS = 30.0
CATCHUP_WINDOW = timedelta(minutes=60)
HISTORY_VIEW_LIMIT = 20

ACTIONS = ("redeploy",)

SCHEDULE_NAME_RE = re.compile(r"^[a-z0-9][a-z0-9-]{0,62}$")

TRIGGERS = ("cron", "manual-ui", "manual-cli", "catch-up")
RESULTS = ("claimed", "started", "succeeded", "failed", "skipped", "missed")

# Search horizon of next/latest occurrence (leap days: `0 0 29 2 *` across 2100).
_HORIZON_YEARS = 8
# Naive local wall-time search starts this much before/after the instant so that a DST
# shift can never hide a candidate; every candidate is re-checked in UTC afterwards.
_DST_MARGIN = timedelta(hours=3)
_MINUTE = timedelta(minutes=1)


class CronError(ValidationError):
    """A cron expression or a timezone name is invalid."""


# --- cron ----------------------------------------------------------------------------

_FIELD_SPECS = (
    ("minute", 0, 59),
    ("hour", 0, 23),
    ("day-of-month", 1, 31),
    ("month", 1, 12),
    ("day-of-week", 0, 7),
)
_DAYS_IN_MONTH = (31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)  # February: leap year


@dataclass(frozen=True)
class CronSpec:
    minutes: tuple[int, ...]
    hours: tuple[int, ...]
    days: tuple[int, ...]
    months: tuple[int, ...]
    weekdays: tuple[int, ...]  # 0-6, Sunday = 0
    dom_star: bool
    dow_star: bool

    def day_matches(self, year: int, month: int, day: int) -> bool:
        dom_ok = day in self.days
        # Python: Monday=0 .. Sunday=6 -> cron: Sunday=0 .. Saturday=6
        dow_ok = ((date(year, month, day).weekday() + 1) % 7) in self.weekdays
        if self.dom_star or self.dow_star:
            return dom_ok and dow_ok
        return dom_ok or dow_ok


def _int(text: str, field: str, expr: str) -> int:
    if not (text.isascii() and text.isdigit()):
        raise CronError(f"cron {expr!r}: {field} field: {text!r} is not a number")
    return int(text)


def _parse_field(text: str, field: str, lo: int, hi: int, expr: str) -> list[int]:
    if not text:
        raise CronError(f"cron {expr!r}: empty {field} field")
    values: set[int] = set()
    for part in text.split(","):
        if not part:
            raise CronError(f"cron {expr!r}: {field} field has an empty list item")
        rng, slash, step_text = part.partition("/")
        step = 1
        if slash:
            step = _int(step_text, field, expr)
            if step < 1:
                raise CronError(f"cron {expr!r}: {field} field: step must be at least 1")
        if rng == "*":
            start, end = lo, (6 if field == "day-of-week" else hi)
        elif "-" in rng:
            a, _, b = rng.partition("-")
            start, end = _int(a, field, expr), _int(b, field, expr)
        else:
            if slash:
                raise CronError(
                    f"cron {expr!r}: {field} field: a step needs '*' or a range ({part!r})"
                )
            start = end = _int(rng, field, expr)
        if not (lo <= start <= hi and lo <= end <= hi):
            raise CronError(f"cron {expr!r}: {field} field: {part!r} is outside {lo}-{hi}")
        if start > end:
            raise CronError(f"cron {expr!r}: {field} field: range {part!r} is backwards")
        values.update(range(start, end + 1, step))
    if field == "day-of-week":
        values = {v % 7 for v in values}
    return sorted(values)


def parse_cron(expr: str) -> CronSpec:
    """Parse a 5-field cron expression. Raises `CronError` (a `ValidationError`)."""
    if not isinstance(expr, str):
        raise CronError(f"cron expression must be a string, got {expr!r}")
    return _parse_cron(str(expr))


@lru_cache(maxsize=256)
def _parse_cron(expr: str) -> CronSpec:
    fields = expr.split()
    if len(fields) != 5:
        raise CronError(
            f"cron {expr!r}: expected 5 fields (minute hour day-of-month month day-of-week), "
            f"got {len(fields)}"
        )
    parsed = [
        _parse_field(text, name, lo, hi, expr)
        for text, (name, lo, hi) in zip(fields, _FIELD_SPECS, strict=True)
    ]
    spec = CronSpec(
        minutes=tuple(parsed[0]),
        hours=tuple(parsed[1]),
        days=tuple(parsed[2]),
        months=tuple(parsed[3]),
        weekdays=tuple(parsed[4]),
        dom_star=fields[2].startswith("*"),
        dow_star=fields[4].startswith("*"),
    )
    # With day-of-week unrestricted the day-of-month must exist in a wanted month
    # (`0 0 31 2 *` would never fire); the OR form always has the weekday to fall back on.
    if spec.dow_star and not spec.dom_star:
        if not any(d <= _DAYS_IN_MONTH[m - 1] for m in spec.months for d in spec.days):
            raise CronError(f"cron {expr!r}: no such day in the selected months, it never fires")
    return spec


_TZ_NAME_RE = re.compile(r"^[A-Za-z0-9_+./-]+$")


def parse_timezone(name: str) -> ZoneInfo:
    """An IANA timezone by name. Raises `CronError`."""
    if not isinstance(name, str) or not _TZ_NAME_RE.fullmatch(name):
        raise CronError(f"timezone {name!r} is not an IANA timezone name (e.g. Europe/Paris)")
    try:
        return ZoneInfo(name)
    except (ZoneInfoNotFoundError, ValueError, OSError) as exc:
        raise CronError(f"unknown timezone {name!r} (IANA name, e.g. Europe/Paris)") from exc


def _zone(tz: "str | ZoneInfo") -> ZoneInfo:
    return tz if isinstance(tz, ZoneInfo) else parse_timezone(tz)


def _utc(value: datetime) -> datetime:
    if value.tzinfo is None:
        raise ValueError("an aware datetime is required")
    return value.astimezone(timezone.utc)


def _next_naive(spec: CronSpec, t: datetime, limit_year: int) -> datetime | None:
    """Smallest naive minute-aligned datetime >= t matching `spec` (skips by field)."""
    while t.year <= limit_year:
        if t.month not in spec.months:
            t = datetime(t.year + 1, 1, 1) if t.month == 12 else datetime(t.year, t.month + 1, 1)
            continue
        if not spec.day_matches(t.year, t.month, t.day):
            t = datetime(t.year, t.month, t.day) + timedelta(days=1)
            continue
        if t.hour not in spec.hours:
            i = bisect.bisect_left(spec.hours, t.hour)
            if i == len(spec.hours):
                t = datetime(t.year, t.month, t.day) + timedelta(days=1)
            else:
                t = t.replace(hour=spec.hours[i], minute=0)
            continue
        i = bisect.bisect_left(spec.minutes, t.minute)
        if i == len(spec.minutes):
            t = t.replace(minute=0) + timedelta(hours=1)
            continue
        return t.replace(minute=spec.minutes[i])
    return None


def _prev_naive(spec: CronSpec, t: datetime, low_year: int) -> datetime | None:
    """Largest naive minute-aligned datetime <= t matching `spec` (skips by field)."""
    while t.year >= low_year:
        if t.month not in spec.months:
            t = datetime(t.year, t.month, 1) - _MINUTE
            continue
        if not spec.day_matches(t.year, t.month, t.day):
            t = datetime(t.year, t.month, t.day) - _MINUTE
            continue
        if t.hour not in spec.hours:
            i = bisect.bisect_right(spec.hours, t.hour) - 1
            if i < 0:
                t = datetime(t.year, t.month, t.day) - _MINUTE
            else:
                t = t.replace(hour=spec.hours[i], minute=59)
            continue
        i = bisect.bisect_right(spec.minutes, t.minute) - 1
        if i < 0:
            t = t.replace(minute=0) - _MINUTE
            continue
        return t.replace(minute=spec.minutes[i])
    return None


def _to_utc(naive: datetime, zone: ZoneInfo) -> datetime | None:
    """The UTC instant of a local wall time (fold=0 when ambiguous), None when the time
    does not exist (spring-forward gap)."""
    utc = naive.replace(tzinfo=zone).astimezone(timezone.utc)
    if utc.astimezone(zone).replace(tzinfo=None) != naive:
        return None
    return utc


def next_occurrence(expr: str, tz: "str | ZoneInfo", after: datetime) -> datetime | None:
    """The first occurrence strictly after `after`, as an aware UTC datetime.

    Computed in the local wall time of `tz`. None only when nothing fires within the
    8-year horizon (cannot happen for a validated expression)."""
    spec, zone, after = parse_cron(expr), _zone(tz), _utc(after)
    local = after.astimezone(zone).replace(tzinfo=None, second=0, microsecond=0)
    t = local - _DST_MARGIN
    limit_year = min(local.year + _HORIZON_YEARS, 9998)
    while True:
        cand = _next_naive(spec, t, limit_year)
        if cand is None:
            return None
        utc = _to_utc(cand, zone)
        if utc is not None and utc > after:
            return utc
        t = cand + _MINUTE


def latest_occurrence(expr: str, tz: "str | ZoneInfo", at_or_before: datetime) -> datetime | None:
    """The last occurrence at or before `at_or_before`, as an aware UTC datetime (None when
    there is none within the 8-year horizon)."""
    spec, zone, at = parse_cron(expr), _zone(tz), _utc(at_or_before)
    local = at.astimezone(zone).replace(tzinfo=None, second=0, microsecond=0)
    t = local + _DST_MARGIN
    low_year = max(local.year - _HORIZON_YEARS, 2)
    while True:
        cand = _prev_naive(spec, t, low_year)
        if cand is None:
            return None
        utc = _to_utc(cand, zone)
        if utc is not None and utc <= at:
            return utc
        t = cand - _MINUTE


# --- time helpers --------------------------------------------------------------------


def iso(value: datetime) -> str:
    """UTC ISO-8601 with a `Z`, second resolution."""
    return _utc(value).strftime("%Y-%m-%dT%H:%M:%SZ")


def parse_iso(value: object) -> datetime | None:
    """Inverse of `iso()`; None for anything that is not an ISO timestamp."""
    if not isinstance(value, str):
        return None
    try:
        parsed = datetime.fromisoformat(value)
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


def relative(delta: timedelta) -> str:
    """`in 5h 12m` / `3m ago`, coarse on purpose."""
    seconds = int(delta.total_seconds())
    future = seconds >= 0
    seconds = abs(seconds)
    days, rest = divmod(seconds, 86400)
    hours, rest = divmod(rest, 3600)
    minutes = rest // 60
    if days:
        text = f"{days}d {hours}h"
    elif hours:
        text = f"{hours}h {minutes}m"
    elif minutes:
        text = f"{minutes}m"
    else:
        text = "<1m"
    return f"in {text}" if future else f"{text} ago"


def applies_here(schedule: Any, domain: str) -> bool:
    """Whether a schedule runs on the server whose fleet domain is `domain` (an absent or
    empty `servers` means every server)."""
    servers = tuple(schedule.servers or ())
    return not servers or domain.lower() in {s.lower() for s in servers}


# --- state + history -----------------------------------------------------------------


class ScheduleStore:
    """`<fleet home>/schedules/state.json` (atomic) + `history.jsonl` (append-only).

    Every read-modify-write of the state takes an flock on `.state.lock`, so the daemon's
    ticks and `fleet schedule pause/resume/run` never lose each other's update.

    The store FAILS CLOSED: a state file that cannot be read, is corrupt or has the wrong
    shape, or a lock/state file that cannot be written, raises `ScheduleStateError`. It is
    never treated as empty and never overwritten (pause flags and `last_fired` would be
    lost), so nothing fires until an operator fixes or deletes the file."""

    def __init__(self, root: Path) -> None:
        self.root = Path(root)
        self.state_file = self.root / "state.json"
        self.history_file = self.root / "history.jsonl"
        self._lock_file = self.root / ".state.lock"

    def _error(self, what: str, exc: object, *, fix: str | None = None) -> ScheduleStateError:
        fix = fix or (
            f"make {self.root} and its files readable and writable by the user the fleet "
            "daemon runs as (chown), or delete the file: every schedule is then treated as "
            "first sight (nothing fires because of it, but pause flags are lost)"
        )
        return ScheduleStateError(f"schedule state {self.state_file}: {what}: {exc}. Fix: {fix}")

    @contextmanager
    def _locked(self) -> Iterator[None]:
        try:
            self.root.mkdir(parents=True, exist_ok=True)
            fh = open(self._lock_file, "a")
        except OSError as exc:
            raise self._error("cannot open the lock file", exc) from exc
        with fh:
            try:
                fcntl.flock(fh.fileno(), fcntl.LOCK_EX)
            except OSError as exc:
                raise self._error("cannot lock", exc) from exc
            try:
                yield
            finally:
                fcntl.flock(fh.fileno(), fcntl.LOCK_UN)

    def _keep_corrupt_copy(self, raw: str) -> Path | None:
        """Copy the corrupt file aside once (an identical copy is not repeated)."""
        for existing in self.root.glob(f"{self.state_file.name}.corrupt-*"):
            try:
                if existing.read_text(encoding="utf-8", errors="replace") == raw:
                    return existing
            except OSError:
                continue
        stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        copy = self.state_file.with_name(f"{self.state_file.name}.corrupt-{stamp}")
        try:
            shutil.copy2(self.state_file, copy)
        except OSError as exc:
            logger.warning("schedules: cannot keep a copy of the corrupt state file: %s", exc)
            return None
        return copy

    def _read_state(self) -> dict[str, dict]:
        try:
            raw = self.state_file.read_text(encoding="utf-8")
        except FileNotFoundError:
            return {}
        except (OSError, UnicodeDecodeError) as exc:
            raise self._error("cannot be read", exc) from exc
        try:
            data = json.loads(raw)
            if not isinstance(data, dict):
                raise ValueError("the top level is not a JSON object")
            for key, value in data.items():
                if not isinstance(value, dict):
                    raise ValueError(f"the entry {key!r} is not a JSON object")
        except (ValueError, RecursionError) as exc:
            copy = self._keep_corrupt_copy(raw)
            where = f" (a copy is kept at {copy})" if copy else ""
            raise self._error(f"is corrupt{where}", exc) from exc
        return {str(k): v for k, v in data.items()}

    def _write_state(self, state: dict[str, dict]) -> None:
        tmp_name = None
        try:
            fd, tmp_name = tempfile.mkstemp(dir=self.root, prefix=".state.", suffix=".tmp")
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                json.dump(state, fh, indent=2, sort_keys=True)
                fh.write("\n")
                fh.flush()
                os.fsync(fh.fileno())
            os.replace(tmp_name, self.state_file)
        except OSError as exc:
            if tmp_name:
                try:
                    os.unlink(tmp_name)
                except OSError:
                    pass
            raise self._error("cannot be written", exc) from exc
        except BaseException:
            if tmp_name:
                try:
                    os.unlink(tmp_name)
                except OSError:
                    pass
            raise

    def load_state(self) -> dict[str, dict]:
        with self._locked():
            return self._read_state()

    def ensure_writable(self) -> None:
        """Raise `ScheduleStateError` unless the state can be read AND rewritten. Called
        before a run is started so that a job is never submitted that cannot be recorded."""
        with self._locked():
            self._write_state(self._read_state())

    def patch(self, name: str, fields: dict[str, Any]) -> dict:
        """Merge `fields` into the state entry `name` (created when absent)."""
        with self._locked():
            state = self._read_state()
            entry = {**state.get(name, {}), **fields}
            state[name] = entry
            self._write_state(state)
            return entry

    def claim_occurrence(
        self,
        schedule: Any,
        occurrence: datetime,
        history_entry: dict[str, Any],
    ) -> bool:
        """Atomically claim `occurrence` for `schedule` (compare-and-set).

        Re-reads the state under the lock and claims only when the stored `last_fired` is
        still older than `occurrence` and was recorded for the same cron expression and
        timezone. In the same locked transaction `history_entry` is appended, so an
        occurrence lost to a crash before the job is submitted is visible. Returns False
        (and writes nothing) when somebody else already claimed it."""
        with self._locked():
            state = self._read_state()
            entry = state.get(schedule.name, {})
            last = parse_iso(entry.get("last_fired"))
            if (
                last is None
                or last >= occurrence
                or entry.get("cron") != schedule.cron
                or entry.get("timezone") != schedule.timezone
            ):
                return False
            # Trace first, then claim: if the claim cannot be written the occurrence is simply
            # claimed again on the next tick; the reverse order could lose it without a trace.
            self.append_history(history_entry)
            state[schedule.name] = {**entry, "last_fired": iso(occurrence)}
            self._write_state(state)
            return True

    def set_paused(
        self,
        name: str,
        paused: bool,
        *,
        fired: datetime | None = None,
        cron: str | None = None,
        timezone: str | None = None,
    ) -> dict:
        """Pause or resume. `fired` (on resume) is the latest occurrence at that moment, so
        occurrences that fell during the pause are never caught up; `cron` and `timezone`
        are the definition it was computed for."""
        fields: dict[str, Any] = {"paused": paused}
        if fired is not None:
            fields["last_fired"] = iso(fired)
            fields["cron"] = cron
            fields["timezone"] = timezone
        return self.patch(name, fields)

    def append_history(self, entry: dict[str, Any]) -> None:
        line = json.dumps(entry, sort_keys=False) + "\n"
        try:
            self.root.mkdir(parents=True, exist_ok=True)
            fd = os.open(self.history_file, os.O_WRONLY | os.O_APPEND | os.O_CREAT, 0o644)
            try:
                os.write(fd, line.encode("utf-8"))
            finally:
                os.close(fd)
        except OSError as exc:
            raise ScheduleStateError(
                f"schedule history {self.history_file} cannot be written: {exc}. Fix: make "
                f"{self.root} and its files writable by the user the fleet daemon runs as"
            ) from exc

    def tail_history(self, n: int = HISTORY_VIEW_LIMIT, schedule: str | None = None) -> list[dict]:
        """The last `n` history entries, OLDEST first; torn or non-object lines are skipped."""
        try:
            lines = self.history_file.read_text(encoding="utf-8", errors="replace").splitlines()
        except FileNotFoundError:
            return []
        except OSError as exc:
            logger.warning("schedules: cannot read %s: %s", self.history_file, exc)
            return []
        entries: list[dict] = []
        for line in lines:
            try:
                entry = json.loads(line)
            except (ValueError, RecursionError):
                continue
            if not isinstance(entry, dict):
                continue
            if schedule is not None and entry.get("schedule") != schedule:
                continue
            entries.append(entry)
        return entries[-n:] if n > 0 else []


# --- running -------------------------------------------------------------------------


@dataclass(frozen=True)
class StartOutcome:
    """What starting a run produced: `started` (a job exists), `skipped` or `failed`
    (no job). `job_id` is None when no job was created."""

    result: str
    reason: str | None = None
    job_id: str | None = None


@dataclass(frozen=True)
class JobState:
    state: str  # queued | running | succeeded | failed
    detail: str | None = None


class Runner(Protocol):
    async def start(self, schedule: Any) -> StartOutcome: ...

    def job_state(self, job_id: str) -> JobState | None: ...


def precheck_instance(paths: Any, instance_id: str) -> StartOutcome | None:
    """Shared by the daemon and the CLI: the instance must exist on this server and its
    lock must be free. Returns the outcome that ends the run, or None to go ahead.

    The lock is only PROBED (taken and released at once), never queued behind: a busy
    instance means the run is skipped and recorded."""
    if not (paths.instances / instance_id).is_dir():
        return StartOutcome("failed", f"instance {instance_id} not found")
    try:
        with instance_lock(paths.locks, instance_id):
            pass
    except LockHeldError:
        return StartOutcome("skipped", "instance busy")
    return None


def _short(text: str | None, limit: int = 300) -> str | None:
    if text is None:
        return None
    text = " ".join(str(text).split())
    return text if len(text) <= limit else text[: limit - 1] + "…"


def record_outcome(
    store: ScheduleStore,
    schedule: Any,
    trigger: str,
    now: datetime,
    outcome: StartOutcome,
    *,
    finished: bool = False,
    update_last_run: bool = True,
) -> dict:
    """Write one run outcome to the history and (by default) to the state's `last_run`."""
    reason = _short(outcome.reason)
    record: dict[str, Any] = {
        "ts": iso(now),
        "trigger": trigger,
        "instance": schedule.instance,
        "result": outcome.result,
        "reason": reason,
        "job_id": outcome.job_id,
    }
    if finished:
        record["finished"] = iso(now)
    if update_last_run:
        store.patch(schedule.name, {"last_run": record})
    store.append_history({"schedule": schedule.name, **record})
    return record


def run_blocking(
    store: ScheduleStore,
    paths: Any,
    registry: Any,
    schedule: Any,
    redeploy: Callable[[Any, Any, str], Any],
    *,
    trigger: str = "manual-cli",
    clock: Callable[[], datetime] = utcnow,
) -> dict:
    """Run a schedule's action in this process (`fleet schedule run`): the same checks as
    the daemon (instance exists, lock free), the redeploy streams to the terminal and the
    final outcome is recorded. No job exists, so `job_id` is None.

    Fails closed: with an unreadable, corrupt or unwritable state it raises
    `ScheduleStateError` before anything runs."""
    store.ensure_writable()
    if schedule.action != "redeploy":
        outcome = StartOutcome("failed", f"unsupported action {schedule.action!r}")
        return record_outcome(store, schedule, trigger, clock(), outcome, finished=True)
    pre = precheck_instance(paths, schedule.instance)
    if pre is not None:
        return record_outcome(store, schedule, trigger, clock(), pre, finished=True)
    try:
        redeploy(paths, registry, schedule.instance)
    except KeyboardInterrupt:
        record_outcome(
            store,
            schedule,
            trigger,
            clock(),
            StartOutcome("failed", "interrupted"),
            finished=True,
        )
        raise
    except FleetError as exc:
        outcome = StartOutcome("failed", exc.message)
    except Exception as exc:  # noqa: BLE001 - recorded, then reported by the caller
        outcome = StartOutcome("failed", f"{type(exc).__name__}: {exc}")
    else:
        outcome = StartOutcome("succeeded")
    return record_outcome(store, schedule, trigger, clock(), outcome, finished=True)


class Scheduler:
    """The tick logic (R6). `load()` returns `(paths, registry)` afresh each tick (the
    registry is re-read like every request does); `runner` starts a run; `clock` is
    injectable for tests."""

    def __init__(
        self,
        *,
        load: Callable[[], tuple[Any, Any]],
        runner: Runner,
        clock: Callable[[], datetime] = utcnow,
    ) -> None:
        self.load = load
        self.runner = runner
        self.clock = clock
        self._last_error: dict[str, str] = {}
        # Schedules whose run is being started right now (in-process guard, I2): marked
        # before the first await and held until the job is submitted AND recorded.
        self._starting: set[str] = set()

    # -- supervision -----------------------------------------------------------------

    async def run_forever(self) -> None:
        """Tick every `TICK_SECONDS`, forever. Nothing but cancellation ends it."""
        while True:
            try:
                await self.tick()
            except asyncio.CancelledError:
                raise
            except Exception:  # noqa: BLE001 - the scheduler task must never die
                logger.exception("schedules: tick failed")
            await asyncio.sleep(TICK_SECONDS)

    # -- reconcile -------------------------------------------------------------------

    def reconcile(self, store: ScheduleStore, now: datetime | None = None) -> None:
        """Finish the runs recorded as `started`: look at their job and record
        `succeeded`/`failed` (a job the daemon no longer knows died with a restart)."""
        now = now or self.clock()
        for name, entry in store.load_state().items():
            last = entry.get("last_run")
            if not isinstance(last, dict) or last.get("result") != "started":
                continue
            job_id = last.get("job_id")
            job = self.runner.job_state(job_id) if isinstance(job_id, str) else None
            if job is None:
                result, reason = "failed", "interrupted: the job was lost (daemon restarted?)"
            elif job.state in ("queued", "running"):
                continue
            elif job.state == "succeeded":
                result, reason = "succeeded", None
            elif (job.detail or "").startswith("lock already held for instance"):
                result, reason = "skipped", "instance busy"
            else:
                result, reason = "failed", _short(job.detail) or "job failed"
            finished = {**last, "result": result, "reason": reason, "finished": iso(now)}
            store.patch(name, {"last_run": finished})
            store.append_history(
                {
                    "schedule": name,
                    "ts": iso(now),
                    "trigger": last.get("trigger"),
                    "instance": last.get("instance"),
                    "result": result,
                    "reason": reason,
                    "job_id": job_id,
                }
            )

    # -- dispatch --------------------------------------------------------------------

    async def _dispatch(
        self, store: ScheduleStore, schedule: Any, trigger: str, now: datetime
    ) -> dict:
        # No await between this check and the add: two concurrent callers (a double click, a
        # click coinciding with a cron tick) can never both get past it.
        if schedule.name in self._starting:
            return self._skip_running(store, schedule, trigger, now, None)
        self._starting.add(schedule.name)
        try:
            store.ensure_writable()  # fail closed BEFORE a job is submitted
            self.reconcile(store, now)
            last = store.load_state().get(schedule.name, {}).get("last_run")
            if isinstance(last, dict) and last.get("result") == "started":
                # reconcile() left it alone, so its job is still queued or running.
                return self._skip_running(store, schedule, trigger, now, last.get("job_id"))
            try:
                outcome = await self.runner.start(schedule)
            except Exception as exc:  # noqa: BLE001 - one failing run must not stop the scheduler
                logger.exception("schedules: starting %s failed", schedule.name)
                outcome = StartOutcome("failed", f"{type(exc).__name__}: {exc}")
            finished = outcome.result != "started"
            return record_outcome(store, schedule, trigger, now, outcome, finished=finished)
        finally:
            self._starting.discard(schedule.name)

    @staticmethod
    def _skip_running(
        store: ScheduleStore, schedule: Any, trigger: str, now: datetime, job_id: str | None
    ) -> dict:
        """`skipped: previous run still running`. History only: `last_run` must keep pointing
        at the run in progress."""
        outcome = StartOutcome("skipped", "previous run still running", job_id)
        return record_outcome(store, schedule, trigger, now, outcome, update_last_run=False)

    async def run_now(self, name: str, trigger: str = "manual-ui") -> dict:
        """Run a schedule right now (UI button). Works for paused or disabled schedules (an
        explicit operator action) but not for one that is not on this server. Returns the
        recorded run (`result`, `reason`, `job_id`, ...)."""
        paths, registry = self.load()
        schedule = next((s for s in registry.schedules if s.name == name), None)
        if schedule is None:
            raise FleetError(f"unknown schedule {name!r}")
        if not applies_here(schedule, registry.domain):
            raise FleetError(f"schedule {name!r} is not on this server")
        return await self._dispatch(ScheduleStore(paths.schedules), schedule, trigger, self.clock())

    # -- tick ------------------------------------------------------------------------

    async def tick(self, now: datetime | None = None) -> None:
        now = now or self.clock()
        try:
            paths, registry = self.load()
        except FleetError as exc:
            self._warn_once("<registry>", f"registry not loadable: {exc.message}")
            return
        self._last_error.pop("<registry>", None)
        store = ScheduleStore(paths.schedules)
        try:
            try:
                self.reconcile(store, now)
            except ScheduleStateError:
                raise
            except Exception:  # noqa: BLE001
                logger.exception("schedules: reconcile failed")
            schedules = registry.schedules
            if not schedules:
                self._last_error.pop("<state>", None)
                return
            state = store.load_state()
        except ScheduleStateError as exc:
            # Fail closed: fire nothing, log once per distinct error, keep ticking.
            self._warn_once("<state>", exc.message)
            return
        self._last_error.pop("<state>", None)
        for schedule in schedules:
            try:
                await self._tick_one(
                    store, state.get(schedule.name, {}), schedule, registry.domain, now
                )
                self._last_error.pop(schedule.name, None)
            except ScheduleStateError as exc:
                self._warn_once("<state>", exc.message)
                return
            except Exception as exc:  # noqa: BLE001 - per schedule, never fatal
                self._report_error(store, schedule, exc, now)

    async def _tick_one(
        self, store: ScheduleStore, entry: dict, schedule: Any, domain: str, now: datetime
    ) -> None:
        occ = latest_occurrence(schedule.cron, schedule.timezone, now)
        if occ is None:
            return
        last_fired = parse_iso(entry.get("last_fired"))
        # `last_fired` is only meaningful for the cron expression and timezone it was
        # recorded for: after an edit, re-baseline instead of catching up the new expression.
        same_definition = (
            entry.get("cron") == schedule.cron and entry.get("timezone") == schedule.timezone
        )
        active = applies_here(schedule, domain) and schedule.enabled and not entry.get("paused")
        if last_fired is None or not same_definition or not active:
            # First sight, edited definition, or not active: remember the occurrence without
            # running it, so adding/editing/enabling/resuming never fires or catches up anything.
            if last_fired is None or not same_definition or occ > last_fired:
                store.patch(
                    schedule.name,
                    {"last_fired": iso(occ), "cron": schedule.cron, "timezone": schedule.timezone},
                )
            return
        if occ <= last_fired:
            return
        age = now - occ
        in_window = age <= CATCHUP_WINDOW
        trigger = "catch-up" if age > 2 * timedelta(seconds=TICK_SECONDS) else "cron"
        base = {
            "schedule": schedule.name,
            "ts": iso(now),
            "trigger": trigger,
            "instance": schedule.instance,
            "job_id": None,
        }
        if in_window:
            trace = {**base, "result": "claimed", "reason": f"occurrence {iso(occ)}"}
        else:
            trace = {
                **base,
                "trigger": "catch-up",
                "result": "missed",
                "reason": (
                    f"occurrence {iso(occ)} passed while the scheduler was not running "
                    f"(older than the {int(CATCHUP_WINDOW.total_seconds() // 60)} min "
                    "catch-up window)"
                ),
            }
        # Claim FIRST, as a compare-and-set under the state lock, with the history trace in
        # the same transaction: a crash or restart from here on can never fire this
        # occurrence twice, a second scheduler process cannot claim it too, and an
        # occurrence lost between the claim and the job submit is visible in the history.
        if not store.claim_occurrence(schedule, occ, trace):
            return
        if in_window:
            await self._dispatch(store, schedule, trigger, now)

    def _warn_once(self, key: str, message: str) -> None:
        if self._last_error.get(key) != message:
            self._last_error[key] = message
            logger.warning("schedules: %s", message)

    def _report_error(
        self, store: ScheduleStore, schedule: Any, exc: Exception, now: datetime
    ) -> None:
        message = f"scheduler error: {type(exc).__name__}: {exc}"
        logger.exception("schedules: %s failed", schedule.name)
        if self._last_error.get(schedule.name) == message:
            return  # the same error every tick: record it once
        self._last_error[schedule.name] = message
        try:
            outcome = StartOutcome("failed", message)
            record_outcome(store, schedule, "cron", now, outcome, finished=True)
        except Exception:  # noqa: BLE001
            logger.exception("schedules: cannot record the error of %s", schedule.name)


# --- views (UI + CLI) ----------------------------------------------------------------

_UNSET = object()
STATE_ACTIVE = "active"
STATE_PAUSED = "paused"
STATE_DISABLED = "disabled in fleet.yml"
STATE_NOT_HERE = "not on this server"
STATE_UNKNOWN = "state unreadable"


@dataclass(frozen=True)
class ScheduleView:
    schedule: Any
    state: str
    next_run: datetime | None
    next_local: str
    next_hint: str
    last_run: dict | None
    on_this_server: bool
    paused: bool

    @property
    def state_slug(self) -> str:
        """A CSS-class friendly spelling of `state`."""
        return {
            STATE_ACTIVE: "active",
            STATE_PAUSED: "paused",
            STATE_DISABLED: "disabled",
            STATE_NOT_HERE: "elsewhere",
            STATE_UNKNOWN: "unknown",
        }[self.state]

    @property
    def actionable(self) -> bool:
        """Whether Run now / Pause / Resume are offered (not for another server's schedule,
        nor while the state is unreadable)."""
        return self.on_this_server and self.state != STATE_UNKNOWN


def schedule_state(schedule: Any, entry: dict, domain: str) -> str:
    if not applies_here(schedule, domain):
        return STATE_NOT_HERE
    if not schedule.enabled:
        return STATE_DISABLED
    if entry.get("paused"):
        return STATE_PAUSED
    return STATE_ACTIVE


def build_views(
    registry: Any,
    store: ScheduleStore,
    runner: Runner | None,
    now: datetime,
    state: "dict[str, dict] | None | object" = _UNSET,
) -> list[ScheduleView]:
    """One view per declared schedule. A `started` last run is shown live: when `runner` can
    tell its job has ended, the view carries the final result (the tick persists it).

    `state` is read from the store when omitted (raising `ScheduleStateError`); pass `None`
    to say "the state is unreadable": the definitions are still listed, with the state
    `STATE_UNKNOWN` for every schedule that runs here and is enabled."""
    if state is _UNSET:
        state = store.load_state()
    state_ok = state is not None
    state = state or {}
    views: list[ScheduleView] = []
    for schedule in registry.schedules:
        entry = state.get(schedule.name, {})
        st = schedule_state(schedule, entry, registry.domain)
        if not state_ok and st in (STATE_ACTIVE, STATE_PAUSED):
            st = STATE_UNKNOWN
        nxt = None
        local = hint = "-"
        if st == STATE_ACTIVE:
            nxt = next_occurrence(schedule.cron, schedule.timezone, now)
            if nxt is not None:
                zone = parse_timezone(schedule.timezone)
                local = nxt.astimezone(zone).strftime("%Y-%m-%d %H:%M %Z")
                hint = relative(nxt - now)
        last = entry.get("last_run") if isinstance(entry.get("last_run"), dict) else None
        if last and last.get("result") == "started" and runner is not None:
            job = runner.job_state(last.get("job_id") or "")
            if job is None:
                last = {**last, "result": "failed", "reason": "interrupted: the job was lost"}
            elif job.state == "succeeded":
                last = {**last, "result": "succeeded"}
            elif job.state == "failed":
                last = {**last, "result": "failed", "reason": _short(job.detail)}
        views.append(
            ScheduleView(
                schedule=schedule,
                state=st,
                next_run=nxt,
                next_local=local,
                next_hint=hint,
                last_run=last,
                on_this_server=st != STATE_NOT_HERE,
                paused=bool(entry.get("paused")),
            )
        )
    return views
