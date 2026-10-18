"""Webhooks screen (FLE-30): GET /webhooks, the /ui/webhooks/table partial, filters, escaping."""

import hashlib
import hmac
import json
import re

import pytest
from fastapi.testclient import TestClient

from fleet.core import webhooks as webhooks_mod
from fleet.core.instances import FleetPaths
from fleet.core.webhooks import WebhookStore
from fleet.daemon import create_app

REGISTRY = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    default_template: default
    default_branch: main
    templates:
      default: {}
"""

HX = {"HX-Request": "true"}


@pytest.fixture
def env(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(REGISTRY, encoding="utf-8")
    return paths, WebhookStore.from_paths(paths), TestClient(create_app(fleet_home))


def _seed(store):
    store.append_log(
        {
            "ts": "2026-10-18T09:00:00+00:00",
            "project": "demo",
            "delivery_id": "jira-delivery-1",
            "event": "jira:issue_updated",
            "issue": "FLE-30",
            "from": "To Do",
            "to": "Dispatched",
            "result": "accepted",
            "reason": None,
            "instance": "demo--fle-30",
            "job": "abc123def456",
            "action": "deploy",
            "template": "jira-work",
            "label": "FLE-30",
            "rule": "on_status=Dispatched",
        }
    )
    store.append_log(
        {
            "ts": "2026-10-18T10:00:00+00:00",
            "project": "demo",
            "delivery_id": "bb-delivery-1",
            "event": "repo:push",
            "branch": "feature/x--pw-run",
            "sha": "0123456789abcdef",
            "result": "ignored",
            "reason": "no matching rule",
            "action": "deploy",
            "template": "ci-pw",
            "rule": "on_event=repo:push branch=*--pw-run",
        },
        webhooks_mod.SOURCE_BITBUCKET,
    )
    store.append_log(
        {
            "ts": "2026-10-18T11:00:00+00:00",
            "project": "other",
            "event": "pullrequest:approved",
            "pr": 7,
            "pipeline": 17,
            "result": "triggered",
        },
        webhooks_mod.SOURCE_BITBUCKET,
    )


def _rows(html):
    return re.findall(r'<tr class="webhook-row">', html)


def test_nav_links_instances_secrets_webhooks(env):
    _, _, client = env
    nav = re.search(r'<nav class="main-nav">(.*?)</nav>', client.get("/webhooks").text, re.S)
    links = re.findall(r'<a href="([^"]+)">([^<]+)</a>', nav.group(1))
    assert links == [("/", "Instances"), ("/secrets", "Secrets"), ("/webhooks", "Webhooks")]
    assert 'href="/webhooks"' in client.get("/").text


def test_page_renders_both_sources_newest_first(env):
    _, store, client = env
    _seed(store)
    resp = client.get("/webhooks")
    assert resp.status_code == 200
    assert resp.headers["content-type"].startswith("text/html")
    assert "Content-Security-Policy" in resp.headers
    body = resp.text
    assert len(_rows(body)) == 3
    order = [body.index(x) for x in ("pullrequest:approved", "repo:push", "jira:issue_updated")]
    assert order == sorted(order)
    assert "2026-10-18 11:00:00 UTC" in body
    assert "FLE-30" in body and "To Do &rarr; Dispatched" in body
    assert "feature/x--pw-run" in body and "01234567" in body and "0123456789" not in body
    assert "PR #7" in body and "pipeline #17" in body
    assert "no matching rule" in body
    # FLE-30: rule and action columns
    assert "<th>Rule</th><th>Action</th>" in body
    assert "on_status=Dispatched" in body and "deploy jira-work" in body
    assert "label FLE-30" in body
    assert "on_event=repo:push branch=*--pw-run" in body and "deploy ci-pw" in body
    assert "bb-delivery-1" in body and "jira-delivery-1" in body
    # read-only: no mutating form or method on the page
    assert "hx-post" not in body and "hx-delete" not in body and 'method="post"' not in body.lower()


def test_empty_and_missing_logs_show_an_empty_state(env):
    _, _, client = env
    for url in ("/webhooks", "/ui/webhooks/table"):
        resp = client.get(url)
        assert resp.status_code == 200
        assert "No webhook delivery has been logged yet." in resp.text
        assert "<table" not in resp.text


def test_empty_state_with_filters_is_distinct(env):
    _, store, client = env
    _seed(store)
    resp = client.get("/webhooks", params={"project": "ghost"})
    assert resp.status_code == 200
    assert "No delivery matches these filters" in resp.text
    assert not _rows(resp.text)


def test_torn_lines_do_not_break_the_page(env):
    paths, store, client = env
    _seed(store)
    with paths.webhook_log.open("a", encoding="utf-8") as fh:
        fh.write('not json\n{"ts": "2026-10-18T1')
    assert len(_rows(client.get("/webhooks").text)) == 3


@pytest.mark.parametrize("url", ["/webhooks", "/ui/webhooks/table"])
def test_filters_via_query_string(env, url):
    _, store, client = env
    _seed(store)
    count = lambda **p: len(_rows(client.get(url, params=p).text))  # noqa: E731
    assert count() == 3
    assert count(source="jira") == 1
    assert count(source="bitbucket") == 2
    assert count(project="other") == 1
    assert count(result="ignored") == 1
    assert count(source="bitbucket", project="demo") == 1
    assert count(source="gopher") == 0
    assert count(result="deployed") == 0
    assert count(limit="2") == 2
    assert count(limit="0") == 1  # clipped to 1
    assert count(limit="banana") == 3  # falls back to the default


@pytest.mark.parametrize("url", ["/webhooks", "/ui/webhooks/table"])
def test_hide_ignored_filters_out_ignored_deliveries(env, url):
    _, store, client = env
    _seed(store)  # 3 deliveries, one of them ignored
    count = lambda **p: len(_rows(client.get(url, params=p).text))  # noqa: E731
    assert count() == 3
    assert count(hide_ignored="1") == 2
    assert "bb-delivery-1" not in client.get(url, params={"hide_ignored": "1"}).text
    assert "bb-delivery-1" in client.get(url).text
    assert count(hide_ignored="1", result="ignored") == 0
    # The limit counts only non-ignored rows: newest are triggered, ignored, accepted.
    body = client.get(url, params={"hide_ignored": "1", "limit": "2"}).text
    assert len(_rows(body)) == 2 and "jira-delivery-1" in body


def test_hide_ignored_checkbox_is_unchecked_by_default_and_stays_checked(env):
    _, store, client = env
    _seed(store)

    def box(html):
        return re.search(r'<input type="checkbox" name="hide_ignored"[^>]*>', html).group(0)

    assert "Hide ignored hooks" in client.get("/webhooks").text
    assert " checked" not in box(client.get("/webhooks").text)
    assert " checked" in box(client.get("/webhooks", params={"hide_ignored": "1"}).text)


def test_partial_is_a_fragment_with_the_swap_target(env):
    _, store, client = env
    _seed(store)
    body = client.get("/ui/webhooks/table", headers=HX).text
    assert body.lstrip().startswith('<div id="webhook-table">')
    assert "<html" not in body and "<form" not in body


def test_filter_options_cover_projects_in_the_window_and_the_result_vocabulary(env):
    _, store, client = env
    _seed(store)
    body = client.get("/webhooks", params={"project": "demo", "result": "ignored"}).text
    select = lambda name: re.search(  # noqa: E731
        rf'<select name="{name}">(.*?)</select>', body, re.S
    ).group(1)
    assert re.findall(r'value="([^"]*)"', select("project")) == ["", "demo", "other"]
    assert re.findall(r'value="([^"]*)"', select("source")) == ["", "jira", "bitbucket"]
    assert re.findall(r'value="([^"]*)"', select("result")) == ["", *webhooks_mod.RESULTS]
    assert 'value="demo" selected' in select("project")
    assert 'value="ignored" selected' in select("result")
    assert 'value="100" selected' in select("limit")


def test_limit_select_keeps_a_custom_limit(env):
    _, _, client = env
    body = client.get("/webhooks", params={"limit": "137"}).text
    assert 'value="137" selected' in body


def test_instance_is_linked_only_when_it_exists(env, fleet_home):
    _, store, client = env
    _seed(store)
    assert '<a href="https://demo--fle-30' not in client.get("/webhooks").text
    assert "demo--fle-30" in client.get("/webhooks").text  # plain text
    (fleet_home / "instances" / "demo--fle-30").mkdir()
    body = client.get("/webhooks").text
    assert '<a href="https://demo--fle-30.fleet.example.test">demo--fle-30</a>' in body
    assert "job abc123def456" in body


def test_old_entries_without_rule_fields_render_dashes(env):
    _, store, client = env
    store.append_log({"ts": "2026-01-01T00:00:00+00:00", "project": "demo", "result": "ignored"})
    body = client.get("/webhooks").text
    cells = re.findall(r"<td[^>]*>(.*?)</td>", body.split("<tbody>", 1)[1], re.S)
    # time, source, project, event, ref, rule, action, result, reason, delivery
    assert [c.strip() for c in cells[5:7]] == ["-", "-"]


def test_unrepresentable_timestamps_do_not_break_the_page(env):
    _, store, client = env
    # year 1 with a positive offset overflows when converted to UTC
    for ts in ("0001-01-01T00:00:00+05:00", "9999-12-31T23:59:59-05:00", "garbage", 17, None):
        store.append_log({"ts": ts, "project": "demo", "result": "ignored"})
    for url in ("/webhooks", "/ui/webhooks/table"):
        resp = client.get(url)
        assert resp.status_code == 200
        assert len(_rows(resp.text)) == 5
    assert "0001-01-01T00:00:00+05:00" in client.get("/webhooks").text  # shown as logged


def test_odd_instance_values_are_never_linked(env, fleet_home):
    _, store, client = env
    (fleet_home / "instances" / "..").mkdir(exist_ok=True)
    for value in ("../etc", "a/b", "UPPER", ["x"], 5, ""):
        store.append_log({"project": "demo", "result": "accepted", "instance": value})
    body = client.get("/webhooks").text
    assert '<a href="https://' not in body.split("<tbody>", 1)[1]


def test_logged_values_are_escaped(env):
    _, store, client = env
    evil = "<script>alert(1)</script><img src=x onerror=alert(2)>\"'"
    store.append_log(
        {
            "project": evil,
            "event": evil,
            "issue": evil,
            "to": evil,
            "reason": evil,
            "delivery_id": evil,
            "instance": evil,
            "result": evil,
        }
    )
    store.append_log(
        {"project": evil, "branch": evil, "sha": evil, "state": evil, "pr": evil, "pipeline": evil},
        webhooks_mod.SOURCE_BITBUCKET,
    )
    for url in ("/webhooks", "/ui/webhooks/table"):
        body = client.get(url).text
        assert "<script>alert" not in body and "<img src=x" not in body
        assert "&lt;script&gt;alert(1)&lt;/script&gt;" in body
    # the dropdowns escape too (project and result come from the log)
    page = client.get("/webhooks").text
    assert '<option value="<script>' not in page
    assert (
        "&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(2)&gt;"
        in page.split('<select name="project">', 1)[1].split("</select>", 1)[0]
    )


def test_no_write_routes(env):
    _, _, client = env
    for url in ("/webhooks", "/ui/webhooks/table"):
        for method in ("post", "put", "delete", "patch"):
            assert getattr(client, method)(url).status_code == 405


def test_unknown_registry_is_a_renderable_error(fleet_home):
    # no fleet.yml at all: the usual FleetError -> error panel, never a 500 traceback
    client = TestClient(create_app(fleet_home), raise_server_exceptions=False)
    assert client.get("/webhooks").status_code == 400


def test_round_trip_from_a_real_delivery(env, fleet_home, monkeypatch):
    """A Jira delivery posted to the hook shows up, with its instance and delivery id."""
    paths, store, client = env
    paths.registry.write_text(
        REGISTRY
        + "    jira_hooks:\n      - {on_status: Dispatched, action: deploy, template: default}\n",
        encoding="utf-8",
    )
    secret = store.create_secret("demo")
    from fleet import daemon as daemon_mod

    monkeypatch.setattr(daemon_mod.instances_mod, "deploy", lambda *a, **k: "https://x")
    body = json.dumps(
        {
            "webhookEvent": "jira:issue_updated",
            "issue": {"key": "FLE-9"},
            "changelog": {
                "items": [{"field": "status", "fromString": "To Do", "toString": "Dispatched"}]
            },
        }
    ).encode()
    sig = "sha256=" + hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    resp = client.post(
        "/hooks/jira/demo",
        content=body,
        headers={"X-Hub-Signature": sig, "X-Atlassian-Webhook-Identifier": "round-trip-1"},
    )
    assert resp.status_code == 200, resp.text
    page = client.get("/webhooks").text
    assert "round-trip-1" in page and "FLE-9" in page and "accepted" in page
    assert resp.json()["instance"] in page
    assert hashlib.sha256(secret.encode()).hexdigest() not in page and secret not in page
