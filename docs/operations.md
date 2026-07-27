---
Author: Claude Code
Reviewer: none
Last updated: 2026-07-24
Type: documentation
---

# Operating ddev-fleet

Ongoing procedures for an already-provisioned host. For first-time setup
see `docs/installation.md`.

## Updating the code

`/opt/ddev-fleet` is a `fleet`-owned git checkout tracking `origin/main`
(or whatever `fleet_repo_url`/`fleet_repo_version` point at), holding the
read-only deploy key. The package is installed **editable**
(`pip install -e`), so a restart alone picks up new source — no reinstall
needed for a pure Python change:

```bash
SRV=you@<server>
ssh "$SRV" '
  sudo -u fleet git -C /opt/ddev-fleet pull --ff-only && \
  sudo -u fleet git -C /srv/fleet/config pull --ff-only && \
  sudo systemctl restart fleet && sleep 2 && systemctl is-active fleet'
```

Run the full installer (`sudo bash /opt/ddev-fleet/bootstrap.sh`, or
`ansible-playbook site.yml` directly) only when system packages, the
venv/dependencies, systemd units, or the Caddyfile change — not for a
plain code change.

**CAVEAT** — do NOT run the full `ansible-playbook site.yml` against a
live host casually. The `fleet_service` role's git task rewrites
`/opt/ddev-fleet`'s git remote to the `https://` `fleet_repo_url` in
`ansible/group_vars/all.yml`, which breaks the `fleet`-user
SSH-key-based `git pull` above. When only the Caddy config needs
reapplying (e.g. after touching `Caddyfile.j2` or a port variable),
apply the `caddy` role alone via a scoped one-off playbook, not the full
`site.yml`. Rotating the dashboard admin password is NOT one of these
cases — see below, it never touches Ansible.

### Rollback

If you keep a backup of the previous source (`cp -a /opt/ddev-fleet/src
/opt/ddev-fleet.src.bak` before updating):

```bash
ssh "$SRV" 'sudo rm -rf /opt/ddev-fleet/src && sudo mv /opt/ddev-fleet.src.bak /opt/ddev-fleet/src && sudo systemctl restart fleet'
```

## Rotating the dashboard admin password

No Ansible run, no Caddyfile redeploy — this hashes the password, writes
the fleet-owned Caddy snippet at `/etc/caddy/fleet/admin-auth.conf`,
validates the Caddyfile, and reloads Caddy directly:

```bash
sudo -u fleet fleet rotate-admin-password
# prints the new password ONCE — save it now, it is not stored anywhere in the clear
```

Or `fleet set-admin-password <password>` to choose your own. See
`fleet.core.caddyauth`. Re-running the `caddy` Ansible role afterwards
will **not** revert a rotated password — the seed step only ever runs
once, on a host where the snippet doesn't exist yet.

## Rotating the Claude Code OAuth token

```bash
sudo -u fleet fleet refresh-claude-token [--restart]
```

Runs `claude setup-token` interactively, writes the new
`CLAUDE_CODE_OAUTH_TOKEN` to `/srv/fleet/.secrets`, and rewrites every
deployed instance's `.ddev/config.fleet.yaml` with the new value. By
default this does **not** restart any running instance (restarting
everything is slow with many instances live) — it prints the exact `cd
<instance-dir> && ddev restart` command for every running instance that
still has the old token loaded. Pass `--restart` to restart every running
instance immediately instead. `fleet set-claude-token <token> [--restart]`
does the same propagation for a token you already have (e.g. minted
elsewhere), instead of running `claude setup-token` itself.

## Managing instances at scale (bulk operations)

`fleet start`, `fleet stop`, and `fleet destroy` each accept either one or
more explicit instance ids, or a selector — never both at once:

```bash
fleet start <instance-id> [<instance-id> ...]
fleet start --all
fleet start --project <project>
fleet start --state running|deployed

fleet stop    --all | --project <project> | --state running|deployed
fleet destroy --all | --project <project> | --state running|deployed [--yes]
```

`--all` cannot be combined with `--project`/`--state`. A selector that
matches nothing is a no-op (exit 0), not an error. `fleet destroy` with a
selector (or more than one explicit id) always asks for confirmation —
type the number of instances it's about to destroy — unless `--yes` is
passed; a *single explicit* instance id (the traditional `fleet destroy
<id>` form) is destroyed immediately without a prompt, for backward
compatibility. Each command prints a per-instance `OK`/`FAILED` line and a
final `N succeeded, M failed` summary; the exit code is `0` if all
succeeded, `1` if all failed, `2` on a partial failure.

### Deploying multiple instances at once

```bash
fleet deploy <project> [<template>] --branch <ref> --count <n> [--skip-disk-check]
```

