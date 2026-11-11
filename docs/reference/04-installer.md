# Installer reference

The installer turns a fresh Debian 13 host into a working Fleet, and the same command upgrades or repairs it. This page lists its flags, exit codes, roles, checks and recovery commands. For the walk-through, see the [install guide](../guides/01-install.md).

## Flags

```bash
curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/main/install/install.sh \
  | sudo bash -s -- --domain=fleet.example.com --acme-email=you@example.com
```

| Flag | Meaning |
|---|---|
| `--domain=<fqdn>` | Required. The public host name. `--domain=auto` derives `<ip-with-dashes>.nip.io` from the host's public IPv4 address (the `FLEET_PUBLIC_IPV4` variable overrides the lookup); instances are `<instance>.<ip-with-dashes>.nip.io`, one Let's Encrypt certificate each. A test convenience: [try on DigitalOcean](../guides/11-try-on-digitalocean.md). |
| `--acme-email=<email>` | Let's Encrypt contact address (recommended). |
| `--ref=<ref>` | Branch, tag or commit to install; default `main`. Pin a release with `--ref=vX.Y.Z`. |
| `--repo-url=<url\|path>` | Install from another git remote or path instead of the public mirror. |
| `--skip-dns-check` | Do not compare DNS with the host's addresses (NAT, CDN). |
| `--no-shell-profile` | Skip the shell prompt and alias profile. |
| `--harden` | Opt-in host hardening ([07-hardening.md](07-hardening.md)). |
| `--ssh-allow-user=<name>` | With `--harden`, repeatable: account allowed to log in over SSH (default: the `sudo` caller). |
| `--trusted-ip=<ip\|cidr>` | With `--harden`, repeatable: address never banned, always allowed through the firewall. |
| `--harden-tmp` | With `--harden`: mount `/tmp` `nosuid,nodev,noexec` at next boot (can break builds). |
| `--firewall-grace=<minutes>` | With `--harden`: dead-man's switch delay, 2 to 120, default 10. |
| `--engine=ansible` | Deprecated and ignored: the Ansible playbook is the only engine. `--engine=bash` is refused with exit `64` (the bash engine was removed). Scripts that pass `--engine=ansible` keep working. |
| `--preflight` | Check the host and change nothing; works without root. |
| `--dry-run` | Print the plan, change nothing. |
| `--help` | Usage text. |

Exit codes: `0` ok, `1` a task failed, `2` DNS mismatch, `3` pre-flight blocked, `64` usage, `75` another run in progress, `77` not root, `78` unsupported host.

## What it does

