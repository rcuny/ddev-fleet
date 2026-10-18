"""WebhookStore.recent / recent_with_options: the merged reader behind the Webhooks page."""

import json

import pytest

from fleet.core import webhooks as webhooks_mod
from fleet.core.webhooks import (
    DEFAULT_RECENT_LIMIT,
    MAX_RECENT_LIMIT,
    RESULTS,
    WebhookStore,
    clip_limit,
)


@pytest.fixture
def store(tmp_path):
    return WebhookStore(tmp_path / "webhooks", tmp_path / "logs" / "webhooks" / "jira.jsonl")


def _jira(store, ts, **fields):
    store.append_log({"ts": ts, "project": "p", "result": "accepted", **fields})


def _bb(store, ts, **fields):
    store.append_log(
        {"ts": ts, "project": "p", "result": "triggered", **fields}, webhooks_mod.SOURCE_BITBUCKET
    )


def test_merges_both_sources_newest_first_and_tags_source(store):
    _jira(store, "2026-10-18T10:00:00+00:00", n=1)
    _bb(store, "2026-10-18T11:00:00+00:00", n=2)
    _jira(store, "2026-10-18T12:00:00+00:00", n=3)
    _bb(store, "2026-10-18T09:00:00+00:00", n=4)
    entries = store.recent()
    assert [(e["n"], e["source"]) for e in entries] == [
        (3, "jira"),
        (2, "bitbucket"),
        (1, "jira"),
        (4, "bitbucket"),
    ]


def test_order_compares_instants_not_strings(store):
    _jira(store, "2026-10-18T12:00:00+02:00", n="earlier")  # 10:00Z
    _bb(store, "2026-10-18T11:00:00+00:00", n="later")
    assert [e["n"] for e in store.recent()] == ["later", "earlier"]


def test_same_timestamp_later_line_comes_first(store):
    for n in range(3):
        _jira(store, "2026-10-18T10:00:00+00:00", n=n)
    assert [e["n"] for e in store.recent()] == [2, 1, 0]


def test_missing_and_unparseable_timestamps_sort_last(store):
    _jira(store, "2026-10-18T10:00:00+00:00", n="ok")
    _jira(store, "not a date", n="junk")
    _jira(store, 12345, n="int")
    store.append_log({"n": "naive-ts", "ts": "2026-10-19T00:00:00"})  # no tz: taken as UTC
    got = [e["n"] for e in store.recent()]
    assert got[:2] == ["naive-ts", "ok"]
    assert set(got[2:]) == {"junk", "int"}


def test_missing_files_give_no_entries(store):
    assert store.recent() == []
    entries, options = store.recent_with_options()
    assert entries == []
    assert options["sources"] == ["jira", "bitbucket"]
    assert options["projects"] == []
    assert options["results"] == list(RESULTS)


def test_empty_files_give_no_entries(store):
    store.log_path.parent.mkdir(parents=True)
    store.log_path.write_text("")
    store.bitbucket_log_path.write_text("\n\n")
    assert store.recent() == []


def test_torn_and_non_json_lines_are_skipped(store):
    _jira(store, "2026-10-18T10:00:00+00:00", n=1)
    with store.log_path.open("ab") as fh:
        fh.write(b"not json\n[1, 2]\n\n\xff\xfe broken utf8\n")
        fh.write(b'{"ts": "2026-10-18T10:30:00+00:00", "n": 2, "pro')  # torn last write
    _bb(store, "2026-10-18T11:00:00+00:00", n=3)
    assert [e["n"] for e in store.recent()] == [3, 1]


def test_deeply_nested_line_is_skipped(store):
    _jira(store, "2026-10-18T10:00:00+00:00", n=1)
    with store.log_path.open("a", encoding="utf-8") as fh:
        fh.write("[" * 100_000 + "\n")
    assert [e["n"] for e in store.recent()] == [1]


