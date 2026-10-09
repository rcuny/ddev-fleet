"""Browser end-to-end fixtures (FLE-24): a real daemon, a real Chromium, real CSP.

Unit tests drive the Secrets form against a fake htmx and the ASGI test client,
so they cannot see what only a browser does (htmx's form validation, the CSP,
module loading). These fixtures start the actual ``fleet.daemon:app`` under
uvicorn on a free port and drive it with Playwright. Chromium runs with the
CSP *enforced* (never ``bypass_csp``); any console error, uncaught page error or
``securitypolicyviolation`` fails the test.

Skip policy mirrors ``FLEET_REQUIRE_PGP_TOOLS``: a missing Playwright or a
Chromium that cannot launch skips, unless ``FLEET_REQUIRE_E2E=1`` (CI), which
fails instead so the suite cannot silently turn into a skip.
"""

from __future__ import annotations

import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

import pytest

from fleet.core import pgp
from fleet.core.instances import FleetPaths
from tests.conftest import SAMPLE_REGISTRY_YAML, _kill_gpg_agent, _short_tmp_root

# Collected in every page: CSP violations are DOM events, not console messages.
_CSP_LISTENER = """
window.__cspViolations = [];
document.addEventListener("securitypolicyviolation", (e) => {
  window.__cspViolations.push(
    e.violatedDirective + " blocked " + e.blockedURI + " (" + e.sourceFile + ")"
  );
});
"""


def _unavailable(reason: str) -> None:
    if os.environ.get("FLEET_REQUIRE_E2E") == "1":
        pytest.fail(f"FLEET_REQUIRE_E2E=1 but the browser E2E suite cannot run: {reason}")
    pytest.skip(reason)


@pytest.fixture
def _chromium():
    """A headless Chromium per test. Deliberately not session-scoped: a started sync
    Playwright keeps an asyncio loop running in the main thread, which breaks every
    later test that calls `asyncio.run()` (tests/pytest/web/test_jobs.py)."""
    try:
        from playwright.sync_api import Error, sync_playwright
    except ImportError as exc:
        _unavailable(f"playwright is not importable ({exc}); pip install -e '.[dev]'")
    playwright = sync_playwright().start()
    try:
        browser = playwright.chromium.launch(headless=True)
    except Error as exc:
        playwright.stop()
        _unavailable(
            f"chromium cannot launch ({str(exc).splitlines()[0]}); "
            "run: python -m playwright install --with-deps --only-shell chromium"
        )
    yield browser
    browser.close()
    playwright.stop()


@pytest.fixture
def e2e_home(requires_gpg):
    """A short FLEET_HOME (gpg-agent socket limit) with one project, `demo`, and
    an initialised host key, so /secrets shows the form."""
    home = Path(tempfile.mkdtemp(prefix="fe-", dir=_short_tmp_root()))
    (home / "config" / "assets").mkdir(parents=True)
    for name in ("instances", "logs", "locks"):
        (home / name).mkdir()
    (home / "config" / "fleet.yml").write_text(SAMPLE_REGISTRY_YAML, encoding="utf-8")
    paths = FleetPaths.from_home(home)
    key = pgp.init_host_key(paths.gnupg, "fleet e2e <e2e@example.test>")
    (home / "host-public-key.asc").write_text(key.armored_public_key, encoding="utf-8")
    yield home
    _kill_gpg_agent(home / "gnupg")
    shutil.rmtree(home, ignore_errors=True)


@pytest.fixture
def e2e_home_no_key(requires_gpg):
    """Like `e2e_home` but without a host key."""
    home = Path(tempfile.mkdtemp(prefix="fe-", dir=_short_tmp_root()))
    (home / "config" / "assets").mkdir(parents=True)
    for name in ("instances", "logs", "locks"):
        (home / name).mkdir()
    (home / "config" / "fleet.yml").write_text(SAMPLE_REGISTRY_YAML, encoding="utf-8")
    yield home
    shutil.rmtree(home, ignore_errors=True)


def _free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def _serve(home: Path):
    port = _free_port()
    env = {**os.environ, "FLEET_HOME": str(home)}
    proc = subprocess.Popen(
        [
            sys.executable,
            "-m",
            "uvicorn",
            "fleet.daemon:app",
            "--host",
            "127.0.0.1",
            "--port",
            str(port),
            "--log-level",
            "warning",
        ],
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )
    base = f"http://127.0.0.1:{port}"
    deadline = time.monotonic() + 30
    try:
        while True:
            if proc.poll() is not None:
                pytest.fail(f"daemon exited early:\n{proc.stdout.read()}")
            try:
                urllib.request.urlopen(f"{base}/secrets", timeout=1).close()
                break
            except (urllib.error.URLError, OSError):
                if time.monotonic() > deadline:
                    pytest.fail("daemon did not answer within 30s")
                time.sleep(0.1)
        yield base
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=10)
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.wait()
        if proc.stdout:
            proc.stdout.close()


@pytest.fixture
def live_server(e2e_home):
    yield from _serve(e2e_home)


@pytest.fixture
def live_server_no_key(e2e_home_no_key):
    yield from _serve(e2e_home_no_key)


@pytest.fixture
def expected_console_errors() -> list[str]:
    """Substrings of console errors a test deliberately provokes. Chromium logs
    every 4xx/5xx response as "Failed to load resource: ... status of 400"; a
    test that asserts the server's error panel appends that text here. Nothing
    else is ever ignored, and only for the test that declares it."""
    return []


@pytest.fixture
def browser_page(_chromium, expected_console_errors):
    """A fresh page in a fresh context with CSP enforced. After the test, fails
    on any console error, uncaught page error or CSP violation (apart from the
    ones the test declared in `expected_console_errors`)."""
    context = _chromium.new_context()
    page = context.new_page()
    page.add_init_script(_CSP_LISTENER)
    problems: list[str] = []
    page.on("pageerror", lambda exc: problems.append(f"pageerror: {exc}"))
    page.on(
        "console",
        lambda msg: (
            problems.append(f"console.{msg.type}: {msg.text}")
            if msg.type == "error" and not any(e in msg.text for e in expected_console_errors)
            else None
        ),
    )
    yield page
    try:
        problems += [f"CSP: {v}" for v in page.evaluate("window.__cspViolations || []")]
    except Exception:  # page already closed or navigated to a non-document
        pass
    context.close()
    assert not problems, "browser reported problems:\n" + "\n".join(problems)
