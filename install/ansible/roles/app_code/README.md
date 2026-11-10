# app_code

Port of `install/steps/80-code.sh` and `85-app-init.sh`, run as `fleet` with `fleet.env` loaded.

- The checkout in `fleet_src_dir` is made by the bootstrap; this role only reads its revision.
- Waits (bounded by `fleet_job_wait`) for running jobs, then builds (composer, importmap, Tailwind, assets, cache), then
  migrates, sets up the queue, refreshes the instance sites and records the host (`fleet:host:init`).
- **Difference from bash:** the build runs only when the revision differs from `installed-revision`, `fleet.env` changed,
  `vendor/` or the compiled assets are missing, or `fleet_force_build` is true. Bash rebuilt on every run.
- Facts for the `units` role: `fleet_code_changed`, `fleet_code_revision`, `fleet_setup_complete`.
- `command` tasks are skipped in `--check`: a drift report does not show the build or the migrations.
