---
Author: Claude Code
Reviewer: none
Last updated: 2026-09-25
Type: documentation
---

# ddev-fleet

[![Licence: MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

A fleet manager for running many independent DDEV-based projects (mainly
Drupal, not exclusively) in parallel on one bare-metal host, with minimal
human interaction. It provides a registry (`fleet.yml`), a CLI that
clones/configures/starts/tears down instances (singly or in bulk),
centralized per-project secrets and assets (DB dumps, `.env` values, local
settings) shared across a project's instances, and a web UI with
TLS/routing for every instance under one wildcard domain.

**Status:** runs on any Debian-family bare-metal or VPS host.

## Architecture

The fleet manager runs **natively on the host** — never inside DDEV/Docker
itself — so it can keep managing (and recovering) other DDEV projects even
if one of them, or Docker, is unhealthy. Caddy is the only
Internet-facing process; it terminates TLS and proxies to a shared,
loopback-only DDEV router, which routes to the right instance's
containers by Host header.

```
Bare-metal / VPS host
├── fleet.service (systemd, User=fleet, 127.0.0.1:8765)
│   ├── FastAPI + uvicorn daemon — web UI (Jinja2 + HTMX) + JSON/WS API
│   └── fleet.core (Python library) — deploy/destroy/start/stop/list, driven
│       directly by the CLI too, so the CLI never depends on the daemon
├── Caddy (systemd) — public TLS, the only externally reachable process
│   ├── fleet.<domain>            → basic_auth → reverse_proxy 127.0.0.1:8765 (web UI)
│   ├── *.<domain>                → on-demand TLS → optional per-instance basic_auth → reverse_proxy 127.0.0.1:8080 (ddev-router HTTP)
│   └── *.<domain>:<port>         → on-demand TLS → reverse_proxy 127.0.0.1:<router-port>, one per fleet.yml-registered named port (Typesense, Playwright reports, ...) — see docs/networking.md
└── Docker (DDEV)
    ├── ddev-router (shared Traefik) — HTTP/HTTPS entrypoints, loopback-only
    ├── project1--main    (PHP · MariaDB · … containers)
    ├── project1--feature-xyz
    └── × N more instances, one DDEV project per `<project>--<label>`
```

Full design, module map, and the daemon/CLI split: `docs/architecture.md`.

## Quickstart

```bash
curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/main/bootstrap.sh | sudo bash
```

`bootstrap.sh` installs git + Ansible, then runs `ansible/site.yml`, which
installs Docker, DDEV, Caddy, and the `fleet` system user and Python
package (editable install into a venv at `/opt/ddev-fleet/venv`), and
enables (but does not start) the `fleet.service` systemd unit. From
there: DNS, adding the deploy key, `fleet init`, and the first deploy are
covered end to end in **`docs/installation.md`** — start there for a new
install. For updating an already-live host, see `docs/operations.md`.

## Network exposure

Public traffic reaches every instance through Caddy, the only
Internet-facing process: it terminates TLS (on-demand certs) and proxies
to a shared, loopback-only DDEV router, which routes by Host header to
the right instance's containers — this happens automatically for every
instance's main site, no configuration needed. Extra per-project ports
(Typesense, a Playwright report port, a search dashboard, ...) are
opt-in: declare them once in `fleet.yml`'s `fleet.ports` catalogue and
list the names a project subscribes to in its own `ports:` key, then run
`fleet refresh-ports` to apply — no Ansible re-run, no redeploy. See
`docs/networking.md` for the full topology, every port in one table, and
the exact steps to add a new one.

## Security note: default credentials

`bootstrap.sh` always sets a real dashboard password on first
provisioning — either one you supplied or a randomly generated one,
printed once. There is no fixed public default to rotate away from.
Per-instance basic auth (separate from the dashboard) is **on by default**
on every `fleet deploy`, user `fleet` / password `fleet` unless
overridden with `--auth-password` or disabled with `--no-auth`. The
credential is symmetric: `--auth-password=fern` gives user `fern` /
password `fern`. Where a network's own policy blocks HTTP basic auth
outright (it looks like a server error to the client, not a login
prompt), `fleet.auth_bypass_cidrs` in `fleet.yml` lists CIDR ranges that
skip the prompt entirely — everyone else still gets it; see
`docs/configuration.md`. Rotate the
dashboard password any time, with no Ansible run required:

```bash
fleet rotate-admin-password   # generates a strong random password, applies it, prints it ONCE
```

Full detail (both credential types, the underlying mechanism): `docs/operations.md`.

## Repository layout

- `src/fleet/` — the Python package: `core/` (deploy engine — registry,
  gitops, ddev wrappers, secrets, tokens, typesense, bulk orchestration,
  caddy port/auth reconciliation, locks, naming, assets), `cli.py`
  (argparse CLI), `daemon.py` (FastAPI app), `jobs.py` (in-memory job
  registry for the web UI), `templates/` + `static/` (Jinja2/HTMX UI)
- `ansible/` — provisioning playbook (`site.yml` and its roles)
- `bootstrap.sh` — one-shot installer entry point
- `tests/` — pytest suite (unit + FastAPI `TestClient`/`httpx` tests)
- `fleet.yml.dist` — example registry (project/template/ports skeleton);
  the live registry is edited at `<config-repo>/fleet.yml` or
  `/srv/fleet/config/fleet.yml`, not committed to this repo
- `docs/` — the full documentation set, linked below

## Documentation

| Doc | Covers |
|---|---|
| `docs/installation.md` | First-run checklist: DNS, the installer, deploy key, `fleet init`, first deploy, web UI check |
| `docs/operations.md` | Ongoing procedures: code updates, rollback, credential rotation, bulk operations, verification checklist |
| `docs/configuration.md` | The `fleet.yml` registry schema, field by field |
| `docs/cli.md` | The full `fleet` CLI command reference, with examples |
| `docs/networking.md` | Full network topology, every port in one table, and how to expose a new one |
| `docs/architecture.md` | Native-not-in-DDEV design, module map, the daemon/CLI split |
| `docs/README-typesense.md` | The Typesense browser-search exposure worked example |
| `CONTRIBUTING.md` | Dev setup, code style, and how to submit a change |
| `CHANGELOG.md` | Release history |

Licence: [MIT](LICENSE).
