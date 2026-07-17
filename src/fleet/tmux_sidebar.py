"""Display-only sidebar renderer for `fleet tmux` (runs inside a tmux pane).

Renders a vertical list of instances with status, highlighting the current
window's row. Read-only: it does not switch windows (that stays native tmux).
"""

from __future__ import annotations

import time

from rich.console import Console
from rich.text import Text

from fleet.core import ddev, shell, tmux
from fleet.core.instances import FleetPaths

STATUS_GLYPH = {"running": "●", "stopped": "○", "deployed": "•", "error": "!"}
STATUS_STYLE = {"running": "green", "stopped": "grey50", "deployed": "cyan", "error": "red"}


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


def build_rows(instance_ids, statuses, current) -> list[tuple[str, str]]:
    rows: list[tuple[str, str]] = []
    general_style = "current" if current == tmux.GENERAL_WINDOW else "dim"
    rows.append(("  general", general_style))
    for instance_id in sorted(instance_ids):
        status = statuses.get(instance_id, "deployed")
        glyph = STATUS_GLYPH.get(status, "•")
        text = f"{glyph} {instance_id}"
        style = "current" if instance_id == current else STATUS_STYLE.get(status, "white")
        rows.append((text, style))
    return rows


def _render(console: Console, paths: FleetPaths, window: str, statuses: dict[str, str]) -> None:
    ids = shell.list_instance_ids(paths)
    body = Text()
    body.append("FLEET\n\n", style="bold")
    for text, style in build_rows(ids, statuses, window):
        body.append(text + "\n", style=("reverse bold" if style == "current" else style))
    console.clear()
    console.print(body)


def run(
    paths: FleetPaths,
    window: str,
    *,
    once: bool = False,
    list_interval: float = 2.0,
    status_interval: float = 10.0,
) -> None:
    console = Console()
    statuses = _statuses()
    last_status = time.monotonic()
    while True:
        if time.monotonic() - last_status >= status_interval:
            statuses = _statuses()
            last_status = time.monotonic()
        _render(console, paths, window, statuses)
        if once:
            return
        try:
            alive = tmux.session_exists()
        except Exception:  # noqa: BLE001 - tmux gone -> exit the sidebar cleanly
            return
        if not alive:
            return
        time.sleep(list_interval)
