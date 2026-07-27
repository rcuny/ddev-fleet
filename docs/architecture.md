---
Author: Claude Code
Reviewer: none
Last updated: 2026-07-25
Type: documentation
---

# Architecture

## Runs natively, not inside DDEV

The fleet manager runs **natively on the host** — never inside
DDEV/Docker itself — so it can keep managing (and recovering) other DDEV
projects even if one of them, or Docker, is unhealthy.

```
Bare-metal / VPS host
├── fleet.service (systemd, User=fleet, 127.0.0.1:8765)
│   ├── FastAPI + uvicorn daemon — web UI (Jinja2 + HTMX) + JSON/WS API
│   └── fleet.core (Python library) — deploy/destroy/start/stop/list, driven
│       directly by the CLI too, so the CLI never depends on the daemon
├── Caddy (systemd) — public TLS, the only externally reachable process
│   ├── fleet.<domain>            → basic_auth → reverse_proxy 127.0.0.1:8765 (web UI)
│   ├── *.<domain>                → on-demand TLS → optional per-instance basic_auth → reverse_proxy 127.0.0.1:8080 (ddev-router HTTP)
│   └── *.<domain>:9108           → on-demand TLS → reverse_proxy 127.0.0.1:8108 (ddev-router, Typesense)
└── Docker (DDEV)
    ├── ddev-router (shared Traefik) — HTTP/HTTPS entrypoints, loopback-only
    ├── project1--main    (PHP · MariaDB · … containers)
    ├── project1--feature-xyz
    └── × N more instances, one DDEV project per `<project>--<label>`
```

Provisioning is Ansible (`ansible/site.yml`, roles `base`, `docker`,
`fleet_user`, `shell_profile`, `ddev`, `claude_cli`, `caddy`,
`fleet_service`) — see `docs/installation.md`.

## Module map (`src/fleet/`)

| File | Responsibility |
|---|---|
| `cli.py` | Thin argparse CLI (`fleet …`); calls straight into `fleet.core`, never depends on the daemon |
| `daemon.py` | FastAPI app: `/api/tls-authorize` (Caddy on-demand TLS callback), `/api/jobs/{id}`, `/ws/instances/{id}/log` (HMAC-token-gated WebSocket), `/` + `/ui/*` HTMX routes for the web UI |
| `jobs.py` | In-memory `JobManager` backing the web UI's async deploy jobs |
| `core/registry.py` | Loads/validates `fleet.yml` (`Registry`), resolves `(project, template, branch, label)` → `ResolvedInstance` |
| `core/instances.py` | Orchestrates `deploy`/`destroy`/`start`/`stop`/`list_instances`/`snapshot`; `FleetPaths` maps `FLEET_HOME` to all on-disk paths |
| `core/fleetconfig.py` | Writes the fleet-owned per-instance `.ddev/config.fleet.yaml`, `.ddev/.env`, `settings.local.php`/`services.fleet.yml` |
| `core/gitops.py` | `clone`/`update` of an instance's git worktree |
| `core/ddev.py` | Subprocess wrappers: `start`/`stop`/`restart`/`delete`/`list_projects`/`ram_usage` |
| `core/assets.py` | rsync-mirrors a project's asset tree into an instance, then runs the `[[token]]` substitution pass |
| `core/tokens.py` | The `[[token]]` substitution engine and `FLEET_*` env var derivation for `post_deploy` |
| `core/secrets.py` | Read/write `KEY=VALUE` files (0600) |
| `core/typesense.py` | Per-project Typesense admin+search-only key generation/registration |
| `core/caddyauth.py` | Rotates the Caddy dashboard/per-instance `basic_auth` password without an Ansible run |
| `core/locks.py` | Per-instance `flock`-based locking |
| `core/naming.py` | Validates project/template/label parts, composes `<project>--<label>` instance ids |
| `core/sysinfo.py` | Host stats for the web UI footer |
| `core/errors.py` | `FleetError` hierarchy — every user-facing failure carries an actionable `.message` |

## The daemon/CLI split

The CLI is a thin wrapper over `fleet.core` — it never calls the daemon.
This means every operation (`deploy`, `destroy`, `list`, ...) works from
the command line even if the daemon/web UI is down; the daemon exists
purely to provide the web UI and JSON/WebSocket API over the same
`fleet.core` functions.

## See also

- `docs/installation.md` / `docs/operations.md` — provisioning and
  day-to-day operation.
- `docs/networking.md` — full network topology, every port in one table.
- `docs/configuration.md` — the `fleet.yml` registry schema.
- `CONTRIBUTING.md` — dev setup and code style.
