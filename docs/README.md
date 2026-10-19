---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Documentation index

`develop` is the **1.x** line (Symfony 8), rewritten milestone by milestone from the **0.x**
(Python) product on `main`. Every document below carries a status:

- **Current** - describes what `develop` does today.
- **0.x, valid** - describes tooling or process that applies unchanged to both lines.
- **0.x reference** - describes the 0.x product. It is kept as history and as the specification the
  port re-implements; each one is rewritten in the milestone named in its banner.

## Current

| Document | Status | Covers |
|---|---|---|
| [`installation.md`](installation.md) | Current | Installing 1.x on a Debian 13 host: prerequisites, `install.sh` and its flags, the setup wizard, what is installed where, recovery (`fleet setup:reset`, `fleet user:reset-password`), upgrades, the security model, uninstalling, troubleshooting |
| [`projects-and-templates.md`](projects-and-templates.md) | Current | Projects and templates in the browser and with `fleet project\|template\|port`: concepts, every field and limit, the screens, CLI reference (exit codes, `--json`), permissions, deleting, named ports, differences from 0.x |
| [`assets.md`](assets.md) | Current | A project's assets: the Assets tab (browser, upload, download, delete, folders, rescan), chunked resumable upload and its 2 GiB cap, copying large files over SSH, path and symbolic-link rules, `fleet asset:list` and `fleet asset:gc` (exit codes, `--json`), limits |
| [`development.md`](development.md) | Current | Prerequisites, first run, environment variables, recording runner, stand-in login, gates, Playwright modes, adding a UI component |
| [`README-ci.md`](README-ci.md) | Current | Bitbucket Pipelines, the `v1.*` tag guard, the systemd security check and baseline |
| [`RELEASING.md`](RELEASING.md) | 0.x flow valid; 1.x section added | Versioning, CHANGELOG conventions, release and hotfix procedure. The section "1.x during the port" covers `v1.*` tags on `develop`; the document is rewritten at v1.0.0 (milestone M0.04) |
| [`../tests/README-tests.md`](../tests/README-tests.md) | Current | Test suites, counts and how to run them |
| [`../CLAUDE.md`](../CLAUDE.md) | Current | Agent-facing guide: branches, namespaces, seams, commands |
| [`../CONTRIBUTING.md`](../CONTRIBUTING.md) | Current | Contribution workflow, CI, mirror, dependency updates |

## 0.x, valid (shared tooling)

| Document | Status | Covers |
|---|---|---|
| [`README-renovate.md`](README-renovate.md) | 0.x, valid | Hands-off merge of approved Renovate PRs (the pipeline is unchanged by the port; the automatic webhook trigger returns in M0.08) |

## 0.x reference (`v0/`)

Each file starts with a banner naming the milestone that re-implements it. The files, commands and
configuration they describe (`src/fleet/`, the `fleet` CLI, `fleet.yml`, `bootstrap.sh`) exist on
`main`, not on `develop`.

| Document | Covers | Rewritten in |
|---|---|---|
| [`v0/installation.md`](v0/installation.md) | First-run checklist: DNS, installer, deploy key, `fleet init`, first deploy | M0.02 (done: [`installation.md`](installation.md); the first deploy returns in M0.04) |
| [`v0/README-authelia.md`](v0/README-authelia.md) | Authelia auth mode: login portal, users, rollout | M0.10 (the portal itself is installed by M0.02, see [`installation.md`](installation.md)) |
| [`v0/configuration.md`](v0/configuration.md) | The `fleet.yml` registry schema | M0.03 (done for projects and templates: [`projects-and-templates.md`](projects-and-templates.md); the rest returns with M0.05 to M0.10) |
| [`v0/cli.md`](v0/cli.md) | The `fleet` command reference | M0.04 |
| [`v0/networking.md`](v0/networking.md) | Topology, every port, exposing a new one | M0.07 |
| [`v0/README-typesense.md`](v0/README-typesense.md) | Typesense browser-search exposure | M0.07 |
| [`v0/README-webhooks.md`](v0/README-webhooks.md) | Jira and Bitbucket webhooks | M0.08 |
| [`v0/README-schedules.md`](v0/README-schedules.md) | Scheduled jobs | M0.09 |
| [`v0/srv-fleet-CLAUDE.md`](v0/srv-fleet-CLAUDE.md) | Registry and CLI context for the host's Claude session | M0.11 |
| [`v0/architecture.md`](v0/architecture.md) | Module map and the daemon/CLI split | M0.12 |
| [`v0/operations.md`](v0/operations.md) | Updates, rollback, credential rotation, verification checklist | M0.12 |
| [`v0/runbook-server-rollout.md`](v0/runbook-server-rollout.md) | Routine rollout and domain-change procedure | M0.12 |
| [`v0/README-reboot-notifications.md`](v0/README-reboot-notifications.md) | Reboot-required notices | M0.12 |

The milestone numbers refer to the port plan (M0.01 foundation, M0.02 installer and setup wizard,
M0.03 projects and templates, M0.04 instances and jobs, M0.05 secrets, M0.06 assets, M0.07 networking,
M0.08 webhooks, M0.09 schedules, M0.10 users and access, M0.11 agent workspace, M0.12 host
operations and parity).
