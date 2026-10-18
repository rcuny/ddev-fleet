---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-18
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
| `develop` | **1.x** (Symfony 8) | The rewrite in `app/`. No Python app, no `fleet.yml`, no `bootstrap.sh`. Not deployable yet. |

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
- **The 1.x app must not face the Internet yet** (see "Known limitation: trusted proxy").
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
| `ansible/` | Provisioning for the 0.x host layout; reshaped for 1.x in milestone M0.02 |
| `docs/` | Documentation index in `docs/README.md`; `docs/v0/` holds the 0.x reference |
| `demo/`, `scripts/` | A minimal DDEV project the 0.x installer seeds as `demo`; the Renovate merge helper |

### Namespaces (`app/src/`, `App\`)

| Namespace | Holds |
|---|---|
| `App\Domain\<Area>\` | Doctrine entities and repositories (attribute mapping; empty until M0.02) |
| `App\Doctrine` | Doctrine plumbing: `SqlitePragmaMiddleware` (WAL, `busy_timeout=5000`, `foreign_keys=ON` on every connection) |
| `App\Fleet` | `FleetPaths` and host-driving services; `App\Fleet\Shell` is the `CommandRunner` seam |
| `App\Health` | `HealthChecker` / `HealthReport` behind `GET /health` |
| `App\Security` | `FleetUser`, `RemoteUserAuthenticator`, `TrustedProxyChecker`, `ForbiddenHandler`; `App\Security\StandIn` (dev/test only) |
| `App\Controller` | Controllers (`Home`, `Health`, `Placeholder`, `AdminFallback`; `Admin\` for admin pages) |
| `App\Twig\Components` | Twig components with logic (`MainNav`, `BottomNav`, `UserMenu`) |
| `App\Ui` | UI support classes: `NavigationCatalog`, `NavItem`, `ProfilerToolbarSwitch` |
| `App\Message`, `App\MessageHandler`, `App\Command` | Reserved for Messenger messages, handlers and `fleet:*` console commands (from M0.04) |

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
  `logs/jobs/<job>.log`, `logs/instances/<instance>/<job>.log`, `locks/`. Never hard-code a path outside it. Ids are validated (no `..`, no `/`).
  `ensureLayout()` creates the tree with exact modes (`keys/`, `ssh/` 0700; everything holding data or logs 0750).
- **Two SQLite files**: `fleet.sqlite` (Doctrine `default`, domain data) and `queue.sqlite` (Doctrine
  `queue`, Messenger transports `jobs` and `failed` only). Entities live under `App\Domain\<Area>\`.
- **Locks** use Symfony Lock with a flock store in `FLEET_HOME/locks` (`LOCK_DSN`).
- **Mercure** is wired (bundle config, `MERCURE_*` variables) but no hub is started by the dev commands and nothing publishes yet.
- **Authentication** is by header, never by password. In `prod` the app trusts `Remote-User`,
  `Remote-Groups`, `Remote-Email` and `Remote-Name` **only** when `REMOTE_ADDR` is in
  `FLEET_TRUSTED_PROXIES` (default `127.0.0.1,::1`). Group `FLEET_ADMIN_GROUP` (default
  `fleet-admins`) gives `ROLE_ADMIN`; every other authenticated user is `ROLE_MEMBER`.
  `/health` is public; `/admin/*` needs `ROLE_ADMIN`; the rest needs a login.
- **Dev/test stand-in** (does not exist in `prod`): identities from `FLEET_STAND_IN_*`; the role
  comes from the `X-Fleet-Stand-In-Role` header, else the `fleet_stand_in_role` cookie set by
  `GET /_dev/stand-in/{admin|member|none}?next=/path`, else `FLEET_STAND_IN_ROLE`.
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

### Test layout

`app/tests/`: `Unit/` (plain objects), `Integration/` (kernel and container wiring, SQLite, the
runner seam), `Functional/` (HTTP through `WebTestCase`), `Repo/` (repository rules: hygiene,
pipeline configuration, tag guard, design-system agent block, Python app gone). Each PHPUnit
process gets its own `FLEET_HOME` and forces `APP_ENV=test`. Counts and run guide:
`tests/README-tests.md`.

## Known limitation: trusted proxy (until M0.02)

Header authentication trusts the TCP peer address only. With Caddy and PHP-FPM on the same host
every request arrives from loopback, so any local process, or anything that can reach the app
without passing through the authenticating proxy, can assert an identity. Nothing runs behind
Caddy yet, so this is accepted for M0.01; the installer milestone (M0.02) adds a shared-secret
header (or loopback TCP) between Caddy and the app and sets the production environment. Related
requirements for M0.02: Caddy must strip both `Remote-User` and `Remote_User` from client
requests (FastCGI maps both to `HTTP_REMOTE_USER`); `::ffff:127.0.0.1` does not match
`127.0.0.1` (it fails closed); a production deployment must set `APP_ENV=prod`, an absolute
`FLEET_HOME`, `APP_SECRET` and `MERCURE_JWT_SECRET` in the real environment (the committed `.env`
defaults are for development and are project-relative).

## The 0.x host layout (Ansible, until the installer milestone)

`ansible/` still describes the 0.x host: it installs the Python package and units, which no longer
exist on `develop`. Do not run it against a live host from `develop`, and never run the full
`site.yml` against a live host at all (the `fleet_service` role rewrites the checkout's git
remote). Apply individual roles through the scoped playbooks beside it (`caddy-only.yml`,
`hardening.yml`, `fleet-units.yml`, `security-probe.yml`, `authelia.yml`, `claude-onboarding.yml`),
`--check --diff` first. Milestone M0.02 reshapes the roles for 1.x. Roles, in `site.yml` order:

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
