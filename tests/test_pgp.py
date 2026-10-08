import re
import stat
import subprocess

import pytest

from fleet.core import pgp
from fleet.core.pgp import GpgResult, HostKey, PgpError, run_gpg
from tests.conftest import require_tool
from tests.fakegpg import (
    FINGERPRINT,
    PUBLIC_KEY,
    SECRET_KEYS_COLONS,
    SUBKEY_ID,
    FakeGpg,
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
