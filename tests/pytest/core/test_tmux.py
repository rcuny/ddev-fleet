from dataclasses import dataclass
from pathlib import Path

import pytest

from fleet.core import tmux
from fleet.core.errors import FleetError
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def _joined(fake):
    return [" ".join(str(c) for c in call["cmd"]) for call in fake.calls]


@dataclass
class _FakePaths:
    home: Path
    instances: Path


def test_assert_target_safe_rejects_bad_names():
    for bad in ["", "a b", "a:b", "a.b", "../x", "a/b"]:
        with pytest.raises(FleetError):
            tmux._assert_target_safe(bad)
    tmux._assert_target_safe("oak--click-3")  # ok, no raise


def test_session_exists_true_on_returncode_zero():
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    assert tmux.session_exists(runner=fake) is True
    assert _joined(fake) == ["tmux has-session -t fleet"]


def test_session_exists_false_on_nonzero():
    fake = FakeRunner(default=RunResult(returncode=1, lines=[]))
    assert tmux.session_exists(runner=fake) is False


def test_window_exists_reads_list_windows():
    fake = FakeRunner(
        scripted={
            "tmux list-windows -t fleet -F #{window_name}": RunResult(
                returncode=0, lines=["general", "oak--click-3"]
            )
        },
    )
    assert tmux.window_exists("oak--click-3", runner=fake) is True
    assert tmux.window_exists("nope--x", runner=fake) is False


def test_ensure_instance_window_creates_window_split_and_focus():
    import sys as _sys

    dir_ = Path("/srv/fleet/instances/oak--click-3")
    sidebar_split = (
        f"tmux split-window -hbf -l {tmux.SIDEBAR_WIDTH} -t fleet:oak--click-3 -d -P -F "
        "#{pane_id} -- " + _sys.executable + " -m fleet.cli tmux-sidebar --window oak--click-3"
    )
    fake = FakeRunner(
        scripted={
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general"]),
            "tmux new-window -t fleet -n oak--click-3 -c "
            + str(dir_)
            + " -P -F #{pane_id}": RunResult(0, ["%5"]),
            # sidebar list-panes: no sidebar yet -> empty role lines
            "tmux list-panes -t fleet:oak--click-3 -F #{@fleet_role}": RunResult(0, ["", ""]),
            # real ensure_sidebar (Task 2) splits since no sidebar role is present yet
            sidebar_split: RunResult(0, ["%9"]),
        },
    )
    tmux.ensure_instance_window("oak--click-3", dir_, runner=fake)
    joined = _joined(fake)
    new_window = "tmux new-window -t fleet -n oak--click-3 -c " + str(dir_) + " -P -F #{pane_id}"
    assert new_window in joined
    assert "tmux split-window -h -t %5 -c " + str(dir_) in joined
    assert "tmux set-window-option -t fleet:oak--click-3 automatic-rename off" in joined
    assert "tmux set-option -w -t fleet:oak--click-3 @fleet_managed 1" in joined
    assert "tmux select-pane -t %5" in joined


def test_ensure_instance_window_idempotent_when_exists():
    fake = FakeRunner(
        scripted={
            "tmux list-windows -t fleet -F #{window_name}": RunResult(
                0, ["general", "oak--click-3"]
            ),
        },
    )
    tmux.ensure_instance_window("oak--click-3", Path("/x"), runner=fake)
    assert _joined(fake) == ["tmux list-windows -t fleet -F #{window_name}"]


def test_kill_instance_window_kills_when_present():
    fake = FakeRunner(
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(
                0, ["general", "oak--click-3"]
            ),
        },
    )
    tmux.kill_instance_window("oak--click-3", runner=fake)
    assert "tmux kill-window -t fleet:oak--click-3" in _joined(fake)


def test_kill_instance_window_noop_when_no_session():
    fake = FakeRunner(scripted={"tmux has-session -t fleet": RunResult(1, [])})
    tmux.kill_instance_window("oak--click-3", runner=fake)
    assert _joined(fake) == ["tmux has-session -t fleet"]


# --- Task 2: sidebar pane management -----------------------------------


def test_has_sidebar_detects_role_option():
    fake = FakeRunner(
        scripted={
            "tmux list-panes -t fleet:general -F #{@fleet_role}": RunResult(0, ["", "sidebar"])
        }
    )
    assert tmux.has_sidebar("general", runner=fake) is True


