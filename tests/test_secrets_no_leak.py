"""FLE-21 acceptance: after `fleet secret set` (stdin) and `fleet secret
migrate`, no file under FLEET_HOME and no captured stdout/stderr contains the
plaintext value."""

import io
from pathlib import Path

from fleet import cli
from fleet.core.instances import FleetPaths
from fleet.core.secrets import write_secret
from fleet.core.secretstore import SecretStore
from tests.test_cli import _write_minimal_registry

SENTINEL_STDIN = "SENTINEL-7f3a91c2-from-stdin"
SENTINEL_LEGACY = "SENTINEL-4be0d6a8-from-legacy-env"


def _hits(root: Path, needle: str) -> list[str]:
    """Relative paths of every regular file under `root` containing `needle`
    (sockets such as gpg-agent's are skipped by is_file())."""
    found = []
    for path in sorted(root.rglob("*")):
        if path.is_file() and not path.is_symlink() and needle.encode() in path.read_bytes():
            found.append(str(path.relative_to(root)))
    return found


def _fleet(home, *argv):
    return cli.main(["--fleet-home", str(home), *argv])


def test_no_plaintext_secret_survives_set_or_migrate(gpg_fleet_home, capsys, monkeypatch):
    home = gpg_fleet_home
    paths = FleetPaths.from_home(home)
    _write_minimal_registry(home)
    write_secret(paths.project_secrets / "demo.env", "LEGACY_TOKEN", SENTINEL_LEGACY)

    # The grep itself works: before migrating, the legacy plaintext is found.
    assert _hits(home, SENTINEL_LEGACY) == ["secrets/demo.env"]

    assert _fleet(home, "keys", "init") == 0
    assert _fleet(home, "secret", "migrate", "demo") == 0
    monkeypatch.setattr("sys.stdin", io.StringIO(SENTINEL_STDIN + "\n"))
    assert _fleet(home, "secret", "set", "demo", "SLACK_BOT_TOKEN") == 0
    assert _fleet(home, "secret", "list", "demo") == 0

    captured = capsys.readouterr()
    for sentinel in (SENTINEL_STDIN, SENTINEL_LEGACY):
        assert sentinel not in captured.out + captured.err
        assert _hits(home, sentinel) == [], f"{sentinel} found in files under FLEET_HOME"

    assert not list(paths.project_secrets.glob("*.env"))
    # ...yet the deploy path still gets the values back.
    assert SecretStore(paths).read_all("demo") == {
        "LEGACY_TOKEN": SENTINEL_LEGACY,
        "SLACK_BOT_TOKEN": SENTINEL_STDIN,
    }


def test_deprecated_positional_value_is_stored_encrypted_and_not_echoed(gpg_fleet_home, capsys):
    home = gpg_fleet_home
    _write_minimal_registry(home)
    assert _fleet(home, "keys", "init") == 0
    capsys.readouterr()

    assert _fleet(home, "secret", "set", "demo", "API_TOKEN", SENTINEL_STDIN) == 0

    captured = capsys.readouterr()
    assert "deprecated" in captured.err
    assert SENTINEL_STDIN not in captured.out + captured.err
    assert _hits(home, SENTINEL_STDIN) == []  # with a host key it is stored encrypted
