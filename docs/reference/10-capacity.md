# Capacity: allotments, slots and slices

One noisy instance should not starve the host. Fleet puts a CPU ceiling on each project and template and a cap on concurrent heavy runs, so CI and agents get predictable CPU. This page is the lookup; the tuning advice is in [operate](../guides/07-operate.md) and the CI setup in [run CI](../guides/04-run-ci.md). Everything is stored per host.

## The model

| Concept | Meaning |
|---|---|
| Allotment | CPU threads shared by all instances of a project or template together. A ceiling, not a reservation. Empty = unlimited (template: inherits the project's). |
| Slot | One concurrent heavy run (a CI suite, an agent working). A run needs a free slot at every level that sets `slots`: template, then project. |
| Slice | The systemd cgroup carrying the quota. Containers sit under it, so web, db and add-ons are all covered, and a change applies live without a restart. |

- Whole numbers of at least 1. A template allotment above its project's, or a project lowered below a template's, is refused.
- Slots are advisory (scripts enforce them with `flock`); the hard guarantee is the slice.

## Host numbers

`/admin/host?tab=capacity` and `fleet host:capacity` show:

| Number | Meaning |
|---|---|
| Threads, cores, RAM | Detected. |
| Reserve | Threads kept for the OS, Docker, Caddy and workers. Editable, default 2. |
| Allocatable | `threads - reserve`; also the quota of `fleet.slice`. |
| Instance cap | Editable, default 25. |
| Committed | Sum of the project limits. Above allocatable shows an overcommit warning; allowed, because `fleet.slice` still caps the total. |

**`busy`** for a pool is the larger of the slot lock files held now and 1 when its slice used at least 0.2 threads over the last sample, so an agent holding no lock still counts.

## Slot directories and variables

Written into each instance's `.ddev/config.fleet.yaml` at deploy and restart. A `slots` change reaches a running instance at its next restart or redeploy.

| Variable | Meaning |
|---|---|
| `FLEET_PROJECT_SLOTS`, `FLEET_TEMPLATE_SLOTS` | Slot counts; empty = unlimited. |
| `FLEET_TEMPLATE_CPU_THREADS` | The template's effective allotment. |
| `FLEET_SLOT_DIR` | `$FLEET_HOME/slots/<project>/<template>/`. |
| `FLEET_PROJECT_SLOT_DIR` | `$FLEET_HOME/slots/<project>/_project/`. |

A script takes a slot by holding an `flock` on a file in the directory for the length of its run.

## Slice naming

Hierarchy: `fleet.slice` > `fleet-<project>.slice` > `fleet-<project>-<template>.slice`. Each `-` inside a project or template name is written `_`, because systemd derives a slice's parent from dashes: project `blog-ci`, template `gates` is `fleet-blog_ci-gates.slice`. A project or template without a limit still gets a slice (usage stays measurable) but no quota.

## Commands

| Command | Does |
|---|---|
| `fleet project:create` / `project:update`, `template:create` / `template:update` | Set the allotment and slots (`none` = unlimited, or inherit for templates). |
| `fleet host:capacity` | The report; `--reserve` and `--max-instances` compute a what-if without saving. |
| `fleet host:slices` | (Re)install the slices from the database; `--print` only lists them. |

Options are in [01-cli.md](01-cli.md). Limit changes reapply the slices by themselves. Run `fleet host:slices` once after an upgrade that introduces slices, or to repair a host ([upgrade](05-upgrade.md)). Instances created before slices existed are not under a slice until redeployed; the host page lists them.

## What capacity does not cover

- RAM: Fleet sets no memory limit.
- tmpfs: `fleet:host:capacity` and the host page's Capacity tab warn when `/tmp` is a tmpfs more than 50% full, or
  when `Shmem` in `/proc/meminfo` is above 25% of the RAM. The JSON report has `memory` (`shmem_mb`, `shmem_percent`,
  `tmp`: `tmpfs`, `size_mb`, `used_mb`, `percent`) and `warnings` (a list of `{code, message}`, codes `tmp_tmpfs_high`
  and `shmem_high`; empty when all is well). The installer caps the `/tmp` tmpfs (`docs/reference/04-installer.md`).
- Disk and I/O: no quota or priority either; watch the disk holding `/srv/fleet` yourself.
- A per-instance CPU cap or CPU pinning: only the project and template ceilings exist.

## Learn more

- [Operate](../guides/07-operate.md)
- [Run CI](../guides/04-run-ci.md)
- [Configuration](02-configuration.md)

## CI instance retention

Optional, per project, and **off by default**. Old CI instances fill the instance cap of a host (new deploys then fail with `429 capacity_full`), so a project can ask Fleet to remove them. Set `ci_retention_days` on the project's **Capacity** tab, or with `fleet project:update <project> --ci-retention-days=N` (`none` turns it off).

A pass runs once a day (03:17, host time) and, for each project with a retention, destroys the instances that match all of these:

- the label starts with `ci-`;
- the last deploy (`deployed_at`, else `created_at`) is older than N days.

It never destroys a protected instance (the lock of the Protection section), an instance with a job queued or running, one already being destroyed, a project's `main` instance, or a label that does not start with `ci-`. Each destroy goes through the same code as a manual one and is recorded in the audit log as `instance.retention` (subject `<instance id>/<N>d`; the actor is `scheduler`).

`fleet instance:retention [--project=P] [--dry-run] [--json]` runs the same pass by hand; with `--dry-run` it lists what would be destroyed and changes nothing. Pin an instance you want to keep by protecting it.

Instance ages are shown in the web instance list (**Age** column), in `fleet instance:list` (`createdAt` and `deployedAt` in the JSON) and in the API instance JSON (`created_at`, `deployed_at`).
