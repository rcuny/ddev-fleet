---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-18
Type: documentation
---

# Developing the 1.x app

How to run, test and extend the Symfony app on `develop`. The conventions that apply to every
change (namespaces, the `CommandRunner` seam, `FleetPaths`) are in [`CLAUDE.md`](../CLAUDE.md);
the contribution workflow is in [`CONTRIBUTING.md`](../CONTRIBUTING.md).

## Prerequisites

| Tool | Version | Used for |
|---|---|---|
| PHP | 8.4 (CLI) | the app, PHPUnit, PHPStan, `php -S`. Extensions: `ctype`, `dom`, `iconv`, `mbstring`, `pdo_sqlite`, `sodium`, `xml` (`composer check-platform-reqs`) |
| Composer | 2.x | PHP dependencies and the `gates` script |
| Node.js | 22 | Playwright and TypeScript only; the app has **no** Node build |
| Playwright browser | Chromium | `npx playwright install chromium` (add `--with-deps` as root on Linux) |
| Network | - | the first `tailwind:build` downloads the standalone Tailwind binary (GitHub releases) and `importmap:install` downloads the JavaScript packages (jsDelivr) |

If your default `php` is older than 8.4, call `php8.4` explicitly and run Composer with the
same binary: `php8.4 "$(command -v composer)" install`. Composer scripts use `@php`, so they
follow the binary that started Composer.

## First run

```bash
cd app
composer install
php bin/console importmap:install
php bin/console tailwind:build                 # add --watch while you edit templates or styles
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console messenger:setup-transports
php -S 127.0.0.1:8000 -t public
```

Open <http://127.0.0.1:8000>. The defaults come from `app/.env` and `app/.env.dev`
(`APP_ENV=dev`, `FLEET_RUNNER=recording`, `FLEET_HOME=app/var/fleet-home`); override them in
`app/.env.local` (git-ignored) or in the real environment, which always wins. Check the wiring
with `curl http://127.0.0.1:8000/health`:

```json
{"status":"ok","checks":{"fleet_db":"ok","queue_db":"ok","home_writable":"ok"}}
```

`/health` answers `503` with the failing checks marked when a SQLite file cannot be queried or
`FLEET_HOME` is not writable; the cause goes to the log, never to the response.

## Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `APP_ENV` | `dev` in `.env`; `test` under PHPUnit | Symfony environment. Production must set `prod` |
| `APP_SECRET` | dev/test values in `.env.dev` / `.env.test` | Symfony secret; production sets its own |
| `FLEET_HOME` | `app/var/fleet-home` | Absolute directory for everything the app persists (`FleetPaths`). Relative or empty values are rejected |
| `FLEET_RUNNER` | `process` in `.env`; `recording` in `.env.dev` and `.env.test` | `process` runs host commands; `recording` records them and replays scripted results |
| `FLEET_TRUSTED_PROXIES` | `127.0.0.1,::1` | IPs and CIDRs allowed to assert `Remote-*` identity headers |
| `FLEET_ADMIN_GROUP` | `fleet-admins` | Members of this group get `ROLE_ADMIN` |
| `FLEET_STAND_IN_ROLE` | `admin` (dev/test) | Default stand-in role: `admin`, `member` or `none` |
| `FLEET_STAND_IN_ADMIN_USER`, `_ADMIN_GROUPS` | `dev-admin`, `fleet-admins` | Stand-in admin identity |
| `FLEET_STAND_IN_MEMBER_USER`, `_MEMBER_GROUPS` | `dev-member`, `demo` | Stand-in member identity |
| `FLEET_PROFILER_TOOLBAR` | `1` | `0` hides the Symfony toolbar in `dev` (it would cover the phone bottom bar) |
| `DATABASE_URL`, `QUEUE_DATABASE_URL` | `FLEET_HOME/var/fleet.sqlite`, `…/queue.sqlite` | The two SQLite databases |
| `LOCK_DSN` | `flock://FLEET_HOME/locks` | Symfony Lock store |
| `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET`, `MERCURE_JWT_ISSUER` | hub on `127.0.0.1:3000`; dev/test secrets in `.env.dev` / `.env.test` | Mercure wiring. No hub is started by these commands and nothing publishes yet |
| `FLEET_E2E_BASE_URL` | unset | Playwright: target an already-running server instead of starting one |
| `FLEET_E2E_PORT`, `FLEET_PHP`, `FLEET_E2E_IGNORE_HTTPS_ERRORS` | `8000`, `php8.4` or `php`, unset | Playwright: port of the self-started app, its PHP binary, accept a local certificate |

PHPUnit forces `APP_ENV=test` and gives every process its own temporary `FLEET_HOME`, whatever
your shell exports.

## The recording runner

Every host command goes through `App\Fleet\Shell\CommandRunner`. With `FLEET_RUNNER=recording`
(the default in `dev` and `test`) nothing is executed: `RecordingCommandRunner` returns scripted
results and keeps the calls for assertions.

```php
$runner = self::getContainer()->get('fleet.test_alias.recording_command_runner');
$runner->expect(['ddev', 'list'], new CommandResult(0, '[]', ''));   // script a result by argv prefix
// ... exercise the code ...
self::assertCount(1, $runner->callsMatching(['ddev', 'list']));
```

Set `FLEET_RUNNER=process` to run real commands (Symfony Process, argv without a shell). No code
path in this milestone needs it.

