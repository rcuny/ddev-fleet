# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **FLE-63: follow-ups of FLE-59 and FLE-62.** **Upgrade note:** the host git identity is validated as strictly as a project override (FLE-59): `bot@localhost` (no dot in the domain) and names with control or invisible formatting characters are refused, so an identity saved earlier can fail on its next save. The Git identity tab now warns when the stored value no longer passes; nothing is rewritten silently. "Back up now" counts the `-wal` and `-shm` files in the 256 MiB bound; `ComponentsAccessTest` requires the admin-only components to declare `ROLE_ADMIN`; the 1500-id chunk test asserts a match in several chunks whatever the SQLite variable limit; the Playwright "serves nothing" check asserts an empty or forbidden body.
- **FLE-61: follow-ups of the assets upload (FLE-52).** The upload cap is editable on a new **Uploads** tab of the host settings page (MiB, 1 to 1 048 576; the server still enforces it per upload). A folder holding only stale hidden upload temporaries (older than an hour, left by a killed assembly) can be deleted. A name clash with a folder no longer offers "Replace it?" (the 409 carries `reason: file-exists` only for a replaceable file). The free-space check looks at the filesystem of `uploads/` and of the target assets directory. `assets.spec.ts` gains a Rescan spec (desktop and phone). Kept as documented gaps: no `Range` downloads, no empty-file upload.

