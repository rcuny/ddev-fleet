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
Mercure hub, DDEV and Docker). Projects, instances and jobs arrive in later milestones (see
[`README.md`](README.md)). The 0.x installation guide is kept in [`v0/installation.md`](v0/installation.md).

## 1. Before you start

| You need | Detail |
|---|---|
| A host | **Debian 13**, amd64 or arm64, reachable on the Internet. The installer refuses another system (`FLEET_FORCE_OS=1` in the environment tries anyway, unsupported). |
| Root | Run the installer as root (`sudo`). `--dry-run` works without root. |
| DNS | A record (and AAAA, if you have IPv6) for **both** `<domain>` and `auth.<domain>` pointing at this host. One wildcard record `*.<domain>` covers `auth.<domain>`. The installer compares them with the host's own addresses and stops (exit 2) on a mismatch. |
| Ports | 80 and 443 free and reachable from the Internet (Let's Encrypt uses port 80 for the HTTP-01 challenge, even if you later switch to DNS-01). |
| Time | Several minutes. Run it inside `screen`, `tmux` or `systemd-run`: a dropped SSH session would interrupt it. If it is interrupted anyway, run the same command again. |

`<domain>` is the public host name of this fleet, for example `fleet.example.com`: the fleet is served on
`https://fleet.example.com/` and the sign-in portal on `https://auth.fleet.example.com/`. Both get their own
certificate, so **`auth.<domain>` needs DNS in every TLS mode**.

## 2. Install

```bash
curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/develop/install/install.sh \
  | sudo bash -s -- --domain=fleet.example.com --acme-email=you@example.com
```

At the end the installer prints a one-time **setup URL** on the terminal (it is never written to the install
log). Open it in a browser and follow section 4.

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
| 60 authelia | Authelia from its repository, its three secrets (generated once), the tmpfiles mask (see Troubleshooting), an empty valid `users.yml`. |
| 70 env | `/etc/ddev-fleet/fleet.env` and `mercure.env`: only missing keys are added, secrets are generated once. |
| 80 code | `composer install --no-dev`, assets, prod cache, as `fleet` with the application environment. |
| 85 app init | Migrations, the queue transport, `fleet:host:init` (domain, ACME email). |
| 90 privileged | The root helper, its single sudoers line and the `fleet` command. |
| 95 units | The `fleet` php-fpm pool, the job worker `fleet-worker@.service`, the standalone Mercure hub (pinned release, sha256-checked) and its unit. |
| 98 render and start | Renders the Caddy and Authelia configuration, installs it through the helper, starts everything, waits until `https://<domain>/health` and the hub answer, and prints the setup URL. |

The installer logs to `/var/log/ddev-fleet-install.log` (mode 0600).

## 3. What is installed where

### Users and directories

| Path | Owner and mode | Holds |
|---|---|---|
| user `fleet` | home `/home/fleet`, group `docker` | Runs php-fpm, the workers, DDEV and the CLI. |
| `/opt/ddev-fleet` | `fleet:fleet` 0755 | The git checkout of the product. Stays `fleet`-owned. |
| `/srv/fleet` | `fleet:fleet` 0750, ACL `authelia:--x` | `FLEET_HOME`: `var/fleet.sqlite` and `var/queue.sqlite`, `keys/secret.key` (the encryption key), `ssh/` (`deploy_ed25519`, `push_ed25519`, `known_hosts`), `staging/` (0700), `locks/`, `logs/`, `assets/`, `instances/`. |
| `/srv/fleet/authelia` | `fleet:authelia` 2750 | `users.yml`, the sign-in portal's user file (written by the app). |
| `/etc/ddev-fleet` | `root:fleet` 0750 | `fleet.env` (0640, see below), `mercure.env` (`root:root` 0600), `installed-revision`. |
| `/etc/caddy/Caddyfile` | root | Written by the root helper, never by the app. |
| `/etc/caddy/fleet/` | `fleet:caddy` 2750 | `gate.conf`, the sign-in gate, written by the app; `instances/` and `ports/` for later milestones. |
| `/etc/caddy/fleet.env` | `root:caddy` 0640 | The proxy secret and, in DNS mode, the OVH credentials. Caddy reads it at start. |
| `/etc/authelia/` | | `configuration.yml` (written by the helper), `secrets/` (`root:authelia` 0750, generated once). |
| `/etc/fleet-mercure/Caddyfile` | root | The Mercure hub configuration (it lives apart from `/etc/ddev-fleet` because the hub runs as a dynamic user). |
| `/run/php/fleet.sock` | `fleet:caddy` 0660 | The only way into the app. |
| `/usr/local/libexec/fleet/apply` | `root:root` 0755 | The root helper. |
| `/etc/sudoers.d/fleet-apply` | 0440 | The one sudoers line (section 7). |
| `/usr/local/bin/fleet` | `root:root` 0755 | The `fleet` command (section 6). |
| `/usr/local/bin/mercure` | root | The Mercure hub binary. |

### Units and ports

| Unit | Listens on | Notes |
|---|---|---|
| `caddy` | 80, 443 | The only Internet-facing process; sandboxed by a drop-in. Reads `/etc/caddy/fleet.env`. |
| `authelia` | `127.0.0.1:9091` | The sign-in portal, served publicly as `https://auth.<domain>/` by Caddy. |
| `php8.4-fpm` | `/run/php/fleet.sock` | One pool, `fleet`. `request_terminate_timeout` is 240 s (the Finish request can take that long). The unit drop-in loads `fleet.env`. |
| `fleet-worker@1` | | One Messenger worker (`messenger:consume jobs`). More can be enabled as `fleet-worker@2` and so on. |
| `fleet-mercure` | `127.0.0.1:3000` | A standalone Mercure hub (version 1.1.2, legacy protocol mode as `symfony/mercure-bundle` mints). Caddy proxies `/.well-known/mercure` to it. |
| `docker`, DDEV | router on 8080 and 8443 | The installer does not set `router_bind_all_interfaces`, so DDEV keeps its default (loopback only). |

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
| `FLEET_ADMIN_GROUP` | `fleet-admins`. |
| `FLEET_CADDY_DIR` | `/etc/caddy/fleet`. |
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
   The account is created in the `fleet-admins` group.
2. **Domain and certificates.** The domain was set by the installer. Choose how certificates are obtained:
   - `http01` (default): Let's Encrypt HTTP-01 on port 80, for `<domain>` and `auth.<domain>`.
   - `ovh_dns`: DNS-01 through the OVH API. Enter the API endpoint (`ovh-eu`, `ovh-ca` or `ovh-us`), the
     application key, the application secret and the consumer key. The credentials need `GET`, `POST`, `PUT`
     and `DELETE` on `/domain/zone/<zone>/*` for your zone. They are stored encrypted (libsodium, key in
     `/srv/fleet/keys/secret.key`) and never shown again. The endpoints of OVH's budget brands are not
     supported: create the credentials at one of the three endpoints above. Today this mode only changes how
     the two certificates are obtained; the wildcard certificate arrives with instance networking.
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
   only) and marks setup complete, **in that order and last**. It can take a minute or two; keep the page open.
   If a step fails, the page shows why, nothing is marked complete and the link still works: fix the cause and
   press Finish again.

