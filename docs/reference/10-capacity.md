---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

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
- Disk and I/O: no quota or priority either; watch the disk holding `/srv/fleet` yourself.
- A per-instance CPU cap or CPU pinning: only the project and template ceilings exist.

## Learn more

- [Operate](../guides/07-operate.md)
- [Run CI](../guides/04-run-ci.md)
- [Configuration](02-configuration.md)