def test_ensure_sidebar_splits_and_tags_when_absent():
    fake = FakeRunner(
        scripted={
            "tmux list-panes -t fleet:general -F #{@fleet_role}": RunResult(0, [""]),
        },
    )
    # script the split argv -> pane id
    import sys as _sys

    split = (
        f"tmux split-window -hbf -l {tmux.SIDEBAR_WIDTH} -t fleet:general -d -P -F #{{pane_id}} -- "
        + _sys.executable
        + " -m fleet.cli tmux-sidebar --window general"
    )
    fake._scripted[split] = RunResult(0, ["%9"])
    tmux.ensure_sidebar("general", runner=fake)
    joined = [" ".join(str(c) for c in c2["cmd"]) for c2 in fake.calls]
    assert split in joined
    assert "tmux set-option -p -t %9 @fleet_role sidebar" in joined


def test_ensure_sidebar_idempotent_when_present():
    fake = FakeRunner(
        scripted={
            "tmux list-panes -t fleet:general -F #{@fleet_role}": RunResult(0, ["sidebar", ""])
        }
    )
    tmux.ensure_sidebar("general", runner=fake)
    assert [" ".join(str(c) for c in c2["cmd"]) for c2 in fake.calls] == [
        "tmux list-panes -t fleet:general -F #{@fleet_role}"
    ]


# --- Task 3: ensure_session, reconcile, attach --------------------------


def test_ensure_session_creates_when_absent():
    import sys as _sys

    sidebar_split = (
        f"tmux split-window -hbf -l {tmux.SIDEBAR_WIDTH} -t fleet:general -d -P -F #{{pane_id}} -- "
        + _sys.executable
        + " -m fleet.cli tmux-sidebar --window general"
    )
    fake = FakeRunner(
        scripted={
            "tmux has-session -t fleet": RunResult(1, []),
            "tmux list-panes -t fleet:general -F #{@fleet_role}": RunResult(0, [""]),
            # real ensure_sidebar (Task 2) splits since no sidebar role is present yet
            sidebar_split: RunResult(0, ["%9"]),
        },
    )
    tmux.ensure_session(Path("/srv/fleet"), runner=fake)
    joined = [" ".join(str(c) for c in c2["cmd"]) for c2 in fake.calls]
    assert "tmux new-session -d -s fleet -n general -c /srv/fleet" in joined
    assert "tmux set-window-option -t fleet:general automatic-rename off" in joined


def test_reconcile_adds_sorted_and_prunes_stale():
    paths_instances = Path("/srv/fleet/instances")

    class P:  # minimal FleetPaths stand-in
        home = Path("/srv/fleet")
        instances = paths_instances

    fake = FakeRunner(
        default=RunResult(0, []),
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(
                0, ["general", "old--gone", "oak--click-3"]
            ),
            "tmux list-windows -t fleet -F #{window_name}\t#{@fleet_managed}": RunResult(
                0, ["general\t", "old--gone\t1", "oak--click-3\t1"]
            ),
            "tmux list-panes -t fleet:general -F #{@fleet_role}": RunResult(0, ["sidebar"]),
            "tmux list-panes -t fleet:oak--click-3 -F #{@fleet_role}": RunResult(0, ["sidebar"]),
            "tmux list-panes -t fleet:old--gone -F #{@fleet_role}": RunResult(0, ["sidebar"]),
        },
    )
    tmux.reconcile(P, ["oak--click-3"], runner=fake)
    joined = [" ".join(str(c) for c in c2["cmd"]) for c2 in fake.calls]
    # prunes the stale managed window, keeps general (unmanaged, never pruned)
    assert "tmux kill-window -t fleet:old--gone" in joined
    assert "tmux kill-window -t fleet:general" not in joined


def test_reconcile_does_not_kill_unmanaged_window_with_dashes():
    """A human-renamed window containing '--' (e.g. notes--scratch) must
    survive reconcile as long as it was never tagged @fleet_managed."""
    paths_instances = Path("/srv/fleet/instances")

    class P:  # minimal FleetPaths stand-in
        home = Path("/srv/fleet")
        instances = paths_instances

    fake = FakeRunner(
        default=RunResult(0, []),
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(
                0, ["general", "notes--scratch"]
            ),
            "tmux list-windows -t fleet -F #{window_name}\t#{@fleet_managed}": RunResult(
                0, ["general\t", "notes--scratch\t"]
            ),
            "tmux list-panes -t fleet:general -F #{@fleet_role}": RunResult(0, ["sidebar"]),
            "tmux list-panes -t fleet:notes--scratch -F #{@fleet_role}": RunResult(0, ["sidebar"]),
        },
    )
    tmux.reconcile(P, [], runner=fake)
    joined = [" ".join(str(c) for c in c2["cmd"]) for c2 in fake.calls]
    assert "tmux kill-window -t fleet:notes--scratch" not in joined


def test_attach_execs_tmux(monkeypatch):
    called = {}
    monkeypatch.setattr(tmux.os, "execvp", lambda f, a: called.setdefault("a", (f, a)))
    tmux.attach()
    assert called["a"] == ("tmux", ["tmux", "attach", "-t", "fleet"])


