import os
import stat

import pytest

from fleet.core import pgp
from fleet.core.errors import FleetError
from fleet.core.instances import FleetPaths
from fleet.core.pgp import PgpError
from fleet.core.secrets import read_secrets, write_secret
from fleet.core.secretstore import SecretStore, SecretStoreError
from tests.fakegpg import FakeGpg


@pytest.fixture
def paths(fleet_home):
    return FleetPaths.from_home(fleet_home)


def _keyed(paths):
    gpg = FakeGpg()
    gpg.install_key(paths.gnupg)
    return SecretStore(paths, gpg=gpg), gpg


def _mode(path):
    return stat.S_IMODE(path.stat().st_mode)


# --- hosts without a key behave exactly as before ---------------------------


def test_no_key_never_calls_gpg(paths):
    gpg = FakeGpg()
    store = SecretStore(paths, gpg=gpg)

    assert store.encrypted() is False
    assert store.host_key() is None
    assert store.names("demo") == []
    assert store.read_all("demo") == {}
    assert store.set("demo", "SLACK_BOT_TOKEN", "xoxb-1") == "plaintext"
    assert store.read_all("demo") == {"SLACK_BOT_TOKEN": "xoxb-1"}
    assert store.names("demo") == [("SLACK_BOT_TOKEN", "plaintext")]
    assert gpg.calls == []
    legacy = paths.project_secrets / "demo.env"
    assert legacy.read_text(encoding="utf-8") == "SLACK_BOT_TOKEN=xoxb-1\n"
    assert _mode(legacy) == 0o600
    assert not paths.gnupg.exists()  # the check must not create GNUPGHOME


def test_encrypted_false_for_empty_or_missing_gnupg_dir(paths):
    gpg = FakeGpg()
    store = SecretStore(paths, gpg=gpg)
    paths.gnupg.mkdir()
    (paths.gnupg / "private-keys-v1.d").mkdir()
    assert store.encrypted() is False
    assert gpg.calls == []


def test_encrypted_true_with_key_material_and_host_key_is_cached(paths):
    store, gpg = _keyed(paths)
    assert store.encrypted() is True
    assert store.host_key().fingerprint
    before = len(gpg.calls)
    assert store.encrypted() is True
    assert len(gpg.calls) == before


def test_plaintext_set_rejects_newline(paths):
    store = SecretStore(paths, gpg=FakeGpg())
    with pytest.raises(SecretStoreError, match="single line"):
        store.set("demo", "PRIVATE_KEY", "line1\nline2")
    assert not (paths.project_secrets / "demo.env").exists()


# --- validation --------------------------------------------------------------


@pytest.mark.parametrize("key", ["lower", "1ABC", "A-B", "", "A" * 65, "../X", "sk-live-9f8e7d"])
def test_invalid_key_names_are_rejected_without_echoing_them(paths, key):
    store, _ = _keyed(paths)
    with pytest.raises(SecretStoreError) as excinfo:
        store.set("demo", key, "v")
    assert key == "" or key not in str(excinfo.value)


@pytest.mark.parametrize("project", ["../evil", "a/b", "", "UPPER", "a--b"])
def test_unsafe_project_names_are_rejected(paths, project):
    store, _ = _keyed(paths)
    with pytest.raises(FleetError):
        store.set(project, "KEY", "v")
    with pytest.raises(FleetError):
        store.read_all(project)


def test_empty_value_is_rejected(paths):
    store, _ = _keyed(paths)
    with pytest.raises(SecretStoreError, match="empty"):
        store.set("demo", "KEY", "")


# --- encrypted store ---------------------------------------------------------


def test_encrypted_set_writes_a_0600_asc_in_a_0700_dir_and_no_plaintext(paths):
    store, gpg = _keyed(paths)

    assert store.set("demo", "SLACK_BOT_TOKEN", "SENTINEL-enc-1") == "encrypted"

    directory = paths.project_secrets / "demo"
    asc = directory / "SLACK_BOT_TOKEN.asc"
    assert _mode(directory) == 0o700
    assert _mode(asc) == 0o600
    assert asc.read_text(encoding="utf-8").startswith("-----BEGIN PGP MESSAGE-----")
    assert "SENTINEL-enc-1" not in asc.read_text(encoding="utf-8")
    assert sorted(os.listdir(directory)) == ["SLACK_BOT_TOKEN.asc"]  # no temp files left
    assert not (paths.project_secrets / "demo.env").exists()
    assert all("SENTINEL" not in arg for call in gpg.calls for arg in call)
    assert store.read_all("demo") == {"SLACK_BOT_TOKEN": "SENTINEL-enc-1"}


@pytest.mark.parametrize(
    "value", ["line1\nline2", 'a = "q" \t', "  padded  ", "ünïcödé-密码", "k=v=w"]
)
def test_encrypted_value_roundtrips_awkward_characters(paths, value):
    store, _ = _keyed(paths)
    store.set("demo", "KEY", value)
    assert store.read_all("demo") == {"KEY": value}


