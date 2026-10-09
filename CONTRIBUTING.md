---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-06
Type: documentation
---

# Contributing to ddev-fleet

## Dev setup

```bash
python3 -m venv .venv
.venv/bin/pip install -e ".[dev]"
```

`pyproject.toml` also declares an `infra` extra
(`ansible-core`, `ansible-lint`, `yamllint`) for anyone touching
`ansible/`:

```bash
.venv/bin/pip install -e ".[infra]"
```

## Running the gates

```bash
.venv/bin/pytest -q
.venv/bin/ruff check .
.venv/bin/black --check .
node --test tests/node/*.test.mjs
(cd tests/playwright && npm ci && npx playwright install --with-deps chromium && npx playwright test)
```

`node` (20 or newer) and `gpg` are needed for the secrets tests; without them
those tests skip locally (set `FLEET_REQUIRE_PGP_TOOLS=1` to make that an
error, as CI does). The last line is the browser E2E suite, a TypeScript
`@playwright/test` npm package in `tests/playwright/`; run `npm ci` and the
browser install once, then only `npx playwright test` (it starts the daemons
itself and never skips). See `tests/README-tests.md`.

`ruff` selects `E,F,I`, line length 100 (`black` matches). Never open a
PR with a red suite.

## Code style

- Match the existing module boundaries — see `docs/architecture.md`'s
  module map before adding a new `core/*.py` file; most new behaviour
  belongs inside an existing module unless it's a genuinely new concern.
- `FleetError` (and its subclasses in `core/errors.py`) is how
  user-facing failures are raised — every one should carry an actionable
  `.message`.
- Tests live in `tests/`, one file per module (`test_<module>.py`),
  following the existing fixture conventions in `tests/conftest.py`.

## Pull requests

- Keep the suite green (`pytest`/`ruff`/`black`, see above) — CI runs the
  same three checks.
- Describe *why*, not just *what*, in the PR description.
- If your change touches `ansible/`, note whether you've run
  `ansible-lint`/`yamllint` locally (`infra` extra) — CI runs them
  non-blocking for now (a separate Bitbucket Pipelines step,
  see `bitbucket-pipelines.yml`).
- `docs/architecture.md` and `CLAUDE.md`'s module map should stay in sync
  with any new `core/*.py` module.
- Add your entry under `## [Unreleased]` in `CHANGELOG.md` in the same
  branch; the conventions are in
  [`docs/RELEASING.md`](docs/RELEASING.md#3-changelog-conventions).

## CI, mirror and dependency updates

- **Bitbucket Pipelines is the canonical (and only) CI** (`bitbucket-pipelines.yml`):
  pytest, ruff, black and an offline systemd security check on every pull
  request, on `develop` and on `v*` release tags, plus a non-blocking
  ansible-lint/yamllint step on pull requests and `develop`. Bitbucket is the
  source of truth; there is no GitHub Actions workflow. If your change makes a
  unit's `systemd-analyze security` score worse the pipeline fails; an
  intended change means re-recording the baseline (see
  [`docs/README-ci.md`](docs/README-ci.md)).
- **GitHub is a read-only mirror**, published automatically: `develop` after
  each green push, `main` plus the tag on each `v*` release tag. Nothing is
  ever force-pushed, and a tag that is not the tip of `main` is refused.
  Never push or merge on GitHub directly: GitHub pull requests are welcome,
  but the maintainer applies them on Bitbucket and the mirror publishes the
  result. The mirror steps skip unless
  `GITHUB_MIRROR_URL` is set, so forks are unaffected.
- **Renovate** (`renovate-config.json`, run by the scheduled `custom: renovate`
  pipeline) opens daily grouped dependency PRs against `develop`; review them
  like any other PR. Their commits and PR titles start with the standing
  Jira key of the "Dependency updates (Renovate)" ticket. The Python
  interpreter version is never auto-bumped. Approve a PR (or comment `/merge`)
  and the `custom: renovate-merge` pipeline merges it once its gates are green;
  see [`docs/README-renovate.md`](docs/README-renovate.md).
  A bump of the vendored htmx in `docs/vendored-assets.md` also needs the
  file re-downloaded — see that document.
- Required repository variables and the GitHub deploy key are listed in the
  header of `bitbucket-pipelines.yml`.
- **Releases and hotfixes** follow Gitflow (`develop` -> `release/X.Y.Z` ->
  `main` + annotated tag); the full procedure, versioning rules and push
  order are in [`docs/RELEASING.md`](docs/RELEASING.md).

## See also

- `docs/architecture.md` — module map and design.
- `docs/RELEASING.md` — versioning, CHANGELOG conventions, release and hotfix steps.
- `docs/README-ci.md` — the pipelines, the systemd security check and its baseline.
- `CLAUDE.md` — the AI-agent-facing contributor guide (same codebase,
  machine-readable framing).
