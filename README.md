---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-18
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
| **1.x** (Symfony 8, PHP 8.4, SQLite) | `develop` (tags `v1.*` from v1.0.0) | A rewrite in progress, developed milestone by milestone. This branch currently ships the foundation: app skeleton, command seam, authentication by proxy header, the design system and app shell, and the test and CI setup. It cannot manage instances yet and is **not ready to deploy**. |

What each milestone brings back from 0.x (installer and setup wizard, projects, instances and
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
today; Caddy, Authelia, the Mercure hub, the workers and the jobs arrive in M0.02 to M0.04.

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
- `ansible/` — provisioning for the 0.x host layout, reshaped for 1.x in M0.02
- `docs/` — documentation, indexed in [`docs/README.md`](docs/README.md); `docs/v0/` is the 0.x reference
- `demo/` — a minimal DDEV project the 0.x installer seeds as the `demo` project

## Documentation

| Doc | Covers |
|---|---|
| [`docs/README.md`](docs/README.md) | Index of every document with its status |
| [`docs/development.md`](docs/development.md) | Prerequisites, first run, environment variables, recording runner, stand-in, gates, adding a component |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Workflow, code style, pull requests, CI and mirror |
| [`docs/RELEASING.md`](docs/RELEASING.md) | Versioning, CHANGELOG conventions, release and hotfix procedure (0.x flow and 1.x during the port) |
| [`docs/README-ci.md`](docs/README-ci.md) | Bitbucket Pipelines, the tag guard, the systemd security check |
| [`docs/v0/`](docs/v0/) | The 0.x (Python) installation, operations, CLI, configuration and feature documentation |
| [`SYSTEMD-SECURITY-REPORT.md`](https://github.com/rcuny/ddev-fleet/blob/develop/SYSTEMD-SECURITY-REPORT.md) | The weekly systemd security report |
| [`CHANGELOG.md`](CHANGELOG.md) | Release history |

Licence: [MIT](LICENSE).
