"""CSP headers and the same-origin check on /ui/* (FLE-23), through the real app."""

import pytest
from fastapi.testclient import TestClient

from fleet.core.instances import FleetPaths
from fleet.core.webhooks import WebhookStore
from fleet.daemon import create_app

_REGISTRY = """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    default_template: default
    default_branch: main
    templates:
      default: {}
  p:
    git: git@example.test:org/p.git
    default_template: jira-work
    default_branch: main
    issue_id_regexp: "FLE-[0-9]+"
    templates:
      jira-work: {}
    jira_hooks:
      - {on_status: Dispatched, action: deploy, template: jira-work}
"""

_CSP_DIRECTIVES = [
    "default-src 'self'",
    "script-src 'self'",
    "style-src 'self'",
    "img-src 'self' data:",
    "connect-src 'self' wss://testserver",
    "object-src 'none'",
    "base-uri 'none'",
    "frame-ancestors 'none'",
    "form-action 'self'",
]
_REFUSED = "cross-origin request refused"
# A POST to /ui/deploy that is rejected by validation (400) when it gets past the
# same-origin check: this proves "allowed" without starting any real deploy.
_BAD_DEPLOY = {"project": "Bad Name", "template": "default"}


@pytest.fixture
def seeded_home(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_REGISTRY, encoding="utf-8")
    WebhookStore.from_paths(paths).create_secret("p")
    return fleet_home


@pytest.fixture
def client(seeded_home):
    return TestClient(create_app(seeded_home))


def _assert_full_csp(response):
    assert response.headers["content-security-policy"] == "; ".join(_CSP_DIRECTIVES)
    assert response.headers["x-content-type-options"] == "nosniff"
    assert response.headers["referrer-policy"] == "no-referrer"


def test_dashboard_page_has_csp(client):
    response = client.get("/")
    assert response.status_code == 200
    assert response.headers["content-type"].startswith("text/html")
    _assert_full_csp(response)


def test_htmx_fragment_has_csp(client):
    response = client.get("/ui/deploy/templates", params={"project": "demo"})
    assert response.status_code == 200
    _assert_full_csp(response)


def test_error_fragment_has_csp(client):
    # same-origin htmx POST rejected by validation: the fleet_error_handler fragment
    response = client.post(
        "/ui/deploy",
        data=_BAD_DEPLOY,
        headers={"HX-Request": "true", "Sec-Fetch-Site": "same-origin"},
    )
    assert response.status_code == 400
    assert "error-panel" in response.text
    _assert_full_csp(response)


def test_csp_uses_the_request_host_for_wss(seeded_home):
    app_client = TestClient(create_app(seeded_home), base_url="http://dash.example.test:8765")
    response = app_client.get("/")
    assert "connect-src 'self' wss://dash.example.test:8765;" in (
        response.headers["content-security-policy"]
    )


def test_json_responses_have_no_csp(client):
    response = client.get("/api/tls-authorize", params={"domain": "nope.example.test"})
    assert response.headers["content-type"].startswith("application/json")
    assert "content-security-policy" not in response.headers


@pytest.mark.parametrize("path", ["/static/fleet.css", "/static/htmx.min.js", "/static/ws-log.js"])
def test_static_files_are_served_without_csp(client, path):
    response = client.get(path)
    assert response.status_code == 200
    assert response.text
    assert "content-security-policy" not in response.headers


def test_plain_text_log_has_no_csp(client):
    response = client.get("/ui/instances/demo--develop/log")
    assert response.status_code == 404
    assert response.headers["content-type"].startswith("text/plain")
    assert "content-security-policy" not in response.headers


@pytest.mark.parametrize(
    "headers",
    [
        {"Sec-Fetch-Site": "cross-site"},
        {"Sec-Fetch-Site": "same-site"},
        {"Sec-Fetch-Site": "none"},
        {"Origin": "https://evil.example"},
        {"Origin": "null"},
        {"Origin": "https://sibling.fleet.example.test"},
        {"Origin": "http://testserver:9999"},
    ],
)
def test_cross_origin_ui_post_is_refused(client, headers):
    response = client.post("/ui/deploy", data=_BAD_DEPLOY, headers=headers)
    assert response.status_code == 403
    assert _REFUSED in response.json()["error"]


def test_refused_htmx_request_gets_a_renderable_fragment(client):
    response = client.post(
        "/ui/deploy",
        data=_BAD_DEPLOY,
        headers={"HX-Request": "true", "Sec-Fetch-Site": "cross-site"},
    )
    assert response.status_code == 403
    assert response.headers["content-type"].startswith("text/html")
    assert "error-panel" in response.text
    assert _REFUSED in response.text
    _assert_full_csp(response)


@pytest.mark.parametrize(
    "headers",
    [
        {"Sec-Fetch-Site": "same-origin"},
        {"Origin": "http://testserver"},
        {"Origin": "https://TESTSERVER"},
        {"Sec-Fetch-Site": "same-origin", "Origin": "http://testserver"},
        {},  # no Origin and no Sec-Fetch-Site: non-browser client, allowed by policy
    ],
)
def test_same_origin_ui_post_passes_the_check(client, headers):
    response = client.post("/ui/deploy", data=_BAD_DEPLOY, headers=headers)
    assert response.status_code == 400  # past the check, rejected by validation
    assert "invalid name" in response.json()["error"]


def test_cross_site_ui_get_is_not_blocked(client):
    response = client.get(
        "/ui/deploy/templates",
        params={"project": "demo"},
        headers={"Sec-Fetch-Site": "cross-site"},
    )
    assert response.status_code == 200


def test_every_ui_post_route_is_behind_the_check(client):
    # No UI write route may slip past the middleware: probe each one cross-site.
    post_routes = sorted(
        {
            route.path
            for route in client.app.routes
            if getattr(route, "methods", None)
            and "POST" in route.methods
            and route.path.startswith("/ui/")
        }
    )
    assert len(post_routes) >= 8, post_routes
    for path in post_routes:
        concrete = path.replace("{instance_id}", "demo--develop")
        response = client.post(concrete, headers={"Sec-Fetch-Site": "cross-site"})
        assert response.status_code == 403, path


def test_webhooks_are_not_subject_to_the_origin_check(client):
    # Unsigned, with cross-site headers: must reach the HMAC check (401), not our 403.
    response = client.post(
        "/hooks/jira/p",
        content=b"{}",
        headers={
            "Content-Type": "application/json",
            "Sec-Fetch-Site": "cross-site",
            "Origin": "https://jira.example",
        },
    )
    assert response.status_code == 401
    assert _REFUSED not in response.text
    assert "content-security-policy" not in response.headers


def test_websocket_upgrade_is_untouched(client):
    from fastapi import WebSocketDisconnect

    with pytest.raises(WebSocketDisconnect):
        with client.websocket_connect(
            "/ws/instances/demo--develop/log?token=bad",
            headers={"Origin": "https://evil.example", "Sec-Fetch-Site": "cross-site"},
        ) as ws:
            ws.receive_text()
