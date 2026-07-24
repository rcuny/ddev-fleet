---
Author: Claude Code
Reviewer: none
Last updated: 2026-07-24
Type: documentation
---

# Installing ddev-fleet

This is the first-run checklist: DNS, the installer, adding the deploy
key, minting the Claude Code token, and the first deploy. For ongoing
operations (updates, rollback, password rotation) see `docs/operations.md`.

## 1. DNS

Before running the installer, create the wildcard DNS record. A wildcard
matches exactly one label, so both of the following are required —
`fleet.<domain>` is NOT covered by the `*.fleet.<domain>` wildcard:

```
fleet.<domain>       A     <server-ip>
*.fleet.<domain>     A     <server-ip>
```

## 2. Run the installer

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
   supply it), `FLEET_REPO_VERSION` (optional, defaults to `main`), and two
   Yes-by-default hardening prompts (`FLEET_NETWORK_HARDENING`,
   `FLEET_SECURITY_HARDENING` — see the sibling hardening documentation
   once that work lands). Every value can be supplied as an env var to
   skip its prompt entirely (useful for unattended installs):

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

## 3. Add the deploy key to each forge

```bash
cat /srv/fleet/fleet-deploy-key.pub
```

Add it as a **read-only** deploy key on every forge hosting a project
you'll register in `/srv/fleet/config/fleet.yml`.

## 4. `fleet init` — create the registry and mint the Claude Code token

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

## 5. Start the fleet daemon

```bash
sudo systemctl start fleet.service
sudo systemctl status fleet.service
```

## 6. First deploy

Register a project in `/srv/fleet/config/fleet.yml` (see `fleet.yml.dist`
for the schema — projects, `default_branch`/`default_template`, and
per-template `post_deploy` commands), then:

```bash
fleet deploy <project> <template> --branch <ref>
```

Browse to the printed instance URL.

## 7. Web UI first-run check

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8765/api/jobs/nonexistent
```

Expected: `404`. Then browse to `https://fleet.<domain>`, confirm the
basic-auth prompt (the password printed by the installer, or one you
supplied), log in, and confirm the instance deployed in step 6 is
listed. Trigger a fresh deploy from the UI's deploy form and confirm the
live log pane updates over WebSocket while `post_deploy` runs.

## See also

- `docs/operations.md` — ongoing code updates, rollback, password
  rotation, the verification checklist as a repeatable template.
- `fleet.yml.dist` — the `fleet.yml` registry schema, with the full set
  of optional keys (named ports, Typesense, additional hostnames)
  commented out as examples.
- `README.md` — the full CLI command reference.
