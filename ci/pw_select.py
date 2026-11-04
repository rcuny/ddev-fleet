#!/usr/bin/env python3
"""FLE-172: decide which optional Playwright parts a CI run needs, from the files a change touches.

Two decisions, both made from the changed-file list (the diff against the merge base, see ci/run-gates.sh):

  phone     the phone project runs only when the change touches interface code (templates, assets, Twig
            components, form rendering, phone specs, the Playwright config and support code);
  targeted  the quick tier skips every `*-coverage` and `*-validation` spec; this adds back the ones whose
            feature area the diff touches. The mapping is deliberately simple (see `area_matches`):
            a spec `<stem>-coverage.spec.ts` belongs to the area `<stem>`, matched by name against the changed
            paths. Cross-cutting changes (layout, config, migrations, lock files, test support) run them all.
            When unsure, run the spec.

  pw_select.py [--base REV] [--head REV] [--repo DIR] [--files F...] [--format env|json]

With no --files the list comes from `git diff --name-only $(git merge-base BASE HEAD) HEAD`; BASE defaults to
origin/develop, HEAD to HEAD. If the diff cannot be computed the answer is the safe one: phone on, every spec.
Standard library only.
"""

from __future__ import annotations

import argparse
import fnmatch
import json
import re
import shlex
import subprocess
import sys
from pathlib import Path

# Interface code: a change here turns the phone project on.
UI_PATTERNS = (
    "app/templates/*",
    "app/assets/*",
    "app/src/Twig/*",
    "app/src/Form/*",
    "tests/playwright/*.phone.spec.ts",
    "tests/playwright/playwright.config.ts",
    "tests/playwright/support/*",
)

# Cross-cutting changes: every coverage / validation spec may be affected, so all of them run.
SHARED_PATTERNS = (
    "app/templates/base*",
    "app/templates/_*",
    "app/templates/components/*",
    "app/templates/layout*",
    "app/assets/*",
    "app/config/*",
    "app/migrations/*",
    "app/src/Kernel.php",
    "app/src/Security/*",
    "app/src/Twig/*",
    "app/src/Form/*",
    "app/composer.json",
    "app/composer.lock",
    "app/importmap.php",
    "tests/playwright/playwright.config.ts",
    "tests/playwright/support/*",
    "tests/playwright/package.json",
    "tests/playwright/package-lock.json",
)

# Where a feature area's code lives; the prefix is stripped before the name match so that the directory
# `app/templates/` does not make the area `templates` match every template.
AREA_ROOTS = ("app/src/", "app/templates/", "app/assets/", "app/tests/")
SPEC_RE = re.compile(r"^(?P<stem>.+)-(?:coverage|validation)\.spec\.ts$")


def _norm(text: str) -> str:
    """CamelCase, kebab-case and snake_case collapse to the same lowercase run: SetupWizard == setup-wizard."""
    return re.sub(r"[-_/.]", "", text).lower()


def _keys(stem: str) -> set[str]:
    """Name keys of an area: the stem and its parts, singular and plural (schedules -> schedule)."""
    keys = {_norm(stem)}
    for part in stem.split("-"):
        if len(part) >= 4:
            keys.add(_norm(part))
    return {k[:-1] if k.endswith("s") and len(k) > 4 else k for k in keys}


def _matches_any(path: str, patterns: tuple[str, ...]) -> bool:
    return any(fnmatch.fnmatch(path, p) for p in patterns)


def area_matches(stem: str, path: str) -> bool:
    """True when `path` belongs to the feature area `stem`."""
    pw = "tests/playwright/"
    if path.startswith(pw):
        # A spec of the same area (projects.spec.ts, projects-crud.spec.ts, projects-coverage.spec.ts ...).
        name = path[len(pw):]
        return name == stem or name.startswith(stem + "-") or name.startswith(stem + ".")
    for root in AREA_ROOTS:
        if path.startswith(root):
            rest = _norm(path[len(root):])
            return any(k in rest for k in _keys(stem))
    return False


