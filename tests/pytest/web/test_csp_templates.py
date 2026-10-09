"""Templates must be CSP-clean: no inline script, <style>, style=, handlers (FLE-23)."""

import re
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from fleet.core.instances import FleetPaths
from fleet.daemon import create_app

_TEMPLATES = Path(__file__).resolve().parents[3] / "src" / "fleet" / "templates"

# name -> pattern. Each is a construct that script-src/style-src 'self' would block
# or that needs eval (allowEval is false).
_FORBIDDEN = {
    "inline <script> (no src=)": re.compile(r"<script\b(?![^>]*\bsrc\s*=)", re.I),
    "<style> element": re.compile(r"<style\b", re.I),
    "style= attribute": re.compile(r"\sstyle\s*=", re.I),
    "on*= event handler": re.compile(r"\son[a-z]+\s*=", re.I),
    "hx-on attribute": re.compile(r"\shx-on\b", re.I),
    "javascript: URL": re.compile(r"javascript:", re.I),
    "htmx js: expression": re.compile(r"""=\s*["']\s*js:""", re.I),
}
_SCRIPT_SRC = re.compile(r"<script\b[^>]*\bsrc\s*=\s*[\"']([^\"']*)[\"']", re.I)


def scan(text: str) -> list[str]:
    """Names of the forbidden constructs found in `text`."""
    found = [name for name, rx in _FORBIDDEN.items() if rx.search(text)]
    found += [
        f"external script {src}"
        for src in _SCRIPT_SRC.findall(text)
        if not src.startswith("/static/")
    ]
    return found


@pytest.mark.parametrize(
    ("snippet", "expected"),
    [
        ("<script>alert(1)</script>", "inline <script> (no src=)"),
        ("<style>p{}</style>", "<style> element"),
        ('<div style="color:red">', "style= attribute"),
        ("<div\n  style='x'>", "style= attribute"),
        ('<button onclick="go()">', "on*= event handler"),
        ('<a href="javascript:void(0)">', "javascript: URL"),
        ('<div hx-on:click="x()">', "hx-on attribute"),
        ('<div hx-on::after-request="x()">', "hx-on attribute"),
        ("<div hx-vals='js:{a:1}'>", "htmx js: expression"),
        (
            '<script src="https://cdn.example/x.js"></script>',
            "external script https://cdn.example/x.js",
        ),
    ],
)
def test_scanner_flags_forbidden_constructs(snippet, expected):
    assert expected in scan(snippet)


def test_scanner_accepts_clean_markup():
    clean = (
        '<script src="/static/htmx.min.js"></script>\n'
        '<script src="/static/ws-log.js" defer></script>\n'
        '<div class="job-panel" data-ws-url="/ws/x" hx-get="/ui/y" hx-trigger="every 2s">\n'
        '<input type="checkbox" id="select-all"> <form hx-post="/ui/deploy">'
    )
    assert scan(clean) == []


def test_template_sources_are_csp_clean():
    files = sorted(_TEMPLATES.rglob("*.html"))
    assert len(files) >= 6, files  # base, instances, 4 partials: the glob must not be empty
    problems = {str(f.relative_to(_TEMPLATES)): scan(f.read_text(encoding="utf-8")) for f in files}
    assert {k: v for k, v in problems.items() if v} == {}


def test_base_template_has_htmx_config_meta_and_stylesheet():
    base = (_TEMPLATES / "base.html").read_text(encoding="utf-8")
    assert (
        '<meta name="htmx-config" '
        'content=\'{"includeIndicatorStyles":false,"allowEval":false}\'>'
    ) in base
    assert '<link rel="stylesheet" href="/static/fleet.css">' in base
    # the meta must come before htmx is loaded
    assert base.index("htmx-config") < base.index("/static/htmx.min.js")


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
"""


@pytest.fixture
def client(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(_REGISTRY, encoding="utf-8")
    instance_fleet = fleet_home / "instances" / "demo--develop" / ".fleet"
    instance_fleet.mkdir(parents=True)
    (instance_fleet / "instance.yml").write_text(
        "project: demo\ninstance: develop\nbranch: main\n"
        "created-at: '2026-07-01T00:00:00Z'\nlast-deployed-at: '2026-07-01T00:00:00Z'\n",
        encoding="utf-8",
    )
    return TestClient(create_app(fleet_home))


def test_rendered_dashboard_is_csp_clean(client, monkeypatch):
    from fleet.core import sysinfo

    # force the reboot badge (formerly the only inline style=) into the page
    monkeypatch.setattr(
        sysinfo,
        "read_reboot_status",
        lambda *a, **k: sysinfo.RebootStatus(pending=True, since=1000.0, packages=["libc6"]),
    )
    body = client.get("/").text
    assert "reboot-badge" in body
    assert scan(body) == []


def test_rendered_fragments_are_csp_clean(client):
    fragment = client.get("/ui/deploy/templates", params={"project": "demo"}).text
    assert scan(fragment) == []
    error = client.post(
        "/ui/deploy",
        data={"project": "Bad Name", "template": "default"},
        headers={"HX-Request": "true"},
    )
    assert error.status_code == 400
    assert scan(error.text) == []


def test_fleet_css_is_served_with_the_moved_rules(client):
    response = client.get("/static/fleet.css")
    assert response.status_code == 200
    assert response.headers["content-type"].startswith("text/css")
    css = response.text
    for selector in (
        "body",
        "table",
        "form.deploy-form",
        ".job-panel",
        ".error-panel",
        ".job-log",
        "button.destroy",
        "footer.sys-stats",
        "footer.sys-stats .mount",
        ".reboot-badge",
        ".htmx-indicator",
    ):
        assert selector in css, selector
    assert "#b00020" in css  # reboot badge colour moved out of the style= attribute
    assert "font-family: system-ui" in css
