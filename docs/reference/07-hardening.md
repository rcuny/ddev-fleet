# Hardening reference

Hardening locks down SSH and the firewall on a host exposed to the Internet. It is opt-in, because a mistake locks you out of a remote machine. This page lists what each part does and, more important, how to get back in and how to undo it. Overview: [secure-the-host](../guides/08-secure-the-host.md).

A plain install or upgrade never touches `sshd`, `ufw`, fail2ban or unattended-upgrades. Docker's `daemon.json` and mail relays are never part of it.

## Turn it on

Open a **second SSH session** and keep it open. Preview (changes nothing), then apply:

```bash
sudo bash install/install.sh --dry-run --domain=fleet.example.com --harden --trusted-ip=203.0.113.7
sudo bash install/install.sh --domain=fleet.example.com --harden --trusted-ip=203.0.113.7
```

| Flag | Meaning |
|---|---|
| `--harden` | Apply the parts below. |
| `--ssh-allow-user=<user>` | Add to `AllowUsers` (repeatable). |
| `--trusted-ip=<addr>` | Never banned, all ports open to it (repeatable). |
| `--harden-tmp` | Also mount `/tmp` `nosuid,nodev,noexec` (see below). |
| `--firewall-grace=<min>` | Minutes before an unconfirmed firewall is switched off: 2 to 120, default 10. |

Choices are stored in `/etc/ddev-fleet/hardening.conf`. While it says `ENABLED=1`, every later installer run and `sudo fleet upgrade` re-applies them without `--harden`. A list flag replaces its list; `--harden` alone keeps what was stored.

## The parts

| Part | Result (file) |
|---|---|
| SSH | `PasswordAuthentication no`, `KbdInteractiveAuthentication no`, `PermitRootLogin prohibit-password`, `MaxAuthTries 3`, `AllowUsers` (`/etc/ssh/sshd_config.d/10-fleet-hardening.conf`). Checked with `sshd -t`, then `ssh` is reloaded; sessions survive. Refused if your account is not in the list or has no key, or if another account with `authorized_keys` is missing from the list. |
| fail2ban | `sshd` jail: 5 tries in 10 min, banned 1 h; localhost, trusted IPs and your current session are ignored (`/etc/fail2ban/jail.d/fleet-sshd.conf`). |
| Firewall | `ufw`: deny incoming; allow SSH, 80, 443, trusted IPs and Fleet's named ports. A `DOCKER-USER` guard block in `/etc/ufw/after.rules` stops Docker bypassing it. |
| Named-port sync | `fleet-firewall-sync.timer` opens or closes named ports within a minute; it never touches SSH, 80, 443 or your own rules. |
| Dead-man's switch | `fleet-firewall-deadman.timer` switches the firewall **off** after the grace period unless you confirm; `fleet-firewall-deadman-bootcheck.service` does the same at boot until you confirmed once. |
| Other | LLMNR off (`/etc/systemd/resolved.conf.d/fleet-llmnr.conf`); sysctl (`/etc/sysctl.d/60-fleet-hardening.conf`); auditd rules (`/etc/audit/rules.d/50-fleet.rules`). |

`--harden-tmp` takes effect at the next boot (override `/etc/systemd/system/tmp.mount.d/override.conf`). `noexec` can break composer, npm, Playwright and DDEV helpers: test first.

## Confirm the firewall

From a **second, independent** session, as the installer prints:

```bash
sudo /usr/local/sbin/fleet-firewall-confirm
```

This writes `/etc/ddev-fleet/firewall-confirmed` and stops the timer. The installer clears the file whenever it re-enables the firewall or SSH ports or trusted addresses change. Time left: `systemctl list-timers fleet-firewall-deadman.timer`.

## Day-to-day

- **Add a trusted address:** re-run with `--harden` and the full `--trusted-ip` list. A dropped address is not removed from `ufw` (`ufw status numbered`, `ufw delete <n>`).
  Without `--ref` the installer keeps the release the checkout is on (it records the tag when HEAD is exactly one); to be explicit, pass `--ref=<installed tag>`.
- **"Too many authentication failures"** (an agent offering many keys, `MaxAuthTries 3`): `ssh -o IdentitiesOnly=yes -i <key> ...`.

## Locked out

Wait for the grace period: the firewall turns itself off. For an SSH lock-out or a ban, use the provider console (rescue or KVM), then remove `/etc/ssh/sshd_config.d/10-fleet-hardening.conf` and reload `ssh`, or run `fail2ban-client unban <ip>`.

## Undo on purpose

**The uninstaller does not undo hardening.**

1. Set `ENABLED=0` in `/etc/ddev-fleet/hardening.conf`.
2. `ufw disable`; `systemctl stop fleet-firewall-sync.timer fleet-firewall-deadman.timer`; `systemctl disable fleet-firewall-sync.timer fleet-firewall-deadman-bootcheck.service`.
3. Delete the files in the table above, plus `/etc/systemd/system/tmp.mount.d/override.conf` if used.
4. Remove the block between `# BEGIN ddev-fleet DOCKER-USER guard` and `# END ...` in `/etc/ufw/after.rules`.
5. Reload `ssh`; restart `fail2ban` and `systemd-resolved`.

## Authelia "Forgot password?" by e-mail

Authelia's `one_factor` (password) sign-in has a self-service reset, **off until an SMTP server is configured** (its link could not be delivered otherwise). Until then use `fleet user:reset-password` ([04-installer.md](04-installer.md)). To switch it on:

1. In `/etc/ddev-fleet/fleet.env` set `FLEET_AUTHELIA_SMTP_ADDRESS` (`smtp://host`, `submission://host:587` or `submissions://host:465`), `FLEET_AUTHELIA_SMTP_SENDER` (the From address) and, if the server needs a login, `FLEET_AUTHELIA_SMTP_USERNAME`.
2. Fleet never writes the SMTP password. Put it in a file (`root:authelia`, 0640), e.g. `/etc/authelia/secrets/smtp_password`, and point Authelia at it with a systemd drop-in for `authelia.service`: `Environment=AUTHELIA_NOTIFIER_SMTP_PASSWORD_FILE=/etc/authelia/secrets/smtp_password`. Then `sudo systemctl daemon-reload`.
3. `sudo fleet host:render --apply`.

Fleet then adopts a password hash Authelia changed (a user's reset) before rewriting the users file, unless an administrator set that password in Fleet meanwhile (Fleet wins). `users.yml` becomes group-writable (0660) for that. This is separate from the Notifications tab in Host settings, which stores a relay for the host's e-mails.

## Learn more

- [Secure the host](../guides/08-secure-the-host.md): the security model.
- [Installer](04-installer.md): flags, recovery and uninstall.
- [Files on disk](03-files-on-disk.md): units and timers.
