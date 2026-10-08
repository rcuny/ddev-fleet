import os
import stat
from pathlib import Path

import pytest

from fleet.core import pgp
from fleet.core.errors import FleetError
from fleet.core.instances import FleetPaths
from fleet.core.pgp import PgpError
from fleet.core.secrets import read_secrets, write_secret
from fleet.core.secretstore import SecretStore, SecretStoreError
from tests.fakegpg import FakeGpg, fake_armor, packets_output


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


# --- set_armored (the browser path) -----------------------------------------


def test_set_armored_stores_the_inspected_message_as_is(paths):
    store, gpg = _keyed(paths)
    armored = fake_armor("browser-secret")

    store.set_armored("demo", "API_TOKEN", armored)

    asc = paths.project_secrets / "demo" / "API_TOKEN.asc"
    assert asc.read_text(encoding="utf-8") == armored
    assert _mode(asc) == 0o600
    assert any("--list-packets" in call for call in gpg.calls)
    assert store.read_all("demo") == {"API_TOKEN": "browser-secret"}


def test_set_armored_rejects_and_writes_nothing(paths):
    gpg = FakeGpg(packets=packets_output(keyids=("1111111111111111",)))
    gpg.install_key(paths.gnupg)
    store = SecretStore(paths, gpg=gpg)

    with pytest.raises(PgpError, match="not encrypted to this host"):
        store.set_armored("demo", "API_TOKEN", fake_armor("x"))

    assert not (paths.project_secrets / "demo").exists()


def test_set_armored_without_a_host_key_points_at_keys_init(paths):
    store = SecretStore(paths, gpg=FakeGpg())
    with pytest.raises(SecretStoreError, match="fleet keys init"):
        store.set_armored("demo", "API_TOKEN", fake_armor("x"))


def test_set_armored_validates_the_key_name(paths):
    store, _ = _keyed(paths)
    with pytest.raises(SecretStoreError):
        store.set_armored("demo", "bad-name", fake_armor("x"))


def test_set_armored_replaces_a_legacy_plaintext_key(paths):
    SecretStore(paths, gpg=FakeGpg()).set("demo", "API_TOKEN", "old-plain")
    store, _ = _keyed(paths)
    store.set_armored("demo", "API_TOKEN", fake_armor("new"))
    assert not (paths.project_secrets / "demo.env").exists()
    assert store.read_all("demo") == {"API_TOKEN": "new"}


# --- migrate ------------------------------------------------------------------


def _legacy(paths, project="demo", **values):
    plain = SecretStore(paths, gpg=FakeGpg())
    for key, value in values.items():
        plain.set(project, key, value)


def test_migrate_encrypts_every_legacy_key_and_removes_the_env(paths):
    _legacy(paths, A="one", B="two")
    store, _ = _keyed(paths)

    assert store.migrate("demo") == 2

    assert not (paths.project_secrets / "demo.env").exists()
    assert store.names("demo") == [("A", "encrypted"), ("B", "encrypted")]
    assert store.read_all("demo") == {"A": "one", "B": "two"}


def test_migrate_without_a_legacy_file_is_a_noop(paths):
    store, gpg = _keyed(paths)
    assert store.migrate("demo") == 0
    assert not any("--encrypt" in call for call in gpg.calls)


def test_migrate_without_a_host_key_leaves_the_env_untouched(paths):
    _legacy(paths, A="one")
    store = SecretStore(paths, gpg=FakeGpg())
    with pytest.raises(SecretStoreError, match="fleet keys init"):
        store.migrate("demo")
    assert read_secrets(paths.project_secrets / "demo.env") == {"A": "one"}


def test_migrate_refuses_invalid_legacy_names_and_changes_nothing(paths):
    env = paths.project_secrets / "demo.env"
    env.parent.mkdir(parents=True, exist_ok=True)
    env.write_text("GOOD=1\nlower_case=2\n", encoding="utf-8")
    store, _ = _keyed(paths)

    with pytest.raises(SecretStoreError, match="lower_case"):
        store.migrate("demo")

    assert env.read_text(encoding="utf-8") == "GOOD=1\nlower_case=2\n"
    assert not (paths.project_secrets / "demo").exists()


def test_migrate_verifies_by_decrypting_and_aborts_before_writing(paths):
    _legacy(paths, A="one")
    gpg = FakeGpg(decrypt_result="WRONG")
    gpg.install_key(paths.gnupg)
    store = SecretStore(paths, gpg=gpg)

    with pytest.raises(SecretStoreError, match="verification failed"):
        store.migrate("demo")

    assert read_secrets(paths.project_secrets / "demo.env") == {"A": "one"}
    assert not (paths.project_secrets / "demo").exists()


def test_migrate_does_not_overwrite_an_existing_asc_with_an_older_legacy_value(paths):
    store, _ = _keyed(paths)
    store.set("demo", "A", "new-encrypted")
    write_secret(paths.project_secrets / "demo.env", "A", "old-legacy")
    write_secret(paths.project_secrets / "demo.env", "B", "legacy-b")

    assert store.migrate("demo") == 1  # only B was newly encrypted

    assert store.read_all("demo") == {"A": "new-encrypted", "B": "legacy-b"}
    assert not (paths.project_secrets / "demo.env").exists()


