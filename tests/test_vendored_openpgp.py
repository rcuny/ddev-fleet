"""The vendored OpenPGP.js (FLE-22): pinned bytes, licence, docs row, MIME type, packaging."""

import hashlib
import mimetypes
import re
from pathlib import Path

from fastapi.testclient import TestClient

from fleet.daemon import create_app

ROOT = Path(__file__).resolve().parents[1]
STATIC = ROOT / "src" / "fleet" / "static"
DOC = (ROOT / "docs" / "vendored-assets.md").read_text(encoding="utf-8")

OPENPGP_VERSION = "6.3.2"
OPENPGP_BYTES = 395_333
OPENPGP_SHA256 = "7d3285efa6dfedbb34a136d8b5ad21c28fb973269df0b2818dcb74dfb40b59d9"
LICENSE_SHA256 = "e3a994d82e644b03a792a930f574002658412f62407f5fee083f2555c5f23118"
TARBALL_INTEGRITY = (
    "sha512-wcZTzHz41LV8Y48zH/JlD1JT8YdNmpWOuMAjQ/podvvCwsjBEU37K"
    "3znJh4UQcj3hksE5z9TzvhbUbwzsGvlLQ=="
)


def _sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def test_openpgp_bundle_is_byte_identical_to_the_pinned_release():
    bundle = STATIC / "openpgp.min.mjs"
    assert bundle.stat().st_size == OPENPGP_BYTES
    assert _sha256(bundle) == OPENPGP_SHA256, (
        "openpgp.min.mjs changed: re-vendor it from the npm tarball, verify dist.integrity, "
        "then update the pins here and in docs/vendored-assets.md"
    )
    first_line = bundle.read_text(encoding="utf-8").splitlines()[0]
    assert f"OpenPGP.js v{OPENPGP_VERSION}" in first_line


def test_openpgp_licence_text_ships_next_to_the_bundle():
    licence = STATIC / "openpgp.LICENSE"
    assert _sha256(licence) == LICENSE_SHA256
    assert "GNU LESSER GENERAL PUBLIC LICENSE" in licence.read_text(encoding="utf-8")


def test_vendored_assets_doc_records_the_pins_and_the_licence():
    assert OPENPGP_SHA256 in DOC
    assert TARBALL_INTEGRITY in DOC
    assert re.search(
        rf"\|\s*openpgp\.min\.mjs\s*\|\s*openpgp\s*\|\s*{re.escape(OPENPGP_VERSION)}\s*\|", DOC
    )
    assert "LGPL-3.0-or-later" in DOC


def test_mjs_is_registered_as_javascript():
    assert mimetypes.guess_type("x.mjs")[0] == "text/javascript"


def test_static_modules_are_served_with_a_javascript_content_type(fleet_home):
    client = TestClient(create_app(fleet_home))
    for name in ("openpgp.min.mjs", "secrets-crypto.mjs", "secrets-form.mjs", "secrets.js"):
        response = client.get(f"/static/{name}")
        assert response.status_code == 200, name
        assert response.headers["content-type"].startswith("text/javascript"), name
    assert client.get("/static/openpgp.LICENSE").status_code == 200


def test_every_static_file_is_top_level_so_the_package_data_glob_ships_it():
    # pyproject: package-data fleet = ["static/*", ...] does not recurse.
    assert [p for p in STATIC.iterdir() if p.is_dir()] == []


def test_client_side_encryption_doc_exists_and_is_linked_from_the_readme():
    doc = (ROOT / "docs" / "client-side-encryption.md").read_text(encoding="utf-8")
    for needle in (
        "## Threat model",
        "## Data flow",
        "OpenPGP version 4",
        "Content-Security-Policy",
        OPENPGP_SHA256,
        "root on the host",
        "fleet keys init",
    ):
        assert needle in doc, needle
    assert "docs/client-side-encryption.md" in (ROOT / "README.md").read_text(encoding="utf-8")
