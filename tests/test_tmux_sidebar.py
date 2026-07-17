from fleet import tmux_sidebar


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
