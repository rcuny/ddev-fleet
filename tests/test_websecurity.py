"""Pure helpers behind the dashboard's CSP and same-origin check (FLE-23)."""

import pytest
from starlette.datastructures import Headers

from fleet import websecurity


def _csp(connect: str) -> str:
    return (
        "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        f"connect-src {connect}; object-src 'none'; base-uri 'none'; "
        "frame-ancestors 'none'; form-action 'self'"
    )


def test_csp_value_exact_with_host():
    assert websecurity.csp_value("fleet.example.test") == _csp("'self' wss://fleet.example.test")


def test_csp_value_keeps_port():
    assert websecurity.csp_value("127.0.0.1:8765") == _csp("'self' wss://127.0.0.1:8765")


@pytest.mark.parametrize(
    "host",
    [
        None,
        "",
        "evil.example; script-src *",
        "a b",
        "a,b",
        "a.example\r\nX-Injected: 1",
        "[::1]:8765",
        "host:99999999",
        "-leading.example",
        "good.example\n",
    ],
)
def test_csp_value_omits_unsafe_host(host):
    value = websecurity.csp_value(host)
    assert value == _csp("'self'")
    assert "wss://" not in value
    assert value.count(";") == 8  # no extra directive can be smuggled in


def test_html_security_headers():
    headers = websecurity.html_security_headers("fleet.example.test")
    assert headers == {
        "Content-Security-Policy": _csp("'self' wss://fleet.example.test"),
        "X-Content-Type-Options": "nosniff",
        "Referrer-Policy": "no-referrer",
    }


@pytest.mark.parametrize(
    ("content_type", "expected"),
    [
        ("text/html; charset=utf-8", True),
        ("TEXT/HTML", True),
        ("text/html", True),
        ("application/json", False),
        ("text/plain; charset=utf-8", False),
        ("text/css; charset=utf-8", False),
        (None, False),
        ("", False),
    ],
)
def test_is_html(content_type, expected):
    assert websecurity.is_html(content_type) is expected


def _blocked(method, path, **headers):
    hdrs = Headers({"Host": "fleet.example.test", **headers})
    return websecurity.is_cross_origin_ui_write(method, path, hdrs)


@pytest.mark.parametrize(
    "headers",
    [
        {"Sec-Fetch-Site": "cross-site"},
        {"Sec-Fetch-Site": "same-site"},  # a sibling *.<domain> instance
        {"Sec-Fetch-Site": "none"},
        {"Sec-Fetch-Site": "Cross-Site"},
        {"Origin": "https://evil.example"},
        {"Origin": "null"},
        {"Origin": "https://fleet.example.test.evil.example"},
        {"Origin": "https://sibling.fleet.example.test"},
        {"Origin": "https://fleet.example.test:8443"},  # port differs from the Host
        {"Origin": "not a url"},
        {"Origin": "http://[::1"},
        {"Origin": "http://[x]"},
        {"Sec-Fetch-Site": "cross-site", "Origin": "https://fleet.example.test"},
    ],
)
def test_ui_post_refused(headers):
    assert _blocked("POST", "/ui/deploy", **headers) is True


@pytest.mark.parametrize(
    "headers",
    [
        {},  # non-browser client, or a browser too old to send either header
        {"Sec-Fetch-Site": "same-origin"},
        {"Sec-Fetch-Site": "SAME-ORIGIN"},
        {"Origin": "https://fleet.example.test"},
        {"Origin": "http://FLEET.example.test"},  # scheme is not compared, host is caseless
        {"Sec-Fetch-Site": "same-origin", "Origin": "https://fleet.example.test"},
    ],
)
def test_ui_post_allowed(headers):
    assert _blocked("POST", "/ui/deploy", **headers) is False


def test_host_with_port_matches_origin_with_same_port():
    hdrs = Headers({"Host": "127.0.0.1:8765", "Origin": "http://127.0.0.1:8765"})
    assert websecurity.is_cross_origin_ui_write("POST", "/ui/deploy", hdrs) is False
    hdrs = Headers({"Host": "127.0.0.1:8765", "Origin": "http://127.0.0.1:9999"})
    assert websecurity.is_cross_origin_ui_write("POST", "/ui/deploy", hdrs) is True


def test_origin_without_host_header_is_refused():
    hdrs = Headers({"Origin": "https://fleet.example.test"})
    assert websecurity.is_cross_origin_ui_write("POST", "/ui/deploy", hdrs) is True


@pytest.mark.parametrize("method", ["POST", "PUT", "PATCH", "DELETE"])
def test_every_unsafe_method_is_checked(method):
    assert _blocked(method, "/ui/instances/x/destroy", **{"Sec-Fetch-Site": "cross-site"})


@pytest.mark.parametrize("method", ["GET", "HEAD", "OPTIONS"])
def test_safe_methods_are_never_blocked(method):
    assert not _blocked(method, "/ui/deploy/templates", **{"Sec-Fetch-Site": "cross-site"})


@pytest.mark.parametrize(
    "path",
    ["/hooks/jira/p", "/hooks/bitbucket/p", "/api/tls-authorize", "/", "/static/x", "/uiX/deploy"],
)
def test_only_ui_paths_are_checked(path):
    assert not _blocked("POST", path, **{"Sec-Fetch-Site": "cross-site"})
