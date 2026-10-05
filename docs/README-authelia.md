---
Author: Claude Code
Reviewer: none
Last updated: 2026-09-28
Type: documentation
---

# Authelia auth mode

An alternative to fleet's default per-instance HTTP basic auth: a
cookie-based login portal ([Authelia](https://www.authelia.com/)) that
works on networks that block basic auth outright, with users defined per
project in `fleet.yml`.

**Status:** implemented and rolled out to **two** live servers (ddev2 and
the primary, both Debian 13, Authelia 4.39.28 apt package, `User=authelia
Group=authelia`, both now on Authelia mode as of 2026-09-28) — see
"Rollout" below for the fixes those live runs required.

**Brute-force regulation is on by default.** Authelia's built-in
`regulation` settings ship with sane defaults (observed: 3 failed logins
→ a 5-minute ban on that username) — the role does not disable this, and
you don't need to configure anything for it to apply. It has bitten
testing more than once (an admin locked out mid-smoke-test after a few
wrong-password attempts) — wait out the ban or fix the password, don't
"fix" it by editing `configuration.yml` by hand (see next paragraph).

Do **not** hand-edit `/etc/authelia/configuration.yml` to relax
regulation for a one-off test — re-running `ansible/authelia.yml` (the
normal way to apply any other change) re-renders the file from the role's
template and silently restores the defaults, discarding the override with
no warning. If you must disable it temporarily, know that it will not
survive the next playbook run.

## How it works

- **Server-level setting**: `auth_mode: basic | authelia` in
  `/srv/fleet/host.yml`, written by Ansible's `caddy` role from
  `fleet_auth_mode`. Absent means `basic`. This lets several servers share
  one `fleet.yml` while running different modes.
- **Authelia only authenticates.** It runs as a systemd service
  (`authelia.service`, apt package, `127.0.0.1:9091`) with a STATIC
  configuration — nothing in `configuration.yml` changes after first
  provisioning, so it never needs a restart (which would log everyone out —
  sessions are in-memory) for a routine `fleet.yml` edit. The apt package's
  own `systemd-sysusers` fragment creates its service account
  (`User=authelia Group=authelia`) — Ansible reads it back from the
  installed unit (`systemctl show`) rather than assuming or creating it.
- **Caddy does authorization**, per instance: each instance's fleet-owned
  Caddy snippet, inside a `route` block (required — see "Caddy syntax
  pitfall" below), strips any client-supplied `Remote-*` headers, forwards
  the request to Authelia's forward-auth endpoint
  (`forward_auth 127.0.0.1:9091 { uri /api/authz/forward-auth }`), then
  checks the visitor's `Remote-Groups` header for either that instance's
  project name or `admins` (as a whole comma-delimited element, never a
  substring) — anything else gets a 403.
- **Groups come from `fleet.yml`.** Each project's `users:` list becomes
  that project's group membership; the fleet dashboard's own Caddy snippet
  requires the `admins` group, which only the installer's admin account
  belongs to.
- **Named ports are unauthenticated in both modes.** A project's opted-in
  named ports (Typesense, a Playwright report port, …) are exposed by
  `core/caddyports.py`, which Authelia mode does not touch — see
  `docs/networking.md`.
- **Editing users never restarts Authelia.** `fleet.yml`'s `users:` only
  ever rewrites `/srv/fleet/authelia/users.yml`, which Authelia's file
  backend hot-reloads (`watch: true`) — apply an edit with
  `fleet refresh-auth`, the same command basic mode already used for its
  (now-removed) IP whitelist.
- **Secrets** (session secret, storage encryption key, reset-password JWT
  secret) are generated once under `/etc/authelia/secrets` and wired to the
  service via `AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET_FILE`,
  `AUTHELIA_SESSION_SECRET_FILE`, and `AUTHELIA_STORAGE_ENCRYPTION_KEY_FILE`
  environment variables (a systemd drop-in) — plain `AUTHELIA_` prefix, not
  `X_AUTHELIA_` (that prefix is reserved for a small fixed set of
  meta/behavioural vars and is never read for secrets by Authelia 4.39). The
  role's own `authelia config validate` task sets the same three env vars
  (from the single `authelia_secret_env` mapping in
  `ansible/roles/authelia/vars/main.yml`, shared with the systemd drop-in) —
  without them, validation fails with `storage: option 'encryption_key' is
  required` even though the service itself starts fine once the drop-in is
  in place.

## File layout

| Path | Owner:group | Mode | Written by | Contents |
|---|---|---|---|---|
| `/etc/authelia/configuration.yml` | `<authelia svc user>:<authelia svc group>` | `0640` | Ansible (`authelia` role, static) | Server/session/access-control config — no secrets, no state |
| `/etc/authelia/secrets/*` | `root:<authelia svc group>` | `0640` | Ansible (generated once) | The three secret values, loaded via `AUTHELIA_*_FILE` env vars |
| `/srv/fleet/authelia/` | `<fleet user>:<authelia svc group>` | `2750` (setgid) | Ansible creates the dir; `users.yml` is written by `fleet.core.authelia` (never Ansible) | `users.yml` only — Authelia has **group-read only** here by design; it must never write into this tree |
| `/var/lib/authelia/` | `<authelia svc user>:<authelia svc group>` | `0750` | Ansible creates the dir (before Authelia starts); Authelia writes into it at runtime | `db.sqlite3` (storage backend) + `notification.txt` (filesystem notifier) — Authelia's own writable state |

**Why the DB/notifier moved out of `/srv/fleet/authelia`**: the first live
rollout (ddev2) pointed `storage.local.path` and
`notifier.filesystem.filename` at `/srv/fleet/authelia/db.sqlite3` /
`notification.txt`. That directory is intentionally `2750`
`<fleet user>:<authelia group>` — group-**read** only for Authelia, since
`fleet.core.authelia` is the only writer allowed there (`users.yml`).
Authelia failed to start with `unable to open database file: permission
denied`. The fix is `/var/lib/authelia`, a separate directory the role
creates as `<authelia service user>:<authelia service group>` mode `0750`
— writable by Authelia, with no risk of collateral write access into the
fleet-owned `users.yml` tree.

## The `/srv/fleet` traverse ACL

The same rollout also hit `stat /srv/fleet/authelia: permission denied`:
`/srv/fleet` itself is `drwxr-x--- fleet:fleet`, and the Authelia service
account is a member of neither. Even though `/srv/fleet/authelia` grants
Authelia's group read access, a process can't reach a subdirectory it
can't first traverse into.

The fix, applied live as a hotfix and now encoded in the role:

```bash
apt-get install acl
setfacl -m u:authelia:x /srv/fleet
```

This grants **traverse-only** (`x`, no `r`) access on `/srv/fleet` itself
to the Authelia service user — it can `cd`/`stat` through the directory
but never `ls` it or read anything else living there (config repo
checkouts, other projects' assets, etc.). The role applies this
idempotently, guarded by a `getfacl` read-back so it only reports
`changed` the first time, and only when the service doesn't already run
as root (root needs no ACL to traverse anything).

## `fleet.yml` schema

```yaml
projects:
  myproject:
    users:
      - name: alice
        password: some-plaintext-password   # hashed (argon2id) only when rendering users.yml
      - name: bob
        password: another-password
```

Rules: names match `^[a-z0-9._-]+$`, `admins` is reserved, and the same
name must carry the same password in every project that lists it. A
project reached only via a YAML alias (e.g. `oak: *fern`) shares the same
users but is its own group — a user in `fern`'s list also needs `oak` in
their group set to reach `oak--*` instances, which happens automatically
since the alias shares the identical `users:` list.

Passwords are plaintext in `fleet.yml` (same trust model as everything
else the registry already holds) and are hashed with argon2id only when
`fleet.core.authelia.render_users()` writes `users.yml`. An existing hash
is reused whenever the plaintext hasn't changed, so an unrelated
`fleet.yml` edit doesn't churn `users.yml` (and thus doesn't trigger
Authelia's file-watcher) for users whose passwords didn't move.

## Installing in Authelia mode

`bootstrap.sh` prompts for the auth mode (or set `FLEET_AUTH_MODE=authelia`
non-interactively). This installs the `authelia` apt package, generates
its secrets once, and seeds the installer's admin password into Authelia's
admin account as well as the dashboard.

## Switching an existing server's mode

```bash
# edit /etc/ddev-fleet/local-vars.yml: fleet_auth_mode: authelia
cd /opt/ddev-fleet/ansible
sudo ansible-playbook authelia.yml --check --diff   # preview
sudo ansible-playbook authelia.yml
```

The `authelia` role's own tasks already run `fleet refresh-auth` on every
apply, so `users.yml` and every instance's snippet are current once the
playbook finishes — no extra manual step. `caddy` runs before `authelia`
in this playbook (and in `site.yml`): it writes `host.yml`'s `auth_mode`,
which `fleet set-admin-password`/`fleet refresh-auth` read to decide how to
apply.

Never run the full `site.yml` against a live host for this — see
`CLAUDE.md`'s "Deploy model" section for why (it rewrites `/opt/ddev-fleet`'s
git remote and breaks the `fleet` user's SSH-key `git pull`).

## Managing the admin account

`fleet set-admin-password <password>` / `fleet rotate-admin-password` work
in both modes and never need a valid `fleet.yml` in basic mode (they read
only `/srv/fleet/host.yml`'s `auth_mode`, a deliberate break-glass
guarantee) — in Authelia mode they load the full registry, write
`admin.yml`, and re-render `users.yml`, with no Caddy/Authelia restart
needed.

`--auth-password` on `fleet deploy`/`fleet redeploy` is **rejected** in
Authelia mode (`"auth passwords are managed in fleet.yml users: (Authelia
mode)"`) — per-instance credentials don't exist in this mode; authorization
is by project group membership, not a password.

## Caddy syntax pitfall (why the snippet uses `route { ... }`)

Caddy does not execute directives in source order inside a plain site
block — it reorders them by a fixed internal directive-precedence list,
which sorts bare `request_header` directives to run *after*
`forward_auth`. That silently deletes the very `Remote-Groups` header
`forward_auth`'s `copy_headers` just set, so the group check would see an
empty header on every request and reject every authenticated visitor,
admins included. The fix — confirmed against a real Caddy v2.11.4 +
Authelia v4.39 instance — is to wrap the header-strip + `forward_auth` +
group-check sequence in a single `route @matcher { ... }` block: `route`
disables the automatic reordering and runs its directives in the literal
order written.

## Rollout

Applied to two live servers so far — ddev2 (2026-09-27) and the primary
(2026-09-28). The ddev2 run exposed the apt signing key 404, the three
bugs fixed in "File layout" and "The `/srv/fleet` traverse ACL" above,
and the validate-task secrets gap covered in "Secrets" — all fixed in the
role before the primary's run, which needed no further code changes. The
order:

```bash
pip install argon2-cffi   # into the venv, BEFORE the code that imports it
# push-product, push-config
cd /opt/ddev-fleet/ansible && sudo ansible-playbook authelia.yml
sudo -u fleet fleet refresh-auth
# smoke test
```

`argon2-cffi` is a new runtime dependency (`fleet.core.authelia` imports
it) — it must be installed into `/opt/ddev-fleet/venv` before pulling code
that imports it, or every `fleet` CLI invocation fails at import time.
`push-config` now runs `fleet refresh-auth` automatically after pulling
the config repo, so a `fleet.yml` `users:` edit takes effect as soon as
config is pulled.

**`ansible/authelia.yml --check --diff` always stops at "Install the
authelia package"** and reports the play as failed/incomplete from there
— check mode can't actually add the apt repo + install the package, so
every later task that depends on Authelia being installed (config
validate, service management) never runs in check mode. This is expected,
not a bug: check mode is only useful here to preview the rendered
`configuration.yml`/`users.yml` diff up to that point; run the play for
real to see the rest.

**Authelia takes ~9 seconds to fully start** after `systemctl restart
authelia` (or the role restarting it). A `502` from any Authelia-fronted
host in that window is normal — wait it out before treating it as a
failure.

**Warning for a server switching FROM basic mode with an IP whitelist**:
once this code runs in basic mode, the old `fleet.auth_bypass_cidrs` IP
whitelist no longer applies (it's deprecated and ignored) — any users who
relied on it to skip the basic-auth prompt will suddenly see one. Switch
that server to Authelia mode in the *same* maintenance step, not a later
one, so the whitelisted network gets Authelia's cookie-based login instead
of an unexpected basic-auth prompt.

## Directory ownership and the setgid bit

`/srv/fleet/authelia` is created by the `authelia` Ansible role as
`fleet:<authelia group>` with mode `2750` (the leading `2` is the setgid
bit). The setgid bit is critical: when the `fleet` user writes `users.yml`
(mode `0640`) into this directory, the file automatically inherits the
Authelia service group as its group owner, so Authelia can read it without
needing `fleet` in the Authelia group. If the directory loses its setgid
bit — for example, after a manual `chown`, `chmod`, or filesystem restore —
newly written `users.yml` files become unreadable to Authelia and logins
fail silently.

**Fix:** Re-run the scoped `ansible/authelia.yml` playbook, or restore the
directory permissions manually and re-run `fleet refresh-auth`:

```bash
sudo chmod 2750 /srv/fleet/authelia
sudo chgrp <authelia-group> /srv/fleet/authelia   # verify from 'systemctl show -p Group authelia'
sudo -u fleet fleet refresh-auth
```

## Troubleshooting

- **Every protected host returns 502**: Authelia is down —
  `systemctl status authelia`, `journalctl -u authelia`.
- **`fleet refresh-auth` fails naming `set-admin-password`**: no admin
  account has been recorded yet on this server — run
  `fleet set-admin-password <password>` once, then retry.
- **A project user can't log in after a `fleet.yml` edit**: confirm
  `push-config` (or `fleet refresh-auth` directly) actually ran on the
  server — a `users:` edit alone, without either, never takes effect.
- **An authenticated visitor still gets 403**: check that the instance's
  Caddy snippet wraps its sequence in `route @matcher { ... }` — a bare
  sequence loses `Remote-Groups` to Caddy's directive reordering (see
  above).
- **Authelia fails to start with `unable to open database file: permission
  denied`**: `storage.local.path`/`notifier.filesystem.filename` in
  `/etc/authelia/configuration.yml` must point under `/var/lib/authelia`,
  never `/srv/fleet/authelia` (see "File layout" above). Confirm
  `/var/lib/authelia` exists and is owned `<authelia svc user>:<authelia
  svc group>` mode `0750`; re-run `ansible/authelia.yml` if not.
- **`authelia config validate` fails with `stat /srv/fleet/authelia:
  permission denied`** (running it by hand, outside the role): the
  Authelia service user needs the traverse ACL on `/srv/fleet` — see "The
  `/srv/fleet` traverse ACL" above. `getfacl /srv/fleet` should show a
  `user:<authelia svc user>:--x` (or wider) entry.
- **`authelia config validate` fails with `storage: option
  'encryption_key' is required'`** when run by hand: it needs the same
  `AUTHELIA_*_FILE` env vars the systemd drop-in supplies — the role's own
  validate task now sets them (see "Secrets" above); running the command
  manually still requires exporting them yourself.
