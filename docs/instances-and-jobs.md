---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Instances and jobs

An **instance** is one running copy of a project: a checkout of one branch, started as its own DDEV project, served at
`https://<id>.<domain>`. A **job** is one piece of work on the host (deploy, redeploy, start, stop, destroy) with a log you
can follow while it runs. This guide covers both, in the browser and with `fleet instance:*` and `fleet job:*`.

The web request never runs `ddev`, `git` or `docker`. A click or a command stores a job; a **worker** (the
`fleet-worker@1` unit) picks it up and runs it as the `fleet` user. Projects and templates are described in
[`projects-and-templates.md`](projects-and-templates.md), the install in [`installation.md`](installation.md).

## Concepts

- **Instance id**: `<project>--<label>`, for example `acme--main` or `blog--fix-login`. It is the DDEV project name, the
  directory name, the primary key and the first part of the site name. At most 63 characters; lower case letters, digits and
  single dashes inside each half, and exactly one `--`.
- **Label**: the part after `--`. It defaults to the branch name, cleaned to the grammar above. `--label` sets it.
- **Template**: the project's template used for the deploy (its Drupal environment, post-deploy commands and so on).
- **The database is the source of truth.** There is no instance file on disk; `ddev list` only confirms what the database
  says.

### Instance states

| State | Meaning |
|---|---|
| `deploying` | A deploy or redeploy was requested and its job has not finished. |
| `running` | The DDEV project is up. |
| `stopped` | The DDEV project is stopped; the checkout and database stay on disk. |
| `failed` | The last deploy, redeploy or destroy failed. Read its log, then retry. |
| `destroying` | A destroy was requested and its job has not finished. |
| `unknown` | The status watcher could not see the project in `ddev list`. |

A start or stop in flight shows as the instance's active job, not as a state. The **status watcher** (`fleet-status`) moves
only `running`, `stopped` and `unknown` instances, from `ddev list` every 10 seconds, reads the memory of each instance
from `docker stats` every 30 seconds, marks dead jobs `interrupted` and prunes old jobs once a day.

### Job statuses and types

Statuses: `queued`, `running`, `succeeded`, `failed`, `interrupted`. The last three are final. Types: `deploy`, `redeploy`,
`start`, `stop`, `destroy`. Triggers: `ui`, `cli`, `webhook`, `schedule`.

- A worker takes the instance's lock **before** it marks a job `running`, so `running` means someone is really working.
- A job that finds its instance busy goes back to the queue after 3 seconds and stays `queued`. After **30 minutes** of
  waiting for a busy instance it fails with "gave up waiting".
- When a worker or the web app dies (a kill, a reboot), the job is marked `interrupted` within about ten seconds because its
  lock is free. A queued job whose message was lost is failed 10 minutes after the limit above.
- **Retry** creates a new job that points back at the old one. Retrying a failed deploy creates a redeploy.

## Deploying

In the browser: **Instances > Deploy** (administrators). Pick a project, a template, a branch, a label and a count, then
**Deploy**. The instance appears in the list at once as `deploying`.

On the host:

```text
fleet instance:deploy acme
fleet instance:deploy acme --branch=feature/login --label=login
fleet instance:deploy acme --count=3 --parallel
```

- **Auto-suffix.** A label already used by an instance, or by a directory on disk that holds a `.git`, gets a numeric
  suffix. With `--count` the labels are numbered. Two simultaneous requests for one label never get the same one.