def specs_in(pw_dir: Path) -> dict[str, str]:
    """{spec file name: area stem} for every coverage / validation spec."""
    out: dict[str, str] = {}
    for f in sorted(pw_dir.glob("*.spec.ts")):
        m = SPEC_RE.match(f.name)
        if m:
            out[f.name] = m.group("stem")
    return out


def select(changed: list[str] | None, specs: dict[str, str], phone_tag_files: list[str] | None = None) -> dict[str, object]:
    """changed=None means the diff is unknown: the safe answer."""
    if changed is None:
        return {
            "phone": "on",
            "phone_reason": "diff unknown, running it to be safe",
            "targeted": sorted(specs),
            "targeted_reason": "diff unknown, every coverage spec",
        }
    ui = [p for p in changed if _matches_any(p, UI_PATTERNS)]
    # A change that adds, removes or edits a line carrying @phone in any other spec file also reaches the phone project.
    ui += [p for p in (phone_tag_files or []) if p not in ui]
    if ui:
        phone, phone_reason = "on", "UI change: " + ", ".join(ui[:3]) + (" ..." if len(ui) > 3 else "")
    else:
        phone, phone_reason = "off", "no UI change"

    shared = [p for p in changed if _matches_any(p, SHARED_PATTERNS)]
    if shared:
        targeted, why = sorted(specs), "shared code changed: " + ", ".join(shared[:2]) + (" ..." if len(shared) > 2 else "")
    else:
        targeted = sorted(name for name, stem in specs.items() if any(area_matches(stem, p) for p in changed))
        why = f"{len(targeted)} of {len(specs)} by area" if targeted else "none touched"
    return {"phone": phone, "phone_reason": phone_reason, "targeted": targeted, "targeted_reason": why}


def changed_files(repo: Path, base: str, head: str) -> list[str] | None:
    def git(*args: str) -> str:
        return subprocess.run(["git", "-C", str(repo), *args], check=True, capture_output=True, text=True).stdout

    try:
        mb = git("merge-base", base, head).strip()
        return [line for line in git("diff", "--name-only", mb, head).splitlines() if line]
    except (subprocess.CalledProcessError, FileNotFoundError):
        return None


def phone_tag_files(repo: Path, base: str, head: str) -> list[str]:
    """Files under tests/playwright/ whose diff hunks add or remove a line mentioning @phone."""
    try:
        mb = subprocess.run(["git", "-C", str(repo), "merge-base", base, head], check=True, capture_output=True, text=True).stdout.strip()
        diff = subprocess.run(
            ["git", "-C", str(repo), "diff", "-U0", mb, head, "--", "tests/playwright"],
            check=True, capture_output=True, text=True,
        ).stdout
    except (subprocess.CalledProcessError, FileNotFoundError):
        return []
    files: list[str] = []
    current = ""
    for line in diff.splitlines():
        if line.startswith("+++ b/"):
            current = line[6:]
        elif line[:1] in "+-" and not line.startswith(("+++", "---")) and "@phone" in line and current and current not in files:
            files.append(current)
    return files


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--repo", default=str(Path(__file__).resolve().parent.parent))
    ap.add_argument("--base", default="origin/develop")
    ap.add_argument("--head", default="HEAD")
    ap.add_argument("--files", nargs="*", default=None)
    ap.add_argument("--format", choices=("env", "json"), default="env")
    args = ap.parse_args(argv)

    repo = Path(args.repo)
    files = args.files if args.files is not None else changed_files(repo, args.base, args.head)
    tagged = phone_tag_files(repo, args.base, args.head) if args.files is None else []
    result = select(files, specs_in(repo / "tests" / "playwright"), tagged)
    if args.format == "json":
        print(json.dumps(result, indent=2))
    else:
        print(f"FLEET_PW_PHONE={shlex.quote(str(result['phone']))}")
        print(f"FLEET_PW_TARGETED={shlex.quote(' '.join(result['targeted']))}")  # type: ignore[arg-type]
        print(f"PW_PHONE_REASON={shlex.quote(str(result['phone_reason']))}")
        print(f"PW_TARGETED_REASON={shlex.quote(str(result['targeted_reason']))}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
