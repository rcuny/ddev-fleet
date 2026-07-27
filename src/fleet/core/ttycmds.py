"""Resolve an instance's `tty1`/`tty2` template commands (spec's
`[[issue-id]]` + interactive tmux panes design).

`deploy()` (in `core/instances.py`) knows the project/label/branch/template
already, because it just resolved them to run the deploy. `fleet tmux`'s
`reconcile()` (in `core/tmux.py`) knows only an instance id — everything
else has to be recovered from the on-disk `.fleet/instance.yml`. Both need
the exact same answer: which `tty1`/`tty2` commands (if any) to type into a
freshly created window's panes, with `[[token]]`s substituted using the same
context asset files get. Rather than duplicate that registry+secrets+token
plumbing in two places (and inevitably let them drift), it lives here once.
`core/tmux.py` stays a thin, registry-agnostic tmux wrapper that only knows
about plain command lists; `core/instances.py`'s tmux hook and `cli.py`'s
`_cmd_tmux` both call into this module instead.
"""

from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from ruamel.yaml import YAML

from fleet.core.registry import Registry
from fleet.core.secrets import read_secrets, secret_tokens
from fleet.core.tokens import build_context, extract_issue_id, substitute_lenient

_yaml = YAML()


@dataclass(frozen=True)
class TtyPlan:
    tty1: list[str] = field(default_factory=list)
    tty2: list[str] = field(default_factory=list)
    skipped: list[str] = field(default_factory=list)

    @property
    def is_empty(self) -> bool:
        return not self.tty1 and not self.tty2

    @classmethod
    def empty(cls) -> "TtyPlan":
        return cls()


EMPTY = TtyPlan.empty()


def _substitute_commands(
    key: str, commands: list[str], context: dict[str, str]
) -> tuple[list[str], list[str]]:
    """Run `substitute_lenient` over one tty key's commands, preserving
    order. A command with unresolved tokens is dropped and a
    `skipped ...` line (naming the token(s) and echoing the ORIGINAL,
    unsubstituted command — never the substituted/secret-bearing text) is
    appended for the caller to log."""
    resolved: list[str] = []
    skipped: list[str] = []
    for command in commands:
        substituted, unresolved = substitute_lenient(command, context)
        if unresolved:
            names = ", ".join(f"[[{name}]]" for name in unresolved)
            skipped.append(f"skipped {key} (unresolved {names}): {command}")
        else:
            resolved.append(substituted)
    return resolved, skipped


def plan_from_template(
    registry: Registry,
    paths: Any,
    project: str,
    label: str,
    branch: str,
    template: str,
) -> TtyPlan:
    """Resolve `template`'s `tty1`/`tty2` commands for a given
    project/label/branch, substituting `[[token]]`s with exactly the same
    context `deploy()` builds for asset files (including per-project
    secrets), and lenient-dropping any command that still has an unresolved
    token instead of raising."""
    resolved = registry.resolve(project, template, branch, label=label)

    context = build_context(
        project,
        label,
        branch,
        registry.domain,
        issue_id=extract_issue_id(registry.issue_id_regexp(project), label, branch),
    )
    context.update(secret_tokens(read_secrets(paths.project_secrets / f"{project}.env")))

    tty1, skipped1 = _substitute_commands("tty1", resolved.tty1, context)
    tty2, skipped2 = _substitute_commands("tty2", resolved.tty2, context)

    return TtyPlan(tty1=tty1, tty2=tty2, skipped=[*skipped1, *skipped2])


def _load_instance_yaml(info_path: Path) -> dict | None:
    """Best-effort load of `.fleet/instance.yml`. Returns None (not raise)
    for anything short of a well-formed mapping — missing file, empty file,
    or a YAML scalar/list instead of a mapping."""
    if not info_path.exists():
        return None
    try:
        with open(info_path, "r", encoding="utf-8") as fh:
            data = _yaml.load(fh)
    except Exception:
        return None
    if not isinstance(data, dict):
        return None
    return data


def plan_for_instance(registry: Registry, paths: Any, instance_id: str) -> TtyPlan:
    """Recover an instance's tty plan from disk alone — the path
    `reconcile()` uses for a window it is about to create for `instance_id`.

    Returns an EMPTY plan, never an exception, whenever the answer is "no
    tty commands": missing instance directory, missing/malformed
    `instance.yml`, no `template:` key (every pre-existing instance predates
    this field — expected, silent no-op), an unknown recorded project, or a
    recorded template no longer present in the *live* registry. Only a truly
    unexpected error falls through to the blanket `except Exception` net —
    the explicit checks above are what keep genuine bugs from being hidden
    as silent no-ops in tests.
    """
    try:
        instance_dir = paths.instances / instance_id
        if not instance_dir.exists():
            return EMPTY

        info_path = instance_dir / ".fleet" / "instance.yml"
        data = _load_instance_yaml(info_path)
        if data is None:
            return EMPTY

        template = data.get("template")
        if not template:
            return EMPTY

        project = data.get("project")
        if not project or not registry.has_project(project):
            return EMPTY
        if template not in registry.template_keys(project):
            return EMPTY

        label = data.get("instance") or instance_id
        branch = data.get("branch") or ""

        return plan_from_template(registry, paths, project, label, branch, template)
    except Exception:
        return EMPTY