def test_old_entries_without_new_fields_are_returned_as_logged(store):
    store.log_path.parent.mkdir(parents=True)
    store.log_path.write_text('{"ts": "2026-01-01T00:00:00+00:00", "project": "p"}\n')
    (entry,) = store.recent()
    assert entry == {"ts": "2026-01-01T00:00:00+00:00", "project": "p", "source": "jira"}


def test_filters_source_project_result(store):
    _jira(store, "2026-10-18T10:00:00+00:00", n=1, project="a", result="accepted")
    _jira(store, "2026-10-18T10:01:00+00:00", n=2, project="b", result="ignored")
    _bb(store, "2026-10-18T10:02:00+00:00", n=3, project="a", result="ignored")
    ids = lambda **kw: [e["n"] for e in store.recent(**kw)]  # noqa: E731
    assert ids(source="jira") == [2, 1]
    assert ids(source="bitbucket") == [3]
    assert ids(project="a") == [3, 1]
    assert ids(result="ignored") == [3, 2]
    assert ids(source="jira", project="b", result="ignored") == [2]
    assert ids(source="nope") == []
    assert ids(project="ghost") == []
    assert ids(result="deployed") == []


def test_limit_truncates_after_filtering(store):
    for n in range(10):
        _jira(store, f"2026-10-18T10:00:{n:02d}+00:00", n=n, result="ignored" if n % 2 else "error")
    assert [e["n"] for e in store.recent(limit=3)] == [9, 8, 7]
    assert [e["n"] for e in store.recent(limit=3, result="error")] == [8, 6, 4]
    assert store.recent(limit=0) == []


def test_hide_ignored_excludes_ignored_before_the_limit(store):
    for n in range(10):
        _jira(
            store,
            f"2026-10-18T10:00:{n:02d}+00:00",
            n=n,
            result="ignored" if n >= 6 else "accepted",
        )
    ids = lambda **kw: [e["n"] for e in store.recent(**kw)]  # noqa: E731
    # Default keeps ignored entries.
    assert ids(limit=3) == [9, 8, 7]
    # Hidden: the limit counts only the non-ignored rows (not 3 of the newest 3 minus ignored).
    assert ids(limit=3, hide_ignored=True) == [5, 4, 3]
    assert ids(limit=100, hide_ignored=True) == [5, 4, 3, 2, 1, 0]
    entries, _ = store.recent_with_options(limit=3, hide_ignored=True)
    assert [e["n"] for e in entries] == [5, 4, 3]
    # Explicit result=ignored together with hide gives nothing.
    assert ids(result="ignored", hide_ignored=True) == []
    assert ids(result="ignored") == [9, 8, 7, 6]


def test_window_is_bounded_but_covers_a_generous_multiple_of_limit(store):
    total = webhooks_mod._RECENT_WINDOW_MIN + 50
    store.log_path.parent.mkdir(parents=True)
    with store.log_path.open("a", encoding="utf-8") as fh:
        for n in range(total):
            fh.write(json.dumps({"ts": "2026-10-18T10:00:00+00:00", "n": n}) + "\n")
    assert store.recent(limit=5)[0]["n"] == total - 1
    all_rows = store.recent(limit=MAX_RECENT_LIMIT)
    assert len(all_rows) == MAX_RECENT_LIMIT
    window = store._window(1)
    assert len(window) == webhooks_mod._RECENT_WINDOW_MIN
    assert window[-1]["n"] == total - webhooks_mod._RECENT_WINDOW_MIN


