"""Browser-facing hardening for the dashboard (FLE-23).

Pure helpers, no FastAPI imports: a strict Content-Security-Policy for HTML
responses and the same-origin decision for state-changing ``/ui/*`` requests.
`daemon.create_app` wires them in with one HTTP middleware.

Same-origin policy (see docs/architecture.md): when ``Sec-Fetch-Site`` is
present it decides (only ``same-origin`` passes — ``same-site`` is refused too,
because DDEV instances on ``*.<domain>`` are same-site siblings running
third-party code). Otherwise, if ``Origin`` is present its host[:port] must
equal ``Host``. A request with neither header is not a browser cross-site
request (those always carry at least ``Origin``), so it is allowed.
"""

import re
from collections.abc import Mapping
from urllib.parse import urlsplit

_UI_PREFIX = "/ui/"
_SAFE_METHODS = frozenset({"GET", "HEAD", "OPTIONS"})

# Strict host[:port]: the Host header is client-controlled and is copied into
# the CSP, so anything that is not plain DNS-name/IPv4[:port] is left out.
_HOST_RE = re.compile(r"^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?(?::[0-9]{1,5})?$")

_STATIC_HEADERS = {
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "no-referrer",
}


def csp_value(host: str | None) -> str:
    """The dashboard's Content-Security-Policy for a request sent to `host`.

    ``connect-src`` gets an explicit ``wss://<host>`` next to ``'self'`` (older
    Safari did not let ``'self'`` cover WebSocket schemes) — only when `host`
    is a plain host[:port].
    """
    connect = "'self'"
    if host and _HOST_RE.match(host):
        connect += f" wss://{host}"
    directives = [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self' data:",
        f"connect-src {connect}",
        "object-src 'none'",
        "base-uri 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
    ]
    return "; ".join(directives)


def html_security_headers(host: str | None) -> dict[str, str]:
    """Headers added to every ``text/html`` response."""
    return {"Content-Security-Policy": csp_value(host), **_STATIC_HEADERS}


def is_html(content_type: str | None) -> bool:
    """True for a ``text/html`` Content-Type (parameters and case ignored)."""
    if not content_type:
        return False
    return content_type.split(";", 1)[0].strip().lower() == "text/html"


def is_cross_origin_ui_write(method: str, path: str, headers: Mapping[str, str]) -> bool:
    """True when this is a state-changing ``/ui/*`` request that must be refused.

    `headers` must be case-insensitive (Starlette ``Headers``).
    """
    if method.upper() in _SAFE_METHODS or not path.startswith(_UI_PREFIX):
        return False
    site = headers.get("sec-fetch-site")
    if site is not None:
        return site.strip().lower() != "same-origin"
    origin = headers.get("origin")
    if origin is None:
        return False
    host = (headers.get("host") or "").strip().lower()
    return not host or urlsplit(origin).netloc.lower() != host
