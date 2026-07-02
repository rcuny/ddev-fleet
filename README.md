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

| Command | Arguments | Behavior |
|---|---|---|
| `fleet init` | — | Interactive: fleet domain, admin credentials, `claude setup-token`, writes `.secrets` |
| `fleet deploy <project> <instance>` | `[--branch=<ref>] [--fresh] [--force]` | Full deploy pipeline; auto-registers unknown instances (`--branch` required then) |
| `fleet destroy <instance-id>` | — | Tears down containers, removes instance dir + lock file |
| `fleet start <instance-id>` | — | `ddev start` on an existing, stopped instance |
| `fleet stop <instance-id>` | — | `ddev stop` — frees RAM, keeps disk |
| `fleet list` | — | Table: id, project, branch, state, URL, RAM |
| `fleet project add <key>` | `--git=<url> [--post-deploy=...]` | Registers a new project in `fleet.yml` |
| `fleet ssh-key` | — | Prints the fleet deploy public key |
| `fleet refresh-claude-token` | — | Rotates the Claude Code OAuth token fleet-wide |

## Repository layout

- `src/fleet/` — the Python package (core library + CLI + daemon stub)
- `ansible/` — provisioning playbook (roles: base, docker, fleet_user, ddev, caddy, fleet_service)
- `bootstrap.sh` — one-shot installer entry point
- `docs/` — operational runbooks
- `fleet.yml` — the live registry (not a template — see the design spec §4)
- `assets/`, `instances/` — per-project asset trees and deployed instance checkouts (gitignored)
