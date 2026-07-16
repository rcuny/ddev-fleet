---
Author: Claude Code
Reviewer: none
Last updated: 2026-07-16
Type: documentation
---

# ddev-fleet

A fleet manager for running many independent DDEV-based projects (mainly
Drupal, not exclusively) in parallel on one bare-metal host, with minimal
human interaction. It provides a registry (`fleet.yml`), a CLI that
clones/configures/starts/tears down instances, centralized per-project
secrets and assets (DB dumps, `.env` values, local settings) shared across
a project's instances, and a web UI with TLS/routing for every instance
under one wildcard domain.

**Status:** live in production on a Kimsufi KS-7 host
(`ddev.personal.example`, Debian 13), serving instances under the
`fleet.personal.example` wildcard domain.

Full design: see the companion repo's
`.claude/user/docs/specs/2026-07-02-ddev-fleet-v1-design.md`.

## Architecture

The fleet manager runs **natively on the host** — never inside DDEV/Docker
itself — so it can keep managing (and recovering) other DDEV projects even
if one of them, or Docker, is unhealthy.

```
Kimsufi host (bare metal)
├── fleet.service (systemd, User=fleet, 127.0.0.1:8765)
│   ├── FastAPI + uvicorn daemon — web UI (Jinja2 + HTMX) + JSON/WS API
│   └── fleet.core (Python library) — deploy/destroy/start/stop/list, driven
│       directly by the CLI too, so the CLI never depends on the daemon
├── Caddy (systemd) — public TLS, the only externally reachable process
│   ├── fleet.<domain>            → basic_auth → reverse_proxy 127.0.0.1:8765 (web UI)
│   ├── *.<domain>                → on-demand TLS → reverse_proxy 127.0.0.1:8080 (ddev-router HTTP)
│   └── *.<domain>:9108           → on-demand TLS → reverse_proxy 127.0.0.1:8108 (ddev-router, Typesense)
└── Docker (DDEV)
    ├── ddev-router (shared Traefik) — HTTP/HTTPS entrypoints, loopback-only
    ├── project1--main    (PHP · MariaDB · … containers)
    ├── project1--feature-xyz
    └── × N more instances, one DDEV project per `<project>--<label>`
```

Provisioning is Ansible (`ansible/site.yml`, roles `base`, `docker`,
`fleet_user`, `ddev`, `claude_cli`, `caddy`, `fleet_service`) — see
Quickstart below and `docs/runbook-server-rollout.md` for the full
first-rollout checklist.

## Quickstart (server provisioning)

```bash
# Repo is PRIVATE — the bare raw-URL one-liner is auth-gated. Either:
#   (a) scp bootstrap.sh to the server, then: sudo bash bootstrap.sh
#       (export FLEET_REPO_URL=https://<user>:<app-password>@bitbucket.org/personal_maintainer/ddev-fleet.git first), or
#   (b) pre-clone the repo to /opt/ddev-fleet manually, then: sudo bash /opt/ddev-fleet/bootstrap.sh
# public-repo variant: curl -fsSL https://bitbucket.org/personal_maintainer/ddev-fleet/raw/main/bootstrap.sh | sudo bash
```

`bootstrap.sh` installs git + Ansible, then runs `ansible/site.yml`, which
installs Docker, DDEV, Caddy, and the `fleet` system user and Python
package (editable install into a venv at `/opt/ddev-fleet/venv`), and
enables (but does not start) the `fleet.service` systemd unit. See
`docs/runbook-server-rollout.md` for the full first-rollout checklist (DNS,
admin password, deploy key, Claude token) and §2a for how to ship a code
update to an already-live host (git-pull based, no full playbook re-run).

## On-server layout

```
/opt/ddev-fleet/            # product code — fleet-owned git checkout of ddev-fleet.git
├── venv/                   # editable pip install; a `fleet.service` restart picks up new code
└── (this repo's tree)

/srv/fleet/                 # fleet's runtime home (FLEET_HOME)
├── config/                 # git checkout of the private ddev-fleet-config repo:
│   ├── fleet.yml           #   the project/template registry (edited by hand, read-only at runtime)
│   └── assets/<project>/   #   per-project DB dumps, .env templates, local-settings templates
├── secrets/<project>.env   # per-project secrets (KEY=VALUE, mode 0600) — `fleet secret set`
├── instances/<id>/         # deployed git worktrees, one per `<project>--<label>`
├── locks/                  # per-instance flock files (concurrency safety)
├── .secrets                # CLAUDE_CODE_OAUTH_TOKEN etc. (mode 0600)
└── .push-key/              # fleet's read-write git push key (kept out of default SSH search path)
```

