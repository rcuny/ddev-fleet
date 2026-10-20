"""`tmux.reconcile` must not create (or prune) windows for instances whose
per-instance lock is held (deploy in progress) — hotfix v0.20.1."""

from fleet.core import tmux
from fleet.core.instances import FleetPaths
from fleet.core.locks import instance_lock
from fleet.core.runner import RunResult


class _TmuxFake:
    """Minimal stateful tmux: has-session ok, list-windows reports `windows`."""

    def __init__(self, windows=()):
        self.windows = list(windows)  # managed windows
        self.calls = []

    def __call__(self, cmd, **kw):
        self.calls.append([str(c) for c in cmd])
        sub = cmd[1]
        if sub == "list-windows":
            fmt = cmd[-1]
            if "\t" in fmt:
                return RunResult(0, [f"{w}\t1" for w in self.windows])
            return RunResult(0, ["general", *self.windows])
        return RunResult(0, ["%1"])

    def new_windows(self):
        return [c for c in self.calls if c[1] == "new-window"]

    def killed(self):
        return [c for c in self.calls if c[1] == "kill-window"]


def _paths(tmp_path):
    return FleetPaths.from_home(tmp_path)


def test_locked_instance_gets_no_window_others_do(tmp_path):
    paths = _paths(tmp_path)
    fake = _TmuxFake()
    with instance_lock(paths.locks, "a--deploying"):
        tmux.reconcile(paths, {"a--deploying", "b--idle"}, runner=fake)
    created = " ".join(" ".join(c) for c in fake.new_windows())
    assert "b--idle" in created
    assert "a--deploying" not in created


def test_locked_instance_existing_window_not_pruned(tmp_path):
    paths = _paths(tmp_path)
    fake = _TmuxFake(windows=["a--deploying"])
    with instance_lock(paths.locks, "a--deploying"):
        tmux.reconcile(paths, {"a--deploying"}, runner=fake)
    assert fake.killed() == []
    assert fake.new_windows() == []


def test_unlocked_behaviour_unchanged(tmp_path):
    paths = _paths(tmp_path)
    fake = _TmuxFake(windows=["stale--x"])
    with instance_lock(paths.locks, "a--done"):
        pass  # lock file exists but is released
    tmux.reconcile(paths, {"a--done"}, runner=fake)
    assert any("a--done" in " ".join(c) for c in fake.new_windows())
    assert any("stale--x" in " ".join(c) for c in fake.killed())
