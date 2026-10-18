---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-18
Type: documentation
---

# Tests

The 1.x app is tested at three levels: PHPUnit inside the Symfony project (`app/tests/`),
Playwright in a real browser (`tests/playwright/`), and a small set of Python tests that remain
for the CI tooling and the Ansible roles (`tests/pytest/`). The commands below are run from the
repository root unless stated otherwise; use `php8.4` where the default `php` is older than 8.4.

## Current counts (2026-10-18)

| Suite | Where | Count |
|---|---|---|
| PHPUnit `Unit` | `app/tests/Unit/` | 114 tests |
| PHPUnit `Integration` | `app/tests/Integration/` | 25 tests |
| PHPUnit `Functional` | `app/tests/Functional/` | 70 tests |
| PHPUnit `Repo` | `app/tests/Repo/` | 63 tests |
| Playwright | `tests/playwright/` | 38 (6 spec files; each spec runs on the `desktop` and the `phone` project where it applies) |
| pytest | `tests/pytest/` | 255 passed, 2 skipped (a missing optional binary) |

PHPUnit totals 272 tests. Update this table when the numbers change.

## Layout

```
app/tests/
  bootstrap.php      forces APP_ENV=test and gives each PHPUnit process its own FLEET_HOME
  Unit/              plain objects: FleetPaths, the command runners, health, security, navigation, tokens
  Integration/       kernel and container wiring: Doctrine/SQLite, Messenger, Lock, Mercure, prod security
  Functional/        HTTP through WebTestCase: access control, app shell, design-system page, health, stand-in
  Repo/              repository rules: hygiene (no owner or workspace references), pipeline configuration,
                     tag guard, the design-system block in CLAUDE.md, the Python app being gone
  Support/           shared helpers (a temporary FLEET_HOME)
tests/playwright/    TypeScript @playwright/test, its own npm package
  playwright.config.ts   projects "desktop" (1280 px) and "phone" (390 px); starts the app unless FLEET_E2E_BASE_URL is set
  support/               env.ts, start-app.mjs (the self-started app), shell.ts, standIn.ts
  *.spec.ts              shell, design-system, nav.desktop, nav.phone, dark-mode, a11y (axe, both themes)
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
`FLEET_HOME`, `importmap:install`, `tailwind:build`, migrations, `php -S 127.0.0.1:8000`) and stops it
afterwards. With it set to a URL, the suite targets that already-running server and starts nothing
(`FLEET_E2E_IGNORE_HTTPS_ERRORS=1` accepts a local certificate; `*.ddev.site` hosts are accepted
automatically). Other variables: `FLEET_E2E_PORT` (default 8000) and `FLEET_PHP` (the PHP binary of the
launcher). The specs send `X-Fleet-Profiler-Toolbar: 0` so the Symfony toolbar cannot cover the phone
bottom bar, and the shell specs assert that no console error or uncaught page error was seen (`support/shell.ts`).

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
