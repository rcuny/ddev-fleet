---
Author: Claude Code
Reviewer: none
Last updated: 2026-07-24
Type: documentation
---

# Fleet networking — topology, ports, and how to add one

How traffic reaches a deployed instance, every port the fleet manages, and
the exact steps to expose a new one. Generalizes `docs/README-typesense.md`'s
topology (kept as the worked example) to any named port, per
`2026-07-24-fleet-port-exposure-design.md`.

## 1. Overview

```
Browser
  │  HTTPS (:443, or a named port — see §3)
  ▼
Caddy                    — the ONLY Internet-facing process; terminates TLS
  │  reverse_proxy 127.0.0.1:<router-port>   (plaintext, loopback-only)
  ▼
ddev-router (Traefik)     — shared HTTP entrypoint, Host-header routed,
  │                         bound to 127.0.0.1 only (never reachable
  │                         directly from outside the host)
  ▼
The right instance's container(s)
```

## 2. The four layers, and what each owns

| Layer | Owns |
|---|---|
| **Caddy** (systemd) | The only externally reachable process. Terminates every TLS connection (on-demand certs via `/api/tls-authorize`). Proxies `fleet.<domain>` (dashboard, behind `basic_auth`) and `*.<domain>[:port]` (every instance) to loopback. Its own admin API (`127.0.0.1:2019`) is never proxied — used only by `caddy reload`/`validate` on the host itself. |
| **Docker** | Runs every instance's containers plus the shared `ddev-router`. |
| **ddev-router / Traefik** | The one shared HTTP entrypoint every instance's containers sit behind, Host-routed to the right instance. Bound to `127.0.0.1` only (`router_bind_all_interfaces: false`, DDEV's global default) — nothing but Caddy should ever reach it directly; a container port bound to all interfaces would bypass this boundary entirely (see the sibling security-hardening spec §3 for the `DOCKER-USER` firewall guard that defends this assumption). |
| **fleet daemon** | Orchestration (deploy/destroy/start/stop, the web UI). Itself just another thing Caddy proxies (`fleet.<domain>` → `127.0.0.1:8765`) — never directly exposed. |

## 3. Every port, in one table

The registry (`fleet.yml`'s `fleet.ports` + `Registry.all_port_profiles()`)
is the actual source of truth — this table is illustrative, current as of
this document's `Last updated` date.

| Port | Owner | Purpose |
|---|---|---|
| `22` | sshd | Operator SSH access |
| `80`, `443` | Caddy | HTTP→HTTPS redirect / TLS termination for `fleet.<domain>` and every `*.<domain>` instance |
| `2019` | Caddy admin API | Loopback-only, never proxied — `caddy reload`/`validate` talk to it locally |
| `8080` | ddev-router HTTP | Shared entrypoint every instance's main site sits behind (loopback-only) |
| `8443` | ddev-router HTTPS | Not used by Caddy's proxy path — proxying to the HTTPS entrypoint 404s (Traefik's HTTPS router matching doesn't engage for a proxied request; see `docs/README-typesense.md`) |
| `8765` | fleet daemon | FastAPI/uvicorn, loopback-only, proxied by Caddy at `fleet.<domain>` |
| `9108` / `8108` | `fleet.ports.typesense` (public/router) | Typesense browser search — legacy built-in default when `fleet.ports.typesense` is undefined (`typesense: true` back-compat, `core/registry.py`) |
| *(any name)* | `fleet.ports.<name>` (public/router) | Any other opt-in named port — e.g. `playwright` (`9324`/`8323`), `ts-dashboard` (`9111`/`8110`) |

## 4. TLS termination points

Exactly one: **Caddy**, for every hostname:port combination it fronts.
Nothing downstream ever terminates TLS — the loopback hop from Caddy to
`ddev-router` (and from there to a container) is plaintext, which is safe
only because it never leaves the host (`127.0.0.1`). Caddy sets
`X-Forwarded-Proto=https` so Drupal still sees the request as secure.

## 5. Host-header routing

Every named port's Caddy site block (`*.{{ fleet_domain }}:<port> {
reverse_proxy 127.0.0.1:<router-port> }`) proxies to the **same shared**
loopback router port regardless of which instance the browser asked for —
`ddev-router` is what actually re-routes by `Host` header to the specific
instance's container. This generalizes what was already true for
Typesense alone: the *router*-side port in a `PortProfile` is shared
across every subscribing instance; only the *public*-side port is unique
per named service, not per instance.

## 6. Runbook: adding a new exposed port

1. Add the port to `fleet.yml`'s `fleet.ports:` catalogue (pick public +
   router numbers outside the reserved set — see `core/registry.py`'s
   `_validate_fleet_ports()` for the exact rules: no `22`/`80`/`443`/`8765`,
   router must not collide with `8080`/`8443`, no duplicate public or
   router numbers across entries):
   ```yaml
   fleet:
     ports:
       my-new-port: { public: 9400, router: 8400 }
   ```
   Fix the project's own `.ddev/*.yml`/`docker-compose.*.yaml` so its
   container actually publishes `8400` — fleet does not do this for you
   (no automatic check that the two sides agree; see the port-exposure
   design spec's assumptions section).
2. Add the name to the project's `ports:` list:
   ```yaml
   projects:
     my-project:
       ports: [my-new-port]
   ```
3. Apply it: `fleet refresh-ports` — reconciles the Caddy snippet (and
   UFW, if the `network_hardening` role is installed) immediately; no
   Ansible re-run, no redeploy.
4. **Confirm real external reachability.** OVH's edge network filters
   ports independently of the host's own firewall (verified during the
   `:9108` Typesense rollout). Test from a real external client, never
   `WebFetch` (it runs from Anthropic's network, not the operator's, and
   gave a false positive for `:9324` once):
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' https://<instance>.fleet.<domain>:9400/
   ```

## 7. Cross-references

- `docs/README-typesense.md` — the worked example this document
  generalizes (topology, admin vs. search-only keys, env injection).
- `.claude/user/docs/specs/2026-07-24-fleet-port-exposure-design.md`
  (companion repo) — the full design this document summarizes.
- `.claude/user/docs/specs/2026-07-24-fleet-security-hardening-design.md`
  (companion repo) — the UFW/`fleet-ufw-sync` side of `fleet refresh-ports`,
  and the `DOCKER-USER` firewall guard referenced in §2 above.
- `core/caddyauth.py` — the sibling fleet-owned-snippet mechanism (per-
  instance/dashboard `basic_auth`), same write/validate/reload shape as
  `core/caddyports.py`.