### Added
- **FLE-44: users and project access, backend** (milestone M0.10, in progress). A `ProjectAccess` entity (user and project, unique pair, rows removed with the user or the project) and a real project membership: a member passes `ProjectVoter::OPERATE` for their projects only, `SETTINGS` stays administrator-only. **Authelia hash read-back:** before every `users.yml` render Fleet adopts a password hash that Authelia changed (self-service reset), so a later user change does not undo it. Authelia's **self-service password reset is enabled only when an SMTP server is configured** (`FLEET_AUTHELIA_SMTP_ADDRESS`, `FLEET_AUTHELIA_SMTP_SENDER`, optional `FLEET_AUTHELIA_SMTP_USERNAME`); otherwise it stays off. The Authelia configuration now carries one access rule per project (`<project>--*.<domain>`: administrators and the project's members), installed through `apply authelia` only when it changed. See `docs/installation.md`.
- **FLE-44: the Users pages** (milestone M0.10). `/admin/users` (administrators only, usable at 390 px) lists the people who can sign in and lets an administrator create a user (username, display name, email, initial password checked by the password policy, administrator yes or no, projects), edit one (profile, role, project memberships as tap-sized checkboxes), set a new password, disable or enable the account and delete it. Every change goes through `IdentitySync::commit()`: the database change is saved first (a user that cannot be rendered refuses it), then `users.yml` is written and the Authelia access rules follow. Nobody can delete, disable or demote themselves, and the last enabled administrator cannot be deleted, disabled or demoted. New: `UserController`, `App\Form\User`, `InvalidUserInput` (a refused field names its form field); the form theme draws a multiple choice as checkbox rows. Playwright: `users.spec.ts` (an administrator adds a user to a project at 390 px, with axe in both themes).
- **FLE-44: `fleet user:*` commands.** `fleet user:list|create|update|delete|grant|revoke` manage users and project memberships from the host through the same services as the pages (`--format=table|json`, exit codes `0` done, `1` refused value, `2` not allowed now: the last enabled administrator or an unconfirmed delete, `3` not found). A password is never an argument: `create` asks for it on the terminal without echo, or reads one line from `--password-stdin`. Creating or deleting a project now refreshes the Authelia access rules; when installing them fails the change stands and a warning is shown.
- **FLE-44: user changes no longer fail with "database is locked".** `UserRepository::transactional()` takes the SQLite write lock when the transaction starts, so two administrators (or parallel test workers) saving users at the same time wait for each other instead of one getting a 500. `IdentitySync::commit()` now only checks that the users render inside the transaction and writes `users.yml` after the commit; when that write or the access install fails the change stays saved and a warning is shown.
- **FLE-51 (part 2): the host settings page and the reboot notice.** `/admin/host` (administrators; the former Settings placeholder) with tabs for General (domain and TLS mode read-only with the documented commands, ACME contact editable and validated with `ConfigValue`), Git identity (`GitIdentityRule`), Claude token (the wizard's `claude_oauth_token` secret; write-only, "Set / Not set" and the update time only), Notifications (SMTP host, port, user, sender, recipient; the SMTP password is a write-only `smtp_password` secret that can be removed; no test mail) and Backups (list, streamed attachment download with `Cache-Control: no-store`, "Back up now", retention 1 to 3650 days). Every change is a POST with the stateless CSRF token. Administrators also get a banner on every shell page while `/run/reboot-required` exists (`RebootSignal`, packages from `reboot-required.pkgs`, capped at 50); the fleet never reboots by itself. `SecretStore::fleetWideUpdatedAt()` reports when a secret was written without opening it. The page works at 390 px (`host-settings.spec.ts`).
- **FLE-51 (part 1): database backups.** `fleet:backup:create` writes consistent snapshots of `fleet.sqlite` and `queue.sqlite` (SQLite `VACUUM INTO`, integrity-checked, atomic, files 0600) to `FLEET_HOME/backups/` and prunes backups older than the retention (`HostSettings::backupRetentionDays`, default 14; the newest of each kind is always kept). The installer adds `fleet-backup.service` and a daily `fleet-backup.timer`; `docs/installation.md` has the restore procedure. `HostSettings` gains the retention, git bot identity and notification e-mail fields for the host page (migration `Version20261019100000`); `FleetPaths::backupsDir()`.
- **FLE-52: assets in the browser** (milestone M0.06a). A project's **Assets** tab (`/admin/projects/<project>/assets`, administrators only) browses the project's assets directory (`/srv/fleet/assets/<project>/`): nested folders, name, size and modified time (readable at 390 px), **Download** (streamed as an attachment), **Delete** (confirmation dialog, POST with CSRF; a folder only when empty), **Create folder** and **Rescan**. The list is read from the disk on every visit, so files copied in with `scp` or `rsync` show up after a rescan (documented in `docs/assets.md` and `docs/installation.md`).
- **Chunked, resumable browser upload** (a small Stimulus controller, no Node build): files are cut into chunks of up to 8 MiB, so php-fpm and Caddy body limits do not matter; chunks are staged in `/srv/fleet/uploads/<id>/` (mode 0700), assembled atomically and the file's sha256 is shown. Choosing the same file again resumes. The default cap is **2 GiB per file** (new `host_settings.asset_upload_max_bytes`, migration included; no screen to change it yet); a bigger file gets a message naming the limit and the directory to copy it into. Abandoned uploads (24 hours) are removed by the next upload and by `fleet asset:gc`.
- **Path safety:** every path is checked by `AssetPath` (no `..`, absolute paths, NUL, control or direction-changing characters; an allow-listed character set; length and depth limits) and walked by `AssetStore` with `lstat`: a symbolic link is never followed and nothing is written through one.
- **`fleet asset:list <project> [path]` and `fleet asset:gc`** on the host: `--format=table|json` (`--json`), exit codes `0` done, `1` refused value, `2` a symbolic link in the way, `3` not found. The `fleet` wrapper maps the group `asset`.
- New code: `App\Fleet\Assets`, `App\Controller\Admin\AssetController`, `App\Command\Asset`, the `bytes` Twig filter, `FleetPaths::uploadsDir()`. Tests: path-confinement unit tests (traversal, symlinks, NUL, unicode), upload assembly tests (out-of-order and duplicate chunks, size mismatch, cap, resume, concurrent uploads, abandoned uploads, a folder swapped for a symlink), functional tests for every route (admin 200, member 403, anonymous 401, CSRF), command tests, `AssetDocsTest`, and `assets.spec.ts` in Playwright at both widths.
- **FLE-39: secrets** (milestone M0.05). Credentials and tokens are entered once in the browser and stored encrypted: **Secrets** (`/admin/secrets`) manages the fleet-wide secrets and lists the host's own (set by the setup wizard) read-only, and a project's new **Secrets** tab manages that project's secrets. A secret is write-only: lists show names, the placeholder (`JIRA_CLAUDE_TOKEN` is `[[jira-claude-token]]`) and who set it when, the form is never pre-filled, and an error never repeats the value. An administrator can **reveal** a value (POST with CSRF, shown once in a dialog, `Cache-Control: no-store`, recorded before it is handed out); every put, delete and reveal is an entry in the new **audit log** (`/admin/audit`, latest 200, filter by action; values are never recorded). The project's tab flags a secret that no asset file mentions (the 0.x trap of a silently inert secret) and says "No asset files yet" until some exist. Deleting a project deletes its secrets. Names are `[A-Z][A-Z0-9_]{0,63}` (not the built-in `[[project]]`, `[[branch]]`, ... placeholders), values are text of 1 byte to 64 KiB, a value wrapped in quotes is refused (it would double up inside a quoted placeholder) and CRLF and trailing newlines are normalised. See `docs/secrets.md`.
- **`fleet secret:list`, `secret:set` and `secret:unset`** on the host (`--project=<name>` for a project's secrets, `--format=table|json`, `--json`; `unset` needs `--yes` unless a person confirms): the value is read from a hidden prompt or standard input, never from an argument; exit codes as the project commands (`0` done, `1` refused value, `2` not allowed now, `3` not found). The wrapper maps the group `secret`.
- **`[[token]]` substitution** (`App\Fleet\Secrets\TokenRenderer`, used by the deploy from M0.04): a project secret wins over a fleet-wide one, built-in values win over both, values are inserted verbatim, an inserted value is never expanded again, and unresolved placeholders are reported or refused by name. Host secrets are never substituted.
- New domain code: entity `AuditEntry` with a migration (project secrets reuse the `secret` table), `App\Fleet\Audit\AuditLog`, the managed-secret methods of `SecretStore`, `App\Fleet\Secrets\SecretService` (the one path for pages and commands), `SecretName`, `SecretValue`, `PlaceholderScanner`, `App\Command\Secret`, `App\Security\CurrentActor` and the secrets and audit controllers and templates.
- Tests: unit tests for names, values and the renderer, integration tests for the store, service, scanner, audit log and commands, functional tests for every route (member 403, anonymous 401, CSRF, `no-store`, no value in any page except the reveal response, no value in any log record), `app/tests/Repo/SecretsDocsTest.php` (the guide documents every command and option) and `secrets.spec.ts` in Playwright at both widths.

- **FLE-37: projects and templates** (milestone M0.03). An administrator describes what the fleet can deploy in the browser, without YAML or a config repository: **Projects** (`/admin/projects`) lists, creates, edits and deletes projects (git URL, default branch and template, extra hostnames, git identity, issue id pattern, submodule branch, Typesense) and, on the project's Templates tab, their templates (Drupal environment, post-deploy, terminal 1 and terminal 2 commands); the Ports tab shows the named-port catalogue read-only. A live tester on the General tab shows the issue id a pattern extracts from a sample label or branch. Everything is administrator-only (a `ProjectVoter` backs the `/admin` rule) and works at 390 px. See `docs/projects-and-templates.md`.
- **`fleet project:*`, `fleet template:*` and `fleet port:*`** on the host (`list`, `show`, `create`, `update`, `delete`; `port:list|create|delete`; `fleet project list` is accepted as well): `--format=table|json` (`--json`), exit codes `0` done, `1` refused value, `2` not allowed now (instances exist, default template, port in use, unconfirmed delete), `3` not found, and a JSON error `{"error": ..., "fields": {...}}` keyed by the option spelling. A hand-edited, unquoted value with shell syntax in `/etc/ddev-fleet/fleet.env` is executed by the wrapper as `fleet`: keep plain `KEY=value` lines.
- **Validation in one place** (`App\Fleet\Projects\Rule`, used by the pages and the commands alike): names of 1 to 40 characters, an allow-list of three git URL shapes (no credentials, `ext::`, `file://` or `http://`), single-line command lists (50 lines of 2000 characters), `drupal_env` of 1 to 64 safe characters, at most 10 hostnames, 50 templates and 50 ports per project, a hostname text box 10 000 characters and a command list text 100 100 (the same bound in the browser and on the command line), and an issue id pattern that cannot hang the server (static scan, trial run, backtrack limit). Names are immutable.
- New domain code: entities `Project`, `Template` and `NamedPort` with a migration (the installer re-run applies it), `App\Fleet\Projects` (services, inputs, `ProjectSnapshot`, `ValidationFailed`), `ProjectInstanceGuard` (instances arrive in M0.04, deletion is refused while a project has any), `App\Form\Project`, `ProjectVoter`, the `IssueIdPreview` live component and a `Textarea` design-system component.
- Tests: unit tests for every rule (hostile git URLs, issue id patterns and command lines), integration tests for the services, repositories and commands, functional tests for the pages, the voter and the live component, `app/tests/Repo/CliDocsTest.php` (the guide documents every command and option and names nothing else), and `projects.spec.ts` in Playwright at both widths.

- **FLE-36: installer and setup wizard.** `install/install.sh --domain=<fqdn>` turns a fresh Debian 13 host (amd64 or arm64) into a working 1.x fleet with real HTTPS from the first minute. It is a pure bash, two-stage installer (`curl ... | sudo bash -s -- --domain=fleet.example.com`; flags `--acme-email`, `--ref`, `--repo-url`, `--skip-dns-check`, `--dry-run`, `--help`; no prompts) that installs PHP 8.4 from Debian, Docker CE, DDEV, a custom Caddy with the OVH DNS module, Authelia and a standalone Mercure hub, creates the `fleet` user, `/srv/fleet` and `/etc/ddev-fleet/fleet.env`, and starts Caddy, Authelia, a dedicated php-fpm pool (`/run/php/fleet.sock`), `fleet-worker@1` and `fleet-mercure`. Every step is idempotent and re-running the same command is the upgrade (secrets, users, keys and the wizard's choices are kept; Caddy and Authelia are restarted, so signed-in users sign in again). It checks DNS for `<domain>` and `auth.<domain>` (A and AAAA), never touches `sshd`, the firewall, fail2ban or unattended-upgrades, and keeps the setup URL (a credential for 24 hours) out of its log. `install/uninstall.sh --yes [--keep-data] [--purge-packages] [--dry-run]` removes it again (never run automatically). See `docs/installation.md`.
- **The setup wizard** at `/setup`, opened by the setup URL the installer prints (a credential: a SHA-256-stored token, valid 24 hours or until Finish, exchanged for a `__Host-` Strict cookie; wrong attempts are rate limited): administrator (argon2id), certificates (`http01`, or `ovh_dns` with OVH credentials stored encrypted), the deploy and push SSH keys (generated in PHP, or imported) and a fingerprint-confirmed `known_hosts` (GitHub, GitLab and Bitbucket pre-trusted), an optional Claude token, and Finish, which writes the Authelia users file, restarts Authelia, switches the Caddy gate from "setup only" to the Authelia sign-in and marks setup complete last. A failed Finish leaves setup open; afterwards every wizard URL answers 404, whatever the spelling of the path. Signed out, every `/setup` URL after Finish leads to the sign-in page (the gate is in front of it). Until Finish only `/setup`, `/assets/*` and `/health` are reachable. The wizard works at 390 px.
- **Recovery commands**, run on the host as `fleet <group>:<verb>` (the `/usr/local/bin/fleet` wrapper loads `fleet.env` and switches to the `fleet` user): `fleet setup:token` (a new setup URL), `fleet setup:reset` (reopen the wizard and put the setup gate back), `fleet user:reset-password <username>` (hidden prompt, needs a terminal: over SSH use `ssh -t`; `--no-apply` skips the Authelia restart), plus `fleet:host:init` and `fleet:host:render` for the installer.
- A root helper, `/usr/local/libexec/fleet/apply <caddy|authelia>`, behind a single sudoers line: the app renders Caddy and Authelia configuration into `/srv/fleet/staging/`, the helper validates it with the real binary, installs it atomically and rolls back when the reload fails. It is a guard against half-applied configuration, not a security boundary (`fleet` is in the `docker` group).
- New domain code: entities `HostSettings`, `User`, `SshKey` and `Secret` with a migration; `App\Fleet\Secrets` (`SecretBox`, libsodium XChaCha20-Poly1305 with the scope and name as associated data, `SecretStore`, key in `keys/secret.key`); `App\Fleet\Ssh`; `App\Fleet\Auth`; `App\Fleet\System` (Caddyfile, Caddy environment, Authelia configuration and gate renderers, `PrivilegedApply`); `App\Setup`; and a host-health card on Home (memory, disk, reboot required).
- Tests: the wizard walk-through in Playwright at both widths (`setup-wizard.desktop.spec.ts`, runs when Playwright starts the app itself), a live-install spec for a real host (`live-install.desktop.spec.ts`, skipped unless `FLEET_LIVE_SETUP_URL` is set), functional wizard and guard tests (every spelling of a `/setup` path is refused after completion), installer, helper, unit-file, sudoers, uninstall and documentation tests (`app/tests/Repo/`), shellcheck over the scripts, and `openssh-client`, `shellcheck` and `sudo` in the CI gates image.
- **FLE-35: the Symfony 8.1 app skeleton in `app/`** (PHP 8.4, Doctrine ORM over SQLite, Twig, Symfony UX, AssetMapper, Tailwind v4 without a Node build) with the seams every later milestone uses: `App\Fleet\Shell\CommandRunner` (real `ProcessCommandRunner`, scripted `RecordingCommandRunner`, chosen by `FLEET_RUNNER`), `App\Fleet\FleetPaths` (every path under `FLEET_HOME`, ids validated), two SQLite databases (`fleet.sqlite` and the Messenger `queue.sqlite`, WAL, `busy_timeout`, foreign keys on every connection), Symfony Lock and Mercure wiring.
- Authentication by proxy header: `Remote-User`, `Remote-Groups`, `Remote-Email` and `Remote-Name` are trusted only from `FLEET_TRUSTED_PROXIES`; the group `FLEET_ADMIN_GROUP` gives `ROLE_ADMIN`, everyone else is a member. A dev/test-only stand-in login (role switch, cookie and header) replaces the proxy during development. Until the installer (FLE-36) the proxy trust boundary was the loopback peer only.
- Production safety for the Symfony app: a `prod` kernel refuses to boot with an empty `APP_SECRET` or `FLEET_RUNNER=recording`; the committed `.env` stand-in role is `none`; host commands run with an allow-listed environment (no `APP_SECRET`, `DATABASE_URL`, ...); a repo test keeps process execution inside `ProcessCommandRunner`; `composer run prod-check` (also a CI step) builds and probes the production path in a throw-away copy.
- A public `GET /health` endpoint (fleet database, queue database, writable `FLEET_HOME`).
- The design system and responsive app shell: design tokens with dark mode, the UX Toolkit shadcn kit, reusable Twig components, sidebar and phone bottom navigation, and a reference page at `/admin/design-system`.
- A Playwright suite (38 specs at 1280 px and 390 px: app shell, navigation, dark mode, accessibility with axe in both themes, design-system page) and the PHPUnit suites (`Unit`, `Integration`, `Functional`, `Repo`).
- **Known limits of this installer (FLE-36):**
  - `ovh_dns` is implemented but **not yet verified live**: only `http01` has run on a real host (see `docs/installation.md`, section "Domain and certificates", for what to check). It accepts the OVH endpoints `ovh-eu`, `ovh-ca` and `ovh-us` only (the budget brands' separate endpoints are not supported); it changes how the two certificates (`<domain>` and `auth.<domain>`) are obtained, a wildcard certificate arrives with instance networking. `http01` needs a DNS record for `auth.<domain>` as well as `<domain>`.
  - Only `<domain>` and `auth.<domain>` are served: subdomains (instances) are denied by default until instance networking (M0.04 and M0.07).
  - An installer re-run and a password reset restart Authelia and drop every session. Authelia's self-service password reset is disabled until M0.10 (use `fleet user:reset-password`).
  - The Mercure hub is the standalone release (1.1.2, sha256-pinned) rather than a module in Caddy, because the Caddy download service does not offer the Mercure module. The domain cannot be changed after setup yet.
  - Finish runs in one HTTP request (up to four minutes; php-fpm's `request_terminate_timeout` is 240 s); there is no Job entity before M0.04.

### Upgrade notes
- **Existing hosts: re-run the installer** (`curl ... | sudo bash -s -- --domain=<fqdn>`, the same command as the first install) **to get the database backup timer.** Steps 95 (writes `fleet-backup.service` and `fleet-backup.timer`) and 98 (enables the timer) are the ones that matter; a fresh install already has them. Until then no backup is made. The migration `Version20261019100000` (host settings: backup retention, git bot identity, notification e-mail) runs on deploy, in the same installer run.

### Changed
- **FLE-59 (follow-up of FLE-51).** "Back up now" on the host page refuses databases above 256 MiB (`BackupService::INTERACTIVE_MAX_BYTES`, `BackupTooLarge`) with a message that names `fleet fleet:backup:create` and the daily timer, because it runs inside the php-fpm request and a large copy could hit `max_execution_time` (the smaller option; a Messenger message waits for the M0.04 job core). The timer and the command have no limit. `fleet-backup.service` documents that its `ReadWritePaths` is the installer's `FLEET_HOME` (systemd does not expand variables there) and a repo test keeps the two equal. The host git identity (`GitIdentityRule`) now uses the project rules of FLE-37 (`Projects\Rule\GitIdentity`), so both are held to one standard (host values additionally allow "both empty"). The dev environment reads the reboot flag from `FLEET_HOME/test-reboot-required` like the test one, and `reboot-banner.spec.ts` covers the banner at 1280 and 390 px.
- **Secrets** in the sidebar is a real page instead of a placeholder (FLE-39); deleting a project (browser or `fleet project:delete`) also deletes its secrets, and the `fleet` wrapper maps the group `secret`.
- **Projects** in the sidebar is a real page instead of a placeholder (FLE-37), and the `fleet` wrapper maps the groups `project`, `template` and `port` besides `host`, `setup` and `user`. `docs/v0/configuration.md` (the `fleet.yml` reference) is superseded by `docs/projects-and-templates.md`.
- **The proxy trust boundary is closed (FLE-36).** In production the app trusts `Remote-*` identity headers only from a request carrying the shared secret as the FastCGI parameter `FLEET_PROXY_AUTH` (Caddy sets it from `FLEET_PROXY_SECRET`; clients cannot set a FastCGI parameter; compared with `hash_equals`; a secret shorter than 32 characters is ignored). Caddy strips every `Remote-*` and `Remote_*` request header on all routes first. The installer sets `FLEET_TRUSTED_PROXIES` empty, `::ffff:` peers are normalised, and `ProductionGuard` refuses a `FLEET_HOME` inside the project and trusted proxies together with a proxy secret. New environment variables `FLEET_PROXY_SECRET` and `FLEET_CADDY_DIR`.
- The Ansible roles under `ansible/` are the 0.x host layout and are not used by the 1.x installer (`ansible/LEGACY-0x.md`). Host hardening (UFW, sshd, fail2ban) is not part of the installer; it stays an operator decision.
- Documentation: new `docs/installation.md` (installer, wizard, files and units, recovery, upgrades, security model, uninstalling, troubleshooting); `README.md`, `CLAUDE.md`, `docs/README.md`, `docs/development.md` and `tests/README-tests.md` updated.
- **FLE-35: `develop` is now the Symfony 1.x line.** The Python app (`src/fleet/`, the `fleet` CLI, the FastAPI daemon), `pyproject.toml`, `fleet.yml` and its example, the OpenPGP/htmx vendoring (`bootstrap.sh`, `docs/client-side-encryption.md`, `docs/vendored-assets.md`) and the Python/Node/pytest suites for them are removed from `develop`. The 0.x line continues on `main` (tags `v0.*`) and is not affected.
- The documentation is reworked for the 1.x workflow: `CLAUDE.md`, `README.md`, `CONTRIBUTING.md`, `docs/README.md` (index with a status per document), `docs/development.md`, `tests/README-tests.md`, and `docs/RELEASING.md` (new section "1.x during the port": `v1.*` tags are cut from `release/1.x.y` and merged into `develop` only). The 0.x reference documentation moved to `docs/v0/` with a banner naming the milestone that re-implements each document, and its server, client and workspace specifics were replaced with neutral examples.
- CI is now PHP: gates (php-cs-fixer, PHPStan level 8, Twig/YAML/container lint, Doctrine schema validation, PHPUnit) and the Playwright suite run on pull requests, `develop`, `feature/**`, `release/**` and tags; Python only remains for the infra tests and the systemd security check. A `v1.*` tag publishes to the GitHub mirror only when its commit is on `origin/develop` and pushes the tag alone (`ci/tag-guard.sh`); `v0.*` tags keep the old rule. Renovate now covers Composer and npm.

## [0.20.0] - 2026-10-18

### Added
- **FLE-31: scheduled jobs.** New top-level `schedules:` key in `fleet.yml` (name, 5-field `cron`, IANA `timezone`, `action`, `instance`, optional `servers` fleet-domain filter and `enabled`), validated at load time (`Registry.schedules`, frozen `Schedule` dataclasses). The v1 action is `redeploy <instance>`, the same in-place rebuild as `fleet redeploy`. An in-daemon asyncio scheduler (ticks every 30 s, started with `fleet.service`, restarted if it ever dies) fires the schedules through the daemon's `JobManager`, so a run appears in the job list with its live `deploy.log`. In-house cron (`core/schedules.py`, no new dependency): `*`, numbers, ranges, steps, lists, Vixie day-of-month/day-of-week OR; evaluated in the schedule's local time, a nonexistent DST time is skipped and an ambiguous one fires once. Runtime state lives in `<fleet home>/schedules/` (`state.json` with pause flag, last fired occurrence and last run, written atomically; `history.jsonl`). Missed-run policy: an occurrence is claimed (compare-and-set under the state lock, with a `claimed` history line) before it runs (no double run across a restart or a second process), catch-up within 60 minutes, an edited `cron`/`timezone` is re-baselined (no catch-up), older ones are recorded as `missed`, a new/re-enabled/resumed schedule never fires immediately, one run per schedule at a time, a missing instance is `failed` and a locked one `skipped` (never queued). An unreadable, corrupt or unwritable `state.json` fails closed: nothing fires, the UI shows a banner naming the path and the fix, the actions and the CLI refuse, and the file is never overwritten. New **Scheduled jobs** tab (`GET /schedules`, partial `GET /ui/schedules/table`, `POST /ui/schedules/<name>/run|pause|resume`) after Webhooks, and `fleet schedule list|run|history|pause|resume`. See `docs/README-schedules.md`.

## [0.19.0] - 2026-10-18

### Added
- FLE-30 follow-up: Webhooks page filter **Hide ignored hooks** (unchecked by default) hides deliveries whose result is `ignored`.

## [0.18.0] - 2026-10-18

### Added
- **FLE-30: Webhooks page in the web UI.** New read-only `GET /webhooks` page (nav: Instances, Secrets, Webhooks) listing the latest Jira and Bitbucket deliveries from the existing delivery logs, merged and newest first, with time, source, project, event, issue or branch, deployed instance (linked while it exists) / job / pipeline, result, reason and delivery id. Filters `source`, `project`, `result` and `limit` (default 100, max 500) work on the page and on its HTMX partial `GET /ui/webhooks/table`; a Refresh button re-fetches the table. A missing or empty log shows an empty state, torn lines are skipped. New `WebhookStore.recent()` / `recent_with_options()` read a bounded tail of each log. The delivery logs gain four plain fields, `action`, `template`, `label` and `rule` (the rule that matched, from `fleet.yml`, never from the request), shown in the new Rule and Action columns; older lines render with a dash and `fleet webhook log` is unchanged. See `docs/README-webhooks.md`.

## [0.17.0] - 2026-10-17

### Added
- **FLE-32: the instance https site is reachable on `:443` inside the Docker network.** On fleet hosts DDEV's `router_https_port` is `8443`, so in-container clients had to use `https://<fleet host>:8443/`; the `:8443` in the `Host` header broke exact-hostname matching in multi-domain apps (Drupal Domain Access redirected to the production host). The FLE-29 router mirror now also writes a `fleet-mirror-443` entrypoint (`:443`, not published on the host) and a TLS-passthrough TCP router to the router's own https entrypoint (`router_https_port` from `~/.ddev/global_config.yaml`), so `https://<fleet host>/` works and the `Host` header carries no port (DDEV's certificate, `X-Forwarded-Proto: https`). It goes through the same files, lock, failed-apply marker and self-repair; it is skipped when `router_https_port` is `443` or another `static_config.*.yaml` already declares `:443` / `fleet-mirror-443` (`fleet refresh-ports` prints the reason). The existing `fleet.port_mirror` switch (default `true`) covers it. Inside a container use `https://$FLEET_INSTANCE_HOST` as the base URL rather than `DDEV_PRIMARY_URL`. See `docs/networking.md`.
- **Rollout:** (FLE-32) the first deploy after upgrading restarts `ddev-router` once (a brief blip for every instance on that host); `fleet refresh-ports` reports `router mirror: live` afterwards.

## [0.16.0] - 2026-10-17

### Added
- **FLE-29: fleet named ports are mirrored inside the Docker network.** The fleet's public named ports (`fleet.ports` catalogue plus the built-in Typesense `9108`) are now also served by `ddev-router` inside the Docker network, so in-container clients (e.g. Playwright) reach `https://<fqdn>:<public port>` (TLS is DDEV's mkcert certificate, so such clients need `ignoreHTTPSErrors`). New `core/routermirror.py` writes two generic Traefik files in `~/.ddev/traefik/` (`static_config.fleet-port-mirror.yaml`, `custom-global-config/fleet-port-mirror.yaml`) from `deploy`, `destroy`, `fleet refresh-ports` and daemon startup; DDEV merges them on `ddev start`, and a deploy restarts `ddev-router` only when its live entrypoints differ from the registry, self-repairing (files removed, router restarted again) and warning if the router does not come back. Ports that collide with the router's own (`80/443/8080/8443/8025/8026/10999`), with another entry's router port, or that duplicate a public port are skipped with a reason. `fleet refresh-ports` reports `router mirror: live` or `pending`. Opt out with `fleet.port_mirror: false` (default `true`). New `RouterMirrorError`. See `docs/networking.md`.
- **Rollout:** (FLE-29) the first deploy after upgrading restarts `ddev-router` once (a brief blip for every instance on that host). On ddev3, delete the spike files `~/.ddev/traefik/static_config.fleet-spike.yaml` and `~/.ddev/traefik/custom-global-config/fleet-spike.yaml`, then run `fleet refresh-ports` and a deploy. Until they are deleted the mirror simply skips `9108` (another `static_config.*.yaml` already declares it; `fleet refresh-ports` prints the reason). A failed apply leaves `~/.ddev/traefik/.fleet-port-mirror-failed`, which keeps the mirror off until the wanted ports change or `fleet refresh-ports` clears it.

## [0.15.0] - 2026-10-16

### Changed
- **FLE-27: derived-label push deploys no longer overwrite.** A Bitbucket `repo:push` `action: deploy` rule with `label_prefix` now behaves like a normal deploy: when `<project>--<label>` already exists, the push deploys a new instance `<label>-1`, `-2`, ... (the response, the delivery log, the job and its `deploy.log` name the instance that will really be created) and old instances are kept (destroy them with `fleet destroy`). Rules with a fixed `label` are unchanged: one instance, rebuilt in place on every push. Derived labels are fitted with 4 characters of extra room for the suffix, so ids of 60-63 characters are now cut and hashed. New `instances.allocate_instance_label()`, shared with `deploy()`. See `docs/README-webhooks.md`.

## [0.14.0] - 2026-10-16

### Added
- **FLE-27: Bitbucket `repo:push` webhooks deploy the pushed branch.** `bitbucket_hooks` rules take a new `action: deploy` (only with `on_event: repo:push`, `branch` glob required) with `template` and either a fixed `label` or `label_prefix` / `strip_suffix`. A push of a matching branch starts a deploy job of that template with `replace=True` on the instance `<project>--<label>`, e.g. `*--pw-run` -> `website--pw-<branch>`. Branch deletions and tags are ignored; one push can start several deploys. Labels are slugified and, when the id would exceed 63 characters, cut and suffixed with `-` + the first 6 hex of the branch's SHA-1 (deterministic). No Bitbucket token is needed. A job that finds its instance locked (a deploy of the same id still running) waits and retries for up to 45 minutes instead of failing. A derived-label deploy that would land on the id of a fixed-`label` rule is ignored (label-collision guard), and the derived-label prefix namespace is reserved for webhook-owned instances. Label fitting is alias-aware: a project's `additional_hostnames` aliases (`<hostname>-<id>`, 63-character limit) are reserved room, so long branches still deploy. A derived label requires `label_prefix` (`strip_suffix` alone is rejected at load time, since a bare branch slug could equal a hand-deployed instance id). See `docs/README-webhooks.md`.

### Changed
- **Rollout:** (FLE-27) every server must run 0.14.0+ before `fleet.yml` contains an `action: deploy` rule: v0.10.0-v0.13.1 refuse a registry with unknown `bitbucket_hooks` keys, which breaks every command and webhook route on that server, and all servers share the config repo.
- `fleet webhook secret <p> --source bitbucket` no longer warns about a missing pipeline token when all of the project's rules are `deploy` rules.

### Dependencies
- Python tooling: ruff >=0.17.1, black >=26.10.1, ansible-lint >=26.10.1 (Renovate #32, #33, #35).
- Python runtime: fastapi >=0.143.2 (Renovate #34).

## [0.13.1] - 2026-10-09

### Fixed
- **FLE-26: `hardening.yml --check` on a never-hardened host.** v0.13.0 still failed a dry run on a server without ufw: the `ufw` tasks (the binary is only pretend-installed in check mode) and enabling units the roles template in the same run (`fleet-ufw-deadman-bootcheck.service`, the dead-man's timer, `fleet-reboot-notify.timer`). These tasks are now tolerated in check mode only, so a dry run reaches `security_hardening` and shows its diff. Real runs are unchanged.

## [0.13.0] - 2026-10-09

### Added
- **FLE-25: scoped playbooks for live servers.** `ansible/hardening.yml` applies `network_hardening`, `security_hardening` and `security_probe` alone, and `ansible/fleet-units.yml` applies only the systemd units and CLI wrapper of `fleet_service` (no git clone/update, no venv/pip; the unit tasks moved to `roles/fleet_service/tasks/units.yml`, still imported by `main.yml` in the same order). Both load `/etc/ddev-fleet/local-vars.yml` like `caddy-only.yml`. A changed `fleet.service` now also queues a `systemctl try-restart fleet.service` (no-op when the daemon is not running). See `docs/operations.md`.

### Changed
- **Rollout:** (FLE-25) servers hardened before this release may still block the registry's named ports (Typesense 9108, ...): run `fleet refresh-ports` once, or re-run `hardening.yml`.

### Fixed
- **FLE-25: named ports blocked after hardening.** `network_hardening` now deploys `fleet-ufw-sync` and runs it before `ufw enable`, so the registry's named ports are staged with SSH/80/443 (skipped in `--check` and when the fleet venv is missing; a failing sync warns instead of aborting).
- **FLE-25: hardening no longer restarts Docker.** The `daemon.json` change notifies a `Reload docker` handler (`systemctl reload docker`) instead of restarting it, so running DDEV containers (restart policy `no`) survive on a host without live-restore yet.
- **FLE-25: `--check` works.** The UFW "SSH port staged" assert no longer errors in a dry run (`ufw show added` runs read-only in check mode); the `findmnt` and msmtp credential reads in `security_hardening` do too, and its test-send report is skipped with the send. On a host that has never been hardened, the needrestart, fail2ban and auditd tasks (their directories and units only exist once the packages are really installed) are tolerated in check mode only.

### Dependencies
- FLE-10: `fastapi>=0.143.0` (was `>=0.142.4`), Renovate #29.

## [0.12.1] - 2026-10-09

### Added
- **FLE-24: browser end-to-end tests.** `tests/playwright/` is a TypeScript `@playwright/test` suite (its own npm package, Playwright 1.63.0 pinned exactly) that starts the real daemons under uvicorn and drives the Secrets page in headless Chromium with the CSP enforced; console errors, page errors and CSP violations fail a test, and the save test checks that only ciphertext is sent and that `gpg` decrypts what the browser encrypted. It never skips: a missing browser fails the run. The Bitbucket gates step runs `npm ci`, `npx playwright install --with-deps --only-shell chromium` and `npx playwright test` in that folder. See `tests/README-tests.md` and `docs/README-ci.md`.

### Changed
- **FLE-24: test tree reorganised by framework.** `tests/pytest/<area>/` (unit and TestClient tests, grouped by area), `tests/playwright/` (browser E2E, TypeScript) and `tests/node/` (Node's built-in test runner, was `tests/js/`); shared `conftest.py` and `fixtures/` stay at the root of `tests/`.

### Fixed
- **FLE-24: Secrets "Encrypt and save" sent nothing.** htmx validates every form field before sending; the plaintext value field was cleared after encryption, so its `required` attribute silently halted the request. `required` is removed from that unnamed field; an empty value is still refused in the browser with a visible message.

## [0.12.0] - 2026-10-08

### Added
- **FLE-21: encrypted per-project secret store.** `fleet keys init|show` creates and shows a GnuPG host key (ed25519 + cv25519, no passphrase) in `$FLEET_HOME/gnupg`. With a key, `fleet secret set` stores `secrets/<project>/<KEY>.asc` (OpenPGP, 0600) and deploy, tty commands and Typesense key handling decrypt through the new `SecretStore`; `.asc` wins over a legacy `secrets/<project>.env`, which is still read. New `fleet secret list|unset|migrate (<project>|--all)`; `migrate` encrypts, verifies by decrypting, then deletes the plaintext file. The Ansible `fleet_user` role creates `/srv/fleet/secrets` and `/srv/fleet/gnupg` (0700). See `docs/operations.md` "Encrypted project secrets".
- **FLE-22: Secrets screen.** New dashboard page `/secrets`: choose a project, type a name and a value, and the value is encrypted in the browser with OpenPGP.js (vendored 6.3.2, LGPL-3.0-or-later, sha256-pinned) to the host's GnuPG public key before anything is sent. The server only validates the ciphertext (packet inspection, never a decrypt) and stores it as `secrets/<project>/<KEY>.asc`; values are never shown again. Without a host key the page explains `fleet keys init`. Routes: `GET /secrets`, `GET|POST /ui/secrets/{project}`, `POST /ui/secrets/{project}/{key}/delete`. Existing legacy plaintext names are listed as such.
- **FLE-22: browser-to-GnuPG interop tests.** `node --test tests/js/*.test.mjs` (new gate) covers the browser crypto; `tests/test_pgp_interop.py` encrypts with Node and the vendored file and decrypts with the real `gpg` (plus a committed OpenPGP.js ciphertext fixture). They skip when `gpg`/`node` are missing unless `FLEET_REQUIRE_PGP_TOOLS=1`.
- `docs/client-side-encryption.md`: data flow, key management and the threat model of client-side secret encryption.

### Changed
- **FLE-21: `fleet secret set <project> <key>`** reads the value from a hidden prompt or stdin; the positional `<value>` still works but is deprecated and prints a warning to stderr. The project must exist in `fleet.yml`, and the key must match `^[A-Z][A-Z0-9_]{0,63}$`. `fleet init` now also creates an empty `gnupg/` directory.
- **FLE-22:** the Bitbucket gates image installs `gnupg` and `nodejs`, sets `FLEET_REQUIRE_PGP_TOOLS=1` and runs `node --test tests/js/*.test.mjs`. `.mjs` files are served as `text/javascript`. Renovate now also tracks the vendored OpenPGP.js version in `docs/vendored-assets.md`.
- **Rollout:** encryption is opt-in per server. Nothing changes until `sudo -u fleet fleet keys init` creates the host key; then optionally run `sudo -u fleet fleet secret migrate --all` to encrypt the existing plaintext secret files, and check that gpg-agent works under the systemd sandbox (`docs/operations.md`). The new `/srv/fleet/secrets` and `/srv/fleet/gnupg` directories (0700) are created by `fleet init` / `fleet keys init`, or by the `fleet_user` role through a scoped playbook (never the full `site.yml`).

### Security
- **FLE-21:** project secrets can rest as ciphertext on disk once a host key exists; no plaintext value in argv when the prompt or stdin is used (the deprecated positional value still lands in argv), in logs, error messages or command output, and gpg is run with separate stdout/stderr rather than the merged streaming runner. Run `fleet secret migrate --all` after `fleet keys init`, then rotate secrets that were ever stored in plaintext. The host key has no passphrase; it is protected by file permissions only (see the threat notes in `docs/operations.md`).
- **FLE-23: strict Content-Security-Policy on the dashboard.** Every HTML response (pages and htmx fragments) now carries `default-src 'self'; script-src 'self'; style-src 'self'; ...; frame-ancestors 'none'`, plus `X-Content-Type-Options: nosniff` and `Referrer-Policy: no-referrer`. The inline `<style>` and `style=` moved to `static/fleet.css`, and htmx runs with `includeIndicatorStyles` and `allowEval` off. A test fails if a template reintroduces inline script, style or event handlers.
- **FLE-23: cross-origin writes to `/ui/*` are refused (HTTP 403).** A state-changing UI request must be `Sec-Fetch-Site: same-origin` or carry an `Origin` equal to the request `Host`; `cross-site`, `same-site` (a DDEV instance on a sibling subdomain) and `Origin: null` are rejected. Requests sending neither header (curl, scripts) still work. `/hooks/*`, `/api/*` and the WebSocket are unaffected.
- **FLE-22:** a secret entered in the web UI never crosses the network or touches the host's disk as plaintext (from the browser to the stored `.asc`; decrypted deploy-time use is unchanged): the value input is unnamed (it cannot be submitted), is cleared once encrypted, and the server rejects anything that is not an OpenPGP message addressed to the host key. The limits (root on the host, the unpassphrased host key readable by the fleet user, a compromised dashboard page) are documented in `docs/client-side-encryption.md`.

### Dependencies
- FLE-10: `fastapi>=0.142.4` (was `>=0.142.2`), Renovate #24.

## [0.11.0] - 2026-10-07

### Added
- **FLE-11:** `docs/README-renovate.md` explains how to test the hands-off Renovate merge end to end with a `renovate/*` test PR (it came in through such a PR, #22, merged by `renovate-merge`).
- **FLE-16: published systemd security report.** The weekly `systemd-security-live` run now builds `SYSTEMD-SECURITY-REPORT.md` (`ci/systemd_security.py publish-report`): what the exposure score means, a status line per host, the product's units first (fleet, fleet-boot, fleet-reboot-notify, caddy, authelia) with score, baseline and delta, the full host overview, and the offline scores of the shipped unit files. On `develop` (or any branch run with `PUBLISH_REPORT=1`) the pipeline commits it, even when a unit regressed, and pushes it back (never forced, one rebase retry); the README links to it on GitHub. The step image is now `python:3.11-trixie` with `systemd` and `openssh-client`.
- **FLE-16: Jira alert on a regression.** When a host regressed or could not be fetched, `ci/systemd_security.py jira-alert` posts one comment on the issue in `JIRA_ALERT_ISSUE` with a real @mention (the account id in `JIRA_ALERT_MENTION`), naming the host, the regressed units, the changed directives, the branch and links to the pipeline run and the report. Stdlib only; a scoped token goes through the `api.atlassian.com` gateway when `JIRA_ALERT_CLOUD_ID` is set. Unset variables skip it, and a failed post only warns. See `docs/README-ci.md`.

### Changed
- **FLE-8: ddev3 baseline recorded** in `ci/systemd-security-baseline.json` (systemd 257, 37 units) for the live systemd security check, after the `security_probe` role was applied there. The live-check test now uses a host that never gets a baseline, so recording a real one cannot break it (#20).

### Fixed
- **FLE-11: `renovate-merge` only considers open PRs.** Bitbucket ignored `state=OPEN` next to the `q` filter, so merged and declined `renovate/*` PRs were listed as candidates (shown as "behind", requesting needless Renovate runs). The state is now part of `q` and checked again client-side. Docs: `RENOVATE_MERGE_APPROVERS` must hold the maintainer's account id when the Renovate token belongs to a bot account.

## [0.10.0] - 2026-10-07

### Added
- **FLE-8: systemd security regression checks in Bitbucket Pipelines.** `ci/systemd_security.py` renders the sandboxed units (fleet, fleet-boot, fleet-reboot-notify, caddy with its drop-in), scores them with `systemd-analyze security --offline=yes`, compares them with the committed baseline `ci/systemd-security-baseline.json` (tolerance +0.1) and fails the pipeline on a regression, listing the directives whose exposure changed. Runs in the gates on every pull request, `develop` push and release tag; Markdown and JSON reports are published as pipeline artifacts (`reports/**`). Improvements and new/removed units are reported without failing. Baseline update procedure: `docs/README-ci.md`.
- **FLE-8: live check.** A `custom: systemd-security-live` pipeline (weekly Bitbucket schedule) fetches `systemd-analyze security` scores from each server in the repository variable `SECURITY_PROBE_TARGETS` and compares them with that server's baseline. It skips when the variable is unset.
- **FLE-8: `security_probe` Ansible role** and scoped playbook `ansible/security-probe.yml`: a no-sudo `fleet-probe` user whose SSH keys (`fleet_security_probe_authorized_keys`, set in `/etc/ddev-fleet/local-vars.yml`) are forced commands that can only print the JSON report (`/usr/local/bin/fleet-security-report`, stdlib-only Python). Inert until a key is configured; it never introduces the first `AllowUsers` line, only extends an existing one. Also listed in `site.yml` after `security_hardening`.
- `docs/README-ci.md`: the pipelines, the baseline, regression handling and live-check setup.

- **FLE-14: branch indicator can follow a submodule.** New optional per-project `fleet.yml` key `display_submodule_branch: <relative submodule path>` (e.g. `ddev-fleet`). When set, `fleet list`, the web UI instance list and the tmux sidebar show the branch and short HEAD of the git checkout at `<instance dir>/<path>` instead of the instance's own, prefixed with the path (`ddev-fleet: feature/FLE-12-x`). A missing or uninitialised submodule falls back to the instance's own branch. Display only: deploy, redeploy and the recorded `branch` are unchanged. Validated at registry load (non-empty relative path, no `..`).
- **FLE-15:** the web UI shows the server hostname (`ddev-fleet (<hostname>)` as page title and heading) and the Fleet version it is running (from `git describe --tags`, falling back to the package version) in the footer next to the server stats.
- **FLE-11: Bitbucket webhooks start a custom pipeline.** New route `POST /hooks/bitbucket/{project}` verifies Bitbucket Cloud's `X-Hub-Signature` HMAC-SHA256 with a per-project secret (separate from the Jira one). When an event matches one of the project's new `bitbucket_hooks` rules in `fleet.yml` (`on_event`, `repo`, optional `branch` glob, `state`, `comment`, `action: run-pipeline`, `pattern`, `ref`), it starts that custom pipeline through the Bitbucket API, using a per-project access token that only needs the `pipeline:write` scope. Deliveries are deduplicated on `X-Request-UUID`, and a failed trigger releases the claim so Bitbucket's retry can still succeed. New CLI: `fleet webhook secret <p> --source bitbucket`, `fleet webhook bitbucket-token <p>` (reads stdin or a hidden prompt), and `fleet webhook log --source bitbucket`. See `docs/README-webhooks.md`.
- **FLE-11: hands-off Renovate merges.** New custom pipeline `renovate-merge`, backed by `scripts/renovate_merge.py`. It merges (merge commit) at most one open `renovate/*` PR per run, and only one that the maintainer has accepted (an Approve or a `/merge` comment), that has green gates on its current head, and that is up to date with `develop`. It then runs Renovate to recreate the other PRs on the new `develop`. An approved PR with red gates gets one "needs a human" comment. Renovate now uses `rebaseWhen: behind-base-branch`. See `docs/README-renovate.md`.

### Changed
- **FLE-8: the GitHub Actions workflow is removed** (`.github/workflows/ci.yml`); Bitbucket Pipelines is the only CI. The ansible-lint/yamllint job moved to a non-blocking Bitbucket step that runs next to the gates on pull requests and `develop`. The Renovate rule for GitHub Actions is dropped with it.
- The Bitbucket gates image is Debian 13 (`python:3.11-trixie`) with `systemd` installed, so `tests/test_systemd_sandbox.py` scores the units instead of skipping. With `FLEET_REQUIRE_SYSTEMD_ANALYZE=1` (set in the pipeline) the tests fail when `systemd-analyze` is missing.

### Dependencies
- FLE-10: vendored htmx 2.0.11 (was 1.9.12; supersedes Renovate PR #11). No breaking change applies to the UI; behaviour verified identical in a headless-browser run (#15).

## [0.9.2] - 2026-10-06

### Dependencies
- `argon2-cffi` >= 25.1.0 (was >= 23.1) (#10).
- `rich` >= 15.0.0 (was >= 13.9.4) (#12).
- Dev: `ansible-lint` >= 26.9.0 (was >= 24.12.2) (#9).
- CI (GitHub workflow): `actions/checkout` v4 → v7, `actions/setup-python` v5 → v7 (#13).

## [0.9.1] - 2026-10-06

### Added
- **FLE-12:** `docs/RELEASING.md` — the release procedure (versioning, CHANGELOG conventions, release and hotfix steps, push order and recovery for the tag pipeline guard).

## [0.9.0] - 2026-10-06

### Added
- **FLE-3: Jira webhooks trigger deploys.** New route `POST /hooks/jira/{project}` receives Jira Cloud admin webhooks, verifies the `X-Hub-Signature` HMAC-SHA256 with a per-project secret, and, when an issue moves into a status listed in the project's new `jira_hooks` rules in `fleet.yml` (`on_status`, `action: deploy`, `template`, optional `branch`), deploys an instance labelled with the issue key, exactly like a web-UI deploy (tty commands, auto-suffix). Retries are deduplicated on `X-Atlassian-Webhook-Identifier`. Every outcome is answered with JSON and a 4xx/200 code (see `docs/README-webhooks.md`). Hooks stay off on a server until a secret exists there.
- `fleet webhook secret <project> [--rotate]` (generates and stores the secret in `/srv/fleet/webhooks/secrets.env`, mode 0600, and prints it with the URL to configure in Jira) and `fleet webhook log [--project P] [-n N]` (tails `/srv/fleet/logs/webhooks/jira.jsonl`).
- **FLE-1: systemd sandboxing.** `fleet.service`, `fleet-boot.service` and `fleet-reboot-notify.service` get a `systemd-analyze security` sandbox (`ProtectSystem=strict`, syscall filter, empty capability set, `PrivateDevices`, `MemoryDenyWriteExecute`, ...; exposure 8.7 / 9.2 / 9.0 down to 1.8 / 1.8 / 1.6). The two daemon units share one include (`fleet-sandbox.inc.j2`) so they cannot drift. The `caddy` role installs a drop-in `caddy.service.d/50-fleet-sandbox.conf` (8.8 down to 1.6). `fleet-tmux.service` stays unsandboxed on purpose.
- `security_hardening` runs `systemd-analyze security` against fleet, fleet-boot, fleet-reboot-notify, caddy and authelia at the end of the role (skips units that are not installed) and prints one summary; a unit over its threshold logs a warning, or fails the play with `fleet_systemd_security_enforce: true`. Settings: `fleet_systemd_security_check_enabled`, `fleet_systemd_security_thresholds`, `fleet_systemd_security_enforce`.
- `tests/test_systemd_sandbox.py`: renders the units and scores them offline with `systemd-analyze` (skipped when it is absent).
- **FLE-2: Bitbucket Pipelines CI and GitHub auto-mirror.** `bitbucket-pipelines.yml` runs pytest, ruff and black on every pull request, on `develop` and on `v*` tags. Bitbucket is the source of truth: a green `develop` push is mirrored to the GitHub mirror, and a `v*` tag publishes `main` plus the tag (refused unless the tag is the tip of `main`). Pushes are never forced; the steps skip when `GITHUB_MIRROR_URL` is unset, so forks stay inert. See `CONTRIBUTING.md`.
- **FLE-2: Renovate.** `renovate-config.json` plus a scheduled `custom: renovate` pipeline open daily grouped dependency PRs against `develop` (Python, GitHub Actions, Ansible collections, vendored htmx via `docs/vendored-assets.md`). The Python interpreter version is never auto-bumped.
- `docs/vendored-assets.md`: records the vendored htmx version and how to re-vendor it.
- `tests/test_ci_config.py`: structure checks for the pipelines and the Renovate config.

### Changed
- Caddy (`caddy` role): `/hooks/*` on the fleet host is exempt from Authelia/basic auth (the daemon authenticates it by HMAC, as `/ws/*` by token) and its request body is capped at 1MB. **Re-run `ansible/caddy-only.yml` after upgrading** to get the route through Caddy.
- **FLE-10 (Renovate): dependency floors raised.** Runtime: `fastapi>=0.142.2`, `uvicorn[standard]>=0.54.0`, `ruamel.yaml>=0.19.1`, `jinja2>=3.1.6`, `python-multipart>=0.0.32`, `rich>=13.9.4`. Dev/tooling: pytest 9.1, ruff 0.16, black 26.10, httpx 0.28, ansible-core 2.21, ansible-lint 24.12, yamllint 1.38. Ansible collections (`ansible/requirements.yml`) move to new major versions. **Upgrading a server needs `pip install -e` in the fleet venv** (`push-product` does it when `pyproject.toml` changed).
- `bootstrap.sh` fresh installs clone with `--branch main` instead of the remote's default branch (now `develop` on Bitbucket).
- `fleet.service` now runs with `ProtectSystem=strict`; the daemon can only write `fleet_srv_dir`, the Caddy snippet dir, the `fleet` user's home, `/tmp` and `/var/tmp`. Anything else fails with EROFS. Set `fleet_systemd_sandbox_enabled: false` to get the previous units back (and the Caddy drop-in removed). See `docs/operations.md`, "systemd sandboxing".

## [0.8.0] - 2026-10-06

### Added
- **FLE-6: `fleet-tmux.service`** (new systemd unit in the `fleet_service` Ansible role, tag `fleet_tmux`). It owns the `fleet` tmux server in its own cgroup, so `systemctl restart fleet` never kills operators' panes, and recreates the session after a reboot with a window per instance (sidebar + tty1 + tty2) as plain shells. `Type=oneshot` + `RemainAfterExit=yes`, `After=fleet.service` only (it does not wait for `fleet-boot.service`).
- `fleet tmux --ensure`: non-interactive create-if-missing + reconcile, no attach — what the unit runs.
- `post_deploy` may now be a mapping with optional `exec`, `tty1` and `tty2` lists; the list form stays valid as shorthand for `exec:`. Any other key is a validation error naming the template.

### Changed
- **FLE-6: tty1/tty2 commands are now a deploy action.** They are typed only by `fleet deploy` and `fleet redeploy` (CLI and web UI alike), from the template as resolved at deploy time. `fleet tmux`, reconcile, a recreated or closed window, `^b R` and the reboot reconcile always create plain shells, so a reboot no longer relaunches `/jira work` (with permissions skipped) on every templated instance.
- Deploy with no `fleet` tmux session: the CLI creates the session as before; the daemon/web UI never does and logs `fleet-tmux.service not running: tty commands not typed; start it with sudo systemctl start fleet-tmux and redeploy`. If the instance's window already exists the commands are not typed into it (warning in the deploy log).
- **Rollout order:** every server must run 0.8.0+ before `fleet.yml` uses the `post_deploy` mapping — older products cannot parse it and all servers share the config repo.

### Deprecated
- Template-level `tty1:`/`tty2:` (use `post_deploy.tty1`/`tty2`). Still accepted, with a deprecation warning once per template; setting the same pane in both places is a validation error.

### Removed
- `ttycmds.plan_for_instance` and the `tty_for` argument of `tmux.reconcile` (they re-derived tty commands from the live `fleet.yml` on every window creation).

## [0.7.2] - 2026-10-05

### Fixed
- **FLE-5: in-container `git push` failed on every instance after a reboot.** DDEV's ssh-agent is shared by all projects and starts empty after a reboot, and `start()` (`fleet start`, `fleet start --all`, `fleet-boot.service`) never loaded the read-write push key — only `deploy()` did — so a project pre-start hook could leave just the read-only deploy key in the agent. `start()` now clears the shared agent and loads only the push key after a successful `ddev start` (including after the port-conflict stop+start retry), via a helper shared with `deploy()`. A push-key problem only warns and never fails a start; a server with no push-key directory skips the step.

## [0.7.1] - 2026-10-05

### Fixed
- **FLE-4: every public URL returned 502 after a reboot until all instances had started.** `fleet-boot.service` is now `Type=exec` instead of `Type=oneshot`, so it no longer holds up `multi-user.target` (which the Authelia unit is ordered after); the dashboard and Authelia come up immediately while instances keep starting sequentially in the background.

## [0.7.0] - 2026-10-05

### Added
- **Authelia auth mode** as an alternative to per-instance basic auth
  (`auth_mode: authelia` in `host.yml`, default remains `basic`): a
  cookie-based login portal for networks that block basic auth outright,
  with per-project users defined in `fleet.yml`'s new `users:` key and
  authorized per-instance by Caddy's `forward_auth` against Authelia
  (`fleet.core.authelia`, `fleet.core.caddyauth`). Editing `users:` never
  restarts Authelia — only its hot-reloaded `users.yml` changes. The old
  `fleet.auth_bypass_cidrs` IP whitelist is removed: it is now accepted
  with a deprecation warning and otherwise ignored. See
  `docs/README-authelia.md`.
  - **Fixed (first live rollout, ddev2, 2026-09-27):** the role's apt
    signing key URL 404'd (`ff461cc`) — corrected to
    `https://www.authelia.com/keys/authelia-security.gpg`, gpgv-verified
    against the repo's `InRelease` like every other apt source in this
    project. Then three more bugs the `authelia` role only hit on a real
    server (`5b75ce9`). `storage.local.path` and
    `notifier.filesystem.filename` moved from `/srv/fleet/authelia`
    (Authelia has group-read only there, by design — "unable to open
    database file: permission denied") to a new, Authelia-writable
    `/var/lib/authelia`. The role now also grants the Authelia service
    user a traverse-only (`x`) ACL entry on `/srv/fleet` itself (via
    `setfacl`, guarded by a `getfacl` read-back), since it previously
    couldn't even `stat` into `/srv/fleet/authelia`. And the role's own
    `authelia config validate` task now gets the same
    `AUTHELIA_*_FILE` secret env vars the systemd drop-in supplies
    (both derive from one `authelia_secret_env` mapping in
    `ansible/roles/authelia/vars/main.yml`) — it previously failed with
    `storage: option 'encryption_key' is required`. See
    `docs/README-authelia.md`.

## [0.6.0] - 2026-09-25

### Added
- **Bounded timeouts on `fleet list`'s read-only status calls** so a
  stalled `docker`/`ddev`/`git` process degrades the table instead of
  hanging the whole command forever (observed once on ddev2, 2026-09-22:
  a `fleet list` hung >2 minutes with no output before being killed by
  hand — not reproduced since, so this is defensive hardening rather than
  a confirmed root-cause fix). `ddev list --json-output`
  (`core/ddev.py:LIST_TIMEOUT` = 30s), `docker stats --no-stream`
  (`core/ddev.py:STATS_TIMEOUT` = 20s), and the per-instance `git
  rev-parse` branch/HEAD reads (`core/instances.py:GIT_READ_TIMEOUT` =
  10s) now all time out rather than block indefinitely. On a `ddev list`
  timeout/failure, instances are still listed from on-disk state but
  `InstanceStatus.state` reports a new `"unknown"` value instead of the
  previous, inaccurate `"deployed"` (which asserted a live fact — nothing
  running — that was never actually observed); a `docker stats`
  timeout/failure only blanks the RAM column; a git-read timeout/failure
  falls back quietly to the deploy-time recorded branch, same as its other
  failure modes. Each of the first two prints one `warning: …` line to
  stderr naming what's unavailable. Deploy/destroy/start/stop/import paths
  are untouched — they still wait indefinitely by design. See
  `docs/cli.md`'s "`fleet list` degraded state".
- **Pre-destroy preflight** for redeploy/replace: `redeploy()` and
  `deploy(replace=True)` (and, more lightly, `destroy()`) now run cheap,
  side-effect-free checks — registry still resolves the deploy target,
  alias FQDNs still compose, the Caddy snippet directory guard, and the
  CURRENT Caddy config still validates — BEFORE anything is torn down.
  Raises `DeployError` naming the reason and stating nothing was
  destroyed. Closes the gap that let `fleet redeploy --all` destroy 5
  instances on 2026-09-22 and then fail to rebuild them because `caddy
  validate` was already broken for an unrelated reason.
- **Per-host `fleet.domain`** (`<FLEET_HOME>/host.yml`, e.g.
  `/srv/fleet/host.yml`): lets several fleet servers share ONE `fleet.yml`
  (via the config repo) while each keeps its own domain. Rendered by the
  `caddy` Ansible role from `fleet_domain`; `host.yml`'s `domain` wins over
  `fleet.yml`'s own `fleet.domain` when both are set, and `fleet.domain`
  becomes optional in `fleet.yml` once every host has its own `host.yml`.
  See `docs/configuration.md`'s "Per-host domain" section.
- **TLS certificate mode**, chosen at install time (`fleet_tls_mode`,
  `on_demand` default or `ovh_dns`): `on_demand` keeps today's
  per-hostname Let's Encrypt behaviour (HTTP-01 via `/api/tls-authorize`,
  ~50 new certs/registered-domain/7 days); `ovh_dns` issues a single
  wildcard certificate for `*.<domain>` via the OVH DNS-01 challenge
  (`caddy-dns/ovh` plugin, custom Caddy build installed via
  `update-alternatives`), removing the per-hostname rate limit entirely.
  Both modes render from one shared Caddy snippet, `tls.conf`, imported by
  a literal path from the `*.<domain>` site and every named-port site
  (`fleet.core.caddyports` needs no knowledge of the mode). New scoped
  playbook `ansible/caddy-only.yml` lets an existing server switch mode
  (or domain) without running `site.yml`. `bootstrap.sh` prompts for the
  mode and, for `ovh_dns`, the OVH API credentials (written straight to
  `/etc/caddy/ovh.env`, never to `local-vars.yml`). See
  `docs/installation.md` "Choosing a TLS mode" and `docs/networking.md` §4.
- The `*.<domain>` site now sends `X-Robots-Tag: noindex, nofollow` on
  every response, in both TLS modes — instance URLs are ephemeral,
  often-unfinished work and should never be indexed.
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

## [0.5.0] - 2026-07-27

Backfilled summary — this release's larger features predate the detailed entries below: named-port exposure (`fleet.ports`, per-port Caddy snippets, `fleet refresh-ports`); bulk start/stop/destroy and multi-deploy (`deploy --count`) in CLI and web UI; reboot-required notifications (msmtp email, UI badge, tmux banner, `fleet reboot-notify`); the `network_hardening` (UFW + dead-man's switch) and `security_hardening` (SSH, fail2ban, sysctl, auditd, needrestart, Docker `daemon.json`) Ansible roles; the four fleet-management Claude skills; the bundled `demo` project.

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

## [0.4.0] - 2026-07-23

Operator workspace release (backfilled): per-project secret injection via `[[token]]` substitution; Typesense exposure reworked to port-based browser access with search-only keys; `fleet set-claude-token` with non-destructive propagation; `fleet shell` / `fleet ddev`; per-instance HTTP basic auth and a rotatable dashboard password; deploy logs kept after destroy; shared per-project DB dumps (hard links); server shell profile and Claude first-run onboarding; `fleet refresh-instance-config`; the `fleet tmux` workspace (instance tabs, live sidebar showing the checked-out branch, pane layouts, `tmux-reset`); per-project `git_bot` override; web UI footer with free memory/disk; mkcert local CA for the fleet user.

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

[Unreleased]: https://github.com/rcuny/ddev-fleet/compare/v0.20.0...HEAD
[0.20.0]: https://github.com/rcuny/ddev-fleet/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/rcuny/ddev-fleet/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/rcuny/ddev-fleet/compare/v0.17.0...v0.18.0
[0.17.0]: https://github.com/rcuny/ddev-fleet/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/rcuny/ddev-fleet/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/rcuny/ddev-fleet/compare/v0.14.0...v0.15.0
[0.14.0]: https://github.com/rcuny/ddev-fleet/compare/v0.13.1...v0.14.0
[0.13.1]: https://github.com/rcuny/ddev-fleet/compare/v0.13.0...v0.13.1
[0.13.0]: https://github.com/rcuny/ddev-fleet/compare/v0.12.1...v0.13.0
[0.12.1]: https://github.com/rcuny/ddev-fleet/compare/v0.12.0...v0.12.1
[0.12.0]: https://github.com/rcuny/ddev-fleet/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/rcuny/ddev-fleet/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/rcuny/ddev-fleet/compare/v0.9.2...v0.10.0
[0.9.2]: https://github.com/rcuny/ddev-fleet/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/rcuny/ddev-fleet/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/rcuny/ddev-fleet/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/rcuny/ddev-fleet/compare/v0.7.2...v0.8.0
[0.7.2]: https://github.com/rcuny/ddev-fleet/compare/v0.7.1...v0.7.2
[0.7.1]: https://github.com/rcuny/ddev-fleet/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/rcuny/ddev-fleet/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/rcuny/ddev-fleet/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/rcuny/ddev-fleet/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/rcuny/ddev-fleet/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/rcuny/ddev-fleet/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/rcuny/ddev-fleet/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/rcuny/ddev-fleet/releases/tag/v0.1.0