## Operator CLI

Projects and their deploy `templates` (post_deploy recipes) are declared by
hand in `fleet.yml` — the registry is declarative and read-only at runtime;
there is no `fleet project add` and no auto-registration on deploy. On the
server, `/usr/local/bin/fleet` (installed by the `fleet_service` Ansible
role) execs the venv CLI as the `fleet` user, so any admin account can run
these without `sudo -u fleet` or the venv path.

| Command | Arguments | Behavior |
|---|---|---|
| `fleet init` | `[--domain=...] [--skip-claude]` | Interactive: fleet domain, `claude setup-token`, writes `.secrets` |
| `fleet deploy <project> [<template>] --branch <ref>` | `[--label=<name>] [--fresh] [--force]` | Full deploy pipeline; running instance is named `<project>--<label>` (label defaults to the slugified branch); `template`/`--branch` fall back to the project's `default_template`/`default_branch` when omitted |
| `fleet destroy <instance-id>` | — | Tears down containers, removes instance dir + lock file |
| `fleet start <instance-id>` | — | `ddev start` on an existing, stopped instance |
| `fleet stop <instance-id>` | — | `ddev stop` — frees RAM, keeps disk |
| `fleet list` | — | Table: id, project, branch, state, URL, RAM |
| `fleet ssh-key` | — | Prints the fleet deploy (read-only) public key |
| `fleet secret set <project> <key> <value>` | — | Writes `KEY=VALUE` into `/srv/fleet/secrets/<project>.env` (0600); values are available to deploy as `[[key-with-dashes]]` tokens |
| `fleet assets push <project> <src> <dest-rel>` | — | Copies a local file into `assets/<project>/<dest-rel>` |
| `fleet snapshot <instance-id>` | `[--dest-rel=dumps/db.sql.gz]` | Runs `ddev export-db` into the project's asset tree |
| `fleet refresh-claude-token` | — | Rotates the Claude Code OAuth token fleet-wide, rewrites every instance's `config.fleet.yaml`, restarts running instances |
| `fleet refresh-config` | — | Git-aware pull of `/srv/fleet/config` (the `fleet.yml` registry + assets checkout) |

## Web UI

Once `fleet.service` is running (see `docs/runbook-server-rollout.md`),
browse to `https://fleet.<domain>` for the web UI: an instance list (id,
project, branch, state, URL, RAM) with per-row Start/Stop/Destroy actions,
a deploy form (project/template/branch/label/fresh), and a live deploy log
streamed over WebSocket while a deploy job runs. The UI has no login of
its own — Caddy's `basic_auth` in front of `fleet.<domain>` is the single
auth layer; the daemon itself binds `127.0.0.1:8765` only and is
unreachable except through Caddy.

## Repository layout

- `src/fleet/` — the Python package: `core/` (deploy engine — registry,
  gitops, ddev wrappers, secrets, tokens, typesense, locks, naming, assets),
  `cli.py` (argparse CLI), `daemon.py` (FastAPI app), `jobs.py` (in-memory
  job registry for the web UI), `templates/` + `static/` (Jinja2/HTMX UI)
- `ansible/` — provisioning playbook (`site.yml`; roles: `base`, `docker`,
  `fleet_user`, `ddev`, `claude_cli`, `caddy`, `fleet_service`)
- `bootstrap.sh` — one-shot installer entry point
- `tests/` — pytest suite (unit + FastAPI `TestClient`/`httpx` tests)
- `docs/` — operational runbooks and reference docs:
  - `docs/runbook-server-rollout.md` — ordered first-rollout checklist and
    the ongoing code-update procedure (§2a)
  - `docs/srv-fleet-CLAUDE.md` — the registry/CLI reference copied onto the
    server for `claude -p "..."` sessions run there
  - `docs/README-typesense.md` — the Typesense browser-search exposure
    (port-based topology, keys, env injection)
- `fleet.yml.dist` — example registry (project/template skeleton); the live
  registry is edited directly at `/srv/fleet/config/fleet.yml` on the
  server, not committed to this repo
- `assets/`, `instances/` — per-project asset trees and deployed instance
  checkouts (gitignored; these directories mirror `/srv/fleet/` locally but
  are not used by the live server, which has its own `/srv/fleet/`)

## Docs / pointers

- Full first-rollout checklist and the ongoing code-update procedure:
  `docs/runbook-server-rollout.md`.
- Typesense browser-search exposure (port-based topology, admin vs.
  search-only keys, env injection): `docs/README-typesense.md`.
