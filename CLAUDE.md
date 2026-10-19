---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# CLAUDE.md — working on the ddev-fleet product repo (1.x)

This file is for Claude (or any agent) editing code in this repository. It is the
**product**: the fleet manager itself. The repository is public (Bitbucket is the
source of truth, GitHub is a read-only mirror), so keep it free of owner
hostnames, client or project names, workspace paths and Jira site names; examples
use `fleet.example.com`, `demo` and `blog`.

## Two lines of development

| Branch | Line | Contents |
|---|---|---|
| `main` | **0.x** (Python) | Releases `v0.*` only: FastAPI daemon, `fleet` CLI, `fleet.yml`, `bootstrap.sh`. Live servers follow it. Not touched by the port. |
| `develop` | **1.x** (Symfony 8) | The rewrite in `app/` and its installer in `install/`. No Python app, no `fleet.yml`, no `bootstrap.sh`. Installable on a test host (`install/install.sh`, `docs/installation.md`), not released or production-ready yet. |

Gitflow on `develop`: features are `feature/<KEY>-<slug>` cut off `develop` and merged
back with `git merge --no-ff`; 1.x releases are `v1.*` annotated tags cut from
`release/1.x.y` and merged into `develop` only (`main` stays 0.x until the cut-over). The
tag pipeline publishes a `v1.*` tag only when its commit is on `origin/develop`
(`ci/tag-guard.sh`). Procedure: `docs/RELEASING.md` ("1.x during the port"). Never
push to GitHub by hand and never rewrite `main`, `develop` or a tag.

## Hard facts

