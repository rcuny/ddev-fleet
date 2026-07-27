---
name: fleet-deploy-batch
description: Deploy several instances in one go — the driving case is one instance per distinct branch (e.g. "deploy oak on branches x, y, z"), with a secondary case of N identical replicas of the same branch. Invoked via /fleet-deploy-batch or "deploy <project> on branches x, y, z".
---

# /fleet-deploy-batch — batch deploy

Deploy is reversible (a bad instance is cheap to `fleet destroy`, which
still prompts), so this skill never gates *before* starting a batch — it
only warns on thin headroom.

## Inputs

- `project` (required).
- A list of `(branch, optional label)` pairs (required, at least one).
- Optional shared `template` (falls back to the project's
  `default_template` in the registry if omitted, same as plain `fleet
  deploy`).
- Optional `--fresh` / `--force` to pass through to every call in the
  batch.

## Step 0 — preflight validate `(project, template)`

Before looping, confirm the project (and template, if given) actually
exist, so one typo doesn't waste N attempts:

```bash
grep -n "^  <project>:" /srv/fleet/config/fleet.yml
```

If that prints nothing, stop and report "unknown project `<project>` — not
in /srv/fleet/config/fleet.yml" without running anything else. If a
template was given, also check it appears under that project's
`templates:` block (`grep -n -A 30 "^  <project>:" /srv/fleet/config/fleet.yml`
and look for `    <template>:` inside the `templates:` section). If the
template is missing, stop the same way naming the missing template.

## Step 1 — group the inputs

Group the `(branch, label)` pairs by `(project, template, branch)` — same
project and template are implied (this skill only takes one project/template
pair per invocation), so effectively group by `branch`.

For each group:
- **Size 1** → always the loop path (Step 2a) below.
- **Size ≥2, and every entry in the group either has no explicit label or
  the group's labels are exactly the auto-numbered shape `<base>-1`,
  `<base>-2`, ... `<base>-N` in order** → eligible for the native `--count`
  path (Step 2b), **if** it's available (Step 1.5).
- **Size ≥2 with any explicit, non-auto-numbered-shaped label** → the loop
  path (Step 2a), even though the branch is shared — `--count`'s
  auto-numbering can't reproduce arbitrary user-chosen labels.

Mixed input is handled **per-group**, not all-or-nothing: e.g. two replicas
of branch `A` plus one instance of branch `B` does one `--count=2` call (or
a 2-iteration loop, if `--count` isn't available) for `A` and one looped
call for `B`. The final output table doesn't distinguish which path
produced a given row — both are equally valid.

## Step 1.5 — detect native `--count` support

`fleet deploy --count` does not exist in the CLI as of this writing (it
belongs to a sibling spec/plan that may or may not have landed) — detect
it at runtime, never assume either way:

```bash
fleet deploy --help
```

If the output lists a `--count` option, same-branch-replica groups
(Step 1, second bullet) use the native path (Step 2b). Otherwise **every**
group — including same-branch replica groups — uses the loop path
(Step 2a). This check costs one cheap call and makes the skill correct
regardless of when the sibling work ships.

## Step 2a — loop path (multi-branch, and same-branch groups without native support)

For each `(branch, label)` pair, run:

```bash
fleet deploy <project> [<template>] --branch <branch> [--label <label>] [--fresh] [--force]
```

Capture the exit code and printed output (the instance URL on success, or
the `FleetError` message on failure) for each call. **One failure never
aborts the batch** — continue to the next pair regardless.

## Step 2b — native path (same-branch replicas, only when Step 1.5 found `--count`)

```bash
fleet deploy <project> [<template>] --branch <branch> --label <base-label> --count <N> [--fresh] [--force]
```

This auto-labels `<base-label>-1` .. `<base-label>-N`, enforces its own
10%-disk gate by default (pass `--skip-disk-check` only if the user
explicitly asked to override it), and returns exit code `2` for partial
success across the N replicas — treat that the same as "some succeeded,
some failed" in the output table below.

## Pre-batch headroom warning (loop path only, batches of 3+)

Before starting a loop-path batch of 3 or more calls, run `fleet list`,
`df -h /srv/fleet`, and `free -h` and print a one-line warning if disk free%
(`100 - Use%` from `df`) is below 15% — this is advisory only, never a
block; the native path's own 10% gate (Step 2b) is the actual hard stop for
whatever fraction of the batch takes that path.

## Output format

```
/fleet-deploy-batch: <project> [<template>]

BRANCH          LABEL              INSTANCE ID              RESULT     ELAPSED
<branch>        <label>            <id or ->                ok/FAILED  <Ns>
...

<K>/<N> deployed.
[Any FAILED rows: quote the FleetError message underneath the table.]
```

Never silently swallow a failed call — every row must show `ok` or
`FAILED` plus the underlying message for the failed ones.