# --- Task 2: apply_settings ---------------------------------------------


def test_apply_settings_sets_mouse_clipboard_status_and_reset_bind():
    import sys as _sys

    fake = FakeRunner()
    tmux.apply_settings(runner=fake)
    joined = _joined(fake)
    assert "tmux set-option -t fleet mouse on" in joined
    assert "tmux set-option -t fleet set-clipboard on" in joined
    assert "tmux set-option -t fleet status on" in joined
    # reset binding: prefix R -> run-shell invoking `fleet tmux-reset` (no
    # window arg — the raw window name must never be shell-interpolated
    # into the run-shell string; tmux-reset resolves it itself via
    # current_window()).
    bind = next(c for c in joined if c.startswith("tmux bind-key R "))
    assert "tmux-reset" in bind
    assert _sys.executable in bind


def test_ensure_session_applies_settings():
    fake = FakeRunner(
        # default provides a pane id for ensure_sidebar's unscripted split-window
        # call (the new-session path creates a sidebar before applying settings)
        default=RunResult(0, ["%9"]),
        scripted={"tmux has-session -t fleet": RunResult(1, [])},
    )
    tmux.ensure_session(Path("/srv/fleet"), runner=fake)
    joined = _joined(fake)
    assert any(c == "tmux set-option -t fleet mouse on" for c in joined)


# --- Task 4: reset_window -------------------------------------------------


def test_reset_window_instance_respawns_and_rebuilds_two_bash_panes():
    paths = _FakePaths(home=Path("/srv/fleet"), instances=Path("/srv/fleet/instances"))
    win = "oak--click-3"
    fake = FakeRunner(
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general", win]),
            # window currently has only sidebar + ONE bash pane (a bash pane was deleted)
            f"tmux list-panes -t fleet:{win} -F #{{pane_id}}\t#{{@fleet_role}}": RunResult(
                0, ["%10\t", "%11\tsidebar"]
            ),
            # ensure_sidebar's self-heal check: sidebar already present, so it
            # must not try to create a second one
            f"tmux list-panes -t fleet:{win} -F #{{@fleet_role}}": RunResult(0, ["sidebar"]),
        },
    )
    tmux.reset_window(paths, win, runner=fake)
    joined = _joined(fake)
    # respawns the surviving bash pane at the instance dir
    assert any(
        c.startswith("tmux respawn-pane -k -t %10 -c /srv/fleet/instances/oak--click-3")
        for c in joined
    )
    # adds the second bash pane
    assert any(
        c.startswith("tmux split-window -h -t %10 -c /srv/fleet/instances/oak--click-3")
        for c in joined
    )
    # refreshes the surviving sidebar in place (reruns its tmux-sidebar command)
    assert "tmux respawn-pane -k -t %11" in joined
    # ...and does NOT recreate it via ensure_sidebar's split-window -hbf path
    assert not any("split-window -hbf" in c for c in joined)
    # restores sidebar width
    assert any(c.startswith(f"tmux resize-pane -t %11 -x {tmux.SIDEBAR_WIDTH}") for c in joined)


def test_reset_window_instance_only_sidebar_creates_base_and_second_pane():
    """When list-panes shows ONLY the sidebar (both bash panes gone), reset_window
    must take the `else` branch: split-window to create the base pane, then a
    second split-window for the 2nd bash pane, then resize the sidebar."""
    paths = _FakePaths(home=Path("/srv/fleet"), instances=Path("/srv/fleet/instances"))
    win = "oak--click-3"
    cwd = "/srv/fleet/instances/oak--click-3"
    create_base = f"tmux split-window -h -t fleet:{win} -c {cwd} -P -F #{{pane_id}}"
    fake = FakeRunner(
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general", win]),
            # window currently has ONLY a sidebar pane (both bash panes gone)
            f"tmux list-panes -t fleet:{win} -F #{{pane_id}}\t#{{@fleet_role}}": RunResult(
                0, ["%20\tsidebar"]
            ),
            create_base: RunResult(0, ["%21"]),
            # ensure_sidebar's self-heal check: sidebar already present, so it
            # must not try to create a second one
            f"tmux list-panes -t fleet:{win} -F #{{@fleet_role}}": RunResult(0, ["sidebar"]),
        },
    )
    tmux.reset_window(paths, win, runner=fake)
    joined = _joined(fake)
    assert create_base in joined
    # second bash pane split off the newly created base pane
    assert any(c.startswith(f"tmux split-window -h -t %21 -c {cwd}") for c in joined)
    # refreshes the surviving sidebar in place rather than recreating it
    assert "tmux respawn-pane -k -t %20" in joined
    assert not any("split-window -hbf" in c for c in joined)
    # restores sidebar width against the pre-existing sidebar pane
    assert any(c.startswith(f"tmux resize-pane -t %20 -x {tmux.SIDEBAR_WIDTH}") for c in joined)


