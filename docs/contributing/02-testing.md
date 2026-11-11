# Testing

Know which command proves your change is safe, and which suite to extend. This page holds the canonical gates.

## The gates

Run all three before every pull request; never merge a red suite.

```bash
cd app && composer run gates      # assets, cs, stan, lint, test
cd tests/playwright && npm ci && npm run typecheck && env -u FLEET_E2E_BASE_URL npx playwright test
pytest -q tests/pytest            # after: pip install -r ci/requirements-dev.txt
```

| Gate | What it is |
|---|---|
| `assets` | importmap install and Tailwind build |
| `cs` | php-cs-fixer, check only |
| `stan` | PHPStan level 8 |
| `lint` | Twig, YAML, container, `doctrine:schema:validate --skip-sync` |
| `test` | PHPUnit, all four suites |

Repair formatting with `php vendor/bin/php-cs-fixer fix`. `ci/run-gates.sh` runs everything in one go ([03-ci.md](03-ci.md)). Counts: `ci/test-counts.sh`.

## Suites

| Suite | Where | Runs what | How |
|---|---|---|---|
| PHPUnit `Unit`, `Integration`, `Functional`, `Repo` | `app/tests/<Suite>/` | plain objects; container and database wiring; HTTP round trips; repository rules (hygiene, CI config, tag guard, docs) | `php vendor/bin/phpunit [--testsuite Repo] [--filter X]` in `app/` |
| Playwright | `tests/playwright/` | the real UI, plus an axe accessibility sweep. `desktop` (1280 px) runs every spec; `phone` (390 px) is opt-in and runs only tests tagged `@phone` | commands above; `npm run test:smoke` for `@smoke` |
| Installer and real-binary | `app/tests/Repo/Installer*Test` and friends | the install scripts with `--dry-run` and stubbed commands; rendered Caddy and Mercure configs when `FLEET_CADDY_BIN` / `FLEET_MERCURE_BIN` are set (else skipped). Fleet CI sets `FLEET_CADDY_STOCK_BIN` (a stock Caddy; `FLEET_CADDY_BIN` stays the OVH build) from `ci/fetch-caddy.sh` (pinned version, SHA-512 checked, cached under `~/.cache/fleet-ci`), so the real-Caddy API route test runs there | part of `Repo` |
| pytest | `tests/pytest/` | CI tooling (systemd scorer, gates summary, Renovate helper) | command above |

A phone-only spec is named `<topic>.phone.spec.ts` with `@phone` on its describe; to give one test a phone run, put `@phone` in its title.

## Playwright modes and tiers

- **Mode.** `FLEET_E2E_BASE_URL` unset: Playwright starts a throw-away app (`php -S`, fresh `FLEET_HOME`) and stops it; this is the gate. Set to a URL: it targets that running server and starts nothing (`FLEET_E2E_IGNORE_HTTPS_ERRORS=1` accepts a local certificate). If your shell exports the variable, use `env -u`.
- **Tier** `FLEET_PW_TIER`: `full` (default) runs every spec. `quick` drops the `*-coverage`, `*-validation` and axe (`a11y*`) specs and limits `phone` to tests tagged `@phone` and `@smoke`. Any other value is an error.
- **Phone** `FLEET_PW_PHONE=on|off|auto`: whether the `phone` project exists (auto: the tier's own behaviour).

In CI, `ci/pw_select.py --base origin/develop` extends the quick tier from the changed files:

- **Phone** runs only if the change touches `app/templates/`, `app/assets/`, `app/src/Twig/`, `app/src/Form/`, a `*.phone.spec.ts`, `playwright.config.ts` or `support/`.
- **Targeted specs** (`FLEET_PW_TARGETED`): a spec `<stem>-coverage.spec.ts` or `<stem>-validation.spec.ts` belongs to area `<stem>` and runs when a changed path under `app/src/`, `app/templates/`, `app/assets/` or `app/tests/` contains that name (CamelCase equals kebab-case), or a `tests/playwright/<stem>*` spec changed.
- **Cross-cutting** changes (base templates and components, assets, config, migrations, lock files, `Kernel`, `Security`, `Twig`, `Form`, Playwright config and support) run every one of them. If unsure, or the diff cannot be computed, everything runs.

## What to add with a change

- A PHPUnit test per behaviour, in the suite that fits: `Unit` plain objects, `Integration` container or database, `Functional` an HTTP round trip, `Repo` a rule about the repository.
- A Playwright spec per UI change: layout, focus, dark mode and contrast are invisible to PHPUnit. A new page or error state gets a test in the `a11y*` family, not an inline axe call.
- Test data uses `fleet.example.com`, `demo` and `blog`. Tests never touch real state or run a host command.

## Docs tests

The `app/tests/Repo/*DocsTest.php` tests read the docs and pin command names, options, flags, env keys and check ids against the code: `InstallDocsTest`, `CliDocsTest`, `AssetDocsTest`, `SecretsDocsTest`, `ExportDocsTest`, `UpgradeDocsTest`, `UpgradeCheckDocsTest`. If you rename a command or option, update its doc or the test fails. `docs/reference/01-cli.md` is generated, and a test checking that it is fresh is planned.

## Learn more

- [03-ci.md](03-ci.md): the same gates in CI
- [01-development.md](01-development.md): setup and environment variables
