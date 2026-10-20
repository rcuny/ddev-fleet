---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Networking (1.x)

How traffic reaches a deployed instance, every port the host uses, how named ports are served, what the in-network
port mirror is for, and which ports to open in the firewall. The catalogue itself is documented in
[`projects-and-templates.md`](projects-and-templates.md#named-ports); installing the host in
[`installation.md`](installation.md). The 0.x document is [`v0/networking.md`](v0/networking.md).

**The installer does not change the firewall.** It never runs `ufw`, `nft` or `iptables`. Open the ports of this page
yourself (and in your provider's firewall, if it has one); `fleet port:firewall` prints the list.

## Every port

| Port | Owner | Purpose |
|---|---|---|
| `22` | sshd | Operator SSH access. |
| `80`, `443` | Caddy | Public. TLS termination for `<domain>`, `auth.<domain>` and every instance site; port 80 redirects to https and carries the HTTP-01 challenge. |
| `2019` | Caddy admin API | Loopback only. The app reloads Caddy through it. |
| `3000` | Mercure hub | Loopback only. |
| `8765` | reserved | Loopback only. Reserved so that a catalogue entry cannot take it. |
| `9091` | Authelia | Loopback only. Served publicly as `https://auth.<domain>` by Caddy. |
| `8080`, `8443` | DDEV router (Traefik) | Loopback only. Host-header routing to the instances; `8443` is the router's https entrypoint. |
| catalogue ports | Caddy (public) and the DDEV router (router) | One pair per [named port](projects-and-templates.md#named-ports): Caddy listens on the *public* port, the traffic goes to the *router* port. |

A migration seeds one entry, `typesense` (public `9108`, router `8108`), unless the host already has a port with that
name or those numbers. It is the entry of the Typesense search server: assigning the catalogue entry `typesense` to a project is the same as switching on the
project's Typesense flag (the two stay in step). How the instance gets its keys is described in [Typesense keys and environment](#typesense-keys-and-environment).

## Typesense keys and environment

The fleet installs and manages no Typesense server. Each instance of a project with the Typesense flag runs its own
container from the project's committed `.ddev/docker-compose.typesense.yaml`; the fleet supplies the keys, as 0.x did
([`v0/README-typesense.md`](v0/README-typesense.md)).

**Keys.** Per project, on its first deploy, the fleet generates two keys of 48 hex characters and stores them sealed as
project secrets (see [secrets](secrets.md)): `TYPESENSE_API_KEY` (admin) and `FLEET_TYPESENSE_SEARCH_KEY` (search only).
They are never rotated, and a value an administrator set by hand under those names is kept. Every instance of the
project shares them. A project without the flag gets nothing.

**What a deploy injects:**

| Where | What |
|---|---|
| `.ddev/.env` (mode 0640, kept out of the project's git status) | `TYPESENSE_API_KEY=<admin key>`. The project's compose file must read it as `${TYPESENSE_API_KEY}` to boot Typesense. Other lines of the file stay as they are. |
| `.ddev/config.fleet.yaml`, `web_environment` | `FLEET_TYPESENSE_HOST=<instance host>`, `FLEET_TYPESENSE_PORT` (the public port of the catalogue entry `typesense`, 9108), `TYPESENSE_API_KEY` (admin, for indexing) and `FLEET_TYPESENSE_SEARCH_KEY`. The protocol is https. |
| The instance's Typesense, after `ddev start` | The search-only key, created through the admin API (`POST /keys`, action `documents:search`, all collections, description `fleet-search-only`) by a request to the router on `127.0.0.1:<router port>` with the instance host in the `Host` header. A key with that description is left alone, so a redeploy adds nothing. |

If the registration fails (Typesense not up yet), the job log gets a `WARNING: typesense: ...` line and the deploy
goes on; a redeploy tries again. Neither key is ever written to a log (the job log masks both) or put on a command line.

**Checks on the host:** `ss -tlnp | grep 8108` (the router listens on the router port), `curl https://<instance host>:9108/health`
(through Caddy) and `fleet port:firewall` for the public ports to open.

Only Caddy listens on the Internet. Everything else is on loopback or inside the Docker network.

## Named-port sites

For every instance of a project that has a named port, Caddy serves one more site per host: `<host>:<public port>`
(the primary host and every alias). It is the instance's own site again: identity headers stripped, Authelia's
`forward_auth` first, then `reverse_proxy 127.0.0.1:<router port>`. A named port is never reachable without a session
that may open the instance.

**Host header.** The browser sends `Host: <host>:<public port>`. Caddy forwards the request as it is to the router
port, and the router (Traefik) picks the instance from the `Host` rules DDEV wrote for it. Check that a request with
the `:port` in the header reaches the instance, see the live checks below.

**Certificates.** The certificate belongs to the name, not to the port: `<host>:9108` uses the same certificate as
`<host>`, and Caddy manages it automatically. Each instance host gets its own certificate (the host settings never ask
for a wildcard certificate).

| Mode | What it means for `<host>:<port>` |
|---|---|
| `http01` | The name still has to pass the HTTP-01 challenge on **port 80**, as for the plain host. Keep port 80 open and a DNS record for each name (the aliases too). Nothing to open for the challenge on the named port itself. |
| `ovh_dns` | DNS-01 through the OVH API: no inbound port is needed for the challenge. The name is still a per-name certificate. |

Caddy must be reachable on the public port, so each public port in use has to be open in the firewall.

## In-network port mirror

**Why.** The named ports exist on the host's Caddy only. Inside an instance's containers the instance host name
resolves to `ddev-router` on the Docker network, which does not listen on `9108` and the like, so a browser inside the
network (Playwright) cannot open `https://<host>:9108`, the URL the site hands to its clients.

**What.** `ddev-router` is made to listen on the same public ports inside the Docker network only (they are not
published on the host, so they do not clash with Caddy). Fleet writes two generic files into the fleet user's DDEV
global Traefik directory, `~/.ddev/traefik/`:

- `static_config.fleet-port-mirror.yaml`: one entrypoint `fleet-mirror-<public>` per port. DDEV merges every
  `static_config.*.yaml` into its static config at `ddev start`.
- `custom-global-config/fleet-port-mirror.yaml`: one TCP router and service per entrypoint (`HostSNI(*)`, TLS
  terminated), forwarding to the router's own http entrypoint `127.0.0.1:<router port>`, whose `Host` rules pick the
  instance.

Both start with a "managed by ddev-fleet, do not edit" comment.

**Skipped ports.** A port is skipped, with a reason, when its public number is one of the router's own entrypoints
(`80`, `443`, `8080`, `8443`, `8025`, `8026`, `10999`), equals the router port of another catalogue entry, or is
already declared as an entrypoint by another `static_config.*.yaml` in `~/.ddev/traefik/` (the reason names the file).
A colliding entrypoint would stop the shared router and take every instance down.

**How it is applied.**

1. Syncing (after a deploy or destroy, and `fleet port:refresh`) only writes the files, atomically and only when the
   content changed, and deletes them when nothing is left to mirror. It never touches the router.
2. DDEV reads the files at `ddev start`; the router reads its static config only when it starts. After a successful
   `ddev start`, the converge step compares the live entrypoints (`http://127.0.0.1:10999/api/entrypoints`) with the
   wanted `fleet-mirror-*` set and restarts `ddev-router` **only on a difference**, under a lock. Nothing else restarts
   the router.
3. If the router does not come back healthy with the wanted entrypoints, fleet removes both files, writes the marker
   `~/.ddev/traefik/.fleet-port-mirror-failed` (the wanted ports), restarts the router again and logs a warning. The
   deploy still succeeds. While the marker matches the wanted ports nothing is written and the router is not restarted,
   so a broken mirror cannot restart the shared router on every deploy. `fleet port:refresh` is the explicit retry: it
   clears the marker.
4. `fleet port:refresh` prints `router mirror: live` or `router mirror: pending` (applied at the next instance deploy).

**Opt-out.** The host settings have a port-mirror switch (default on). Off, the next sync removes the files (and the
`:443` entry), and the next deploy restarts the router once to drop the entrypoints.

**Site mirror on `:443`.** DDEV's `router_https_port` is `8443` on a fleet host, because Caddy owns `443`. Inside the
network `https://<host>/` would be refused and only `https://<host>:8443/` would work, which puts `:8443` in the `Host`
header and breaks apps that compare the host name exactly (Drupal Domain Access, for one). The same files therefore
carry one more entrypoint `fleet-mirror-443` with TLS **passthrough** to `127.0.0.1:<router https port>` (read from
`~/.ddev/global_config.yaml`). It is skipped when the router https port already is `443`, is absent or unreadable, or
when another `static_config.*.yaml` declares `:443` or the name `fleet-mirror-443`. `443` stays refused as a catalogue
public port. The port-mirror switch covers it too.

**TLS.** Inside the network the certificate is DDEV's own mkcert one, not Caddy's: clients such as Playwright need
`ignoreHTTPSErrors`. Use `https://$FLEET_INSTANCE_HOST` as the base URL, not `DDEV_PRIMARY_URL`, which carries `:8443`.

**Rollout.** The first deploy after an upgrade restarts `ddev-router` once: a brief blip for every instance on the host.

## Firewall: `fleet port:firewall`

```
fleet port:firewall [--json]
```

Lists the public ports to open: `80` and `443` always, plus the public port of every catalogue entry that at least one
project uses. Ports nobody uses are not listed.

```
$ fleet port:firewall
 ------ ------------------------------------------------ ---------- --------------------
  PORT   PURPOSE                                          PROJECTS   COMMAND
 ------ ------------------------------------------------ ---------- --------------------
  80     http (certificate challenge and redirect ...)    -          ufw allow 80/tcp
  443    https                                            -          ufw allow 443/tcp
  9108   typesense                                        blog       ufw allow 9108/tcp
 ------ ------------------------------------------------ ---------- --------------------
Note: the installer does not change the firewall. Run these commands yourself (or add the ports to your provider firewall).
```

`--json` prints `{"ports": [{"port": 80, "purpose": "...", "projects": [], "ufw": "ufw allow 80/tcp"}, ...]}`, sorted
by port. Run it again after assigning a port to a project. Port `22` is not listed; keep it open for SSH.

## Checks that need a live host

These have only been reviewed in code. Verify them on a server before relying on them:

- [ ] Caddy obtains a certificate for `<host>:9108` (`http01`: validation on port 80, same certificate as the plain
  host; `ovh_dns`: DNS-01).
- [ ] The host firewall (hardening, nftables or ufw) lets the public ports through.
- [ ] A request with `Host: <host>:<port>` reaches the ddev router and is routed to the instance.
- [ ] After the first deploy, `fleet port:refresh` prints `router mirror: live`; `ddev-router` listens on
  `fleet-mirror-<public>` (and `fleet-mirror-443`) and no instance went down at the one restart.
- [ ] From inside an instance container, `https://<host>:<public>` and `https://<host>/` open the instance (with
  `curl -k`).
- [ ] A forced failure (a colliding entrypoint) leaves the marker `.fleet-port-mirror-failed`, no mirror files, a healthy
  router, and `fleet port:refresh` clears it once fixed.
