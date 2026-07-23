---
Author: Claude Code
Reviewer: none
Last updated: 2026-07-16
Type: documentation
---

# Typesense browser-search exposure

How the fleet makes a project's Typesense reachable **from the browser**
(for widgets like `fern_facets` that query Typesense client-side), without
ever exposing the Typesense admin key.

## Why this exists

Under classic (non-fleet) DDEV, a project exposes Typesense on a dedicated
port on its own dev hostname (e.g. `https://fern.ddev.site:8109`) and the
browser talks to it directly. Under the fleet, instances sit behind Caddy,
which only fronts `:443` for the wildcard site and proxies to DDEV's shared
`ddev-router` on loopback — nothing else is reachable from outside the host
by default. Typesense needed its own deliberate, opt-in exposure path.

## This supersedes the path-based design

An earlier design (see the companion repo's
`.claude/user/docs/specs/2026-07-15-typesense-edge-exposure-design.md`)
exposed Typesense at `https://{instance}.fleet.<domain>/_typesense/*`
(same-origin, Caddy `handle`/`strip_prefix`). It was implemented, then
found **incompatible** at rollout: the `fern_facets` widget's built
typesense-js client has no `path` option (confirmed in the built dist JS —
zero `_typesense` occurrences; it always calls `/multi_search` at the
document root), so path-prefixing broke it. The **port-based** design below
replaced it fleet-wide (ddev-fleet commit `f3d3820`, 2026-07-16) and matches
how the project already worked locally (a dedicated port, not a path). If
you see `/_typesense` or a `FLEET_TYPESENSE_PATH` env var referenced
anywhere (old specs, stray comments in `fleet.yml.dist`), it's stale.

## Topology (port-based)

```
Browser
  │  HTTPS, dedicated port
  ▼
https://<project>--<label>.fleet.personal.example:9108/multi_search
  │
  ▼
Caddy  — *.{{ fleet_domain }}:9108 site (ansible/roles/caddy/templates/Caddyfile.j2)
  │  on_demand TLS (same wildcard cert machinery as the main :443 site)
  │  reverse_proxy 127.0.0.1:{{ ddev_typesense_http_port }}   (8108)
  ▼
127.0.0.1:8108  — the shared ddev-router HTTP entrypoint (loopback-only),
  │                Host-routed to the correct instance's Typesense container
  ▼
Instance's Typesense (internal :8108)
```

- `9108` is `fleet_typesense_public_port` in `ansible/group_vars/all.yml`
  and **must** stay numerically in sync with `TYPESENSE_PUBLIC_PORT` in
  `src/fleet/core/instances.py` — the two are configured independently
  (Ansible vs. Python) with no shared source of truth, so a change to one
  without the other silently breaks the browser URL.
- `8108` is `ddev_typesense_http_port` in the same `group_vars/all.yml` and
  must match `TYPESENSE_ROUTER_HTTP_PORT` in `instances.py` — this is the
  shared `ddev-router` entrypoint that every Typesense-enabled instance's
  container sits behind, Host-routed exactly like the main `:8080` HTTP
  entrypoint DDEV instances already share.
- Opt-in is per-project: set `typesense: true` on a project block in
  `fleet.yml` (see `fleet.yml.dist` for the commented example).
  `Registry.typesense_enabled(project)` gates all of the behavior below.

## The two keys, and why

`src/fleet/core/typesense.py:ensure_project_keys()` generates and persists,
once per project, into `/srv/fleet/secrets/<project>.env`:

| Key | Purpose | Ever sent to the browser? |
|---|---|---|
| `TYPESENSE_API_KEY` | Admin key. Boots the Typesense container, used by the Drupal backend for indexing (`search_api` write access). | **No.** |
| `FLEET_TYPESENSE_SEARCH_KEY` | Search-only key (`documents:search` only, all collections), registered against the running instance's Typesense via `register_search_key()` (a `POST /keys` call over the loopback route above) after `ddev start`. | **Yes — this is the only key the browser ever receives.** |

Key generation is idempotent (`generate_key()` is `secrets.token_hex(24)`;
existing keys are read back and never rotated once set) and non-fatal on
failure: if `register_search_key()` can't reach the instance's admin API,
deploy logs a `WARNING` and continues rather than failing the whole deploy —
search just won't work until the key is re-registered (re-running a deploy
retries it).

## `.ddev/.env` — the container boot key

`write_ddev_env()` (`src/fleet/core/fleetconfig.py`) upserts
`TYPESENSE_API_KEY` into the instance's `.ddev/.env`, which DDEV interpolates
into the project's own `docker-compose.*.yaml` (the project commits its own
`.ddev/docker-compose.typesense.yaml` — the fleet does not generate the
compose file, only the admin key it needs). This is how the Typesense
container itself boots with the fleet-managed admin key instead of a
project-local default. `.ddev/.env` is added to `.git/info/exclude` for the
instance (never committed).

## `FLEET_TYPESENSE_*` env injection (`config.fleet.yaml`)

`write_fleet_config()` adds to the instance's `.ddev/config.fleet.yaml`
`web_environment` block, when the project opts in:

| Var | Value |
|---|---|
| `FLEET_TYPESENSE_HOST` | `{instance-id}.{fleet-domain}` |
| `FLEET_TYPESENSE_PORT` | `9108` (the public Caddy port, `typesense_port` param) |
| `TYPESENSE_API_KEY` | the admin key (backend indexing use only) |
| `FLEET_TYPESENSE_SEARCH_KEY` | the search-only key (what the frontend/Drupal settings hand to the browser) |

## `settings.project.php` connection (config repo)

The project's `settings.project.php` (in the private `ddev-fleet-config`
repo's `assets/<project>/` tree, injected + token-substituted at deploy —
see the main `CLAUDE.md`'s "per-project secrets model") reads
`FLEET_TYPESENSE_HOST`/`FLEET_TYPESENSE_PORT`/`FLEET_TYPESENSE_SEARCH_KEY`
from the environment and builds the Typesense client config Drupal hands to
`drupalSettings` for the browser widget — host/port/protocol only, **no
path** (the old `FLEET_TYPESENSE_PATH` value was removed when the port-based
design replaced the path-based one). The admin key never appears in
anything served to the browser.

## `post_deploy` reindex

Because a fresh deploy imports a DB dump whose `search_api` tracker state
can desync from an empty/fresh Typesense (Drupal reports the index 100%
tracked while Typesense holds zero documents), the `oak` project's
`fleet.yml` template `post_deploy` includes `drush search-api:reset-tracker`
followed by `drush search-api:index` so a freshly deployed instance's
Typesense index is actually populated, not just marked complete.

## OVH edge firewall reachability caveat

`:9108` is a non-standard port opened on a Kimsufi/OVH host; OVH's edge
network can filter ports outside an allowed range independently of the
host's own `iptables`. This was flagged as an open risk during rollout
(2026-07-16) — local `iptables` was confirmed open immediately, but
external reachability from a real browser required separate confirmation.
**Status: confirmed working** — the user verified `:9108` reachability and
live browser search end-to-end on `oak--slacktest` after rollout.

## Verifying live

```bash
# Shared ddev-router Typesense entrypoint bound on loopback:
ss -tlnp | grep -E ':8108\b'          # expect 127.0.0.1:8108

# End-to-end from an external client (replace with a real typesense-enabled instance):
curl -s -o /dev/null -w '%{http_code}\n' \
  https://<project>--<label>.fleet.personal.example:9108/health
```

See also `docs/runbook-server-rollout.md` §6 for the fuller live-verification
checklist (note: that section's `/_typesense/health` example predates this
port-based design and is stale — use the `:9108` form above).
