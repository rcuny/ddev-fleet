---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-18
Type: documentation
---

# Scheduled jobs

> **v0.x (Python) documentation** — the 1.x port re-implements this in milestone M0.09; the files and commands it names (`src/fleet/`, `fleet`, `bootstrap.sh`) live on the `main` branch, not on `develop`.

A fleet server can run periodic tasks by itself. A schedule is declared in
`fleet.yml`, fired by a scheduler that lives inside the fleet daemon, and shown
(with **Run now** and **Pause / Resume**) on the **Scheduled jobs** tab of the
web UI and by `fleet schedule`. v1 has one action: `redeploy <instance>`, an
in-place rebuild of an existing instance, the same operation as `fleet redeploy`
and the dashboard's Redeploy button.

The first use case: redeploy the blog Playwright baseline instance
(`blog--baseline`) every night at 03:00 so that date-dependent listers do
not make the next branch run fail visual regression for reasons that have
nothing to do with the branch.

## Declaring a schedule (`fleet.yml`)

A new top-level key `schedules:`, a mapping of schedule name to definition:

```yaml
schedules:
  pw-baseline-nightly:            # name: [a-z0-9][a-z0-9-]{0,62}
    cron: "0 3 * * *"             # 5 fields, see below
    timezone: Europe/Paris        # IANA name; required
    action: redeploy              # v1: only "redeploy"
    instance: blog--baseline # required for action redeploy
    servers: [server1.fleet.example.com]     # optional: fleet domains it runs on; absent = every server
    enabled: true                 # optional, default true
```

- All of `cron`, `timezone` and `action` are required; unknown keys, an unknown
  action, a bad cron expression or timezone and a missing `instance` are
  registry errors naming the schedule (`schedules.<name>.<key>: ...`), like
  every other `fleet.yml` key.
- Schedules are declared in the config repo, so they are reviewed and versioned
  like `bitbucket_hooks`, and all servers that share the repo see the same
  definitions. They live at the top level of the file, next to `fleet:` and
  `projects:`. A server running a fleet version from before FLE-31 ignores the
  unknown top-level key (the schedule simply does not exist there), so the key can
  be committed before every server is upgraded.
- The web UI and the CLI never create or edit schedules. They only run, pause
  and resume them.

### The `servers` filter

`servers` lists fleet domains. A schedule runs on this server when `servers` is
absent, or contains the server's fleet domain, i.e. what the registry resolves
as `Registry.domain`: `host.yml`'s `domain` override, else `fleet.domain`. The
comparison ignores case. A schedule that is not for this server is listed (UI
and CLI) as **not on this server**, has no buttons, and never fires. Use it for
a nightly job that must exist on one server only (the config repo is shared).

## Cron syntax

Five fields: minute (0-59), hour (0-23), day-of-month (1-31), month (1-12),
day-of-week (0-7; 0 and 7 are Sunday).

- Allowed: `*`, numbers, ranges `a-b`, steps `*/n` and `a-b/n`, comma lists.
- Not supported: month and weekday names, `@daily` style macros, seconds, `L`,
  `W`, `#`. This is a small in-house implementation (no dependency).
- Vixie semantics for the two day fields: when **both** day-of-month and
  day-of-week are restricted (the field does not start with `*`), a day matches
  when **either** matches. `0 0 1 * 1` is "midnight on the 1st of the month and
  on every Monday". When one of them starts with `*` (`*`, `*/2`), both must
  match: `0 0 */2 * 1` is "Mondays that fall on an odd day of the month".
- An expression that can never fire (`0 0 31 2 *`) is rejected at load time.
  `0 0 29 2 *` (leap day) is fine.
- The expression is evaluated in the **local wall time of the schedule's
  `timezone`**, not in UTC and not in the server's timezone.

### Daylight saving time

Two rules, both tested for Europe/Paris:

1. A local time that **does not exist** (spring-forward gap) is **skipped**:
   with `30 2 * * *` in Europe/Paris there is no run on the night of the last
   Sunday of March, and the next run is the following night.
2. A local time that is **ambiguous** (fall-back overlap) fires **once, at the
   first instance** (the summer-time one). `30 2 * * *` fires at 02:30 CEST that
   night, not again at 02:30 CET an hour later. An hourly `0 * * * *` therefore
   has no separate run for the repeated 02:00 hour.

A time such as `0 3 * * *` is not affected by either rule.

## How the scheduler runs

One asyncio task is started with the daemon (`fleet.service`, the same lifespan
that syncs the Caddy port snippets) and ticks every 30 seconds. Each tick it
re-reads `fleet.yml` (so an edit is picked up without a restart, once the config
repo is pulled), finishes the bookkeeping of runs that ended, then looks at every
schedule:

