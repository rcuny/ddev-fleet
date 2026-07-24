---
Author: Claude Code
Reviewer: none
Last updated: 2026-07-25
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
```

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
  non-blocking for now (see `.github/workflows/ci.yml`).
- `docs/architecture.md` and `CLAUDE.md`'s module map should stay in sync
  with any new `core/*.py` module.

## See also

- `docs/architecture.md` — module map and design.
- `CLAUDE.md` — the AI-agent-facing contributor guide (same codebase,
  machine-readable framing).
