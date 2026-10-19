---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Installing ddev-fleet (1.x)

One command turns a fresh Debian 13 host into a working Fleet, and a short setup wizard in the browser
finishes the job. The host serves real HTTPS from the first minute. This guide covers the installer, the
wizard, what ends up on the host, recovery, upgrades and uninstalling.

The 1.x line is developed milestone by milestone and is **not a finished product yet**: this installer
gives you the sign-in, the app shell and the host plumbing (Caddy, Authelia, PHP-FPM, the job worker, the
Mercure hub, DDEV and Docker), and instances can be deployed from projects
([`instances-and-jobs.md`](instances-and-jobs.md)). Later milestones add networking, webhook triggers and the agent
workspace (see [`README.md`](README.md)). The 0.x installation guide is kept in [`v0/installation.md`](v0/installation.md).

## 1. Before you start

| You need | Detail |
|---|---|
| A host | **Debian 13**, amd64 or arm64, reachable on the Internet. The installer refuses another system (`FLEET_FORCE_OS=1` in the environment tries anyway, unsupported). |
| Root | Run the installer as root (`sudo`). `--dry-run` works without root. |
| DNS | A record (and AAAA, if you have IPv6) for **both** `<domain>` and `auth.<domain>` pointing at this host. One wildcard record `*.<domain>` covers `auth.<domain>`. The installer compares them with the host's own addresses and stops (exit 2) on a mismatch. |
| Ports | 80 and 443 free and reachable from the Internet (Let's Encrypt uses port 80 for the HTTP-01 challenge, even if you later switch to DNS-01). |
| Time | A fresh install takes about five minutes, a re-run under half a minute. Run it inside `screen`, `tmux` or `systemd-run`: a dropped SSH session would interrupt it. If it is interrupted anyway, run the same command again. Some bare-metal servers take about **three minutes** to answer SSH again after a reboot: do not give up after 90 seconds. |

`<domain>` is the public host name of this fleet, for example `fleet.example.com`: the fleet is served on
`https://fleet.example.com/` and the sign-in portal on `https://auth.fleet.example.com/`. Both get their own
certificate, so **`auth.<domain>` needs DNS in every TLS mode**.

## 2. Install

```bash
curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/develop/install/install.sh \
  | sudo bash -s -- --domain=fleet.example.com --acme-email=you@example.com
```

At the end the installer prints a **setup URL** on the terminal (it is never written to the install log). The URL
is a credential for 24 hours (or until Finish): whoever opens it can create the administrator, so treat it like a
password, open it yourself, and do not paste it into a chat, a ticket or a shared terminal log. Open it in a
browser and follow section 4.

> **Interim note.** The one-liner above downloads `install.sh` from the public GitHub mirror, which is
> published from `develop` by the next green `develop` pipeline after this installer is merged. Until the
> mirror carries `install/install.sh`, the command answers 404: copy the repository to the host (a clone, or a
> `git bundle`) and run `sudo bash install/install.sh --domain=... --repo-url=<path or url> --ref=<branch>`
> instead (the `fleet` user must be able to read the path).

To inspect before you run: save the script, read it, and call it with `--dry-run`, which prints the complete
plan and changes nothing.

### Flags

| Flag | Meaning |
|---|---|
| `--domain=<fqdn>` | **Required.** The public host name, lower case, fully qualified (no wildcard, port or IP address). |
| `--acme-email=<email>` | Contact address for Let's Encrypt (expiry notices). Optional, recommended. |
| `--ref=<ref>` | Branch, tag or commit to install. Default `develop`, the unreleased 1.x line; pin a `v1.*` tag once one exists. |
| `--repo-url=<url\|path>` | Git remote (or a bundle or path readable by the `fleet` user) to install from. Default: the public GitHub mirror. |
| `--skip-dns-check` | Do not compare the DNS records with this host's addresses (for example behind NAT or a CDN). |
| `--dry-run` | Print the plan, change nothing. Works without root. |
| `--help` | Print the usage text. |

There are no prompts. Exit codes: `0` ok, `1` a step failed (the message names the step), `2` DNS mismatch,
`64` usage error, `75` another installer run is in progress, `77` not root, `78` unsupported host.

### What the installer does

It runs in two stages. `install.sh` (stage 1) validates the flags, installs three packages, creates the
`fleet` user and clones the requested ref into `/opt/ddev-fleet` **as `fleet`, never as root**, then copies
that checkout's `install/` directory to a root-owned location and hands over to its `run.sh` (stage 2). Stage 2
runs the steps in `install/steps/` in numeric order; every step is idempotent:

| Step | Does |
|---|---|
| 10 packages | PHP 8.4 from Debian (php-fpm, cli and extensions, no third-party PHP repository), Composer, `openssh-client`, `acl`, `sudo` and a few tools. |
| 20 docker | Docker CE from Docker's repository; the daemon is enabled and started. |
| 30 fleet user | The `fleet` system user (member of `docker`), `/srv/fleet`, `/etc/ddev-fleet`, `/opt/ddev-fleet`. |
| 40 ddev | DDEV from its repository, configured for `fleet` (router on ports 8080 and 8443), mkcert's CA for the NSS store only. |
| 50 caddy | Caddy from its repository plus a **custom build with the OVH DNS module**; the package binary is diverted so `apt` never replaces the custom one. A re-run refreshes the custom build when it is older than the packaged Caddy. The sandbox drop-in is installed. |
| 60 authelia | Authelia from its repository, its three secrets (generated once), the tmpfiles mask (see Troubleshooting), and a `users.yml` with one disabled placeholder user (Authelia 4.39 refuses an empty user list; the placeholder can never sign in and disappears when the first real user exists). |
| 70 env | `/etc/ddev-fleet/fleet.env` and `mercure.env`: only missing keys are added, secrets are generated once. |
| 80 code | `composer install --no-dev`, assets, prod cache, as `fleet` with the application environment. |
| 85 app init | Migrations, the queue transport, `fleet:host:init` (domain, ACME email). |
| 90 privileged | The root helper, its single sudoers line and the `fleet` command. |
| 95 units | The `fleet` php-fpm pool, the job worker `fleet-worker@.service`, the status watcher `fleet-status.service`, `fleet-boot.service`, the standalone Mercure hub (pinned release, sha256-checked) and its unit. |
| 98 render and start | Renders the Caddy and Authelia configuration, installs it through the helper, starts everything, waits until `https://<domain>/health` and the hub answer, and prints the setup URL. |

The installer logs to `/var/log/ddev-fleet-install.log` (mode 0600).

## 3. What is installed where

### Users, directories and file modes

| Path | Owner and mode | Holds |
|---|---|---|
| user `fleet` | home `/home/fleet`, group `docker` | Runs php-fpm, the workers, DDEV and the CLI. |
| `/opt/ddev-fleet` | `fleet:fleet` 0755 | The git checkout of the product. Stays `fleet`-owned. |
| `/srv/fleet` | `fleet:fleet` 0750, ACL `authelia:--x` | `FLEET_HOME`: `var/` (0750; `fleet.sqlite` and `queue.sqlite`, 0644 inside it), `locks/`, `logs/`, `assets/`, `instances/` (0750). |
| `/srv/fleet/backups` | `fleet:fleet` 0700 | Database backups written by `fleet-backup.timer` (files 0600; see "Backup and restore" in section 5). |
| `/srv/fleet/keys` | `fleet:fleet` 0700 | `secret.key`, the encryption key for the stored secrets (0600). **Back it up with the databases**: without it the OVH credentials and the Claude token cannot be read again. |
| `/srv/fleet/ssh` | `fleet:fleet` 0700 | `deploy_ed25519` and `push_ed25519` (private keys, 0600), their `.pub` files and `known_hosts` (0644). |
| `/srv/fleet/staging` | `fleet:fleet` 0700 | Rendered configuration waiting for the root helper (`caddy/`, `authelia/`). Empty at rest: it is filled only for the moment before an `apply`, and emptied after every apply, every failed install step and every plain `fleet:host:render`, because the staged Caddy environment file holds the OVH credentials in clear text. Leave it out of backups. |
| `/srv/fleet/assets/<project>` | `fleet:fleet` 0750 | A project's seed files (database dumps, files archives, env templates), shown on the project's Assets tab; created by the first upload or by `mkdir`. Copy large files in here over SSH, see [`assets.md`](assets.md). Back it up with the databases. |
| `/srv/fleet/uploads` | `fleet:fleet` 0700 | Chunks of browser uploads on their way to `assets/`, one directory per upload; emptied when an upload completes and by `fleet asset:gc` (after 24 hours). Leave it out of backups. |
| `/srv/fleet/authelia` | `fleet:authelia` 2750 | `users.yml` (0640, `fleet:authelia`), the sign-in portal's user file with the argon2id hashes, written by the app. |
| `/etc/ddev-fleet` | `root:fleet` 0750 | `fleet.env` (0640 `root:fleet`, see below), `mercure.env` (`root:root` 0600), `installed-revision` (0644). |
| `/etc/caddy/Caddyfile` | `root:root` 0644 | Written by the root helper, never by the app. Holds `{env.X}` placeholders, no secrets. |
| `/etc/caddy/fleet/` | `fleet:caddy` 2750 | `gate.conf` (0640), the sign-in gate, written by the app; `instances/` and `ports/` for later milestones. |
| `/etc/caddy/fleet.env` | `root:caddy` 0640 | The proxy secret (Caddy reads it from its environment, so it only takes effect at the next start). It holds no OVH credential: an `OVH_*` line left by an older apply is dropped at the next `apply caddy`. |
| `/etc/caddy/ovh/` | `root:caddy` 0750 | DNS mode only: four files (`endpoint`, `application_key`, `application_secret`, `consumer_key`), `root:caddy` 0640, one **clear-text** value each, which the Caddyfile reads through `{file./etc/caddy/ovh/…}` placeholders each time Caddy loads its configuration. Nothing sits in Caddy's process environment. Anyone who is root or in group `caddy` can read them; the copy in the app's database is encrypted. Removed when the host leaves DNS mode. |
| `/etc/authelia/` | `authelia:authelia` | `configuration.yml` (0640, written by the helper); `secrets/` (`root:authelia` 0750, three secret files 0640, generated once). |
| `/etc/fleet-mercure/Caddyfile` | `root:root` 0644 | The Mercure hub configuration (it lives apart from `/etc/ddev-fleet` because the hub runs as a dynamic user). |
| `/run/php/fleet.sock` | `fleet:caddy` 0660 | The only way into the app. |
| `/usr/local/libexec/fleet/apply` | `root:root` 0755 | The root helper. |
| `/etc/sudoers.d/fleet-apply` | `root:root` 0440 | The one sudoers line (section 7). |
| `/usr/local/bin/fleet` | `root:root` 0755 | The `fleet` command (section 5). |
| `/usr/local/bin/mercure` | `root:root` 0755 | The Mercure hub binary. |
| `/usr/local/lib/ddev-fleet-installer/` | `root:root` | A root-owned copy of `install/`, from which the privileged files are installed and the uninstaller runs. |
| `/var/log/ddev-fleet-install.log` | `root:root` 0600 | The installer's log (never contains the setup URL or a secret). |

### Units and ports

| Unit | Listens on | Notes |
|---|---|---|
| `caddy` | 80, 443 | The only Internet-facing process; sandboxed by a drop-in. Reads `/etc/caddy/fleet.env`. |
| `authelia` | `127.0.0.1:9091` | The sign-in portal, served publicly as `https://auth.<domain>/` by Caddy. |
| `php8.4-fpm` | `/run/php/fleet.sock` | One pool, `fleet`. `request_terminate_timeout` is 240 s (the Finish request can take that long). The unit drop-in loads `fleet.env`. |
| `fleet-worker@1` | | The one Messenger worker (`messenger:consume jobs`). Do not enable `fleet-worker@2`: the SQLite message queue cannot be consumed by two workers (`database is locked`). Jobs of different instances therefore run one after another. |
| `fleet-scheduler` | | The schedule ticker (`messenger:consume scheduler_default`, one tick every 30 seconds): fires the due schedules and queues their jobs for `fleet-worker@1`. See `docs/schedules.md`. |
| `fleet-status` | | The status watcher (`fleet:instance:watch --interval=10`): keeps instance states and memory true to `ddev list` and `docker stats`, reaps dead jobs and prunes old ones. Restarted by an upgrade. |
| `fleet-boot` | | A oneshot, enabled but never started by the installer: after a reboot it runs `fleet:instance:start --all --sequential --timeout=1800 --retry-port-conflict`, which starts every instance, stopped ones too (as 0.x did). |
| `fleet-backup.timer` | | Starts `fleet-backup.service` (a oneshot running `fleet:backup:create`) once a day, at a random time within an hour of midnight. `Persistent`: a backup missed while the host was off runs at the next boot. See "Backup and restore" in section 5. |
| `fleet-mercure` | `127.0.0.1:3000` | A standalone Mercure hub (version 1.1.2, legacy protocol mode as `symfony/mercure-bundle` mints). Caddy proxies `/.well-known/mercure` to it. Subscribers need a JWT: an anonymous request to the hub answers 401. |
| `docker`, DDEV | router on 8080 and 8443 (once an instance runs) | The installer configures the router ports but starts no project, so nothing listens on 8080/8443 until the first instance exists (a later milestone). It does not set `router_bind_all_interfaces`, so DDEV keeps its default (loopback only). |

### `/etc/ddev-fleet/fleet.env`

The app, the workers and the CLI all read this file. Existing keys are never changed by a re-run.

| Key | Value |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `prod`, `0`. The pool also pins `APP_ENV=prod`, so a forgotten value can never give the dev kernel. |
| `APP_SECRET` | Generated once (64 hex characters). |
| `MERCURE_JWT_SECRET` | Generated once; the hub's keys in `mercure.env` are derived from it. |
| `FLEET_PROXY_SECRET` | Generated once. Caddy passes it to PHP as the FastCGI parameter `FLEET_PROXY_AUTH`; only a request carrying it is trusted to assert an identity. |
| `FLEET_HOME` | `/srv/fleet`. |
| `FLEET_RUNNER` | `process` (commands really run). |
| `FLEET_TRUSTED_PROXIES` | Empty on purpose: trust comes from the proxy secret, not from the peer address. |
| `FLEET_ADMIN_GROUP` | `fleet-admins`. The one group that makes an administrator: the wizard puts its administrator in it (and so into Authelia's `users.yml`), and the app grants `ROLE_ADMIN` to it. Set it (in `fleet.env`, then restart php-fpm and the workers) **before** the wizard creates the administrator; later changes do not regroup existing users. Letters, digits, `.`, `_`, `-`. |
| `FLEET_CADDY_DIR` | `/etc/caddy/fleet`. |
| `FLEET_JOB_RETENTION_DAYS` | `90`. Finished jobs and their logs older than this are pruned daily by the status watcher (`fleet job:prune --days=N` does it by hand). |
| `DEFAULT_URI` | `https://<domain>`. |

## 4. The setup wizard

The setup URL looks like `https://<domain>/setup?token=...`. Until the wizard is finished, Caddy lets **only**
`/setup`, `/assets/*` and `/health` through; everything else redirects to `/setup`, and nobody can sign in yet.

The first visit exchanges the token for a session cookie (`__Host-fleet_setup`, 2 hours, Secure, HttpOnly,
SameSite=Strict) and redirects to the first unfinished step with a clean address. If the cookie expires, open
the link again. The token itself stays valid for **24 hours** or until Finish, whichever comes first, so you
can open it on a phone and a laptop; ten token attempts per address in fifteen minutes are allowed. Every
step is saved as you go, and the wizard works at phone width.

1. **Administrator.** Username, display name, email and a password of 12 to 128 characters. The username is
   fixed once an administrator exists (saving again updates that account; the password must be entered again).
   The account is created in the administrator group (`FLEET_ADMIN_GROUP`, default `fleet-admins`).
2. **Domain and certificates.** The domain was set by the installer. Choose how certificates are obtained:
   - `http01` (default): Let's Encrypt HTTP-01 on port 80, for `<domain>` and `auth.<domain>`.
   - `ovh_dns`: DNS-01 through the OVH API. Enter the API endpoint (`ovh-eu`, `ovh-ca` or `ovh-us`), the
     application key, the application secret and the consumer key. The credentials need these rights (the list the DNS
     library behind Caddy's OVH module, [libdns/ovh](https://github.com/libdns/ovh#authenticating), documents;
     `<zone>` is your DNS zone, or `*` for several): `GET` and `POST` on `/domain/zone/<zone>/record`, `GET`, `PUT` and
     `DELETE` on `/domain/zone/<zone>/record/*`, `GET` on `/domain/zone/<zone>/soa`, and `POST` on
     `/domain/zone/<zone>/refresh`. They are stored encrypted (libsodium, key in
     `/srv/fleet/keys/secret.key`) and never shown again. The endpoints of OVH's budget brands are not
     supported: create the credentials at one of the three endpoints above. Today this mode only changes how
     the certificates are obtained (the two host names and one per instance, see "Instances on the host" in section 6).

     **Implemented, not yet verified live.** Only `http01` has run end to end on a real host; the DNS-01 path is
     covered by unit and helper tests with stand-ins, never against the OVH API. Finish installs the new Caddyfile
     and the four credential files in `/etc/caddy/ovh/` and **reloads** Caddy (the files are read at every load, so a
     rotation of the credentials needs a reload only, never a restart). What to check after choosing it:
     `systemctl is-active caddy` a minute after Finish, `journalctl -u caddy -n 100 --no-pager` free of `ovh` or
     `dns` errors, and `sudo -u caddy env -i bash -c 'set -a; . /etc/caddy/fleet.env; caddy validate --config
     /etc/caddy/Caddyfile --adapter caddyfile'` succeeding (it reads `/etc/caddy/ovh/*` as `caddy`). Caddy keeps the certificates it already has, so the first
     DNS-01 issuance happens at the next renewal (about 30 days before expiry): to prove the credentials earlier,
     move the certificate storage aside and restart Caddy while watching the journal. If Caddy does not come back
     (the web UI is behind it, so the wizard cannot help), go back to HTTP-01 from the shell:
     `sudo fleet host:init --tls-mode=http01 --force && sudo fleet host:render --apply && sudo systemctl restart caddy`.
3. **SSH keys.** Fleet clones and pushes with two ed25519 keys, **deploy** (read) and **push** (write). Copy each
   public key and register it at your Git server (the push key with write access) before you finish. You can
   generate a new key or import an existing unencrypted private key; the old key then stops working wherever it
   was registered. *Trusted Git servers*: GitHub, GitLab and Bitbucket are pre-trusted. For another server enter
   its host (or `[host]:port`); Fleet reads the server's host keys and shows their fingerprints, and nothing
   is trusted until you compare them with the ones your server's administrator published and confirm. Loopback
   and link-local targets are refused.
4. **Claude token** (optional). The OAuth token that `claude setup-token` prints, so Claude Code can run
   inside instances. Stored encrypted, never shown again. *Skip for now* is fine.
5. **Finish.** A summary (domain, certificates, administrator, both key fingerprints, whether a Claude token is
   stored). Pressing *Finish setup* writes the administrator into the sign-in service, restarts Authelia,
   switches the Caddy gate from the setup gate to the sign-in gate, applies the certificate settings (DNS mode
   only) and marks setup complete, **in that order and last**. It usually takes a few seconds (up to a few minutes when the host is slow; the request may run for 240 s); keep the page open.
   If a step fails, the page shows why, nothing is marked complete and the link still works: fix the cause and
   press Finish again.

After Finish the setup token is gone. Signed out you are sent to the sign-in page whatever you open (the sign-in
gate is in front of `/setup` too); signed in, every `/setup` URL answers 404 "Setup is complete". Open
`https://<domain>/`: you are sent to `https://auth.<domain>/` to sign in with the administrator you created.
Authelia's own self-service password reset ("Forgot password?" on the sign-in page) is **switched off** until an SMTP
server is configured, because without one its link could not be delivered; the recovery path is section 5. To switch it
on, add to `/etc/ddev-fleet/fleet.env` (the installer keeps lines it did not write) `FLEET_AUTHELIA_SMTP_ADDRESS`
(`smtp://host`, `submission://host:587` or `submissions://host:465`), `FLEET_AUTHELIA_SMTP_SENDER` (the From address) and,
if the server wants a login, `FLEET_AUTHELIA_SMTP_USERNAME`. The SMTP password is never written by Fleet: give Authelia
the path of a file holding it with `Environment=AUTHELIA_NOTIFIER_SMTP_PASSWORD_FILE=/etc/authelia/secrets/smtp_password`
in a `systemd` drop-in of `authelia.service` (the file `root:authelia` 0640). Then run `sudo fleet host:render --apply`.
With a server configured, Fleet keeps what Authelia changes: before every rewrite of the users file it adopts a password
hash that Authelia changed (the user's self-service reset), unless an administrator set that user's password in Fleet
in the meantime (Fleet then wins), so the next user change does not undo a reset. `users.yml` is then group-writable
(0660) so Authelia can write it.

### Users and project access

Open **Users** (`https://<domain>/admin/users`, administrators only; it works on a phone). Fleet owns the people who may
sign in, so there is no other place to add them:

- **New user:** username (lowercase letters, digits, dot, underscore or dash; fixed afterwards), display name, email and
  an initial password (the same rules as everywhere: 12 to 128 characters), whether the person is an administrator, and
  the projects they work on (one tap-sized checkbox per project).
- **Edit** (tap a user in the list): display name, email, the administrator role and the project checkboxes. A member can
  operate the instances of the projects they are in (start, stop, redeploy, read logs; deploy and destroy stay with administrators), and cannot create or delete projects, change
  settings or open another project's instance sites. An administrator needs no memberships: every project is theirs.
- **Set a new password** for the user (the person can also reset it from the sign-in page once outgoing mail is
  configured, see above), **Disable** or **Enable** the account (a disabled user cannot sign in; nothing is deleted)
  and **Delete** it (with its project access).

Every change is written to the Authelia users file before it is saved, and the per-project access rules are installed
into Authelia when they changed (that restarts Authelia, so signed-in users sign in again; it happens only when the
rules differ). If installing the rules fails the page says so: the change is saved and the next user or project change
tries again.

Safeguards: nobody can delete, disable or demote **themselves**, and the **last enabled administrator** can never be
deleted, disabled or demoted (create or enable another one first), so the fleet cannot lock itself out. Everything on
these pages is checked on the server and protected against cross-site requests; passwords are stored as argon2id
hashes and are never shown or logged.

Next, describe what the fleet can deploy: add your first project and its templates in the browser or on the host, see
[`projects-and-templates.md`](projects-and-templates.md), and give them their seed data (database dumps, files) on the project's Assets tab or by copying over SSH, see [`assets.md`](assets.md). Then deploy an instance: [`instances-and-jobs.md`](instances-and-jobs.md).

## 5. Recovery

All commands run on the host. `fleet` is the wrapper the installer puts in `/usr/local/bin`: it switches to the
`fleet` user and loads `/etc/ddev-fleet/fleet.env`. `fleet setup:token` is short for `fleet fleet:setup:token`.

| Situation | Command |
|---|---|
| Lost the setup URL (setup not complete) | `sudo fleet setup:token` prints a new setup URL; the old one stops working. It refuses once setup is complete. |
| Nobody can sign in, or a password is forgotten | `ssh -t admin@fleet.example.com sudo fleet user:reset-password alice` |
| Start the wizard over (change certificates, replace the administrator, ...) | `sudo fleet setup:reset` |

**`fleet:user:reset-password <username>`** asks for the new password twice **without echo** and stores its
argon2id hash, rewrites the Authelia users file and restarts Authelia. It needs a real terminal: over SSH use
`ssh -t`, because without one (a pipe, cron, plain `ssh host command`) the command refuses to run rather than
read the password from an argument or the environment, and it never falls back to a visible prompt.
`--no-apply` writes the users file but skips the Authelia restart (and the sudo). **Restarting Authelia drops
every signed-in session**: all users must sign in again.

**`fleet:setup:reset`** marks setup as not complete, mints a new token, prints the URL and puts the setup gate
back, so until the wizard finishes again only the wizard, its assets and `/health` are reachable and nobody can
sign in. Existing users, keys and secrets are kept and the wizard updates them: the reopened wizard remembers its earlier
answers, so the new link lands on Finish (the stepper links go back to any step, and the administrator's username
stays fixed). It asks for confirmation on a
real terminal; `--yes` skips the question (required without a terminal). It is logged. Finishing the wizard again
restarts Authelia, so sessions are dropped here as well.

The remaining commands exist mostly for the installer:

| Command | Purpose |
|---|---|
| `fleet:host:init --domain=<fqdn> [--acme-email=] [--tls-mode=http01\|ovh_dns] [--force]` | Create the state directories, the secret key and the host settings. Idempotent. Changing the domain or the TLS mode after setup needs `--force`. |
| `fleet:host:render [--gate=auto\|setup\|authelia] [--no-reload] [--apply] [--stage] [--force]` | Check that the Caddy and Authelia configuration renders, write the gate and leave nothing staged. `--apply` stages each target, installs it through the root helper and removes it again; `--stage` (used by the installer) leaves the files staged for a root `apply`. `--gate=setup` after completion needs `--force` (prefer `fleet:setup:reset`). |
| `fleet:backup:create` | Back up the databases into `/srv/fleet/backups` and prune old backups (see below). Run daily by `fleet-backup.timer`. |
| `fleet:setup:token` | Mint a setup token and print the URL. |
| `fleet:setup:reset [--yes]` | See above. |
| `fleet:user:reset-password <username> [--no-apply]` | See above. |

### Host settings page

Administrators find the host's own settings under **Settings** (`/admin/host`), in five tabs (a select with a Go button on a phone):

| Tab | What you can do |
|---|---|
| General | See the domain and TLS mode (read-only; the page names the commands that change them) and edit the ACME contact e-mail. After saving, apply it to Caddy with `sudo fleet host:render --apply`. |
| Git identity | The default author name and e-mail of the commits the fleet makes in instances. Both or neither; the name has no control characters, `<` or `>` and at most 100 characters. |
| Claude token | Store or replace the Claude Code token (the same secret the setup wizard writes). It is write-only: the page shows only "Set" or "Not set" and when it was last written. |
| Notifications | The SMTP relay (host, port, user, password), sender and recipient for the host's e-mails. The password is stored encrypted, write-only, and can be removed with a checkbox. Nothing sends mail yet, and there is no test-mail button. |
| Backups | The database backups (see below): list and download them, "Back up now", and the retention in days (1 to 3650). |

A **reboot notice** appears at the top of every page for administrators when Debian has left `/run/reboot-required`
(the packages that asked for it, from `/run/reboot-required.pkgs`, are listed). The fleet never reboots the host by itself.

### Backup and restore

**What is backed up.** `fleet-backup.timer` runs `fleet:backup:create` once a day. It writes a consistent snapshot
(SQLite `VACUUM INTO`, taken while the app keeps running, checked with `PRAGMA quick_check` and renamed into place
atomically) of the two databases into `/srv/fleet/backups/` (directory 0700, files 0600, owned by `fleet`):

| File | Holds |
|---|---|
| `fleet-YYYYmmdd-HHMMSS.sqlite` | The fleet database: host settings, users (argon2id hashes), SSH key metadata and the encrypted secrets. The one you restore. |
| `queue-YYYYmmdd-HHMMSS.sqlite` | The Messenger queue (pending and failed jobs). Only transient work; you rarely want it back. |

The timestamp is UTC. **`/srv/fleet/keys/secret.key` is not part of a backup**, and the encrypted secrets cannot be
read without it: copy it somewhere safe once (and again if it ever changes), apart from the backups. Instances,
assets and the SSH keys under `/srv/fleet/ssh` are not backed up either.

**Retention.** Backups older than the retention (14 days by default; `backupRetentionDays` in the host settings)
are deleted after every run, except that the newest backup of each kind is always kept. The backups live on the
same disk as the databases: copy `/srv/fleet/backups` to another machine (`rsync`, `restic`, ...) for a real
disaster recovery.

**Back up now.** The Backups tab of the host settings page (`/admin/host?tab=backups`) lists the fleet database backups,
downloads one (an attachment, never cached) and has a "Back up now" button. On the host, `sudo fleet backup:create` prints the name of the new file (it is short for
`fleet fleet:backup:create`); a failure exits non-zero. `systemctl list-timers fleet-backup.timer` shows the next
run and `journalctl -u fleet-backup.service` the last ones.

**Restore.** Stop everything that writes to the database, put the file back and start again. Replace
`fleet-20261019-083015.sqlite` with the backup you chose:

```bash
sudo systemctl stop php8.4-fpm 'fleet-worker@*' fleet-status
sudo rm -f /srv/fleet/var/fleet.sqlite-wal /srv/fleet/var/fleet.sqlite-shm      # leftovers of the old database
sudo install -m 0600 -o fleet -g fleet /srv/fleet/backups/fleet-20261019-083015.sqlite /srv/fleet/var/fleet.sqlite
sudo systemctl start php8.4-fpm fleet-worker@1 fleet-status
sudo fleet doctrine:migrations:migrate -n                                       # only if the backup is from an older version
```

Check `https://<domain>/health`. If `keys/secret.key` was lost, the restored secrets cannot be decrypted: set the
OVH credentials and the Claude token again. To restore the queue as well, copy `queue-<same stamp>.sqlite` to
`/srv/fleet/var/queue.sqlite` the same way (and remove its `-wal` and `-shm` files); otherwise leave the current one.

## 6. Upgrading and re-running

**Re-running the same command is the upgrade.** The installer updates the checkout (a branch is reset to
`origin/<ref>`; local edits in `/opt/ddev-fleet` are discarded), runs `composer install`, applies migrations and
repeats every step. It keeps the secrets, the users, the SSH keys and the wizard's choices, prints **no new setup
token once setup is complete** (while setup is still open it prints a fresh URL and the old one stops working), and reloads php-fpm and the workers when the code changed. It always restarts
Caddy (a refreshed custom build only takes effect in a new process) and Authelia, so expect a short blip and
**every signed-in user is signed out**. A step that failed halfway is simply run again.

The packages (Docker, DDEV, PHP) are upgraded by the operating system's own `apt upgrade`; only the custom
Caddy and the Mercure hub are refreshed by the installer.

`--domain` cannot change once setup is complete (`fleet:host:init` refuses without `--force`), and a domain
change involves the DNS records, the certificates and the sign-in cookie domain, so it is not a supported
operation yet. `DEFAULT_URI` in `fleet.env` is not rewritten by a re-run either.

Switching TLS mode after setup: run `sudo fleet setup:reset`, change the mode in the wizard's second step and
finish again.

### Instances on the host

Deploying instances ([`instances-and-jobs.md`](instances-and-jobs.md)) needs a few things from the host:

- **Wildcard DNS.** Add one record `*.<domain>` (A, and AAAA if you use IPv6) pointing at this host. Every instance is
  served at `https://<id>.<domain>`; without the record the deploy works and the site does not open.
- **Units.** `fleet-worker@1` runs the jobs (one consumer: the SQLite queue takes a single one), `fleet-status` keeps the
  instance states true, and `fleet-boot` starts the instances one at a time after a reboot. `systemctl list-units` should show none of them as failed.
- **Certificates.** Each instance site gets **its own certificate** from Caddy (HTTP-01, or DNS-01 in `ovh_dns` mode). Let's
  Encrypt limits how many certificates one registered domain may obtain per week, so deploying dozens of new instances in
  a week can hit the limit: the site then stays without a certificate until the limit passes. Reusing a label (redeploy)
  reuses the certificate.
- **Who can open a site.** The installer's Authelia rule for `*.<domain>` admits **administrators only**. Project members
  can operate their instances in the app but cannot open the sites until per-project access rules are rendered
  (users and access, milestone M0.10).
- **Upgrading while a job runs.** Re-running the installer restarts the workers when the code changed. A job running at that
  moment is marked `interrupted` (its worker is gone) and can be retried; wait for running deploys before upgrading when you can.
- **Root-owned leftovers.** A container can leave files owned by root in an instance checkout. Destroy then fails with the
  exact `sudo rm -rf <directory>` line; run it and retry the job.
- **No rate limit on `/_components`.** The live components endpoint is open to every signed-in user, each component checks
  the user's role itself, and no request rate limit is applied; Caddy and the sign-in gate are the only front line.

## 7. Security model

- **Caddy is the only Internet-facing process.** It strips every `Remote-*` and `Remote_*` request header on every
  route, including `/health`, `/setup` and in setup mode, then runs the Authelia `forward_auth` gate, which
  copies the verified identity back. The app trusts an identity only from a request that carries the shared
  secret as the FastCGI parameter `FLEET_PROXY_AUTH` (a client cannot set a FastCGI parameter) and refuses a secret
  shorter than 32 characters.
- **What needs no sign-in.** Only `/health` (a minimal status document) and `/hooks/*` (reserved for signed
  webhooks, which will authenticate themselves; no such route exists yet and the path answers 404) bypass the
  `forward_auth` gate. Caddy matches those paths case-insensitively, so `/HOOKS/x` is treated as `/hooks/x`;
  Symfony's routing is case-sensitive, and the identity headers are stripped on these paths too, so nothing there
  can act as a signed-in user. Everything else, `/setup` included, needs an Authelia session.
- **Authelia** uses the `one_factor` policy (password only) for `<domain>`, bypasses its own portal and denies every
  other host by default. Nothing under `*.<domain>` is served yet; instances and their per-project access arrive in later
  milestones.
- **The root helper.** The app never runs as root. To install a rendered Caddy or Authelia configuration it writes
  the files into `/srv/fleet/staging/<target>/` and runs `sudo -n /usr/local/libexec/fleet/apply <target>`. The
  sudoers file contains exactly one line, allowing `apply caddy` and `apply authelia` for `fleet`, with the
  arguments matched exactly. The helper pins the staging directory, rejects symlinks, hard links and oversized or
  foreign files, validates the files with the real Caddy or Authelia binary running as their own users, installs
  atomically, rolls back if the reload fails, and deletes the staged files afterwards.
- **Be honest about what that buys.** `fleet` is a member of the `docker` group, which is root-equivalent on the
  host. The helper prevents accidents and half-applied configurations; it is **not a security boundary** against
  a compromised `fleet` account or a compromised app. This is why php-fpm's unit sets `NoNewPrivileges=no`
  (a setuid `sudo` needs it), and the Finish request runs the helper from php-fpm for now.
- **Secrets.** Generated once, kept in files with narrow modes (see section 3); the OVH credentials and the Claude
  token are stored encrypted in the database with a key that is only readable by `fleet` (the OVH credentials
  also sit in clear text in `/etc/caddy/ovh/`, one file per value, `root:caddy` 0640 in a 0750 directory, because Caddy
  needs them; they are not in Caddy's environment); the setup URL is not in the install log, and Caddy's
  log filter removes the `token` query parameter from every log line (there is no access log);
  secrets do not appear in command-line arguments.
- **The Mercure hub** listens on loopback only and is reachable only through Caddy.

## 8. What the installer deliberately does not touch

The installer **never changes `sshd`, the firewall (`ufw`, `iptables`, `nftables`), `fail2ban` or unattended-upgrades
settings**: a mistake there locks you out of a remote host, so host hardening is the operator's decision. Do it
yourself, and do it carefully (keep a second SSH session open while you test). At the least, allow SSH, 80 and 443
and deny the rest, and remember that Docker publishes container ports by editing the firewall itself. A
hardening guide for 1.x is planned with the host operations milestone. The Ansible roles in `ansible/` describe the
0.x layout and are not used by this installer (see [`../ansible/LEGACY-0x.md`](../ansible/LEGACY-0x.md)).

## 9. Uninstalling

Nothing in the installer calls the uninstaller. Run it deliberately, **from the root-owned copy** the installer
keeps (the checkout in `/opt/ddev-fleet` belongs to the `fleet` user and is removed by the uninstaller itself; do
not run root code from a directory another account can write; the uninstaller refuses to start as root from a file or
directory that is not owned by root or is writable by anyone else):

```bash
sudo bash /usr/local/lib/ddev-fleet-installer/install/uninstall.sh --yes --dry-run     # review the plan first
sudo bash /usr/local/lib/ddev-fleet-installer/install/uninstall.sh --yes [--keep-data] [--purge-packages]
```

**Back up first.** Without `--keep-data` the uninstaller deletes `/srv/fleet` (databases, the secret key, the SSH
keys, every instance) and `/etc/ddev-fleet` (the generated secrets), and `/etc/authelia/secrets`: none of it can
be recreated. Copy at least `/srv/fleet` and `/etc/ddev-fleet` somewhere safe (as root, with `tar -C / -czpf
backup.tgz --exclude=srv/fleet/staging srv/fleet etc/ddev-fleet etc/authelia/secrets`).

| Flag | Effect |
|---|---|
| `--yes` | Required. Without it nothing is removed. |
| `--keep-data` | Keep `/srv/fleet` (databases, secret key, SSH keys, instances), Authelia's secrets, its database and the tmpfiles mask, and the `fleet` user that owns them. Everything else is removed, including `/etc/ddev-fleet` (so `fleet.env` and its generated secrets are gone: a later install generates new ones; copy `/etc/ddev-fleet` beforehand if you want them back). |
| `--purge-packages` | Also purge Caddy, Authelia, DDEV and Docker CE and remove their repositories. Without it the packages stay installed. |
| `--dry-run` | Print what would be removed. Works without root, but still needs `--yes`. |

It stops DDEV (`ddev poweroff`), disables and removes the units and configuration, restores the stock php-fpm pool
and the packaged Caddy, removes the helper, the sudoers line, the `fleet` command, `/opt/ddev-fleet` and (unless
`--keep-data`) `/srv/fleet` and the `fleet` user. It never touches `sshd` or the firewall, so your SSH access is
unaffected. It leaves `/var/log/ddev-fleet-install.log` and the apt packages (unless asked) in place.

A failed or half-finished install needs no uninstall: run the install command again.

## 10. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| The installer stops with `... has the A record ..., which is not an address of this host` (exit 2) | An A record for `<domain>` or `auth.<domain>` points elsewhere, or an AAAA record is not this host's (Let's Encrypt prefers IPv6; fix it or remove the AAAA record). Fix DNS, or `--skip-dns-check` if you know better. "DNS check inconclusive" means the lookup itself failed (timeout, SERVFAIL). The check asks the system resolver first; when that answers a loopback address (Debian's `/etc/hosts` line for the host name, served by systemd-resolved) it asks 1.1.1.1 and 9.9.9.9 instead (`FLEET_DNS_FALLBACK_RESOLVERS` changes the list). |
| A step failed | The message names the step, the script line and the log. Fix the cause and run the same command again. |
| No certificate, the browser warns | Port 80 or 443 is closed, or DNS is wrong. `journalctl -u caddy -n 100 --no-pager`. In `ovh_dns` mode check the OVH credentials' rights and, if you restricted them to an IP address, that it is this host's. |
| After a reboot the sign-in portal answers 502 and nobody can sign in | Authelia could not read its secrets. The Authelia package ships a tmpfiles rule that strips the execute bit of `/etc/authelia/secrets` at every boot; the installer masks it with `/etc/tmpfiles.d/authelia.conf`. Check that file exists and `stat -c %a /etc/authelia/secrets` prints `750`; re-run the installer to repair it. |
| Finish fails with "The Caddy snippet directory ... lost its setgid bit" | A directory under `/etc/caddy/fleet` lost its group or setgid bit, so Caddy could not read what the app writes. As root: `chgrp -R caddy /etc/caddy/fleet; find /etc/caddy/fleet -type d -exec chmod 2750 {} +; find /etc/caddy/fleet -type f -exec chmod 0640 {} +; systemctl restart caddy`. A re-run of the installer repairs it too. |
| `https://<domain>/health` does not answer after the install | `journalctl -u caddy -u authelia -u php8.4-fpm -u fleet-mercure -u fleet-worker@1 -u fleet-status -n 50 --no-pager`. |
| An instance deploys but its site does not open | The wildcard record `*.<domain>` is missing, you are not an administrator (members cannot open instance sites yet), or Let's Encrypt refused the certificate: `journalctl -u caddy -n 100`. |
| A deploy or destroy job is `interrupted` or fails | See the troubleshooting table in [`instances-and-jobs.md`](instances-and-jobs.md). |
| The wizard answers 403 | No valid session cookie: open the setup URL again (it is valid for 24 hours), or mint a new one with `sudo fleet setup:token`. 429 means too many attempts from this address; wait 15 minutes. |
| The wizard answers 404 "Setup is complete" | It is complete. Sign in at `https://<domain>/`; to start over use `sudo fleet setup:reset`. |
| `fleet user:reset-password` says it needs a terminal | Use `ssh -t` (section 5). |
