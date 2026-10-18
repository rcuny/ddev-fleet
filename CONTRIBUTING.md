---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-18
Type: documentation
---

# Contributing to ddev-fleet

`develop` is the 1.x line (Symfony 8, PHP 8.4); `main` is the 0.x line (Python) and only receives
fixes for it. Contribute against `develop` unless you are fixing 0.x. The full setup, the
environment variables and the stand-in login are in [`docs/development.md`](docs/development.md).

## Dev setup

```bash
cd app
composer install
php bin/console importmap:install
php bin/console tailwind:build
php -S 127.0.0.1:8000 -t public
```

You need PHP 8.4 (with `pdo_sqlite` and `sodium`), Composer 2 and, for the browser tests,
Node 22 and the Playwright browser (`cd tests/playwright && npm ci && npx playwright install chromium`).
The Python CI tooling (`pip install -r ci/requirements-dev.txt`) is only needed when you touch
`ci/`, `scripts/` or `ansible/`.

## Running the gates

```bash
cd app && composer run gates                 # assets, php-cs-fixer, PHPStan 8, lint, PHPUnit
cd tests/playwright && npm run typecheck && env -u FLEET_E2E_BASE_URL npx playwright test
```

`composer run gates` includes the `Repo` suite, which pins the pipeline configuration, the tag
guard, the design-system block in `CLAUDE.md` and the hygiene rules below. Playwright starts the
app itself when `FLEET_E2E_BASE_URL` is unset (the `env -u` makes sure). Never open a pull
request with a red suite. Suites and counts: [`tests/README-tests.md`](tests/README-tests.md).

## Code style

- Follow the namespaces and seams in [`CLAUDE.md`](CLAUDE.md): host commands go through
  `App\Fleet\Shell\CommandRunner`, paths come from `App\Fleet\FleetPaths`, and `ddev`, `git` and
  `docker` never run inside an HTTP request.
- PHP-CS-Fixer (`@Symfony`, risky rules, `declare(strict_types=1)`) formats the code:
  `composer run cs` checks, `php vendor/bin/php-cs-fixer fix` repairs. PHPStan runs at level 8.
- UI follows the design system: use the tokens and components documented at `/admin/design-system`,
  write bare semantic HTML for text, and make every page work at 390 px. A change to a page, a
  template or the JavaScript it loads needs a Playwright spec in `tests/playwright/`.
- Tests: `Unit` for plain objects, `Integration` for container and database wiring, `Functional`
  for HTTP, `Repo` for repository rules. Add a test with every behaviour change.
- The repository is public: no hostnames, project or client names, or workspace paths of any
  deployment. Examples use `fleet.example.com`, `demo` and `blog`. `RepoHygieneTest` enforces this.

## Pull requests

- Branch off `develop`: `feature/<KEY>-<slug>`; no double underscores or double dashes in the name.
- Keep the suite green and describe *why*, not just *what*, in the description.
- Add your entry under `## [Unreleased]` in `CHANGELOG.md` in the same branch; the conventions
  are in [`docs/RELEASING.md`](docs/RELEASING.md#3-changelog-conventions).
- If you change `ansible/`, say whether you ran `ansible-lint` and `yamllint` locally
  (`pip install -r ci/requirements-infra.txt`); CI runs them non-blocking.
- Keep documentation in step: [`docs/README.md`](docs/README.md) lists every document and its
  status; update `docs/development.md` when a command or variable changes.

## CI, mirror and dependency updates

- **Bitbucket Pipelines is the canonical, and only, CI** (`bitbucket-pipelines.yml`): the PHP gates
  and the Playwright suite on every pull request, on `develop`, on `feature/**` and `release/**`
  pushes and on `v*` tags, plus the Python infra tests and a non-blocking ansible-lint/yamllint
  step. If your change makes a unit's `systemd-analyze security` score worse the pipeline fails;
  an intended change means re-recording the baseline ([`docs/README-ci.md`](docs/README-ci.md)).
  There is no GitHub Actions workflow.
- **GitHub is a read-only mirror**, published by the pipeline: `develop` after each green push,
  and a `v1.*` tag only when its commit is on `origin/develop` (`ci/tag-guard.sh`; `v0.*` tags
  still publish `main`). Nothing is force-pushed. Never push or merge on GitHub directly: pull
  requests there are welcome, but the maintainer applies them on Bitbucket. The mirror steps skip
  unless `GITHUB_MIRROR_URL` is set, so forks are unaffected.
- **Renovate** (`renovate-config.json`, the scheduled `custom: renovate` pipeline) opens daily
  grouped PRs against `develop` for Composer, npm, pip and Ansible dependencies; review them like any
  other PR. Approve one (or comment `/merge`) and `custom: renovate-merge` merges it once its gates
  are green ([`docs/README-renovate.md`](docs/README-renovate.md)). The PHP platform constraint and
  the Debian and Python pipeline images are never auto-bumped.
- Required repository variables and the GitHub deploy key are listed in the header of
  `bitbucket-pipelines.yml`.
- **Releases** follow Gitflow; 1.x tags are cut from `release/1.x.y` and merged into `develop`
  while the port is under way. See [`docs/RELEASING.md`](docs/RELEASING.md).

## See also

- [`CLAUDE.md`](CLAUDE.md) — the agent-facing guide to the same codebase (namespaces, seams, commands).
- [`docs/README.md`](docs/README.md) — documentation index.
- [`docs/RELEASING.md`](docs/RELEASING.md), [`docs/README-ci.md`](docs/README-ci.md).