## Signing in during development: the stand-in

There is no proxy in `dev` and `test`, so a stand-in authenticator signs you in. It does not exist
in `prod`. The role is resolved from, in order:

1. the `X-Fleet-Stand-In-Role` request header (`admin`, `member`, `none`) - what the browser tests use;
2. the `fleet_stand_in_role` cookie, set by visiting `/_dev/stand-in/admin`, `/_dev/stand-in/member` or
   `/_dev/stand-in/none` (optional `?next=/path`, same-site paths only); the app shell has the same switch;
3. `FLEET_STAND_IN_ROLE`.

`none` means unauthenticated (a login is required). A request that carries `Remote-User` is never
treated as a stand-in. To exercise the real header authenticator from your machine, send the headers
yourself; the peer is loopback, which is trusted by default:

```bash
curl -H 'Remote-User: alice' -H 'Remote-Groups: fleet-admins' http://127.0.0.1:8000/admin/design-system   # 200
curl -H 'X-Fleet-Stand-In-Role: member' http://127.0.0.1:8000/admin/design-system                       # 403
```

### Known limitation: the proxy trust boundary

In production the app trusts `Remote-User`, `Remote-Groups`, `Remote-Email` and `Remote-Name` when the
TCP peer is in `FLEET_TRUSTED_PROXIES`. With the reverse proxy and PHP-FPM on one host, that peer is
always loopback, so anything able to reach the app locally without passing through the proxy can
claim any identity. Nothing runs behind a proxy yet, which is why this is accepted for the foundation
milestone; **do not expose the 1.x app to the Internet before the installer milestone (M0.02)**,
which adds a shared-secret header (or loopback TCP) between the proxy and the app, requires the proxy
to strip both `Remote-User` and `Remote_User` from client requests, and sets production values for
`APP_ENV=prod`, an absolute `FLEET_HOME`, `APP_SECRET` and `MERCURE_JWT_SECRET` (the committed `.env`
defaults are for development).

## Gates

```bash
cd app && composer run gates
cd tests/playwright && npm ci && npm run typecheck && env -u FLEET_E2E_BASE_URL npx playwright test
```

`composer run gates` runs, in order: `assets` (`importmap:install`, `tailwind:build`), `cs`
(php-cs-fixer, check only), `stan` (PHPStan level 8 after warming the dev cache), `lint` (Twig, YAML,
container, `doctrine:schema:validate --skip-sync`) and `test` (PHPUnit: `Unit`, `Integration`,
`Functional`, `Repo`). Each part is also a script (`composer run cs`, `stan`, `lint`, `test`);
one suite is `php vendor/bin/phpunit --testsuite Repo`. To repair formatting: `php vendor/bin/php-cs-fixer fix`.

### Browser tests

`tests/playwright/` has two projects, `desktop` (1280 px) and `phone` (390 px), and runs in two modes:

| `FLEET_E2E_BASE_URL` | Behaviour |
|---|---|
| unset | Playwright starts the app (`support/start-app.mjs`: throw-away `FLEET_HOME`, assets, migrations, `php -S`) and stops it afterwards. This is the local and CI gate |
| set to a URL | the suite targets that running server and starts nothing; add `FLEET_E2E_IGNORE_HTTPS_ERRORS=1` for a local certificate (`*.ddev.site` hosts are accepted automatically) |

If your shell or container exports `FLEET_E2E_BASE_URL`, run the self-contained gate with
`env -u FLEET_E2E_BASE_URL npx playwright test`. `npm run test:smoke` runs the `@smoke` specs only;
`npx playwright show-report` opens the last HTML report. The suite sets `X-Fleet-Profiler-Toolbar: 0`
on every request so the Symfony toolbar cannot cover the phone bottom bar.

## Adding a UI component

The UI is Twig + Symfony UX + the UX Toolkit **shadcn** kit + Tailwind v4 on AssetMapper. Design
tokens (colours, fonts, the bridge from the kit's variables to ours) live in
`app/assets/styles/design-system.css`; app-specific CSS goes in `app/assets/styles/app.css`. The
design-system page at `/admin/design-system` previews every primitive with the exact Twig to use.

```bash
cd app
php bin/console ux:install <recipe> --kit=shadcn       # e.g. "tabs": copies templates/components/Tabs*.twig
php bin/console ux:icons:import lucide:<icon-name>     # vendors an icon into assets/icons/
php bin/console tailwind:build
```

`ux:install` is available in `dev` and `test` only. After adding a component, add a section for it to
the design-system page, make sure it works at 390 px (tap targets of at least 44 px, no horizontal
scroll) and add or extend a Playwright spec. Use tokens and components instead of raw colours or ad-hoc
utilities; the rules for agents are in the "Design system" block of [`CLAUDE.md`](../CLAUDE.md).

## Troubleshooting

- `tailwind:build` or `importmap:install` fails offline: both download on first use; run them once with network access.
- A page renders without styles: `app/var/tailwind/` or `public/assets/` is stale; rerun `composer run assets`.
- Playwright cannot start a browser: `npx playwright install chromium`, plus `--with-deps` as root on a fresh Linux host.
- `Address already in use` from Playwright: another server holds `FLEET_E2E_PORT`; stop it or set another port.
- PHPUnit looks at the wrong environment: it should not (`APP_ENV=test` is forced); if tests touch a
  real `FLEET_HOME`, check that `phpunit.dist.xml` is the file in use.
