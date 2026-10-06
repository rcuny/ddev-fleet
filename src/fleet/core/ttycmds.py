"""Resolve an instance's `post_deploy.tty1`/`post_deploy.tty2` commands
(`[[issue-id]]` + interactive tmux panes design; FLE-6).

tty commands are a DEPLOY action: `deploy()` (in `core/instances.py`) types
them once into a freshly created window's panes, from the template it
resolved at deploy time. Nothing else may type them — not `fleet tmux`, not
reconcile, not the `fleet-tmux.service` boot reconcile, not a recreated
window (those all give plain shells) — which is why this module only offers
`plan_from_resolved` and no "recover from disk later" counterpart. The
registry+secrets+token plumbing (`[[token]]`s substituted with the same
context asset files get) lives here so `core/tmux.py` stays a thin,
registry-agnostic tmux wrapper that only knows about plain command lists.
"""

from dataclasses import dataclass, field
from typing import Any

from fleet.core.registry import Registry, ResolvedInstance
from fleet.core.secrets import read_secrets, secret_tokens
from fleet.core.tokens import build_context, extract_issue_id, substitute_lenient


@dataclass(frozen=True)
class TtyPlan:
    tty1: list[str] = field(default_factory=list)
    tty2: list[str] = field(default_factory=list)
    skipped: list[str] = field(default_factory=list)

    @property
    def is_empty(self) -> bool:
        return not self.tty1 and not self.tty2


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


def plan_from_resolved(registry: Registry, paths: Any, resolved: ResolvedInstance) -> TtyPlan:
    """Plan the tty commands of `resolved` — the `ResolvedInstance` `deploy()`
    already holds, i.e. the template as it was at DEPLOY time (never
    re-resolved from the live `fleet.yml`) — substituting `[[token]]`s with
    exactly the same context `deploy()` builds for asset files (including
    per-project secrets), and lenient-dropping any command that still has an
    unresolved token instead of raising."""
    project, label, branch = resolved.project, resolved.label, resolved.branch

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
