"""Fails the build if personal/deployment-specific strings leak into the
public repo. See
.claude/user/docs/specs/2026-07-24-fleet-open-source-release-design.md §2
for the design and denylist rationale.
"""

from __future__ import annotations

import pathlib

REPO_ROOT = pathlib.Path(__file__).resolve().parents[3]
THIS_FILE = pathlib.Path(__file__).resolve()

EXCLUDED_DIR_NAMES = {
    ".git",
    ".venv",
    "__pycache__",
    "instances",
    "assets",
    # Local/CI caches — gitignored, never part of a fresh clone, excluded
    # so a stale local cache can't produce a false positive during dev runs.
    ".pytest_cache",
    ".ruff_cache",
    ".ansible",
    ".mypy_cache",
}
EXCLUDED_DIR_SUFFIXES = (".egg-info",)

DENYLIST = [
    "personal-maintainer",
    "personal_maintainer",
    "ddev.personal.example",
    "fleet.personal.example",
    "contact@personal.example",
    "Kimsufi",
    "bitbucket.org/personal_maintainer",
]

# "OVH" itself is intentionally NOT on the denylist (removed 2026-09-22,
# fleet_tls_mode / caddy-dns-ovh feature work): it originally only ever
# appeared as an incidental leak of the maintainer's own hosting provider
# (Kimsufi is OVH's budget-server brand — still denylisted above). It is
# now also a genuine, generic, user-facing feature name — `fleet_tls_mode:
# ovh_dns`, the `caddy-dns/ovh` Caddy plugin, `OVH_ENDPOINT`/
# `OVH_APPLICATION_KEY`/etc. — that any operator can opt into regardless of
# where they host, exactly like naming "Cloudflare" or "Route53" would be
# in a tool supporting those DNS backends. Banning the bare substring would
# make this permanent, intentional feature unshippable. "Kimsufi" alone
# still catches the original personal-deployment leak this rule exists for.

# Tracked, temporary exceptions. See the docstring at the top of this file
# and 2026-07-24-fleet-open-source-release-plan.md Phase B/C tasks for
# exactly what removes each entry. Do not add a new entry here to silence a
# freshly discovered leak — fix the leak instead.
#
# Each value set lists every DENYLIST needle that independently matches a
# line in that file (e.g. the bare "personal-maintainer" substring also matches
# inside "fleet.personal.example", so it must be listed alongside it or the
# bare-substring needle keeps failing on its own).
GRANDFATHERED: dict[str, set[str]] = {}


def _iter_text_files():
    for path in REPO_ROOT.rglob("*"):
        if path.is_dir():
            continue
        if path.resolve() == THIS_FILE:
            continue
        rel_parts = path.relative_to(REPO_ROOT).parts
        if any(part in EXCLUDED_DIR_NAMES for part in rel_parts[:-1]):
            continue
        if any(part.endswith(EXCLUDED_DIR_SUFFIXES) for part in rel_parts[:-1]):
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            continue
        yield path, text


def test_no_personal_leakage():
    failures = []
    for path, text in _iter_text_files():
        rel = path.relative_to(REPO_ROOT).as_posix()
        allowed = GRANDFATHERED.get(rel, set())
        for lineno, line in enumerate(text.splitlines(), start=1):
            for needle in DENYLIST:
                if needle in line and needle not in allowed:
                    failures.append(f"{rel}:{lineno}: matched {needle!r}: {line.strip()}")
    assert not failures, "personal/deployment-specific data leaked:\n" + "\n".join(failures)
