import re
import stat
import subprocess

import pytest

from fleet.core import pgp
from fleet.core.pgp import MAX_MESSAGE_BYTES, GpgResult, HostKey, PgpError, run_gpg
from tests.conftest import require_tool
from tests.fakegpg import (
    FINGERPRINT,
    PUBLIC_KEY,
    SECRET_KEYS_COLONS,
    SUBKEY_ID,
    FakeGpg,
    fake_armor,
    packets_output,
)

# --- the reusable tool requirement ------------------------------------------


def test_require_tool_skips_when_missing(monkeypatch):
    monkeypatch.setenv("PATH", "")
    monkeypatch.delenv("FLEET_REQUIRE_PGP_TOOLS", raising=False)
    with pytest.raises(pytest.skip.Exception):
        require_tool("gpg")


def test_require_tool_fails_instead_of_skipping_when_required(monkeypatch):
    monkeypatch.setenv("PATH", "")
    monkeypatch.setenv("FLEET_REQUIRE_PGP_TOOLS", "1")
    with pytest.raises(pytest.fail.Exception):
        require_tool("gpg")


# --- run_gpg -----------------------------------------------------------------


def test_run_gpg_invokes_gpg_with_an_isolated_home(monkeypatch, tmp_path):
    seen = {}

    def fake_run(cmd, **kwargs):
        seen["cmd"], seen["kwargs"] = cmd, kwargs
        return subprocess.CompletedProcess(cmd, 0, stdout=b"out", stderr=b"err")

    monkeypatch.setattr(pgp.subprocess, "run", fake_run)
    home = tmp_path / "gnupg"

    result = run_gpg(["--version"], gnupghome=home, input_bytes=b"in")

    assert result == GpgResult(0, b"out", "err")
    assert seen["cmd"] == ["gpg", "--batch", "--no-tty", "--version"]
    assert seen["kwargs"]["env"]["GNUPGHOME"] == str(home)
    assert seen["kwargs"]["input"] == b"in"
    assert seen["kwargs"]["capture_output"] is True
    assert stat.S_IMODE(home.stat().st_mode) == 0o700


def test_run_gpg_missing_binary_is_a_pgp_error(monkeypatch, tmp_path):
    def boom(cmd, **kwargs):
        raise FileNotFoundError("gpg")

    monkeypatch.setattr(pgp.subprocess, "run", boom)
    with pytest.raises(PgpError, match="gpg is not installed"):
        run_gpg(["--version"], gnupghome=tmp_path / "g")


# --- host_key / init_host_key with the fake ---------------------------------


def test_host_key_parses_the_colon_listing(tmp_path):
    key = pgp.host_key(tmp_path, gpg=FakeGpg(has_key=True))
    assert key == HostKey(FINGERPRINT, SUBKEY_ID, PUBLIC_KEY)


def test_host_key_is_none_without_a_secret_key(tmp_path):
    assert pgp.host_key(tmp_path, gpg=FakeGpg()) is None


def test_host_key_ignores_a_revoked_encryption_subkey(tmp_path):
    listing = SECRET_KEYS_COLONS.replace("ssb:u:", "ssb:r:")
    assert pgp.host_key(tmp_path, gpg=FakeGpg(has_key=True, secret_listing=listing)) is None


def test_host_key_requires_an_encryption_capable_subkey(tmp_path):
    listing = SECRET_KEYS_COLONS.replace("::::::e:::+::cv25519::", "::::::s:::+::ed25519::")
    assert pgp.host_key(tmp_path, gpg=FakeGpg(has_key=True, secret_listing=listing)) is None


def test_init_host_key_makes_ed25519_primary_and_cv25519_subkey_without_passphrase(tmp_path):
    gpg = FakeGpg()

    key = pgp.init_host_key(tmp_path, "fleet host", gpg=gpg)

    gen = next(c for c in gpg.calls if "--quick-gen-key" in c)
    assert gen[: gen.index("--quick-gen-key")] == [
        "--pinentry-mode",
        "loopback",
        "--passphrase",
        "",
    ]
    assert gen[gen.index("--quick-gen-key") :] == [
        "--quick-gen-key",
        "fleet host",
        "ed25519",
        "sign,cert",
        "never",
    ]
    add = next(c for c in gpg.calls if "--quick-add-key" in c)
    assert add[add.index("--quick-add-key") :] == [
        "--quick-add-key",
        FINGERPRINT,
        "cv25519",
        "encr",
        "never",
    ]
    assert key.fingerprint == FINGERPRINT


