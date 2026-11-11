# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.9.1] - 2026-11-11

### Added
- **FLE-308: `fleet project:update <project> --allow-privileged-overrides | --no-allow-privileged-overrides`.** The privileged compose overrides switch (FLE-298) can now be set from the root CLI, so a host can be prepared before an upgrade. The change is audited (`project.privileged-overrides`, actor `cli:<user>`), turning it on prints the risk, and `project:show` (and its JSON) shows the current value. It is still not part of create, export or import.

### Fixed
- **FLE-307: the database isolation no longer fails every start.** DDEV attaches the `db` container to `ddev_default` itself, so the compose override of 1.9.0 (FLE-297) never took effect and each start, restart and deploy of an instance with a db ended in a failed job. The post-start step is now the mechanism: it disconnects the project's non-web containers from `ddev_default`, logs an info line, audits it (`isolation.db-disconnect`) and succeeds; it fails only when the disconnect fails. The instance watcher repeats the check every two minutes. The compose file is no longer written for an instance with a db (a stale one is removed); the `db` pinning of database-less instances stays. The `db` is always detached, even with traefik labels; other services are exempt only with a traefik router label key.
- **FLE-309: `fleet instance:isolate --apply` no longer stops instances the deploy guard refuses.** It now skips them and prints one line per skipped instance with the reason (unless the project allows privileged overrides). It also takes instance ids or project names to narrow the selection, and `--force-refused` restarts the refused ones only after an interactive confirmation (`--yes` does not replace it).

## [1.9.0] - 2026-11-11

### Added
- API hardening follow-ups: opt-in mTLS for `/api/v1/*` (put a CA PEM at `/etc/caddy/fleet/api-client-ca.pem`; Caddy then refuses API calls without a verified client certificate, off by default); a private Mercure alert for every terminal write, sent (or failed after the keys may have been typed), shown live as a toast in the administrator's shell, plus a "Needs attention" count; expiry reminders for API tokens (`fleet:token:remind`, hourly worker check, one `api_token.expiring` audit entry a day, a warning in `fleet:token:list`) (FLE-289).


- **FLE-296: a guide to git access and SSH keys.** `docs/guides/10-git-access-and-ssh-keys.md` explains in plain language which key reads (deploy), which pushes (push) and which reaches other git hosts (extra keys), what an instance can and cannot do with your code, how to set up pushing, rotate and revoke keys, and how to fix `Permission denied (publickey)`.

- **FLE-301: container idempotency job for the host playbook.** `ci/ansible-idempotency.sh` runs `install/ansible/site.yml` twice in a Debian 13 systemd container and fails unless the second run reports `changed=0`; roles that cannot run in a container are skipped by tag. See `docs/contributing/03-ci.md`.

### Changed
- **FLE-263: `--engine` is deprecated.** `install.sh --engine=ansible` is accepted and ignored with a note (older
  `fleet upgrade` helpers still pass it); `--engine=bash` is refused with exit 64 and a clear message.
- **FLE-263: `fleet upgrade` moves a bash-installed host to Ansible.** On a host recorded as `bash` (or without
  `installed-engine`), the first upgrade to this release runs the playbook, which reproduces the host with nothing
  changed and writes `installed-engine=ansible`. The helper prints `Install engine: bash -> ansible` before the
  backup. A rollback to a release that still has both engines passes `--engine=ansible` on an Ansible host.
- **FLE-263: the app_code role removes the stale prod container cache** before a build, as the bash step did (FLE-281).


- `JobPruner` requires the `ApiCallRepository`, so the terminal-input purge always runs, and the uniform-404 test compares the whole problem body (FLE-289, from the FLE-286 review).

- **FLE-296: short inline help where keys matter.** The project form and the Keys & secrets tab say what Git over SSH in the container allows, the template form says what "Extra keys only" changes, the SSH keys tab lists the three keys in one line each, and a failed push key check says what to do. Each links to the guide.

### Removed
- **FLE-263: Ansible is the only install engine.** The bash installer steps (`install/steps/`), the bash hardening
  (`install/harden/`) and the 0.x `ansible/` tree with its pytest tests and `ci/requirements-infra.txt` are removed
  from `develop` (the 0.x line keeps them on the `legacy` branch). The firewall scripts and units live only in
  `install/ansible/roles/hardening/files/`. The bash `Installer*Test` classes that drove the steps are replaced by
  checks of the roles, the lint (`ansible-lint` production profile, `yamllint`, `--syntax-check` on `install/ansible/`)
  and the existing idempotency job.

### Fixed
- **FLE-304: the guide links on the SSH keys tab, the project and template forms and in flash messages are 44 px tap
  targets on a phone.** They were small inline text links (12 px high) and broke the phone tap-target spec on `develop`.

### Security
- **FLE-297: instance isolation, the db container is no longer reachable from other projects.** DDEV attaches every `db` container to the shared `ddev_default` network with the alias `db`, so an instance without its own database (`omit_containers: [db]`) resolved `db` to another project's database and could read and write it with the default credentials. Fleet now writes `.ddev/docker-compose.zzz-fleet-isolation.yaml` before every start path: the `db` service keeps only the project's own network, and an instance without a db container gets `db` and `ddev-<project>-db` pinned to `127.0.0.1` in every service. It needs DDEV >= 1.24.0 and docker compose >= 2.24.4. After each start Fleet inspects the live db container and fails the job (disconnecting it from `ddev_default`) when the isolation did not take effect. New command `fleet instance:isolate` lists instances deployed before the fix and, with `--apply`, restarts the running ones.

- **FLE-298: deploy guard against privileged compose overrides.** A deploy, redeploy, start or restart is refused, before `ddev start`/`restart` (one shared check), when the instance's `.ddev/docker-compose*` or `config*` files mount the Docker socket, run privileged, share a host namespace, add SYS_ADMIN/NET_ADMIN/ALL, pass devices, run `exec-host` hooks, include other files or mount host paths outside the instance (symlinks and `${DDEV_*}/..` followed). An admin-only, re-authenticated, audited project switch (off by default) allows them. New read-only `instance:audit-overrides`, and `fleet:host:docker-socket [--restore]`; the status watcher checks that the Docker socket is `root:docker` 0660, audits and warns on Settings. See `docs/reference/17-instance-isolation.md`.


