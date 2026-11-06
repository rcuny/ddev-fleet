---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Networking

One public entry point keeps the host small to defend: Caddy terminates TLS for every hostname, gates access with Authelia, and forwards to instances, which never listen on the Internet. This page lists every port, how certificates behave, and how named ports and the in-network mirror work. The installer does not touch the firewall; [secure the host](../guides/secure-the-host.md) covers the opt-in hardening and [files on disk](files-on-disk.md) the units.

## Every port

| Port | Process | Bound to | Public? |
|---|---|---|---|
| `22` | sshd | all | Yes (operators) |
| `80`, `443` | Caddy (TLS, redirect, HTTP-01) | all | Yes |
| Catalogue public ports (`9108` for `typesense`) | Caddy | all | Yes, if a project uses it |
| `2019` | Caddy admin API | loopback | No |
| `3000` | Mercure hub | loopback | No |
| `8765` | reserved | loopback | No |
| `9091` | Authelia (`https://auth.<domain>`) | loopback | No |
| `8080`, `8443` | DDEV router (Traefik) | loopback | No |
| Catalogue router ports (`8108`) | DDEV router | loopback | No |

**Request path:** browser, Caddy (TLS), Authelia check, the instance's Caddy snippet, `ddev-router` on `127.0.0.1:8080`, which picks the instance by its `Host`.

## Certificates

| Mode | Behaviour |
|---|---|
| `http01` | Each instance host (and alias) gets its own certificate, validated on port 80. Keep port 80 open and a DNS record for every name. Many hostnames deployed often can hit Let's Encrypt's limits (50 certificates per week per registered domain). |
| `ovh_dns` | DNS-01 through the OVH API; no inbound port needed. The whole host is one `*.<domain>` site with one wildcard certificate, so a new instance never orders one. |

A named-port site `<host>:<port>` reuses the certificate of `<host>`; in `ovh_dns` mode these sites keep their own address, so Caddy manages a certificate for them unless the wildcard covers the name.

**Certificate health.** `fleet host:tls-check` runs every 10 minutes (`fleet-tls-check.timer`) and records rate limits, failed challenges in the last hour, certificates issued in 7 days and the host certificate's expiry. `fleet host:tls-status` prints it (`--json`):

| Exit code | Meaning |
|---|---|
| `0` | OK. |
| `1` | Warning: failed solves, expiry under 14 days, no check yet or one older than an hour. |
| `2` | Error: Let's Encrypt is rate limiting, or a certificate expired. |

Administrators see the same status as a banner and on the Host page's Certificates tab.

## Named-port sites

For each instance of a project with a named port, Caddy serves one more site, `<instance host>:<public port>` (primary host and every alias): identity headers stripped, Authelia first, then the router port, which picks the instance by `Host`. Ports are **gated** by default. A **public** port skips the login and group check (the `typesense` default, protected by its search-only key), so cross-origin browser calls work: the CORS preflight carries no cookie and would otherwise get a 302.

Commands: `fleet port:create`, `fleet port:update <name> --access=gated|public`, `fleet port:refresh` (rewrites the sites), and `fleet project:update <project> --port=<name>` to assign one ([cli.md](cli.md)). Port fields: [configuration](configuration.md). Open each public port in the firewall.

## `:443` passthrough

DDEV's router HTTPS port is `8443` because Caddy owns `443`. Inside the network `https://<host>/` would fail, and `:8443` in the `Host` header breaks apps that compare host names exactly (Drupal Domain Access). The mirror therefore adds an entrypoint `fleet-mirror-443` with TLS passthrough to `127.0.0.1:<router https port>`. It is skipped when the router HTTPS port already is `443`, is unreadable, or when another `static_config.*.yaml` declares `:443` or that name. `443` stays refused as a catalogue public port.

## In-network port mirror

| Topic | Detail |
|---|---|
| Why | Inside an instance's containers the host name resolves to `ddev-router`, which does not listen on `9108` and the like, so a browser in the network (Playwright) cannot open the URL the site hands out. |
| What | `ddev-router` also listens on the same public ports inside the Docker network only (not published, so no clash with Caddy). Fleet writes `static_config.fleet-port-mirror.yaml` and `custom-global-config/fleet-port-mirror.yaml` under `~/.ddev/traefik/` of the fleet user. |
| Enable | On by default; the switch is in the host settings. Off, the next deploy restarts the router once to drop the entrypoints. |
| Applying | Restarts `ddev-router` once, only when the live entrypoints differ. `fleet port:refresh` prints `router mirror: live` or `pending` (applied at the next deploy). The first deploy after an upgrade causes a brief blip for every instance. |
| Skipped ports | Router-owned numbers (`80`, `443`, `8080`, `8443`, `8025`, `8026`, `10999`), another entry's router port, or an entrypoint another `static_config.*.yaml` declares. |
| Failure | If the router does not come back healthy, Fleet removes both files, writes the marker `~/.ddev/traefik/.fleet-port-mirror-failed`, restarts the router and logs a warning; the deploy still succeeds. While the marker matches the wanted ports nothing is retried; `fleet port:refresh` clears it and retries. |
| Clients | The certificate inside is DDEV's own, so set `ignoreHTTPSErrors`. Use `https://$FLEET_INSTANCE_HOST` as the base URL, not `DDEV_PRIMARY_URL` (it carries `:8443`). |

## Firewall

`fleet port:firewall` (`--json`) lists the public ports to open: `80`, `443` and each catalogue port a project uses, with a `ufw allow` line each. Port `22` is not listed. Re-run it after assigning a port.

## Typesense

Fleet installs no Typesense server: each instance runs its own container from the project's `.ddev/docker-compose.typesense.yaml`. On a project's first deploy Fleet generates an admin and a search-only key as project secrets ([assets and secrets](assets-and-secrets.md)), shared by its instances and never rotated.

| Variable | Where | Content |
|---|---|---|
| `TYPESENSE_API_KEY` | `.ddev/.env` and `web_environment` | Admin key; the compose file must read `${TYPESENSE_API_KEY}`. |
| `FLEET_TYPESENSE_SEARCH_KEY` | `web_environment` | Search-only key, registered after `ddev start`. |
| `FLEET_TYPESENSE_HOST` | `web_environment` | The instance host. |
| `FLEET_TYPESENSE_PORT` | `web_environment` | Public port of the `typesense` entry (9108); https. |

Registering the search key is retried for up to 60 seconds; if Typesense never answers, the job log gets `WARNING: typesense` and the deploy succeeds. Operator steps: switch on the project's Typesense flag, run `fleet port:firewall` and open the port, deploy.

## Checks on a live host

```bash
ss -ltnp | grep -E ':(80|443|9108)\b'          # only Caddy on the public ports
ss -tlnp | grep 8108                             # router listens on the router port
curl -I https://myproject--main.fleet.example.com:9108/health
fleet port:refresh                               # expect: router mirror: live
fleet host:tls-status
```

## Learn more

- [Secure the host](../guides/secure-the-host.md)
- [Configuration](configuration.md)
- [Troubleshooting](troubleshooting.md)
