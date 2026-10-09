---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-09
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

Also — if you do run the full installer, run it via `sudo` from your
actual login shell, not detached as root with no controlling `sudo`
session (e.g. `systemd-run`). With no `$SUDO_USER`, the optional
`security_hardening` role's sshd `AllowUsers` can lock out your real SSH
login user; see `docs/installation.md` §4's SSH-lockout warning and
`FLEET_SSH_ALLOW_USERS`.

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

### Automatic instance startup after reboot

DDEV instances do **not** auto-start on their own when the host reboots —
Docker restarts containers per its own restart policy, but `ddev start`'s
project-level bookkeeping (router registration, `ddev-ssh-agent`, etc.)
still needs to run per instance. The `fleet_service` Ansible role installs
`fleet-boot.service`, a `Type=exec` systemd unit enabled at boot
(`WantedBy=multi-user.target`, ordered `After=docker.service`) that runs:

```bash
fleet start --all --sequential --timeout 1800 --retry-port-conflict
```

`--sequential` starts every instance **one at a time**, in the same
alphabetical instance-id order `fleet list` shows, instead of the default
2-at-a-time `run_concurrent` bulk path — running many `ddev start`s at once
right after a reboot causes CPU spikes and `ddev-ssh-agent` registration
races. systemd does not kill the unit partway through a long batch.