def test_init_host_key_refuses_when_a_key_exists(tmp_path):
    gpg = FakeGpg(has_key=True)
    with pytest.raises(PgpError, match="already"):
        pgp.init_host_key(tmp_path, "fleet host", gpg=gpg)
    assert not any("--quick-gen-key" in c for c in gpg.calls)


@pytest.mark.parametrize("uid", ["", "   ", "two\nlines", "bell\x07"])
def test_init_host_key_rejects_unusable_uids(tmp_path, uid):
    with pytest.raises(PgpError, match="user id"):
        pgp.init_host_key(tmp_path, uid, gpg=FakeGpg())


def test_init_host_key_reports_a_failing_gpg_without_a_traceback_payload(tmp_path):
    def failing(args, *, gnupghome, input_bytes=None):
        if "--list-secret-keys" in args:
            return GpgResult(0, b"", "")
        return GpgResult(2, b"", "gpg: agent_genkey failed:   No pinentry\n")

    with pytest.raises(PgpError, match="agent_genkey failed: No pinentry"):
        pgp.init_host_key(tmp_path, "fleet host", gpg=failing)


# --- real gpg ---------------------------------------------------------------


def test_host_key_is_none_on_a_fresh_home(gpg_home):
    assert pgp.host_key(gpg_home) is None


def test_init_host_key_with_real_gpg(gpg_home):
    key = pgp.init_host_key(gpg_home, "ddev-fleet test host")

    assert re.fullmatch(r"[0-9A-F]{40}", key.fingerprint)
    assert re.fullmatch(r"[0-9A-F]{16}", key.encryption_subkey_id)
    assert key.armored_public_key.startswith("-----BEGIN PGP PUBLIC KEY BLOCK-----")
    assert pgp.host_key(gpg_home) == key
    with pytest.raises(PgpError, match="already"):
        pgp.init_host_key(gpg_home, "second attempt")


GOOD = "-----BEGIN PGP MESSAGE-----\n\nQUJD\n=AAAA\n-----END PGP MESSAGE-----\n"
OTHER_KEYID = "1111111111111111"


# --- encrypt / decrypt with the fake ----------------------------------------


def test_encrypt_sends_plaintext_on_stdin_only_and_never_in_argv(tmp_path):
    gpg = FakeGpg(has_key=True)

    armored = pgp.encrypt(tmp_path, "SENTINEL-argv-check", gpg=gpg)

    assert armored.startswith("-----BEGIN PGP MESSAGE-----")
    enc = next(c for c in gpg.calls if "--encrypt" in c)
    assert enc == ["--trust-model", "always", "--armor", "--encrypt", "--recipient", FINGERPRINT]
    assert all("SENTINEL" not in arg for call in gpg.calls for arg in call)


def test_encrypt_without_a_host_key_points_at_keys_init(tmp_path):
    with pytest.raises(PgpError, match="fleet keys init"):
        pgp.encrypt(tmp_path, "x", gpg=FakeGpg())


def test_decrypt_returns_plaintext_from_stdout(tmp_path):
    assert pgp.decrypt(tmp_path, fake_armor("hello"), gpg=FakeGpg(has_key=True)) == "hello"


def test_decrypt_failure_does_not_echo_the_input(tmp_path):
    bad = "-----BEGIN PGP MESSAGE-----\n\nTOP-SECRET-LOOKING-PAYLOAD\n-----END PGP MESSAGE-----\n"
    with pytest.raises(PgpError) as excinfo:
        pgp.decrypt(tmp_path, bad, gpg=FakeGpg(has_key=True))
    assert "TOP-SECRET" not in str(excinfo.value)
    assert "decryption failed" in str(excinfo.value)


# --- inspect_message --------------------------------------------------------


def _inspect(tmp_path, armored=GOOD, **fake_kwargs):
    gpg = FakeGpg(has_key=True, **fake_kwargs)
    pgp.inspect_message(tmp_path, armored, gpg=gpg)
    return gpg


def test_inspect_accepts_a_message_for_the_host_subkey_and_never_decrypts(tmp_path):
    gpg = _inspect(tmp_path, packets=packets_output())
    listing = next(c for c in gpg.calls if "--list-packets" in c)
    assert "--list-only" in listing
    assert not any("--decrypt" in c for c in gpg.calls)


def test_inspect_accepts_a_message_for_several_recipients_including_the_host(tmp_path):
    _inspect(tmp_path, packets=packets_output(keyids=(OTHER_KEYID, SUBKEY_ID)))


NOT_A_MESSAGE = [
    "",
    "hello",
    "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nx\n-----END PGP PUBLIC KEY BLOCK-----\n",
    GOOD + GOOD,
    GOOD.replace("-----END PGP MESSAGE-----", ""),
]


