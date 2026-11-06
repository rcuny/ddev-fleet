---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-18
Type: documentation
---

# CI: Bitbucket Pipelines, tag guard and the systemd security check

Bitbucket Pipelines (`bitbucket-pipelines.yml`) is the only CI. GitHub is a
read-only mirror published by a pipeline step and has no workflow of its own.
The product is a Symfony 8.1 app (PHP 8.4) in `app/` with a Playwright browser
suite in `tests/playwright/`. The gates run on Debian 13 (`debian:trixie`), the
same release as the servers; Python steps use `python:*-trixie` images.

## The pipelines

| Trigger | What runs |
|---|---|
| Pull request (any branch) | in parallel: **gates**, **infra tests**, **ansible lint** |
| Push to `develop` | the same three, then **mirror develop** to GitHub |
| Push to `feature/**`, `release/**` | **gates** and **infra tests** |
| Tag `v*` | **gates** and **infra tests**, then **publish to the mirror** through `ci/tag-guard.sh` |
| Custom `renovate` | Renovate (daily schedule): dependency-update PRs against `develop` |
| Custom `renovate-merge` | merges one approved, green Renovate PR, then runs Renovate (`docs/README-renovate.md`) |

- **Gates** (`Gates (php-cs-fixer, phpstan, lint, phpunit, Playwright)`): installs PHP 8.4
  and Composer from Debian, Node 22 from NodeSource (`@axe-core/playwright` needs Node
  >= 22.12 and Debian 13 ships Node 20; the step prints `node -v` and fails below 22),
  then `composer install` and `composer run gates` in `app/` (importmap and Tailwind
  assets, php-cs-fixer, PHPStan level 8, Twig/YAML/container lint, Doctrine schema
  validation, PHPUnit including the `Repo` suite that pins this CI configuration), then
  in `tests/playwright/`: `npm ci`, `npx playwright install --with-deps --only-shell
  chromium`, `npm run typecheck` and `npx playwright test`. With no
  `FLEET_E2E_BASE_URL` Playwright starts the app itself (`php -S`, throw-away
  `FLEET_HOME`). A missing browser fails the step. Artifacts: the Playwright report and traces.
- **Infra tests** (`python:3.11-trixie` with `systemd`): `pip install -r
  ci/requirements-dev.txt`, `FLEET_REQUIRE_SYSTEMD_ANALYZE=1 pytest -q tests/pytest`
  (Ansible role templates, the systemd security tests, the Renovate merge helper), then the
  offline systemd security check below. Artifacts: `reports/**`.
- **Ansible lint (non-blocking)** (`python:3.13-trixie`, `ci/requirements-infra.txt`):
  `ansible-lint ansible/` and `yamllint ansible/`. Findings are printed but never fail the
  run; drop the `|| echo` in the step to promote it to a hard gate.
- **Mirror / publish**: see below, `docs/RELEASING.md` and the header of
  `bitbucket-pipelines.yml` (`GITHUB_MIRROR_URL`, the deploy key). Pushes are never forced.
- **Renovate**: `renovate-config.json` (composer groups `symfony`, `symfony ux`,
  `doctrine`, `php dev tooling`; npm group `playwright`; pip group `python ci tooling`; Ansible
  collections). Bumps of the `debian` and `python` pipeline images and of the `php` platform
  constraint are disabled on purpose. See `CONTRIBUTING.md`.

Alerts are Bitbucket's built-in "pipeline failed" email.

## Tag guard (`ci/tag-guard.sh`)

`ci/tag-guard.sh <tag> <commit>` decides what a release tag may publish. It runs in the
tag pipeline's last step, after the step fetched `origin/main`, `origin/develop`, `origin/legacy` and the tag,
and prints the refspecs for one non-forced `git push` to the mirror:

| Tag | Required | Pushed to GitHub |
|---|---|---|
| `v1.X.Y`, `v1.X.Y-<prerelease>` | the commit is the tip of `origin/main` | `<commit>:refs/heads/main` (fast-forward) and the tag |
| same | the commit is an older ancestor of `origin/main` (the `v1.0.x` tags cut on `develop`) | the tag only |
| same | the commit is only on `develop` | rejected: merge the release into `main` first |
| `v0.X.Y` (the Python 0.x line) | the commit is the tip of `origin/legacy` | `<commit>:refs/heads/legacy` and the tag |
| anything else | - | rejected (`ERROR: ...` on stderr, exit 1) |

A tag pipeline runs the `bitbucket-pipelines.yml` of the tagged commit, and `legacy` still
carries the Python pipeline, so the `v0.*` branch is a documented safety net, not a path
this pipeline reaches. The CI host's publish script (`publish-github.sh`) calls the same guard. `app/tests/Repo/TagGuardTest.php` runs the script against throw-away
repositories.

## The systemd security check (FLE-8, 1.x since FLE-238)

`systemd-analyze security` scores a unit's sandbox from 0 (locked down) to 10
(no sandbox). The check scores the units **the 1.x installer ships**, offline,
and fails when a score goes up against a committed baseline. No host is
involved and nothing needs Ansible.

### What is scored