After Finish the setup token is gone and every `/setup` URL answers 404 "Setup is complete". Open
`https://<domain>/`: you are sent to `https://auth.<domain>/` to sign in with the administrator you created.
Authelia's own self-service password reset is switched off until a later milestone (use section 5).

## 5. Recovery

All commands run on the host. `fleet` is the wrapper the installer puts in `/usr/local/bin`: it switches to the
`fleet` user and loads `/etc/ddev-fleet/fleet.env`. `fleet setup:token` is short for `fleet fleet:setup:token`.

| Situation | Command |
|---|---|
| Lost the setup URL (setup not complete) | `sudo fleet setup:token` prints a new one-time URL; the old one stops working. It refuses once setup is complete. |
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
sign in. Existing users, keys and secrets are kept and the wizard updates them. It asks for confirmation on a
real terminal; `--yes` skips the question (required without a terminal). It is logged. Finishing the wizard again
restarts Authelia, so sessions are dropped here as well.

The remaining commands exist mostly for the installer:

| Command | Purpose |
|---|---|
| `fleet:host:init --domain=<fqdn> [--acme-email=] [--tls-mode=http01\|ovh_dns] [--force]` | Create the state directories, the secret key and the host settings. Idempotent. Changing the domain or the TLS mode after setup needs `--force`. |
| `fleet:host:render [--gate=auto\|setup\|authelia] [--no-reload] [--apply] [--force]` | Render the Caddy and Authelia configuration into the staging directory and write the gate. `--apply` installs it through the root helper. `--gate=setup` after completion needs `--force` (prefer `fleet:setup:reset`). |
| `fleet:setup:token` | Mint a setup token and print the URL. |
| `fleet:setup:reset [--yes]` | See above. |
| `fleet:user:reset-password <username> [--no-apply]` | See above. |

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

