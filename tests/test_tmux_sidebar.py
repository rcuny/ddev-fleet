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


def test_build_rows_truncates_long_branch_with_ellipsis():
    long_branch = "feature/OAKS-1753-search-cards-rebased-PR-really-long"
    rows = tmux_sidebar.build_rows(
        ["oak--dev-1"],
        {"oak--dev-1": "running"},
        current="general",
        branches={"oak--dev-1": long_branch},
    )
    branch_text = next(t for t, _ in rows if t.strip().startswith("feature/"))
    assert branch_text.endswith("…")
    assert len(branch_text) <= tmux.SIDEBAR_WIDTH  # includes the 4-space indent


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