`--count`/`-n` (default `1`) deploys `n` independently-labelled instances
of the same project/template/branch in one call, gated by a disk-headroom
check before starting (skip it with `--skip-disk-check` if you're sure).
With `--count 1` (the default) `fleet deploy` behaves exactly as a single
deploy and prints just the instance URL; with `--count 0` it prints
`nothing to deploy (--count=0)` and exits `0` without doing anything;
`--count 2` or higher prints the same per-instance `OK`/`FAILED` lines and
summary as the bulk commands above.

## Regenerating instance config without a redeploy

```bash
fleet refresh-instance-config <instance-id> [--restart]
```

Rewrites just that instance's `.ddev/config.fleet.yaml` (including the
Claude onboarding hook) without running a full deploy. The config rewrite
always happens; `--restart` additionally restarts the instance to apply
it (omit it and the command prints the `ddev restart` command to run
yourself).

## Applying `fleet.yml` port changes

```bash
fleet refresh-ports
```

Reconciles the Caddy port-exposure snippets (and UFW rules, if the
network-hardening role is installed) to whatever `fleet.yml`'s
`fleet.ports`/per-project `ports:` keys currently say — no Ansible
re-run, no redeploy needed. Run this after editing the ports catalogue
or a project's `ports:` list. See `docs/networking.md` for the full
port-exposure mechanism and the runbook for adding a new named port.

## Refreshing the registry checkout

```bash
fleet refresh-config
```

If `/srv/fleet/config` is a git checkout (the `FLEET_CONFIG_REPO` mode),
this does a `git fetch --quiet && git pull --ff-only` on it. If it's a
local file (the default `fleet.yml.dist`-derived mode), it prints that
there's nothing to pull — edit `fleet.yml` in place instead.

## Asset and secret management

```bash
fleet assets push <project> <src> <dest-rel>    # copy a local file into assets/<project>/<dest-rel>
fleet secret set <project> <key> <value>        # write KEY=VALUE into secrets/<project>.env (0600)
fleet snapshot <instance-id> [--dest-rel=...]    # ddev export-db --gzip=false into the project's asset tree
```

`fleet snapshot`'s default destination is
`dumps/default-<instance-id>.sql` — never `dumps/default.sql`, which is
the project's shared dump that every instance hard-links and imports
from by default; `fleet snapshot` refuses to write there even via an
explicit `--dest-rel`. Promote a snapshot to the shared default
explicitly (e.g. `fleet assets push`) once you've verified it. Secret
values written with `fleet secret set` become available at deploy time
as `[[key-with-dashes]]` tokens (e.g. `SLACK_BOT_TOKEN` →
`[[slack-bot-token]]`).

## Verification checklist (repeatable template)

Re-run any of these after a change that could plausibly affect it — not
just once at first rollout:

- **`caddy validate` on the generated Caddyfile:**
  ```bash
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
  ```
  (Already wired as the `caddy` role's `template` task `validate:`
  parameter — every apply re-validates automatically. This is the
  manual/interactive re-check.)
- **`systemd-analyze verify fleet.service`** — no structural unit errors.
- **DDEV router binds loopback-only:**
  ```bash
  ss -tlnp | grep -E ':(8080|8443)\b'
  ```
  Expected: both listeners show `127.0.0.1:...`, never `0.0.0.0`/`:::`.
- **DDEV global config lands in the fleet user's HOME, not root's:**
  ```bash
  sudo -u fleet cat /home/fleet/.ddev/global_config.yaml | grep router_
  ```
  Expected: `router_http_port: 8080` and `router_https_port: 8443`.
- **Typesense edge exposure** (only for a project with `typesense: true`):
  ```bash
  ss -tlnp | grep -E ':8108\b'                                  # loopback listener once a typesense-enabled instance is up
  curl -s -o /dev/null -w '%{http_code}\n' https://<project>--<label>.fleet.example.com:9108/health
  ```
  Expected: a `200`. See `docs/README-typesense.md`.
- **`known_hosts` has entries for every forge you use:**
  ```bash
  sudo -u fleet ssh-keygen -F <forge-hostname> -f /home/fleet/.ssh/known_hosts
  ```
  Expected: exit `0`.
- **The dashboard admin password is not left at a value you don't
  control** — if you ever set one manually rather than using the
  installer-generated one, confirm it's what you expect:
  ```bash
  sudo cat /etc/caddy/fleet/admin-auth.conf   # "admin $2..." — a real hash
  ```

## Regenerating the server-side Claude context

Copy the CLI/registry reference doc into `/srv/fleet/` so `claude -p
"..."` sessions run on the server (as the `fleet` user) have grounded
context:

```bash
cp /opt/ddev-fleet/docs/srv-fleet-CLAUDE.md /srv/fleet/CLAUDE.md
```

Re-run this after every `ddev-fleet` upgrade that changed
`docs/srv-fleet-CLAUDE.md` (a CLI surface change, a registry schema
change, or a new server-side Claude skill).

## See also

- `docs/installation.md` — first-run checklist.
- `docs/networking.md` — the port-exposure mechanism behind
  `fleet refresh-ports`.
- `README.md` — the full CLI command reference.