@pytest.mark.parametrize("block", [1, 2, 3, 5, 7, 64])
def test_tail_reader_keeps_raw_multibyte_lines_across_block_edges(store, monkeypatch, block):
    """Raw UTF-8 (not json's \\u escapes) with 2-, 3- and 4-byte characters, read
    with tiny blocks so block edges fall inside the multibyte sequences."""
    monkeypatch.setattr(webhooks_mod, "_TAIL_BLOCK", block)
    store.log_path.parent.mkdir(parents=True)
    with store.log_path.open("wb") as fh:
        for n in range(12):
            line = {"ts": "2026-10-18T10:00:00+00:00", "n": n, "reason": "é€😀" * (n + 1)}
            fh.write(json.dumps(line, ensure_ascii=False).encode("utf-8") + b"\n")
    raw = store.log_path.read_bytes()
    assert len(raw) > len(raw.decode("ascii", errors="ignore"))  # really non-ASCII on disk
    for limit in (1, 3, 11, 12, 20):
        got = store.recent(limit=limit)
        assert [e["n"] for e in got] == list(range(11, max(11 - limit, -1), -1))
        assert all(e["reason"] == "é€😀" * (e["n"] + 1) for e in got)
    lines = webhooks_mod._tail_lines(store.log_path, 4)
    assert [json.loads(line)["n"] for line in lines] == [8, 9, 10, 11]


def test_tail_reader_block_edge_exactly_on_a_newline(tmp_path, monkeypatch):
    path = tmp_path / "x.jsonl"
    path.write_bytes(b'{"n": 1}\n{"n": 2}\n')  # 9 bytes per line
    monkeypatch.setattr(webhooks_mod, "_TAIL_BLOCK", 9)
    assert webhooks_mod._tail_lines(path, 1) == [b'{"n": 2}']
    assert webhooks_mod._tail_lines(path, 2) == [b'{"n": 1}', b'{"n": 2}']
    assert webhooks_mod._tail_lines(path, 5) == [b'{"n": 1}', b'{"n": 2}']


def test_tail_lines_drops_the_cut_first_line(tmp_path):
    path = tmp_path / "x.jsonl"
    path.write_bytes(b'{"n": 0}\n{"n": 1}\n{"n": 2}')  # no trailing newline
    assert webhooks_mod._tail_lines(path, 2) == [b'{"n": 1}', b'{"n": 2}']
    assert webhooks_mod._tail_lines(path, 10) == [b'{"n": 0}', b'{"n": 1}', b'{"n": 2}']
    assert webhooks_mod._tail_lines(tmp_path / "missing", 3) == []


def test_options_come_from_the_unfiltered_window(store):
    _jira(store, "2026-10-18T10:00:00+00:00", project="zeta", result="accepted")
    _bb(store, "2026-10-18T10:01:00+00:00", project="alpha", result="weird-new-result")
    _jira(store, "2026-10-18T10:02:00+00:00", project=["unhashable"], result=None)
    entries, options = store.recent_with_options(source="jira", project="zeta")
    assert [e["project"] for e in entries] == ["zeta"]
    assert options["projects"] == ["alpha", "zeta"]
    assert options["results"] == [*RESULTS, "weird-new-result"]
    assert options["sources"] == ["jira", "bitbucket"]
    assert store.recent_with_options(source="nope")[0] == []


def test_tail_log_is_unchanged(store):
    _jira(store, "2026-10-18T10:00:00+00:00", n=1)
    _bb(store, "2026-10-18T10:00:00+00:00", n=2)
    assert [e["n"] for e in store.tail_log()] == [1]
    assert "source" not in store.tail_log()[0]
    assert [e["n"] for e in store.tail_log(source="bitbucket")] == [2]


@pytest.mark.parametrize(
    ("value", "expected"),
    [
        ("", DEFAULT_RECENT_LIMIT),
        ("abc", DEFAULT_RECENT_LIMIT),
        (None, DEFAULT_RECENT_LIMIT),
        ("12.5", DEFAULT_RECENT_LIMIT),
        ("0", 1),
        ("-7", 1),
        ("25", 25),
        (" 40 ", 40),
        ("500", 500),
        ("501", 500),
        ("99999999999999999999", 500),
    ],
)
def test_clip_limit(value, expected):
    assert clip_limit(value) == expected
