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
SIDEBAR_WIDTH = 50
MIN_BASH_WIDTH = 10
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
    apply_pane_layout(instance_id, runner=runner)
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


def current_window(*, runner=run_streamed) -> str:
    result = _run(runner, ["display-message", "-p", "#{window_name}"])
    return result.lines[0].strip() if result.lines else ""


def _reset_bind_command() -> str:
    # Run in the fleet venv python. Deliberately does NOT interpolate
    # #{window_name} into this shell string — tmux would expand it before
    # run-shell executes, so a window renamed with quotes/backticks could
    # inject arbitrary shell. Instead `tmux-reset` is invoked with no window
    # argument and resolves the current window itself via `current_window()`
    # (see cli.py:_cmd_tmux_reset), which reads it through a safe argv, not
    # shell interpolation.
    return f"{sys.executable} -m fleet.cli tmux-reset"


def apply_settings(*, runner=run_streamed) -> None:
    """Apply operator-facing tmux options + key bindings to the fleet session.
    Idempotent: every call is a set-option/bind-key overwrite, so it is safe to
    re-run on each attach (including for sessions made by an older build)."""
    opts = [
        ["set-option", "-t", SESSION, "mouse", "on"],
        ["set-option", "-t", SESSION, "set-clipboard", "on"],
        ["set-option", "-t", SESSION, "status", "on"],
        ["set-option", "-t", SESSION, "status-position", "bottom"],
        ["set-option", "-t", SESSION, "window-status-format", " #I:#W "],
        ["set-option", "-t", SESSION, "window-status-current-format", " #I:#W "],
        ["set-option", "-t", SESSION, "window-status-current-style", "reverse,bold"],
    ]
    for args in opts:
        _run(runner, args)
    _run(runner, ["bind-key", "R", "run-shell", _reset_bind_command()])


def ensure_session(home: Path, *, runner=run_streamed) -> None:
    if session_exists(runner=runner):
        apply_settings(runner=runner)  # re-apply for already-existing sessions
        return
    _run(runner, ["new-session", "-d", "-s", SESSION, "-n", GENERAL_WINDOW, "-c", str(home)])
    _run(
        runner,
        ["set-window-option", "-t", f"{SESSION}:{GENERAL_WINDOW}", "automatic-rename", "off"],
    )
    ensure_general_layout(home, runner=runner)
    apply_settings(runner=runner)  # new session


def ensure_general_layout(home: Path, *, runner=run_streamed) -> None:
    """Give the `general` window the same 3-pane layout as instance windows: the
    left sidebar plus TWO bash panes, so a human can run e.g. Claude in the
    second bash pane. Idempotent and non-destructive: the second bash pane is
    added only when the window currently has a single bash pane, so a human who
    has split it further is left alone. Safe to call on every `fleet tmux`
    reconcile — that is how an already-running session gains the second pane."""
    ensure_sidebar(GENERAL_WINDOW, runner=runner)
    bash_panes = [
        pane_id
        for pane_id, role in _list_panes_with_roles(GENERAL_WINDOW, runner=runner)
        if role != SIDEBAR_ROLE
    ]
    if len(bash_panes) == 1:
        _run(runner, ["split-window", "-h", "-t", bash_panes[0], "-c", str(home)])
    apply_pane_layout(GENERAL_WINDOW, runner=runner)


def reconcile(paths, instance_ids, *, runner=run_streamed) -> None:
    ensure_session(paths.home, runner=runner)
    ensure_general_layout(paths.home, runner=runner)  # heal an already-running session
    for instance_id in sorted(instance_ids):
        ensure_instance_window(instance_id, paths.instances / instance_id, runner=runner)
    for window in list_window_names(runner=runner):
        ensure_sidebar(window, runner=runner)  # self-heal
    for window in managed_window_names(runner=runner):
        if window not in instance_ids:
            kill_instance_window(window, runner=runner)


def _list_panes_with_roles(window: str, *, runner=run_streamed) -> list[tuple[str, str]]:
    result = _run(
        runner,
        ["list-panes", "-t", f"{SESSION}:{window}", "-F", f"#{{pane_id}}\t#{{{SIDEBAR_ROLE_OPT}}}"],
    )
    panes: list[tuple[str, str]] = []
    for line in result.lines:
        if not line.strip():
            continue
        pane_id, _, role = line.partition("\t")
        panes.append((pane_id.strip(), role.strip()))
    return panes