def test_reset_window_sidebar_missing_recreates_via_ensure_sidebar():
    """When list-panes shows only bash panes (no sidebar role at all), reset_window
    must take the `else` branch: call ensure_sidebar to recreate it (split-window
    -hbf ... tmux-sidebar), NOT respawn-pane on a nonexistent sidebar."""
    import sys as _sys

    paths = _FakePaths(home=Path("/srv/fleet"), instances=Path("/srv/fleet/instances"))
    win = "oak--click-3"
    cwd = "/srv/fleet/instances/oak--click-3"
    sidebar_split = (
        f"tmux split-window -hbf -l {tmux.SIDEBAR_WIDTH} -t fleet:{win} -d -P -F #{{pane_id}} -- "
        + _sys.executable
        + f" -m fleet.cli tmux-sidebar --window {win}"
    )
    fake = FakeRunner(
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general", win]),
            # window currently has only the one surviving bash pane, no sidebar role
            f"tmux list-panes -t fleet:{win} -F #{{pane_id}}\t#{{@fleet_role}}": RunResult(
                0, ["%10\t"]
            ),
            # ensure_sidebar's has_sidebar check: no sidebar role present -> must create
            f"tmux list-panes -t fleet:{win} -F #{{@fleet_role}}": RunResult(0, [""]),
            sidebar_split: RunResult(0, ["%30"]),
        },
    )
    tmux.reset_window(paths, win, runner=fake)
    joined = _joined(fake)
    # adds the second bash pane off the surviving base
    assert any(c.startswith(f"tmux split-window -h -t %10 -c {cwd}") for c in joined)
    # takes the else branch: recreates the sidebar via ensure_sidebar
    assert sidebar_split in joined
    # never respawns a sidebar pane that doesn't exist
    assert "tmux respawn-pane -k -t %30" not in joined
    assert not any(c.startswith("tmux respawn-pane -k -t %11") for c in joined)


def test_current_window_reads_display_message():
    fake = FakeRunner(
        scripted={
            "tmux display-message -p #{window_name}": RunResult(0, ["oak--click-3"]),
        }
    )
    assert tmux.current_window(runner=fake) == "oak--click-3"


def test_current_window_empty_when_no_output():
    fake = FakeRunner(scripted={"tmux display-message -p #{window_name}": RunResult(0, [])})
    assert tmux.current_window(runner=fake) == ""


def test_reset_window_absent_window_is_noop():
    paths = _FakePaths(home=Path("/srv/fleet"), instances=Path("/srv/fleet/instances"))
    fake = FakeRunner(
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general"]),
        },
    )
    tmux.reset_window(paths, "oak--gone", runner=fake)
    joined = _joined(fake)
    assert not any("respawn-pane" in c for c in joined)


def test_apply_pane_layout_instance_evens_bash_panes():
    win = "oak--dev-1"
    fake = FakeRunner(
        scripted={
            f"tmux list-panes -t fleet:{win} -F #{{pane_id}}\t#{{@fleet_role}}": RunResult(
                0, ["%1\t", "%2\t", "%3\tsidebar"]
            ),
            f"tmux display-message -p -t fleet:{win} #{{window_width}}": RunResult(0, ["100"]),
        }
    )
    tmux.apply_pane_layout(win, runner=fake)
    joined = _joined(fake)
    assert f"tmux resize-pane -t %3 -x {tmux.SIDEBAR_WIDTH}" in joined  # sidebar fixed
    assert "tmux resize-pane -t %1 -x 25" in joined  # (100-50)//2 on the first bash pane


def test_apply_pane_layout_general_only_fixes_sidebar():
    win = "general"
    fake = FakeRunner(
        scripted={
            f"tmux list-panes -t fleet:{win} -F #{{pane_id}}\t#{{@fleet_role}}": RunResult(
                0, ["%1\t", "%2\tsidebar"]
            ),
        }
    )
    tmux.apply_pane_layout(win, runner=fake)
    joined = _joined(fake)
    assert f"tmux resize-pane -t %2 -x {tmux.SIDEBAR_WIDTH}" in joined
    assert not any("window_width" in c for c in joined)  # single bash -> no width query
    assert not any(c.startswith("tmux resize-pane -t %1") for c in joined)  # no bash-even


def test_apply_pane_layout_skips_bash_even_on_bad_width():
    win = "oak--dev-1"
    fake = FakeRunner(
        scripted={
            f"tmux list-panes -t fleet:{win} -F #{{pane_id}}\t#{{@fleet_role}}": RunResult(
                0, ["%1\t", "%2\t", "%3\tsidebar"]
            ),
            f"tmux display-message -p -t fleet:{win} #{{window_width}}": RunResult(0, [""]),
        }
    )
    tmux.apply_pane_layout(win, runner=fake)
    joined = _joined(fake)
    assert f"tmux resize-pane -t %3 -x {tmux.SIDEBAR_WIDTH}" in joined  # sidebar still fixed
    assert not any(c.startswith("tmux resize-pane -t %1") for c in joined)  # no bad bash resize


