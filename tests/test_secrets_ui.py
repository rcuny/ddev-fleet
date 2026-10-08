"""Secrets screen (FLE-22): page, routes, server-side validation of browser ciphertext."""

import os
import re
import stat
import subprocess
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from fleet.core import pgp
from fleet.core.instances import FleetPaths
from fleet.core.secretstore import SecretStore
from fleet.daemon import create_app, format_fingerprint

ROOT = Path(__file__).resolve().parents[1]
STATIC = ROOT / "src" / "fleet" / "static"
ENCRYPT_CLI = ROOT / "tests" / "js" / "encrypt-cli.mjs"

SAME_ORIGIN = {"Sec-Fetch-Site": "same-origin"}
HX = {**SAME_ORIGIN, "HX-Request": "true"}
SENTINEL = "plaintext-sentinel-9f3c"

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
  oak:
    git: git@example.test:org/oak.git
    default_template: default
    default_branch: main
    templates:
      default: {}
"""


@pytest.fixture
def paths(gpg_fleet_home):
    # gpg_fleet_home: short path (gpg-agent socket limit); its agent is killed on teardown.
    p = FleetPaths.from_home(gpg_fleet_home)
    p.registry.parent.mkdir(parents=True, exist_ok=True)
    p.registry.write_text(REGISTRY, encoding="utf-8")
    return p


@pytest.fixture
def client(gpg_fleet_home, paths):
    return TestClient(create_app(gpg_fleet_home), headers=HX)


@pytest.fixture
def host_key(paths):
    return pgp.init_host_key(paths.gnupg, "fleet-test <fleet@example.invalid>")


@pytest.fixture
def foreign_home(gpg_home):
    pgp.init_host_key(gpg_home, "other <other@example.invalid>")
    return gpg_home


def _stored(paths, project="demo"):
    directory = paths.project_secrets / project
    return sorted(p.name for p in directory.iterdir()) if directory.is_dir() else []


def _symmetric_message(home) -> str:
    result = subprocess.run(
        [
            "gpg",
            "--batch",
            "--pinentry-mode",
            "loopback",
            "--passphrase",
            "pw",
            "--symmetric",
            "--armor",
        ],
        env={**os.environ, "GNUPGHOME": str(home)},
        input=SENTINEL.encode(),
        capture_output=True,
        check=True,
    )
    return result.stdout.decode()


def _post(client, armored, key="API_TOKEN", project="demo"):
    return client.post(f"/ui/secrets/{project}", data={"key": key, "armored": armored})


def test_format_fingerprint_groups_by_four_in_uppercase():
    assert format_fingerprint("ab" * 20) == " ".join(["ABAB"] * 10)


# --- page -------------------------------------------------------------------------


def test_page_without_host_key_explains_how_to_create_one(client):
    response = client.get("/secrets")

    assert response.status_code == 200
    assert "sudo -u fleet fleet keys init" in response.text
    assert 'id="secret-form"' not in response.text
    assert "host-public-key" not in response.text


def test_page_with_host_key_shows_form_fingerprint_and_public_key(client, host_key):
    html = client.get("/secrets").text

    assert " ".join(host_key.fingerprint[i : i + 4] for i in range(0, 40, 4)) in html
    assert 'id="host-public-key"' in html
    assert "BEGIN PGP PUBLIC KEY BLOCK" in html
    assert "PRIVATE KEY" not in html
    assert 'hx-post="/ui/secrets/demo"' in html
    assert 'hx-trigger="fleet:encrypted"' in html
    assert '<script type="module" src="/static/secrets.js"></script>' in html


def test_plaintext_input_has_no_name_so_it_is_never_submitted(client, host_key):
    html = client.get("/secrets").text

    (value_tag,) = re.findall(r'<input[^>]*id="secret-value"[^>]*>', html)
    assert " name=" not in value_tag
    assert 'type="password"' in value_tag
    assert 'autocomplete="off"' in value_tag
    (armored_tag,) = re.findall(r'<input[^>]*id="secret-armored"[^>]*>', html)
    assert 'type="hidden"' in armored_tag and 'name="armored"' in armored_tag
    # the no-JS fallback is a harmless GET back to the page, not a POST of anything
    (form_tag,) = re.findall(r'<form[^>]*id="secret-form"[^>]*>', html)
    assert 'method="get"' in form_tag and 'action="/secrets"' in form_tag


def test_page_is_csp_clean(client, host_key):
    html = client.get("/secrets").text
    html = re.sub(r'<pre id="host-public-key".*?</pre>', "", html, flags=re.S)  # base64 noise

    assert "<style" not in html
    assert not re.search(r"\sstyle=", html)
    assert not re.search(r"\son[a-z]+=", html)
    assert not re.search(r"\shx-on", html)
    assert not re.search(r"javascript:", html)
    for tag in re.findall(r"<script\b[^>]*>", html):
        assert "src=" in tag, tag
    bodies = re.findall(r"<script\b[^>]*>(.*?)</script>", html, re.S)
    assert all(body.strip() == "" for body in bodies)


def test_page_project_select_lists_registry_projects_and_honours_the_query(client, host_key):
    first = client.get("/secrets").text
    assert '<option value="demo" selected>' in first and '<option value="oak">' in first

    second = client.get("/secrets", params={"project": "oak"}).text
    assert '<option value="oak" selected>' in second
    assert 'hx-post="/ui/secrets/oak"' in second


def test_unknown_project_page_is_404(client):
    assert client.get("/secrets", params={"project": "ghost"}).status_code == 404


def test_main_navigation_links_to_the_secrets_screen(client):
    assert 'href="/secrets"' in client.get("/").text


def test_scripts_reference_only_ids_the_page_provides(client, host_key):
    html = client.get("/secrets").text
    form_js = (STATIC / "secrets-form.mjs").read_text(encoding="utf-8")
    entry_js = (STATIC / "secrets.js").read_text(encoding="utf-8")

    ids = set(re.findall(r'querySelector\("#([\w-]+)"\)', form_js))
    ids |= set(re.findall(r'\.id [!=]== "([\w-]+)"', entry_js))
    assert {"secret-value", "secret-armored", "host-public-key", "secret-client-error"} <= ids
    assert {"secret-form", "secrets-project"} <= ids
    for element_id in ids:
        assert f'id="{element_id}"' in html, element_id


def test_legacy_plaintext_secrets_are_listed_as_plaintext_without_their_values(client, paths):
    paths.project_secrets.mkdir(parents=True, exist_ok=True)
    (paths.project_secrets / "demo.env").write_text(f"OLD_KEY={SENTINEL}\n", encoding="utf-8")

    html = client.get("/secrets").text

    assert "OLD_KEY" in html and "plaintext" in html
    assert SENTINEL not in html


# --- set --------------------------------------------------------------------------


def test_valid_ciphertext_is_stored_listed_and_never_rendered(client, paths, host_key):
    armored = pgp.encrypt(paths.gnupg, SENTINEL)

    response = _post(client, armored)

    assert response.status_code == 200
    assert "API_TOKEN" in response.text and 'hx-swap-oob="true"' in response.text
    assert SENTINEL not in response.text
    assert _stored(paths) == ["API_TOKEN.asc"]
    stored = paths.project_secrets / "demo" / "API_TOKEN.asc"
    assert stat.S_IMODE(stored.stat().st_mode) == 0o600
    assert SENTINEL not in stored.read_text(encoding="utf-8")
    assert SecretStore(paths).read_all("demo")["API_TOKEN"] == SENTINEL
    for page in (client.get("/secrets").text, client.get("/ui/secrets/demo").text):
        assert "API_TOKEN" in page and SENTINEL not in page


def test_rejected_bodies_return_400_store_nothing_and_are_never_echoed(
    client, paths, host_key, foreign_home
):
    bodies = {
        "plaintext": SENTINEL,
        "public key block": host_key.armored_public_key,
        "truncated armor": "-----BEGIN PGP MESSAGE-----\n\nwV4D",
        "wrong recipient": pgp.encrypt(foreign_home, SENTINEL),
        "password only": _symmetric_message(foreign_home),
    }
    for label, armored in bodies.items():
        response = _post(client, armored)
        assert response.status_code == 400, label
        assert 'class="error-panel"' in response.text, label
        assert SENTINEL not in response.text, label
        assert "wV4D" not in response.text, label
        assert "BEGIN PGP PUBLIC KEY BLOCK" not in response.text, label
    assert _stored(paths) == []


def test_error_is_json_for_a_non_htmx_request(client, paths, host_key):
    response = client.post(
        "/ui/secrets/demo",
        data={"key": "API_TOKEN", "armored": SENTINEL},
        headers={"HX-Request": "false"},
    )

    assert response.status_code == 400
    assert "error" in response.json() and SENTINEL not in response.text


@pytest.mark.parametrize("key", ["lower", "1BAD", "HAS-DASH", "A" * 65])
def test_invalid_key_names_are_rejected_even_with_valid_ciphertext(client, paths, host_key, key):
    response = _post(client, pgp.encrypt(paths.gnupg, SENTINEL), key=key)

    assert response.status_code == 400
    assert _stored(paths) == []


def test_posting_without_a_host_key_is_a_400_that_names_the_fix(client, paths):
    armored = "-----BEGIN PGP MESSAGE-----\n\nAA==\n-----END PGP MESSAGE-----\n"

    response = _post(client, armored)

    assert response.status_code == 400
    assert "fleet keys init" in response.text
    assert _stored(paths) == []


def test_unknown_project_is_404_and_creates_nothing(client, paths, host_key):
    armored = pgp.encrypt(paths.gnupg, SENTINEL)

    assert _post(client, armored, project="ghost").status_code == 404
    assert client.get("/ui/secrets/ghost").status_code == 404
    assert client.post("/ui/secrets/ghost/API_TOKEN/delete").status_code == 404
    assert not (paths.project_secrets / "ghost").exists()


def test_cross_site_post_is_rejected_before_anything_is_stored(gpg_fleet_home, paths, host_key):
    bare = TestClient(create_app(gpg_fleet_home))
    armored = pgp.encrypt(paths.gnupg, SENTINEL)

    response = bare.post(
        "/ui/secrets/demo",
        data={"key": "API_TOKEN", "armored": armored},
        headers={"Sec-Fetch-Site": "cross-site"},
    )

    assert response.status_code == 403
    assert _stored(paths) == []


# --- delete -----------------------------------------------------------------------


def test_delete_removes_the_secret_and_a_second_delete_is_404(client, paths, host_key):
    SecretStore(paths).set("demo", "API_TOKEN", SENTINEL)
    page = client.get("/secrets").text
    assert 'hx-post="/ui/secrets/demo/API_TOKEN/delete"' in page
    assert "hx-confirm=" in page

    first = client.post("/ui/secrets/demo/API_TOKEN/delete")
    second = client.post("/ui/secrets/demo/API_TOKEN/delete")

    assert first.status_code == 200 and "Deleted" in first.text
    assert _stored(paths) == []
    assert second.status_code == 404


def test_delete_with_an_invalid_key_name_is_400(client, host_key):
    assert client.post("/ui/secrets/demo/bad-key/delete").status_code == 400


# --- browser -> gpg, through the real routes ---------------------------------------


@pytest.mark.usefixtures("requires_node")
def test_openpgpjs_ciphertext_is_stored_and_decrypts_byte_exact(client, paths, host_key, tmp_path):
    # leading/trailing space, CRLF and a lone LF, non-ASCII: nothing may be normalised
    value = " pa$$ wörd 日本\r\nline two\nline three "
    key_file = tmp_path / "host.asc"
    key_file.write_text(host_key.armored_public_key, encoding="utf-8")
    armored = subprocess.run(
        ["node", str(ENCRYPT_CLI), str(key_file)],
        input=value.encode("utf-8"),
        capture_output=True,
        check=True,
    ).stdout.decode("utf-8")

    response = _post(client, armored)

    assert response.status_code == 200
    assert SecretStore(paths).read_all("demo")["API_TOKEN"] == value