def test_encrypted_set_removes_the_same_key_from_the_legacy_file(paths):
    plain = SecretStore(paths, gpg=FakeGpg())
    plain.set("demo", "SLACK_BOT_TOKEN", "old")
    plain.set("demo", "OTHER", "keep")
    store, _ = _keyed(paths)

    store.set("demo", "SLACK_BOT_TOKEN", "new")

    assert read_secrets(paths.project_secrets / "demo.env") == {"OTHER": "keep"}
    assert store.names("demo") == [("OTHER", "plaintext"), ("SLACK_BOT_TOKEN", "encrypted")]
    assert store.read_all("demo") == {"OTHER": "keep", "SLACK_BOT_TOKEN": "new"}


def test_encrypted_set_deletes_a_legacy_file_that_becomes_empty(paths):
    SecretStore(paths, gpg=FakeGpg()).set("demo", "A", "old")
    store, _ = _keyed(paths)
    store.set("demo", "A", "new")
    assert not (paths.project_secrets / "demo.env").exists()


def test_asc_wins_over_a_legacy_key(paths):
    store, _ = _keyed(paths)
    store.set("demo", "A", "from-asc")
    write_secret(paths.project_secrets / "demo.env", "A", "from-legacy")
    assert store.read_all("demo") == {"A": "from-asc"}
    assert store.names("demo") == [("A", "encrypted")]


def test_names_ignores_stray_files_in_the_project_dir(paths):
    store, _ = _keyed(paths)
    store.set("demo", "REAL", "v")
    directory = paths.project_secrets / "demo"
    (directory / "notes.txt").write_text("x", encoding="utf-8")
    (directory / "lower.asc").write_text("x", encoding="utf-8")
    (directory / ".secret.tmpabc").write_text("x", encoding="utf-8")
    assert store.names("demo") == [("REAL", "encrypted")]
    assert list(store.read_all("demo")) == ["REAL"]


def test_read_all_corrupt_asc_raises_without_echo(paths):
    store, _ = _keyed(paths)
    store.set("demo", "A", "v")
    asc = paths.project_secrets / "demo" / "A.asc"
    asc.write_text(
        "-----BEGIN PGP MESSAGE-----\n\nbroken-payload\n-----END PGP MESSAGE-----\n",
        encoding="utf-8",
    )
    with pytest.raises(PgpError) as excinfo:
        store.read_all("demo")
    assert "broken-payload" not in str(excinfo.value)


def test_unset_removes_asc_and_legacy_and_reports_whether_anything_changed(paths):
    store, _ = _keyed(paths)
    store.set("demo", "A", "v")
    write_secret(paths.project_secrets / "demo.env", "A", "legacy")
    write_secret(paths.project_secrets / "demo.env", "B", "keep")

    assert store.unset("demo", "A") is True
    assert store.unset("demo", "A") is False
    assert store.names("demo") == [("B", "plaintext")]


# --- real gpg ---------------------------------------------------------------


def test_real_gpg_roundtrip_through_fleet_paths(gpg_fleet_home):
    paths = FleetPaths.from_home(gpg_fleet_home)
    pgp.init_host_key(paths.gnupg, "ddev-fleet test host")
    store = SecretStore(paths)

    assert store.set("demo", "SLACK_BOT_TOKEN", "SENTINEL-real-1\nline2") == "encrypted"

    asc = (paths.project_secrets / "demo" / "SLACK_BOT_TOKEN.asc").read_text(encoding="utf-8")
    assert "SENTINEL" not in asc
    pgp.inspect_message(paths.gnupg, asc)  # a real message for the host subkey
    assert store.read_all("demo") == {"SLACK_BOT_TOKEN": "SENTINEL-real-1\nline2"}


# --- trailing-newline / unreadable-file regressions (review fix round 1) ----------


def test_key_with_trailing_newline_is_rejected_on_a_keyless_host(paths):
    store = SecretStore(paths, gpg=FakeGpg())
    with pytest.raises(SecretStoreError) as excinfo:
        store.set("demo", "FOO\n", "v")
    assert "FOO" not in str(excinfo.value)
    assert not (paths.project_secrets / "demo.env").exists()


def test_key_with_trailing_newline_is_rejected_on_a_keyed_host(paths):
    store, _ = _keyed(paths)
    with pytest.raises(SecretStoreError) as excinfo:
        store.set("demo", "FOO\n", "v")
    assert "FOO" not in str(excinfo.value)
    assert not (paths.project_secrets / "demo").exists()


def test_stray_asc_with_trailing_newline_in_name_is_ignored(paths):
    store, _ = _keyed(paths)
    store.set("demo", "REAL", "v")
    (paths.project_secrets / "demo" / "FOO\n.asc").write_text("x", encoding="utf-8")
    assert store.names("demo") == [("REAL", "encrypted")]
    assert store.read_all("demo") == {"REAL": "v"}


def test_project_with_trailing_newline_is_rejected(paths):
    store, _ = _keyed(paths)
    with pytest.raises(FleetError):
        store.set("demo\n", "KEY", "v")
    with pytest.raises(FleetError):
        store.read_all("demo\n")


def test_read_all_non_utf8_asc_raises_pgp_error_naming_only_the_key(paths):
    store, _ = _keyed(paths)
    store.set("demo", "A", "v")
    (paths.project_secrets / "demo" / "A.asc").write_bytes(b"\xff\xfe\x00bad")
    with pytest.raises(PgpError, match="unreadable encrypted secret A"):
        store.read_all("demo")