def test_apply_pane_layout_skips_bash_even_on_narrow_window():
    win = "oak--dev-1"
    fake = FakeRunner(
        scripted={
            f"tmux list-panes -t fleet:{win} -F #{{pane_id}}\t#{{@fleet_role}}": RunResult(
                0, ["%1\t", "%2\t", "%3\tsidebar"]
            ),
            f"tmux display-message -p -t fleet:{win} #{{window_width}}": RunResult(0, ["34"]),
        }
    )
    tmux.apply_pane_layout(win, runner=fake)
    joined = _joined(fake)
    assert f"tmux resize-pane -t %3 -x {tmux.SIDEBAR_WIDTH}" in joined  # sidebar still fixed
    assert not any(c.startswith("tmux resize-pane -t %1") for c in joined)  # no tiny bash resize


# --- Task 2: wire apply_pane_layout into creation + reset ------------------


def test_ensure_instance_window_applies_pane_layout(monkeypatch):
    import sys as _sys

    win = "oak--dev-1"
    dir_ = Path("/srv/fleet/instances/oak--dev-1")
    recorded = []
    monkeypatch.setattr(
        tmux, "apply_pane_layout", lambda window, *, runner=None: recorded.append(window)
    )
    sidebar_split = (
        f"tmux split-window -hbf -l {tmux.SIDEBAR_WIDTH} -t fleet:oak--dev-1 -d -P -F "
        "#{pane_id} -- " + _sys.executable + " -m fleet.cli tmux-sidebar --window oak--dev-1"
    )
    fake = FakeRunner(
        scripted={
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general"]),
            "tmux new-window -t fleet -n oak--dev-1 -c "
            + str(dir_)
            + " -P -F #{pane_id}": RunResult(0, ["%5"]),
            "tmux list-panes -t fleet:oak--dev-1 -F #{@fleet_role}": RunResult(0, ["", ""]),
            sidebar_split: RunResult(0, ["%9"]),
        }
    )
    tmux.ensure_instance_window(win, dir_, runner=fake)
    assert recorded == [win]


def test_reset_window_applies_pane_layout(monkeypatch):
    paths = _FakePaths(home=Path("/srv/fleet"), instances=Path("/srv/fleet/instances"))
    win = "oak--click-3"
    recorded = []
    monkeypatch.setattr(
        tmux, "apply_pane_layout", lambda window, *, runner=None: recorded.append(window)
    )
    fake = FakeRunner(
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general", win]),
            f"tmux list-panes -t fleet:{win} -F #{{pane_id}}\t#{{@fleet_role}}": RunResult(
                0, ["%10\t", "%11\tsidebar"]
            ),
            f"tmux list-panes -t fleet:{win} -F #{{@fleet_role}}": RunResult(0, ["sidebar"]),
        },
    )
    tmux.reset_window(paths, win, runner=fake)
    assert recorded == [win]


def test_reset_window_evens_bash_panes_end_to_end():
    """reset_window's call to the *real* apply_pane_layout (not monkeypatched)
    must resize both the sidebar and the two bash panes when the window
    already has two bash panes + a sidebar."""
    paths = _FakePaths(home=Path("/srv/fleet"), instances=Path("/srv/fleet/instances"))
    win = "oak--click-3"
    fake = FakeRunner(
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general", win]),
            f"tmux list-panes -t fleet:{win} -F #{{pane_id}}\t#{{@fleet_role}}": RunResult(
                0, ["%20\t", "%21\t", "%22\tsidebar"]
            ),
            f"tmux list-panes -t fleet:{win} -F #{{@fleet_role}}": RunResult(0, ["sidebar"]),
            f"tmux display-message -p -t fleet:{win} #{{window_width}}": RunResult(0, ["100"]),
        },
    )
    tmux.reset_window(paths, win, runner=fake)
    joined = _joined(fake)
    assert f"tmux resize-pane -t %22 -x {tmux.SIDEBAR_WIDTH}" in joined  # sidebar fixed
    assert "tmux resize-pane -t %20 -x 25" in joined  # bash-even, (100-50)//2=25


