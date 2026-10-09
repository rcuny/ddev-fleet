# Tests

The suite is split by framework. Pytest holds the unit and FastAPI `TestClient`
tests (grouped by area), Playwright (TypeScript, `@playwright/test`) drives a real browser,
and Node's built-in test runner covers the browser ES modules.

## Layout

```
tests/
  __init__.py        package root (tests import each other as `tests.<...>`)
  conftest.py        pytest: shared fixtures and helpers (FakeRunner, gpg helpers,
                     autouse isolation fixtures), used by pytest/
  README-tests.md    this file
  fixtures/          shared data: the PGP test key pair and a committed OpenPGP.js
                     ciphertext (fixtures/pgp), read by pytest and node tests
  pytest/            pytest: unit + TestClient tests, one subfolder per area
    fakegpg.py         a scripted stand-in for gpg, used by the secrets/integrations tests
    cli/               the `fleet` command line (cli.py, bulk commands)
    web/               daemon, dashboard UI, CSP/web security, Secrets page, job registry
    instances/         instance lifecycle: deploy, redeploy, list, snapshot, refresh-config,
                       paths, locks, naming, ddev wrappers, bulk orchestration, git/branch reads
    secrets/           OpenPGP secret store, gpg wrapper, tokens, vendored OpenPGP.js, interop
    integrations/      Bitbucket/generic webhooks, gitops, Typesense, Authelia, Caddy auth + ports
    core/              registry, fleet config, runner, shell, errors, host/system info, assets,
                       tmux (+ sidebar), tty commands, reboot status
    ansible/           Ansible role and template checks (Caddyfile, Authelia, systemd units/sandbox)
    repo/              repository and CI hygiene (pipeline config, Renovate merge, systemd
                       security report, no personal data, demo project)
  playwright/        TypeScript `@playwright/test`, its own npm package (package.json,
                     package-lock.json, node_modules/): real daemons + headless Chromium
    playwright.config.ts   chromium only, one worker; starts the two daemons (webServer)
    support/           start-daemon.mjs (launcher), env.ts (ports, state files), fixtures.ts
    *.spec.ts          the tests (secrets.spec.ts: the Secrets page)
  node/              Node's built-in test runner (`node --test`): the browser ES modules
                     (`*.test.mjs`) and `encrypt-cli.mjs`, a helper the pytest interop tests call
```

## Prerequisites

- Python 3.11+ and the dev extras:
  `python -m venv .venv && .venv/bin/pip install -e '.[dev]'`
- `gpg` (GnuPG 2.2+) for the real-crypto tests (`pytest/secrets/`, `playwright/`).
- Node 22+ (Playwright itself needs 20+) for `node/`, for the pytest tests that run the
  vendored OpenPGP.js through Node (`secrets/test_pgp_interop.py`, `web/test_secrets_ui.py`)
  and for `playwright/`.
- For `playwright/`, once: `cd tests/playwright && npm ci && npx playwright install --with-deps chromium`
  (or `--only-shell chromium`, enough for the headless runs the suite uses). The browser
  build belongs to the `@playwright/test` version pinned in `tests/playwright/package.json`:
  after a bump of that pin, run the install again.

The Playwright runner has no skip mode: a missing browser, `gpg` or Python makes the run fail.

Optional binaries (`systemd-analyze`, `caddy`, `authelia`) enable a few more checks; the
tests that need them skip when they are missing.

## Running

```bash
.venv/bin/pytest -q                                   # everything under tests/pytest/
.venv/bin/pytest -q tests/pytest/cli                  # one area
.venv/bin/pytest -q tests/pytest/cli/test_cli.py      # one file
.venv/bin/pytest -q "tests/pytest/cli/test_cli.py::test_deploy_happy_path_prints_url"   # one test
node --test tests/node/*.test.mjs                     # Node tests (see below)
cd tests/playwright && npx playwright test            # browser E2E (see below)
.venv/bin/ruff check .                                # lint gates
.venv/bin/black --check .
```

Notes:

- Use the glob for Node. On Node 22 the directory form `node --test tests/node` fails
  ("Cannot find module"), so name the files.
- Run `pytest` from the repository root.

### Browser E2E (`tests/playwright/`)

```bash
cd tests/playwright
npx playwright test                              # all of it, headless
npx playwright test -g "empty value"             # one test, by title
npx playwright test --headed                     # watch the browser
npx playwright test --ui                         # Playwright's UI mode
npx playwright test --debug                      # step through with the inspector
npx playwright show-report                       # the HTML report of the last run
npm run typecheck                                # tsc --noEmit
```

- `playwright test` starts the daemons itself (`webServer` in `playwright.config.ts`, launcher
  `support/start-daemon.mjs`): one with a host key, one without. Per daemon the launcher makes a
  short `FLEET_HOME` under `/tmp` (gpg-agent socket paths are limited to about 100 characters),
  writes the registry, creates the host key with `fleet keys init`, runs uvicorn on
  `fleet.daemon:app`, and on exit stops it, kills the gpg-agent and removes the home.
- Chromium runs headless with the real CSP enforced. A console error, an uncaught page error or a
  CSP violation fails the test (`support/fixtures.ts`); a test that provokes an expected 4xx adds
  its console text to `expectedConsoleErrors`.
- The tests share the two daemons, so they run serially in one worker, and each starts with an
  empty secret store.
- Environment: `FLEET_PYTHON` (interpreter with `fleet` installed; default `.venv/bin/python`
  when it exists, else `python3`), `FLEET_E2E_PORT` (default 18791) and `FLEET_E2E_PORT_NOKEY`
  (default 18792).

## Skip versus fail

A pytest test that needs an external tool skips when the tool is missing. These flags turn the
skip into a failure, so a missing tool cannot silently shrink the suite. CI sets both.

| Flag | Requires |
|---|---|
| `FLEET_REQUIRE_PGP_TOOLS=1` | `gpg` and `node` (the real-crypto and interop tests) |
| `FLEET_REQUIRE_SYSTEMD_ANALYZE=1` | `systemd-analyze` (unit sandbox scoring; any non-empty value counts) |

Two environment variables point at optional binaries: `FLEET_TEST_CADDY_BIN` (validate the
rendered Caddyfile; `caddy` on `PATH` is used by the Caddy auth tests) and
`FLEET_TEST_AUTHELIA_BIN` (validate the Authelia configuration; falls back to `authelia` on
`PATH`). Without them those few tests skip.

To run the suite the way CI does:

```bash
FLEET_REQUIRE_PGP_TOOLS=1 FLEET_REQUIRE_SYSTEMD_ANALYZE=1 .venv/bin/pytest -q
(cd tests/playwright && npx playwright test)
```

## Conventions

- Put a new test in the pytest area that matches the source module it exercises, as
  `tests/pytest/<area>/test_<topic>.py`. A new area is a new folder with an empty
  `__init__.py`.
- A change to a UI page, template or the JavaScript it loads also needs a Playwright test
  (`tests/playwright/*.spec.ts`): unit tests with a fake htmx or the ASGI test client cannot see htmx
  form validation, the CSP or module loading.
- Browser-side logic that can run without a browser (the ES modules under
  `src/fleet/static`) is tested in `tests/node/`.
- Import shared helpers as `from tests.conftest import ...`. Never import from a sibling
  area's test file unless that file exports the helper on purpose.
- CI (`bitbucket-pipelines.yml`) runs the same commands: `pytest -q`, `ruff check .`,
  `black --check .`, `node --test tests/node/*.test.mjs` and, in `tests/playwright`,
  `npm ci`, `npx playwright install --with-deps --only-shell chromium` and
  `npx playwright test`.
