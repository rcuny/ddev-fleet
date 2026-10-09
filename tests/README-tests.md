# Tests

The suite is split by framework. Pytest holds the unit and FastAPI `TestClient`
tests (grouped by area), Playwright drives a real browser, and Node's built-in
test runner covers the browser ES modules.

## Layout

```
tests/
  __init__.py        package root (tests import each other as `tests.<...>`)
  conftest.py        pytest: shared fixtures and helpers (FakeRunner, gpg helpers,
                     autouse isolation fixtures), used by pytest/ and playwright/
  README-tests.md    this file
  fixtures/          shared data: the PGP test key pair and a committed OpenPGP.js
                     ciphertext (fixtures/pgp), read by pytest, node and playwright tests
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
  playwright/        Python Playwright (driven by pytest): real daemon + headless Chromium
  node/              Node's built-in test runner (`node --test`): the browser ES modules
                     (`*.test.mjs`) and `encrypt-cli.mjs`, a helper the pytest interop tests call
```

## Prerequisites

- Python 3.11+ and the dev extras:
  `python -m venv .venv && .venv/bin/pip install -e '.[dev]'`
- `gpg` (GnuPG 2.2+) for the real-crypto tests (`pytest/secrets/`, `playwright/`).
- Node 22+ for `node/` and for the pytest tests that run the vendored OpenPGP.js through
  Node (`secrets/test_pgp_interop.py`, `web/test_secrets_ui.py`).
- Chromium for `playwright/`:
  `.venv/bin/python -m playwright install --with-deps chromium`
  (or `--only-shell chromium`, enough for the headless runs the suite uses). This uses the
  `playwright` package pinned in the `[dev]` extra.

Optional binaries (`systemd-analyze`, `caddy`, `authelia`) enable a few more checks; the
tests that need them skip when they are missing.

## Running

```bash
.venv/bin/pytest -q                                   # everything under pytest/ and playwright/
.venv/bin/pytest -q tests/pytest/cli                  # one area
.venv/bin/pytest -q tests/pytest/cli/test_cli.py      # one file
.venv/bin/pytest -q "tests/pytest/cli/test_cli.py::test_deploy_happy_path_prints_url"   # one test
.venv/bin/pytest -q tests/playwright                  # browser E2E only
node --test tests/node/*.test.mjs                     # Node tests (see below)
.venv/bin/ruff check .                                # lint gates
.venv/bin/black --check .
```

Notes:

- Use the glob for Node. On Node 22 the directory form `node --test tests/node` fails
  ("Cannot find module"), so name the files.
- The Playwright tests use their own fixtures in `playwright/conftest.py` and start Chromium
  headless. `pytest-playwright` is not a dependency, so its options (`--headed`,
  `--browser`, ...) are not available.
- Run `pytest` from the repository root.

## Skip versus fail

A test that needs an external tool skips when the tool is missing. These flags turn the skip
into a failure, so a missing tool cannot silently shrink the suite. CI sets all three.

| Flag | Requires |
|---|---|
| `FLEET_REQUIRE_PGP_TOOLS=1` | `gpg` and `node` (the real-crypto and interop tests) |
| `FLEET_REQUIRE_E2E=1` | the `playwright` package and a launchable Chromium (`playwright/`) |
| `FLEET_REQUIRE_SYSTEMD_ANALYZE=1` | `systemd-analyze` (unit sandbox scoring; any non-empty value counts) |

Two environment variables point at optional binaries: `FLEET_TEST_CADDY_BIN` (validate the
rendered Caddyfile; `caddy` on `PATH` is used by the Caddy auth tests) and
`FLEET_TEST_AUTHELIA_BIN` (validate the Authelia configuration; falls back to `authelia` on
`PATH`). Without them those few tests skip.

To run the suite the way CI does:

```bash
FLEET_REQUIRE_PGP_TOOLS=1 FLEET_REQUIRE_E2E=1 FLEET_REQUIRE_SYSTEMD_ANALYZE=1 .venv/bin/pytest -q
```

## Conventions

- Put a new test in the pytest area that matches the source module it exercises, as
  `tests/pytest/<area>/test_<topic>.py`. A new area is a new folder with an empty
  `__init__.py`.
- A change to a UI page, template or the JavaScript it loads also needs a Playwright test in
  `tests/playwright/`: unit tests with a fake htmx or the ASGI test client cannot see htmx
  form validation, the CSP or module loading.
- Browser-side logic that can run without a browser (the ES modules under
  `src/fleet/static`) is tested in `tests/node/`.
- Import shared helpers as `from tests.conftest import ...`. Never import from a sibling
  area's test file unless that file exports the helper on purpose.
- CI (`bitbucket-pipelines.yml`) runs the same commands: `pytest -q`, `ruff check .`,
  `black --check .` and `node --test tests/node/*.test.mjs`.
