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
`2` not allowed now (no `--yes`; `run` when the instance is busy or gone), `3` not found.

## Rules

- The cron expression has exactly five fields (minute hour day-of-month month day-of-week); macros such as `@daily` are refused.
- Cron times are read in the schedule's timezone. A time the spring-forward skips fires once, shifted past the gap. A
  time the fall-back repeats fires at its first pass when the expression names its hours (`30 2 * * *`); an expression
  that runs every hour (`0 * * * *`) keeps firing through the repeated hour.
- The ticker runs every 30 seconds. An occurrence at most 90 seconds old is a `schedule` run. After downtime, only the
  **most recent** missed occurrence fires, as a `catch-up` run, and only when it is at most 60 minutes old; an older one
  is recorded as `missed` and nothing fires. Resuming a paused schedule and changing its timing never catch up.
- Each occurrence is claimed once (a unique key on schedule and occurrence), so two ticks racing fire one job.
- Results, in the 0.x words: `claimed` (job queued), `started`, `succeeded`, `failed`, `skipped` (the instance was busy)
  and `missed`.
- The only action is `redeploy` (the schedule's instance). Actions are a small registry
  (`App\Fleet\Schedules\ScheduleAction`, tag `fleet.schedule_action`); each builds its job through the generic job API.

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
