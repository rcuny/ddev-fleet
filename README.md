# ddev-fleet

A fleet manager for running many independent DDEV-based projects (mainly
Drupal, not exclusively) in parallel on one bare-metal host, with minimal
human interaction. It provides a registry (`fleet.yml`), a CLI that
clones/configures/starts/tears down instances, centralized asset storage
(DB dumps, `.env` files, local settings) shared across a project's
instances, and — in a later phase — a web UI and TLS/routing for every
instance under one wildcard domain.

Full design: see the companion repo's
`.claude/user/docs/specs/2026-07-02-ddev-fleet-v1-design.md`.

## Quickstart (server provisioning)

```bash
# Repo is PRIVATE — the bare raw-URL one-liner is auth-gated. Either:
#   (a) scp bootstrap.sh to the server, then: sudo bash bootstrap.sh
#       (export FLEET_REPO_URL=https://<user>:<app-password>@bitbucket.org/personal_maintainer/ddev-fleet.git first), or
#   (b) pre-clone the repo to /opt/ddev-fleet manually, then: sudo bash /opt/ddev-fleet/bootstrap.sh
# public-repo variant: curl -fsSL https://bitbucket.org/personal_maintainer/ddev-fleet/raw/main/bootstrap.sh | sudo bash
```

This installs Docker, DDEV, Caddy, and the `fleet` system user and Python
package, and enables (but does not start) the `fleet.service` systemd
unit. See `docs/runbook-server-rollout.md` for the full first-rollout
checklist (DNS, admin password, deploy key, Claude token).

## CLI usage

Projects and their deploy `templates` (post_deploy recipes) are declared by
hand in `fleet.yml` — the registry is declarative and read-only at runtime;
there is no `fleet project add` and no auto-registration on deploy.

| Command | Arguments | Behavior |
|---|---|---|
| `fleet init` | — | Interactive: fleet domain, admin credentials, `claude setup-token`, writes `.secrets` |
| `fleet deploy <project> [<template>] --branch <ref>` | `[--label=<name>] [--fresh] [--force]` | Full deploy pipeline; running instance is named `<project>--<label>` (label defaults to the slugified branch) |
| `fleet destroy <instance-id>` | — | Tears down containers, removes instance dir + lock file |
| `fleet start <instance-id>` | — | `ddev start` on an existing, stopped instance |
| `fleet stop <instance-id>` | — | `ddev stop` — frees RAM, keeps disk |
| `fleet list` | — | Table: id, project, branch, state, URL, RAM |
| `fleet ssh-key` | — | Prints the fleet deploy public key |
| `fleet refresh-claude-token` | — | Rotates the Claude Code OAuth token fleet-wide |
| `fleet refresh-config` | — | Git-aware pull of `/srv/fleet/config` (the `fleet.yml` registry + assets checkout) |
| `fleet assets push <project> <src> <dest-rel>` | — | Copies a local file into `assets/<project>/<dest-rel>` |
| `fleet snapshot <instance-id>` | `[--dest-rel=dumps/db.sql.gz]` | Runs `ddev export-db` into the project's asset tree |

## Web UI

Once `fleet.service` is running (see `docs/runbook-server-rollout.md`),
browse to `https://fleet.<domain>` for the web UI: an instance list (id,
project, branch, state, URL, RAM) with per-row Start/Stop/Destroy actions,
a deploy form (project/template/branch/label/fresh), and a live deploy log
streamed over WebSocket while a deploy job runs. The UI has no login of
its own — Caddy's `basic_auth` in front of `fleet.<domain>` is the single
auth layer (spec §13); the daemon itself binds `127.0.0.1:8765` only and
is unreachable except through Caddy.

## Repository layout

- `src/fleet/` — the Python package (core library + CLI + daemon stub)
- `ansible/` — provisioning playbook (roles: base, docker, fleet_user, ddev, caddy, fleet_service)
- `bootstrap.sh` — one-shot installer entry point
- `docs/` — operational runbooks
- `fleet.yml.dist` — example registry (project/template skeleton) — see the design spec §4; the live registry is edited directly at `/srv/fleet/config/fleet.yml` on the server, not committed to this repo
- `assets/`, `instances/` — per-project asset trees and deployed instance checkouts (gitignored)
