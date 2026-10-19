---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Tests

The 1.x app is tested at three levels: PHPUnit inside the Symfony project (`app/tests/`),
Playwright in a real browser (`tests/playwright/`), and a small set of Python tests that remain
for the CI tooling and the Ansible roles (`tests/pytest/`). The commands below are run from the
repository root unless stated otherwise; use `php8.4` where the default `php` is older than 8.4.

## Current counts (2026-10-19)

| Suite | Where | Count |
|---|---|---|
| PHPUnit `Unit` | `app/tests/Unit/` | 945 tests |
| PHPUnit `Integration` | `app/tests/Integration/` | 412 tests |
| PHPUnit `Functional` | `app/tests/Functional/` | 448 tests |
| PHPUnit `Repo` | `app/tests/Repo/` | 394 tests (13 skip without their tool: 10 need `FLEET_CADDY_BIN`, 1 needs `FLEET_MERCURE_BIN`, 1 only runs as root) |
| Playwright | `tests/playwright/` | 153 (9 spec files, including `host-settings.spec.ts`; 125 run and 28 skip on a default run; each spec runs on the `desktop` and the `phone` project where it applies; `projects.spec.ts` has 13 phone-only tests, skipped on desktop). Against an external server (`FLEET_E2E_BASE_URL` set) the 8 wizard tests skip: 69 run, 21 skipped. Not counted: `live-install.desktop.spec.ts` (6 tests, only with `FLEET_LIVE_SETUP_URL`, skipped otherwise) and `live-projects.desktop.spec.ts` (9 tests against an installed host, only with `FLEET_E2E_BASE_URL` and `FLEET_LIVE_ADMIN_PASSWORD`; `FLEET_LIVE_PHASE=build\|ports\|clean` splits it around host-side checks) |
| pytest | `tests/pytest/` | 255 passed, 2 skipped (a missing optional binary) |

PHPUnit totals 2199 tests (13 skipped). Update this table when the numbers change.

## Layout

```
app/tests/
  bootstrap.php      forces APP_ENV=test, gives each PHPUnit process its own FLEET_HOME and migrates its database once
                     (any test may read the domain tables; isolated tests use FleetDatabase, which migrates a fresh home)
  Unit/              plain objects: FleetPaths, the command runners, health, security, navigation, tokens
  Integration/       kernel and container wiring: Doctrine/SQLite, Messenger, Lock, Mercure, prod security
  Functional/        HTTP through WebTestCase: access control, app shell, design-system page, health, stand-in
  Repo/              repository rules: hygiene (no owner or workspace references), pipeline configuration,
                     tag guard, the design-system block in CLAUDE.md, the Python app being gone, and the
                     installer: Installer*Test (flags, dry-run plan, DNS check, idempotency, safety), the root
                     helper (ApplyHelperTest), units, sudoers, env file, fleet wrapper, uninstall, and
                     InstallDocsTest (docs/installation.md, README.md, CLAUDE.md and the CHANGELOG agree with the code),
                     CliDocsTest (docs/projects-and-templates.md documents every project, template and port command and
                     option and names nothing else; CHANGELOG, indexes and CLAUDE.md point at it)
  Support/           shared helpers (a temporary FLEET_HOME, FleetDatabase, WizardTestCase, AdminTestCase for admin pages)
tests/playwright/    TypeScript @playwright/test, its own npm package
  playwright.config.ts   projects "desktop" (1280 px) and "phone" (390 px); starts the app unless FLEET_E2E_BASE_URL is set
  support/               env.ts, start-app.mjs (the self-started app), shell.ts, standIn.ts, axe.ts, wizard.ts,
                         projects.ts (unique names, create/delete a project through the UI, Turbo-safe navigation)
  *.spec.ts              shell, design-system, nav.desktop, nav.phone, dark-mode, a11y (axe, both themes),
                         setup-wizard.desktop (the setup wizard end to end), projects (project and template pages)
tests/pytest/        Python: ansible/ (role and template checks, systemd sandbox scoring), repo/ (systemd security
                     scorer and report, the Renovate merge helper)
```

## Running

### PHPUnit (the app)

```bash
cd app
composer run gates                         # assets, php-cs-fixer, PHPStan 8, lint, then all four suites
php vendor/bin/phpunit                     # all suites
php vendor/bin/phpunit --testsuite Unit    # Unit | Integration | Functional | Repo
php vendor/bin/phpunit --filter RepoHygiene
```

PHPUnit fails on deprecations, notices and warnings. It forces `APP_ENV=test` and a temporary
`FLEET_HOME`, whatever the shell exports, and `FLEET_RUNNER=recording`, so no test runs a host command.

### Playwright (browser end to end)

One-time setup: `cd tests/playwright && npm ci && npx playwright install chromium` (add `--with-deps` as root).

```bash
cd tests/playwright
npm run typecheck                                  # tsc --noEmit
env -u FLEET_E2E_BASE_URL npx playwright test      # the self-contained gate
npm run test:smoke                                 # @smoke specs only
npx playwright test -g "dark mode"                 # one group, by title
npx playwright test --project=phone --headed       # watch the 390 px project
npx playwright show-report                         # the HTML report of the last run
```