def _sidebar_pane_id(window: str, *, runner=run_streamed) -> str | None:
    for pane_id, role in _list_panes_with_roles(window, runner=runner):
        if role == SIDEBAR_ROLE:
            return pane_id
    return None


def apply_pane_layout(window: str, *, runner=run_streamed) -> None:
    """Re-assert the standard pane widths for `window`: the sidebar fixed at
    SIDEBAR_WIDTH, and — on instance tabs with two bash panes — the two bash
    panes made equal. Resizing one of the two adjacent bash panes to half the
    non-sidebar width moves the border *between the bash panes* (not the sidebar
    border), so the other bash pane takes the equal remainder and the sidebar
    stays fixed. Best-effort: a missing sidebar or an unparseable window width
    simply skips that step, never raises. The sidebar always stays fixed; the
    bash-even step itself is skipped on very narrow windows (below
    MIN_BASH_WIDTH) to avoid tmux clamping the resize and stealing a column
    from the sidebar."""
    sidebar: str | None = None
    non_sidebar: list[str] = []
    for pane_id, role in _list_panes_with_roles(window, runner=runner):
        if role == SIDEBAR_ROLE:
            sidebar = pane_id
        else:
            non_sidebar.append(pane_id)

    if sidebar:
        _run(runner, ["resize-pane", "-t", sidebar, "-x", str(SIDEBAR_WIDTH)])

    if len(non_sidebar) >= 2:
        result = _run(
            runner,
            ["display-message", "-p", "-t", f"{SESSION}:{window}", "#{window_width}"],
        )
        raw = result.lines[0].strip() if result.lines else ""
        try:
            width = int(raw)
        except ValueError:
            return
        half = (width - SIDEBAR_WIDTH) // 2
        if half < MIN_BASH_WIDTH:
            return
        _run(runner, ["resize-pane", "-t", non_sidebar[0], "-x", str(half)])


def reset_window(paths, window: str, *, runner=run_streamed) -> None:
    """Rebuild the standard pane layout for `window` in place (no kill-window,
    so the tab keeps its index). general -> 1 bash + sidebar; instance -> 2 bash
    + sidebar. Best-effort: no-ops if the session/window is gone."""
    _assert_target_safe(window)
    if not session_exists(runner=runner):
        return
    if window not in list_window_names(runner=runner):
        return

    if window == GENERAL_WINDOW:
        cwd = paths.home
        want_bash = 1
    else:
        cwd = paths.instances / window
        want_bash = 2

    panes = _list_panes_with_roles(window, runner=runner)
    non_sidebar = [pid for pid, role in panes if role != SIDEBAR_ROLE]

    if non_sidebar:
        base = non_sidebar[0]
        _run(runner, ["respawn-pane", "-k", "-t", base, "-c", str(cwd)])
        for extra in non_sidebar[1:]:
            _run(runner, ["kill-pane", "-t", extra])
    else:
        created = _run(
            runner,
            [
                "split-window",
                "-h",
                "-t",
                f"{SESSION}:{window}",
                "-c",
                str(cwd),
                "-P",
                "-F",
                "#{pane_id}",
            ],
        )
        base = created.lines[0].strip()

    if want_bash == 2:
        _run(runner, ["split-window", "-h", "-t", base, "-c", str(cwd)])

    # Refresh the sidebar so it always reruns current code (branch line, width).
    # respawn-pane -k reruns the pane's original tmux-sidebar command; if the
    # sidebar pane was the one that got deleted, recreate it instead.
    sidebar = _sidebar_pane_id(window, runner=runner)
    if sidebar:
        _run(runner, ["respawn-pane", "-k", "-t", sidebar])
    else:
        ensure_sidebar(window, runner=runner)
    apply_pane_layout(window, runner=runner)
    _run(runner, ["select-pane", "-t", base])


def attach() -> None:
    os.execvp("tmux", ["tmux", "attach", "-t", SESSION])