Two stages. `install.sh` validates the flags, installs `ca-certificates`, `curl` and `git`, creates the `fleet` user and clones the ref into `/opt/ddev-fleet` **as `fleet`, never as root**, then copies `install/` to a root-owned location and runs its `run.sh`. Stage 2 runs the pre-flight and DNS checks, then the Ansible playbook `install/ansible/site.yml` (see [the playbook](#the-playbook)). The roles run in this order:

| Role | Does |
|---|---|
| `packages` | PHP 8.4 from Debian, Composer, `openssh-client`, `acl`, `sudo`, `tmux`, a few tools. |
| `docker` | Docker CE; `daemon.json` is merged, never replaced: an address pool of `/24`s in `10.64.0.0/10` (Docker's default holds about 30 instance networks), `live-restore`, log rotation. Docker is never restarted on a host that already runs it. |
| `fleet_user` | The `fleet` user (in group `docker`), `/srv/fleet`, `/etc/ddev-fleet`, `/opt/ddev-fleet`. |
| `shell`, `ddev`, `caddy` | Shell profile (not with `--no-shell-profile`); DDEV (router on 8080, 8443); Caddy with the OVH DNS module. |
| `authelia` | Authelia, its three secrets (generated once), a disabled placeholder user. |
| `php_app` | `fleet.env` and `mercure.env`: only missing keys are added; the php-fpm pool. |
| `app_code` | `composer install --no-dev`, assets, prod cache, migrations, queue transport, `fleet:host:init`. |
| `privileged` | Root helpers, the `apply` sudoers line, the `fleet` command. |
| `units` | Worker, status watcher, boot, Mercure, scheduler and timer units; renders and installs the Caddy and Authelia configuration, starts everything and waits for `https://<domain>/health`. |
| `hardening` | Only with `--harden` or stored hardening settings ([07-hardening.md](07-hardening.md)). |

The playbook is idempotent: a re-run converges, adds only missing `fleet.env` keys and never changes existing ones. A re-run on a host that is already up to date changes nothing (a check-mode run of the playbook reports `changed=0`), and a build runs only when the code or the environment changed. If a task fails, read it in `/var/log/ddev-fleet-install.log`, fix the cause and run the same command again.

The installer waits (bounded) for running jobs to finish before it swaps code and database, because a job running on while the code changes can fail. `FLEET_INSTALL_JOB_WAIT=<seconds>` in the installer's environment sets the limit (default 300; `0` skips the wait). Jobs still running after that are left to finish. After a reboot or upgrade, start instances one at a time with `fleet:instance:start --all --sequential`.

## The playbook

Ansible is the only engine (since the bash steps were removed; hosts first installed by the older bash engine are moved over by their next [upgrade](05-upgrade.md#install-engine)). Stage 1 installs nothing of it. Stage 2 runs the flag validation, pre-flight checks and DNS check, then:

1. installs `ansible-core` and `python3-apt` from Debian's repository;
2. writes `/etc/ddev-fleet/host-vars.yml` from the flags;
3. runs `ansible-playbook -i localhost, -c local install/ansible/site.yml -e fleet_ref=<ref> -e fleet_hardening_strict=<true|false>` from the root-owned copy of `install/`.

`--dry-run` prints the three actions and changes nothing. To see what a run would change on a live host, run the playbook yourself in Ansible's check and diff mode from `/usr/local/lib/ddev-fleet-installer/install/ansible` (needs the same `-e` values; `command` tasks are skipped in check mode).

### host-vars.yml

The host's own answers, mode `0644` (no secret in it), rewritten on every run from the flags and, for hardening, from the settings an earlier run stored in `hardening.conf` (a list flag replaces that list; `--harden` alone keeps what was stored). The installer's writer (`install/lib/host-vars.sh`) and the app's `App\Fleet\System\HostVarsRenderer` produce the same bytes. The release ref and the lock-out check are per run, so they are passed with `-e`, not stored. There is no TLS-mode key: the TLS mode is chosen in the setup wizard.

| Key | From | Meaning |
|---|---|---|
| `fleet_domain` | `--domain` | Public host name. |
| `fleet_acme_email` | `--acme-email` | ACME contact; `""` when not given. |
| `fleet_shell_profile` | `--no-shell-profile` | `false` skips the shell profile. |
| `fleet_hardening_enabled` | `--harden` or stored `ENABLED=1` | When `false`, no hardening key follows. |
| `fleet_hardening_ssh_allow_users` | `--ssh-allow-user` | List; empty means the account running `sudo`. |
| `fleet_hardening_trusted_ips` | `--trusted-ip` | List of addresses or CIDRs. |
| `fleet_hardening_firewall_grace_minutes` | `--firewall-grace` | 2 to 120, default 10. |
| `fleet_hardening_tmp` | `--harden-tmp` | `true` mounts `/tmp` `nosuid,nodev,noexec` at the next boot. |

An explicit `--harden` refuses a lock-out (`fleet_hardening_strict=true`); a stored re-run, such as an upgrade, only warns.

## Setup URL

The installer ends by telling you to run `sudo fleet setup:token`, which prints the setup URL (the playbook never mints it, so it cannot reach a log). It is a credential for 24 hours (or until Finish): whoever opens it can create the administrator. Open it yourself; never paste it into a chat or ticket. Until setup finishes, Caddy lets only `/setup` and `/health` through.

## Pre-flight

`--preflight` prints one line per finding, `BLOCK`, `WARN` or `INFO <id>: <message>`, and exits `0`, or `3` when anything blocks. A normal run does the same checks first and stops before it changes anything on a `BLOCK`. Run it from a checkout: `bash install/run.sh --preflight --domain=<fqdn>`.

| Id | Checks |
|---|---|
| `host.os`, `host.arch` | Debian 13; amd64 or arm64. |
| `disk.opt`, `disk.srv`, `disk.var` | Free space on `/opt` (500 MiB), `/srv/fleet` (2 GiB) and `/var` (2 GiB). |
| `host.packages` | Required commands present (the `packages` role installs missing ones). |
| `docker.answer`, `ddev.answer` | Docker and DDEV answer when installed. |
| `instances.id-length` | Existing instance ids are not too long. |
| `fleet.env` | The file, when it exists, is readable. |
| `network.repo` | The release source answers. |

## Recovery

All on the host; `fleet setup:token` is short for `fleet fleet:setup:token`.

| Situation | Command |
|---|---|
| Lost the setup URL (setup unfinished) | `sudo fleet setup:token` (the old URL stops working). |
| Forgotten password, nobody can sign in | `ssh -t admin@fleet.example.com sudo fleet user:reset-password alice`. Needs a terminal (`ssh -t`); Authelia restarts and **every signed-in session** ends. |
| Start the wizard over | `sudo fleet setup:reset` (keeps users, keys and secrets). |
| Caddy will not start in `ovh_dns` mode | `sudo fleet host:init --tls-mode=http01 --force && sudo fleet host:render --apply && sudo systemctl restart caddy`. |

## OVH token rights (`ovh_dns` only)

Create an API token on your endpoint's `createToken` page (`ovh-eu`, `ovh-ca` or `ovh-us`) with validity Unlimited and four rights, one per method, each on the zone with the path ending in `/*`:

- `GET` `/domain/zone/example.com/*`
- `POST` `/domain/zone/example.com/*`
- `PUT` `/domain/zone/example.com/*`
- `DELETE` `/domain/zone/example.com/*`

The form starts every new line on `GET`: change the other three. A wrong method or a path without `/*` fails every certificate with `This call has not been granted`. The rights match the [libdns/ovh](https://github.com/libdns/ovh#authenticating) calls. Enter the three values in the wizard (with the matching endpoint), or rotate later with `sudo fleet host:ovh-credentials`.

## Uninstall

Run it from the root-owned copy, never from `/opt/ddev-fleet`. **Back up first.** Without `--keep-data` it deletes `/srv/fleet` (databases, secret key, SSH keys, every instance) and `/etc/ddev-fleet`.

```bash
sudo bash /usr/local/lib/ddev-fleet-installer/install/uninstall.sh --yes --dry-run
sudo bash /usr/local/lib/ddev-fleet-installer/install/uninstall.sh --yes [--keep-data] [--purge-packages]
```

| Flag | Effect |
|---|---|
| `--yes` | Required; without it nothing is removed. |
| `--keep-data` | Keep `/srv/fleet`, Authelia's secrets and the `fleet` user. |
| `--purge-packages` | Also remove Caddy, Authelia, DDEV and Docker CE. |
| `--dry-run` | Only print the plan. |

It never touches `sshd` or the firewall, so hardening stays. A failed install needs no uninstall: run the install again.

## Learn more

- [Install guide](../guides/01-install.md)
- [Troubleshooting](13-troubleshooting.md)
- [Upgrade reference](05-upgrade.md)
