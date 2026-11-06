---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# CPU allotments, slots and slices (1.x)

How a host decides who may use how many CPU threads: **allotments** (a ceiling per project and per template),
**slots** (a cap on concurrent heavy runs), and the **slices** that enforce the ceilings in the kernel. Fleet 1.x
only; the 0.x line keeps its per-container `cpus:` files.

Everything here is configured per host, in that host's database: the same project can have a different allotment
on each server.

## The model in one picture

```
fleet.slice                     allocatable threads (host-wide fence)
 +- fleet-<project>.slice       project allotment (no limit when unset)
     +- fleet-<project>-<template>.slice   template allotment (no limit when unset)
         +- docker-<id>.scope   every container of every instance of the template
```

- An **allotment** is a total shared by all instances of the project (or template) together. It is a ceiling, not
  a reservation, and there is no per-run ceiling: a lone run may use the whole allotment, concurrent runs share it.
- A **slot** is one concurrent heavy run (a CI suite run, or a dispatched agent actively working).
- A **slice** is the systemd cgroup that carries the quota. Docker puts each container under it
  (`cgroup_parent`), so the limit covers web, db and every add-on of an instance, and a change applies live.

## Host numbers

| Value | Where it comes from |
|---|---|
| threads | logical CPUs, detected (`nproc --all`), never stored |
| cores | sockets x cores per socket (`lscpu`), detected |
| RAM | `MemTotal`, detected |
| reserve | threads kept for the OS, Docker, Caddy, php-fpm and the Fleet workers. Editable, default **2** |
| allocatable | `threads - reserve`. Also the quota of `fleet.slice` |
| max instances | cap on existing instances. Editable, default **25** |
| committed | sum of the effective project limits (an unlimited project counts as allocatable) |

Detected values are read on demand so a resized host is never stale. When `committed` exceeds `allocatable` the
host page and `fleet host:capacity` flag the host as overcommitted. This is allowed: the limits are ceilings,
and `fleet.slice` still caps the total at `allocatable`, so overcommit cannot starve the OS.

`instances.max` also shrinks to what Docker's address pools can hold (`docker_networks`, see
[`upgrading.md`](upgrading.md)).

## Allotments

| Field | Entity | Meaning |
|---|---|---|
| CPU threads | project | total threads for all instances of the project; empty = unlimited |
| CPU threads | template | total threads for all instances of the template; empty = inherits the project's |

Effective values:

```
effective project  = min(project.cpuThreads  ?? allocatable, allocatable)
effective template = min(template.cpuThreads ?? effective project, effective project)
```

Rules (the form and the CLI refuse a violation and name the template involved):

- whole numbers of at least 1 (0 is refused; stop or destroy an instance to disable it);
- a template's value may not exceed the project's effective limit, and a project limit may not be lowered below one
  of its templates' own values;
- a project value above `allocatable` is accepted and capped at run time; the UI shows the cap.

## Slots, two levels

| Field | Entity | Meaning |
|---|---|---|
| Slots | project | concurrent runs across all templates of the project; empty = unlimited |
| Slots | template | concurrent runs of this template; empty = unlimited |

A run needs a slot at **every level that sets one**: the template pool if the template sets `slots`, and the
project pool if the project sets `slots`. Locks are taken template first, then project, and released in reverse.
A template's `slots` may not exceed the project's.

Use project-level slots for one shared pool across several templates (for example three concurrent CI runs
whichever of `full`, `browser` or `browser-ml` they are), template-level slots for a pool per template.

Slots are **advisory**: Fleet stores and reports them, and the scripts that start runs enforce them with `flock`.
The hard guarantee is the CPU slice. A script that ignores its slots is not stopped, only throttled.

### How `busy` is counted

`busy` of a pool is the larger of:

1. the slot lock files held right now (read from `/proc/locks`; Fleet never locks a file to ask), and
2. 1 when the pool's slice used at least **0.2 threads** over the last sample, so a dispatched agent that holds no
   lock still counts. The template pool reads its own slice; the project pool reads the project slice minus its
   template slices.

## Slot directories and environment variables

Fleet creates the lock directories and writes these variables into every instance's
`.ddev/config.fleet.yaml` (`web_environment`) at deploy and restart:

| Variable | Value |
|---|---|
| `FLEET_PROJECT_SLOTS` | project slots; empty = unlimited |
| `FLEET_TEMPLATE_SLOTS` | template slots; empty = unlimited |
| `FLEET_TEMPLATE_CPU_THREADS` | the template's effective allotment (a number) |
| `FLEET_SLOT_DIR` | template pool lock directory, `$FLEET_HOME/slots/<project>/<template>/` |
| `FLEET_PROJECT_SLOT_DIR` | project pool lock directory, `$FLEET_HOME/slots/<project>/_project/` |

(`_project` cannot collide with a template name: names match `[a-z0-9-]+` and never contain `_`.) A script takes a
slot by holding an `flock` on a file in the pool's directory for the length of its run, and skips a pool whose
count variable is empty.

