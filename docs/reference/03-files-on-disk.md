# Files on disk, services and ports

When something misbehaves, you need to know where Fleet keeps things and what runs. This page lists the paths, the systemd units, the ports and the logs.

## Paths

| Path | Owner and mode | Holds |
|---|---|---|
| `/opt/ddev-fleet` | `fleet` 0755 | The product checkout. |
| `/srv/fleet` | `fleet` 0750 | `FLEET_HOME`: `var/` (the two SQLite databases), `assets/<project>/`, `instances/`, `logs/`, `locks/`, `slots/`. |
| `/srv/fleet/keys/secret.key` | `fleet` 0600 (dir 0700) | Encryption key for stored secrets. **Back it up separately**: without it OVH credentials, the Claude token and project secrets cannot be read. |
| `/srv/fleet/ssh` | `fleet` 0700 | `deploy_ed25519` (read), `push_ed25519` (write), extra keys, `known_hosts`. |
| `/srv/fleet/backups` | `fleet` 0700 | Daily database backups (files 0600). |
| `/srv/fleet/uploads`, `/srv/fleet/staging` | `fleet` 0700 | Browser-upload chunks; rendered config waiting for the root helper. Empty at rest; leave out of backups. |
| `/srv/fleet/cache/tailwind` | `fleet` | Cached Tailwind binary. Safe to delete. |
| `/srv/fleet/authelia/users.yml` | `fleet:authelia` 0640 | The user file Authelia reads, written by the app. |
| `/etc/ddev-fleet` | `root:fleet` 0750 | `fleet.env` (0640), `mercure.env` (0600), `installed-version`, `installed-revision`, `installed-engine` (`ansible`; `bash` on a host not yet upgraded since the bash engine was removed), `hardening.conf`. |
| `/etc/caddy/Caddyfile`, `/etc/caddy/fleet/` | `root`; `fleet:caddy` | Caddy configuration, the sign-in gate, per-instance snippets and `ports/`. |
| `/etc/caddy/ovh/` | `root:caddy` 0750 | `ovh_dns` mode only: three credential files in clear text (0640), because Caddy needs them. |
| `/etc/authelia/` | `authelia` | Configuration and generated secrets (`secrets/`). |
| `/run/php/fleet.sock` | `fleet:caddy` 0660 | The only way into the app (php-fpm). |
| `/usr/local/libexec/fleet/apply`, `/etc/sudoers.d/fleet-apply` | `root` | The root helper (`caddy`, `authelia`, `slices`, `provision`) and its one sudoers line. |
| `/usr/local/bin/fleet` | `root` 0755 | The `fleet` command: switches to the `fleet` user and loads `fleet.env`. |
| `/usr/local/lib/ddev-fleet-installer/` | `root` | Root-owned copy of `install/`; the uninstaller runs from here. |

## Services

| Unit | What it does |
|---|---|
| `fleet-worker@1` | The job worker. Run **exactly one**: never enable `fleet-worker@2`; the SQLite queue allows one consumer. Raise concurrency in the host settings (Jobs tab) instead. |
| `fleet-scheduler` | Schedule ticker (every 30 seconds): fires due schedules. |
| `fleet-status` | Status watcher (every 10 seconds): keeps instance states true, reaps dead jobs, prunes old ones. |
| `fleet-boot` | Oneshot after a reboot: `fleet:instance:start --all --sequential`, so instances start one at a time. |
| `fleet-tmux` | Owns the `fleet` tmux session (the tmux workspace) in its own cgroup. |
| `fleet-mercure` | The Mercure hub for live updates (loopback). |
| `fleet-backup.timer` | Daily database backup (`fleet:backup:create`). |
| `fleet-tls-check.timer` | Certificate health check, 3 minutes after boot and every 10 minutes. |
| `fleet-upgrade-check.timer` | Daily check for a newer release (`fleet:upgrade:refresh`). |
| `caddy`, `authelia`, `php8.4-fpm` | The TLS front door, the sign-in portal, the app (one pool, `fleet`). |

## Ports

| Port | Process | Bound to | Public? |
|---|---|---|---|
| `22` | sshd | all | Yes (operators) |
| `80`, `443` | Caddy | all | Yes |
| Catalogue public ports (`9108` for `typesense`) | Caddy | all | Yes, if a project uses it |
| `2019` | Caddy admin API | loopback | No |
| `3000` | Mercure hub | loopback | No |
| `8765` | reserved | loopback | No |
| `9091` | Authelia (served as `https://auth.<domain>`) | loopback | No |
| `8080`, `8443` | DDEV router | loopback | No |
| Catalogue router ports (`8108`) | DDEV router | loopback | No |

The installer does not touch the firewall; `fleet port:firewall` lists what to open.

## Logs

| What | Where |
|---|---|
| Installer | `/var/log/ddev-fleet-install.log` (never holds the setup URL or a secret) |
| Upgrade history | `/var/log/ddev-fleet/upgrade-history.jsonl` |
| Job logs | `/srv/fleet/logs/`, or `fleet instance:log <id>` |
| Services | `journalctl -u caddy -u authelia -u php8.4-fpm -u fleet-mercure -u fleet-worker@1 -u fleet-status -n 50` |

## Learn more

- [Installer](04-installer.md)
- [Configuration](02-configuration.md)
- [Networking](09-networking.md)
