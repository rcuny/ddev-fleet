---
Author: Claude Code
Reviewer: none
Last updated: 2026-09-28
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

### Certificate mode (`fleet_tls_mode`)

How Caddy actually *gets* those certificates is chosen at install time
(`docs/installation.md` "Choosing a TLS mode") and persisted as the
`fleet_tls_mode` Ansible var:

- **`on_demand`** (default) — one cert per exact hostname, issued the
  first time it's requested, via the HTTP-01 challenge authorized by
  `/api/tls-authorize`. Subject to Let's Encrypt's ~50-new-certs-per-
  registered-domain-per-7-days limit (see §7 below).
- **`ovh_dns`** — one wildcard cert for `*.{{ fleet_domain }}`, via the
  DNS-01 challenge (`caddy-dns/ovh` plugin writing the `_acme-challenge`
  TXT record through the OVH API). No per-hostname limit; needs an OVH API
  key. OVH is currently the only supported DNS provider — a future
  provider would be a new mode named `<provider>_dns`.

Both modes render the same shape of Caddy config: the full `tls { ... }`
directive lives in ONE file, `{{ fleet_caddy_snippet_dir }}/tls.conf`
(rendered by the `caddy` Ansible role from `tls.conf.j2`, per
`fleet_tls_mode` — never by `fleet.core`), imported by a **literal** path
from both the `*.{{ fleet_domain }}` site (`Caddyfile.j2`) and every
fleet-owned named-port site (`fleet.core.caddyports.render_port_snippet()`)
— so `core/caddyports.py` carries zero knowledge of TLS mode, and in
`ovh_dns` mode every one of those sites shares the exact same wildcard
certificate (same cert name in Caddy's storage, since they all import the
identical `tls.conf`). The dashboard site (`fleet.{{ fleet_domain }}`
itself) keeps Caddy's own default automatic HTTPS (HTTP-01) in **both**
modes — it isn't a wildcard-eligible hostname.