The values are baked at start: a change of `slots` reaches a running instance at its next restart or redeploy,
while a change of an allotment applies at once (see slices below).

## Slices

Slice names encode each `-` of a project or template name as `_`, because systemd derives a slice's parent from
the dashes in its name: project `blog-ci`, template `gates` is `fleet-blog_ci-gates.slice`. The
encoding is injective since names never contain `_`.

- A project or template without a limit still gets a slice (so its usage is measurable) but no `CPUQuota`.
- `fleet-*.slice` units and a `10-fleet-quota.conf` drop-in are written by the root helper (`apply slices`), so
  the limits survive a reboot. A change also calls `systemctl set-property`, which is what changes `cpu.max` of a
  **running** slice (rewriting the drop-in and `daemon-reload` alone does not). Containers are not restarted.
- Every service of an instance gets the same `cgroup_parent` in `.ddev/docker-compose.fleet-cgroup.yaml`, listed
  from `ddev debug compose-config` before `ddev start`.
- The file is only written when the installed root helper knows the `slices` target. On a host that has not been
  upgraded yet, instances deploy unconstrained as before, because a `cgroup_parent` naming a slice nobody created
  would silently run with no limit.
- The 1.x assets seed skips `docker-compose.*cpu*.yaml` files, so a legacy per-container `cpus:` cap never stacks
  with a slice.
- Instances created before slices existed are not under a slice until redeployed or restarted. The host page and
  `fleet host:capacity --json` (`instances.not_under_slice`) list them.

Usage is measured from the slice's cgroup `cpu.stat` (`usage_usec` between two samples, sampled by the status
watcher) and `nr_throttled` shows whether the quota bit. A slice that throttles often is a sizing signal.

## Web UI

| Screen | What it shows |
|---|---|
| `/admin/host?tab=capacity` | threads, cores, RAM; reserve and max instances (editable); allocatable and committed with the overcommit warning; per project and template the limit (inherited marked), slots, busy, live use, throttling; instances not under a slice |
| project form | **CPU threads** and **Slots**, empty = unlimited; help text quotes the allocatable threads |
| template form | **CPU threads** (empty shows "inherits project: N") and **Slots** |

## CLI

| Command | Option | Effect |
|---|---|---|
| `fleet project:create`, `project:update` | `--cpu-threads=<N\|none>` | project allotment; `none` = unlimited |
| | `--slots=<N\|none>` | project slots; `none` = unlimited |
| `fleet template:create`, `template:update` | `--cpu-threads=<N\|none>` | template allotment; `none` = inherit the project's |
| | `--slots=<N\|none>` | template slots; `none` = unlimited |
| `fleet project:show`, `template:show` | | print both values |
| `fleet host:capacity` | `--json` | the report below |
| | `--reserve=<N>` | what-if reserve (not saved; from 0) |
| | `--max-instances=<N>` | what-if instance cap (not saved; from 1) |
| `fleet host:slices` | `--print` | install the slices from the registry (or, with `--print`, show the list and install nothing) |

Changing a limit or the set of projects and templates reapplies the slices on its own. Run `fleet host:slices`
once after an upgrade to a release with slices, or to repair a host.

### `fleet host:capacity --json`

Schema 1; changes are additive only. The exit code is always 0: it is information, the dispatch gate is computed
by the consumer (the companion's `ddev-fleet` skill).

```json
{
  "schema": 1,
  "threads": 48, "cores": 24, "ram_mb": 192000,
  "reserve_threads": 2, "allocatable_threads": 46, "committed_threads": 46,
  "instances": {"count": 12, "max": 25},
  "projects": [
    {"name": "blog-ci", "cpu_threads": 24, "effective_threads": 24, "used_cores": 3.4,
     "slots": 3, "busy": 1,
     "templates": [
       {"name": "gates", "cpu_threads": null, "effective_threads": 24, "slots": 3, "busy": 1, "used_cores": 3.4}
     ]}
  ]
}
```

`cpu_threads` and `slots` are `null` for unlimited or inherited; `effective_threads` is the resolved number.
`used_cores: null` means unknown (no sample yet, a sample older than 2 minutes, or no slice). Further fields
exist (`overcommitted`, `docker_networks`, `unreadable_locks`, `instances.not_under_slice`); see
[`upgrading.md`](upgrading.md) and [`installation.md`](installation.md) for the operator view.

## Worked example

A 48-thread host with a reserve of 2 (allocatable 46):

| Project | Allotment | Slots | Templates |
|---|---|---|---|
| `blog-ci` | 24 | none | `gates`, slots 3, inherits 24 |
| `blog-updates` | 4 | none | `gates`, slots 1 |
| `demo` | 18 | 3 (one shared pool) | `full`, `browser`, `browser-ml` inherit 18, no slots |

The sum is 46 = allocatable, so no overcommit warning. With three concurrent `blog-ci` runs they share 24
threads; a single run may use all 24.

## Not covered

RAM limits, a per-instance CPU cap, CPU pinning and enforcement of slots inside Fleet are future work.
