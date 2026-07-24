"""Display-only sidebar renderer for `fleet tmux` (runs inside a tmux pane).

Renders a vertical list of instances with status, highlighting the current
window's row. Read-only: it does not switch windows (that stays native tmux).
"""

from __future__ import annotations

import time

from rich.console import Console
from rich.text import Text

from fleet.core import ddev, shell, tmux
from fleet.core.instances import FleetPaths, read_instance_git_branch
from fleet.core.reboot import RebootStatus, format_duration_since, read_reboot_status

STATUS_GLYPH = {"running": "●", "stopped": "○", "deployed": "•", "error": "!"}
STATUS_STYLE = {"running": "green", "stopped": "grey50", "deployed": "cyan", "error": "red"}

# Operator cheat-sheet shown at the bottom of every sidebar. "^b" is the tmux
# prefix (Ctrl-b). `detach` returns you to your normal shell WITHOUT stopping
# anything — reattach later with `fleet tmux`.
KEY_HINTS = [
    "^b w   switch tab",
    "^b R   reset tab",
    "^b d   detach → shell",
    "^b ←→  move pane",
    "^b z   zoom pane (toggle)",
]


def _statuses() -> dict[str, str]:
    try:
        projects = ddev.list_projects()
    except Exception:  # noqa: BLE001 - status is best-effort
        return {}
    out: dict[str, str] = {}
    for proj in projects:
        name = proj.get("name")
        if name:
            out[name] = "running" if proj.get("status") == "running" else "stopped"
    return out


def build_rows(instance_ids, statuses, current, branches=None) -> list[tuple[str, str]]:
    branches = branches or {}
    rows: list[tuple[str, str]] = []
    general_style = "current" if current == tmux.GENERAL_WINDOW else "dim"
    rows.append(("  general", general_style))
    for instance_id in sorted(instance_ids):
        status = statuses.get(instance_id, "deployed")
        glyph = STATUS_GLYPH.get(status, "•")
        text = f"{glyph} {instance_id}"
        style = "current" if instance_id == current else STATUS_STYLE.get(status, "white")
        rows.append((text, style))
        branch = branches.get(instance_id)
        if branch:
            avail = tmux.SIDEBAR_WIDTH - 4
            for start in range(0, len(branch), avail):
                rows.append((f"    {branch[start:start + avail]}", "grey50"))
    return rows


def _branches(paths: FleetPaths, ids: list[str]) -> dict[str, str]:
    return {i: read_instance_git_branch(paths.instances / i) for i in ids}


def _reboot_banner_lines(status: RebootStatus) -> list[str]:
    avail = tmux.SIDEBAR_WIDTH - 4
    duration = format_duration_since(status.since) if status.since else "?"
    lines = ["REBOOT REQUIRED", f"pending {duration}"]
    shown = status.packages[:3]
    pkg_text = ", ".join(shown)
    if len(status.packages) > 3:
        pkg_text += f" +{len(status.packages) - 3} more"
    if pkg_text:
        for start in range(0, len(pkg_text), avail):
            lines.append(pkg_text[start : start + avail])
    return lines


def _render(
    console: Console,
    window: str,
    ids: list[str],
    statuses: dict[str, str],
    branches: dict[str, str],
    reboot_status: RebootStatus | None = None,
) -> None:
    body = Text()
    body.append("FLEET\n\n", style="bold")
    if reboot_status is not None and reboot_status.pending:
        for line in _reboot_banner_lines(reboot_status):
            body.append(line + "\n", style="red bold")
        body.append("\n")
    for text, style in build_rows(ids, statuses, window, branches):
        body.append(text + "\n", style=("reverse bold" if style == "current" else style))
    body.append("\nkeys · ^b = Ctrl-b\n", style="bold grey50")
    for hint in KEY_HINTS:
        body.append(hint + "\n", style="grey50")
    body.append("\nreattach: fleet tmux\n", style="grey50")
    console.clear()
    console.print(body)


def run(
    paths: FleetPaths,
    window: str,
    *,
    once: bool = False,
    list_interval: float = 2.0,
    status_interval: float = 10.0,
    branch_interval: float = 300.0,
    reboot_interval: float = 10.0,
) -> None:
    console = Console()
    statuses = _statuses()
    ids = shell.list_instance_ids(paths)
    branches = _branches(paths, ids)
    reboot_status = read_reboot_status()
    last_status = time.monotonic()
    last_branch = last_status
    last_reboot = last_status
    while True:
        now = time.monotonic()
        if now - last_status >= status_interval:
            statuses = _statuses()
            ids = shell.list_instance_ids(paths)
            last_status = now
        if now - last_branch >= branch_interval:
            branches = _branches(paths, ids)
            last_branch = now
        if now - last_reboot >= reboot_interval:
            reboot_status = read_reboot_status()
            last_reboot = now
        _render(console, window, ids, statuses, branches, reboot_status)
        if once:
            return
        try:
            alive = tmux.session_exists()
        except Exception:  # noqa: BLE001 - tmux gone -> exit the sidebar cleanly
            return
        if not alive:
            return
        time.sleep(list_interval)