## 7. Security model

- **Caddy is the only Internet-facing process.** It strips every `Remote-*` and `Remote_*` request header on every
  route, including `/health`, `/setup` and in setup mode, then runs the Authelia `forward_auth` gate, which
  copies the verified identity back. The app trusts an identity only from a request that carries the shared
  secret as the FastCGI parameter `FLEET_PROXY_AUTH` (a client cannot set a FastCGI parameter) and refuses a secret
  shorter than 32 characters.
- **Authelia** uses the one-factor policy for `<domain>`, bypasses its own portal and denies every other host by
  default. Nothing under `*.<domain>` is served yet; instances and their per-project access arrive in later
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
  token are stored encrypted with a key that is only readable by `fleet`; the setup URL is not in the install log;
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

Nothing in the installer calls the uninstaller. Run it deliberately:

```bash
sudo bash /opt/ddev-fleet/install/uninstall.sh --yes [--keep-data] [--purge-packages] [--dry-run]
```

| Flag | Effect |
|---|---|
| `--yes` | Required. Without it nothing is removed (and `--dry-run` alone is refused too). |
| `--keep-data` | Keep `/srv/fleet` (databases, secret key, SSH keys, instances), Authelia's secrets and database, and the `fleet` user that owns them. |
| `--purge-packages` | Also purge Caddy, Authelia, DDEV and Docker CE and remove their repositories. Without it the packages stay installed. |
| `--dry-run` | Print what would be removed. Works without root. |

It stops DDEV (`ddev poweroff`), disables and removes the units and configuration, restores the stock php-fpm pool
and the packaged Caddy, removes the helper, the sudoers line, the `fleet` command, `/opt/ddev-fleet` and (unless
`--keep-data`) `/srv/fleet` and the `fleet` user. It leaves `/var/log/ddev-fleet-install.log` and the apt packages
(unless asked) in place.

## 10. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| The installer stops with `... has the A record ..., which is not an address of this host` (exit 2) | An A record for `<domain>` or `auth.<domain>` points elsewhere, or an AAAA record is not this host's (Let's Encrypt prefers IPv6; fix it or remove the AAAA record). Fix DNS, or `--skip-dns-check` if you know better. "DNS check inconclusive" means the lookup itself failed (timeout, SERVFAIL). |
| A step failed | The message names the step, the script line and the log. Fix the cause and run the same command again. |
| No certificate, the browser warns | Port 80 or 443 is closed, or DNS is wrong. `journalctl -u caddy -n 100 --no-pager`. In `ovh_dns` mode check the OVH credentials' rights and, if you restricted them to an IP address, that it is this host's. |
| After a reboot the sign-in portal answers 502 and nobody can sign in | Authelia could not read its secrets. The Authelia package ships a tmpfiles rule that strips the execute bit of `/etc/authelia/secrets` at every boot; the installer masks it with `/etc/tmpfiles.d/authelia.conf`. Check that file exists and `stat -c %a /etc/authelia/secrets` prints `750`; re-run the installer to repair it. |
| Finish fails with "The Caddy snippet directory ... lost its setgid bit" | A directory under `/etc/caddy/fleet` lost its group or setgid bit, so Caddy could not read what the app writes. As root: `chgrp -R caddy /etc/caddy/fleet; find /etc/caddy/fleet -type d -exec chmod 2750 {} +; find /etc/caddy/fleet -type f -exec chmod 0640 {} +; systemctl restart caddy`. A re-run of the installer repairs it too. |
| `https://<domain>/health` does not answer after the install | `journalctl -u caddy -u authelia -u php8.4-fpm -u fleet-mercure -u fleet-worker@1 -n 50 --no-pager`. |
| The wizard answers 403 | No valid session cookie: open the setup URL again (it is valid for 24 hours), or mint a new one with `sudo fleet setup:token`. 429 means too many attempts from this address; wait 15 minutes. |
| The wizard answers 404 "Setup is complete" | It is complete. Sign in at `https://<domain>/`; to start over use `sudo fleet setup:reset`. |
| `fleet user:reset-password` says it needs a terminal | Use `ssh -t` (section 5). |
