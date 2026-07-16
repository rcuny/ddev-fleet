# Server rollout runbook

Ordered checklist for the first provisioning of a ddev-fleet host. Run
each step in order. Steps in section 6 correspond to spec §16 items that
could not be confirmed inside the companion dev container (no
Docker/systemd/caddy binaries there) and MUST be confirmed live, on the
real host, before it is considered production-ready.

## 1. DNS

Before running bootstrap, create the wildcard DNS record. A wildcard
matches exactly one label (spec §9.4), so both of the following are
required — `fleet.<domain>` is NOT covered by the `*.fleet.<domain>`
wildcard:

```
fleet.<domain>       A     <server-ip>
*.fleet.<domain>     A     <server-ip>
```

## 2. Deliver the code + run bootstrap

The repo is PRIVATE, so there is no server-side git auth. **Recommended:
push a clean checkout from a machine that already has the repo, then run
bootstrap in skip-fetch mode** — nothing secret ever lands on the server.

Set the login accordingly first. A minimal **Debian 13** cloud image logs
in as `debian@` with passwordless `sudo` (root SSH disabled); a bare
netinstall may allow `root@` directly. The verified 2026-07-13 rollout used
`debian@` + `sudo`; substitute `SRV=debian@<server>` below (or `root@…`).

The minimal image ships **no `rsync`**, so delivery uses a `tar`-over-ssh
pipe (tar is in the base system — nothing to install on the server):

```bash
# --- on the build machine (has the repo) --------------------------------
SRV=debian@<server>
TMP="$(mktemp -d)"
git -C /path/to/ddev-fleet archive --format=tar HEAD | tar -x -C "$TMP"   # pristine, no .git, no uncommitted cruft
ssh "$SRV" 'sudo mkdir -p /opt/ddev-fleet'
tar -C "$TMP" -cf - . | ssh "$SRV" 'sudo tar -C /opt/ddev-fleet -xf - && sudo chown -R root:root /opt/ddev-fleet'
rm -rf "$TMP"

# --- on the server ------------------------------------------------------
ssh "$SRV" 'sudo env FLEET_SKIP_FETCH=1 bash /opt/ddev-fleet/bootstrap.sh'
```

`FLEET_SKIP_FETCH=1` makes bootstrap use the code already on disk and skip
**both** git fetch points — its own clone/pull AND the `fleet_service`
role's clone (via `-e fleet_skip_fetch=true`) — so a private repo needs no
server-side auth. It aborts with a clear error if the code is missing. To
upgrade later, re-run the same tar pipe + command. (`bootstrap.sh` is long —
apt + Docker/DDEV/Caddy + playbook — so run it detached: `nohup … &` to a
logfile and poll, rather than holding an SSH session open.)

Alternative delivery methods (only if not using the tar pipe above):
```bash
# (a) install rsync on the server (sudo apt-get install -y rsync), then
#     rsync -az --delete --rsync-path="sudo rsync" "$TMP"/ "$SRV":/opt/ddev-fleet/
# (b) scp bootstrap.sh, clone over https with an app-password:
#     export FLEET_REPO_URL=https://<user>:<app-password>@bitbucket.org/personal_maintainer/ddev-fleet.git; sudo bash bootstrap.sh
# (c) pre-clone /opt/ddev-fleet manually, then: sudo bash /opt/ddev-fleet/bootstrap.sh
# (d) public-repo one-liner: curl -fsSL https://bitbucket.org/personal_maintainer/ddev-fleet/raw/main/bootstrap.sh | sudo bash
```

The first run uses the placeholder `fleet_admin_bcrypt_hash` in
`ansible/group_vars/all.yml` — basic auth will not yet accept a real
password. Continue to step 3, then re-run bootstrap (or just re-run the
playbook, step 3 below).

## 2a. Updating an already-deployed server (code update)

Sections 2–5 are first-provisioning only. Once a host is live, ship a new
engine version with the lightweight path below. A full `bootstrap.sh` re-run
(which runs the whole Ansible playbook) is **only** needed when system
packages, the venv/dependencies, systemd units, or the Caddyfile change. For
a pure Python code change the package is installed **editable**
(`pip install -e`, `__editable__.ddev_fleet-*.pth` in the venv), so replacing
the source under `/opt/ddev-fleet/src` and restarting `fleet.service` is
enough — the running daemon picks up the new code on restart.

### Option A — tar-over-ssh update (works today)

Current mechanism: the server has **no git credential for the private
`ddev-fleet.git`**, and `/opt/ddev-fleet` is a plain extracted tree (not a
git checkout). Ship from a machine that has the repo and SSH access:

```bash
SRV=debian@ddev.personal.example
# 1. (recommended) back up the current source for rollback
ssh "$SRV" 'sudo rm -rf /opt/ddev-fleet.src.bak && sudo cp -a /opt/ddev-fleet/src /opt/ddev-fleet.src.bak'
# 2. ship the committed HEAD tree (does not touch the venv — venv is gitignored)
git -C /path/to/ddev-fleet archive --format=tar HEAD \
  | ssh "$SRV" 'sudo tar -C /opt/ddev-fleet -xf - && sudo chown -R root:root /opt/ddev-fleet'
# 3. refresh the config/registry checkout (brings new assets + fleet.yml)
ssh "$SRV" 'sudo -u fleet git -C /srv/fleet/config pull --ff-only'
# 4. restart the daemon
ssh "$SRV" 'sudo systemctl restart fleet && sleep 2 && systemctl is-active fleet'
# 5. verify
ssh "$SRV" 'curl -s -o /dev/null -w "daemon:%{http_code}\n" http://127.0.0.1:8765/'
```

Run `sudo env FLEET_SKIP_FETCH=1 bash /opt/ddev-fleet/bootstrap.sh` between
steps 2 and 4 **only** when dependencies or provisioning changed (not for a
plain code change).

Rollback:

```bash
ssh "$SRV" 'sudo rm -rf /opt/ddev-fleet/src && sudo mv /opt/ddev-fleet.src.bak /opt/ddev-fleet/src && sudo systemctl restart fleet'
```

### Option B — `git pull` update (enabled 2026-07-16)

`/opt/ddev-fleet` is a git checkout of `ddev-fleet.git`, owned by `fleet` (the
`fleet.service` user), tracking `origin/main`. The `fleet` user holds the
read-only deploy key registered on the Bitbucket repo (same pattern as
`ddev-fleet-config.git`). Ongoing updates:

```bash
SRV=debian@ddev.personal.example
ssh "$SRV" '
  sudo -u fleet git -C /opt/ddev-fleet pull --ff-only && \
  sudo -u fleet git -C /srv/fleet/config pull --ff-only && \
  sudo systemctl restart fleet && sleep 2 && systemctl is-active fleet'
```

The package is installed editable, so the restart alone picks up new source —
no reinstall. Run `bootstrap.sh` / the Ansible playbook only when dependencies
or provisioning change.

> **How the checkout was created (in place, 2026-07-16):**
> `sudo systemctl stop fleet`; `sudo chown -R fleet:fleet /opt/ddev-fleet`;
> then as `fleet`: `git init -b main`, `git remote add origin <url>`,
> `git fetch origin`, `git reset --hard origin/main` (this rewrites only
> git-tracked files; the untracked, gitignored `venv/` is preserved);
> `sudo systemctl start fleet`.
>
> **Ownership:** `/opt/ddev-fleet` is `fleet:fleet` — exactly the state the
> `fleet_service` Ansible role enforces (its first task recursively chowns the
> checkout to the fleet user, and the clone/pip tasks run as that user), so
> re-running `bootstrap.sh` *preserves* fleet ownership and does not break
> `git pull`. This in-place conversion simply brings the host to the state the
> role already expects: a fleet-owned git checkout. A host operator's CLI
> wrapper `/usr/local/bin/fleet` (installed by the role) execs the venv CLI as
> the fleet user, so `fleet secret set …`, `fleet list`, etc. can be run from
> any admin account without `sudo -u fleet` or the venv path.

## 3. Generate the admin password hash

```bash
caddy hash-password
```

Paste the output into `fleet_admin_bcrypt_hash` in
`/opt/ddev-fleet/ansible/group_vars/all.yml`, then redeploy the Caddyfile:

```bash
ansible-playbook -c local /opt/ddev-fleet/ansible/site.yml
```

## 4. Add the deploy key to each forge

```bash
cat /srv/fleet/fleet-deploy-key.pub
```

Add it as a **read-only** deploy key on Bitbucket (and GitHub, if used)
for every project registered in `/srv/fleet/config/fleet.yml`.

## 5. `fleet init` — mint the Claude Code token

```bash
sudo -u fleet -i
cd /opt/ddev-fleet
venv/bin/fleet init
```

Runs `claude setup-token` as `fleet`, writes `CLAUDE_CODE_OAUTH_TOKEN` into
`/srv/fleet/.secrets` (`600`).

## 6. Verification items (spec §16 — confirm live here, not in-container)

- **(a) `config.fleet.yaml` merge order.** Deploy a test instance, run
  `ddev describe -j` inside its directory, confirm `name`/`project_tld`
  reflect the fleet-injected values ahead of the project's own
  `config.yaml`/`config.local.yaml`. If they do not, fall back to
  `ddev config --project-name=<instance-id>` invoked explicitly at deploy
  time — tracked as a Plan 3 follow-up, not this plan.
- **(b) Cheapest `web_environment` reload path.** On a running test
  instance, change `CLAUDE_CODE_OAUTH_TOKEN` in `.ddev/config.fleet.yaml`,
  try `ddev restart`, then confirm with
  `ddev exec env | grep CLAUDE_CODE_OAUTH_TOKEN` that the new value took
  effect. Record whether `ddev restart` suffices or a full
  `ddev stop && ddev start` is required — this determines the
  implementation of `fleet refresh-claude-token` in Plan 3.