def test_ensure_general_layout_adds_second_bash_pane():
    home = Path("/srv/fleet")
    role_fmt = "#{pane_id}\t#{" + tmux.SIDEBAR_ROLE_OPT + "}"
    fake = FakeRunner(
        scripted={
            # sidebar already present -> ensure_sidebar no-ops
            f"tmux list-panes -t fleet:general -F #{{{tmux.SIDEBAR_ROLE_OPT}}}": RunResult(
                0, [tmux.SIDEBAR_ROLE, ""]
            ),
            # one sidebar pane + one bash pane -> needs a second bash pane
            f"tmux list-panes -t fleet:general -F {role_fmt}": RunResult(
                0, ["%1\t" + tmux.SIDEBAR_ROLE, "%2\t"]
            ),
        }
    )
    tmux.ensure_general_layout(home, runner=fake)
    joined = _joined(fake)
    assert "tmux split-window -h -t %2 -c " + str(home) in joined


def test_ensure_general_layout_no_split_when_two_bash_panes():
    home = Path("/srv/fleet")
    role_fmt = "#{pane_id}\t#{" + tmux.SIDEBAR_ROLE_OPT + "}"
    fake = FakeRunner(
        scripted={
            f"tmux list-panes -t fleet:general -F #{{{tmux.SIDEBAR_ROLE_OPT}}}": RunResult(
                0, [tmux.SIDEBAR_ROLE, "", ""]
            ),
            f"tmux list-panes -t fleet:general -F {role_fmt}": RunResult(
                0, ["%1\t" + tmux.SIDEBAR_ROLE, "%2\t", "%3\t"]
            ),
        }
    )
    tmux.ensure_general_layout(home, runner=fake)
    joined = _joined(fake)
    assert not any(c.startswith("tmux split-window -h -t %") for c in joined)


# --- Step 1b: tty1/tty2 pane commands -----------------------------------

BASH_PANE_FMT = "#{pane_id}\t#{pane_left}\t#{@fleet_role}"


def _bash_pane_list_key(window: str) -> str:
    return f"tmux list-panes -t fleet:{window} -F {BASH_PANE_FMT}"


def test_send_pane_command_emits_literal_and_enter_argv():
    fake = FakeRunner()
    tmux.send_pane_command("%5", "echo hi", runner=fake)
    assert [c["cmd"] for c in fake.calls] == [
        ["tmux", "send-keys", "-t", "%5", "-l", "--", "echo hi"],
        ["tmux", "send-keys", "-t", "%5", "Enter"],
    ]


def test_send_pane_command_passes_key_name_looking_text_verbatim():
    """`-l` must make tmux treat the whole command as literal text, not key
    names — otherwise C-c/Space/Escape-looking substrings would be parsed as
    keystrokes instead of typed characters."""
    fake = FakeRunner()
    cmd = 'ddev exec claude "/jira pull OAKS-1781" C-c Space'
    tmux.send_pane_command("%5", cmd, runner=fake)
    assert fake.calls[0]["cmd"] == ["tmux", "send-keys", "-t", "%5", "-l", "--", cmd]
    assert fake.calls[1]["cmd"] == ["tmux", "send-keys", "-t", "%5", "Enter"]


def test_bash_pane_ids_orders_by_pane_left_not_listing_order():
    win = "oak--click-3"
    fake = FakeRunner(
        scripted={
            _bash_pane_list_key(win): RunResult(
                0,
                [
                    "%7\t60\t",  # right bash pane, listed first
                    "%1\t0\tsidebar",  # sidebar, must be excluded regardless of pane_left
                    "%5\t10\t",  # middle bash pane
                ],
            ),
        }
    )
    assert tmux.bash_pane_ids(win, runner=fake) == ["%5", "%7"]


def test_bash_pane_ids_unparseable_pane_left_sorts_last_not_raises():
    win = "oak--click-3"
    fake = FakeRunner(
        scripted={
            _bash_pane_list_key(win): RunResult(
                0,
                [
                    "%9\t??\t",  # unparseable pane_left -> must sort last, not raise
                    "%5\t10\t",
                ],
            ),
        }
    )
    assert tmux.bash_pane_ids(win, runner=fake) == ["%5", "%9"]


def test_send_tty_commands_sends_tty1_and_tty2_to_ordered_panes():
    win = "oak--click-3"
    fake = FakeRunner(
        scripted={
            _bash_pane_list_key(win): RunResult(0, ["%9\t-50\tsidebar", "%6\t50\t", "%5\t0\t"]),
        }
    )
    tmux.send_tty_commands(win, ["a1", "a2"], ["b1"], runner=fake)
    joined = _joined(fake)
    assert "tmux send-keys -t %5 -l -- a1" in joined
    assert "tmux send-keys -t %5 -l -- a2" in joined
    assert "tmux send-keys -t %6 -l -- b1" in joined
    # order preserved: a1 sent (and Enter'd) before a2
    a1_idx = joined.index("tmux send-keys -t %5 -l -- a1")
    a2_idx = joined.index("tmux send-keys -t %5 -l -- a2")
    assert a1_idx < a2_idx


