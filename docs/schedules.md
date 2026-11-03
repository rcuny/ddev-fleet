---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Schedules

A schedule fires an action on a cron expression, as the 0.x fleet did: five fields, an IANA timezone, pause and resume,
a catch-up window after downtime, a run history and "run now". Manage them on **Schedules** (`/admin/schedules`,
administrators only) or with `fleet schedule:*` (`php bin/console fleet:schedule:*`).

## Commands

| Command | What |
|---|---|
| `fleet:schedule:list` | Name, cron, timezone, action, instance, state, next fire, last result. |
| `fleet:schedule:create <name> --cron="0 3 * * *" [--timezone=Europe/Brussels] [--action=redeploy] --instance=<id>` | Create one. |
| `fleet:schedule:update <name> ...` | Change the timing, action or instance (not the name). |
| `fleet:schedule:pause <name>` / `resume <name>` | A paused schedule never fires. Resuming does not catch up what it missed. |
| `fleet:schedule:run <name>` | Run now, also while paused. Recorded as a `manual` run. |
| `fleet:schedule:history <name> [--limit=50]` | The runs, newest first. |
| `fleet:schedule:delete <name> --yes` | Delete the schedule and its history. |

All accept `--json`. Exit codes: `0` done, `1` a value was refused (`--cron`, `--timezone`, `--instance` named on stderr),
`2` not allowed now (no `--yes`; `run` when the instance is busy or does not exist), `3` not found.

## Rules

- The cron expression has exactly five fields (minute hour day-of-month month day-of-week); macros such as `@daily` are refused.
- Cron times are read in the schedule's timezone. A time the spring-forward skips fires once, shifted past the gap. A
  time the fall-back repeats fires at its first pass when the expression names its hours (`30 2 * * *`); an expression
  that runs every hour (`0 * * * *`) keeps firing through the repeated hour.
- The ticker runs every 30 seconds and records when each tick ended (table `scheduler_heartbeat`). An occurrence at most
  90 seconds old is a `schedule` run. A `catch-up` run needs both: the occurrence is more than 90 seconds old **and** the
  ticker was down, meaning the previous tick ended more than 90 seconds ago or there was none. A late occurrence while the
  ticker was running (a slow tick, a retry after an error) stays a `schedule` run. After downtime, only the **most
  recent** missed occurrence fires, as a `catch-up` run, and only when it is at most 60 minutes old; an older one is
  recorded as `missed` and nothing fires. Resuming a paused schedule and changing its timing never catch up.
- Orphaned runs: the ticker claims an occurrence, then queues the job. If it dies in between, the run stays `claimed`
  with no job. Each tick finishes such a run, once it is older than 5 minutes, as `failed` with the detail "The ticker
  stopped before the job was queued." It is never fired again (the claim consumed the occurrence), and a second tick
  leaves it alone.
- Each occurrence is claimed once (a unique key on schedule and occurrence), so two ticks racing fire one job.
- Results, in the 0.x words: `claimed` (job queued), `started`, `succeeded`, `failed`, `skipped` (the instance was busy, or does not exist) and `missed`.
- **An instance that does not exist yet is accepted** by the CLI, the pages and project import alike, so a schedule can
  be set up before its instance is deployed (or after a host restore). The create and update commands print a warning
  ("Instance X does not exist yet; runs are skipped until it does", the `warning` key with `--json`), the pages flash it,
  and the schedule list shows a **Target missing** badge. Until the instance exists every run, timed or **Run now**,
  is recorded as `skipped` with that reason and no job is queued; it is not retried. Once the instance exists the next
  occurrence runs normally. An empty instance id is still refused.
- The only action is `redeploy` (the schedule's instance). The form and `fleet:schedule:create --help` list the
  registered actions. Actions are a small registry
  (`App\Fleet\Schedules\ScheduleAction`, tag `fleet.schedule_action`); each builds its job through the generic job API.

## Recipe: nightly redeploy

Rebuild an instance every night at 03:00 Brussels time:

```bash
fleet schedule:create nightly-demo --cron "0 3 * * *" --timezone Europe/Brussels --action redeploy --instance demo--main
fleet schedule:run nightly-demo     # try it now: prints "Started job N."
fleet schedule:history nightly-demo # one row per night, with the job and how it ended
```

At 03:00 the ticker queues a `redeploy` job for the instance (trigger `schedule`, requested by `schedule:nightly-demo`)
and the history row follows that job: `claimed` while queued, `started`, then `succeeded` or `failed`. If the instance
has a queued or running job the run is `skipped` (with the reason); if the instance does not exist (never deployed, or destroyed) it is `skipped` with that reason. The
history links each run to its job page. On the page, **Run now** does the same by hand and the schedule row links the
last job.

## Running the ticker

The ticker is the Messenger consumer of the scheduler transport. The unit file is `install/etc/fleet-scheduler.service`
; the installer places it, enables it and starts it
(restarted with the workers on a code or environment change, removed by the uninstaller). To look at it on a host:

```bash
systemctl status fleet-scheduler
journalctl -u fleet-scheduler -n 50 --no-pager
```

Jobs a schedule fires run on the normal job worker (`fleet-worker@1`), so both units must be running. In dev and test the
`jobs` transport is synchronous, so "run now" executes inside the request.
