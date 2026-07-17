"""tmux lifecycle engine for `fleet tmux`.

Thin wrappers over the `tmux` CLI, mirroring core/ddev.py's injectable-runner
pattern so behaviour is asserted against a FakeRunner in tests. All calls pass
echo=False so tmux query noise never streams into deploy/destroy logs.
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

from fleet.core.errors import FleetError
from fleet.core.runner import RunResult, run_streamed

SESSION = "fleet"
GENERAL_WINDOW = "general"
SIDEBAR_WIDTH = 24
SIDEBAR_ROLE_OPT = "@fleet_role"
SIDEBAR_ROLE = "sidebar"
MANAGED_OPT = "@fleet_managed"

_UNSAFE = set(" \t\n:./")


def _run(runner, args: list[str]) -> RunResult:
    return runner(["tmux", *args], echo=False)


def _assert_target_safe(name: str) -> None:
    if not name or any(ch in _UNSAFE for ch in name) or ".." in name:
        raise FleetError(f"unsafe tmux target name {name!r}")


def session_exists(*, runner=run_streamed) -> bool:
    return _run(runner, ["has-session", "-t", SESSION]).returncode == 0


def list_window_names(*, runner=run_streamed) -> list[str]:
    result = _run(runner, ["list-windows", "-t", SESSION, "-F", "#{window_name}"])
    return [line.strip() for line in result.lines if line.strip()]


def window_exists(instance_id: str, *, runner=run_streamed) -> bool:
    return instance_id in list_window_names(runner=runner)


def managed_window_names(*, runner=run_streamed) -> list[str]:
    """Names of windows tagged as fleet-managed (i.e. created by
    `ensure_instance_window`), as opposed to `general` or any window a human
    created/renamed by hand — those must never be pruned by `reconcile`."""
    result = _run(
        runner,
        ["list-windows", "-t", SESSION, "-F", f"#{{window_name}}\t#{{{MANAGED_OPT}}}"],
    )
    names = []
    for line in result.lines:
        if not line.strip():
            continue
        name, _, managed = line.partition("\t")
        if managed == "1":
            names.append(name)
    return names


def ensure_instance_window(instance_id: str, instance_dir: Path, *, runner=run_streamed) -> None:
    """Create the window (two bash panes + sidebar) if absent. Assumes the
    `fleet` session already exists (callers guarantee this)."""
    _assert_target_safe(instance_id)
    if window_exists(instance_id, runner=runner):
        return
    created = _run(
        runner,
        [
            "new-window",
            "-t",
            SESSION,
            "-n",
            instance_id,
            "-c",
            str(instance_dir),
            "-P",
            "-F",
            "#{pane_id}",
        ],
    )
    main_pane = created.lines[0].strip()
    _run(runner, ["split-window", "-h", "-t", main_pane, "-c", str(instance_dir)])
    _run(
        runner,
        ["set-window-option", "-t", f"{SESSION}:{instance_id}", "automatic-rename", "off"],
    )
    _run(runner, ["set-option", "-w", "-t", f"{SESSION}:{instance_id}", MANAGED_OPT, "1"])
    ensure_sidebar(instance_id, runner=runner)
    _run(runner, ["select-pane", "-t", main_pane])


def kill_instance_window(instance_id: str, *, runner=run_streamed) -> None:
    if not session_exists(runner=runner):
        return
    if window_exists(instance_id, runner=runner):
        _run(runner, ["kill-window", "-t", f"{SESSION}:{instance_id}"])


def _sidebar_cmd(window: str) -> list[str]:
    return [sys.executable, "-m", "fleet.cli", "tmux-sidebar", "--window", window]


def has_sidebar(window: str, *, runner=run_streamed) -> bool:
    result = _run(
        runner, ["list-panes", "-t", f"{SESSION}:{window}", "-F", f"#{{{SIDEBAR_ROLE_OPT}}}"]
    )
    return SIDEBAR_ROLE in [line.strip() for line in result.lines]


def ensure_sidebar(window: str, *, runner=run_streamed) -> None:
    if has_sidebar(window, runner=runner):
        return
    created = _run(
        runner,
        [
            "split-window",
            "-hbf",
            "-l",
            str(SIDEBAR_WIDTH),
            "-t",
            f"{SESSION}:{window}",
            "-d",
            "-P",
            "-F",
            "#{pane_id}",
            "--",
            *_sidebar_cmd(window),
        ],
    )
    pane = created.lines[0].strip()
    _run(runner, ["set-option", "-p", "-t", pane, SIDEBAR_ROLE_OPT, SIDEBAR_ROLE])


def ensure_session(home: Path, *, runner=run_streamed) -> None:
    if session_exists(runner=runner):
        return
    _run(runner, ["new-session", "-d", "-s", SESSION, "-n", GENERAL_WINDOW, "-c", str(home)])
    _run(
        runner,
        ["set-window-option", "-t", f"{SESSION}:{GENERAL_WINDOW}", "automatic-rename", "off"],
    )
    ensure_sidebar(GENERAL_WINDOW, runner=runner)


def reconcile(paths, instance_ids, *, runner=run_streamed) -> None:
    ensure_session(paths.home, runner=runner)
    for instance_id in sorted(instance_ids):
        ensure_instance_window(instance_id, paths.instances / instance_id, runner=runner)
    for window in list_window_names(runner=runner):
        ensure_sidebar(window, runner=runner)  # self-heal
    for window in managed_window_names(runner=runner):
        if window not in instance_ids:
            kill_instance_window(window, runner=runner)


def attach() -> None:
    os.execvp("tmux", ["tmux", "attach", "-t", SESSION])
