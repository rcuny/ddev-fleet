from pathlib import Path

import pytest

from fleet.core import tmux
from fleet.core.errors import FleetError
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def _joined(fake):
    return [" ".join(str(c) for c in call["cmd"]) for call in fake.calls]


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
        "tmux split-window -hbf -l 24 -t fleet:oak--click-3 -d -P -F #{pane_id} -- "
        + _sys.executable
        + " -m fleet.cli tmux-sidebar --window oak--click-3"
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
        "tmux split-window -hbf -l 24 -t fleet:general -d -P -F #{pane_id} -- "
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
        "tmux split-window -hbf -l 24 -t fleet:general -d -P -F #{pane_id} -- "
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
            "tmux list-panes -t fleet:general -F #{@fleet_role}": RunResult(0, ["sidebar"]),
            "tmux list-panes -t fleet:oak--click-3 -F #{@fleet_role}": RunResult(0, ["sidebar"]),
            "tmux list-panes -t fleet:old--gone -F #{@fleet_role}": RunResult(0, ["sidebar"]),
        },
    )
    tmux.reconcile(P, ["oak--click-3"], runner=fake)
    joined = [" ".join(str(c) for c in c2["cmd"]) for c2 in fake.calls]
    # prunes the stale "--" window, keeps general
    assert "tmux kill-window -t fleet:old--gone" in joined
    assert "tmux kill-window -t fleet:general" not in joined


def test_attach_execs_tmux(monkeypatch):
    called = {}
    monkeypatch.setattr(tmux.os, "execvp", lambda f, a: called.setdefault("a", (f, a)))
    tmux.attach()
    assert called["a"] == ("tmux", ["tmux", "attach", "-t", "fleet"])