def test_migrate_with_a_corrupt_existing_asc_loses_nothing(paths):
    store, gpg = _keyed(paths)
    write_secret(paths.project_secrets / "demo.env", "A", "legacy-a")
    write_secret(paths.project_secrets / "demo.env", "B", "legacy-b")
    asc_dir = paths.project_secrets / "demo"
    asc_dir.mkdir(parents=True)
    (asc_dir / "A.asc").write_text("not a message\n", encoding="utf-8")
    env_before = (paths.project_secrets / "demo.env").read_text(encoding="utf-8")

    with pytest.raises(SecretStoreError, match="existing encrypted A is unreadable") as excinfo:
        store.migrate("demo")

    assert "legacy-a" not in excinfo.value.message and "not a message" not in excinfo.value.message
    assert (paths.project_secrets / "demo.env").read_text(encoding="utf-8") == env_before
    assert sorted(p.name for p in asc_dir.iterdir()) == ["A.asc"]
    assert (asc_dir / "A.asc").read_text(encoding="utf-8") == "not a message\n"


def test_migrate_with_an_unreadable_existing_asc_loses_nothing(paths):
    store, _ = _keyed(paths)
    write_secret(paths.project_secrets / "demo.env", "A", "legacy-a")
    asc_dir = paths.project_secrets / "demo"
    asc_dir.mkdir(parents=True)
    (asc_dir / "A.asc").write_bytes(b"\xff\xfe\x00")

    with pytest.raises(SecretStoreError, match="existing encrypted A is unreadable"):
        store.migrate("demo")

    assert (paths.project_secrets / "demo.env").exists()


def test_real_gpg_migrate_with_a_corrupt_existing_asc_loses_nothing(gpg_fleet_home):
    paths = FleetPaths.from_home(gpg_fleet_home)
    pgp.init_host_key(paths.gnupg, "ddev-fleet test host")
    store = SecretStore(paths)
    write_secret(paths.project_secrets / "demo.env", "TOKEN", "legacy-value")
    asc_dir = paths.project_secrets / "demo"
    asc_dir.mkdir(parents=True)
    (asc_dir / "TOKEN.asc").write_text("garbage\n", encoding="utf-8")

    with pytest.raises(SecretStoreError, match="existing encrypted TOKEN is unreadable"):
        store.migrate("demo")

    assert read_secrets(paths.project_secrets / "demo.env") == {"TOKEN": "legacy-value"}
    assert sorted(p.name for p in asc_dir.iterdir()) == ["TOKEN.asc"]


def test_read_all_with_asc_files_but_no_host_key_points_at_keys_init(paths):
    asc_dir = paths.project_secrets / "demo"
    asc_dir.mkdir(parents=True)
    (asc_dir / "A.asc").write_text(fake_armor("x"), encoding="utf-8")
    gpg = FakeGpg()
    store = SecretStore(paths, gpg=gpg)

    with pytest.raises(PgpError, match="fleet keys init"):
        store.read_all("demo")

    assert gpg.calls == []


def test_secret_project_directory_is_created_0700(paths, monkeypatch):
    modes = []
    real_mkdir = Path.mkdir

    def spy(self, mode=0o777, parents=False, exist_ok=False):
        modes.append((self.name, mode))
        return real_mkdir(self, mode=mode, parents=parents, exist_ok=exist_ok)

    monkeypatch.setattr(Path, "mkdir", spy)
    store, _ = _keyed(paths)
    store.set("demo", "A", "x")
    assert ("demo", 0o700) in modes


def test_migrate_twice_is_idempotent(paths):
    _legacy(paths, A="one")
    store, _ = _keyed(paths)
    assert store.migrate("demo") == 1
    assert store.migrate("demo") == 0
    assert store.read_all("demo") == {"A": "one"}


def test_legacy_projects_lists_valid_named_env_files_sorted(paths):
    root = paths.project_secrets
    root.mkdir(parents=True, exist_ok=True)
    for name in ("zeta.env", "alpha.env", "Bad_Name.env", "notes.txt"):
        (root / name).write_text("A=1\n", encoding="utf-8")
    (root / "dir.env").mkdir()
    (root / "alpha").mkdir()
    store, _ = _keyed(paths)
    assert store.legacy_projects() == ["alpha", "zeta"]


def test_real_gpg_migrate_normalises_quoted_values(gpg_fleet_home):
    paths = FleetPaths.from_home(gpg_fleet_home)
    pgp.init_host_key(paths.gnupg, "ddev-fleet test host")
    env = paths.project_secrets / "demo.env"
    env.parent.mkdir(parents=True, exist_ok=True)
    env.write_text('QUOTED="abc"\nPLAIN=def\n', encoding="utf-8")
    store = SecretStore(paths)

    assert store.migrate("demo") == 2

    assert store.read_all("demo") == {"QUOTED": "abc", "PLAIN": "def"}
    assert not env.exists()