- A schedule is **active** when it is `enabled` in `fleet.yml`, **not paused**
  and **on this server**. Anything else never fires.
- It computes the latest occurrence at or before now. If that occurrence is newer
  than the schedule's recorded `last_fired`, the scheduler **claims** it first and
  only then runs it. The claim is a compare-and-set under the state lock (the
  state is re-read inside the lock and the claim only succeeds if `last_fired` is
  still older), so a crash, a restart or even a second scheduler process on the
  same fleet home can never fire the same occurrence twice. In the same locked
  transaction a `claimed` line is appended to the history, so an occurrence lost
  to a crash between the claim and the job submit is visible: a `claimed` line
  with no `started` after it.
- A run in the process is also guarded per schedule: while a run is being
  started, another Run now click or cron tick for the same schedule is recorded as
  `skipped: previous run still running` and does not touch the `last_run` of the
  run in progress.
- The task never dies: errors are caught per schedule, logged and recorded as a
  `failed` entry (once, not on every tick), and if the task itself ever ends the
  daemon logs it and starts it again.

A run goes through the daemon's `JobManager` exactly like the Redeploy button
(`kind: redeploy`, the instance's `deploy.log` as its live log), so it shows up in
the same job list. Completion is noticed on the next tick (or when the page is
rendered): the run is then recorded as `succeeded` or `failed`, with the job's
error as the reason.

### Missed runs: the policy

| Situation | What happens |
|---|---|
| Normal firing | The occurrence is run, trigger `cron` |
| The daemon was down, the occurrence is **at most 60 minutes** old when the scheduler sees it | Run it now, trigger `catch-up` (also used when a tick was late by more than two ticks) |
| The occurrence is **more than 60 minutes** old | **Not run.** One `missed` entry is written to the history, and the schedule moves on to the next occurrence |
| A schedule is seen for the first time (no state yet), or is enabled/resumed | The latest occurrence is recorded as seen **without running it**: adding or re-enabling a schedule never fires immediately |
| The `cron` or `timezone` of a schedule is **edited** in `fleet.yml` | Treated as first sight too (the state stores the cron and timezone next to `last_fired`): the new expression's latest occurrence is recorded as seen, never caught up. The first run is the next occurrence after the edit |
| A schedule is paused | Occurrences during the pause are neither run nor reported as missed. **Resume behaves like first sight:** no catch-up for the pause |
| Several occurrences were missed (`*/5 * * * *` and a long outage) | Only the latest one is considered; one `missed` entry, not one per occurrence |
| A daemon restart | Nothing is lost (state is on disk) and nothing runs twice. A run that was in flight is recorded as `failed: interrupted` (the redeploy died with the daemon) |
| The previous run of the same schedule is still running | The new one is `skipped`, reason `previous run still running`. At most one run per schedule at a time |
| The instance does not exist on this server | `failed`, reason `instance <id> not found`, no job is created |
| The instance is locked (a deploy or redeploy of it is running) | `skipped`, reason `instance busy`. Never queued: the next occurrence tries again |

**"Succeeded" means the redeploy job succeeded, not that the work inside the
instance ran.** The tty1 commands (for the blog baseline, `pw-ci.sh` in baseline
mode) are typed into the instance's tmux window only when `fleet-tmux.service` is
active; if it is not, the deploy log gets a warning and the job still succeeds.
Even when they are typed, they run after the job has ended. To check that the
baseline itself was refreshed, look at the artifact (for the blog project, the mtime of
`/srv/fleet/artifacts/blog/pw-baseline/BASELINE_SHA`), not only at the green row.

A schedule removed from `fleet.yml` and added again later keeps its old
`last_fired`, so it can report one `missed` entry (or catch up, inside the
window) for the occurrences while it was absent. Delete its entry in
`state.json` to make it a fresh "first sight".

### Interaction with the CI baseline lock

`blog--baseline` is the blog Playwright CI baseline. Its tty1 command
(`pw-ci.sh`, baseline mode) takes the `flock` `/srv/fleet/artifacts/blog/pw.lock`,
as does a branch CI run. A baseline run therefore delays a branch CI run in
progress, and the reverse, **by design**. That lock is taken inside the instance,
not by fleet: the scheduled redeploy itself only needs the per-instance fleet lock
(deploy, redeploy and destroy of one instance), then re-types the tty1 commands.
Note that a redeploy **destroys** the instance first, so a baseline run still in
progress at 03:00 is killed and restarted. 03:00 is quiet: nightly Renovate runs
on Bitbucket, not on the servers.

## State and history on disk

Under `<fleet home>/schedules/` (`/srv/fleet/schedules/`):

- `state.json`: `{"<name>": {"paused": bool, "last_fired": "<ISO UTC>",
  "cron": "...", "timezone": "...",
  "last_run": {ts, trigger, instance, result, reason, job_id, finished?}}}`.
  Written atomically (temp file, fsync, rename) under a lock, so the daemon and the
  CLI never lose each other's update.
- `history.jsonl`: one line per run outcome, append-only:
  `{schedule, ts, trigger, instance, result, reason, job_id}`.
  - `trigger` is `cron`, `catch-up`, `manual-ui` or `manual-cli`.
  - `result` is `claimed` (an occurrence was claimed, written before the job is
    submitted), `started` (a job was created), then later `succeeded` or `failed`
    for that job; or `skipped` or `missed`. `job_id` is `null` when no job was
    created. A cron run therefore usually has three lines: `claimed`, `started` and its final result (a manual run has no `claimed` line).
  - The file is never trimmed; the UI reads the last 20 lines and skips torn or
    foreign lines.

These are runtime state and are **not** part of the config repo.

### A bad state fails closed

If `state.json` cannot be read, is corrupt (invalid JSON or the wrong shape) or
cannot be written, or the lock file cannot be opened (typically a root-owned file
left by a CLI run as root), the scheduler does **not** treat it as empty and never
overwrites it: that would silently drop every `paused` flag (a paused schedule
would fire again) and every `last_fired`. Instead:

- the tick logs the error **once** per distinct error, fires nothing, and keeps
  ticking (no crash, no restart loop); it recovers by itself when the file is fixed;
- `/schedules` and the table partial still render (the definitions come from
  `fleet.yml`) under a banner naming the path and the fix, with the schedules shown
  as `state unreadable` and no buttons; **Run now**, **Pause** and **Resume** answer
  400 with the same message;
- `fleet schedule list|run|pause|resume` exit with status 1 and that message
  (`history` only reads the history and still works);
- a corrupt file is also copied once to `state.json.corrupt-<timestamp>`.

The fix is either to make `/srv/fleet/schedules/` and its files readable and
writable by the user the daemon runs as (`chown`), or to delete `state.json`.
Deleting it re-initialises every schedule as first sight: nothing fires because of
it, but **pause flags are lost**, so pause again what must stay paused.

## The web UI

`GET /schedules` (nav: Instances, Secrets, Webhooks, **Scheduled jobs**), with an
HTMX partial `GET /ui/schedules/table` behind the Refresh button. Same Caddy
authentication and the same strict CSP as the other pages (no inline script, no
inline handler).

| Column | |
|---|---|
| Name | |
| Schedule | the cron expression, its timezone, and the `servers` list when set |
| Action | `redeploy` and the target, linked to the instance's URL while it exists |
| Next run | local time in the schedule's timezone, plus a relative hint. Only for active schedules |
| Last run | time, result, trigger, reason, job id and a link to the instance's deploy log |
| State | `active`, `paused`, `disabled in fleet.yml` or `not on this server` |

Buttons (POST `/ui/schedules/<name>/run|pause|resume`, covered by the same
cross-origin refusal as every other `/ui/*` write):

- **Run now** asks for confirmation (it destroys and rebuilds the instance) and
  works for paused and disabled schedules too, since it is an explicit action; it
  does not work for a schedule that is not on this server. A busy instance shows
  the `skipped` reason, a missing one the `failed` reason.
- **Pause** / **Resume** edit the state file. Resume marks the latest occurrence as
  seen, so the pause is never caught up.

Below the table are the last 20 history entries, newest first.

## CLI

```
fleet schedule list                     # state, cron, target, next run, last run
fleet schedule run <name>               # run it now, in this process, output streamed
fleet schedule history [<name>] [-n N]  # recent outcomes, oldest first (default 20)
fleet schedule pause <name>
fleet schedule resume <name>
```

- `run` works like `fleet redeploy <instance>`: in-process, output on the
  terminal, the per-instance lock respected (a busy instance is `skipped`, exit
  code 1), the outcome recorded with trigger `manual-cli` and no job id. It does
  not need the daemon.
- `pause` and `resume` edit the state file; the daemon sees the change on its next
  tick. Run the CLI as the user the daemon runs as (the `fleet` wrapper does), so
  `state.json` keeps its owner.
- `list` shows the effective state (the same four values as the UI) and the next
  run in the schedule's timezone.

## Troubleshooting

- *A schedule did not fire.* `fleet schedule list` (is it `active`? is the next run
  what you expect, in the right timezone?), then `fleet schedule history <name>`
  (`missed` means the daemon was down for more than 60 minutes around the
  occurrence), then `journalctl -u fleet` for `schedules:` lines.
- *The registry has an error.* The scheduler logs `registry not loadable` once and
  keeps trying every tick; nothing fires until `fleet.yml` loads again.
- *`failed: instance X not found`.* The schedule names an instance that is not
  deployed on this server (check `servers:` and the instance id).