- **FLE-300: instance isolation (audit 2026-11-11, R4 R5 R6 R8).** Every instance gets its own Typesense admin and search keys (derived from the project's keys; a redeploy replaces the old search key). Mailpit requires basic auth with a per-instance password shown on the instance page. The web container mounts the router's `traefik/` cache tree read-only, only when the host has Docker 26+ and Compose 2.23+ and `traefik/` already exists in the cache volume (otherwise a job log warning, and the next deploy applies it). The Typesense dashboard service is switched off by default (compose profile `dashboard`); a project setting (*Also run the Typesense dashboard*, `--typesense-dashboard`) turns it back on. The installer adds DDEV's shared `ddev-ssh-agent` container to the omitted containers (merged with any existing list); a manual `ddev auth ssh` no longer works on a Fleet host. Templates have two new options: *Instances have a database* (off: `FLEET_HAS_DB=0` and a poisoned `DB_HOST`) and *An AI agent works in these instances* (instance page warning, `FLEET_AGENT=1`); both are in the export format, the CLI (`--database`, `--agent`) and the API shapes.

### Rollout
- ACTION: after the upgrade run `fleet instance:isolate` to list the instances still exposed, then `fleet instance:isolate --apply` to restart the running ones (ends what runs in their containers; nothing is restarted automatically). New deploys and every start, restart or redeploy get the override on their own.
- Per-instance random database credentials are not part of this change (tracked in FLE-302); the network isolation is the control.

#### Rollout
- Before the first upgrade of a bash-installed host to this release, test it on a throw-away host first: the bash to
  Ansible switch runs the whole playbook on the live host (backup first, then `changed=0` is expected apart from the
  rebuild of the application for the new version).
- Run `sudo fleet instance:audit-overrides` after the upgrade; redeploy of an instance with a socket mount is refused until the project drops it or an admin allows it.
- If the host socket is not `root:docker` 0660, run `sudo fleet host:docker-socket --restore`.
- Projects that ship `exec-host` hooks need `allow_privileged_overrides` switched on before they deploy, start or restart on the new version.
- Rotating `APP_SECRET` changes every Mailpit login: redeploy to apply the new ones.
- Redeploy the instances to apply the new Typesense keys, the Mailpit login and the read-only mount (they are baked at deploy).
- Re-run the installer (`ddev push-product`) so the DDEV global config omits `ddev-ssh-agent`; DDEV stops the container at the next `ddev start`.

## [1.8.0] - 2026-11-11

### Added
- API: `GET /api/v1/instances/{id}/terminal` (scope `terminal:read`): read-only capture of tty1 or tty2, limited by the token's projects and instance patterns, escape-free, masked, capped at 200 lines and 64 KiB, rate-limited and audited (FLE-285).

- API: `POST /api/v1/instances/{id}/terminal` (scope `terminal:write`): types one line of text into an instance's agent pane. Opt-in per project (admin switch behind re-auth, not settable from the CLI, export or import), foreground process must be the agent and is checked again just before the keys are sent, strict text allow-list, 1 call per 2 seconds, 6 per minute per token and 10 per minute per instance, the token's issue rules checked again on every call, one identical 404 for a missing, foreign or unmatched instance, masked text in the evidence, evidence in a new `api_call` table (text included) and an audit entry written before the send (no record, no keys) (FLE-286).

- **FLE-290: a guide to create and use HTTP API tokens** (`docs/guides/09-use-the-api.md`), linked from the README, the docs map and the API reference.

- **FLE-292: a reusable `fleet-api` Claude skill for project orchestrators.** `skills/fleet-api/` holds a `SKILL.md` and a standard-library Python client and CLI for the HTTP API: token check, instances, jobs and job logs, deploy, redeploy, start, stop, destroy (needs `--confirm <id>`, sends `If-Match`) and terminal read. https only, no redirects, `Retry-After` honoured. The project capacity verdict is a clear stub until the endpoint exists. See "Use the HTTP API".

### Changed
- Terminal panes are tagged `tty1` and `tty2` when a window is healed, and read and write find them by that tag, so splitting a pane no longer moves their address. An unterminated OSC payload in a terminal read is cut to the end of its line (FLE-286, from the FLE-285 review).

- **FLE-291: a project-limited API token can read `GET /api/v1/host/capacity?project=<own project>`.** It gets the scoped document (dispatch verdict, its own project's figures, remaining instance-cap headroom), never other projects or host totals; without `project`, or for another project, it is still refused (403). Unrestricted tokens keep the full report.
- **FLE-293:** The `fleet-api` skill's `capacity` command now calls the project-scoped `GET /api/v1/host/capacity?project=` and prints the verdict; `--project` defaults to the token's single project.

### Fixed
- `fleet-api --wait` no longer exits 3 when the answer carries no job id; the literal token is masked in every message.

## [1.7.0] - 2026-11-11

### Added
- **FLE-271:** `fleet ssh:check-push-key [--host=bitbucket.org]` and a "Check the push key" button in the SSH keys view test whether the push key itself is registered on a git host. The test ignores the user's `~/.ssh/config` and any ssh-agent (`-F /dev/null`, `IdentitiesOnly`, `IdentityAgent=none`), so a pinned deploy key can no longer make a missing push key look fine. A clear "push key is not registered on the git host" message is shown in the CLI and the UI.
- The push-key check also runs as a warning (never a failure) when a `container_ssh` project is deployed and when its template is saved, reads the host from the project's remote, caches answers for 60 seconds and ignores system-wide known hosts. The button only offers the hosts of registered projects.
- **FLE-273: the foundation of the HTTP API.** A separate `api` firewall for `/api/`, with service tokens (`flt_svc_` + checksum, only the SHA-256 stored, required expiry, revocation, optional IP allow-list), scopes (`read`, `deploy`, `lifecycle`, `lifecycle:destroy`, `terminal:read`, `terminal:write`) with a project voter, per-token rate limits, a per-address limit on failed authentications, RFC 9457 `application/problem+json` errors, audit entries (`api_token.*`, `api.denied`) and `GET /api/v1/tokens/self`.
- The API never trusts the proxy identity headers, a token in the query string or a session: bearer header only. Every failed authentication looks the same to the caller.
- New reference page `docs/reference/15-api.md`.

- **FLE-274: Caddy route for the HTTP API.** `/api/v1/*` now skips the Authelia login (and only that path; `/api/other` stays gated). The identity strip stays in front of everything, so a forged `Remote-*` / `Remote_*` header never reaches the app. The route is TLS only, caps request bodies at 1 MB, uses a 30-second upstream timeout, sends `Cache-Control: no-store` (replacing whatever the app sends) and no CORS header. A repository test runs the rendered Caddyfile against a real Caddy (`FLEET_CADDY_STOCK_BIN`; Fleet CI fetches a pinned, checksummed Caddy with `ci/fetch-caddy.sh`) and sends forged identity headers, oversized bodies and path tricks.

- **FLE-275: API token management.** `fleet token:create|list|rotate|revoke` (the secret is printed once, on stdout only) and
  **API tokens** in the web interface (`/admin/api-tokens`, admins, password asked again for every change) make, rotate and
  revoke service tokens for the HTTP API. A "Needs attention" panel and `token:list` flag tokens that expire within 14 days or
  were not used for 60 days. Documented in `docs/reference/15-api.md`, including that the failed-authentication limit is per
  client address.

- **FLE-276: API read endpoints.** `GET /api/v1/` `host`, `host/capacity` (with an optional server-side dispatch verdict
  `?project=&template=`), `projects`, `projects/{p}/templates`, `templates/{p}/{t}`, `instances` (with `url`, `created_at`
  and `busy`), `instances/{id}`, `jobs`, `jobs/{id}`, `jobs/{id}/log?offset=N` (tail with `X-Next-Offset`), `upgrade` (offline)
  and `webhooks/log`. Scope `read`; a token limited to some projects sees those projects only. Lists paginate with
  `limit` and `cursor`. See `docs/reference/15-api.md`.

- **FLE-277: API write endpoints.** `POST /api/v1/instances` (deploy, behind the server-side capacity gate),
  `POST /instances/{id}/redeploy|start|stop`, `DELETE /instances/{id}` (needs `lifecycle:destroy`, refuses `main`, requires
  `If-Match` on `created_at`, 428 without it), `POST /instances:destroy-batch` (one job and one result per id) and `POST /jobs/{id}/retry`.
  Each answers `202` with the job to poll, is limited to the token's projects and audited as `api.write`; jobs carry the new
  trigger `api`. No dispatch endpoint: dispatch stays the Jira webhook. See `docs/reference/15-api.md`.

- **FLE-278: OpenAPI description of the HTTP API.** `GET /api/v1/openapi.json` (any `read` token) serves the hand-maintained OpenAPI 3.1 document `app/resources/api/openapi.yaml`; a contract test fails when a route and the document disagree or a real response does not fit its schema. Reference page `docs/reference/16-openapi.md`.
- The token rotate form in the web interface can set the successor's lifetime.

### Changed
- Rotating an API token that was already rotated (in its grace period) is refused.
- `ci/fetch-caddy.sh` re-hashes the cached Caddy tarball and binary on every cache hit instead of trusting a recorded hash; its header says SHA-512. `FLEET_REQUIRE_CADDY=1` makes a missing Caddy fail the real-Caddy test instead of skipping it.
- Docs: `token:create --json` and `token:rotate --json` print the secret; keep it out of CI logs.

- **FLE-282: tests for DriftMask negative cases, the PushKeyCheck cache pool wiring and the API token audit actor; the destroy-batch 207 description says 428/412 are reported per item.**

### Fixed
- **FLE-270: SSH keys review minors (FLE-266)**
  - A template save that names extra keys now checks and stores under the same lock as key creation, import and deletion, so a key cannot be deleted in between.
  - An imported RSA or ECDSA extra key is stored as `ssh/<name>_rsa` / `_ecdsa` (by its real type), no longer as `_ed25519`; existing files keep working.
  - An "extra keys only" start checks the result of `ssh-add -d` and fails if the push key is still in the agent.
  - Rotating a host key writes the same `ssh.key.rotate` audit entry from the UI and the setup wizard.
  - Hand-placed `*_rsa` and `*_ecdsa` key files are now listed as external keys.
  - An extras-only start no longer fails on a host without a push key.

- **FLE-272: the ssh-agent of an instance whose state was "unknown" lost its push key after 24 hours.** The status
  watcher renewed the key lifetime only for instances in state `running`; an instance that `ddev list` omitted or
  reported with an unmapped status kept an agent whose key silently expired (`The agent has no identities`). The
  watcher now also renews the key of an "unknown" instance whose agent still answers. It never starts an agent for one
  that has none, and a stopped instance still has no agent. The 24 h key lifetime stays (a stopped watcher must not
  leave a write key loaded for ever).
- Rotating a token now refuses a revoked or expired one, and audits the end of the old token (`api_token.revoke`, or
  `api_token.expire` with the grace period).
- **FLE-280: `host:drift` hardening.** The report mask now also hides bare `*_key` names (`OVH_CONSUMER_KEY`) and the
  multi-line value of a secret key written as a YAML block scalar. A task failure handled by a block `rescue` no longer
  shows as failed in the report. A repo test fails when a role task with `check_mode: false` uses a mutating module, and the
  job queue's `redeliver_timeout` is now 7200 s, above the 3700 s drift and provision jobs (Doctrine's default 3600 s could
  redeliver a running job).
  Camel-case names (`consumerKey`, `applicationKey`) are masked too. The SSH push-key check from the web (button, template
  save) now uses an 8 s budget instead of 20 s.

- The users list no longer prefetches a user's edit page on hover, which could request a just-deleted user's page from a stale list and log a 404; the setup wizard test host now waits for its php server to exit before removing its home (FLE-283).
- **FLE-284: Renovate no longer bumps `ansible-core` in `install/ansible/requirements-dev.txt`.** The pin matches the ansible-core that Debian 13 ships (the deploy target); a newer core hid a lint failure that broke on 2.19 (FLE-241). A `packageRule` disables it, `requirements-dev.txt` says so, and a repository test pins the rule. Other python ci tooling updates are unchanged.

### Security
- API clients are judged by their IPv4 address when a dual-stack socket reports it as `::ffff:a.b.c.d`, so an IPv4 allow-list
  entry matches; a failed write of a token's last-use time no longer turns a valid request into a 500; token strings are
  marked `#[\SensitiveParameter]`.
- `host`, `host/capacity` and `upgrade` answer 403 `host_wide_not_allowed` to a token limited to some projects; an access rule
  that refuses a token is now audited as `api.denied`.
- `ProjectVoter` no longer grants `PROJECT_DEPLOY` to an API token on a null subject ("any project"): a token always names its
  project (carried over from the FLE-276 review).
- Redeploy over the API validates `branch` (422 on option-like or malformed names); the check lives in `InstanceService::request()`.
- Retrying a destroy job over the API applies the `main` protection and the required `If-Match`.

### Dependencies
- `friendsofphp/php-cs-fixer` ^3.95.29 → ^3.95.30 (#237).
- `community.general` >=14.0.0 → >=14.0.1 for the 0.x `ansible/` tree (#236).

#### Rollout
- Run the database migration (`fleet upgrade` does it): adds the `api_token` table. No endpoint other than `GET /api/v1/tokens/self` exists yet and no token can be created from the UI or CLI yet (follow-up tickets), so the API is not usable from outside until those and the Caddy route land.
- Re-render the Caddyfile and gate (`fleet host:render --apply`; `fleet upgrade` does it). No schema change.

## [1.6.1] - 2026-11-10

### Fixed
- **FLE-281: the installer no longer boots a stale prod container cache.** Step 80 removes `app/var/cache/prod` before the first console command (a changed service constructor made `asset-map:compile` fail during an upgrade). `fleet upgrade` accepts a checkout already at the target commit as a resumed install after a failed install of that target (`checkout.moved` no longer blocks it), and `apt-get update` is retried (3 attempts) when a vendor repository answers a transient error.

## [1.6.0] - 2026-11-10

### Added
- **FLE-260: `apply provision` and the Hardening tab.** The root helper gains a fixed `apply provision` verb (one more sudoers
  line): it validates a staged `host-vars.yml` against the installer's schema, installs it, and runs the installed release's
  own `install/ansible/site.yml` with a fixed command line (no playbook, role or argument comes from the caller). **Settings →
  Hardening** edits the hardening answers (SSH allow list, the account you log in with, trusted addresses, firewall grace
  period, `/tmp`), asks for your password, and applies them as a job with a live log. The tab shows the firewall
  dead-man's switch and tells you to run `sudo fleet-firewall-confirm` from a second SSH session; it never confirms itself.
  Available on hosts installed with `--engine=ansible`.

- **FLE-261: `fleet host:drift`, a read-only drift report.** `sudo fleet host:drift [--json]` runs the installed release's
  playbook with `--check --diff` through a new fixed root-helper verb, `apply drift` (one more sudoers line; it installs and
  stages nothing), and reports per role: unchanged, changed (the diff, secrets masked) or failed. Roles that a check run cannot
  show as a changed task (for example a Caddy build that would be refreshed) say so with a `DRIFT:` debug message, which the
  report counts as drift. Exit code 0 no drift, 1 drift, 2 a task failed, 3 no report. **Settings → Drift** shows the last
  report and a "Run drift check" button (a job). The report says that steps which run a command, such as firewall rules,
  are not visible in check mode. Nothing is scheduled and nothing alerts. Available on hosts installed with `--engine=ansible`.
- **FLE-266: a clean SSH key model.** The host settings have an **SSH keys** tab (`/admin/host/ssh-keys`) and `fleet ssh:key:list [--json]` lists every key (deploy, push, extras) with its purpose, fingerprint, public key (copy button), where it is used and when it was created.
- Extra keys are managed by Fleet: `fleet ssh:key:create|import|delete` and the same actions in the UI (password again; audited). A key file placed by hand keeps working and is listed as "external". A key a template names cannot be deleted.
- A template option **SSH identities** (`--ssh-identities=push-and-extras|extras-only`, `ssh_identities` in the project export): "extras only" keeps the host's push key out of the instance's agent.
- Saving a template whose post-deploy or terminal lines run `ddev auth ssh` or `ssh-add -D` warns that they use DDEV's shared agent.
- Rotating the deploy or push key is available on the SSH keys tab, behind re-authentication.
- New reference page `docs/reference/14-ssh-keys.md`.

### Changed
- **FLE-259: `fleet upgrade` re-runs the engine that installed the host.** Both installer engines now record
  `bash` or `ansible` in `/etc/ddev-fleet/installed-engine` (a host without the file is `bash`). `sudo fleet upgrade`,
  and a rollback with `--to`, read it and run the target tag's `install.sh` with `--engine=ansible` on an Ansible host,
  so the playbook of that tag runs again; a target without the Ansible engine is refused before any service is stopped.
  The pre-flight checks, the backup and the history are unchanged. The Upgrade page shows the install engine.
- **FLE-267: the guides say where the Claude token comes from.** "Work with agents" and the install guide now start with `claude setup-token` (run on a machine with Claude Code and a browser; the installer does not install the Claude CLI on the host).
- **FLE-269: the documentation no longer carries a front matter block.** The `Author` / `Reviewer` / `Last updated` / `Type` YAML header is gone from `README.md`, `CLAUDE.md` and every page under `docs/`, so GitHub no longer renders it as a table at the top of each page; the generated `docs/reference/01-cli.md` does not emit it either. `DocsStructureTest` now checks that no page starts with front matter. The GitHub issue templates and the Claude `SKILL.md` files keep theirs, because GitHub and Claude read it.

#### Rollout
- ACTION: run `sudo fleet upgrade` on the host before using the Hardening tab: the new `apply provision` verb and its sudoers line arrive with the upgrade.
- Run the database migration (`fleet upgrade` does it): adds `ssh_extra_key` and `template.ssh_identities` (existing templates keep push key and extras).

## [1.5.3] - 2026-11-10

### Added
- **FLE-241: Ansible provisioning spike (PR #235).** Ansible roles `packages`, `docker` and `hardening` under `install/ansible/`, verified to describe a bash-installed, hardened host with zero changes. The installer does not run them unless asked (see FLE-258).
- **FLE-255: Ansible roles `fleet_user`, `shell` and `ddev`.** Ports of install steps 30, 35 and 40 under
  `install/ansible/`, run after `docker` and not yet called by the installer. The `hardening` SSH drop-in no longer
  deletes a good existing drop-in when validation fails, and sshd's ports are re-checked after the reload.
- A tag-limited Ansible run of another role (`--tags ddev`) no longer reaches the `hardening` role's settings validation.
- Ansible roles `caddy` and `authelia` (plus the helper role `apt_repo`) in `install/ansible/`, ports of `50-caddy.sh` and `60-authelia.sh`. Not run by the installer yet. (FLE-256)
- **FLE-257: Ansible roles for the application layer** (inert, the bash installer is unchanged). `php_app`, `app_code`,
  `privileged` and `units` under `install/ansible/` port `install/steps/70`, `80`, `85`, `90`, `95` and `98`: the
  environment files (secrets generated once), the php-fpm pool, the build and migrations, the root helpers and sudoers
  (validated with `visudo -cf`), the unit files and pinned Mercure hub, and the start with a health check. They write
  `installed-version` and `installed-revision` from `fleet_ref`. Services restart only when their code, environment or
  unit changed.
- **FLE-258: `install.sh --engine=ansible`** (opt-in; the bash steps stay the default). Stage 2 installs `ansible-core` and
  `python3-apt`, writes `/etc/ddev-fleet/host-vars.yml` from the flags (`install/lib/host-vars.sh`) and runs
  `install/ansible/site.yml`. `App\Fleet\System\HostVarsRenderer` renders the same file for the app. The schema is in
  `docs/reference/04-installer.md`; `--preflight` works with both engines.
- **FLE-264: AI usage policy.** `AI_POLICY.md` explains how Fleet is built (AI agents directed and reviewed by one maintainer), how AI involvement is marked (commit trailers, pull request notes), what contributors who use AI tools must disclose, and the licensing and security stance. The README's AI disclaimer now links to it, and so does `CONTRIBUTING.md`.

### Changed
- **FLE-237: the documentation is rewritten in layers.** The README now pitches Fleet with a three-command quick start, `docs/concepts.md` and eight task guides come first (why and what before how), and the exact behaviour moved to `docs/reference/`. Contributor pages live in `docs/contributing/`; `CONTRIBUTING.md` is a short pointer. The Python 0.x documentation (`docs/v0/`) and the legacy Ansible note are removed; the `legacy` branch holds that line.
- **FLE-237: generated CLI reference.** `docs/reference/01-cli.md` is generated from the console definition by the new `fleet:docs:cli` command (`composer run docs:cli`), and `CliReferenceTest` fails when it is stale.
- **FLE-238: the systemd security check scores the 1.x units.** `ci/systemd_security.py offline` now runs
  `systemd-analyze security --offline=yes` on the unit files the installer ships (`install/etc/*.service`,
  `install/harden/etc/*.service`, the Caddy sandbox drop-in) and compares them with a new baseline
  (`ci/systemd-security-baseline.json`, same regression threshold). `SYSTEMD-SECURITY-REPORT.md` is regenerated
  from it and names no host (`python ci/systemd_security.py report`).
- **FLE-253: documentation polish.** The files in `docs/guides/`, `docs/reference/` and `docs/contributing/` are numbered (`01-`, `02-`, ...) so they sort in reading order, and `guides/` and `reference/` have a `README.md` index. The example project is `website` instead of `shop`. The tone no longer promises to remove human work: Fleet lets you choose what to automate. The tmux workspace has its own highlighted section in the README and the guide `docs/guides/05-work-with-agents.md`.

### Removed
- **FLE-238: the Python-era scoring paths.** The live check over SSH (`ci/systemd-security-live.sh`, the
  `systemd-security-live` custom pipeline, the `security_probe` Ansible role and `security-probe.yml`), the Jira
  alert, the weekly report commit and the scoring of the 0.x Ansible templates are gone; they stay on `legacy`.

### Fixed
- **FLE-220: a webhook push that arrives while its instance is busy is no longer dropped.** A fixed-label or `--reuse`
  rule used to record `Busy` and start nothing, so a second merge during a CI run left the newest commit without a
  `fleet-gates` status. The redeploy is now kept as a pending request per instance (the latest push wins) and one
  follow-up redeploy is queued when the active job ends. See `docs/reference/11-webhooks-and-schedules.md`.
- **FLE-236: the installer records the release tag when HEAD is exactly one.** Re-running `install.sh` without `--ref`
  (for example to change `--harden` options) wrote `main@<sha7>` to `installed-version` even when the checkout was exactly
  `v1.5.0`, so `fleet upgrade --check` said "not a release". It now records the tag (`git describe --exact-match`).
- **FLE-239: stale documentation paths re-pointed**: the 0.x Ansible comments and `bitbucket-pipelines.yml` now cite the current pages (`docs/reference/09-networking.md`, `docs/contributing/03-ci.md`) or the `legacy` branch for 0.x-only docs; the generated `docs/reference/01-cli.md` no longer carries a hardcoded `Last updated` date, so its output is deterministic.

### Dependencies
- PHP dev tooling: `friendsofphp/php-cs-fixer` 3.95.28 -> 3.95.29 (#232); `phpstan/phpstan-doctrine` 2.1.1 -> 2.1.2 (#233).

## [1.5.2] - 2026-11-06

### Added
- **FLE-229: port access is editable from the project's Network tab.** The access of a port the project uses (login required or public) can be changed in the browser with the rules of `fleet port:update`; the Public badge is drawn in the warning colour. Adding a hostname, assigning or unassigning a public port and any access change ask for a confirmation in an accessible dialog first, and the changes are written to the audit log (`port.access`, `project.hostname`, `project.port`).

- **FLE-230: the project Access tab lists who can reach the project.** It shows the project members (site-only users marked), the administrators, the public or login access of each port of the project (read-only; edit it on Network) and a note on the Authelia sign-in. Members can be added and removed there (removal asks first). It uses the same service as the user page and `fleet:user:grant` / `fleet:user:revoke`, so a membership changed on either page shows on the other; each change from the tab is recorded in the audit log (`project.member.add`, `project.member.remove`).
- **FLE-233: webhook rules can be added and removed on the project's Integrations tab.** The form takes the options of `fleet webhook:rule:add` (including `reuse`, `label` and `label-prefix`) and is checked by the same service, so a rule is accepted or refused exactly as on the command line. Both changes are audited (`webhook.rule.add`, `webhook.rule.remove`; the command now records them as `cli`).

### Changed
- **FLE-226: the project settings page has ten tabs** (Overview, Source, Templates, Assets, Network, Access, Capacity, Keys & secrets, Integrations, Transfer & danger). `/admin/projects/<name>` is the new read-only Overview; the former General form is on Source (`/source`). Below the `sm` breakpoint the tabs are a labelled select. Access, Capacity and Transfer & danger are placeholders for now. The old `/ports`, `/secrets` and `/webhooks` addresses redirect to Network, Keys & secrets and Integrations.
- **FLE-227: project settings, Source tab and default template**: the Source tab now groups the repository (Git URL, default branch, submodule shown), the git identity and the issue id pattern under headings; the default template is chosen on the Templates tab, next to the template list; template forms have Environment, Commands and Terminals sections.

- **FLE-229: the Network tab holds the additional hostnames, the Typesense toggle and the named ports** in one form (`NetworkType`), saved without touching the other project fields. The hostnames and the Typesense toggle are gone from the Source tab of an existing project (the create form keeps them). The command line is unchanged.
- **FLE-231: project settings, Capacity tab**: the project's CPU threads and slots are now edited on the Capacity tab (they leave the Source tab; the create form keeps them). The tab also lists every template's own caps with the effective values after clamping by the project caps, links each row to the template form, and warns when the templates' own caps add up to more than the project cap. Enforcement is unchanged.

- **FLE-232:** Project Keys & secrets tab: the "Git over SSH in the container" setting moved here from the Source tab. It states the risk, asks for a confirmation and writes an audit entry (`project.container-ssh`) when it changes. The tab also lists each template's extra SSH key names (read-only) and links to the host settings for key rotation.
- **FLE-232:** Secret values are entered in a masked field (a separate textarea for multi-line values) and are never echoed back.
- **FLE-232:** Turning on Git over SSH in the container needs the server-side confirmation and a recent re-auth, like revealing a secret; turning it off needs neither.
- **FLE-233: Integrations tab.** Rotating a webhook secret, removing a rule and removing the pipeline token ask in a confirm dialog instead of a `<details>`; the delivery log has its own "Delivery log" heading and is collapsed until it is opened or filtered.
- **FLE-234: the project Transfer & danger tab holds Export and Delete.** The Export card (optional passphrase, masked, with the minimum length shown; a passphrase still needs the recent password check) and the Danger zone moved there from the Source tab. Deleting a project now asks you to type its name; a wrong or missing name leaves the project untouched, and the delete is still refused while the project has instances.

### Security
- **FLE-227: a Git URL with a user name or token (`https://user:token@host/...`) is refused with a message that says so**; the URL is never written to a log or an audit entry.
- **FLE-228: Reveal secret and the sealed Export need a recent password check.** A 5 minute re-auth window
  (`/admin/reauth`: CSRF, 5 attempts per 15 minutes per user) gates revealing a secret value and exporting a project with a
  passphrase; an export without a passphrase is unchanged. Success, failure and each stopped action are audited
  (`reauth.success`, `reauth.failure`, `reauth.required`), never with a password or a value. The export passphrase field
  now states its minimum length.

### Fixed
- **FLE-235:** The re-auth limiter counts only failed password attempts and is keyed on user and client address, so a hijacked session can no longer lock the real administrator out and successful confirmations never use the budget.
- **FLE-235:** A sealed export, turning on Git over SSH in the container and deleting a project now close the re-auth window; `docs/secrets.md` notes that an Authelia-only user without a fleet account cannot re-authenticate.
- **FLE-235:** Playwright: the secret save helper waits for the fresh page instead of submitting a form Turbo was about to replace (flaky 422 on phone).

## [1.5.1] - 2026-11-06

### Added
- **FLE-208: documentation page for CPU allotments, slots and slices.** `docs/capacity.md` describes the allotment
  model, slots at project and template level, the slice tree, the slot environment variables, the web UI and the
  CLI options (`--cpu-threads`, `--slots`, `fleet host:capacity`, `fleet host:slices`).

### Changed
- **FLE-223:** Renovate updates the PHP dev tooling group with `--with-all-dependencies`, so a tool needing newer Symfony components gets a consistent `composer.lock` (#189 failed on a stale lock).

### Fixed
- `install --harden`: on the first run the named-port sync (Typesense 9108 and others) now runs again right after `ufw --force enable`, so the ports are open at once instead of about a minute later. The false "named-port sync failed" warning before the enable is gone.
- `fleet-firewall-sync` no longer fails when `/etc/ddev-fleet/hardening.conf` does not exist yet (the installer's first sync runs before that file is written); this was the cause of the first-run failure.

### Dependencies
- `phpstan/phpstan-doctrine` ^2.1.0 -> ^2.1.1 (#214)
- `ansible-lint` >=26.10.1 -> >=26.11.0 for the CI tooling (#194)
- Ansible collection `community.general` >=13.5.1 -> >=14.0.0 (#148)
- Symfony UX (`stimulus-bundle`, `ux-icons`, `ux-live-component`, `ux-turbo`, `ux-twig-component`, `ux-toolkit`) 3.6 -> 3.7, with the `symfony/*` components at v8.1.11 (#190)
- `friendsofphp/php-cs-fixer` ^3.95.27 -> ^3.95.28, with `symfony/console`, `symfony/process`, `symfony/stopwatch` at v8.1.11 and `ergebnis/agent-detector` 2.0.0 (dev) (#189)

## [1.5.0] - 2026-11-06

### Added
- **FLE-203: capacity in the web UI.** The host settings page has a **Capacity** tab: threads, cores, RAM, the reserved
  threads and the instance cap (both editable), the allocatable and committed threads with a warning when the project
  allotments add up to more than the host can allocate, the job concurrency setting, a table of every project and its
  templates (allotment, inherited marker, slots with the number busy, live use, throttling) and the instances that do
  not run under a CPU slice yet. The project and template forms gain **CPU threads** and **Slots** fields (empty =
  unlimited for a project, inherit for a template), with the refusals of the capacity rules shown under the field.
- **FLE-215: webhook deploy rules can redeploy in place (`fleet:webhook:rule:add --reuse`)**: a Bitbucket deploy rule with `--reuse` redeploys the instance of the branch when it already exists, instead of creating `<label>-1`, `-2`, ... on every push (the duplicate CI instances seen on ddev5). Rules without the flag and Jira dispatch rules behave as before.

- **FLE-219: Docker network pools, live-restore and log rotation (installer step 20).** Every instance makes a Docker bridge network and Docker's built-in pools hold about 30 of them, so a host could not run more than about 29 instances (`ddev start` failed with "all predefined address pools have been fully subnetted"). The installer now merges `/etc/docker/daemon.json` (operator keys and already-set values are kept): Docker's built-in pools first, then one pool of `/24`s in `10.64.0.0/10` (about 16000 networks; skipped with a warning when the host routes that range), `live-restore: true` and `json-file` log rotation (10 MB x 3). On a fresh install the file is written before Docker first starts. On a host that already runs Docker the installer never restarts it; it prints a note until Docker has been restarted.
- `fleet host:capacity` reports `docker_networks` `{used, max}` (schema 1, additive), and `instances.max` is now the smaller of the configured cap and the networks left plus the ones the instances hold.

#### Rollout
- Re-add the Fleet CI rules (`feature/`, `release/`, `hotfix/`, `renovate/`) on ddev5 with `--reuse` (the orchestrator does it).
- ACTION: on an existing host, restart Docker once at a quiet moment so it reads `/etc/docker/daemon.json`: `fleet instance:stop --all`, `sudo systemctl restart docker`, `fleet instance:start --all` (see docs/upgrading.md, "Docker network pool"). Until then the host holds about 30 instances and `fleet host:capacity` shows `docker_networks.max` 31.

## [1.4.0] - 2026-11-05

### Added
- **FLE-199: capacity allotments, domain and CLI (epic FLE-197).** Projects and templates get `cpu_threads` and `slots` (empty = unlimited on a project, inherited on a template); the host settings get `cpu_reserve_threads` (default 2) and `max_instances` (default 25). `fleet project:create|update` and `fleet template:create|update` take `--cpu-threads=N|none` and `--slots=N|none`, and the show commands and JSON print them. A template above its project's value is refused, and lowering a project below one of its templates is refused naming the template. Nothing is enforced yet.

- **FLE-200: host capacity report and CPU usage sampler (epic FLE-197).** `fleet host:capacity [--json] [--reserve=N] [--max-instances=N]` prints the detected threads, cores and RAM, the reserve, allocatable threads, the effective CPU limit of every project and template, what is committed (with an overcommit flag), the instance count against its cap, live use, and the busy slots read from the lock directories `/srv/fleet/slots/<project>/<template>/` and `.../<project>/_project/`. The JSON is schema 1 (additive changes only) and the exit code is 0. The status watcher now samples the CPU use of the `fleet.slice` subtree from cgroup `cpu.stat` every 10 seconds (read-only, no root). Nothing is enforced yet.

- **FLE-201: capacity S3, the `slices` target of the root helper (epic FLE-197).** `apply slices` installs the systemd slice tree of the CPU allotments: `fleet.slice` (allocatable threads), `fleet-<project>.slice` and `fleet-<project>-<template>.slice` (`-` in a name becomes `_`), as unit files plus `10-fleet-quota.conf` drop-ins. The helper takes data only (`slices.json`, a list of `{unit, quota_percent|null}`), validates every unit name and quota (an integer from 100 to threads x 100), generates the files itself, removes only the slices it wrote earlier, starts every slice before any container and applies each quota to the running slice (`systemctl set-property --runtime`), so a changed limit is live without restarting a container. A failure restores the previous files and quotas. The app syncs the slices after every project or template create, delete and limit change (a failure is a warning); `fleet host:slices [--print]` installs them on demand. Nothing puts a container into a slice yet. On a host whose installed helper predates `slices`, the sync does nothing and stays silent until the installer has run; the uninstaller stops and removes the managed slices.

- **FLE-202: capacity S4, instances join their CPU slice and get the slot environment (epic FLE-197).** A deploy and a restart write `.ddev/docker-compose.fleet-cgroup.yaml`, a compose override that sets `cgroup_parent` to the template's slice on every service of the instance (web, db, add-ons, profile services such as xhgui). The service list comes from `ddev debug compose-config`, read after the assets are seeded and before `ddev start`, and the file is kept out of git status. After the start, the slice that carries the limit is checked: a missing slice or one with no quota (`cpu.max` is `max`, which Docker and systemd create silently) is reported in the job log. The override is written only when the installed root helper has the `slices` target; on a host that has not been upgraded, instances deploy exactly as before. `config.fleet.yaml` now exports `FLEET_PROJECT_SLOTS`, `FLEET_TEMPLATE_SLOTS` (empty = unlimited), `FLEET_TEMPLATE_CPU_THREADS` (effective allotment), `FLEET_SLOT_DIR` and `FLEET_PROJECT_SLOT_DIR` (`<FLEET_HOME>/slots/<project>/<template>/` and `.../_project/`, created at deploy); a change reaches a running instance at its next restart or redeploy. `fleet:host:capacity --json` lists the instances without the override under `instances.not_under_slice` (additive, schema 1).

- **FLE-209: jobs of different instances can run at the same time.** A new host setting, **Jobs at the same time**
  (Jobs tab of the host settings, or `fleet fleet:host:job-concurrency N`; 1 to 8), lets the single worker start each
  job as a child process (`fleet:job:work`) instead of running them one after another. Jobs of one instance still never
  overlap (instance lock), a job past the limit stays `queued` until a slot frees up, and the SQLite queue keeps its one
  consumer. Before, a CI host deployed strictly one instance at a time whatever its size.

- **FLE-211: opt-in host hardening for 1.x hosts (`install.sh --harden`)**: key-only SSH with `MaxAuthTries 3` and
  `AllowUsers` (checked with `sshd -t`, session-safe), fail2ban with `ignoreip` from `--trusted-ip`, a UFW firewall
  (SSH, 80, 443 and Fleet's named ports, kept in step by a one-minute timer, plus a `DOCKER-USER` guard) behind a
  dead-man's switch (`fleet-firewall-confirm`), LLMNR off, a sysctl drop-in and auditd rules; `/tmp` mount options only with the separate `--harden-tmp`. The run is refused
  when the account in use has no SSH key or another account with keys would be shut out.
  `--dry-run` lists every change. Off by default: a plain install or upgrade changes none of it. The choices are stored
  in `/etc/ddev-fleet/hardening.conf` and re-applied by later runs. See `docs/installation.md`, section 8.

### Changed
- **FLE-202: the 1.x assets seed skips legacy CPU cap files.** Any file under the instance's `.ddev/` matching `docker-compose.*cpu*.yaml` (for example `docker-compose.fleet-cpu.yaml`, `docker-compose.cpu-limit.yaml`) is no longer copied, so a per-container `cpus:` from the config repo never stacks with the slice quota. The config repo keeps the files for the legacy 0.x line.

- **FLE-210: `start --all` / `stop --all` on an empty host use a dedicated `NoInstanceMatches` exception instead of matching the error text** (internal; messages and exit codes unchanged), with tests for `stop --all` and `--format=json`.
- **FLE-212: capacity `busy` follow-ups (epic FLE-197).** `fleet host:capacity` now counts a pool as busy when its CPU slice used at least 0.2 threads over the last sample, even without a slot lock (the `_project` pool reads the project slice minus its templates), reads slot locks from `/proc/locks` instead of probing with `flock` (a probe could make a runner's `flock -n` fail and missed shared-lock holders), reports unreadable lock files and directories (`unreadable_locks`, additive), takes the host thread count from `nproc --all`, and documents `used_cores: null` as unknown. The JSON stays schema 1.

- The installer waits, up to 300 seconds, for running jobs to finish before it swaps the code and migrates (`FLEET_INSTALL_JOB_WAIT=<seconds>`, `0` skips the wait).

### Fixed
- **FLE-193: the installer and `fleet upgrade` no longer depend on a live GitHub download of the Tailwind CLI**: step 80 now keeps the binary in a host cache (`/srv/fleet/cache/tailwind/<version>/`), downloaded once with retries and a SHA-256 check against the release's `sha256sums.txt`, and seeds `app/var/tailwind/<version>/` from it before `tailwind:build`. A re-run or upgrade with GitHub unreachable succeeds when the cache holds the configured version; a version bump downloads once and drops the old version from the cache. If the cache cannot be filled the installer warns and the bundle tries its own download.
- **FLE-213: jobs handed to a child process are no longer lost or double-counted across a worker restart.** The worker stamps a job (`job.spawned_at`) before it starts its child, so a restarted worker counts children that have not claimed their job yet and no longer overshoots *Jobs at the same time*. A child that dies before claiming its job is detected after about a minute (before: up to 40 minutes, by the orphan reaper) and the job is queued again.


- At the default job concurrency (1) a job no longer waits behind a `running` job of another process; only the instance lock serialises jobs, as before concurrent jobs (FLE-209). With the `sync://` transport of dev and test (several requests at once) the job used to fail with "The instance is busy and this queue cannot wait for it", which turned `instances.spec.ts` red on `develop`.
- **FLE-216: the Typesense port (9108) is public again, so the browser search works on 1.x.** Every named port was behind
  Authelia, and the browser's CORS preflight (`OPTIONS`, no cookies) got a 302 to the login: the search widget showed
  nothing. Named ports now have an **access** setting, `gated` (default, Authelia and the project group) or `public`
  (no login; the identity headers are still stripped). `typesense` is public by default, as in 0.x (its search-only key
  protects it); `ts-dashboard` and `playwright` stay gated. `fleet:port:create --access=`, the new `fleet:port:update
  <name> --access=`, `fleet:port:list` and the project export/import (`ports[].access`, optional) carry it.

The CORS headers come from Typesense's own `Access-Control-Allow-Origin` answer; Caddy adds none.

- **FLE-217: instance ids stay short enough for `ddev-<id>-<service>` to fit in 63 characters, `-N` suffix included.** DDEV container names longer than a DNS label cannot be resolved by Docker, so the router answered 502 for add-on services (Typesense on a `-1` instance, `typesense-dashboard` always). For a project with Typesense, webhook-derived labels keep 20 characters for the service name and room for a `-999` suffix (never cut below 12 characters), and a deploy from the CLI or the UI (single, batch, `-N` allocation) is refused with a clear message when the id would be too long. Fixed-label webhook rules and existing instances keep their ids, so nothing is recreated. An existing over-long instance needs a redeploy under a new label: `fleet instance:list` warns about them. The 20-character budget covers `typesense-dashboard` (19), the longest add-on service Fleet ships.

- Routing parity with 0.x (FLE-218): instance sites and named ports send `X-Robots-Tag: noindex, nofollow` again, and an unknown host name on a named port (for example `bogus.<domain>:9108`) answers `404` instead of Caddy's empty `200`, from one shared catch-all file `instances/ports-fallback.sites`. Rollout: takes effect at the next site render (a redeploy, or `fleet port:refresh`); a host upgrade re-renders the main Caddyfile.

#### Rollout
- One migration adds nullable columns and two defaulted host columns; it runs with the normal upgrade and changes no data.
- No migration and no new unit; the status watcher picks the sampler up at the next restart of `fleet-status.service`.
- The new helper and sudoers line arrive with the installer (`ddev push-product`). Afterwards run `fleet host:slices` once to create the slices of the existing projects and templates.
- Instances deployed before this release have no slice until they are redeployed or restarted; `fleet:host:capacity` lists them as `not_under_slice`.
- Run `fleet host:slices` once after the installer has upgraded the host (FLE-201), before redeploying instances, so the slices exist when their containers start.
- The default is 1 (today's one-after-another behaviour). To use it, set the value per host: `fleet fleet:host:job-concurrency 3` or the Jobs tab. No restart: the worker reads it on its next job.
- The upgrade adds a column to `host_settings` (migration `Version20261105120000`).
- Jobs started as child processes survive a worker restart, the hourly time-limit exit and an upgrade (the worker unit has `KillMode=process`); a new worker counts them from the `running` job rows. A child that dies before claiming its job gets the job re-queued at once. A reboot still interrupts running jobs.
- Nothing changes on upgrade. Hardening is applied per host by the operator, with the owner's OK (the shared dev host has colleagues).
- To test a host: open a second SSH session, run the installer with `--dry-run --harden --trusted-ip=<your ip>`, then without `--dry-run`, then run `sudo /usr/local/sbin/fleet-firewall-confirm` from the second session within 10 minutes.
- No migration; the status watcher is unchanged.
- The upgrade adds a column to `job` (migration `Version20261105130000`).
- Upgrade at an idle moment: a job still running when the installer replaces the code runs on against the new code and may fail (retry it). The installer waits up to five minutes for running jobs first.
- The upgrade adds `named_port.access`, sets the existing `typesense` port to public (migration `Version20261105160000`)
  and then runs `fleet:port:refresh`, which rewrites the instance Caddy sites: nothing to do by hand. `fleet:port:update`
  also rewrites them.
- Check: `curl -X OPTIONS -H 'Origin: https://resources-<host>' -H 'Access-Control-Request-Method: POST' https://<host>:9108/multi_search` answers 200, `:9111` and `:9325` still redirect to the login.

## [1.3.1] - 2026-11-05

### Fixed
- **FLE-195: `fleet:instance:start --all` (and `stop --all`) exits 0 with "nothing to start" on a host with no instance at all**, so `fleet-boot.service` no longer ends `failed` on a fresh install. An explicit id, a project or a state matching nothing still fails.
- **FLE-196: `fleet-boot.service` no longer blocks Authelia after a reboot (regression of FLE-4)**: the unit is now `Type=exec` instead of `Type=oneshot`, so `multi-user.target` does not wait for the sequential start of all instances and Authelia (and every public URL behind it) comes up within about a minute of boot.

#### Rollout
- Existing hosts pick up the new unit at the next installer run (`ddev push-product`). Until then a reboot still delays Authelia until every instance is started.

## [1.3.0] - 2026-11-05

### Added
- **FLE-190: extra SSH keys per template.** A template can name extra keys (`ssh/<name>_ed25519` in the fleet home) that
  its instances get in their own ssh-agent next to the push key, so an instance can reach another host without a
  hand-written deploy step. The keys are loaded on deploy, start, restart and on the status watcher's refresh, so they
  survive an agent restart; an instance of another template of the same project does not get them. Set with
  `fleet template:create|update --ssh-key=<name>` (repeatable, `--unset=ssh-keys` clears), the template form, and the
  project export/import (`ssh_keys`). The names are allow-listed (never the deploy or push key), the file must exist with
  mode `0600` and be owned by the fleet user, and the project needs `container_ssh`. No key content ever reaches the
  database, a command line or a log.
- **FLE-190: `fleet ssh:trust <host>`** adds a server's host keys to the fleet's known hosts after you compared the
  fingerprints (a prompt, or `--fingerprint` for scripts), so instances trust that host from their next deploy.

### Fixed
- **FLE-192: a template's Drupal environment is applied again when the project's assets hold a `.env`.** The
  assets seed copied the project's own `.env` over the checkout after the template's `DRUPAL_ENV` had been
  written, so the instance kept the asset's value (for example `dev` instead of `staging`). `DRUPAL_ENV` is now
  set after the assets seed and before `ddev start`; the rest of the file is kept.

#### Rollout
- A database migration adds `template.ssh_keys` (empty for every template); it runs with the upgrade.
- ACTION: redeploy the instances whose template sets a Drupal environment and whose project has a `.env` in its
  assets; until then they keep the asset's value.

## [1.2.2] - 2026-11-04

### Added
- **FLE-177: the app warns when Let's Encrypt refuses certificates.** A new timer job (`fleet-tls-check.timer`, every 10 minutes) reads Caddy's journal for rate limits (`rateLimited`, HTTP 429, "retry after") and failed challenge solves, counts the certificates issued in the last 7 days, and checks the expiry of the certificate served for the host name. It writes a small status file under `FLEET_HOME`. Administrators see a red banner on every page when Let's Encrypt is rate limiting this host (with the retry time) and an amber one for failed solves or a certificate that expires in under 14 days; the new **Certificates** tab on the Host page lists the last errors, affected hostnames and the 7-day count against the 50-per-week limit.
- `fleet host:tls-status` (`--json`) prints the same status for scripts and watchdogs: exit 0 ok, 1 warning, 2 error.

### Changed
- **FLE-174: faster Fleet CI.** `ci/run-gates.sh` runs the PHPUnit suites and the static checks as parallel lanes, installs
  npm and Chromium beside the composer gates, runs the infra tests beside Playwright, and reuses `node_modules` and the Python
  venvs when their lock files are unchanged. The report adds the 20 slowest PHPUnit tests (`slowest-phpunit.txt`).

### Fixed
- **FLE-175: a redeploy no longer kills a running tty1/tty2 command.** The instance's tmux window is kept (healed, not reset); a pane running a command (non-shell foreground, or a shell with child processes) is left alone and logged as "ttyN busy, not restarted", while idle panes get the template commands typed as on a deploy. 0.x parity: it typed nothing into an existing window.
- **FLE-176: in `ovh_dns` mode Caddy no longer orders one certificate per instance host name.** The host is now one
  wildcard site (`*.<domain>`, one certificate), each instance a `host` matcher and `handle` inside it, so a new
  instance neither hits Let's Encrypt's 50-certificates-per-week limit nor needs a Caddy reload that interrupts a
  DNS-01 solve. `http01` mode is unchanged. The wildcard site asks for `*.<domain>` only (the bare domain keeps its own
  certificate), the same name set 0.x used, so Let's Encrypt treats it as a renewal. A name that already holds a valid
  stored certificate (list written by the root helper, `/etc/caddy/fleet/stored-certs.list`) keeps its own exact-name
  site until that certificate is within 30 days of expiry, then moves under the wildcard. Named-port sites move to
  `instances/<id>.sites`. A rewrite that changes nothing no longer reloads Caddy.


- A detached `fleet` tmux session keeps a usable size: `default-size 300x80` and `window-size latest` are set with the other session options (re-applied on every `fleet:tmux:ensure`), so new windows are no longer 80x24 with ~14-column shell panes; an attached client still drives the size.

- **FLE-188: new deploys failed at `ddev start` on v1.2.0/v1.2.1** (`GIT_SSH_COMMAND` in the instance's `web_environment` contained double quotes and a `$DDEV_APPROOT`, so DDEV generated an invalid `.ddev-docker-compose-base.yaml`). The command now uses the literal container path of the pinned known_hosts file (the container's approot plus `.ddev/fleet_known_hosts`); a regression test checks that no `web_environment` entry holds a quote or a `$`.


- A git identity name with a double quote, `$` or backslash is refused in the project and host settings (and by the CLI), and a stored one fails the deploy with a clear message: DDEV writes `web_environment` into its compose file unescaped, so such a value made every `ddev start` fail.

#### Rollout
- ACTION: upgrade when the weekly Let's Encrypt window has room: the host needs one new wildcard certificate (and one for the bare domain). If the account is rate limited Caddy retries with backoff and the instances are not served over HTTPS until it succeeds.
- The old per-name certificates are no longer used; Caddy keeps them in its storage until they expire.
- Delete the stale `_acme-challenge` TXT records left in the OVH zone by interrupted solves (one-off, by hand).
- ACTION: after the upgrade, check that the new timer runs: `systemctl list-timers fleet-tls-check.timer` (the installer enables it); `fleet host:tls-status` reports a warning until the first check has run.
- Instances deployed with v1.2.0/v1.2.1 have a broken `.ddev/config.fleet.yaml`. `start` does not re-render the file; only a redeploy (full deploy workflow, new checkout) or a `restart` job (queued by the Claude-token apply action) rewrites it. Redeploy each affected instance, or rewrite its `.ddev/config.fleet.yaml` by hand (`GIT_SSH_COMMAND=ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=<container approot>/.ddev/fleet_known_hosts`) and run `ddev restart`.

## [1.2.1] - 2026-11-04

### Changed
- **FLE-172: Fleet CI runs less Playwright by default.** The phone project runs only when the diff touches interface code,
  and the quick tier adds back the `*-coverage` / `*-validation` specs whose area the diff touches (`ci/pw_select.py`,
  `FLEET_PW_PHONE`, `FLEET_PW_TARGETED`). `ci/run-gates.sh` reports the choice and the 20 slowest Playwright tests.

### Fixed
- **FLE-171: a template's `tty1`/`tty2` commands are typed only after post-deploy succeeded.** They were typed as soon as `ddev start` was up, so they raced the post-deploy lines (regression vs 0.x): a CI template's tty1 (`bash .ddev/ci/pw-ci.sh --full`) ran PHPUnit against a half-installed site, hit an "infrastructure failure" and posted no status. They are now typed as the last step of the deploy or redeploy, after `deploy complete`; if a post-deploy line fails nothing is typed and the log says `tty commands not started: post-deploy failed at '<line>'`. A plain `instance:start` still types nothing (as before).
- **FLE-173: `ci/upgrade-rehearsal.sh` runs on a real host.** The first step no longer fails as root with git's "dubious ownership" on the fleet-owned `/opt/ddev-fleet` (the clone now runs as the checkout's owner), and the run points the checkout's `origin` at the throwaway release repository, as the `checkout.origin` pre-flight check requires, and puts it back afterwards.
- **FLE-173: the rehearsal always puts the host back.** Its clean-up ran from an exit trap after the function that owned its variables had returned, so under `set -u` it died at once and left the `FLEET_UPGRADE_REPO_URL` line and the changed `origin` behind. It now runs before the script returns (and from the trap on `exit`, INT, TERM and HUP), restores the `origin` the host had, and verifies each step.
- **FLE-173: `sudo fleet upgrade --repo-url=<local path>` works when that repository belongs to another user.** The root helper's tag re-check (and installer fetch) against that path was refused by git as "dubious ownership" and reported as a moved tag. The helper now trusts exactly that path for its own git calls through a private, temporary git config, and shows git's message when the re-check fails.

## [1.2.0] - 2026-11-04

### Added
- **FLE-149: the installer records what it installed and can check the host first.** `/etc/ddev-fleet/installed-version` holds the release tag (or `<ref>@<sha7>` when `--ref` is not a tag), written after the health check so a failed run keeps the old value. New `--preflight` flag (`run.sh`, mirrored in `install.sh`): read-only, no root, prints `BLOCK|WARN|INFO <id>: <message>` lines and exits 0 or 3. It checks free disk on `/opt`, `/srv/fleet` and `/var`, the length of every existing instance id, Docker and DDEV, `fleet.env`, the OS, the required packages and the repository. A normal run executes the same checks first and stops before step 10 on a `BLOCK`.
- **FLE-150: release check for `fleet upgrade` (read side).** The app knows which version this host runs (the
  `/etc/ddev-fleet/installed-version` file, else the checkout's exact tag, else `dev`) and which releases exist
  (`git ls-remote` of the annotated `v1.*` tags, semver-sorted, pre-releases ignored), reads the CHANGELOG sections
  between two versions with their `#### Rollout` notes and `ACTION:` lines, and plans an upgrade or downgrade between
  them. `fleet upgrade:refresh` refreshes the cached catalog; the new `fleet-upgrade-check.timer` runs it daily. An
  unreachable source keeps the previous list and records the reason; nothing throws.
- **FLE-150: Rollout notes in the changelog.** A fragment may end with `## Rollout` and bullets; the release step
  assembles them into `#### Rollout` (documented in `changelog.d/README.md`).
- **FLE-151: pre-flight checks for `fleet upgrade`.** An extensible registry that stops an upgrade before it changes
  anything. A check implements `UpgradeCheck`, tags itself `fleet.upgrade_check` with a priority, and returns findings
  (id, `block`/`warn`/`info`, message, remedy, overridable); `CheckRunner` runs them in a fixed order and applies
  `--force=<id>` only to overridable blocks. Shipped checks: sudo present, release source reachable, checkout clean
  and at the installed revision with the expected origin (never overridable), target tag in the 1.x series, not
  below 1.1.0 and on `main`, disk for twice the databases plus headroom (never overridable), active jobs, overlong
  instance ids, migrations applied that the target lacks (forces the rollback path), the target installer's own
  `--preflight`, and `ACTION:` rollout notes. The `fleet upgrade --check` command that shows them follows.
- **FLE-152: `sudo fleet upgrade`.** The write side of the upgrade: it plans and runs the pre-flight checks, asks for
  confirmation (`--yes` is required without a terminal), takes a database backup, runs the installer of the planned
  release pinned to the commit the plan resolved, verifies the host (health, Authelia, installed version, migrations,
  workers, an audit row `host.upgrade`) and appends three lines (start, installer, verify) to the root-owned
  `/var/log/ddev-fleet/upgrade-history.jsonl`. Exit codes: 0 done and verified, 1 failed, 2 usage, 3 refused (nothing
  changed), 5 verification failed (the rollback command is printed). A dirty checkout, a Block, a failed backup or a
  non-root caller changes nothing. `fleet upgrade history` and `fleet upgrade verify` are new. The command runs as
  root only: there is no sudoers entry and the web UI cannot start an upgrade. See `docs/upgrading.md`.
- **FLE-153: rollback with `sudo fleet upgrade --to <older release>`.** The database is restored from the backup taken at
  the start of the upgrade being undone (found in the root-owned history), the fleet services are stopped for the
  restore, the older release is installed and the host is verified. It always needs `--yes` and names the data written
  since the upgrade that will be lost. Refused without changing anything: across a major version, below v1.1.0, to the
  `v0.*` series, without a usable backup (the message lists the options), and `--no-db-restore` while the database holds
  a migration the target lacks. See `docs/upgrading.md`.

- **FLE-154: the Upgrade page and the "vX.Y.Z available" notice.** Administrators see the installed version in the
  sidebar and the phone More sheet, with a badge linking to the new `/admin/host/upgrade` page when a newer release is
  out (read from the cached release list, never from the network). The page shows installed against latest, when the
  releases were last checked (and why a check failed, with the last good answer kept), the changelog and rollout notes
  of what an upgrade brings (escaped and capped, `ACTION:` notes called out), the commands to copy (`fleet upgrade
  --check`, `sudo fleet upgrade`, `sudo fleet upgrade --to vX.Y.Z`) and an empty history. "Check now" is CSRF
  protected and limited to one lookup a minute. The page only shows status: the upgrade itself stays a root command on
  the host. The daily release check now also caches the changelog of the newer release.
- **FLE-18: `fleet upgrade`, the milestone.** One safe command keeps a 1.x server current and gives it a way back.
  `fleet upgrade --check` (no root) shows the installed and the latest release, the changelog and the rollout notes
  between them (an `ACTION:` note is called out) and the pre-flight results; `sudo fleet upgrade [--to vX.Y.Z]` plans,
  checks, takes a database backup, runs the installer of the resolved commit, verifies the host (health, sign-in, version,
  migrations, workers) and records the run in a root-owned history; `sudo fleet upgrade --to <older release>` rolls back
  and restores the database from the backup taken before the upgrade being undone. The Upgrade page and the
  "vX.Y.Z available" notice show the status to administrators, and a daily timer keeps the release list fresh. The upgrade
  itself is deliberately command-line only: there is no sudoers entry for it. The installer gained `--preflight` and
  writes `/etc/ddev-fleet/installed-version`. See `docs/upgrading.md` for the commands, flags, exit codes and every
  pre-flight check id, `docs/installation.md` section 6, and `docs/RELEASING.md` for how to write rollout notes.
- **FLE-155: `ci/upgrade-rehearsal.sh`.** A rehearsal for a test host: it builds a local release repository with two
  throwaway annotated tags and runs check, upgrade, upgrade, rollback and a rollback to the starting release, asserting the
  exit codes and `installed-version` after each step, then puts the host back. `--build-only` and `--dry-run` change nothing.

- **FLE-19: `fleet upgrade --check`.** The read-only command that shows what the next release changes: the installed
  version, the latest (or `--to vX.Y.Z`), the changelog sections in between, the rollout notes (`ACTION:` lines
  flagged) and the pre-flight results. It changes nothing and needs no root. Exit codes: 0 checked, 1 error, 2 usage
  (an unknown `--to` lists the available tags), 3 blocked by a pre-flight check, 4 offline (the installed version and
  the cached latest with its age are still printed). `--json` prints a documented, versioned document;
  `--no-cache` asks the release source instead of the 6-hour list. See `docs/upgrading.md`.

### Changed
- **FLE-148: the 0.x workspace layout is back.** Every window is `[sidebar 50 cols | tty1 | tty2]` (side by side, equal shells; Claude stays in pane 2), the `host` window is now `general` with the same three panes, the sidebar lists every instance with state glyphs, live branches, the reboot banner and the key hints, `^b R` rebuilds a tab in place, and `fleet tmux:ensure` heals windows (never killing a pane) and prunes the windows of gone instances.
- **FLE-153: the upgrade helper is hardened.** The history line cannot abort a run on a full disk, the temporary installer
  export is always removed, and git is limited to the `file`, `https` and `ssh` transports.

- **FLE-158: the Playwright accessibility checks moved into one axe sweep (`a11y*.spec.ts`, one test per page state, light and dark); the feature specs no longer run axe inline** (tests only).

- **FLE-159: the Playwright `phone` project is opt-in and runs only the tests tagged `@phone` (layout at 390 px, tap targets, phone-only behaviour); `instances-phone.spec.ts` is now `instances.phone.spec.ts`** (tests only).

- **FLE-160: named-port rules and messages are PHPUnit data providers** (tests only): the console-only Playwright cases (`named-ports`, `project-ports-full`) are replaced by `NamedPortInputTest`, message matrices in `NamedPortServiceTest` and `PortCommandsTest`, and `ProjectPagesTest::testPortsTab*`; the browser keeps one Ports-tab spec.
- **FLE-161: test diet 4/7**: the admin CRUD browser specs (users, secrets, schedules, webhooks, projects, templates) keep only what needs a browser; the plain HTTP checks now run as PHPUnit Functional tests (full Playwright 773 to 714, quick tier 160 to 137).

- **FLE-162: the import/export and setup-wizard refusal matrices moved from Playwright to PHPUnit Functional (`ProjectTransferMatrixTest`, `SetupFieldMatrixTest`, `SetupLinkRefusalTest`); the browser keeps download, upload, confirm, layout and the wizard walk** (tests only).

- **FLE-163: the instances, jobs, assets, snapshot and audit browser specs lose their plain-HTTP cases to PHPUnit** (tests only; new `InstanceDeployFormTest` and `AuditPageTest`, 53 fewer Playwright tests).

- **FLE-164: host settings and reboot banner: the HTTP cases moved to PHPUnit (`HostSettingsMatrixTest`), `host-settings-coverage.spec.ts` is gone, and the axe checks live in the sweep** (tests only).

- **FLE-169: the `fleet tmux` sidebar has its 0.x colours back.** Each instance is coloured by state (running green, stopped grey, deploying cyan, failed red), the branch line under it is grey, `general` is dim when it is not the current tab, and the reboot banner is bold yellow. The current tab stays plain reverse so it reads on any background.

### Fixed
- **FLE-141: destroying an instance whose directories are read-only no longer fails.** The teardown now gives the
  owner write access to every real directory of the checkout (Drupal sets `sites/default` to 0555) before it unlinks
  the entries, never following a symlink, so the instance is removed instead of staying `failed`.
- **FLE-145: git over SSH works in instance terminals and never prompts for a host key.** A deploy now sets the checkout's
  `core.sshCommand` to the deploy key (plus the push key when the host has one, offered first), Fleet's own known_hosts,
  `BatchMode=yes` and `StrictHostKeyChecking=yes`, so a `git fetch` in tty1/tty2 or a CI script fails fast instead of
  hanging at an interactive host-key prompt. The known_hosts seed now also covers `ssh.bitbucket.org` (Bitbucket's new SSH
  host name from 2026-11-12, same keys as `bitbucket.org`).

- **FLE-145: git over SSH also works inside the web container, per project and opt-in** (what `tty2` and its agent use).
  A project with the new `container_ssh` setting (`fleet project:update <name> --container-ssh`, off by default) gets a
  dedicated `ssh-agent` per instance: the `fleet` user runs it, only the push key is loaded (24 h lifetime), and only that
  instance's web container mounts its socket (a generated compose override). It is stopped with the instance and recreated
  at start. Nothing is loaded into DDEV's host-wide `ddev-ssh-agent`, whose socket every DDEV project's container reaches.
  Fleet's pinned known_hosts is copied to `.ddev/fleet_known_hosts` and a strict `GIT_SSH_COMMAND` reads it.


- **FLE-146: upgrading to v1.1.0 no longer fails on an existing instance id of 60 to 63 characters.** The host render step skips such an instance (it cannot run: Docker refuses its host name) with a warning naming the id, instead of aborting the installer at `98-render-start`. `fleet instance:destroy`, the instance log and snapshot lookups, the instance pages, `ddev delete` and the Caddy site removal accept an existing over-long id, so it can be cleaned up through Fleet. Creating an id over 59 characters stays refused.

- **FLE-148: the `fleet tmux` sidebar runs again, and the panes keep their numbers.** The sidebar pane was started with the tmux server's bare environment (no `APP_ENV`, `FLEET_HOME`), crashed, and tmux removed it, leaving two panes and shifting tty2 to pane 1. It now starts through the application environment file, a crash leaves the error on screen instead of removing the pane, and shells are addressed by position, not raw index.


- **FLE-156: dispatched agents no longer stop at Claude's first-run onboarding on 1.x instances.** The per-instance
  `.ddev/config.fleet.yaml` again carries the 0.x `post-start` hook that merges `hasCompletedOnboarding`, a default
  theme and the project trust acceptance into `~/.claude.json`, keeping every other key.
- **FLE-167: per-instance ssh-agents (`container_ssh`) survive worker restarts, keep their key, and are cleaned up on opt-out.**
  - The job worker restarts every hour (its time limit) and on every deploy; its units now use `KillMode=process`
    (`fleet-worker@`, `fleet-status`), so a restart no longer kills the agents it spawned and git push from the containers keeps working.
  - The status watcher revives the agent of every running opted-in instance and renews the key lifetime every 10 minutes, so a
    long-lived instance no longer loses push after 24 h (the lifetime stays 24 h, the shortest window for a leaked socket).
  - Starting or redeploying an instance of a project without `container_ssh` stops its agent and removes the compose override.


- **FLE-168: the installer pre-flight no longer blocks every upgrade on a 1.x host (FLE-149 regression).** The install and upgrade run as root, and DDEV refuses root, so `ddev version` and the instance listing failed and the `ddev.answer` check BLOCKed. As root the pre-flight now runs `ddev` as the `fleet` user; before that user exists (a fresh install) the check is reported as not checked instead of a BLOCK.

### Dependencies
- `ansible-core` >=2.22.0 for the CI tooling (#146).
- Ansible collection `community.general` >=13.5.1 (#147).
- `symfony/mercure-bundle` ^0.7.0 (#149).

#### Rollout
- Instances deployed before this change keep working only with a `~/.ssh/config` workaround; redeploy them (the checkout and the agent are set up at deploy).
  Re-run `fleet host:init` (it seeds known_hosts) so a host gets
  `ssh.bitbucket.org`.
- A project that pushes from the container (for example the one dispatching agents) must be switched on: `fleet project:update <name> --container-ssh`, then redeploy its instances.
- The export format gained an optional `container_ssh` key per project; older files import as off.
- The unit files change: re-run the installer (`ddev push-product`) so systemd picks up `KillMode=process` (`daemon-reload` + restart of `fleet-worker@1` and `fleet-status`).
  `systemctl stop fleet-worker@1` now leaves agents running by design.
- Hosts installed before this release do not have `fleet upgrade`: move them to this release with the
  installer (`install.sh --ref=vX.Y.Z`); every later release can use `sudo fleet upgrade`.

## [1.1.0] - 2026-11-03

### Added
- **FLE-120: one gates script, `ci/run-gates.sh [--quick|--full] [--report-dir DIR]`.** Agents, the orchestrator and Fleet CI run the same gates (composer gates, prod-check, Playwright, infra tests, non-blocking Ansible lint). It clears the Symfony test/dev caches, writes per-gate logs, `summary.json` and `summary.txt` (counts parsed by `ci/gates_summary.py`) into `var/ci-report/` and exits 0 only if every blocking gate passed. See `docs/README-ci.md`.

### Changed
- **FLE-117: review follow-ups.** `fleet:host:render` now requires the access-model upgrade step (a wiring slip can no
  longer skip it before Authelia is relaxed). Every native select is 44 px tall on a phone (one shared class in the
  `NativeSelect` component). `fleet tmux` checks the instance id before the session is ensured, refuses extra arguments
  with a usage line and prints a hint instead of nesting when run inside tmux.


- **FLE-122: Renovate merges its own green patch and minor PRs.** Patch, minor, pin and digest updates, and the weekly lock-file
  maintenance PR, are merged by Renovate itself once their Fleet CI status (`fleet-gates`) is green, one per run; the
  Renovate runner is scheduled hourly. Symfony minor/major groups, Symfony UX and every major update still need a manual merge.

- **FLE-126: Playwright tiers.** `FLEET_PW_TIER=quick|full` (default `full`, so a bare `npx playwright test` is
  unchanged) lets Fleet CI run a quick merge gate on feature and Renovate branches (desktop without the coverage and
  validation matrices, phone limited to `@smoke`) and the full suite on release, develop and nightly. No test removed.

- **FLE-127: the form-validation matrices of projects and templates run as PHPUnit Functional tests.** The per-rule cases
  (refused and accepted names, Git URLs, branches, hostnames, git identities, issue id patterns, submodule paths, template
  fields and command lines) moved from two Playwright specs to `ProjectValidationTest` and `TemplateValidationTest`, which
  run in seconds. The Playwright specs keep what a browser adds (errors on screen, accessibility, overflow). No product change.

- **FLE-128: `ci/run-gates.sh` serves Fleet CI.** New opt-in `FLEET_CI_INSTALL_DEPS=1` installs the Chromium system
  libraries (`sudo playwright install-deps`) before the browser install; `FLEET_PW_TIER` is documented as passing through.
  Fleet CI now runs its gates through this script, so a gate added here needs no CI config change.

- **FLE-130: Renovate composer updates bump `composer.json` and `composer.lock` together again.** `update-lockfile` left the lock content-hash out of step with `composer.json` (the Fleet CI lock guard failed such PRs), so composer is back on `rangeStrategy: bump`; npm keeps `update-lockfile` (no content-hash). Symfony patch releases are no longer separate PRs; they arrive through a weekly `lockFileMaintenance` PR (Monday before 05:00 Europe/Paris) that refreshes the whole lock with a correct hash. Symfony minor and major PRs use `replace`, so `8.1.*` becomes `8.2.*` and never an exact pin. This amends FLE-140.

- **FLE-131: tests pin the job lock-wait give-up window and the nightly-redeploy and schedule-ticker rules** (`JobExecutorTest`, `NightlyRedeployTest`, `ScheduleTickerTest`), and the CI Python dev requirements were adjusted. No runtime behaviour change.

- **FLE-136: `fleet:host:render --apply` is pinned as re-runnable after a failure midway.** When the Caddy apply fails
  after Authelia went in, Authelia stays applied (no rollback), nothing stays staged and no gate is written; a second
  `--apply` once the cause is fixed ends in the same state as a clean run.

- **FLE-140: Renovate keeps Symfony on `8.1.*` ranges.** Composer and npm updates inside the current range now change only the lock file (`rangeStrategy: update-lockfile`) instead of bumping `app/composer.json` to exact pins; Symfony minor and major updates still arrive as separate PRs, which fall back to widening the range.

- **FLE-142: `main` is now the 1.x (Symfony) line.** `main` holds the 1.x releases, `develop` stays the integration branch, and the installer's default `--ref` is `main`. The Python 0.x line lives on the new `legacy` branch (tags `v0.1.0` to `v0.20.2` and later 0.20.x hotfixes), which takes hotfixes only. Ansible's `fleet_repo_version` now defaults to `legacy`, since it provisions the 0.x host only.
- **The tag guard follows the new model** (`ci/tag-guard.sh`): a `v1.*` tag must be on `origin/main` (the tip also moves GitHub's `main`, an older commit publishes only the tag), a `v0.*` tag must be the tip of `origin/legacy`, and a commit that is only on `develop` is refused.

### Fixed
- **FLE-117: a stale "Remove token" submit** (token already removed in another tab) now says "The pipeline token was
  already removed." instead of reporting a removal.

- **FLE-119: a window opened by `fleet tmux ensure` during a deploy no longer costs the deploy its tty commands.** The
  v0.20.1 hotfix made the 0.x reconcile skip instances whose lock is held. 1.x does not have the race: the deploy step
  closes a window that already exists and builds a fresh one before typing `tty1`/`tty2`, and `fleet tmux ensure` skips
  instances being destroyed. A new test pins the deploy-with-existing-window case so it stays that way.

- **FLE-129: the phone E2E "a second Finish while one is running is told to wait" no longer polls `flock` with the
  default 5 s timeout.** The lock holder reports on stdout once it has the lock and the test waits for its pipe to
  close to know the lock is released. Test-only change.
- **FLE-129: the E2E helper `navigate` (used by `follow` and `submitAndWait`) no longer leaves its pending marker set
  when a visit ends without a Turbo render.** The marker (`data-nav-pending`, set and cleared by the test helper only,
  never by the app) was cleared on `turbo:render` / `turbo:frame-render` alone, so a phone visit that ended another way
  (`users-coverage` "New user form refuses ...") waited out the 20 s expect timeout. It is now also cleared on
  `turbo:load`, `turbo:fetch-request-error` and a 204/205 response, and a visit that still never renders fails with the
  Turbo events it saw (`turbo:click`, `turbo:visit`, request, response, error, render, load). Test-only change.

- **FLE-133: UTC date type fallback.** `UtcDateTimeImmutableType` parsed a value not in the stored format in PHP's default zone; it now uses UTC (an explicit offset still wins). Entities and the type are no longer registered as services, `ext-posix` is declared in `composer.json`, and tests cover the type, a full migration rollback and a ban on the stock date types.

- **FLE-143: the instance list no longer resets filter fields being edited.** A 5-second poll answer that arrived while the filters were being changed replaced the typed values; it is now dropped until the form is submitted.
- **FLE-143: Fleet CI sizes Playwright workers to the container's CPU quota** (`ci/run-gates.sh`: two thirds of the cgroup CPUs, at least 1, unless `FLEET_CI_WORKERS` is set). Five workers on a 3-CPU CI container starved the full suite into timeouts. The 51-jobs pagination test is marked slow, and the secrets form test submits with `requestSubmit` instead of a click.

- **FLE-144: an instance id is now limited to 59 characters, not 63.** DDEV names the web container's host name
  `<id>-web` and Docker refuses a host name over 63 characters, so an id of 60 to 63 characters passed validation and then
  failed at `ddev start`. Validation, the label fitting of webhook deploys and the docs use the new limit, and the
  message says why. Alias host names (`<hostname>-<id>`) keep their own 63-character DNS label limit.

### Security
- **FLE-133: Secret entity guards.** A `Secret` now refuses an unknown scope, a fleet secret with a scope id, a project secret without one and a nonce that is not a libsodium secretbox nonce; its timestamps always come from the caller's clock, and so do those of `HostSettings` (no entity reads the wall clock for that any more; callers pass Symfony's clock).


- **FLE-136: the Caddy log no longer records the query string of the `Referer` header.** The error log keeps the request
  headers, and a Referer can carry a setup token; everything from the first `?` is cut.
- **FLE-136: the gate writer checks the group of the gate file, not only the setgid bit of its directory.** Outside
  `FLEET_HOME` a file that does not belong to the `caddy` group is refused (the previous gate is put back) with the
  `chgrp` command to run, instead of failing at the next Caddy restart.


- **FLE-138: installer and root helper hardening.** The installer pins the primary-key fingerprint of every apt signing key (Docker, DDEV, Caddy, Authelia) and refuses a key that does not match; it installs `gnupg` for the check.
- DDEV's router is bound explicitly to loopback (`--router-bind-all-interfaces=false`).
- The installer refuses to run for real from a directory that is not root-owned or that others can write to.
- The root helper `apply` opens staged files read-only, no longer passes its lock and staging descriptors to the validator, accepts a symlinked `/srv` (the path below it must still be real), and waits at most 5 s for the lock so lock plus work fits the app's 60 s budget.
- The DNS pre-check no longer takes the first public resolver's answer: the public resolvers must agree, otherwise the check is inconclusive.

### Dependencies
- **Symfony and lock-file maintenance:** the weekly lock-file updates (#136, #144) move the Symfony components from 8.1.x to v8.1.10 and bump the `symfony/*` helpers (for example `v2.11.0` to `v2.12.0`, a `v1.43.0` to `v1.43.3` group) and several transitive packages (#144).
- **Doctrine:** `doctrine/orm` ^3.7.6 (#142), `doctrine/doctrine-bundle` ^3.3.4 (#135, #141), `doctrine/doctrine-migrations-bundle` ^4.0.1 (#131).
- **PHPStan:** `phpstan/phpstan` ^2.3.4 (#132, #134, #137, #140), `phpstan/phpstan-doctrine` ^2.1.0 (#132, #143), `phpstan/phpstan-symfony` ^2.1.1 (#137).
- **PHP dev tooling:** `phpunit/phpunit` ^13.4.6 (#138, #145), `friendsofphp/php-cs-fixer` ^3.95.27 (FLE-122).
- **`dragonmantank/cron-expression`:** ^3.6.1 (#130).
- **Python CI tooling:** `pytest>=9.2.0` (#139), `PyYAML>=6.0.3` (FLE-122).
- **Ansible:** `community.crypto` >=3.5.1 (#122).
- **PHPStan:** `phpstan/phpstan` ^2.3.5 (#153).

## [1.0.6] - 2026-10-20

### Added
- **FLE-101: site-only users.** A user kind "Site access only" (checkbox on the Users create and edit forms,
  `--site-only` / `--no-site-only` on `fleet user:create|update`) for people who should only open the instance sites of
  chosen projects, such as a client behind a shared `shield` / `shield` login that keeps crawlers out. No email is needed,
  a password of 4 characters or more is accepted (regular users keep the 12-character rule), and the account is never
  an administrator. Fleet itself answers 403 "This account only opens project sites" for them; the users list shows a
  badge. `users.yml` marks them with the reserved group `fleet-site-only` and a placeholder `@site-only.invalid` email.

- **FLE-103: Playwright coverage for projects, templates and project ports.** New specs for every project and template field, each validation message, edit, delete and cancel paths, the Ports tab and the `fleet:port:*` commands, on desktop and phone with axe in both themes.


- Playwright coverage of the users and access area (`users-coverage.spec.ts`): every validation message, the list badges, memberships, the site-only kind, password rules, disable/enable/delete, the last-administrator and own-account protections, a member's refusals, long values at 390 px and axe in both themes.
- **FLE-107: Playwright coverage for assets, instances and jobs, project export/import and the setup wizard**, on desktop and phone, with axe in both themes.

### Changed
- **FLE-114: a project can have up to 50 additional hostnames (was 10).** A real project needs 23. The form, the
  `fleet project:*` commands and project import all use the same limit; hostname validation is unchanged. Every
  hostname is written to the instance's DDEV `additional_fqdns` and to its single Caddy site block. In `http01` mode
  each name gets its own Let's Encrypt certificate, so a project with many hostnames deployed many times can hit the
  rate limits; `ovh_dns` mode uses one wildcard certificate.

- **FLE-115: one rule for a schedule whose target instance does not exist yet.** The CLI and the pages now accept it,
  as project import already did, with a warning ("Instance X does not exist yet; runs are skipped until it does"; the
  `warning` key with `--json`) and a **Target missing** badge in the schedule list. A run against a missing instance
  (timed or **Run now**) is recorded as `skipped` with that reason and queues no job, instead of `failed`. An empty
  instance id is still refused. `docs/schedules.md` describes the rule.

### Fixed
- **FLE-103: the New project form preselects the "inherit" git identity mode**, instead of showing no choice selected.

- **FLE-104: New user form: ticking Administrator and then Site access only no longer submits a hidden administrator tick.** The refusal ("A site-only user cannot be an administrator.") used to appear under a row that was hidden, so the form came back with an error nobody could see. Hiding the row now unticks it.

- Instance list: the row menu used the wrong instance id (row Redeploy and Destroy returned 404), the row Redeploy item was always disabled and never submitted, the bulk Redeploy and Destroy dialogs listed no instances, and two links loaded into the Turbo frame ("Content missing").
- Assets, Import and the setup wizard SSH step no longer overflow horizontally at 390 px with long names or symlink badges.

- **FLE-116: deploy asset seeding verifies the opened file's real path.** The seed step now reads the path of the open
  descriptor (`/proc/self/fd`) and refuses a file that is not inside the project's assets, which closes the case of a
  folder swapped for a link to an outside folder while a deploy runs. The file is opened without blocking, so a
  named pipe swapped in no longer hangs the deploy. `docs/assets.md` states that a deploy cannot be made to read outside
  the assets again, and documents hard links (copied like any regular file). New tests cover the inode swap after the
  open, the folder swap, the fifo and the hard link.

- **FLE-125: stable Playwright phone runs under load.** A Turbo prefetch (a hover or a touch on a link) no longer
  consumes the flash message the redirect after a save is about to show, and a request that Turbo cancels (the instance
  list refreshing itself, a navigation) no longer ends as an unhandled "The user aborted a request." page error. The
  instance list does not start a refresh while the previous one is still loading.

## [1.0.5] - 2026-10-20

### Added
- **FLE-102: Named ports reach the instance as `FLEET_PORT_<NAME>`.** Each instance's `web_environment` gets
  `FLEET_PORT_PLAYWRIGHT=9325`-style variables for the named ports its project uses (0.x parity), and
  `docs/networking.md` documents the `playwright` (9325 to 8323) and `ts-dashboard` (9111 to 8110) catalogue examples.
- **FLE-105: Playwright coverage of the secrets, the audit log and the host settings.** New specs `secrets-coverage`, `audit` and `host-settings-coverage` check every message the services return, the reveal throttle, the "No placeholder" warning, the audit entries and every host settings tab, in both themes and at 390 px. In `dev` and `test` the cookie `fleet_stand_in_user` renames the stand-in user, so a test owns its reveal throttle and its audit actor.


- **FLE-106: Playwright coverage of the Webhooks and Schedules pages.** New specs `webhooks-coverage.spec.ts` and `schedules-coverage.spec.ts` (desktop and phone, axe in both themes) walk every field, every refusal message, the secret create / rotate flow, the delivery log outcomes, filters and paging, the pipeline token, the schedule lifecycle and history, stale tabs, CSRF and the member role. `support/fleetData.ts` gives specs the console and the data directory of the app under test.


- **FLE-108: `fleet tmux` attaches to the agent workspace again (0.x parity).** `sudo fleet tmux` (also `fleet tmux:attach`)
  makes sure the `fleet` session exists and attaches the caller's terminal to it; `fleet tmux <instance-id>` selects that
  instance's window first. Without a terminal (`ssh host fleet tmux`) it stops with "needs a terminal: use ssh -t" and
  exit status 2. `fleet tmux list|ensure|reset|sidebar` are unchanged.

- `fleet host:ovh-credentials`: replace the OVH application key, secret and consumer key after setup without `fleet setup:reset` (hidden prompts or standard input; `--endpoint=` to change the endpoint), then install them and reload Caddy. The installation guide lists the OVH token rights and the IP restriction note.

### Changed
- The reveal throttle is now the same in `dev` as in production (10 reveals a minute per user); the `dev` limit of 1000 is gone.

### Fixed
- **FLE-100: creating or deleting a project or a user no longer restarts Authelia and signs everyone out.** Per-project
  access moved out of Authelia's configuration: Authelia keeps one static rule for the instance hosts (any signed-in
  user) and a member's projects are the groups `fleet-project-<name>` in `users.yml`, which Authelia reloads live.
  Each instance's Caddy site checks the `Remote-Groups` header (admin group or the project's group) and answers 403
  otherwise, aliases and named-port blocks included. Authelia is restarted only by host-level edits (domain, outgoing mail).
  The upgrade (re-run the installer, or `fleet host:render --apply`) first writes the project groups and rewrites every
  existing instance site with the group check, and only then installs the new Authelia configuration; if a site cannot be
  rewritten it stops and leaves Authelia as it is (fail closed). Groups starting with `fleet-project-` are reserved.
- Storing or removing the notification SMTP password on the host settings page is now recorded in the audit log (`secret.put`, `secret.delete`, subject `fleet/smtp_password`), as the Claude token already was. No value is recorded.
- The audit log's action filter is a 44 px target on a phone, and long actors, actions and subjects wrap in the table instead of scrolling it sideways.

- **FLE-106:** Creating a webhook secret from a tab that still offered "Create secret" after another tab had made one now answers with a message and keeps the secret, instead of an error page.
- **FLE-106:** The action select of the schedule form and the Source and Outcome filters of the delivery log are 44 px tall on a phone (the height class sat on the wrapper, not on the select).
- **FLE-106:** The "job #N" link under a schedule is underlined: colour alone did not distinguish it from the text around it (WCAG link-in-text-block).
- **FLE-106:** The schedule form no longer says aliases such as `@daily` are accepted; the validator has always refused them.

- DNS mode no longer depends on an `/etc/caddy/ovh/endpoint` file that was not always written (a host on `ovh-ca` or `ovh-us` would have talked to the EU API). The endpoint is a public API name, so the Caddyfile now carries it (`endpoint ovh-eu`), validated against the three known endpoints, and DNS mode refuses to render without a stored one. The root helper installs the three credential files, checks that each exists, refuses a partial set, and removes the `endpoint` file an older apply left.


- **FLE-111: deploy asset seeding honours symbolic links that stay inside the project's assets.** A `dumps/default.sql`
  pointing at `dump-prod-….sql` was skipped, so the instance had no seed DB. A link is now resolved without being
  followed (relative or absolute target below `assets/<project>/`, folders on the way real, at most 4 hops, ends at a
  regular file): in `dumps/` it becomes a hard link to the target's inode, elsewhere a relative symbolic link to the
  seeded target. A link outside the assets, dangling, to a folder, in a loop or behind a folder link is still skipped,
  now with the reason in the deploy log. The Assets tab and `fleet asset:list` show each link's target and whether it
  is seeded.

- **FLE-113: deploy asset seeding re-checks the source right before it copies it.** The check that a symbolic link
  stays inside the project's assets and the later hard link or copy were separate steps, so a link swapped in
  between could be followed by the copy fallback. The source is now opened, and the opened file must be the regular
  file that was checked (device and inode compared before and after); otherwise the deploy fails with "changed while
  it was being seeded". The seed step also no longer keeps the assets directory as state on the shared service, and
  `docs/assets.md` states the remaining limit (write access to the assets during a deploy) and that
  `../<project>/x` back into the same project is refused.

## [1.0.4] - 2026-10-20

### Added
- **FLE-98: Typesense keys and environment for instances.** An instance of a project with the Typesense flag gets what
  0.x gave it: a generated admin key and search-only key (sealed project secrets, created once, never rotated),
  `TYPESENSE_API_KEY` in `.ddev/.env`, the `FLEET_TYPESENSE_*` variables in `web_environment`, and the search-only key
  registered in the instance's Typesense after `ddev start` (a failure is a warning in the job log). See
  `docs/networking.md`.

### Changed
- **FLE-99: the Typesense search key waits for a cold container.** After `ddev start` the deploy retries registering
  the search-only key with a growing delay for up to 60 seconds instead of 3 x 3 seconds. Giving up is still only a
  warning in the job log. `docs/projects-and-templates.md` now lists the Typesense variables an instance gets, where
  each lands and the operator steps.

## [1.0.3] - 2026-10-20

### Added
- **FLE-96: `fleet backup:restore <file> [--yes] [--json]` restores a database backup into the live databases.** It verifies the backup first (integrity, not from a newer release), refuses while a job is queued or running or a `fleet-worker@*` or `fleet-scheduler` unit is active, swaps the file in atomically, keeps the previous database as `fleet.sqlite.pre-restore-<stamp>` (never pruned), then migrates and validates the schema; a failure puts the previous database back. The queue backup with the same stamp is restored with it. The restore section of `docs/installation.md` is rewritten around it, including how to undo a restore.

### Changed
- **FLE-97: `fleet backup:restore` is safer.** A fleet backup now records a fingerprint of the secret key that sealed it (never the key), `fleet backup:verify` shows it (`secret key: match`, `mismatch` or `not recorded`), and the restore refuses a backup sealed by another key unless you pass `--accept-key-mismatch` (every secret must then be set again); a backup without a fingerprint is restored with a warning. The restore also refuses while `fleet-status` or php-fpm is active (besides the worker and the scheduler; the printed stop and start lines list all four), and when it runs as another user than the owner of `FLEET_HOME`. A leftover `-wal` or `-shm` next to the live database is covered by a test. `docs/installation.md` describes all of it.

## [1.0.2] - 2026-10-20

### Added
- **FLE-92: In-network port mirror and `:443` site mirror (M0.07b).** `ddev-router` now also listens on the catalogue's
  public ports inside the Docker network (two generated files in `~/.ddev/traefik/`), and the `:443` site mirror lets
  `https://<host>/` work inside the network. The router restarts only when the live entrypoints differ from the wanted
  ones; a failure removes the files and writes the marker `.fleet-port-mirror-failed`. `fleet port:refresh` syncs and
  prints `router mirror: live|pending`. The first deploy after the upgrade restarts `ddev-router` once.
- A port-mirror switch in the host settings (default on); off removes the mirror files at the next sync.
- A catalogue port `typesense` (9108 / 8108); a project's Typesense flag and the assignment of that port stay in step.
- The project Ports tab is editable: assign and unassign catalogue ports in the browser.
- `fleet port:firewall [--json]` lists the public ports to open (80, 443 and the ports in use) with a `ufw allow` line
  each. The installer still does not change the firewall.
- `docs/networking.md`: every port, named-port sites and certificates (`http01` and `ovh_dns`), the port mirror, the
  firewall list and the checks that need a live host.


- **FLE-43: the nightly redeploy schedule, end to end** (milestone M0.09b). Integration tests drive a `redeploy` schedule from its cron time to the finished job (no fake action): the job and the run history, a busy instance (`skipped`), a destroyed instance (`failed`), a paused schedule and two schedules on two instances. A test matrix covers the catch-up window (10, 59 and 61 minutes of downtime, one fire only, `missed` after 60 minutes).
- A reaper: each tick finishes a run left `claimed` without a job for over 5 minutes (the ticker died between the claim and the launch) as `failed`, "The ticker stopped before the job was queued.". It is never fired again.
- The schedule form and `fleet:schedule:create` / `update --help` list the registered actions; the run history and the schedule list link to the job. `docs/schedules.md` has a "nightly redeploy" recipe.


- **FLE-91: the installer installs the fleet shell profile.** A new step 35 writes `/etc/profile.d/10-fleet-shell.sh` (coloured prompt that names the host, `ls` colours, `l` alias, `EDITOR=nano`; interactive shells only) and appends one guarded hook block to `/etc/bash.bashrc` and to the `~/.bashrc` of root, `fleet` and the invoking `sudo` user, only when missing. A file copied by hand from the 0.x host is replaced. `--no-shell-profile` skips the step; the uninstaller removes the file and exactly that block.

- **FLE-94: move a project between hosts.** `fleet export:project <project>` and the **Export** card on a project's General tab write one versioned JSON file (`fleet-project-export`, version 1) with the project settings, its templates and ports, webhook rules and the schedules that target its instances. `fleet import:preview <file>` and **Projects → Import** show a create / update / unchanged / skipped diff with every validation error, and `fleet import:apply <file>` (or the confirm button) writes it all in one transaction or not at all. Secrets are left out unless a passphrase seals them (libsodium Argon2id + secretbox); the passphrase comes from a prompt, `FLEET_EXPORT_PASSPHRASE` or stdin, never argv. Webhook secrets are issued afresh on the new host; assets are copied with rsync. Guide: `docs/moving-projects.md`.
- **FLE-94: `fleet backup:verify <file>`** runs SQLite's integrity check and a schema-version comparison on a backup file, read-only, before a restore.

### Changed
- Port values and names rendered in the instance Caddy site go through `ConfigValue`, and the no-ports site test
  compares the whole file byte for byte (FLE-79 review minors).

### Fixed
- **FLE-72 follow-up:** a tick more than 90 seconds late is a `catch-up` only when the ticker was down. The ticker now records the end of every tick (`scheduler_heartbeat`, migration `Version20261022100000`).

## [1.0.1] - 2026-10-20

### Added
- **FLE-79: Named ports are served by Caddy (M0.07a).** Every instance's Caddy site now also carries one block per
  named port of its project (`<instance host>:<public port>`, aliases included), behind the same Authelia gate and
  proxied to the port on ddev's router. Assigning or unassigning a port rewrites the sites of that project's
  instances; a site Caddy refuses is rolled back and reported as a warning. New command `fleet port:refresh
  [--project=<name>] [--json]` rewrites them on demand.


- **FLE-45: the agent workspace deploy step** (milestone M0.11b). A deploy now creates the instance's tmux window and types the template's `tty1` and `tty2` commands into it, with `[[tokens]]` substituted (`TmuxWindowStep`). A command with an unresolved token is skipped with a `WARNING` in the job log, and a tmux problem never fails the deploy. Destroy and redeploy close the window. Tests cover the v0 line `ddev exec claude --model sonnet "/jira work FLE-9"` and prove the Claude token is only written to `config.fleet.yaml`, never to tmux keys, process arguments, the job log or the audit log.
- `fleet claude:token:apply [--instance=<id>|--all] [--json]` and `fleet claude:token:set --apply` restart running instances (a new `restart` job per instance, one sequential batch) so they pick up a rotated token. Never automatic. Audit action `claude.token.apply`. See `docs/agent-workspace.md`.
- `fleet workspace:check` also reports whether the tmux server is alive.


- **FLE-84: a deploy seeds the new instance from the project's assets.** `AssetsSeedStep` copies the assets into the
  checkout before `ddev start`, substitutes `[[token]]` in text files up to 1 MiB, hard-links `dumps/` and keeps
  everything out of `git status`, as 0.x did. The database import stays the project's `post_deploy` command.

### Changed
- **FLE-70: A truncated audit field ends in an ellipsis.** Actor, action and subject are still cut on a character boundary, now with `…` so the cut shows.

- The DDEV router's own ports (8080, 8443) are now refused as a public port too.

- `fleet-tmux.service` documents why it has no `Restart=` (a oneshot cannot supervise the tmux server); the systemd security baseline records the measured scores of `fleet-tmux.service` and `fleet-mercure.service` (`install_units`).
- Integration test with a real tmux (skipped when it is not installed) checks that the sidebar command receives its arguments unchanged.

- **FLE-81:** Playwright: axe checks in both themes (shared `expectNoBlockingViolations` helper) on the jobs specs, the phone instance specs and the phone navigation pages.

- **FLE-85: webhooks cleanup.** `RuleMatcher::label` now delegates to the new `Labels::slugOrNull`, so there is one slug
  implementation. `docs/webhooks.md` states that two Jira deliveries with different bodies give `fle-3` then `fle-3-1`, and
  that a failed or refused action consumes the delivery id and body hash (the sender's retry is a `duplicate`).


- **FLE-87: deploy-time `[[token]]` substitution resolves managed secrets.** The post-deploy commands and the seeded asset files now go through `Secrets\TokenRenderer` (built-in tokens first, then the project secret over the fleet-wide one), and every inserted value is registered with the job log masker before the first log line that could hold it. The second renderer (`Instances\TokenRenderer`) is gone. An unresolved token in a post-deploy line still fails the deploy before anything is changed; in an asset file it stays as written, with a log line naming it. `tty1`/`tty2` commands still take only the built-in tokens: a secret is never typed into a tmux pane, whose scrollback would keep it, and the command is skipped with a `WARNING`. See `docs/secrets.md`.

- **FLE-90: `fleet claude:token:apply --all` asks for confirmation** (or `--yes`/`-y`); non-interactive and `--json` runs refuse without `--yes`, like the other destructive commands.

### Fixed
- **FLE-78: A long secret name no longer widens the phone layout.** The flash message after saving a secret with a 64-character name did not wrap, so the page overflowed sideways on a phone and zoomed out. Flash messages now break long words.

- **FLE-81: M0.04 follow-ups.** Authelia: a project's alias prefix no longer swallows another project's own hosts (project `blog` with the hostname `x` no longer matches the instances of project `x-blog`; the colliding alias is left out of the rule). Mercure: instance changes go to the per-project topic `/instances/<project>` and the page's cookie authorises only the projects the user may operate, so a member no longer receives events about other projects' instances. The instance page's job link is underlined (axe `link-in-text-block`).


- **FLE-83: Playwright schedules spec no longer flakes on a slow machine.** The dark-theme axe check now runs through a shared `expectNoBlockingViolations` helper that switches transitions off before measuring, and the empty-state test no longer depends on test order.

- **FLE-85: a fixed-label webhook redeploy follows the rule's branch.** When the rule's branch differs from the
  instance's, the redeploy job carries it (optional `branch` param, so queued jobs still run) and the instance switches.

- **FLE-88: Playwright specs wait for the Turbo render after a form submit.** A new `submitAndWait` helper
  (next to `navigate()`) clicks and waits for the response to render; the webhook, schedule and user specs use it,
  which removes the stale-flash flake of the phone webhook-actions spec.

- **FLE-89: a password reset no longer looks broken when Authelia has banned the user.** Authelia's regulation
  (`max_retries`, `find_time`, `ban_time`) is now rendered explicitly (3 failures in 2 minutes, 5 minute ban) and
  documented in `docs/installation.md` (Troubleshooting). `fleet user:reset-password` and the Users page "set a new
  password" now say that Authelia may keep blocking the user for up to 5 minutes and that retrying extends the ban.

- **FLE-90: the agent workspace works on an installed host.** The installer now installs the `tmux` package and installs, enables and starts `fleet-tmux.service` (idempotently, never restarting it); the uninstaller removes the unit. The pipeline image installs `tmux` so `TmuxRealTest` runs instead of skipping.


- **FLE-93: the job log no longer fails the axe `aria-prohibited-attr` check.** The log `<pre>` carried an `aria-label` without a role that allows a name. It now has `role="log"` and `aria-live="off"` (the live tail appends often, so screen readers are not flooded; the status line above it still announces). No other `aria-label` in `app/templates` sits on an element without a permitting role.

### Security
- **FLE-70: Secrets used by a deploy are masked in the job log.** The Claude token written to the instance configuration and every secret `TokenRenderer` inserts are registered with the job log masker, so they never reach the job log, the live tail or a job's error text. Only `[masked]` is written.
- **FLE-70: Secret reveals are throttled.** A user may reveal 10 values per minute. A refused reveal answers 429 with `Retry-After` and is audited as `secret.reveal-refused`.

## [1.0.0] - 2026-10-19

### Added
- **FLE-38: instances, jobs and live logs** (milestone M0.04). A project can now be deployed. An instance is `<project>--<label>` (at most 63 characters), a checkout of one branch started as its own DDEV project and served at `https://<id>.<domain>` behind the Authelia gate; the database is the source of truth. The web request only stores a **job** (deploy, redeploy, start, stop, destroy); the worker unit `fleet-worker@1` runs it under a per-instance lock (`queued`, `running`, `succeeded`, `failed`, `interrupted`), so a killed worker or a restart leaves an `interrupted` job that **Retry** repeats as a new job. Labels are allocated when the request is made (a numeric suffix for a clash or a leftover checkout, at most 20 instances per request, a disk-space check for batches), deploys of a batch run one after another unless asked to run together, and a failed member never stops its batch. Post-deploy lines run on the host as an argument list without a shell, with `[[token]]` placeholders substituted afterwards. Destroy never uses privilege: root-owned leftovers fail the job with the exact `sudo rm -rf` line and a retry is safe.
- **Instances and Jobs pages** (usable at 390 px): the instance list with filters, bulk actions and a deploy form, the instance page with start, stop, redeploy and destroy, the job page with the **live log** and Retry, and `/admin/jobs` with the history. Mercure only wakes the page (job id, status and size); the masked log text is read from `/jobs/<id>/log` in chunks, and the pages poll when the hub is not reachable. Administrators deploy and destroy; project members start, stop, redeploy and read the logs of their projects' instances, and anything else answers 404.
- **`fleet instance:*` and `fleet job:*`** (`deploy`, `redeploy`, `start`, `stop`, `destroy`, `list`, `log`, `watch`; `job:list`, `show`, `retry`, `prune`): `--format=table|json` (`--json`), exit codes `0` done, `1` refused value or every job failed, `2` not allowed now or some jobs failed, `3` not found. The changing commands run the jobs attached in the CLI process (`--detach` only queues them; Ctrl-C marks the job `interrupted`, exit 130). The `fleet` wrapper maps the groups `instance` and `job`. Guide: `docs/instances-and-jobs.md`.
- **Installer and units:** `fleet-worker@1` (one consumer, the SQLite queue takes a single one), `fleet-status` (keeps instance states and memory true to `ddev list` and `docker stats`, interrupts dead jobs, prunes once a day), `fleet-boot` (starts the instances one at a time after a reboot), per-instance Caddy snippets in `<caddy dir>/instances/` imported by the main Caddyfile, and `FLEET_JOB_RETENTION_DAYS` (default 90) in `fleet.env`.


- **FLE-66: webhooks, receiving side** (milestone M0.08a; the deploy itself waits for M0.04). `POST /hooks/jira/<project>` and `POST /hooks/bitbucket/<project>` verify the HMAC-SHA256 `X-Hub-Signature` of the raw body (constant time), cap the body at 1 MiB (413), and answer 404 for a project without a receiver and 401 for a bad or missing signature (a log warning with the source, project and cut delivery id, never a delivery row, never the body). Retries are deduplicated by `X-Atlassian-Webhook-Identifier` / `X-Request-UUID` (matched deliveries only, freed after 7 days). Rules (`on_status` for Jira; event, repository, branch glob, state and comment for Bitbucket) are evaluated into a **recorded decision** such as "would deploy fle-66 (template jira-work)"; the action behind them is the `WebhookAction` interface, whose only implementation records "deploy action not available until M0.04". New tables `webhook_endpoint`, `webhook_rule`, `webhook_delivery` (migration `Version20261020120000`); the HMAC secret is sealed with `SecretBox` and removed with its project.
- **FLE-66: the Webhooks page and a project Webhooks tab** (administrators only, usable at 390 px). `/admin/webhooks` (the former placeholder) shows every project's Jira and Bitbucket address with a copy button, whether a secret exists, its rules and the latest deliveries; the project tab adds the paged delivery log with "Hide ignored". **Create secret / Rotate secret** show the new secret once, in the answer to the POST (`Cache-Control: no-store`), and never again; rotation kills the old secret at once.
- **FLE-66: `fleet webhook:*` commands:** `secret <project> [--source] [--rotate]` (labelled `url:` and `secret:` lines, `--json`), `list`, `log`, `rule:add` and `rule:remove`; the `fleet` wrapper maps the group `webhook`. `docs/webhooks.md`. `/hooks/*` is now `PUBLIC_ACCESS` in `security.yaml`; Caddy already exempted it from the login gate and capped the body. Playwright: `webhooks.spec.ts` at both widths.

- **FLE-71: snapshot a running instance's database into a project dump** (milestone M0.06b, part of FLE-40). The project's Assets tab has a **Snapshot a running instance** card (running instances of the project only, 44 px targets at 390 px, an empty state when none runs; POST with CSRF, administrators only). It queues a `snapshot` job (`SnapshotJob`) that runs `ddev export-db` in the instance's checkout and publishes `dumps/default-<instance-id>-<UTC time>.sql.gz` atomically (never a partial file, never overwriting, 512 MiB free-space check, output in the job log). `SnapshotService` refuses a stopped, unknown, other-project or busy instance and records an `asset.snapshot` audit entry. `fleet asset:snapshot <instance-id> [--json]` uses the same service. New dev/test-only `fleet:dev:fake-instance` (absent in prod) for the browser spec, and the recording runner answers `ddev export-db --file=` with a tiny gzip. Tests: job, service, command and page tests, and `snapshot.spec.ts` (desktop and phone, axe in both themes). See `docs/assets.md`.

- **FLE-72: schedules** (milestone M0.09a). Recurring jobs with the 0.x semantics: a 5-field cron expression read in an IANA timezone, pause and resume (no catch-up on resume), a 60-minute catch-up window after downtime (after downtime only the most recent missed occurrence fires, and only when it is at most 60 minutes old; an older one is recorded as `missed`), a run history (`schedule`, `catch-up` or `manual` trigger; results claimed, started, succeeded, failed, skipped, missed) and run now. New `Schedule` and `ScheduleRun` entities (migration `Version20261021090000`; a unique key on schedule and occurrence makes two racing ticks fire one job), `ScheduleService`, `ScheduleTicker` and a small action registry (`ScheduleAction`, `redeploy` registered through `InstanceService::request`). Cron maths use `dragonmantank/cron-expression` (the library `symfony/scheduler` itself uses; its `CronExpressionTrigger` has no per-schedule timezone), with fall-back handling for expressions that name their hours; `symfony/scheduler` supplies the 30-second tick (`scheduler_default`, consumed by the new `install/etc/fleet-scheduler.service`, not yet placed by the installer: see `docs/schedules.md`). `fleet:schedule:list|create|update|delete|run|pause|resume|history` (`--json`), and the `/admin/schedules` pages (administrators only, usable at 390 px) replace the placeholder. The real nightly redeploy end to end is verified with fakes only here; it follows once FLE-56 merges.

- **FLE-77: webhooks act** (milestone M0.08b). A matched rule now does something. A Jira issue moving into a rule's status queues a **deploy job** (trigger `webhook`, requester `webhook:jira`) for the template and the label `<project>--<issue key>`; a Bitbucket `repo:push` queues a deploy for every created or updated branch (deletions and tags are skipped): a fixed `label` redeploys the existing instance, `label_prefix` and `strip_suffix` never overwrite and allocate `-1`, `-2`, long labels are fitted to 63 characters with a SHA-1 suffix (additional hostname aliases included) and a derived label that collides with a fixed-label rule is skipped. A `run-pipeline` rule calls the Bitbucket Pipelines API with a per-project `pipeline:write` token, sealed like the HMAC secret (migration `Version20261022090000`), set on the project's Webhooks tab or with `fleet webhook:token` and shown nowhere afterwards. The delivery row and the answer name the job and the instance; a refusal is a recorded `failed` delivery answered 200, an unresolvable target is 422, and nothing returns a 500 or a stack trace to the sender.
- **FLE-77: the Webhooks pages show the outcome** of each delivery (the job and the note) and filter by source and outcome. `fleet webhook:rule:add` takes `--label-prefix` and `--strip-suffix`; `fleet webhook:list` shows whether a token is set. `symfony/http-client` is now a direct dependency.

- **FLE-73: the agent workspace, host side** (milestone M0.11a). `App\Fleet\Workspace\Tmux` owns the persistent `fleet` tmux session (`tmux -L fleet`, argv arrays only): `ensureSession`, `listWindows`, `ensureWindow` (one window per instance with the sidebar, tty1 and tty2 panes, idempotent), `closeWindow`, `sendKeys` (literal text then Enter into tty1 or tty2; a control character or an over-long line is refused) and `reset`. Commands: `fleet tmux:ensure`, `fleet tmux:reset [window]`, `fleet tmux:list [--json]` and `fleet tmux:sidebar --window <id> [--once]` (the sidebar pane: state and URL from the database, live local branches of the checkout). `install/etc/fleet-tmux.service` (Type=oneshot, RemainAfterExit) runs `fleet:tmux:ensure` and keeps the tmux server in its own cgroup, so restarting php-fpm or the workers leaves the panes alone. The deploy step that creates windows and types the tty commands, and the installer step that installs the unit and the `tmux` package, come with FLE-56 and the installer work; nothing calls the service yet.
- **FLE-73: Claude token rotation.** `fleet claude:token:set` reads the token from a hidden prompt or standard input (never an argument), applies the same shape rule as the host settings form (`ClaudeTokenRule`), stores it as before (fleet-wide secret), records a `claude.token.rotate` audit entry and lists the running instances that will pick it up. The host settings form now goes through the same service (so it is audited too). The Claude tab says "Last rotated" and names the command.
- **FLE-73: workspace checks.** `fleet workspace:check [--json]` (read-only, exit 1 when something is missing) reports whether the push key, the git identity and the Claude token are set, as booleans only; the same three checks are a card on the Git identity and Claude token tabs of `/admin/host`. The `fleet` wrapper gained the groups `tmux`, `claude` and `workspace`.
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

### Changed
- **Post-deploy lines run on the host, not in the web container.** The M0.03 guide said they ran through `ddev exec`; they run as written (`ddev composer install`, `ddev drush deploy`), and a command that needs the container's shell is written `ddev exec '...'`. Deleting a project is refused while it has instances.
- **Upgrade notes:** DNS needs a wildcard record `*.<domain>` for the instance sites; each instance site gets its own certificate, so Let's Encrypt's weekly limit applies; members cannot open instance sites until per-project Authelia rules arrive (M0.10); re-running the installer while a job runs interrupts it (Retry); the live-components endpoint has no request rate limit. See `docs/installation.md`, "Instances on the host".

- **FLE-49: Caddy reads the OVH DNS-01 credentials from files, not from its environment.** The Caddyfile uses `{file./etc/caddy/ovh/<name>}` placeholders; `apply caddy` writes the four values to `/etc/caddy/ovh/` (`root:caddy` 0750, files 0640, atomic, restored on a failed reload) and keeps only `FLEET_PROXY_SECRET` in `/etc/caddy/fleet.env` (any `OVH_*` line left there is dropped, idempotently). A rotation of the credentials is a reload, not a restart; `caddy validate` runs against the staged files before anything is installed. The uninstaller removes `/etc/caddy/ovh`. The Ansible `caddy` role (v0.x) is unchanged: it serves the Python fleet on live hosts and keeps `{env.OVH_*}` in its `tls.conf` until that fleet is retired.
- **FLE-75: every SQLite write transaction takes the write lock when it starts.** A new DBAL middleware (`SqliteImmediateTransactionMiddleware`, on the fleet and the queue connection) issues `BEGIN IMMEDIATE` instead of a deferred `BEGIN`, so every Doctrine flush and explicit transaction (secrets save, unset and reveal audit, host settings, projects, assets, jobs, instances, ports, templates, SSH keys, audit log, messenger) waits for a concurrent writer up to the busy timeout instead of failing with "database is locked" (seen as a 500 on `POST /admin/secrets` in a CI Playwright run). `UserRepository::transactional()` loses its `DELETE ... WHERE 0` workaround. Tests: a two-process secrets writer (`ConcurrentSecretWritesTest`, flag-file hand-over) and `SqliteImmediateTransactionTest`.

- **FLE-77: webhook hardening** (the FLE-66 review notes). A signed body seen again within an hour is a `duplicate` even with a fresh delivery id; an oversized `Content-Length` is a 413 before the body is read; a stored secret that cannot be opened (a lost key) is logged as its own error, apart from a bad signature; the 200 `ignored` for a receiver with no rules (0.x answered 404) is documented. The rule decision text reads "deploy fle-66 (template jira-work)" instead of "would deploy ...".
- **FLE-63: follow-ups of FLE-59 and FLE-62.** **Upgrade note:** the host git identity is validated as strictly as a project override (FLE-59): `bot@localhost` (no dot in the domain) and names with control or invisible formatting characters are refused, so an identity saved earlier can fail on its next save. The Git identity tab now warns when the stored value no longer passes; nothing is rewritten silently. "Back up now" counts the `-wal` and `-shm` files in the 256 MiB bound; `ComponentsAccessTest` requires the admin-only components to declare `ROLE_ADMIN`; the 1500-id chunk test asserts a match in several chunks whatever the SQLite variable limit; the Playwright "serves nothing" check asserts an empty or forbidden body.
- **FLE-61: follow-ups of the assets upload (FLE-52).** The upload cap is editable on a new **Uploads** tab of the host settings page (MiB, 1 to 1 048 576; the server still enforces it per upload). A folder holding only stale hidden upload temporaries (older than an hour, left by a killed assembly) can be deleted. A name clash with a folder no longer offers "Replace it?" (the 409 carries `reason: file-exists` only for a replaceable file). The free-space check looks at the filesystem of `uploads/` and of the target assets directory. `assets.spec.ts` gains a Rescan spec (desktop and phone). Kept as documented gaps: no `Range` downloads, no empty-file upload.
- **FLE-59 (follow-up of FLE-51).** "Back up now" on the host page refuses databases above 256 MiB (`BackupService::INTERACTIVE_MAX_BYTES`, `BackupTooLarge`) with a message that names `fleet fleet:backup:create` and the daily timer, because it runs inside the php-fpm request and a large copy could hit `max_execution_time` (the smaller option; a Messenger message waits for the M0.04 job core). The timer and the command have no limit. `fleet-backup.service` documents that its `ReadWritePaths` is the installer's `FLEET_HOME` (systemd does not expand variables there) and a repo test keeps the two equal. The host git identity (`GitIdentityRule`) now uses the project rules of FLE-37 (`Projects\Rule\GitIdentity`), so both are held to one standard (host values additionally allow "both empty"). The dev environment reads the reboot flag from `FLEET_HOME/test-reboot-required` like the test one, and `reboot-banner.spec.ts` covers the banner at 1280 and 390 px.
- **Secrets** in the sidebar is a real page instead of a placeholder (FLE-39); deleting a project (browser or `fleet project:delete`) also deletes its secrets, and the `fleet` wrapper maps the group `secret`.
- **Projects** in the sidebar is a real page instead of a placeholder (FLE-37), and the `fleet` wrapper maps the groups `project`, `template` and `port` besides `host`, `setup` and `user`. `docs/v0/configuration.md` (the `fleet.yml` reference) is superseded by `docs/projects-and-templates.md`.
- **The proxy trust boundary is closed (FLE-36).** In production the app trusts `Remote-*` identity headers only from a request carrying the shared secret as the FastCGI parameter `FLEET_PROXY_AUTH` (Caddy sets it from `FLEET_PROXY_SECRET`; clients cannot set a FastCGI parameter; compared with `hash_equals`; a secret shorter than 32 characters is ignored). Caddy strips every `Remote-*` and `Remote_*` request header on all routes first. The installer sets `FLEET_TRUSTED_PROXIES` empty, `::ffff:` peers are normalised, and `ProductionGuard` refuses a `FLEET_HOME` inside the project and trusted proxies together with a proxy secret. New environment variables `FLEET_PROXY_SECRET` and `FLEET_CADDY_DIR`.
- The Ansible roles under `ansible/` are the 0.x host layout and are not used by the 1.x installer (`ansible/LEGACY-0x.md`). Host hardening (UFW, sshd, fail2ban) is not part of the installer; it stays an operator decision.
- Documentation: new `docs/installation.md` (installer, wizard, files and units, recovery, upgrades, security model, uninstalling, troubleshooting); `README.md`, `CLAUDE.md`, `docs/README.md`, `docs/development.md` and `tests/README-tests.md` updated.
- **FLE-35: `develop` is now the Symfony 1.x line.** The Python app (`src/fleet/`, the `fleet` CLI, the FastAPI daemon), `pyproject.toml`, `fleet.yml` and its example, the OpenPGP/htmx vendoring (`bootstrap.sh`, `docs/client-side-encryption.md`, `docs/vendored-assets.md`) and the Python/Node/pytest suites for them are removed from `develop`. The 0.x line continues on `main` (tags `v0.*`) and is not affected.
- The documentation is reworked for the 1.x workflow: `CLAUDE.md`, `README.md`, `CONTRIBUTING.md`, `docs/README.md` (index with a status per document), `docs/development.md`, `tests/README-tests.md`, and `docs/RELEASING.md` (new section "1.x during the port": `v1.*` tags are cut from `release/1.x.y` and merged into `develop` only). The 0.x reference documentation moved to `docs/v0/` with a banner naming the milestone that re-implements each document, and its server, client and workspace specifics were replaced with neutral examples.
- CI is now PHP: gates (php-cs-fixer, PHPStan level 8, Twig/YAML/container lint, Doctrine schema validation, PHPUnit) and the Playwright suite run on pull requests, `develop`, `feature/**`, `release/**` and tags; Python only remains for the infra tests and the systemd security check. A `v1.*` tag publishes to the GitHub mirror only when its commit is on `origin/develop` and pushes the tag alone (`ci/tag-guard.sh`); `v0.*` tags keep the old rule. Renovate now covers Composer and npm.

### Upgrade notes
- **Existing hosts: re-run the installer** (`curl ... | sudo bash -s -- --domain=<fqdn>`, the same command as the first install) **to get the database backup timer.** Steps 95 (writes `fleet-backup.service` and `fleet-backup.timer`) and 98 (enables the timer) are the ones that matter; a fresh install already has them. Until then no backup is made. The migration `Version20261019100000` (host settings: backup retention, git bot identity, notification e-mail) runs on deploy, in the same installer run.

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

[Unreleased]: https://github.com/rcuny/ddev-fleet/compare/v1.9.1...HEAD
[1.9.1]: https://github.com/rcuny/ddev-fleet/compare/v1.9.0...v1.9.1
[1.9.0]: https://github.com/rcuny/ddev-fleet/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/rcuny/ddev-fleet/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/rcuny/ddev-fleet/compare/v1.6.1...v1.7.0
[1.6.1]: https://github.com/rcuny/ddev-fleet/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/rcuny/ddev-fleet/compare/v1.5.3...v1.6.0
[1.5.3]: https://github.com/rcuny/ddev-fleet/compare/v1.5.2...v1.5.3
[1.5.2]: https://github.com/rcuny/ddev-fleet/compare/v1.5.1...v1.5.2
[1.5.1]: https://github.com/rcuny/ddev-fleet/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/rcuny/ddev-fleet/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/rcuny/ddev-fleet/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/rcuny/ddev-fleet/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/rcuny/ddev-fleet/compare/v1.2.2...v1.3.0
[1.2.2]: https://github.com/rcuny/ddev-fleet/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/rcuny/ddev-fleet/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/rcuny/ddev-fleet/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/rcuny/ddev-fleet/compare/v1.0.6...v1.1.0
[1.0.6]: https://github.com/rcuny/ddev-fleet/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/rcuny/ddev-fleet/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/rcuny/ddev-fleet/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/rcuny/ddev-fleet/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/rcuny/ddev-fleet/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/rcuny/ddev-fleet/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/rcuny/ddev-fleet/compare/v0.20.0...v1.0.0
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
