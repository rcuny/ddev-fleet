# systemd security report

`systemd-analyze security` scores how much of the system a service's sandbox leaves exposed to it, on a scale from 0 (best: locked down) to 10 (worst: no sandbox at all). The weekly live check reads these scores from each server and the offline check scores the unit files this repository ships.

Every score is compared with the committed baseline `ci/systemd-security-baseline.json`. A unit counts as a regression only when its score rises by more than the tolerance (+0.1) over that baseline.

**Run:** 2026-10-07 08:56 UTC, branch `develop`, [pipeline run](https://bitbucket.org/renaud_cuny/ddev-fleet/pipelines/results/66)

| Host | systemd | Status |
|---|---|---|
| [ddev3](#ddev3) | 257 | OK |

## ddev3

Status: OK. systemd 257, report generated 2026-10-07T08:56:21Z.

The units the product owns:

| Unit | Score | Baseline | Delta | Status |
|---|---|---|---|---|
| `fleet.service` | 1.8 | 1.8 | 0.0 | OK |
| `fleet-boot.service` | 1.8 | 1.8 | 0.0 | OK |
| `fleet-reboot-notify.service` | 1.6 | 1.6 | 0.0 | OK |
| `caddy.service` | 1.6 | 1.6 | 0.0 | OK |
| `authelia.service` | 2.8 | 2.8 | 0.0 | OK |

<details><summary>All 38 units on ddev3</summary>

| Unit | Score | Baseline | Delta | Status |
|---|---|---|---|---|
| `auditd.service` | 8.9 | 8.9 | 0.0 | OK |
| `authelia.service` | 2.8 | 2.8 | 0.0 | OK |
| `caddy.service` | 1.6 | 1.6 | 0.0 | OK |
| `cloud-init-main.service` | 9.5 | 9.5 | 0.0 | OK |
| `containerd.service` | 9.6 | 9.6 | 0.0 | OK |
| `dbus.service` | 9.3 | 9.3 | 0.0 | OK |
| `dm-event.service` | 9.5 | 9.5 | 0.0 | OK |
| `docker.service` | 9.6 | 9.6 | 0.0 | OK |
| `emergency.service` | 9.5 | 9.5 | 0.0 | OK |
| `fail2ban.service` | 9.6 | 9.6 | 0.0 | OK |
| `fleet-boot.service` | 1.8 | 1.8 | 0.0 | OK |
| `fleet-reboot-notify.service` | 1.6 | 1.6 | 0.0 | OK |
| `fleet.service` | 1.8 | 1.8 | 0.0 | OK |
| `getty@tty1.service` | 9.6 | 9.6 | 0.0 | OK |
| `lvm2-lvmpolld.service` | 9.5 | 9.5 | 0.0 | OK |
| `mdmonitor-oneshot.service` | 9.6 | 9.6 | 0.0 | OK |
| `mdmonitor.service` | 9.5 | 9.5 | 0.0 | OK |
| `rc-local.service` | 9.6 | 9.6 | 0.0 | OK |
| `rescue.service` | 9.5 | 9.5 | 0.0 | OK |
| `serial-getty@ttyS1.service` | 9.6 | 9.6 | 0.0 | OK |
| `ssh.service` | 9.6 | 9.6 | 0.0 | OK |
| `sshd@sshd-keygen.service` | 9.6 | 9.6 | 0.0 | OK |
| `systemd-ask-password-console.service` | 9.4 | 9.4 | 0.0 | OK |
| `systemd-ask-password-wall.service` | 9.4 | 9.4 | 0.0 | OK |
| `systemd-bsod.service` | 9.5 | 9.5 | 0.0 | OK |
| `systemd-hostnamed.service` | 1.7 | 1.7 | 0.0 | OK |
| `systemd-initctl.service` | 9.4 | 9.4 | 0.0 | OK |
| `systemd-journald.service` | 4.9 | 4.9 | 0.0 | OK |
| `systemd-logind.service` | 2.8 | 2.8 | 0.0 | OK |
| `systemd-networkd.service` | 2.9 | 2.9 | 0.0 | OK |
| `systemd-resolved.service` | 2.2 | 2.2 | 0.0 | OK |
| `systemd-rfkill.service` | 9.4 | 9.4 | 0.0 | OK |
| `systemd-timesyncd.service` | 2.1 | 2.1 | 0.0 | OK |
| `systemd-udevd.service` | 7.1 | 7.1 | 0.0 | OK |
| `unattended-upgrades.service` | 9.6 | 9.6 | 0.0 | OK |
| `user@1000.service` | 9.4 | 9.4 | 0.0 | OK |
| `user@995.service` | 9.4 | - | - | NEW |
| `uuidd.service` | 5.8 | 5.8 | 0.0 | OK |

</details>

## Offline: the unit files the product ships

The units rendered from the Ansible templates and scored with `systemd-analyze security --offline=yes`, systemd 257. Status: OK.

| Unit | Score | Baseline | Delta | Status |
|---|---|---|---|---|
| `caddy.service` | 1.6 | 1.6 | 0.0 | OK |
| `fleet-boot.service` | 1.8 | 1.8 | 0.0 | OK |
| `fleet-reboot-notify.service` | 1.6 | 1.6 | 0.0 | OK |
| `fleet.service` | 1.8 | 1.8 | 0.0 | OK |

---

How the check works, the baseline and how to update it: [docs/README-ci.md](docs/README-ci.md).