Switching an existing server's mode: `ansible/caddy-only.yml` (a scoped
playbook, mirroring `ddev-only.yml`) reapplies just the `caddy` role — see
`docs/installation.md` "Switching TLS mode on an existing server", which
also covers the OVH token gotchas learned rolling this out to two live
servers: tokens can be **IP-restricted** (one token per server, or list
every server's IP on a shared token), the required rights are
`GET`/`POST`/`PUT`/`DELETE` on `/domain/zone/<zone>/*`, test a token with
a throwaway TXT record before pointing a live domain at it, and any
hand-edit of `/etc/caddy/ovh.env` needs its own `systemctl restart caddy`
(a `reload` does not re-read the `EnvironmentFile=`).

**`auth.<domain>` (Authelia mode only).** When `host.yml`'s `auth_mode` is
`authelia` (`docs/README-authelia.md`), Caddy also serves Authelia's login
portal at `auth.{{ fleet_domain }}` — a single-label subdomain like any
instance hostname, so it is covered by the exact same certificate handling
as every other `*.{{ fleet_domain }}` host: an individual on-demand cert in
`on_demand` mode, or the shared wildcard cert in `ovh_dns` mode. It exists
only when this Caddyfile block is rendered (basic mode omits it entirely)
and is otherwise unauthenticated — Authelia is what authenticates
everything else.

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
4. **Confirm real external reachability.** Some hosting providers filter
   non-standard ports at the network edge independently of the host's own
   firewall — verify with your provider. Test from a real external client, never
   `WebFetch` (it runs from Anthropic's network, not the operator's, and
   gave a false positive for `:9324` once):
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' https://<instance>.fleet.<domain>:9400/
   ```

## 7. Domain-Access alias hostnames (`additional_hostnames`)

A project's `additional_hostnames: [news, odihr, ...]` (`fleet.yml`) gives
each instance extra hostnames for Drupal Domain Access-style multi-domain
sites, alongside its normal `<instance-id>.<domain>`. Full field reference:
`docs/configuration.md`'s `additional_hostnames` row.

- **Flattened, single-label form.** An alias for hostname `h` on instance
  `<instance-id>` is `<h>-<instance-id>.<domain>` — e.g.
  `news-oak--translations-test.fleet.example.com` — never a nested/
  multi-label form (`news.oak--translations-test...`). Caddy's site block
  for this fleet is a single-label wildcard, `*.{{ fleet_domain }}` (§5
  above), which can only ever match one label; a nested alias would be
  unreachable and `/api/tls-authorize` rejects any dotted label anyway.
  `core/instances.py`'s `alias_fqdns()` is the one place that composes this
  string — nothing else should format one by hand.
- **63-character DNS label limit.** `<h>-<instance-id>` must itself be a
  valid DNS label (RFC 1035). `alias_fqdns()` raises `DeployError` at
  deploy time (naming the hostname and the resulting length) if it doesn't
  fit — a long project/label/hostname combination can hit this even though
  the bare instance id was already within the limit on its own.
- **Auth covers alias hosts too, in either mode.** The per-instance Caddy
  snippet's `@auth-<instance-id>` matcher (`core/caddyauth.py`) lists the
  instance FQDN *and* every alias FQDN in the same `host` clause, whether
  it's a basic-auth snippet or an Authelia `forward_auth` one
  (`docs/README-authelia.md`), so an alias can never bypass auth.
- **`FLEET_INSTANCE_HOST`** — injected into every instance's
  `web_environment` (`core/fleetconfig.py`) as `<instance-id>.<domain>` (no
  scheme). A project's Domain Access config builds its own alias-matching
  patterns from it: `"<h>-" . getenv('FLEET_INSTANCE_HOST')` in PHP,
  guaranteed to compose the exact same string `alias_fqdns()` does
  fleet-side.
- **Certificates.** In the default `on_demand` mode, each alias host is a
  distinct hostname to Caddy's on-demand TLS, so it gets its **own** Let's
  Encrypt certificate the first time it's requested — it is not covered by
  the instance's own cert. A registered domain gets roughly 50
  new-certificate issuances per week from Let's Encrypt; a project with
  many aliases across many instances can run into that limit. Switching
  to `fleet_tls_mode: ovh_dns` (§4 above) removes this limit entirely — a
  single wildcard cert for `*.<domain>` already covers every alias host
  (they're all single-label subdomains of the same domain), with no
  `/api/tls-authorize` round trip and no per-hostname issuance at all.

## 8. In-network mirror of named ports (FLE-29)

**Why.** The public named ports (`fleet.ports` entries and the built-in
Typesense `9108`) exist only on the host's Caddy (`*.<domain>:<public>`
-> `127.0.0.1:<router>`). Inside an instance's containers the instance FQDN
resolves to `ddev-router` on the Docker network, which does not listen on those
public ports, so an in-container browser (Playwright) could not reach
`https://<fqdn>:9108`, the URL the site hands to the client.

**What.** `ddev-router` (Traefik) is made to listen on the same public ports
inside the Docker network only (these ports are not published on the host, so
there is no clash with Caddy). `core/routermirror.py` writes two generic files
(no per-instance content) into the fleet user's DDEV global Traefik directory,
`~/.ddev/traefik/`:

- `static_config.fleet-port-mirror.yaml` - one entrypoint `fleet-mirror-<public>`
  per port. DDEV merges every `static_config.*.yaml` into its generated static
  config during `ddev start`.
- `custom-global-config/fleet-port-mirror.yaml` - one TCP router + service per
  entrypoint (`HostSNI(*)`, TLS terminated with the certificates DDEV already
  loads) forwarding the plaintext to the router's OWN http entrypoint
  `127.0.0.1:<router>`, whose existing Host rules pick the instance. DDEV copies
  this directory into the router's config volume on `ddev start`.

Both files start with a `# Managed by ddev-fleet (FLE-29): do not edit.` comment.
A port is skipped (never raised, with a reason printed by `fleet refresh-ports`)
when its public number is one of the router's own entrypoints
(`80/443/8080/8443/8025/8026/10999`), equals the `router` port of any catalogue
entry, duplicates another entry's `public`, or is already declared as an entrypoint
by another `static_config.*.yaml` in `~/.ddev/traefik/` (the reason names the
file): a colliding entrypoint would stop
the shared router from starting and take every instance down.

**Apply model.**

1. `deploy`, `destroy`, `fleet refresh-ports` and the daemon at startup call
   `routermirror.sync()`, which only (re)writes the files, atomically and only
   when their content changed (and deletes them when there is nothing to
   mirror). It never touches the router.
2. DDEV merges/copies the files during the instance's `ddev start`. The router
   only reads its static config when it starts, so right after a successful
   `ddev start`, `deploy` calls `routermirror.converge()`: it compares the live
   entrypoints (`GET http://127.0.0.1:10999/api/entrypoints`) with the wanted
   `fleet-mirror-*` set and restarts `ddev-router` (`docker restart`, then waits
   for `healthy`) only when they differ. Otherwise nothing happens.
3. **Self-repair and marker.** If the router does not come back healthy with the
   wanted entrypoints, fleet removes both files, writes the marker
   `~/.ddev/traefik/.fleet-port-mirror-failed` (the wanted public ports, e.g.
   `9108,9111`), runs `ddev start` in the instance again (DDEV re-merges its
   config without ours), restarts the router again and reports
   `RouterMirrorError`. The deploy itself still succeeds; the deploy log gets a
   `WARNING: port mirror: ...` line. While the marker matches the wanted ports,
   `sync()` writes nothing and `converge()` never restarts the router, so a
   broken mirror cannot restart the shared router on every deploy. A different
   wanted set deletes the marker; `fleet refresh-ports` deletes it
   unconditionally (the explicit retry).
   A transient failure (a docker hiccup, a health timeout) also writes the marker
   and disables the mirror until `fleet refresh-ports` is run: by design, to
   protect the shared router.
   `fleet-mirror-*` entrypoints declared by another `static_config.*.yaml` (for
   example a leftover spike file) are not ours and are ignored when comparing
   with the live router.
   `converge()` also refuses to restart when the two files are missing or differ
   from what would be written now (e.g. `~/.ddev/traefik` absent).
   Every `ddev-router` step (`converge()`, and the `sync()` of deploy, destroy
   and `refresh-ports`) runs under a `flock` on `<fleet home>/locks/_routermirror.lock`
   (waits up to 300 s, then skips with a message, never restarting).
4. `fleet refresh-ports` prints `router mirror: live` or `router mirror:
   pending - applied at the next instance deploy (ddev start + router restart)`;
   it never restarts the router.

**Rollout note.** The first deploy after upgrading restarts `ddev-router` once,
a brief blip for every instance on that host.

**Opt-out.** `fleet.port_mirror: false` in `fleet.yml` (default `true`) removes
the files; the next deploy restarts the router once to drop the entrypoints.

**TLS.** In-container the certificate is DDEV's own mkcert one, not the public
Caddy certificate: clients such as Playwright need `ignoreHTTPSErrors`.

## 9. Cross-references

- `docs/README-typesense.md` — the worked example this document
  generalizes (topology, admin vs. search-only keys, env injection).
- `.claude/user/docs/specs/2026-07-24-fleet-port-exposure-design.md`
  (companion repo) — the full design this document summarizes.
- `.claude/user/docs/specs/2026-07-24-fleet-security-hardening-design.md`
  (companion repo) — the UFW/`fleet-ufw-sync` side of `fleet refresh-ports`,
  and the `DOCKER-USER` firewall guard referenced in §2 above.
- `core/caddyauth.py` — the sibling fleet-owned-snippet mechanism (per-
  instance/dashboard `basic_auth`, or `forward_auth` in Authelia mode),
  same write/validate/reload shape as `core/caddyports.py`.
- `docs/README-authelia.md` — the Authelia auth mode design (server-level
  `auth_mode`, `auth.<domain>` portal, per-project `users:`).