- **It runs natively on the host, not in DDEV.** In production it is a PHP-FPM app
  behind Caddy plus Messenger workers under systemd, and it manages *other* DDEV
  projects (the fleet's instances) through the Docker socket. Do not add DDEV
  configuration to this repository assuming the product runs in a container.
- **`ddev`, `git` and `docker` never run inside an HTTP request.** They run only in
  Messenger workers and console commands, always through the `CommandRunner` seam.
- **Only Caddy faces the Internet.** The installed host serves the app through Caddy and a unix-socket php-fpm
  pool; identity headers are trusted only from a request carrying the proxy secret (see "Production trust and the
  installer"). Never expose `app/public` any other way (`php -S`, a DDEV project): outside the installed host there
  is no proxy, and `dev` has the stand-in login.
- The Python code and docs of the 0.x line are gone from `develop`; their
  documentation is kept under `docs/v0/` as history.

## Repository map

| Path | What |
|---|---|
| `app/` | The Symfony 8.1 project (PHP 8.4): `src/`, `templates/`, `assets/`, `config/`, `migrations/`, `tests/` |
| `tests/playwright/` | TypeScript browser E2E (`@playwright/test`), its own npm package |
| `tests/pytest/` | Python tests that remain for the CI tooling and the Ansible roles (see `tests/README-tests.md`) |
| `ci/` | Tag guard (`tag-guard.sh`), the systemd security scorer and its baseline, requirements files for the Python CI steps |
| `bitbucket-pipelines.yml`, `renovate-config.json` | CI and dependency updates |
| `install/` | The 1.x installer (pure bash): `install.sh` (bootstrap), `run.sh`, `lib/common.sh`, `steps/NN-*.sh`, `etc/` (unit files, pool, sudoers line, env drop-ins), `libexec/apply` (the root helper), `bin/fleet` (the CLI wrapper), `uninstall.sh`. Tested by `app/tests/Repo/Installer*Test` and friends |
| `ansible/` | Provisioning for the 0.x host layout, kept as reference and not used by the installer (`ansible/LEGACY-0x.md`) |
| `docs/` | Documentation index in `docs/README.md`; `docs/v0/` holds the 0.x reference |
| `demo/`, `scripts/` | A minimal DDEV project the 0.x installer seeds as `demo`; the Renovate merge helper |

### Namespaces (`app/src/`, `App\`)

| Namespace | Holds |
|---|---|
| `App\Domain\Host` | `HostSettings` (single row: domain, ACME email, `TlsMode` http01 / ovh_dns, setup token hash and expiry, completion time) and its repository |
| `App\Domain\User` | `User` (argon2id hash, groups; `fleet-admins` is the admin group), `PasswordHash` (strict argon2id check), `UserRepository` |
| `App\Domain\Ssh` | `SshKey` (public key and fingerprint per `SshKeyRole` deploy / push; private keys live on disk, never in the database) |
| `App\Domain\Secret` | `Secret` (sealed ciphertext, nonce, key version, scope `fleet` or `project`; the scope id is the project name) and its repository |
| `App\Domain\Audit` | `AuditEntry` (actor, action, subject, time; never a value) and its repository |
| `App\Domain\Project` | `Project` (name, git URL, defaults, hostnames, git identity mode `GitBotMode`, issue id pattern, submodule branch, Typesense; its ports and templates), `ProjectRepository`, `ProjectInstanceGuard` (the seam M0.04 implements: deletion is refused while `instanceIds()` is not empty; a no-op until then), `ProjectMembership` (who may operate a project; nobody is a member until M0.10) |
| `App\Domain\Template` | `Template` (belongs to a project: Drupal environment and the `postDeploy`, `tty1`, `tty2` command lists) and its repository |
| `App\Domain\Port` | `NamedPort` (the host-wide catalogue: name, public and router port) and its repository |
| `App\Doctrine` | Doctrine plumbing: `SqlitePragmaMiddleware` (WAL, `busy_timeout=5000`, `foreign_keys=ON` on every connection), `UtcDateTimeImmutableType` (`utc_datetime_immutable`, always UTC: use it, not the stock datetime types, under SQLite) |
| `App\Fleet` | `FleetPaths` and host-driving services; `App\Fleet\Shell` is the `CommandRunner` seam |
| `App\Fleet\Secrets` | `SecretKeyFile` (`keys/secret.key`, 32 bytes, never overwritten), `SecretBox` (XChaCha20-Poly1305 with the scope and name as associated data), `SecretStore` (two kinds in one table: the host's own secrets with lowercase names, written by the setup wizard, and the managed secrets with `UPPER_CASE` names, fleet-wide or per project, set by an admin), `SecretService` (the only path the pages and `fleet secret:*` use: validates, seals, audits put / delete / reveal), `SecretName` (the name rule and the kebab-case `[[token]]` of a name), `SecretValue` (text, 1 byte to 64 KiB, normalised, no wrapping quotes), `TokenRenderer` (`[[token]]` substitution for the deploy: project secret over fleet-wide, built-ins over both, verbatim, single pass, unresolved reported), `PlaceholderScanner` (which placeholders a project's asset files mention, for the "no placeholder" warning) |
| `App\Fleet\Audit` | `AuditLog` (`record(actor, action, subject)`, `latest()`; actions `secret.put`, `secret.delete`, `secret.reveal`; the CLI actor is `cli`) |
| `App\Fleet\Ssh` | `SshKeyService` (generate / import / regenerate the deploy and push keys), `Ed25519KeyGenerator` (OpenSSH format in PHP, no process), `KnownHostsFile` (seeded provider keys, trust only after a fingerprint confirmation), `KnownHostScanner` (`ssh-keyscan` through the runner) |
| `App\Fleet\Auth` | `UserService`, `PasswordPolicy`, `AutheliaUsersRenderer` / `AutheliaUsersFile` (the Authelia `users.yml`, written atomically under a lock) |
| `App\Fleet\System` | The renderers (`CaddyfileRenderer`, `CaddyEnvRenderer`, `AutheliaConfigRenderer`; templates in `templates/system/`), `ConfigValue` (validates everything that is rendered), `SystemRenderer` (staging), `GateWriter` / `GateMode` (the Caddy gate snippet), `CaddyReloader`, and the `Applier` seam with `PrivilegedApply` (see "Privileged apply") |
| `App\Fleet\Projects` | `ProjectService`, `TemplateService` and `NamedPortService` (the only code that creates, changes and deletes projects, templates and catalogue ports; each collects the first error of every field and throws one `ValidationFailed` carrying a `field => message` map, or `Refused` / `NotFound`), the inputs they take (`ProjectInput`, `TemplateInput`, `NamedPortInput`) and `ProjectSnapshot` (a project as plain data in the 0.x key names) |
| `App\Fleet\Projects\Rule` | The pure validation rules, one class per concern: `ResourceName`, `GitUrl`, `BranchName`, `IssueIdPattern`, `CommandLines`, `DrupalEnv`, `GitIdentity`, `SubmodulePath`, `PortNumbers`, `TextBlock`. They return a message or null and never echo the input |
| `App\Setup` | The setup wizard's domain: `SetupTokenService`, `SetupCookie`, `SetupState`, `SetupGate` / `SetupAccessSubscriber` (the guard), `SetupAttemptLimiter`, `SetupProgress`, `SetupFinisher`, `ScanTargetPolicy` |
| `App\Health` | `HealthChecker` / `HealthReport` behind `GET /health`; `HostHealth` / `HostHealthReport` (memory, disk, reboot-required) for the Home card |
| `App\Security` | `FleetUser`, `RemoteUserAuthenticator`, `TrustedProxyChecker`, `ForbiddenHandler`, `CurrentActor` (the signed-in user's name for the audit log and `updated_by`), `ProjectVoter` (`SETTINGS` is admin-only; `OPERATE`, used from M0.04, is admin or a member of the project); `App\Security\StandIn` (dev/test only) |
| `App\Controller` | Controllers (`Home`, `Health`, `Placeholder`, `AdminFallback`; `Admin\` for admin pages, among them `ProjectController`, `TemplateController`, `SecretController` and `AuditController`); `App\Controller\Setup` has one controller per wizard step plus `EnterController` (token exchange) |
| `App\Form\Setup` | The wizard's Symfony forms |
| `App\Form\Project` | The project and template forms (`ProjectType`, `TemplateType`; plain Symfony forms, `novalidate`, errors come from the services through `ValidationErrorMapper`) |
| `App\Command` | The flat `fleet:*` console commands: `fleet:host:init`, `fleet:host:render`, `fleet:setup:token`, `fleet:setup:reset`, `fleet:user:reset-password` (run on a host as `fleet <group>:<verb>`). `InstallDocsTest` requires every command found by `glob(app/src/Command/*.php)` to be in `docs/installation.md` |
| `App\Command\Project`, `App\Command\Template`, `App\Command\Port` | `fleet:project\|template\|port:list\|show\|create\|update\|delete` (`port` has no `show` or `update`). **Sub-directories on purpose**: they stay out of `InstallDocsTest`'s flat glob and are covered by `CliDocsTest` against `docs/projects-and-templates.md`. A new command group goes in its own sub-directory, with a pattern in `install/bin/fleet` and `CliGroupsTest` |
| `App\Command\Secret` | `fleet:secret:list\|set\|unset` (`--project=`; the value comes from a hidden prompt or standard input, never argv; actor `cli`), covered by `SecretsDocsTest` against `docs/secrets.md`; `SecretOptions` is not a command |
| `App\Command\Support` | What those commands share: `JsonOutput` (`--format=table\|json`, `--json`, exit codes 0 done / 1 refused value / 2 not allowed now / 3 not found, the `{"error", "fields"}` document), `FieldErrors` (field name to `--option` or `<argument>`), `Shapes` (JSON for templates and ports). Not commands |
| `App\Twig\Components` | Twig components with logic (`MainNav`, `BottomNav`, `UserMenu`, `HostHealthCard`, and `IssueIdPreview`, the one LiveComponent: admin-only through `#[IsGranted]`) |
| `App\Ui` | UI support classes: `NavigationCatalog`, `NavItem`, `ProfilerToolbarSwitch` |
| `App\Message`, `App\MessageHandler` | Reserved for Messenger messages and handlers (from M0.04) |

New work follows these namespaces; add a new one only with a reason.

## Conventions

- **`CommandRunner` is the only way to run a host command.**
  `App\Fleet\Shell\CommandRunner::run(list<string> $argv, ?string $cwd = null, array $env = [], ?callable $onOutput = null, ?float $timeout = null): CommandResult`.
  `ProcessCommandRunner` runs argv without a shell (Symfony Process); `RecordingCommandRunner`
  records calls and replays scripted results (`expect()`, `calls()`, `callsMatching()`). `FLEET_RUNNER=process|recording`
  picks one: `process` in `.env`, `recording` in `.env.dev` and `.env.test`; prod refuses `recording`. Children get an allow-listed
  environment, not the app's secrets. Nothing else may start a process (`tests/Repo/HostCommandSeamTest`).
- **`App\Fleet\FleetPaths` owns every filesystem path** under `FLEET_HOME` (must be absolute):
  `var/fleet.sqlite`, `var/queue.sqlite`, `keys/`, `ssh/`, `assets/<project>/`, `instances/<id>/`,
  `logs/jobs/<job>.log`, `logs/instances/<instance>/<job>.log`, `locks/`, `keys/secret.key`,
  `ssh/{deploy,push}_ed25519[.pub]` and `ssh/known_hosts`, `staging/<target>/<file>` (rendered config waiting for the root
  helper), `authelia/users.yml`. It also owns the Caddy snippet directory (`FLEET_CADDY_DIR`, `/etc/caddy/fleet` in
  production) and derives `gate.conf` and `/etc/caddy/Caddyfile` from it. Never hard-code a path outside it. Ids are validated (no `..`, no `/`).
  `ensureLayout()` creates the tree with exact modes (`keys/`, `ssh/`, `staging/` 0700; everything holding data or logs 0750).
- **Two SQLite files**: `fleet.sqlite` (Doctrine `default`, domain data) and `queue.sqlite` (Doctrine
  `queue`, Messenger transports `jobs` and `failed` only). Entities live under `App\Domain\<Area>\`.
- **Locks** use Symfony Lock with a flock store in `FLEET_HOME/locks` (`LOCK_DSN`).
- **Mercure** is wired (bundle config, `MERCURE_*` variables). The installer runs a standalone hub (`fleet-mercure`, `127.0.0.1:3000`, legacy protocol mode) behind Caddy; the dev commands start none and nothing publishes yet.
- **Authentication** is by header, never by password in the app. In `prod` the app trusts `Remote-User`,
  `Remote-Groups`, `Remote-Email` and `Remote-Name` only from a request that carries the proxy secret: Caddy sets the
  FastCGI parameter `FLEET_PROXY_AUTH` (`TrustedProxyChecker::PROXY_PARAM`) to `FLEET_PROXY_SECRET`, compared with
  `hash_equals`; a secret shorter than 32 characters is ignored. `FLEET_TRUSTED_PROXIES` (default `127.0.0.1,::1`) is the
  dev/test peer list; the installer sets it empty. Group `FLEET_ADMIN_GROUP` (default `fleet-admins`) gives `ROLE_ADMIN`;
  every other authenticated user is `ROLE_MEMBER`. `/health` is public; `/setup*` is public but guarded by
  `App\Setup\SetupGate` (token wizard, see below); `/admin/*` needs `ROLE_ADMIN`; the rest needs a login.
- **Setup wizard.** `fleet:host:init` (installer) creates the host row, `fleet:setup:token` prints
  `https://<domain>/setup?token=...` (sha256 stored, valid 24 hours or until Finish). The exchange sets the signed
  `__Host-fleet_setup` cookie; every other `/setup` route needs it (`SetupState::permits`), and after completion all
  of them answer 404. The guard decides on the decoded path **and** the matched route and each controller guards itself:
  never decide on `getPathInfo()` alone. Finish does every side effect in a fixed order and marks completion last
  (`SetupFinisher`; php-fpm's `request_terminate_timeout` must stay >= `SetupFinisher::REQUIRED_REQUEST_TIMEOUT`, 240 s).
  Wizard forms carry the stateless CSRF token on top of the Strict cookie.
- **Secrets** go through `SecretStore` (sealed with `SecretBox`, key in `keys/secret.key`, ciphertext bound to scope and
  name), never into `.env`, logs, argv or the database in clear. Pages and commands that manage them go through `SecretService`
  (validation and the audit entry live there, controllers and commands never write the store themselves). A value is shown
  only by the admin-only reveal (POST, CSRF, audited before it is handed out, `Cache-Control: no-store`); every parameter that
  carries a value is `#[\SensitiveParameter]`. Only managed secrets (`UPPER_CASE`) are ever a `[[token]]`; host secrets never are. The runner strips the app's secrets from child
  environments. Private SSH keys are files (0600), the database holds only public keys and fingerprints.
- **Privileged apply.** The app never runs as root. A change to `/etc/caddy` or `/etc/authelia` is rendered by
  `SystemRenderer` into `staging/<target>/` and installed by `PrivilegedApply` (`sudo -n /usr/local/libexec/fleet/apply
  caddy|authelia`; `ApplyTarget` is the only argument). The helper (`install/libexec/apply`) validates with the real
  binary, installs atomically and rolls back; it is not a security boundary (the `fleet` user is in `docker`). Four
  places must agree and a repo test pins them: `ApplyTarget`, the sudoers line (`install/etc/sudoers-fleet-apply`), the helper's
  allowed targets and `PrivilegedApply::HELPER`. Add a target only by changing all four.
- **Dev/test stand-in** (does not exist in `prod`): identities from `FLEET_STAND_IN_*`; the role
  comes from the `X-Fleet-Stand-In-Role` header, else the `fleet_stand_in_role` cookie set by
  `GET /_dev/stand-in/{admin|member|none}?next=/path`, else `FLEET_STAND_IN_ROLE`.
- **All project data validation lives in `App\Fleet\Projects\Rule`.** The pages (forms), the `fleet project|template|port`
  commands and later milestones (webhooks, export) go through `ProjectService`, `TemplateService` and `NamedPortService`;
  controllers, forms and commands never validate on their own. A new limit or rule is a `Rule` class plus a unit test, then
  the field table in `docs/projects-and-templates.md`. Entities only store. Names are immutable. Git URLs, branches, command
  lines and issue id patterns are hostile input: the rules are allow-lists, and a consumer must still pass them as argv, never
  through a shell. `docs/projects-and-templates.md` is checked by `CliDocsTest` (commands, options, exit codes): change a
  command, change the guide.
- **Console commands**: `fleet <group>:<verb>` for groups the wrapper knows (`host`, `setup`, `user`, `project`, `template`,
  `port`, `secret`; `fleet project list` also works). Commands that read or write the database are tested with
  `RunsFleetCommands` against a real migrated database; stderr assertions need `capture_stderr_separately`.
- **Components beyond the managed list below.** `<twig:Textarea>` (several lines of text; the form theme renders Symfony's
  `TextareaType` with it, so never write a raw `<textarea>`), `<twig:Checkbox>`, `<twig:NativeSelect>` and `<twig:RadioGroup>` are
  installed and shown in the "Forms" section of `/admin/design-system`; `<twig:IssueIdPreview>` is a LiveComponent, not a
  design-system primitive. A new primitive needs its entry in the page's component list (`DesignSystemPageTest` fails
  otherwise).
- **Pages** extend `base.html.twig` (the app shell). Every page works at 390 px: no horizontal
  scroll, tap targets of at least 44 px. Any change to a page, template or the JS it loads needs a
  Playwright spec.
- Code style is PHP-CS-Fixer (`@Symfony`, risky rules, `declare(strict_types=1)`), PHPStan level 8.
- Commit messages: `<KEY> <type>: <summary>`; add `#time Nh` to the last commit of a ticket if
  your tracker uses Smart Commits.

## Commands

Run everything from the repository root. Use PHP 8.4 (`php8.4` where the default `php` is older);
Composer scripts call `@php`, so run Composer with the same binary
(`php8.4 "$(command -v composer)" …`).

```bash
cd app
composer install
php bin/console importmap:install
php bin/console tailwind:build            # add --watch while editing styles
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console messenger:setup-transports
php -S 127.0.0.1:8000 -t public           # dev server; APP_ENV=dev, FLEET_RUNNER=recording

composer run gates                        # the merge gate, see below
composer run cs                          # also: stan, lint, test (the parts of gates)
php vendor/bin/phpunit --testsuite Repo   # one suite: Unit | Integration | Functional | Repo
```

`composer run gates` = `assets` (importmap + Tailwind build), `cs` (php-cs-fixer check), `stan`
(PHPStan level 8), `lint` (Twig, YAML, container, `doctrine:schema:validate --skip-sync`) and `test`
(PHPUnit, all four suites).

**Gates before every merge to `develop`**: `composer run gates` in `app/`, then in `tests/playwright/`
`npm run typecheck` and `npx playwright test`. Never merge a red suite. Python CI tooling:
`FLEET_REQUIRE_SYSTEMD_ANALYZE=1 pytest -q tests/pytest` (as the pipeline does).

### Playwright modes

`tests/playwright/` runs the same specs two ways, chosen by `FLEET_E2E_BASE_URL`:

- **Unset (the local and CI gate):** Playwright starts the app itself (`support/start-app.mjs`: throw-away
  `FLEET_HOME`, `importmap:install`, `tailwind:build`, migrations, `php -S 127.0.0.1:8000`).
  If your shell exports `FLEET_E2E_BASE_URL`, unset it for this run:
  `env -u FLEET_E2E_BASE_URL npx playwright test`.
- **Set to a URL:** the suite targets an already-running server and starts nothing (for example a
  DDEV or php-fpm setup serving `app/public`). `FLEET_E2E_IGNORE_HTTPS_ERRORS=1` accepts a local
  certificate; `*.ddev.site` hosts are accepted automatically.

`npm run test:smoke` runs only `@smoke` specs. Other knobs: `FLEET_E2E_PORT` (default 8000),
`FLEET_PHP` (PHP binary for the launcher). Projects: `desktop` (1280 px) and `phone` (390 px).
`start-app.mjs` also initialises the host (`fleet:host:init`) and writes a fresh setup link to
`tests/playwright/.run/setup-url.txt`; `setup-wizard.desktop.spec.ts` walks the wizard once and so runs only in the
first mode (it skips itself against an external server, whose `FLEET_HOME` is not fresh).

### Test layout

`app/tests/`: `Unit/` (plain objects), `Integration/` (kernel and container wiring, SQLite, the
runner seam), `Functional/` (HTTP through `WebTestCase`, the wizard included), `Repo/` (repository rules: hygiene,
pipeline configuration, tag guard, design-system agent block, Python app gone, and the installer: its bash
steps, the root helper, units, sudoers, uninstall, docs). Some tests need a real binary (`FLEET_CADDY_BIN`,
`FLEET_MERCURE_BIN`, `ssh-keygen`, `shellcheck`) or run only as an unprivileged user, and skip otherwise. Each PHPUnit
process gets its own `FLEET_HOME` and forces `APP_ENV=test`. Counts and run guide:
`tests/README-tests.md`.

## Production trust and the installer

`install/install.sh --domain=<fqdn>` turns a Debian 13 host into a fleet (`docs/installation.md` is the user guide;
keep it and `README.md` in step with the code, `InstallDocsTest` checks flags, commands and `fleet.env` keys).
Facts to keep true when editing it:

- **Two stages.** `install.sh` (self-contained, wrapped in `main`) clones the ref into `/opt/ddev-fleet` as `fleet`
  (git never runs as root there), exports a root-owned copy of `install/` and execs its `run.sh`; privileged files
  (`apply`, sudoers, wrapper) are always installed from that copy, never from the fleet-writable checkout. Steps are
  `install/steps/NN-*.sh`, each defines `step_main`, each is idempotent; `run.sh` prints `==> <step>` lines only for
  steps. `--dry-run` prints the whole plan and changes nothing.
- **Never touched:** `sshd`, ufw / iptables / nftables, fail2ban, unattended-upgrades (a lockout risk; hardening is the
  operator's). `/opt/ddev-fleet` stays `fleet:fleet`. Authelia's `/etc/tmpfiles.d/authelia.conf` mask is installed
  before the package. Caddy snippet directories are `fleet:caddy` 2750 (setgid).
- **Env files:** `/etc/ddev-fleet/fleet.env` (`root:fleet` 0640; app, workers, CLI, php-fpm via a unit drop-in),
  `mercure.env` (0600), `/etc/caddy/fleet.env` (written by `apply caddy`). Only missing keys are added and secrets are
  generated once. The pool pins `APP_ENV=prod`; `ProductionGuard` refuses an unsafe production environment (empty
  `APP_SECRET`, `FLEET_RUNNER=recording`, `FLEET_HOME` inside the project, trusted proxies together with a proxy
  secret).
- **Caddy** gets its config from the app: the main `Caddyfile` through `apply caddy`, the sign-in gate (`gate.conf`, mode
  `setup` or `authelia`) written by `GateWriter` and loaded with `caddy reload`. It strips every `Remote-*` / `Remote_*`
  request header on all routes before the gate. Setup mode lets through only `/setup`, `/assets/*` and `/health`.
  Only `<domain>` and `auth.<domain>` are served; instance and wildcard sites arrive in M0.04 / M0.07.
- **Restarts on re-run:** Caddy and Authelia are always restarted (Authelia drops every session); php-fpm and the
  workers when the code or env changed.
- Not shipped yet (do not assume it exists): `fleet-scheduler`, `fleet-boot` / `fleet-tmux`, wildcard and instance Caddy
  sites, DB-driven Authelia access rules, Authelia self-service password reset (disabled until M0.10; use
  `fleet user:reset-password`), host hardening.
- Installer tests that need root or real binaries skip without them; run `shellcheck -x install/**/*.sh install/libexec/apply
  install/bin/fleet` before committing a script.

## The 0.x host layout (Ansible, legacy)

`ansible/` still describes the 0.x host: it installs the Python package and units, which no longer
exist on `develop`, and the 1.x installer does not use it (`ansible/LEGACY-0x.md`). Do not run it against a live
host from `develop`, and never run the full
`site.yml` against a live host at all (the `fleet_service` role rewrites the checkout's git
remote). Apply individual roles through the scoped playbooks beside it (`caddy-only.yml`,
`hardening.yml`, `fleet-units.yml`, `security-probe.yml`, `authelia.yml`, `claude-onboarding.yml`),
`--check --diff` first. The roles are not reshaped for 1.x. Roles, in `site.yml` order:

| Role | Responsibility |
|---|---|
| `base` | apt cache + base packages (git, rsync, curl, tmux, unattended-upgrades, …), periodic unattended-upgrades enablement |
| `docker` | Docker CE apt repo + packages, `docker.service` enabled |
| `fleet_user` | The `fleet` system user, `/srv/fleet` tree, `.secrets`, `fleet.yml` seed, deploy/push SSH keys, forge `known_hosts` |
| `shell_profile` | System-wide interactive shell prompt/aliases/exports for root and `fleet`, plus the `CLAUDE_CODE_OAUTH_TOKEN` export hook |
| `ddev` | DDEV apt repo + package, global router config (loopback-only ports), mkcert local CA |
| `claude_cli` | Node.js/npm + the Claude Code CLI, so `claude setup-token` can mint `CLAUDE_CODE_OAUTH_TOKEN` |
| `caddy` | Caddy apt repo + package, fleet-owned Caddy snippet dirs, seeded admin-auth default credentials, the rendered Caddyfile, the `50-fleet-sandbox.conf` systemd sandbox drop-in (FLE-1) |
| `fleet_service` | Clones/updates the product repo into `{{ fleet_opt_dir }}`, installs the editable venv, deploys `fleet.service` + `fleet-boot.service` (both sandboxed via the shared `fleet-sandbox.inc.j2`, FLE-1; toggle `fleet_systemd_sandbox_enabled`) + `fleet-tmux.service` (owns the `fleet` tmux session in its own cgroup, deliberately unsandboxed; tag `fleet_tmux`) + the `fleet` CLI wrapper |
| `network_hardening` | UFW (deny-incoming/allow-outgoing, SSH/80/443 allows, plus the registry's named ports staged via `fleet-ufw-sync` before `ufw enable`), the `DOCKER-USER` guard in `/etc/ufw/after.rules`, and the UFW dead-man's switch (`fleet-ufw-deadman.timer`/`.service`, boot-time `fleet-ufw-deadman-bootcheck.service`, `fleet-firewall-confirm`) |
| `security_hardening` | Unattended-upgrades tuning (auto-reboot hardcoded off), `needrestart` auto-restart trap, Docker `live-restore`+log limits (applied by a Docker *reload*, never a restart: a restart stops every instance), SSH drop-in, fail2ban, conservative sysctl, scoped auditd, conditional `/tmp` hardening, msmtp + the sandboxed `fleet-reboot-notify` timer/service, and a closing `systemd-analyze security` check of the fleet units + caddy + authelia (FLE-1; see `docs/v0/operations.md`) |

`network_hardening` and `security_hardening` are independently optional: every task in both gates
on a single top-level `import_tasks ... when:`, so a bare `site.yml` run stays inert for both.

## Cross-references

- `docs/README.md` — documentation index with the status of each document.
- `docs/installation.md` — installing 1.x on a host: installer, setup wizard, recovery, security model, uninstalling.
- `docs/projects-and-templates.md` — projects, templates and named ports: fields and limits, the pages, the `fleet project|template|port` commands, differences from 0.x.
- `docs/secrets.md` — secrets: fleet-wide and project secrets, names and values, `[[token]]` placeholders and the "no placeholder" warning, reveal and the audit log, the `fleet secret:*` commands, the security model.
- `docs/development.md` — setup, environment variables, stand-in, adding a component.
- `docs/RELEASING.md` — versioning, CHANGELOG conventions, release and hotfix procedure.
- `docs/README-ci.md` — pipelines, tag guard, the systemd security check and its baseline.
- `docs/v0/` — the 0.x (Python) reference, re-implemented milestone by milestone.
- `tests/README-tests.md` — suites and how to run them.

If a tool wrapper condenses command output (RTK), treat the condensed output as complete; re-run
a command as `rtk bypass <cmd>` only when its result is unusable (empty when output was clearly
expected, contradicting its exit code, or garbled).

<!-- symfony-design-system:start -->
## Design system

The UI has a design system documented at [`/admin/design-system`](/admin/design-system) (route `admin_design_system`, admins only). The page previews every primitive - colours, typography, structure, base styles and elements - and shows the exact Twig to use.

When implementing UI:

1. **Check the design system first.** Before writing markup or styles, read `/admin/design-system` and the components in `templates/components/`. Use the tokens (`bg-page`, `bg-surface`, `text-ink-body`, `text-ink-display`, `text-ink-muted`, `border-hairline`, `bg-brand`, `text-brand-darker dark:text-brand`) and the components (`<twig:Button>`, `<twig:Input>`, `<twig:Label>`, `<twig:Badge>`, `<twig:Card>`, `<twig:Alert>`, `<twig:Dialog>`, `<twig:Sheet>`, `<twig:DropdownMenu>`, `<twig:Table>`, `<twig:ThemeToggle>`, `<twig:PageHeader>`).

2. **Do not invent ad-hoc styles.** No raw hex values, no default palette colours (`bg-gray-50`, `text-blue-600`), no raw font sizes or one-off utilities when a token or component exists, no new variant systems beside the kit's.

3. **Use bare semantic HTML for text elements.** `<h1>` to `<h6>`, `<p>`, `<a>`, `<strong>`, `<blockquote>`, `<ul>`, `<ol>`, `<hr>`, `<label>` and `<legend>` get their size, colour, weight and spacing from the base styles. Do not put `text-xl`, `font-semibold`, `text-ink-display` or `tracking-tight` on them: write `<h1>Projects</h1>`, not `<h1 class="text-2xl font-semibold">Projects</h1>`. A page's title is one `<h1>` (use `<twig:PageHeader>`). Layout utilities (`mt-4`, `max-w-md`, `flex`) are fine. A label wrapping a checkbox or radio takes `font-normal text-ink-body`.

4. **Meet the contrast pairings.** Text on a `*-faded` background is `text-*-darker dark:text-*` (for example `bg-brand-faded text-brand-darker dark:text-brand`). Details are in the Colors section of the page.

5. **Every page works at 390 px.** No horizontal scroll, tap targets at least 44 px (`h-11 md:h-8`).

6. **If a needed element is missing, propose it as a design-system addition** before building a one-off, for example: "There is no component for X. Add it to the design system (a component plus a section on `/admin/design-system`) or do a one-off?" Default to the addition.

7. **Re-run the `symfony-design-system` skill** to add sections, refresh tokens or re-scan for UI that bypasses the system; it merges without touching anything outside its markers.
<!-- symfony-design-system:end -->
