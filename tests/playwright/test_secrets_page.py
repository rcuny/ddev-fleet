"""The Secrets screen in a real browser (FLE-24): real clicks, real JS, real CSP."""

from __future__ import annotations

from pathlib import Path
from urllib.parse import parse_qs

from fleet.core import pgp
from fleet.core.instances import FleetPaths

# An <input type=password> cannot hold a newline (the browser strips it), so the
# stress is non-ASCII plus leading and trailing spaces, which must survive untouched.
PLAINTEXT = "  päss wörd é € trailing spaces  "
SAVE = "button:has-text('Encrypt and save')"
SECRETS_URL = "/secrets?project=demo"


def _asc(home: Path, name: str) -> Path:
    return home / "secrets" / "demo" / f"{name}.asc"


def _is_secret_post(request) -> bool:
    return request.method == "POST" and request.url.endswith("/ui/secrets/demo")


def _track_posts(page) -> list:
    posts: list = []
    page.on("request", lambda r: posts.append(r) if r.method == "POST" else None)
    return posts


def _save(page, name: str, value: str) -> None:
    page.fill("input[name=key]", name)
    page.fill("#secret-value", value)
    page.click(SAVE)


def test_save_encrypts_in_the_browser_and_the_host_decrypts_it(browser_page, live_server, e2e_home):
    page = browser_page
    page.set_default_timeout(10_000)
    page.goto(live_server + SECRETS_URL)

    gnupg = FleetPaths.from_home(e2e_home).gnupg
    shown = page.inner_text("#host-fingerprint").replace(" ", "")
    assert shown == pgp.host_key(gnupg).fingerprint.upper()

    with page.expect_response(lambda r: _is_secret_post(r.request)) as received:
        _save(page, "FLEET_E2E", PLAINTEXT)
    request = received.value.request

    fields = parse_qs(request.post_data or "", keep_blank_values=True)
    assert set(fields) == {"project", "key", "armored"}, "only these fields may be sent"
    assert fields["key"] == ["FLEET_E2E"]
    assert fields["armored"][0].startswith("-----BEGIN PGP MESSAGE-----")
    body = request.post_data or ""
    for needle in ("päss", "wörd", "trailing"):
        assert needle not in body and needle not in str(fields), f"plaintext {needle!r} was sent"
    assert received.value.status == 200

    page.wait_for_selector(".secret-notice")
    assert "Stored FLEET_E2E" in page.inner_text(".secret-notice")
    assert "FLEET_E2E" in page.inner_text("#secret-names")

    asc = _asc(e2e_home, "FLEET_E2E")
    assert asc.is_file()
    assert pgp.decrypt(gnupg, asc.read_text(encoding="utf-8")) == PLAINTEXT


def test_empty_value_shows_a_client_side_error_and_sends_nothing(browser_page, live_server):
    page = browser_page
    page.set_default_timeout(10_000)
    page.goto(live_server + SECRETS_URL)
    posts = _track_posts(page)

    page.fill("input[name=key]", "FLEET_E2E")
    page.click(SAVE)

    error = page.locator("#secret-client-error")
    error.wait_for(state="visible")
    assert "empty" in error.inner_text()
    page.wait_for_timeout(500)  # a request, if one were going to be sent, is out by now
    assert posts == []


def test_invalid_name_shows_the_error_keeps_the_name_and_stores_nothing(
    browser_page, live_server, e2e_home, expected_console_errors
):
    expected_console_errors.append("status of 400")
    page = browser_page
    page.set_default_timeout(10_000)
    page.goto(live_server + SECRETS_URL)

    # The Name input's `pattern` is native validation and would block the submit
    # before any request; the server-side error panel is what this test is about,
    # so lift only that pattern.
    page.eval_on_selector("input[name=key]", "el => el.removeAttribute('pattern')")
    with page.expect_response(lambda r: _is_secret_post(r.request)) as received:
        _save(page, "bad-name", "some-value")

    assert received.value.status == 400
    panel = page.locator("#secret-feedback")
    panel.wait_for(state="visible")
    assert "bad-name" in panel.inner_text() or "name" in panel.inner_text().lower()
    assert page.input_value("input[name=key]") == "bad-name"
    assert not (e2e_home / "secrets" / "demo").exists() or not list(
        (e2e_home / "secrets" / "demo").glob("*.asc")
    )


def test_delete_removes_the_secret(browser_page, live_server, e2e_home):
    page = browser_page
    page.set_default_timeout(10_000)
    page.goto(live_server + SECRETS_URL)

    with page.expect_response(lambda r: _is_secret_post(r.request)):
        _save(page, "FLEET_E2E", "to-be-deleted")
    page.wait_for_selector(".secret-notice")
    assert _asc(e2e_home, "FLEET_E2E").is_file()

    dialogs: list[str] = []

    def accept(dialog) -> None:
        dialogs.append(dialog.message)
        dialog.accept()

    page.on("dialog", accept)
    with page.expect_response(lambda r: r.url.endswith("/FLEET_E2E/delete")):
        page.click("#secret-names button.destroy")

    page.locator("#secret-names code").wait_for(state="detached")
    assert "FLEET_E2E" not in page.inner_text("#secret-names")
    assert len(dialogs) == 1 and "FLEET_E2E" in dialogs[0]
    assert not _asc(e2e_home, "FLEET_E2E").exists()


def test_without_a_host_key_the_page_shows_instructions_and_no_form(
    browser_page, live_server_no_key
):
    page = browser_page
    page.goto(live_server_no_key + SECRETS_URL)

    assert "fleet keys init" in page.inner_text("main, body")
    assert page.locator("#secret-form").count() == 0
