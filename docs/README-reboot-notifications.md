# Reboot-required notifications

`security_hardening` never lets Debian reboot the host automatically
(`Automatic-Reboot "false"`, hardcoded — see `ansible/roles/security_hardening/templates/51fleet-hardening.conf.j2`).
Security patches — including kernel and libc — install automatically but
sit inactive until an operator reboots by hand. Three channels make sure
that's noticed, all reading the same shared source,
`src/fleet/core/reboot.py`'s `read_reboot_status()`, over Debian's own
`/var/run/reboot-required` + `.pkgs` marker files:

1. **tmux sidebar** (`fleet tmux`) — a `REBOOT REQUIRED` banner appears at
   the very top of every sidebar pane, above the `general` row and every
   instance row, refreshed every 10s. Absent entirely when no reboot is
   pending.
2. **Email**, via `msmtp` (Debian 13 has no `ssmtp` — `msmtp`+`msmtp-mta`
   is its maintained successor). Sent once when a reboot first becomes
   pending, then again every `fleet_reboot_notify_interval_hours` (default
   24h) until the host is rebooted — never more often, tracked in
   `/srv/fleet/reboot-notify-state.json`.
3. **Web UI footer badge** — a red "Reboot required · pending Nd Nh" badge
   in the dashboard footer, absent when not pending.

## Anti-spam cadence

The `fleet-reboot-notify.timer` systemd unit polls hourly
(`OnCalendar=hourly`), but polling hourly does not mean emailing hourly:
`reboot_notify()` only sends when a reboot first becomes pending, or when
at least `fleet_reboot_notify_interval_hours` have elapsed since the last
send (tracked in `/srv/fleet/reboot-notify-state.json`, cleared the moment
the host is rebooted and the marker disappears). The interval is threaded
end-to-end — `fleet_reboot_notify_interval_hours` is rendered into the
`fleet-reboot-notify.service` unit's `ExecStart` as
`fleet reboot-notify --interval-hours <value>`, so changing the Ansible
variable and re-applying the role actually changes the cadence, not just a
default that's silently ignored.

## Configuring the msmtp relay

### At install time

`bootstrap.sh`'s value-collection phase asks for the relay only when
security hardening is enabled, and only after that: relay host, port,
username, password, from-address, and notify (to) address. Leaving the
relay host blank disables just this channel — the sidebar and web UI
channels are completely unaffected, and the install is never blocked
either way (see "Declining email at install time" below).

Non-secret settings persist to `/etc/ddev-fleet/local-vars.yml`:

- `fleet_msmtp_host`
- `fleet_msmtp_port` (default `587`)
- `fleet_msmtp_tls` (default `true`)
- `fleet_msmtp_from`
- `fleet_msmtp_to`

The username and password are secrets and are **never** written to
`local-vars.yml`. They land instead in `{{ fleet_srv_dir }}/.secrets`
(`/srv/fleet/.secrets`, `0600`, owner `fleet:fleet`) as `MSMTP_USER` /
`MSMTP_PASSWORD` — the exact keys `security_hardening`'s `harden.yml`
reads back out when it renders `/srv/fleet/msmtprc`.

### Adding or changing the relay after install

1. Edit credentials: `{{ fleet_srv_dir }}/.secrets` (`/srv/fleet/.secrets`),
   keys `MSMTP_USER`/`MSMTP_PASSWORD`.
2. Edit non-secret settings: `/etc/ddev-fleet/local-vars.yml`, keys
   `fleet_msmtp_host`/`_port`/`_tls`/`_from`/`_to`.
3. Re-apply **just** the `security_hardening` role via a scoped one-off
   playbook (the same pattern this repo's `CLAUDE.md` documents for the
   `caddy` role — never the full `site.yml` against a live host):
   ```bash
   ansible-playbook -c local --tags security_hardening ansible/site.yml
   ```
   (or re-run `sudo bash bootstrap.sh` on a host that isn't live yet.)

## Install-time test-send

Once security hardening applies with a fully configured relay
(host/from/to/user/password all present), the `security_hardening` role
sends a one-off test email during provisioning and reports the result —
`msmtp test-send SUCCEEDED` or `FAILED (rc=...) — check <srv-dir>/msmtprc
and the relay settings`. You can re-run the same check manually at any
time, without waiting for a real pending reboot:

```bash
sudo -u fleet /opt/ddev-fleet/venv/bin/fleet reboot-notify --test
```

Prints `test email sent` or `test email FAILED to send (check
msmtprc/relay)`.

## Declining email at install time

Leaving the SMTP relay host prompt blank (or `FLEET_MSMTP_HOST` unset in a
non-interactive install) disables the email channel only — the sidebar
and web UI channels are completely unaffected, and the install is never
blocked either way. The `security_hardening` role treats msmtp as fully
optional: if host/from/to/user/password aren't all present, it skips
installing/configuring msmtp and logs that the channel is disabled,
without failing the run.