The unit is `Type=exec`, **not** `oneshot`, so it does not hold up
`multi-user.target`: the dashboard, Caddy and the Authelia portal are
reachable right after boot while instances are still starting one by one in
the background (with `Type=oneshot` the Authelia unit, ordered
`After=multi-user.target`, waited for the whole batch and every public URL
502'd meanwhile — FLE-4). `RemainAfterExit=no`: the unit goes inactive once
the batch ends, and shows `failed` if `fleet start --all` exited non-zero
(some instance failed to start); the log stays in the journal.

`--timeout 1800` is a **per-instance hang guard**, not a slowness limit: if
a single `ddev start` doesn't finish within 30 minutes — e.g. an
`ssh-agent` passphrase prompt blocking forever in this non-interactive boot
context — it is killed and that instance is recorded as a failed result
(`FAILED — ddev start timed out after 1800.0s for <instance-id>`), and the
batch moves straight on to the next instance instead of stalling forever.
It never fires on ordinary slowness; 30 minutes is a generous ceiling. Omit
`--timeout` for the old no-timeout (wait forever) behaviour on a manual
`fleet start`/`fleet stop` invocation — it is opt-in everywhere except
`fleet-boot.service`.

`--retry-port-conflict` self-heals a **separate, distinct** failure mode
from the hang guard above: even run one instance at a time, some instances'
`ddev start` **FAST-FAILs** on a Docker port-allocation race — the db
container's host port hasn't been released by the kernel yet, e.g.:

```
failed to set up container networking: driver failed programming external connectivity on endpoint ddev-<id>-db ...
Bind for 127.0.0.1:32839 failed: port is already allocated
```

— leaving the web container Up-but-unhealthy. Because it fails almost
instantly, `--timeout` never sees it. The proven manual fix is a clean
`fleet stop <id>` then `fleet start <id>` (releases and reallocates the
host ports). With `--retry-port-conflict`, `fleet start` does exactly that
automatically — ONE `ddev stop` + `ddev start` — before giving up on that
instance; a still-failing retry (or any other kind of failure) still just
fails that instance and the sequential batch continues, as before. It is
opt-in (default off, no behaviour change) everywhere except
`fleet-boot.service`, which always passes it.

Check it after a reboot:

```bash
systemctl status fleet-boot.service     # active (running) while the batch runs
journalctl -u fleet-boot.service -b -f  # follow per-instance progress
```

**NOTE:** it starts **every** existing instance, including ones you had
deliberately stopped before the reboot to save RAM — there is no persisted
"was running" state yet, so a deliberately-stopped instance will be woken
back up too.

### The `fleet` tmux session after a reboot (`fleet-tmux.service`)

tmux does not survive a reboot, and a tmux server started by the daemon would
die on every `systemctl restart fleet`. The `fleet_service` Ansible role
therefore installs `fleet-tmux.service` (`Type=oneshot` + `RemainAfterExit=yes`,
`After=fleet.service`, `WantedBy=multi-user.target`), which owns the `fleet`
tmux server **in its own cgroup**. It runs `fleet tmux --ensure` — create the
session if missing, then a window per instance with sidebar + tty1 + tty2 as
**plain shells** — and exits; the tmux server keeps running under the unit.
It is deliberately *not* ordered after `fleet-boot.service` (it must not wait
for the instance batch; a window is just a shell in the instance directory), and
it applies no sandboxing (the panes are operator shells that need `sudo`, `ddev`
and `git`).

What this means after a reboot:

- The session exists with plain shells; **no tty command is typed** (those are a
  deploy action — `fleet deploy`/`fleet redeploy` only), so autonomous commands
  such as `/jira work` are never relaunched by a reboot.
- `fleet tmux` just attaches (and repairs layout); it types nothing.
- `systemctl restart fleet` leaves the session and its panes alive.
- A closed window is recreated as a plain shell by the next `fleet tmux`.

| Event | tty1/tty2 |
|---|---|
| deploy / redeploy (CLI or web UI) | typed from the template resolved at deploy time |
| reboot, `fleet tmux`, closed window recreated, `^b R` | plain shells |

```bash
systemctl status fleet-tmux.service      # active (exited) — the tmux server runs in its cgroup
sudo systemctl start fleet-tmux          # (re)create the session if it is missing
sudo systemctl stop fleet-tmux           # ends the fleet session and ALL its panes
```

If the service is missing or failed, a CLI deploy with tty commands creates the
session itself (as `fleet tmux` does); a web-UI deploy only logs `fleet-tmux.service
not running: tty commands not typed; start it with `sudo systemctl start fleet-tmux`
and redeploy`. Rolling it out on an existing server: re-render
`fleet-tmux.service.j2`, install it root:root 0644, `daemon-reload`, then
`systemctl enable --now fleet-tmux`. If an operator-created `fleet` session
already exists it is adopted as-is (reconcile only) but stays outside the unit's
cgroup until the session is recreated. (`ansible-playbook … --tags fleet_tmux`
applies only the two new role tasks.)

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
fleet secret set <project> <key>               # value from a hidden prompt or stdin; stored encrypted once a host key exists
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

## Encrypted project secrets (FLE-21)

Project secrets (`SLACK_BOT_TOKEN`, API keys, ...) can rest as OpenPGP
ciphertext instead of plaintext. One GnuPG **host key** per server encrypts
them; the deploy pipeline decrypts them with `gpg` and substitutes them as
`[[token]]`s exactly as before. A server without a host key keeps working
unchanged (legacy plaintext `secrets/<project>.env`), so rolling this out is
opt-in per server.

```bash
sudo -u fleet fleet keys init            # once per server; prints the fingerprint
sudo -u fleet fleet keys show            # fingerprint, subkey id, public-key path (also rewrites host-public-key.asc)
sudo -u fleet fleet secret list <project>
printf '%s' "$TOKEN" | sudo -u fleet fleet secret set <project> SLACK_BOT_TOKEN
```

Layout: `/srv/fleet/gnupg/` is `GNUPGHOME` (0700, owner `fleet`);
`/srv/fleet/secrets/<project>/<KEY>.asc` are the ciphertexts (dir 0700, files
0600). The `fleet_user` Ansible role creates both directories.

### Migrating existing plaintext secrets

1. `sudo -u fleet fleet keys init` (skip if `fleet keys show` already works).
2. `sudo -u fleet fleet secret migrate --all` (or one `<project>`). Each value
   is encrypted, **verified by decrypting it**, and only then is
   `secrets/<project>.env` deleted. It is all-or-nothing per project, safe to
   re-run, and refuses (changing nothing) if a legacy name is not
   `^[A-Z][A-Z0-9_]{0,63}$`: rename it in the `.env` and retry. If an `.asc`
   already exists for a key, migrate keeps the encrypted value and discards the
   legacy one, so before rotating a secret check `fleet secret list` and a
   deploy to see which value is live. `migrate` on a project with no `.env` is
   a no-op, and it does not require the project to be registered in `fleet.yml`.
3. `sudo -u fleet fleet secret list <project>` should show every name as
   `encrypted`; `ls /srv/fleet/secrets/*.env` should find nothing.
4. The deleted plaintext can still exist in disk blocks and in earlier
   backups or snapshots. **Rotate any secret that mattered.**
5. Redeploy an instance that uses a secret and check it still receives it.

### What this does and does not protect

Protected: the stored secret files and their backups (ciphertext only).
Not protected: anyone who is root on the host or can read
`/srv/fleet/gnupg/` (the host private key has **no passphrase**, because
deploys are unattended; it is protected by file permissions only), and the
decrypted values that deploy writes into each instance's asset files, `post_deploy`
commands and tty commands, as before.

### Back up and loss of the host key

Back up `/srv/fleet/gnupg/` **separately from** `/srv/fleet/secrets/` (a
backup holding both defeats the encryption). If the host key is lost, every
`.asc` becomes unreadable: deploys that need them fail with a decryption error
and you must `fleet secret set` the values again. Deleting the key on purpose
(`rm -r /srv/fleet/gnupg`, then `fleet keys init`) is the same operation, so
re-create the secrets afterwards.

### Setting a secret from the web UI

The dashboard's **Secrets** page (`/secrets`) sets and deletes a project's secrets without ever
sending plaintext: the value is encrypted in the browser with OpenPGP.js to the host key and
stored as `secrets/<project>/<KEY>.asc`. It needs the host key first
(`sudo -u fleet fleet keys init`, once per server); without one the page says so and shows no form.
Names listed there are the same ones `fleet secret list <project>` shows. Design, key
management and what the encryption does and does not protect:
[`docs/client-side-encryption.md`](client-side-encryption.md).

### Checking gpg-agent under the systemd sandbox (manual, per server)

`gpg` starts a `gpg-agent` whose socket lives in `GNUPGHOME`. The daemon runs
under `fleet-sandbox.inc.j2` (`MemoryDenyWriteExecute`, `SystemCallFilter`),
which gpg children inherit. After enabling encrypted secrets on a server,
verify once, approximating the service sandbox (see `fleet-sandbox.inc.j2` for the full set):

```bash
sudo systemd-run --pipe --wait -p User=fleet -p NoNewPrivileges=yes \
  -p MemoryDenyWriteExecute=yes -p SystemCallFilter=@system-service \
  -p ReadWritePaths=/srv/fleet -p ProtectSystem=strict \
  env GNUPGHOME=/srv/fleet/gnupg sh -c 'echo ok | gpg --batch --no-tty --armor --encrypt \
  --trust-model always -r "$(fleet keys show | awk "/^fingerprint/{print \$2}")" | gpg --batch --no-tty --decrypt'
```

Expected output: `ok` (gpg's own stderr lines may appear before it). Then deploy an instance that uses a secret and check the
journal (`journalctl -u fleet -n 50`) for `gpg` or seccomp denials. If the
sandbox blocks gpg, widen the minimum in `fleet-sandbox.inc.j2`; do not drop
the sandbox.

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

## systemd sandboxing (FLE-1)

Since FLE-1 the units fleet owns run with a systemd sandbox, scored with
`systemd-analyze security` (0 = locked down, 10 = no sandbox):

| Unit | Before | After | Where it comes from |
|---|---|---|---|
| `fleet.service` (daemon) | 8.7 | 1.8 | `fleet_service` role, `fleet.service.j2` + shared `fleet-sandbox.inc.j2` |
| `fleet-boot.service` | 9.2 | 1.8 | same include, `fleet-boot.service.j2` |
| `caddy.service` | 8.8 | 1.6 | `caddy` role drop-in `/etc/systemd/system/caddy.service.d/50-fleet-sandbox.conf` |
| `fleet-reboot-notify.service` | 9.0 | 1.6 | `security_hardening` role, `fleet-reboot-notify.service.j2` |

`fleet-tmux.service` is deliberately **not** sandboxed (its panes are operator
shells that need `sudo`, `ddev` and `git`). The `fleet` user is in the `docker`
group, which is root-equivalent: the daemon sandbox is defense in depth, not
containment. The Caddy sandbox matters most, since Caddy is the internet-facing
process.

**The EROFS trap.** With `ProtectSystem=strict` the whole filesystem is
read-only for the daemon except its `ReadWritePaths`: `fleet_srv_dir`, the Caddy
snippet dir, the `fleet` user's home, `/tmp` and `/var/tmp`. A write anywhere
else fails with `Errno 30` (EROFS) although `ls -l` shows correct ownership and
the `fleet` user can write there from a shell. A CLI `fleet ...` run is not
sandboxed, so test new daemon features through the web UI / API. If the daemon
must write a new path, add it to `ReadWritePaths` in
`ansible/roles/fleet_service/templates/fleet-sandbox.inc.j2`; do not weaken the
sandbox.

**Never add to the daemon units** (each breaks something): `PrivateTmp` (the
tmux socket `/tmp/tmux-<uid>` is shared with `fleet-tmux` and operators),
`ProcSubset=pid` (`core/sysinfo.py` reads `/proc/meminfo`), `PrivateUsers` (the
`docker` group would be unmapped, so no Docker socket), `UMask=0027` (Caddy must
read the snippets the daemon writes), `RemoveIPC` (the `fleet` uid is shared
with the operator panes). If Caddy ever logs to files, add that directory to
the drop-in's `ReadWritePaths`.

Settings (Ansible variables, `ansible/group_vars/all.yml` and
`ansible/roles/security_hardening/defaults/main.yml`):

| Variable | Default | Effect |
|---|---|---|
| `fleet_systemd_sandbox_enabled` | `true` | `false` renders the pre-FLE-1 units and removes the Caddy drop-in |
| `fleet_systemd_security_check_enabled` | `true` | run the check below at the end of `security_hardening` |
| `fleet_systemd_security_thresholds` | fleet, fleet-boot, fleet-reboot-notify, caddy: `25`; authelia: `35` | max exposure per unit on systemd's 0-100 scale (25 = 2.5) |
| `fleet_systemd_security_enforce` | `false` | `true` fails the play when a unit is over its threshold; otherwise it only prints a `WARNING` |

The check (the last tasks of `security_hardening`, so only when
`fleet_security_hardening_enabled` is on) skips units that are not installed
(e.g. `authelia.service` in basic auth mode), runs
`systemd-analyze security --threshold=N <unit>` for the rest and prints one
summary line per unit. Expect a warning for every unit when
`fleet_systemd_sandbox_enabled` is `false`. The same scores are asserted offline
in `tests/pytest/ansible/test_systemd_sandbox.py` (skipped when `systemd-analyze` is absent,
but never in Bitbucket Pipelines, where the Debian 13 image has systemd).

**Regression checks (FLE-8).** Bitbucket Pipelines scores every sandboxed unit
on each pull request / `develop` push / release tag and compares it with a
committed baseline, and a weekly scheduled pipeline does the same for the real
units on `ddev3` through a no-sudo, forced-command `fleet-probe` user
(`ansible/security-probe.yml`, role `security_probe`). A unit whose exposure
rises by more than 0.1 fails the pipeline. How it works, how to record a new
baseline and how to set up the live check: [`docs/README-ci.md`](README-ci.md).

Check by hand:

```bash
sudo systemd-analyze security fleet.service fleet-boot.service caddy.service
```

Rolling it out to an existing server: do **not** run the full `site.yml`
(see `CLAUDE.md`). For Caddy use `ansible-playbook caddy-only.yml` (it installs
the drop-in, reloads systemd and restarts Caddy). For the fleet units, install
the rendered `fleet.service` / `fleet-boot.service` / `fleet-reboot-notify.service`
(root:root 0644), `systemctl daemon-reload`, then `systemctl restart fleet` -- or
run `ansible-playbook fleet-units.yml` (see "Applying the fleet units" below),
which does exactly that without touching git or the venv. Verify with a web-UI deploy (the daemon path) and, for `fleet-boot`, a reboot.
After a Caddy restart, watch `journalctl -u caddy` through the first certificate
renewal.

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

## Hardening an existing server (FLE-25)

`site.yml` must never run on a live host (its `fleet_service` role rewrites the
git remote of `/opt/ddev-fleet`), so use the scoped playbooks in `ansible/`
(they must stay beside `site.yml`: `group_vars/` resolves relative to the
playbook).

### Applying `hardening.yml`

Runs `network_hardening`, `security_hardening` and `security_probe`; each is a
no-op unless enabled.

1. Edit `/etc/ddev-fleet/local-vars.yml`:

   ```yaml
   fleet_network_hardening_enabled: true
   fleet_security_hardening_enabled: true
   fleet_ssh_allow_users: [debian, root]   # pin it: must include your login
   ```

2. Preview, then apply:

   ```bash
   cd /opt/ddev-fleet/ansible
   sudo ansible-playbook hardening.yml --check --diff
   sudo ansible-playbook hardening.yml
   ```

3. **Confirm the UFW dead-man's switch.** When the run enables UFW it arms a
   timer that disables UFW again after `fleet_ufw_deadman_grace_minutes`
   unless confirmed. From a **second, independent SSH session** (this proves
   the firewall let you in):

   ```bash
   sudo /usr/local/sbin/fleet-firewall-confirm
   ```

Safe with running instances:

- **Docker is reloaded, never restarted.** `/etc/docker/daemon.json`
  (`live-restore` + log limits) changes notify a `Reload docker` handler
  (`systemctl reload docker`, SIGHUP). `live-restore` is reloadable and the log
  options only affect new containers. A restart would stop every container, and
  DDEV containers have restart policy `no`, so nothing would come back on a host
  without live-restore yet. Containers keep running through the play.
- **Named ports are staged before UFW is enabled.** The role deploys
  `fleet-ufw-sync` and runs it before `ufw enable`, so the registry's named
  ports (`Registry.public_ports_in_use()`, e.g. Typesense 9108) are open the
  moment the firewall comes up. The step is skipped in `--check` and when the
  fleet venv does not exist yet; if it fails the play warns and continues.
  On a server hardened before FLE-25 whose named ports are blocked, run
  `fleet refresh-ports` (or re-run `hardening.yml`).
- `--check --diff` completes: the read-only commands (`ufw show added`,
  `findmnt`, the msmtp credential reads) run in check mode, and the "SSH port is
  staged" assert accepts a rule the dry run would add.

### Applying the fleet units (`fleet-units.yml`)

Refreshes only the systemd units and the CLI wrapper of `fleet_service`
(`fleet.service`, `fleet-boot.service`, `fleet-tmux.service`,
`/usr/local/bin/fleet`): no git clone/update, no venv, no pip.

```bash
cd /opt/ddev-fleet/ansible
sudo ansible-playbook fleet-units.yml --check --diff
sudo ansible-playbook fleet-units.yml
```

A changed `fleet.service` is daemon-reloaded and then `systemctl try-restart`ed
(the daemon restarts if it was running; DDEV instances are untouched).
`fleet-boot.service` is only enabled (it runs at boot). `fleet-tmux.service` is
enabled and started but never restarted: pick up a changed unit yourself, at a
moment of your choosing, since stopping it ends the `fleet` tmux session.

## See also

- `docs/installation.md` — first-run checklist.
- `docs/networking.md` — the port-exposure mechanism behind
  `fleet refresh-ports`.
- `README.md` — the full CLI command reference.
