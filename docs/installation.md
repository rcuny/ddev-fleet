---
Author: Claude Code
Reviewer: none
Last updated: 2026-09-28
Type: documentation
---

# Installing ddev-fleet

This is the first-run checklist: DNS, the installer, adding the deploy
key, minting the Claude Code token, and the first deploy. For ongoing
operations (updates, rollback, password rotation) see `docs/operations.md`.

## 1. Choosing a TLS mode

The installer asks how Caddy should get Let's Encrypt certificates for
`*.<domain>` (every deployed instance) and any named-port site
(`docs/networking.md`). This is the `fleet_tls_mode` Ansible var
(`ansible/group_vars/all.yml`), persisted per host in
`/etc/ddev-fleet/local-vars.yml`:

| Mode | How it works | Tradeoff |
|---|---|---|
| **`on_demand`** (default) | Caddy requests a fresh Let's Encrypt cert the first time each exact hostname is seen, via the HTTP-01 challenge, authorized by `/api/tls-authorize`. | No setup. But Let's Encrypt caps **new** certificate issuance at roughly **50 per registered domain per rolling 7 days** (refilling ~1 every 3.4 hours) — renewals don't count against this, but a project with many instances/aliases can hit it. |
| **`ovh_dns`** | Caddy issues **one wildcard certificate** for `*.<domain>`, shared by every instance and named port, via the DNS-01 challenge — it writes the `_acme-challenge` TXT record straight into your DNS zone through the OVH API (the `caddy-dns/ovh` plugin, requires a custom Caddy build — the installer/role handles this). | No per-hostname limit (a wildcard is **one** certificate, covering exactly one label — `*.<domain>` covers `foo.<domain>` but not `foo.bar.<domain>`). Needs an OVH API key scoped to your DNS zone. OVH is currently the only supported DNS provider. |

**DNS records needed:**

```
<domain>       A     <server-ip>      # both modes — the dashboard, fleet.<domain>
*.<domain>     A     <server-ip>      # on_demand mode only
```

In `ovh_dns` mode, `*.<domain>` does **not** need its own DNS A/AAAA
record — Caddy proves domain ownership via the DNS-01 TXT record instead,
so the wildcard cert is issued without any wildcard DNS entry pointing at
the server. (You'll usually still want one if you expect any client to
resolve an instance hostname without the fleet's own DNS setup — but it is
not required for the certificate itself.)

**If you choose `ovh_dns`,** create an OVH API token before or during the
prompt:

1. Go to `https://eu.api.ovh.com/createToken/` (or your regional
   equivalent — `ca.api.ovh.com`/`api.ovh.com` for other OVH regions; the
   installer's `OVH_ENDPOINT` default is `ovh-eu`).
2. Grant these rights, scoped to your registered zone (e.g. `example.com`):
   - `GET /domain/zone/<zone>/*`
   - `POST /domain/zone/<zone>/*`
   - `PUT /domain/zone/<zone>/*`
   - `DELETE /domain/zone/<zone>/*`
3. **If you restrict the token to an IP address, it is a hard restriction,
   not a hint**: a token scoped to server A's IP fails on server B with
   `403 "This call has not been granted"` on every DNS-01 call, even
   though the token itself is otherwise valid. Running more than one
   fleet host with `ovh_dns`? Either create **one token per server**, or
   list **all** their IPs on a single token — there is no way to widen an
   existing single-IP token after the fact from the UI, only recreate it.
4. **Test the token before switching a live domain to `ovh_dns`.** A
   throwaway DNS-01 exchange (or a manual signed `POST` + `DELETE` of a
   temporary `_acme-challenge` TXT record via the OVH API) confirms the
   token/rights/IP restriction all actually work. Don't find this out by
   switching the domain and watching the wildcard cert fail — the old
   `on_demand` certs and DNS records are gone the moment `caddy-only.yml`
   applies, so a bad token means every instance is unreachable until you
   roll back.
5. Note the **Application Key**, **Application Secret**, and **Consumer
   Key** it gives you — the installer prompts for these (application
   secret and consumer key are read silently, never echoed) and writes
   them to `/etc/caddy/ovh.env` (`0600 root:root`, then re-owned
   `0640 root:caddy` once the `caddy` role has run). **Ansible never
   writes or reads back this file's contents** — only checks that all
   four required keys are present.
6. **After hand-editing `/etc/caddy/ovh.env`** (e.g. swapping in a
   corrected token), `systemctl restart caddy` — a `reload` does **not**
   re-read the `EnvironmentFile=` a systemd drop-in supplies, so a plain
   reload keeps running with the old (or missing) credentials.

## 2. Choosing an auth mode

The installer also asks how each deployed instance should authenticate
visitors — the `FLEET_AUTH_MODE` prompt, persisted per host in
`/etc/ddev-fleet/local-vars.yml` as `fleet_auth_mode`:

| Mode | How it works | Choose it when |
|---|---|---|
| **`basic`** (default) | Per-instance HTTP basic auth, credential symmetric (`--auth-password`), on by default on every deploy. | The default — no extra moving parts, works everywhere HTTP basic auth itself works. |
| **`authelia`** | A cookie-based login portal (Authelia, systemd service on `127.0.0.1:9091`); Caddy authorizes each instance against it per project, using users defined in `fleet.yml`'s `users:` key. | A network's own policy blocks HTTP basic auth outright (it looks like a server error to the client, not a login prompt), or you want named per-user logins instead of one shared instance credential. |

Full design, `fleet.yml` schema, and how to switch an already-provisioned
server's mode later: `docs/README-authelia.md`.

## 3. DNS

Create the DNS record(s) from the table above before running the
installer.

## 4. Run the installer

```bash
curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/main/bootstrap.sh | sudo bash
```

Version-pinned (recommended for anything beyond a first try):

```bash
curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/main/bootstrap.sh | sudo FLEET_REPO_VERSION=v1.0.0 bash
```

The installer will:

1. Confirm it's running as root.
2. Detect the OS (Debian 13 / Ubuntu 26.04 are verified; anything else
   warns and asks to continue, or set `FLEET_FORCE_OS=1` for an
   unattended run).
3. Collect: `FLEET_DOMAIN` (the fleet wildcard domain, required),
   `FLEET_ACME_EMAIL` (Let's Encrypt contact, required), `FLEET_ADMIN_PASSWORD`
   (optional — a strong one is generated and printed once if you don't
   supply it), a **TLS certificate mode** choice (`FLEET_TLS_MODE`,
   `on_demand` or `ovh_dns` — see "Choosing a TLS mode" below), an
   **auth mode** choice (`FLEET_AUTH_MODE`, `basic` (default) or
   `authelia` — see "Choosing an auth mode" below), `FLEET_REPO_VERSION`
   (optional, defaults to `main`), and two Yes-by-default hardening prompts
   (`FLEET_NETWORK_HARDENING`, `FLEET_SECURITY_HARDENING` — see the sibling
   hardening documentation once that work lands). Every value can be
   supplied as an env var to skip its prompt entirely (useful for
   unattended installs):

   ```bash
   sudo env FLEET_DOMAIN=fleet.example.com FLEET_ACME_EMAIL=you@example.com bash bootstrap.sh
   ```
4. Install git + Ansible, clone (or pull) the code, install the required
   Ansible Galaxy collections, persist your answers to
   `/etc/ddev-fleet/local-vars.yml`, and run the provisioning playbook
   (Docker, DDEV, Caddy, the `fleet` system user, the `fleet.service`
   systemd unit — enabled but not started yet).
5. Print the fleet deploy public key, the generated admin password (if
   one was generated), and a "next steps" list.

**Re-running the installer** (e.g. to pick up a new `FLEET_REPO_VERSION`)
is safe: any key already present in `/etc/ddev-fleet/local-vars.yml` is
never re-prompted or overwritten, and no fresh admin password is printed
on a re-run.

### Delivering to a private fork

If you're running your own private fork rather than the public repo, the
bare `curl | bash` one-liner is auth-gated. Two options:

- **(a) Tar-over-ssh** (no server-side git auth needed at all):

  ```bash
  # on a machine that has the checkout:
  SRV=you@<server>
  TMP="$(mktemp -d)"
  git archive --format=tar HEAD | tar -x -C "$TMP"
  ssh "$SRV" 'sudo mkdir -p /opt/ddev-fleet'
  tar -C "$TMP" -cf - . | ssh "$SRV" 'sudo tar -C /opt/ddev-fleet -xf - && sudo chown -R root:root /opt/ddev-fleet'
  rm -rf "$TMP"
  ssh "$SRV" 'sudo env FLEET_SKIP_FETCH=1 bash /opt/ddev-fleet/bootstrap.sh'
  ```

  `FLEET_SKIP_FETCH=1` skips both git fetch points (bootstrap.sh's own
  clone/pull and the `fleet_service` Ansible role's clone), so a private
  repo needs no server-side auth at all.

- **(b) An https URL with embedded credentials** (e.g. a GitHub personal
  access token): `export FLEET_REPO_URL=https://<user>:<token>@github.com/<you>/ddev-fleet.git`
  then run `bootstrap.sh` normally.

`bootstrap.sh` is long (apt + Docker/DDEV/Caddy + the playbook) — run it
detached (`nohup … &` to a logfile, then poll) rather than holding an SSH
session open.

## 5. Add the deploy key to each forge

```bash
cat /srv/fleet/fleet-deploy-key.pub
```

Add it as a **read-only** deploy key on every forge hosting a project
you'll register in `/srv/fleet/config/fleet.yml`.

## 6. `fleet init` — create the registry and mint the Claude Code token

```bash
sudo -u fleet -i
cd /opt/ddev-fleet
venv/bin/fleet init
```

Prompts for the fleet domain (if not already passed `--domain`) and runs
`claude setup-token` to write `CLAUDE_CODE_OAUTH_TOKEN` into
`/srv/fleet/.secrets` (`600`) — skip with `--skip-claude`.

By default this creates `/srv/fleet/config/fleet.yml` from the bundled
`fleet.yml.dist` template (see `fleet.yml.dist` in the repo root for the
schema). If you're using the private-config-repo pattern instead
(recommended for teams/multi-machine setups — a private git repo holding
your real registry plus per-project assets), set
`FLEET_CONFIG_REPO=<git-url>` before running `fleet init` and it clones
your real registry + assets instead. `fleet init` never overwrites an
existing `fleet.yml` or re-clones an existing `config/` checkout — safe
to re-run.

### Multi-server: one config repo, per-host domain

The private-config-repo pattern above extends naturally to running
`fleet.yml` on **several servers at once** (e.g. staging + production, each
with its own domain) — point `FLEET_CONFIG_REPO` at the SAME repo on every
host. The one thing that can't be shared is the domain, so each host keeps
its own in `/srv/fleet/host.yml`, rendered automatically by the installer's
`caddy` Ansible role from the `fleet_domain` you answered/configured for
THAT host — you don't create or edit it by hand. `host.yml`'s domain always
wins over whatever `fleet.yml`'s own (shared) `fleet.domain` says, so
`fleet.domain` becomes optional in `fleet.yml` once every host in the
fleet has been provisioned (and thus has its own `host.yml`). See
`docs/configuration.md`'s "Per-host domain" section for the full precedence
rules and schema.

## 7. Start the fleet daemon

```bash
sudo systemctl start fleet.service
sudo systemctl status fleet.service
```

## 8. First deploy

The installer seeds a bundled **`demo`** project (a generic `type: php` DDEV
app with no database, built into a local repo at `/srv/fleet/_demo.git`), so
you can prove the fleet end-to-end before registering anything of your own:

```bash
sudo -u fleet fleet deploy demo          # -> https://demo--main.<domain>/
```

Browse to the printed URL — you should see the "ddev-fleet demo is running"
page. Tear it down with `sudo -u fleet fleet destroy demo--main` once you've
confirmed it.

Then register your own projects in `/srv/fleet/config/fleet.yml` (see
`fleet.yml.dist` for the schema — projects, `default_branch`/`default_template`,
and per-template `post_deploy` commands) and deploy:

```bash
fleet deploy <project> <template> --branch <ref>
```

Browse to the printed instance URL.

## 9. Web UI first-run check

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8765/api/jobs/nonexistent
```

Expected: `404`. Then browse to `https://fleet.<domain>`, confirm the
basic-auth prompt (the password printed by the installer, or one you
supplied), log in, and confirm the instance deployed in step 7 is
listed. Trigger a fresh deploy from the UI's deploy form and confirm the
live log pane updates over WebSocket while `post_deploy` runs.

## Switching TLS mode on an existing server

No need to re-run the full installer. From the server:

1. Edit `/etc/ddev-fleet/local-vars.yml`, set `fleet_tls_mode: "ovh_dns"`
   (or back to `"on_demand"`).
2. If switching **to** `ovh_dns`, create `/etc/caddy/ovh.env` (`0600
   root:root`) with `OVH_ENDPOINT`, `OVH_APPLICATION_KEY`,
   `OVH_APPLICATION_SECRET`, `OVH_CONSUMER_KEY` — see "Choosing a TLS
   mode" above for the token-creation steps, **including testing the
   token first** — a token that works fine on one OVH-scoped server can
   be IP-restricted to a different one and fail with `403 "This call has
   not been granted"` only once you're mid-switch. Skip this step if
   switching back to `on_demand`. If you hand-edit an already-deployed
   `/etc/caddy/ovh.env` outside of step 3 below (e.g. swapping in a
   corrected token), `systemctl restart caddy` yourself — nothing else
   will pick it up.
3. Re-apply just the `caddy` role:
   ```bash
   cd /opt/ddev-fleet/ansible
   sudo ansible-playbook caddy-only.yml
   ```
   This is the same scoped-playbook pattern as `ddev-only.yml` — never run
   the full `site.yml` against a live host (see `CLAUDE.md` /
   `docs/operations.md`).

**Upgrade order matters — read this if the server is already running an
older `fleet.core.caddyports`.** Named-port snippets (Typesense, etc.) only
`import` the shared `tls.conf` file when it's already on disk; if it isn't
yet (e.g. right after a plain code-only rollout — `push-product`'s git-pull
+ `systemctl restart fleet`, which reconciles port snippets on daemon
startup with no Ansible involved), they fall back to inlining the legacy
`tls { on_demand }` block instead, so an un-migrated server never ends up
importing a file that doesn't exist (which would otherwise fail `caddy
validate` and take every instance down on Caddy's next restart). So:

1. Run step 3 above (`caddy-only.yml`) **first** — this creates
   `/etc/caddy/fleet/tls.conf`.
2. Then run `sudo -u fleet fleet refresh-ports` (or just let the next
   `fleet.service` restart do it) to rewrite existing named-port snippets
   from the inline fallback over to the `import`.

Doing it in the other order is harmless (the fallback keeps Caddy healthy
either way) but leaves snippets on the inline block until the next
`refresh-ports`/restart.

## See also

- `docs/operations.md` — ongoing code updates, rollback, password
  rotation, the verification checklist as a repeatable template.
- `docs/networking.md` — TLS termination points and every port in one
  table, including the `fleet_tls_mode` mechanics.
- `docs/README-authelia.md` — full design, `fleet.yml` `users:` schema, and
  how to switch an existing server's `auth_mode` later.
- `fleet.yml.dist` — the `fleet.yml` registry schema, with the full set
  of optional keys (named ports, Typesense, additional hostnames)
  commented out as examples.
- `README.md` — the full CLI command reference.
