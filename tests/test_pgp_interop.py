"""OpenPGP.js (the vendored file, run by node) <-> GnuPG (run by fleet.core.pgp) interop (FLE-22).

These are the tests that prove a secret typed in the browser is decryptable at
deploy time. They use the real gpg and node binaries; a missing tool skips, or
fails under FLEET_REQUIRE_PGP_TOOLS=1 (see ``tests.conftest.require_tool``).
"""

import os
import subprocess
from pathlib import Path

import pytest

from fleet.core import pgp
from tests.conftest import require_tool

pytestmark = pytest.mark.usefixtures("requires_gpg", "requires_node")

ROOT = Path(__file__).resolve().parents[1]
ENCRYPT_CLI = ROOT / "tests" / "js" / "encrypt-cli.mjs"
FIXTURES = ROOT / "tests" / "fixtures" / "pgp"
FIXTURE_PLAINTEXT = "openpgpjs-interop-fixture-é"


def _gpg(home: Path, *args: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["gpg", "--batch", "--no-tty", *args],
        env={**os.environ, "GNUPGHOME": str(home)},
        capture_output=True,
        check=False,
    )


def node_encrypt(public_key: str, plaintext: str, workdir: Path) -> str:
    key_file = workdir / "host-public.asc"
    key_file.write_text(public_key, encoding="utf-8")
    result = subprocess.run(
        ["node", str(ENCRYPT_CLI), str(key_file)],
        input=plaintext.encode("utf-8"),
        capture_output=True,
        check=False,
    )
    assert result.returncode == 0, result.stderr.decode("utf-8", "replace")
    return result.stdout.decode("utf-8")


@pytest.fixture
def host(gpg_home):
    return gpg_home, pgp.init_host_key(gpg_home, "interop <interop@example.invalid>")


@pytest.fixture
def other_host(gpg_home_factory):
    home = gpg_home_factory()
    return home, pgp.init_host_key(home, "other <other@example.invalid>")


@pytest.fixture
def fixture_home(gpg_home):
    """A gnupg home holding the committed test-only secret key."""
    result = _gpg(
        gpg_home,
        "--pinentry-mode",
        "loopback",
        "--import",
        str(FIXTURES / "test-host-secret.asc"),
    )
    assert result.returncode == 0, result.stderr.decode("utf-8", "replace")
    return gpg_home


def test_gpg_and_node_are_present():
    # Passes when both are installed; with FLEET_REQUIRE_PGP_TOOLS=1 a missing
    # tool fails instead of skipping, so CI cannot go green without the interop.
    require_tool("gpg")
    require_tool("node")


@pytest.mark.parametrize(
    "plaintext",
    [
        "simple-secret-value",
        "multi\nline\nvalue",
        "unicode-é-日本語-\U0001f511",
        "x" * 4000,
        "crlf\r\nvalue",
        "lone\nnewlines\n\n",
        "  padded  ",
    ],
    ids=["simple", "multiline", "unicode", "4kb", "crlf", "trailing-newlines", "padded"],
)
def test_node_encrypted_value_is_accepted_and_decrypted_by_gpg(host, tmp_path, plaintext):
    home, key = host
    armored = node_encrypt(key.armored_public_key, plaintext, tmp_path)

    assert armored.startswith("-----BEGIN PGP MESSAGE-----")
    assert plaintext not in armored
    pgp.inspect_message(home, armored)  # addressed to the host subkey, no password packet
    decrypted = pgp.decrypt(home, armored)
    assert decrypted.encode("utf-8") == plaintext.encode("utf-8")


def test_node_encrypted_value_for_another_host_is_rejected_by_inspection(
    host, other_host, tmp_path
):
    home, _ = host
    other_home, other_key = other_host
    armored = node_encrypt(other_key.armored_public_key, "for-someone-else", tmp_path)

    pgp.inspect_message(other_home, armored)  # positive control: the intended host accepts it
    with pytest.raises(pgp.PgpError, match="not encrypted to this host"):
        pgp.inspect_message(home, armored)


def test_committed_openpgpjs_ciphertext_decrypts_with_gpg(fixture_home):
    armored = (FIXTURES / "interop-message.asc").read_text(encoding="utf-8")

    pgp.inspect_message(fixture_home, armored)
    assert pgp.decrypt(fixture_home, armored) == FIXTURE_PLAINTEXT


def test_committed_ciphertext_uses_the_packets_gnupg_2_2_understands(fixture_home):
    listing = _gpg(fixture_home, "--list-packets", str(FIXTURES / "interop-message.asc"))
    text = listing.stdout.decode("utf-8", "replace")

    assert ":pubkey enc packet:" in text
    assert ":symkey enc packet:" not in text  # no password-only recipient
    assert ":encrypted data packet:" in text
    assert "mdc_method: 2" in text  # SEIPDv1 (MDC), not AEAD/SEIPDv2