- **Count.** At most 20 instances per request. `--count=0` does nothing.
- **Sequential or parallel.** A batch of several instances deploys one after another unless `--parallel` is given (or the
  form's "at the same time" box is ticked). A failed member never stops the rest of the batch.
- **Disk check.** A batch is refused when less than 10 % of the disk is free; `--skip-disk-check` skips it.
- **Leftovers.** If a checkout of the same name exists on disk without an instance, the deploy refuses; `--force` replaces
  it.

A deploy clones the branch, writes the instance's DDEV configuration and Drupal settings, substitutes the `[[token]]`
placeholders (see [`secrets.md`](secrets.md)), starts the project, runs the template's post-deploy commands, writes the
Caddy site for `https://<id>.<domain>` and reloads Caddy.

**Post-deploy commands run on the host, as arguments, never through a shell.** Quotes and backslashes group words; an
unquoted `|`, `&`, `;`, `<`, `>`, `$`, `` ` ``, `(`, `)`, `{` or `}` is refused with a hint to write `ddev exec '...'`. A
`[[token]]` is substituted after splitting, inside each word, from the built-in tokens and then the project's and
fleet-wide secrets (see [`secrets.md`](secrets.md#placeholders) for every substitution site). An unresolved token fails
the deploy before anything is changed. A secret that lands in an argument is masked in the job log. Secrets are not
substituted in `tty1`/`tty2` commands.

## Start, stop, redeploy, destroy

| Action | What it does | Who |
|---|---|---|
| Start | `ddev start`. A port conflict can be retried once with `--retry-port-conflict`. | administrators and project members |
| Stop | `ddev stop`; frees the memory, keeps disk. | administrators and project members |
| Redeploy | Removes the checkout and deploys the branch again, with the same label (and optionally another `--template`). | administrators and project members |
| Destroy | Deletes the DDEV project, its database and the checkout, then the row. | administrators only |

`start`, `stop`, `redeploy` and `destroy` accept several ids, `--all`, `--project=<name>` or `--state=<state>`. A selector or
several ids ask for confirmation (type the number of instances) or `--yes`; without a terminal they are refused without
`--yes`. Bulk destroy and redeploy run one instance at a time; `--sequential` does the same for start and stop. In the
browser, tick instances in the list and use the bulk bar.

### Destroy and leftovers

A failing `ddev delete` is tolerated (the project may already be gone). The checkout is removed with three attempts. Files
owned by root (a container wrote them) cannot be removed by the `fleet` user: the job fails with the exact
`sudo rm -rf <directory>` line to run, and the instance becomes `failed`. Run it, then **Retry**: a missing directory counts
as success, so retrying is safe.

## Who may do what

| | Administrator | Project member |
|---|---|---|
| See the list, open an instance, read logs | all | their projects' instances and jobs |
| Start, stop, redeploy and retry those | yes | yes |
| Deploy, destroy | yes | no |

An instance or job a user may not operate answers 404, as if it did not exist. Members cannot open instance *sites* yet:
until per-project access rules are rendered (users and access, milestone M0.10) the Authelia rule for `*.<domain>` admits
administrators only.

## Pages

| Page | Path | Shows |
|---|---|---|
| Instances | `/instances` | The list: state, branch and head, template, memory, the active job; filter and bulk actions. |
| Deploy | `/instances/new` | The deploy form (administrators). |
| Instance | `/instances/<id>` | Details, the buttons above, and its jobs. |
| Job | `/jobs/<job>` | Status, times, the error, Retry and the live log. |
| Job history | `/admin/jobs` | Every job, newest first, 50 a page (administrators). |

All pages work at 390 px: 44 px targets, no horizontal scroll.

### The live log

The job page shows the log as it is written. The hub (Mercure) only says "there is news": its message carries the job id,
its status and the log size, on private topics `/jobs/<job>` and `/instances`. The text itself is fetched from
`/jobs/<job>/log?from=<byte>` in chunks of at most 64 KiB, so a page opened late, or after the job ended, shows the whole
log. When the hub is not reachable the page says so and polls instead (the log every 2 seconds, the list every 5). A hub
outage never fails a job. Secret values a deploy uses are masked in the log (`[masked]`; see `docs/secrets.md`).

## Commands

Run on the host as `fleet instance:<verb>` or `fleet job:<verb>` (`fleet instance deploy` also works). Every command takes
`--format=table|json` (`--json` is the same). The commands that change something run **attached** by default: the job runs
in the command's own process, one after another, so you see the log and the browser shows it live; `--detach` only queues
the jobs and returns. `Ctrl-C` marks the running job `interrupted` and exits with 130. These jobs have the trigger `cli`
and the requester `cli:<user>`.

### Instances

| Command | Does |
|---|---|
| `fleet instance:deploy <project> [<template>]` | Deploys one or more instances (see above). |
| `fleet instance:redeploy [<id>...]` | Rebuilds from a fresh checkout. |
| `fleet instance:start [<id>...]` | Starts stopped instances. |
| `fleet instance:stop [<id>...]` | Stops running instances. |
| `fleet instance:destroy [<id>...]` | Destroys instances. |
| `fleet instance:list` | Lists instances. |
| `fleet instance:log <id>` | Prints the log of the instance's latest job. |
| `fleet instance:watch` | The status watcher loop (the `fleet-status` unit runs it). |

| Option | Applies to | Meaning |
|---|---|---|
| `--branch=<name>` | deploy | Branch (default: the project's default branch). |
| `--label=<name>` | deploy | Label of the instance (default: the branch). |
| `--count=<n>` | deploy | How many instances (default 1, at most 20). |
| `--parallel` | deploy | Deploy a batch concurrently. |
| `--force` | deploy, redeploy | Replace a leftover checkout. |
| `--skip-disk-check` | deploy | Skip the free-disk check of a batch. |
| `--template=<name>` | redeploy | Redeploy with another template. |
| `--all`, `--project=<name>`, `--state=<state>` | redeploy, start, stop, destroy, list (`--project`, `--state`) | Select instances without naming them. |
| `--yes`, `-y` | redeploy, destroy | Confirm a selector or several ids. |
| `--sequential` | start, stop | One instance at a time. |
| `--timeout=<seconds>` | start, stop | Seconds `ddev` may take. |
| `--retry-port-conflict` | start | Stop and start again once when `ddev` reports a port conflict. |
| `--detach` | deploy, redeploy, start, stop, destroy | Only queue the jobs. |
| `--job=<n>`, `--follow`, `-f` | log | Another job of the instance; keep printing until it ends. |
| `--interval=<seconds>`, `--once` | watch | Seconds between ticks (default 10); one tick and exit. |
| `--format=table\|json`, `--json` | all but watch | Output format. |

### Jobs

| Command | Does |
|---|---|
| `fleet job:list` | Lists jobs, newest first. |
| `fleet job:show <id>` | Shows one job. |
| `fleet job:retry <id>` | Repeats a failed or interrupted job as a new job. |
| `fleet job:prune` | Deletes finished jobs and their logs past the retention. |

| Option | Applies to | Meaning |
|---|---|---|
| `--status=<status>`, `--trigger=<trigger>`, `--instance=<id>`, `--limit=<n>` | list | Filters; limit defaults to 50. |
| `--days=<n>` | prune | Keep jobs finished within this many days. |
| `--format=table\|json`, `--json` | list, show, retry | Output format. |

### Exit codes and JSON

| Code | Meaning |
|---|---|
| `0` | Done (for a batch: every job succeeded). |
| `1` | A value was refused, or every job of the request failed. |
| `2` | Not allowed now (an unconfirmed bulk request, a busy or refused instance), or some jobs of a batch failed. |
| `3` | Not found (an instance or job id). |

An error in `--json` mode is `{"error": "...", "fields": {"--count": "..."}}`, keyed by the option or `<argument>`
spelling. `130` is returned after `Ctrl-C`.

## Retention

Finished jobs and their logs are kept for **90 days** (`FLEET_JOB_RETENTION_DAYS` in `/etc/ddev-fleet/fleet.env`). The
status watcher prunes once a day; `fleet job:prune --days=<n>` does it on demand. Instances are never pruned.

## What differs from 0.x

- The instance list lives in the database; there is no `.fleet/instance.yml` and no registry file to edit.
- A label clash is settled when you ask, not by a lock held during the deploy.
- Jobs survive a restart of the web app, are listed with their history and can be retried; 0.x logs were a stream.
- Post-deploy commands run on the host without a shell (0.x ran them the same way; the first 1.x guide wrongly said they ran
  through `ddev exec`).
- Not yet in 1.x: the branch picker and the live-branch indicator, ports, Typesense, webhook and schedule *triggers* of
  deploys beyond the recorded decisions, tmux panes and `fleet shell`.

## Troubleshooting

| Symptom | What to do |
|---|---|
| A job is `interrupted` | A worker or the host stopped while it ran. Read its log, then **Retry**. |
| A job stays `queued` | Another job holds the instance (it waits up to 30 minutes), or no worker runs: `systemctl status fleet-worker@1`. |
| "port is already allocated" at start | Another project uses the port. Stop it, or start with `--retry-port-conflict`. |
| Destroy fails with a `sudo rm -rf` line | Root-owned files from a container. Run that exact line on the host, then Retry. |
| The deploy fails after the start | The post-deploy line failed or a `[[token]]` was unresolved; the log shows the line. |
| The instance runs but its site does not open | Caddy refused the generated site: `journalctl -u caddy -n 50`; the job log names the validation error. Members cannot open instance sites yet. |
| The live log says "polling" | The Mercure hub is down: `systemctl status fleet-mercure`. The log still follows, every 2 seconds. |
| After a reboot nothing runs | `fleet-boot` starts the instances one at a time; see `journalctl -u fleet-boot`. |