@pytest.mark.parametrize("armored", NOT_A_MESSAGE)
def test_inspect_rejects_non_message_input_before_calling_gpg(tmp_path, armored):
    gpg = FakeGpg(has_key=True)
    with pytest.raises(PgpError, match="ASCII-armored PGP MESSAGE"):
        pgp.inspect_message(tmp_path, armored, gpg=gpg)
    assert not any("--list-packets" in c for c in gpg.calls)


def test_inspect_rejects_an_oversize_body(tmp_path):
    body = "A" * MAX_MESSAGE_BYTES
    armored = f"-----BEGIN PGP MESSAGE-----\n\n{body}\n-----END PGP MESSAGE-----\n"
    gpg = FakeGpg(has_key=True)
    with pytest.raises(PgpError, match="larger than"):
        pgp.inspect_message(tmp_path, armored, gpg=gpg)
    assert not any("--list-packets" in c for c in gpg.calls)


REJECTED_PACKETS = [
    (packets_output(keyids=(OTHER_KEYID,)), "not encrypted to this host"),
    (packets_output(keyids=()), "not encrypted to this host"),
    (packets_output(symkey=True), "password"),
    (packets_output(keyids=(SUBKEY_ID,), symkey=True), "password"),
    (packets_output(trailing_tags=(11,)), "unexpected"),
]


@pytest.mark.parametrize(("packets", "message"), REJECTED_PACKETS)
def test_inspect_rejects_wrong_recipient_password_only_and_extra_packets(
    tmp_path, packets, message
):
    with pytest.raises(PgpError, match=message) as excinfo:
        _inspect(tmp_path, packets=packets)
    assert "QUJD" not in str(excinfo.value)  # the armored body is never echoed


def test_inspect_rejects_when_gpg_cannot_parse_the_packets(tmp_path):
    with pytest.raises(PgpError, match="not a valid OpenPGP message"):
        _inspect(tmp_path, packets="", packets_returncode=2)


def test_inspect_requires_a_host_key(tmp_path):
    with pytest.raises(PgpError, match="fleet keys init"):
        pgp.inspect_message(tmp_path, GOOD, gpg=FakeGpg())


# --- real gpg ---------------------------------------------------------------


@pytest.fixture
def host_home(gpg_home):
    pgp.init_host_key(gpg_home, "ddev-fleet test host")
    return gpg_home


@pytest.mark.parametrize(
    "value",
    ["plain", "line1\nline2", 'a = "quoted" \t', "  spaces  ", "ünïcödé-密码", ""],
)
def test_real_roundtrip_is_byte_exact(host_home, value):
    armored = pgp.encrypt(host_home, value)
    assert armored.startswith("-----BEGIN PGP MESSAGE-----")
    assert not value or value not in armored  # "" is trivially a substring
    assert pgp.decrypt(host_home, armored) == value


def test_real_inspect_accepts_our_own_ciphertext(host_home):
    pgp.inspect_message(host_home, pgp.encrypt(host_home, "SENTINEL-x"))


def test_real_inspect_rejects_a_message_for_another_key(host_home, gpg_home_factory):
    other = gpg_home_factory()
    pgp.init_host_key(other, "someone else")
    with pytest.raises(PgpError, match="not encrypted to this host"):
        pgp.inspect_message(host_home, pgp.encrypt(other, "x"))


def _symmetric(home, *extra):
    return run_gpg(
        ["--pinentry-mode", "loopback", "--passphrase", "pw", "--armor", "--symmetric", *extra],
        gnupghome=home,
        input_bytes=b"x",
    ).stdout.decode()


def test_real_inspect_rejects_password_only_and_mixed_messages(host_home):
    with pytest.raises(PgpError, match="password|not encrypted to this host"):
        pgp.inspect_message(host_home, _symmetric(host_home))
    key = pgp.host_key(host_home)
    mixed = _symmetric(
        host_home, "--trust-model", "always", "--encrypt", "--recipient", key.fingerprint
    )
    with pytest.raises(PgpError, match="password"):
        pgp.inspect_message(host_home, mixed)


def test_real_inspect_and_decrypt_reject_garbage_without_echo(host_home):
    junk = "-----BEGIN PGP MESSAGE-----\n\nAAAA-NOT-REAL\n=AAAA\n-----END PGP MESSAGE-----\n"
    with pytest.raises(PgpError) as inspected:
        pgp.inspect_message(host_home, junk)
    with pytest.raises(PgpError) as decrypted:
        pgp.decrypt(host_home, junk)
    assert "AAAA-NOT-REAL" not in str(inspected.value) + str(decrypted.value)
