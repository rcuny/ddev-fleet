# Hardening reference

Hardening locks down SSH and the firewall on a host exposed to the Internet. It is opt-in, because a mistake locks you out of a remote machine. This page lists what each part does and, more important, how to get back in and how to undo it. Overview: [secure-the-host](../guides/08-secure-the-host.md).

A plain install or upgrade never touches `sshd`, `ufw`, fail2ban or unattended-upgrades. Docker's `daemon.json` and mail relays are never part of it.

## From the web page (Hardening tab)

On a host installed with the Ansible engine (`install.sh --engine=ansible`), an administrator can turn hardening on, off or change it from **Settings → Hardening** instead of re-running the installer. The tab edits the same answers as the flags above: the allowed SSH accounts, the trusted addresses, the firewall grace period and the `/tmp` option. It also asks for **the SSH account you log in with**, which must be in the allow list; the run refuses a change that would lock that account out, as `--harden` does.

- Saving asks for your password again (once per change), then queues a job and opens its live log. The job writes the answers to `/etc/ddev-fleet/host-vars.yml` and runs the installed release's own playbook through the root helper `apply provision`. The page chooses no playbook, role or argument.
- If the run fails, the previous answers file is put back (only when that run installed new answers), so the next upgrade does not apply a half-accepted change. The rollback restores the answers file only: it does not undo a half-applied playbook run.
- With hardening on, the helper refuses answers that lack the SSH account you log in with, or whose allow list does not contain it (exit 65), so a change cannot lock you out.
- Switching hardening off here stops the host managing it; it does not undo what was changed (use the steps in [Undo on purpose](#undo-on-purpose) for that).
- The tab shows the state of the firewall dead-man's switch. While it is armed, the page tells you to run `sudo fleet-firewall-confirm` **from a second SSH session**. The page never confirms for you: logging in again is the proof that you can still reach the host.
- Unavailable on a host whose root helper predates `apply provision` (upgrade it) and on a host installed with the bash steps (no installed playbook); the tab says which.

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

## Drift check

`sudo fleet host:drift` (and **Settings → Drift**, button "Run drift check") compares the host with what the installed release's playbook would set up, in check mode, through the root helper `apply drift`. It changes nothing. The report lists, per role, what is unchanged, what would change (with the diff; values that look like secrets are masked) and what could not be checked. `--json` prints the same report (schema 1). Exit code: 0 no drift, 1 drift found, 2 a task could not be checked, 3 no report.

Steps that run a command, such as firewall rules, are not visible in check mode: the host can differ there without the report saying so. The check does not run on a schedule and sends no alert. Roles mark drift that a check run cannot show as a changed task with a `DRIFT:` debug message (see `install/ansible/README.md`).