With `FLEET_E2E_BASE_URL` unset, Playwright starts the app itself (`support/start-app.mjs`: a throw-away
`FLEET_HOME`, `importmap:install`, `tailwind:build`, migrations, an initialised host with a fresh setup link
written to `.run/setup-url.txt`, `php -S 127.0.0.1:8000`) and stops it afterwards. With it set to a URL, the suite targets that already-running server and starts nothing
(`FLEET_E2E_IGNORE_HTTPS_ERRORS=1` accepts a local certificate; `*.ddev.site` hosts are accepted
automatically). Other variables: `FLEET_E2E_PORT` (default 8000) and `FLEET_PHP` (the PHP binary of the
launcher). `setup-wizard.desktop.spec.ts` walks the token-gated setup wizard once, in order, in one browser session (refusal without
a cookie, token exchange and its Strict cookie, every step at 1280 px with axe in both themes, the real walk-through at 390 px
ending in Finish, then what every wizard URL answers). It needs a host that is installed but not set up, which only the
self-started app provides, so it skips itself against an external server (the companion's served app keeps a persistent
`FLEET_HOME`). `support/wizard.ts` (`completeWizard`, `readSetupUrl`) is shared with a live-server spec; `FLEET_LIVE_SETUP_URL`
overrides the link file. Each run re-creates the setup link and clears the token-attempt throttle (`cache.rate_limiter`).
`projects.spec.ts` creates its own data: every test owns a project with a unique name (`uniqueName()`) and deletes it
in `finally`, so it passes repeatedly against the shared dev database of the companion and in parallel. It covers the
list, create, edit, templates, ports and delete dialog at both widths, the member 403, the issue id tester, and (phone
project only) the worst-case widths (40-char name, 200-char git URL, 64-char environment), 44 px targets and axe in both
themes. Interactions that depend on a lazily loaded Stimulus controller (dialog, live tester) retry until
the controller answers, because a busy host loads them after the HTML is interactive.
The specs send `X-Fleet-Profiler-Toolbar: 0` so the Symfony toolbar cannot cover the phone
bottom bar, and the shell specs assert that no console error or uncaught page error was seen (`support/shell.ts`).

### Installer and real-binary tests

The installer is bash and is never run for real in the gates (no root, no server). `app/tests/Repo/Installer*Test`,
`ApplyHelperTest`, `UnitsTest`, `EnvFileTest`, `FleetWrapperTest` and `UninstallTest` run its scripts with `--dry-run`,
against scratch directories and with stubbed commands, and assert on the plan, the file modes and the unit files.
Some checks need a tool and skip without it:

```bash
shellcheck -x install/*.sh install/lib/*.sh install/steps/*.sh install/libexec/apply install/bin/fleet   # also a test
# the rendered Caddyfile against the real Caddy (a build with the OVH DNS module, see RenderedCaddyfileValidatesTest)
FLEET_CADDY_BIN=$PWD/app/var/tools/caddy php8.4 app/vendor/bin/phpunit app/tests/Repo/RenderedCaddyfileValidatesTest.php
# the Mercure hub configuration against the pinned hub (v1.1.2, install/steps/95-units.sh)
FLEET_MERCURE_BIN=/path/to/mercure php8.4 app/vendor/bin/phpunit app/tests/Repo/UnitsTest.php
```

A few tests assert the behaviour of a non-root run and skip when the suite runs as root (and one runs only as root).
CI installs `openssh-client`, `shellcheck` and `sudo` so the `ssh-keygen` and shell checks are not skipped. The first
real install on a host is a manual verification, not part of the gates.

### pytest (CI tooling and Ansible roles)

```bash
pip install -r ci/requirements-dev.txt
FLEET_REQUIRE_SYSTEMD_ANALYZE=1 pytest -q tests/pytest     # as the pipeline does; needs systemd-analyze
pytest -q tests/pytest/repo                                  # one area
```

Without `FLEET_REQUIRE_SYSTEMD_ANALYZE`, the unit-scoring tests skip when `systemd-analyze` is missing;
with it, a missing binary is a failure so the suite cannot silently shrink (CI sets it). Two more tests
use optional binaries: `FLEET_TEST_CADDY_BIN` (validates a rendered Caddyfile) and
`FLEET_TEST_AUTHELIA_BIN` (validates the Authelia configuration, falls back to `authelia` on `PATH`);
without them those tests skip. The Python code of the 0.x app and its pytest, Node and gpg tests are
not on `develop`; they are on `main`.

## Conventions

- Put a PHPUnit test where it belongs: `Unit` for plain objects, `Integration` when the container or a
  database is involved, `Functional` for an HTTP round trip, `Repo` for a rule about the repository.
  Classes live in `App\Tests\<Suite>\`.
- A change to a page, a template or the JavaScript it loads also needs a Playwright spec: unit and
  functional tests cannot see layout, focus, dark mode or contrast. Check both projects (1280 px and 390 px).
- Never touch real state: tests use the temporary `FLEET_HOME` and the recording runner. A test that
  needs a real host command does not belong in the gates.
- Public repository: test data uses `fleet.example.com`, `demo` and `blog`. `app/tests/Repo/RepoHygieneTest.php`
  fails on owner hostnames, private project names and workspace paths.
- CI (`bitbucket-pipelines.yml`) runs the same commands: `composer run gates`, then in `tests/playwright`
  `npm ci`, `npx playwright install --with-deps --only-shell chromium`, `npm run typecheck` and
  `npx playwright test`, plus the pytest step above. Details: [`docs/README-ci.md`](../docs/README-ci.md).
