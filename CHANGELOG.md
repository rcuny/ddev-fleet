# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `FLEET_INSTANCE_HOST=<instance-id>.<domain>` injected into every
  instance's `web_environment`, so a project's own Drupal Domain Access
  config can build its alias-matching patterns without hardcoding the
  fleet domain: `"<h>-" . getenv('FLEET_INSTANCE_HOST')`.

### Changed
- `additional_hostnames` alias FQDNs are now **flattened**:
  `<h>-<instance-id>.<domain>` (e.g.
  `news-oak--translations-test.fleet.example.com`) instead of the previous
  nested `<h>.<instance-id>.<domain>` form, which Caddy's single-label
  `*.<domain>` site block could never match. Each entry is now validated
  at registry load as a bare DNS label (lowercase, no dots —
  `RegistryError` otherwise), and deploy raises `DeployError` if a
  composed alias label exceeds the 63-character DNS limit. See
  `docs/networking.md` §7.

### Fixed
- **Security:** per-instance Caddy basic auth now covers alias hosts too —
  the `@auth-<instance-id>` matcher lists the instance FQDN and every
  registered alias FQDN in the same `host` clause (`fleet refresh-auth`
  re-applies this to already-deployed instances). Previously an alias host
  bypassed basic auth entirely, since the matcher only ever named the bare
  instance FQDN.
- **Security:** `/api/tls-authorize` no longer authorizes any
  `<prefix>-<instance-id>` label — only the bare instance id, or a label
  matching one of that instance's project's registered
  `additional_hostnames`. The previous generic
  `label.endswith(f"-{instance_id}")` check let anyone mint an on-demand
  TLS certificate for an arbitrary, unregistered alias pointed at a real
  instance.

### Added
- Template-level `drupal_env` in `fleet.yml`: fleet writes `DRUPAL_ENV=<value>`
  into the deployed instance's own root `.env` (after asset injection, in
  place — comments, key order and every other value preserved), overriding the
  default the project's `assets/<project>/.env` ships. A template without
  `drupal_env` leaves the file untouched. Lets one project run a `staging`
  template beside its `dev` one.
- `fleet.auth_bypass_cidrs` in `fleet.yml` (per fleet server): IP addresses /
  CIDR ranges whose visitors skip per-instance HTTP basic auth, rendered into
  each instance's Caddy snippet as `not remote_ip …`. Anything unlisted still
  gets the prompt — the list never denies. For networks where corporate policy
  blocks basic auth outright.
- `fleet refresh-auth`: re-applies that whitelist (plus each instance's
  recorded auth settings) to every deployed instance — rewrite all snippets,
  then one `caddy validate` + `caddy reload`. No redeploy, no Ansible run.

### Fixed
- Fleet-owned Caddy snippet directories under `/etc/caddy` are no longer
  created on the fly: `caddyauth.ensure_snippet_dir()` now refuses to write
  when the directory is missing OR has lost its **setgid** bit, naming the
  exact fix. A `mkdir` there drops setgid, so every snippet written after it
  is group-owned by `fleet` instead of `caddy` and Caddy cannot read it —
  which took ddev2's Caddy down from 2026-09-14 to 2026-09-21, invisibly,
  because a reload keeps serving the old config until the next restart. The
  unprivileged `fleet` user cannot repair setgid itself (Linux drops S_ISGID
  for a non-member group), so provisioning owns these directories.

### Changed
- Per-instance basic auth is now symmetric: the `--auth-password` / web-UI
  "Auth user & password" value is used as BOTH username and password
  (previously the username was always `fleet`). Because it doubles as a
  Caddyfile username token, it must be a single word (no whitespace, quotes,
  braces, backslashes, or leading `#`) — rejected up front otherwise.
  Existing instances keep their old `fleet`/<password> credentials until
  redeployed.

### Added
- `fleet redeploy <instance-id>... [--all|--project=P|--state=S] [--template T] [--auth-password P] [--force] [--yes]`,
  plus a per-row Redeploy button and a bulk "Redeploy selected" action in the
  web UI. Destroys an instance and rebuilds it under the same id, recovering
  project/template/branch/label/auth from `.fleet/instance.yml`. Refuses if
  no `template` was recorded (an instance deployed before template recording
  existed) unless `--template` is given. Confirmation and bulk targeting
  mirror `destroy` exactly; bulk redeploy runs sequentially.
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
- `fleet deploy` never overwrites an existing instance. If the resolved
  instance id is already taken — whether the label came from `--branch` or
  an explicit `--label` — `-1`, `-2`, … is appended until a free one is
  found; the deploy log records the substitution. This applies to a single
  deploy as well as `deploy --count` (which already always suffixed).
- `.fleet/instance.yml` now also records `auth-enabled`/`auth-password` (so
  a redeploy can reproduce an instance's basic-auth settings), and is
  written mode `0600` on every write instead of the default umask, since it
  now holds a plaintext credential.
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
- `fleet deploy --fresh` and the web UI's "Fresh" checkbox. `fleet redeploy`
  (see Added) replaces both — one operation, one name.
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
