---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# ddev-fleet

[![Licence: MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)
[![systemd security: weekly report](https://img.shields.io/badge/systemd%20security-weekly%20report-blue)](https://github.com/rcuny/ddev-fleet/blob/develop/SYSTEMD-SECURITY-REPORT.md)

A fleet manager for running many independent DDEV-based projects (mainly Drupal, not
exclusively) in parallel on one bare-metal host, with minimal human interaction: it
clones, configures, starts and tears down instances, keeps per-project secrets and assets
in one place, and puts every instance under one wildcard domain with TLS and a login.

## Status: two lines

| Line | Branch | State |
|---|---|---|
| **0.x** (Python: FastAPI daemon, `fleet` CLI, `fleet.yml`) | `main` (tags `v0.*`) | Production line. Install and operate it from `main`; its documentation is kept in [`docs/v0/`](docs/v0/). |
| **1.x** (Symfony 8, PHP 8.4, SQLite) | `develop` (tags `v1.*` from v1.0.0) | A rewrite in progress, developed milestone by milestone. This branch ships the foundation (app skeleton, command seam, the design system and app shell, tests and CI) and the **installer and setup wizard**: one command turns a fresh Debian 13 host into a fleet with HTTPS, a sign-in portal and a setup wizard. It cannot manage instances yet and is **not ready for production use**; install it on a test host. |

What each milestone brings back from 0.x (projects, instances and
jobs, secrets, assets, networking, webhooks, schedules, users and access) is listed in the
[documentation index](docs/README.md).

## Architecture (1.x)

The fleet manager runs **natively on the host**, never inside DDEV or Docker, so it can keep
managing, and recovering, other DDEV projects when one of them or Docker is unhealthy.

```
Bare-metal / VPS host
├── Caddy (systemd) — public TLS, the only Internet-facing process
│   ├── fleet.example.com      → login (Authelia) → PHP-FPM, the Symfony app
│   └── *.fleet.example.com    → login per instance → ddev-router (loopback) → instance
├── Authelia (systemd) — the login portal; asserts Remote-User / Remote-Groups
├── PHP-FPM — the Symfony app: web UI (Twig, Turbo, Stimulus) and /health
├── Messenger workers (systemd) — run ddev / git / docker for queued jobs
├── Mercure hub — pushes live job output to the browser
├── SQLite under FLEET_HOME — fleet.sqlite (domain data), queue.sqlite (job queue)
└── Docker (DDEV)
    ├── ddev-router (shared Traefik, loopback only)
    └── demo--main, blog--feature-x, … one DDEV project per instance
```

The web request never runs `ddev`, `git` or `docker`: it stores a job, a worker picks it up
through the `CommandRunner` seam and the browser follows the log live (Mercure). Of this
picture, the app, the seams, header authentication, the SQLite wiring and the shell exist
today, and so do Caddy, Authelia, the Mercure hub and the worker unit (installed by `install/`); the jobs that
use them arrive in M0.04.

## Install on a server (1.x)

On a fresh **Debian 13** host, as root, with DNS records for the domain and for `auth.<domain>` already
pointing at it and ports 80 and 443 open:

```bash
curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/develop/install/install.sh \
  | sudo bash -s -- --domain=fleet.example.com --acme-email=you@example.com
```

It installs PHP 8.4, Docker, DDEV, Caddy, Authelia and a Mercure hub, creates the `fleet` user and `/srv/fleet`,
starts the services and prints a setup URL (a credential for 24 hours) for the browser wizard (administrator, certificates, SSH
keys, optional Claude token). Re-running the same command upgrades. Flags (`--ref`, `--repo-url`,
`--skip-dns-check`, `--dry-run`), recovery (`fleet setup:reset`, `fleet user:reset-password`), the security
model and uninstalling: [`docs/installation.md`](docs/installation.md).

## Quickstart for contributors

You need PHP 8.4 with the usual extensions (including `pdo_sqlite` and `sodium`), Composer 2,
and Node 22 for the browser tests. No database server and no Node build for the app.

```bash
cd app
composer install
php bin/console importmap:install
php bin/console tailwind:build
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php -S 127.0.0.1:8000 -t public          # open http://127.0.0.1:8000
```

In `dev` there is no proxy, so a stand-in user signs you in (admin by default; switch role
from the app shell or at `/_dev/stand-in/member`), and host commands are recorded rather than
executed. The merge gate is `composer run gates` in `app/`, plus the Playwright suite in
`tests/playwright/`. Details, environment variables and troubleshooting:
[`docs/development.md`](docs/development.md); how to contribute: [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Repository layout

- `app/` — the Symfony project: `src/` (namespaces in [`CLAUDE.md`](CLAUDE.md)), `templates/`,
  `assets/`, `config/`, `migrations/`, `tests/` (PHPUnit: `Unit`, `Integration`, `Functional`, `Repo`)
- `tests/playwright/` — browser end-to-end tests (TypeScript), at 1280 px and 390 px;
  `tests/pytest/` — Python tests for the CI tooling and the Ansible roles; see
  [`tests/README-tests.md`](tests/README-tests.md)
- `ci/`, `bitbucket-pipelines.yml`, `renovate-config.json` — CI, the release-tag guard, dependency updates
- `install/` — the 1.x installer (`install.sh`, `run.sh`, `steps/`, the root helper `libexec/apply`, `uninstall.sh`)
- `ansible/` — provisioning for the 0.x host layout, kept as reference and not used by the 1.x installer
  ([`ansible/LEGACY-0x.md`](ansible/LEGACY-0x.md))
- `docs/` — documentation, indexed in [`docs/README.md`](docs/README.md); `docs/v0/` is the 0.x reference
- `demo/` — a minimal DDEV project the 0.x installer seeds as the `demo` project

## Documentation

| Doc | Covers |
|---|---|
| [`docs/README.md`](docs/README.md) | Index of every document with its status |
| [`docs/installation.md`](docs/installation.md) | Installing 1.x: prerequisites, the installer and its flags, the setup wizard, what is installed where, recovery, upgrades, security model, uninstalling |
| [`docs/development.md`](docs/development.md) | Prerequisites, first run, environment variables, recording runner, stand-in, gates, adding a component |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Workflow, code style, pull requests, CI and mirror |
| [`docs/RELEASING.md`](docs/RELEASING.md) | Versioning, CHANGELOG conventions, release and hotfix procedure (0.x flow and 1.x during the port) |
| [`docs/README-ci.md`](docs/README-ci.md) | Bitbucket Pipelines, the tag guard, the systemd security check |
| [`docs/v0/`](docs/v0/) | The 0.x (Python) installation, operations, CLI, configuration and feature documentation |
| [`SYSTEMD-SECURITY-REPORT.md`](https://github.com/rcuny/ddev-fleet/blob/develop/SYSTEMD-SECURITY-REPORT.md) | The weekly systemd security report |
| [`CHANGELOG.md`](CHANGELOG.md) | Release history |

Licence: [MIT](LICENSE).
