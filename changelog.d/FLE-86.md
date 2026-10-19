### Added

- **FLE-43: the nightly redeploy schedule, end to end** (milestone M0.09b). Integration tests drive a `redeploy` schedule from its cron time to the finished job (no fake action): the job and the run history, a busy instance (`skipped`), a destroyed instance (`failed`), a paused schedule and two schedules on two instances. A test matrix covers the catch-up window (10, 59 and 61 minutes of downtime, one fire only, `missed` after 60 minutes).
- A reaper: each tick finishes a run left `claimed` without a job for over 5 minutes (the ticker died between the claim and the launch) as `failed`, "The ticker stopped before the job was queued.". It is never fired again.
- The schedule form and `fleet:schedule:create` / `update --help` list the registered actions; the run history and the schedule list link to the job. `docs/schedules.md` has a "nightly redeploy" recipe.

### Fixed

- **FLE-72 follow-up:** a tick more than 90 seconds late is a `catch-up` only when the ticker was down. The ticker now records the end of every tick (`scheduler_heartbeat`, migration `Version20261022100000`).