- **(c) Caddy on-demand TLS + Host pass-through end to end.** After
  deploying a test instance, request `https://<instance-id>.<fleet_domain>`
  from an external client; confirm Caddy requests a certificate (check
  `journalctl -u caddy`), the `/api/tls-authorize` call succeeds, and the
  correct project responds — not a different instance and not a TLS or
  routing error.
- **(f) `caddy validate` on the generated Caddyfile:**

  ```bash
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
  ```

  (Already wired as the `validate:` parameter on the caddy role's
  `template` task in `ansible/roles/caddy/tasks/main.yml` — every apply
  re-validates automatically. This command is the manual/interactive
  re-check.)

Additional live checks, not in spec §16 but load-bearing for this plan:

- **`systemd-analyze verify fleet.service`** — confirm the generated unit
  has no structural errors:

  ```bash
  systemd-analyze verify fleet.service
  ```

- **DDEV router binds loopback-only** — confirm it is not reachable from
  outside the host:

  ```bash
  ss -tlnp | grep -E ':(8080|8443)\b'
  ```

  Expected: both listeners show `127.0.0.1:8080` / `127.0.0.1:8443`, never
  `0.0.0.0` or `:::`.

- **DDEV global config lands in the fleet user's HOME, not root's** —
  confirm the router ports were written to `/home/fleet/.ddev`, never
  `/root/.ddev`:

  ```bash
  sudo -u fleet cat /home/fleet/.ddev/global_config.yaml | grep router_
  ```

  Expected: `router_http_port: 8080` and `router_https_port: 8443` appear
  in the output.

- **Typesense edge exposure** (only relevant for instances of a project with
  `typesense: true`) — confirm the shared ddev-router publishes the
  Typesense HTTP entrypoint on loopback, then confirm the same-origin route
  end-to-end:

  ```bash
  # Typesense edge exposure (only relevant for instances of a project with `typesense: true`):
  # confirm the shared ddev-router publishes the Typesense HTTP entrypoint on loopback,
  ss -tlnp | grep -E ':8108\b'
  # Expected: a 127.0.0.1:8108 listener once at least one typesense-enabled instance is up.
  # Then confirm the same-origin route end-to-end (replace the host with a real instance):
  curl -s -o /dev/null -w '%{http_code}\n' https://<project>--<label>.fleet.personal.example/_typesense/health
  # Expected: 200 (Typesense health via the Caddy /_typesense route).
  ```

  Per the design spec's "Key assumption to verify": if `:8108` is NOT bound
  on loopback (DDEV binds a per-project ephemeral port instead), the static
  Caddy target is wrong and the edge design must be revisited.

- **`known_hosts` has entries for both git forges** — confirm the
  ed25519-only keyscan seeded a usable entry for each:

  ```bash
  sudo -u fleet ssh-keygen -F bitbucket.org -f /home/fleet/.ssh/known_hosts
  sudo -u fleet ssh-keygen -F github.com -f /home/fleet/.ssh/known_hosts
  ```

  Expected: both commands exit `0`.

- **`fleet_admin_bcrypt_hash` is your own password, not the shipped
  placeholder** — the shipped value in `ansible/group_vars/all.yml` is a
  syntactically valid bcrypt hash of a discarded random secret, so it
  fails closed (no password will match it), but it is NOT your password.
  Confirm it was replaced with your own `caddy hash-password` output
  (step 3, above) before exposing `fleet.<domain>` publicly.

## 7. Start the fleet daemon

Only after section 6 passes:

```bash
sudo systemctl start fleet.service
sudo systemctl status fleet.service
```

## 8. First deploy

Pick a project already registered in `/srv/fleet/config/fleet.yml` (or register
one with `fleet project add`) and run, as the `fleet` user:

```bash
fleet deploy <project> <template> --branch <x>
```

then browse to the printed instance URL.

## 9. Server-side Claude context

Copy the CLI/registry reference doc into `/srv/fleet/` so `claude -p "..."`
run on the server (spec §10.2) has grounded context:

```bash
cp /opt/ddev-fleet/docs/srv-fleet-CLAUDE.md /srv/fleet/CLAUDE.md
```

Re-run this after every `ddev-fleet` upgrade if `docs/srv-fleet-CLAUDE.md`
changed.

## 10. Web UI first-run check

After `fleet.service` is running (section 7):

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8765/api/jobs/nonexistent
```

Expected: `404`.

Then, from a browser: visit `https://fleet.<domain>`, confirm the
basic-auth prompt appears, log in, and confirm the instance deployed in
section 8 is listed. Trigger a fresh deploy (or redeploy) from the UI's
deploy form and confirm the live log pane updates over WebSocket while
`post_deploy` runs.