`python ci/systemd_security.py offline --out reports/offline.json` runs
`systemd-analyze security --offline=yes` on:

- every `install/etc/*.service` (`fleet-backup`, `fleet-boot`, `fleet-mercure`,
  `fleet-scheduler`, `fleet-status`, `fleet-tls-check`, `fleet-tmux`,
  `fleet-upgrade-check`, `fleet-worker@`);
- every `install/harden/etc/*.service` (`firewall-deadman`,
  `firewall-deadman-bootcheck`, `firewall-sync`);
- `caddy.service`: `install/etc/caddy-sandbox.conf` layered over a stand-in for
  the Debian package's unit.

A new `*.service` in those directories is picked up by name and shows as NEW until
the baseline is updated. Timers have no sandbox and are not scored. Authelia and
php-fpm ship no sandbox drop-in, so there is nothing to score. High scores
(for example `fleet-tmux`, whose panes are operator shells) are recorded as they
are: the check guards against regressions, it does not demand a score.

`compare` then checks the report against the baseline and writes
`reports/systemd-security-offline.md` and `.json` (pipeline artifacts). It runs
in Fleet CI through `ci/run-gates.sh` (gate `systemd-security`) and in the
pipeline's infra step; `tests/pytest/repo/test_systemd_security_ci.py` also
removes a sandbox directive from a unit and asserts the check fails.

### The baseline

`ci/systemd-security-baseline.json`:

```json
{ "tolerance": 0.1,
  "environments": { "offline": { "systemd": "257", "recorded": "2026-11-06",
                                 "overview": {"fleet-backup.service": 1.4},
                                 "units": {"fleet-backup.service": {"score": 1.4, "directives": {"...": 0.1}}} } } }
```

`compare` looks at the score of every unit in the report:

| Status | Meaning | Fails the run |
|---|---|---|
| OK | within the tolerance (+/- 0.1) of the baseline | no |
| REGRESSION | current is more than 0.1 above the baseline | **yes** |
| IMPROVED | current is more than 0.1 below the baseline | no (the report suggests updating the baseline) |
| NEW | not in the baseline | no |
| GONE | in the baseline, not in the report | no |

Every REGRESSION lists the per-directive exposure changes, and stderr gets one
line per unit: `REGRESSION fleet-backup.service (offline): 1.4 -> 2.6 (+1.2)`.
The report also warns when the systemd major version differs from the baseline's,
because scores can move with a systemd upgrade without any change here.

### When it fails

Your change removed or weakened a sandbox directive, or changed a unit so it
exposes more. Read the unit and directives named in
`reports/systemd-security-offline.md`, fix the unit, do not raise the baseline to
hide it. If the weakening is intended (a directive that breaks the daemon),
record the new baseline in the same pull request and say why in the description.

### Updating the baseline and the report

On a feature branch, in a Debian 13 / systemd 257 environment (the CI image, or a
container with `apt-get install systemd`), with `umask 022`:

```bash
python ci/systemd_security.py offline --out reports/offline.json
python ci/systemd_security.py update-baseline --current reports/offline.json
python ci/systemd_security.py compare --current reports/offline.json --report-json reports/systemd-security-offline.json
python ci/systemd_security.py report --compare reports/systemd-security-offline.json
```

Commit `ci/systemd-security-baseline.json` and `SYSTEMD-SECURITY-REPORT.md`
(the page lists scores only and names no host). `update-baseline` keeps the
tolerance; `compare --tolerance X` overrides it for a single run.

## One gates script (`ci/run-gates.sh`, FLE-120)

Every caller runs the same script, so the gate list cannot drift: dispatched agents, the orchestrator
(merge gate) and Fleet CI. The source of truth for the list is the Gates, Infra tests and Ansible lint
steps of `bitbucket-pipelines.yml`; change both together.

```bash
ci/run-gates.sh [--quick|--full] [--report-dir DIR]     # from anywhere; --full is the default
FLEET_CI_WORKERS=2 ci/run-gates.sh --full               # Playwright --workers (default: Playwright's own)
FLEET_PW_TIER=quick ci/run-gates.sh --full              # Playwright tier (FLE-126); Fleet CI sets it per branch
FLEET_CI_INSTALL_DEPS=1 ci/run-gates.sh --full          # sudo playwright install-deps first (Fleet CI web container)
```

| Mode | Gates (log name) |
|---|---|
| `--quick` | `composer-install`, `composer-gates` (`composer run gates`: assets, cs, stan, lint, phpunit), `prod-check` (only if `app/composer.json` has the script) |
| `--full` | quick, plus `playwright-install` (`npm ci`, Chromium), `playwright-typecheck`, `playwright` (whole suite with `FLEET_E2E_BASE_URL` unset), `infra-install` (`.venv`), `pytest` (`tests/pytest`), `systemd-security` (offline score; skipped without `systemd-analyze`), `ansible-lint` (non-blocking, skipped below Python 3.12) |

**What Playwright runs (FLE-172).** The tier and two optional parts decide it:

| Branch | Tier | Phone project | `*-coverage` / `*-validation` specs |
|---|---|---|---|
| `feature/*`, `renovate/*`, `develop` pushes | `quick` (`quick+targeted`) | only on a UI change | only those whose area the diff touches |
| `release/*`, `main`, nightly develop run, `FLEET_PW_TIER=full` | `full` | always | all |

`ci/pw_select.py` decides from the diff `merge-base(FLEET_CI_DIFF_BASE, FLEET_CI_DIFF_HEAD)..FLEET_CI_DIFF_HEAD` (defaults
`origin/develop` and `HEAD`; Fleet CI sets the head to the pushed commit, since it merges develop in first, and the base of a
`develop` push to the last green develop commit). The rules are in `tests/README-tests.md`. `FLEET_PW_PHONE=on|off|auto`
overrides the phone decision. The choice goes to `selection.txt`, to the `playwright` row of `gates.tsv` (so the report and the
`fleet-gates` description say `quick tier; phone: skipped (no UI change); coverage specs: 2 targeted`) and the 20 slowest
Playwright tests of the run to `slowest-tests.txt` (`ci/slowest_tests.py`). Playwright workers stay derived from the container CPU quota
(two thirds of the CPUs, 4 at the 6-CPU cap) and are not hard-coded.

### Wall-clock design (FLE-174)

Chosen over sharding Playwright across the two CI slots: it needs no coordination between runs and keeps one `fleet-gates`
status. `ci/run-gates.sh` runs independent work side by side inside one run (the container has 6 CPUs):

- **`composer-gates`** is `assets` first, then lanes in parallel: `cs` + `stan` + `lint` in one, and one PHPUnit process per
  suite (Unit, Integration, Functional, Repo). Every PHPUnit process makes its own `FLEET_HOME` and uses `APP_ENV=test`, while
  stan and lint warm the dev cache, so the lanes share nothing. Keep the lane list in step with the `gates` script of
  `app/composer.json`; `FLEET_CI_SERIAL=1` runs plain `composer run gates`.
- **Full tier:** `npm ci` and the Chromium install run while the composer gates run; the pytest / systemd score / ansible-lint
  chain runs while Playwright runs. Composer gates and Playwright stay sequential (both use the dev cache in `app/var/cache/dev`).
- **Caches** survive between runs because the CI instance persists: `npm ci` is skipped when `package-lock.json` is unchanged
  (stamp `tests/playwright/node_modules/.ci-lock-hash`), the Python venvs are reused when `ci/requirements-*.txt` are unchanged,
  `playwright install-deps` runs once per lock hash (`~/.cache/fleet-ci/`), Chromium and Composer download caches live in the
  container home, and `fleet-ci.sh` installs systemd only when `systemd-analyze` is missing.
- **Reports:** `slowest-phpunit.txt` (20 slowest PHPUnit tests from the per-suite `--log-junit` files) next to
  `slowest-tests.txt` (Playwright), both linked from the report index.

Every gate runs with a throw-away `FLEET_HOME` (`mktemp -d`, removed on exit) and without `APP_ENV`: the companion
container exports `FLEET_HOME=/tmp/sqlite/fleet-home` (the served dev app's database, which the tests wipe) and
`APP_ENV=dev`. Composer is called as found on PATH; `FLEET_PHP` runs it through that PHP binary instead. Unlike the
pipeline, the browser install is `npx playwright install --only-shell chromium` **without** `--with-deps` (the script
does not need root); on a bare host install the system libraries once with `--with-deps` as root.

It clears `app/var/cache/dev` and `app/var/cache/test` first (a stale cache after a branch switch gives false
Playwright reds). A failing gate does not stop the others; the exit status is 0 only if every **blocking** gate
passed (a skipped gate counts as passed, its reason is in the summary).

The report directory (default `var/ci-report/`, git-ignored) holds `<gate>.log` per gate, `gates.tsv`,
`summary.txt` (printed at the end), `summary.json` (`{"ok", "gates": [{gate, status, duration_s, blocking, log,
counts?, note?}]}`, counts parsed from PHPUnit, Playwright and pytest output by `ci/gates_summary.py`) and the
Playwright HTML report in `playwright-report/`. Tests: `tests/pytest/repo/test_gates_summary.py`.

## Local runs

```bash
# Symfony app (PHP 8.4): the same gates as the pipeline
cd app && php8.4 "$(command -v composer)" run gates
# Pipeline, tag guard and repository hygiene tests only
cd app && php8.4 vendor/bin/phpunit --testsuite Repo
# Browser E2E: install once, then run (Playwright starts the app when FLEET_E2E_BASE_URL is unset)
cd tests/playwright && npm ci && npx playwright install --only-shell chromium   # add --with-deps as root
cd tests/playwright && env -u FLEET_E2E_BASE_URL npx playwright test
# Infrastructure tests (Python)
pip install -r ci/requirements-dev.txt
pytest -q tests/pytest
python ci/systemd_security.py offline --out /tmp/offline.json   # needs systemd-analyze
```

`FLEET_REQUIRE_SYSTEMD_ANALYZE=1` turns "systemd-analyze missing" from a skip into a failure,
as in the pipeline.