def test_send_tty_commands_skips_tty2_when_only_one_bash_pane():
    win = "oak--click-3"
    fake = FakeRunner(
        scripted={
            _bash_pane_list_key(win): RunResult(0, ["%5\t0\t", "%9\t-50\tsidebar"]),
        }
    )
    tmux.send_tty_commands(win, ["a"], ["b"], runner=fake)
    joined = _joined(fake)
    assert "tmux send-keys -t %5 -l -- a" in joined
    assert not any("-- b" in c for c in joined)


def test_ensure_instance_window_with_tty_sends_to_leftmost_bash_panes(monkeypatch):
    import sys as _sys

    win = "oak--click-3"
    dir_ = Path("/srv/fleet/instances/oak--click-3")
    monkeypatch.setattr(tmux, "apply_pane_layout", lambda window, *, runner=None: None)
    sidebar_split = (
        f"tmux split-window -hbf -l {tmux.SIDEBAR_WIDTH} -t fleet:{win} -d -P -F "
        "#{pane_id} -- " + _sys.executable + f" -m fleet.cli tmux-sidebar --window {win}"
    )
    fake = FakeRunner(
        scripted={
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general"]),
            f"tmux new-window -t fleet -n {win} -c {dir_} -P -F #{{pane_id}}": RunResult(0, ["%5"]),
            f"tmux list-panes -t fleet:{win} -F #{{@fleet_role}}": RunResult(0, ["", ""]),
            sidebar_split: RunResult(0, ["%9"]),
            _bash_pane_list_key(win): RunResult(0, ["%5\t0\t", "%6\t50\t", "%9\t-50\tsidebar"]),
        },
    )
    tmux.ensure_instance_window(win, dir_, tty=(["a"], ["b"]), runner=fake)
    joined = _joined(fake)
    assert "tmux send-keys -t %5 -l -- a" in joined
    assert "tmux send-keys -t %5 Enter" in joined
    assert "tmux send-keys -t %6 -l -- b" in joined
    assert "tmux send-keys -t %6 Enter" in joined


def test_ensure_instance_window_existing_with_tty_sends_nothing():
    fake = FakeRunner(
        scripted={
            "tmux list-windows -t fleet -F #{window_name}": RunResult(
                0, ["general", "oak--click-3"]
            ),
        },
    )
    tmux.ensure_instance_window("oak--click-3", Path("/x"), tty=(["a"], ["b"]), runner=fake)
    assert _joined(fake) == ["tmux list-windows -t fleet -F #{window_name}"]


def test_ensure_instance_window_tty_none_sends_nothing():
    import sys as _sys

    win = "oak--click-3"
    dir_ = Path("/srv/fleet/instances/oak--click-3")
    sidebar_split = (
        f"tmux split-window -hbf -l {tmux.SIDEBAR_WIDTH} -t fleet:{win} -d -P -F "
        "#{pane_id} -- " + _sys.executable + f" -m fleet.cli tmux-sidebar --window {win}"
    )
    fake = FakeRunner(
        scripted={
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general"]),
            f"tmux new-window -t fleet -n {win} -c {dir_} -P -F #{{pane_id}}": RunResult(0, ["%5"]),
            f"tmux list-panes -t fleet:{win} -F #{{@fleet_role}}": RunResult(0, ["", ""]),
            sidebar_split: RunResult(0, ["%9"]),
        },
    )
    tmux.ensure_instance_window(win, dir_, runner=fake)
    assert not any(c.startswith("tmux send-keys") for c in _joined(fake))


class _P:  # minimal FleetPaths stand-in
    home = Path("/srv/fleet")
    instances = Path("/srv/fleet/instances")


def _new_window_fake(win: str, *, session: bool = True, bash_panes: bool = True) -> FakeRunner:
    """A FakeRunner for creating ONE new instance window `win`. When
    `bash_panes`, the bash-pane listing is scripted so a (buggy) tty typing
    would actually emit send-keys — letting tests assert that none happen."""
    import sys as _sys

    dir_ = _P.instances / win
    sidebar_split = (
        f"tmux split-window -hbf -l {tmux.SIDEBAR_WIDTH} -t fleet:{win} -d -P -F "
        "#{pane_id} -- " + _sys.executable + f" -m fleet.cli tmux-sidebar --window {win}"
    )
    scripted = {
        "tmux has-session -t fleet": RunResult(0 if session else 1, []),
        "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general"]),
        "tmux list-windows -t fleet -F #{window_name}\t#{@fleet_managed}": RunResult(
            0, ["general\t"]
        ),
        "tmux list-panes -t fleet:general -F #{@fleet_role}": RunResult(0, ["sidebar"]),
        f"tmux new-window -t fleet -n {win} -c {dir_} -P -F #{{pane_id}}": RunResult(0, ["%5"]),
        f"tmux list-panes -t fleet:{win} -F #{{@fleet_role}}": RunResult(0, ["", ""]),
        sidebar_split: RunResult(0, ["%9"]),
    }
    if bash_panes:
        scripted[_bash_pane_list_key(win)] = RunResult(
            0, ["%5\t0\t", "%6\t50\t", "%9\t-50\tsidebar"]
        )
    return FakeRunner(default=RunResult(0, []), scripted=scripted)


