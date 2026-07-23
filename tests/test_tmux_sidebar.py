from fleet import tmux_sidebar
from fleet.core import tmux


def test_run_exits_cleanly_when_session_exists_check_raises(monkeypatch):
    """session_exists() can raise FleetError (tmux binary/socket gone) — the
    liveness check must not let that escape and crash the while-True loop."""

    class DummyPaths:
        instances = None

    def _boom():
        raise RuntimeError("tmux gone")

    monkeypatch.setattr(tmux_sidebar.tmux, "session_exists", _boom)
    monkeypatch.setattr(tmux_sidebar, "_render", lambda *a, **k: None)
    monkeypatch.setattr(tmux_sidebar.shell, "list_instance_ids", lambda paths: [])
    monkeypatch.setattr(tmux_sidebar.ddev, "list_projects", lambda: [])

    tmux_sidebar.run(DummyPaths(), "general", once=False, list_interval=0)
    # If we get here without raising or hanging, the guard worked.


def test_build_rows_lists_general_then_instances_sorted():
    rows = tmux_sidebar.build_rows(
        ["oak--click-3", "democ--main"],
        {"oak--click-3": "running", "democ--main": "stopped"},
        current="oak--click-3",
    )
    labels = [text for text, _style in rows]
    assert labels[0].endswith("general")
    # instances sorted, glyphs applied
    assert any("democ--main" in t for t in labels)
    assert any("oak--click-3" in t for t in labels)


def test_build_rows_marks_current_row_style():
    rows = tmux_sidebar.build_rows(
        ["oak--click-3"], {"oak--click-3": "running"}, current="oak--click-3"
    )
    styles = {text: style for text, style in rows}
    current_row = next(t for t in styles if "oak--click-3" in t)
    assert styles[current_row] == "current"


def test_build_rows_status_glyphs():
    rows = tmux_sidebar.build_rows(["a--b"], {"a--b": "running"}, current="general")
    assert any(tmux_sidebar.STATUS_GLYPH["running"] in t for t, _ in rows)


def test_key_hints_cover_switch_and_detach():
    joined = " ".join(tmux_sidebar.KEY_HINTS).lower()
    assert "switch tab" in joined
    assert "detach" in joined
    assert all("^b" in h for h in tmux_sidebar.KEY_HINTS)


def test_build_rows_adds_dim_branch_line_under_instance():
    rows = tmux_sidebar.build_rows(
        ["oak--click-3"],
        {"oak--click-3": "running"},
        current="oak--click-3",
        branches={"oak--click-3": "feature/OAKS-1762"},
    )
    labels = [text for text, _ in rows]
    assert any(t.strip() == "feature/OAKS-1762" for t in labels)
    # the branch row is indented and dim
    branch_row = next((t, s) for t, s in rows if "feature/OAKS-1762" in t)
    assert branch_row[0].startswith("    ")
    assert branch_row[1] == "grey50"


def test_build_rows_wraps_long_branch_in_full():
    long_branch = "feature/OAKS-1753-search-cards-rebased-PR-really-long"
    rows = tmux_sidebar.build_rows(
        ["oak--dev-1"],
        {"oak--dev-1": "running"},
        current="general",
        branches={"oak--dev-1": long_branch},
    )
    branch_rows = [t for t, style in rows if style == "grey50"]
    # Full name preserved across wrapped lines, nothing cut:
    assert "".join(t.strip() for t in branch_rows) == long_branch
    assert "…" not in "".join(branch_rows)
    # Each wrapped line fits inside the sidebar (4-space indent + chunk):
    assert all(len(t) <= tmux.SIDEBAR_WIDTH for t in branch_rows)
    # A long branch must wrap onto more than one line:
    assert len(branch_rows) >= 2


def test_build_rows_short_branch_single_row():
    rows = tmux_sidebar.build_rows(
        ["oak--dev-1"],
        {"oak--dev-1": "running"},
        current="general",
        branches={"oak--dev-1": "main"},
    )
    branch_rows = [t for t, style in rows if style == "grey50"]
    assert branch_rows == ["    main"]


def test_build_rows_no_branch_line_when_unknown():
    rows = tmux_sidebar.build_rows(
        ["oak--dev-1"],
        {"oak--dev-1": "running"},
        current="general",
        branches={"oak--dev-1": ""},
    )
    assert all("feature/" not in t for t, _ in rows)
    # exactly one row for the instance (plus the general row)
    assert sum(1 for t, _ in rows if "oak--dev-1" in t) == 1


class _DummyPaths:
    def __init__(self, instances):
        self.instances = instances


def test_run_uses_live_branch_on_first_render(monkeypatch, tmp_path):
    seen = {}
    monkeypatch.setattr(tmux_sidebar, "read_instance_git_branch", lambda d: "feature/live")
    monkeypatch.setattr(tmux_sidebar.shell, "list_instance_ids", lambda paths: ["oak--dev-1"])
    monkeypatch.setattr(tmux_sidebar, "_statuses", lambda: {"oak--dev-1": "running"})
    monkeypatch.setattr(
        tmux_sidebar,
        "_render",
        lambda console, window, ids, statuses, branches: seen.update(branches),
    )

    tmux_sidebar.run(_DummyPaths(tmp_path), "general", once=True)
    assert seen == {"oak--dev-1": "feature/live"}


def test_run_branch_tick_is_independent_of_status_tick(monkeypatch, tmp_path):
    branch_calls = {"n": 0}
    status_calls = {"n": 0}

    def fake_branch(d):
        branch_calls["n"] += 1
        return "b"

    def fake_statuses():
        status_calls["n"] += 1
        return {}

    monkeypatch.setattr(tmux_sidebar, "read_instance_git_branch", fake_branch)
    monkeypatch.setattr(tmux_sidebar, "_statuses", fake_statuses)
    monkeypatch.setattr(tmux_sidebar.shell, "list_instance_ids", lambda paths: ["oak--dev-1"])
    monkeypatch.setattr(tmux_sidebar, "_render", lambda *a, **k: None)
    monkeypatch.setattr(tmux_sidebar.time, "sleep", lambda *_: None)

    # monotonic() is called once before the loop, then once per iteration:
    clock = iter([0, 5, 15, 400])
    monkeypatch.setattr(tmux_sidebar.time, "monotonic", lambda: next(clock))
    # session alive for two iterations, then gone -> loop exits on the 3rd:
    alive = iter([True, True, False])
    monkeypatch.setattr(tmux_sidebar.tmux, "session_exists", lambda: next(alive))

    tmux_sidebar.run(
        _DummyPaths(tmp_path),
        "general",
        list_interval=0,
        status_interval=10,
        branch_interval=300,
    )

    # branches: once up front (t=0) + once when 300s elapsed (t=400) = 2
    assert branch_calls["n"] == 2
    # statuses initial + refresh at t=15 and t=400 -> re-read more often than branches
    assert status_calls["n"] >= 3
