---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-08
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
│   ├── *.<domain>                → TLS (on_demand OR ovh_dns wildcard — fleet_tls_mode) → optional per-instance basic_auth → reverse_proxy 127.0.0.1:8080 (ddev-router HTTP)
│   └── *.<domain>:9108           → TLS (same tls.conf snippet) → reverse_proxy 127.0.0.1:8108 (ddev-router, Typesense)
└── Docker (DDEV)
    ├── ddev-router (shared Traefik) — HTTP/HTTPS entrypoints, loopback-only
    ├── project1--main    (PHP · MariaDB · … containers)
    ├── project1--feature-xyz
    └── × N more instances, one DDEV project per `<project>--<label>`
```

Provisioning is Ansible (`ansible/site.yml`, roles `base`, `docker`,
`fleet_user`, `shell_profile`, `ddev`, `claude_cli`, `caddy`,
`fleet_service`) — see `docs/installation.md`. The `caddy` role also
renders the shared `tls.conf` snippet per `fleet_tls_mode`
(`on_demand`/`ovh_dns` — see `docs/networking.md` §4), and `ansible/
caddy-only.yml` reapplies just that role on a live host.

## Module map (`src/fleet/`)

| File | Responsibility |
|---|---|
| `cli.py` | Thin argparse CLI (`fleet …`); calls straight into `fleet.core`, never depends on the daemon |
| `daemon.py` | FastAPI app: `/api/tls-authorize` (Caddy on-demand TLS callback), `/api/jobs/{id}`, `/ws/instances/{id}/log` (HMAC-token-gated WebSocket), `/` + `/ui/*` HTMX routes for the web UI |
| `websecurity.py` | Pure helpers for the dashboard's strict Content-Security-Policy and the same-origin check on `/ui/*` writes (wired in by a middleware in `daemon.py`) |
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
| `core/hostinfo.py` | Server hostname (web UI title/heading) and Fleet version from `git describe --tags` (web UI footer) |
| `core/errors.py` | `FleetError` hierarchy — every user-facing failure carries an actionable `.message` |

## Web UI hardening: CSP and same-origin check

The dashboard sits behind Caddy (basic auth or Authelia), so it is protected
against the *browser* of an authenticated admin being tricked, not just
against anonymous callers. One HTTP middleware in `daemon.py` (policy in
`websecurity.py`) does two things:

**Headers on every `text/html` response** (pages, htmx fragments, error
fragments): `Content-Security-Policy: default-src 'self'; script-src 'self';
style-src 'self'; img-src 'self' data:; connect-src 'self' wss://<host>;
object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action
'self'`, plus `X-Content-Type-Options: nosniff` and `Referrer-Policy:
no-referrer`. `<host>` is the request's `Host` (only if it is a plain
host[:port]). JSON, static files and plain-text logs are not HTML and get none
of them. The consequence for templates: **no inline `<script>`, `<style>`,
`style=`, `on*=` handlers or `hx-on`** — styles live in `static/fleet.css`,
scripts in `static/*.js`, and `base.html` carries `<meta name="htmx-config"
content='{"includeIndicatorStyles":false,"allowEval":false}'>` so htmx neither
injects a `<style>` nor evaluates strings. `tests/test_csp_templates.py` fails
the build if a template reintroduces any of them.

**Same-origin rule for state-changing `/ui/*` requests** (every method except
GET/HEAD/OPTIONS): if `Sec-Fetch-Site` is sent, only `same-origin` passes
(`same-site` is refused on purpose: DDEV instances on `*.<domain>` are
same-site siblings running third-party code); otherwise, if `Origin` is sent,
its host[:port] must equal `Host` (`Origin: null` or a malformed `Origin` is refused); a request with
neither header is not a browser cross-site request and is allowed. A refusal is
HTTP 403 — as a renderable error fragment for htmx requests, JSON otherwise.
`/hooks/*` (HMAC-authenticated webhooks), `/api/*`, `/static/*` and the
WebSocket are never subject to this check. Behind a reverse proxy the proxy
must keep the original `Host` (Caddy's `reverse_proxy` does by default).

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