def test_reconcile_creates_new_windows_as_plain_shells_never_typing(monkeypatch):
    """FLE-6: tty commands are a deploy action. reconcile (== `fleet tmux`,
    `fleet tmux --ensure`, the boot reconcile) creates the window with
    sidebar + 2 bash panes and types NOTHING."""
    monkeypatch.setattr(tmux, "apply_pane_layout", lambda window, *, runner=None: None)
    win = "new--b"
    fake = _new_window_fake(win)

    tmux.reconcile(_P, [win], runner=fake)

    joined = _joined(fake)
    assert any(c.startswith(f"tmux new-window -t fleet -n {win}") for c in joined)
    assert not any(c.startswith("tmux send-keys") for c in joined)


def test_reconcile_no_longer_accepts_a_tty_resolver():
    with pytest.raises(TypeError):
        tmux.reconcile(_P, [], tty_for=lambda instance_id: (["x"], []), runner=FakeRunner())


def test_reconcile_passes_no_tty_to_ensure_instance_window(monkeypatch):
    fake = FakeRunner(
        default=RunResult(0, []),
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general"]),
            "tmux list-windows -t fleet -F #{window_name}\t#{@fleet_managed}": RunResult(
                0, ["general\t"]
            ),
            "tmux list-panes -t fleet:general -F #{@fleet_role}": RunResult(0, ["sidebar"]),
        },
    )
    calls = []
    monkeypatch.setattr(
        tmux,
        "ensure_instance_window",
        lambda instance_id, instance_dir, **kw: calls.append((instance_id, kw)),
    )
    tmux.reconcile(_P, ["new--b"], runner=fake)
    assert calls == [("new--b", {"runner": fake})]


def test_ensure_instance_window_returns_true_when_created_false_when_present(monkeypatch):
    monkeypatch.setattr(tmux, "apply_pane_layout", lambda window, *, runner=None: None)
    win = "oak--click-3"
    fake = _new_window_fake(win)
    assert tmux.ensure_instance_window(win, _P.instances / win, runner=fake) is True

    present = FakeRunner(
        scripted={"tmux list-windows -t fleet -F #{window_name}": RunResult(0, ["general", win])}
    )
    assert tmux.ensure_instance_window(win, _P.instances / win, runner=present) is False


def test_ensure_session_creates_when_missing_and_only_heals_when_present():
    missing = FakeRunner(
        default=RunResult(0, ["%9"]),
        scripted={"tmux has-session -t fleet": RunResult(1, [])},
    )
    tmux.ensure_session(Path("/srv/fleet"), runner=missing)
    assert "tmux new-session -d -s fleet -n general -c /srv/fleet" in _joined(missing)

    present = FakeRunner(default=RunResult(0, ["%9"]))
    tmux.ensure_session(Path("/srv/fleet"), runner=present)
    assert not any(c.startswith("tmux new-session") for c in _joined(present))


def test_reset_window_general_rebuilds_two_bash_panes():
    """After the layout change, resetting the general window rebuilds it to TWO
    bash panes + sidebar (previously one), and reruns the sidebar in place so it
    picks up the current code (e.g. the branch/commit line)."""
    paths = _FakePaths(home=Path("/srv/fleet"), instances=Path("/srv/fleet/instances"))
    win = "general"
    fake = FakeRunner(
        scripted={
            "tmux has-session -t fleet": RunResult(0, []),
            "tmux list-windows -t fleet -F #{window_name}": RunResult(
                0, ["general", "oak--click-3"]
            ),
            f"tmux list-panes -t fleet:{win} -F #{{pane_id}}\t#{{@fleet_role}}": RunResult(
                0, ["%10\t", "%11\tsidebar"]
            ),
            f"tmux list-panes -t fleet:{win} -F #{{@fleet_role}}": RunResult(0, ["sidebar"]),
        },
    )
    tmux.reset_window(paths, win, runner=fake)
    joined = _joined(fake)
    assert any(c.startswith("tmux respawn-pane -k -t %10 -c /srv/fleet") for c in joined)
    # general now ALSO gets a second bash pane
    assert any(c.startswith("tmux split-window -h -t %10 -c /srv/fleet") for c in joined)
    # sidebar rerun in place (so the commit display refreshes)
    assert "tmux respawn-pane -k -t %11" in joined
