# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Per-project `issue_id_regexp` and the `[[issue-id]]` token: derived from
  the deploying instance's label (checked first) or branch (fallback),
  matched case-insensitively with the result uppercased; also exported as
  `FLEET_ISSUE_ID`.
- `templates.<name>.tty1`/`.tty2`: lists of commands typed (via `tmux
  send-keys`) into the middle/right bash panes of an instance's `fleet
  tmux` window the first time that window is created — for interactive
  workflows (e.g. `ddev exec claude "/jira pull [[issue-id]] ..."`) the
  operator can attach to and keep talking to. An unresolved `[[token]]` in
  a `tty1`/`tty2` command is skipped with a warning rather than failing the
  deploy.
- MIT `LICENSE`.
- `.github/` issue templates and a CI workflow (pytest, ruff, black).
- `tests/test_no_personal_leakage.py`, a denylist test guarding against
  deployment-specific data leaking into the public repo.
- Public installer flow: OS detection, env-var/tty/generated value
  collection, `/etc/ddev-fleet/local-vars.yml` persistence,
  `FLEET_REPO_VERSION` pinning.
- `fleet init`'s local-file registry mode (`fleet.yml.dist` →
  `$FLEET_HOME/config/fleet.yml`) and the `FLEET_CONFIG_REPO` advanced
  config-repo mode.
- `docs/installation.md`, `docs/operations.md`, `docs/architecture.md`,
  `CONTRIBUTING.md`.

### Changed
- `post_deploy` commands are now actually `[[token]]`-substituted before
  running (previously only documented, not implemented); an unresolved
  token now aborts the deploy with a named `DeployError` instead of running
  the literal, unsubstituted command.
- De-personalised all deployment-specific references (hostnames, hosting
  provider, private-forge URLs) throughout the codebase and documentation.
- The old server-rollout runbook split into `docs/installation.md`
  (first-run) and `docs/operations.md` (ongoing).
- The dashboard admin password is no longer a fixed default — the
  installer always generates or prompts for one.
- `fleet deploy --label` / the web UI's Label field is now normalised
  (slugified) instead of rejected: lowercased, non-alphanumeric runs
  collapsed to a single `-`, leading/trailing `-` stripped (e.g.
  `--label=ABC-1234` → `abc-1234`) — matching the existing behaviour for a
  branch-derived label. The deploy log records when an explicit label was
  changed by this normalisation.

### Removed
- `ansible/group_vars/all.yml`'s `fleet_admin_default_password: ddev-admin`
  fixed default.
- The old server-rollout runbook (content split, see Changed).

## [0.3.0] - 2026-07-15

Typesense edge exposure: port-based browser access to a project's
Typesense (`*.<domain>:9108` via Caddy), replacing an earlier,
incompatible path-based (`/_typesense`) design. Per-project admin +
search-only key generation.

## [0.2.0] - 2026-07-14

Fleet-core model redesign: projects→templates registry, `deploy` =
project + template + branch, instance naming as `<project>--<label>`,
public-engine/private-config repo split, hybrid config-override
ownership.

## [0.1.0] - 2026-07-02

Initial deploy engine: registry (`fleet.yml`), CLI (`deploy`/`destroy`/
`start`/`stop`/`list`), Ansible provisioning (Docker, DDEV, Caddy,
`fleet.service`), asset/secret management, web UI (FastAPI + HTMX).

[Unreleased]: https://github.com/rcuny/ddev-fleet/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/rcuny/ddev-fleet/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/rcuny/ddev-fleet/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/rcuny/ddev-fleet/releases/tag/v0.1.0
