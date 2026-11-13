# systemd security report

`systemd-analyze security` scores how much of the system a service's sandbox leaves exposed to it, on a scale from 0 (best: locked down) to 10 (worst: no sandbox at all). The offline check scores the unit files the installer ships (`install/etc`, `install/ansible/roles/hardening/files`, and the Caddy sandbox drop-in); no host is involved.

Every score is compared with the committed baseline `ci/systemd-security-baseline.json`. A unit counts as a regression only when its score rises by more than the tolerance (+0.1) over that baseline. Regenerate this page with `python ci/systemd_security.py report` (see [docs/contributing/03-ci.md](docs/contributing/03-ci.md)).

**Generated:** 2026-11-13, systemd 257. Status: OK.

| Unit | Score | Baseline | Delta | Status |
|---|---|---|---|---|
| `caddy.service` | 1.6 | 1.6 | 0.0 | OK |
| `firewall-deadman-bootcheck.service` | 9.4 | 9.4 | 0.0 | OK |
| `firewall-deadman.service` | 9.4 | 9.4 | 0.0 | OK |
| `firewall-sync.service` | 9.4 | 9.4 | 0.0 | OK |
| `fleet-backup.service` | 1.4 | 1.4 | 0.0 | OK |
| `fleet-boot.service` | 9.0 | 9.0 | 0.0 | OK |
| `fleet-isolation-watch.service` | 8.9 | 8.9 | 0.0 | OK |
| `fleet-isolation.service` | 3.5 | 3.5 | 0.0 | OK |
| `fleet-mercure.service` | 8.2 | 8.2 | 0.0 | OK |
| `fleet-scheduler.service` | 8.3 | 8.3 | 0.0 | OK |
| `fleet-status.service` | 8.9 | 8.9 | 0.0 | OK |
| `fleet-tls-check.service` | 1.7 | 1.7 | 0.0 | OK |
| `fleet-tmux.service` | 9.0 | 9.0 | 0.0 | OK |
| `fleet-upgrade-check.service` | 1.7 | 1.7 | 0.0 | OK |
| `fleet-worker@.service` | 8.9 | 8.9 | 0.0 | OK |
