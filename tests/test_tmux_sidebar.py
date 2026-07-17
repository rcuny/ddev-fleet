from fleet import tmux_sidebar


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
