"""Per-project secret store: GnuPG-encrypted `secrets/<project>/<KEY>.asc`
files, with the legacy plaintext `secrets/<project>.env` still read and
`.asc` taking precedence (FLE-21).

Without a host key (`fleet keys init` not run) this behaves exactly like the
legacy store and never calls gpg. Encryption is to the host key only; the
private key never leaves `$FLEET_HOME/gnupg`.
"""

from __future__ import annotations

import os
import re
import tempfile
from pathlib import Path
from typing import TYPE_CHECKING

from fleet.core import pgp
from fleet.core.errors import FleetError, ValidationError
from fleet.core.naming import validate_part
from fleet.core.pgp import GpgRunner, HostKey, run_gpg
from fleet.core.secrets import read_secrets, write_secret

if TYPE_CHECKING:  # instances imports this module; avoid the cycle at runtime
    from fleet.core.instances import FleetPaths

KEY_RE = re.compile(r"^[A-Z][A-Z0-9_]{0,63}$")
ENCRYPTED = "encrypted"
PLAINTEXT = "plaintext"
_ASC_SUFFIX = ".asc"
_NO_KEY = "no host key yet: run `fleet keys init` first"


class SecretStoreError(FleetError):
    """An invalid secret name/value, or an operation that needs a host key."""


def validate_key(key: str) -> None:
    """The error deliberately does not echo `key`: a secret pasted into the
    name argument by mistake must not end up in a terminal log."""
    if not KEY_RE.fullmatch(key):
        raise SecretStoreError(
            "invalid secret name: must match ^[A-Z][A-Z0-9_]{0,63}$ "
            "(uppercase letters, digits and underscores, starting with a letter)"
        )


def _atomic_write(path: Path, text: str) -> None:
    """Write `text` to `path` (0600) via a temp file in the same directory and
    an atomic rename, so a reader never sees a partial file."""
    fd, tmp_name = tempfile.mkstemp(dir=path.parent, prefix=".secret.")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write(text)
        os.chmod(tmp_name, 0o600)
        os.replace(tmp_name, path)
    except BaseException:
        Path(tmp_name).unlink(missing_ok=True)
        raise


class SecretStore:
    def __init__(self, paths: "FleetPaths", *, gpg: GpgRunner = run_gpg) -> None:
        self._paths = paths
        self._gpg = gpg
        self._host_key: HostKey | None = None

    # --- host key ------------------------------------------------------------

    def _has_key_material(self) -> bool:
        keys_dir = self._paths.gnupg / "private-keys-v1.d"
        return keys_dir.is_dir() and any(keys_dir.iterdir())

    def host_key(self) -> HostKey | None:
        if self._host_key is None and self._has_key_material():
            self._host_key = pgp.host_key(self._paths.gnupg, gpg=self._gpg)
        return self._host_key

    def encrypted(self) -> bool:
        return self.host_key() is not None

    @staticmethod
    def _no_key_error() -> SecretStoreError:
        return SecretStoreError(_NO_KEY)

    # --- paths ---------------------------------------------------------------

    def _project_dir(self, project: str) -> Path:
        validate_part(project)
        return self._paths.project_secrets / project

    def _legacy_path(self, project: str) -> Path:
        validate_part(project)
        return self._paths.project_secrets / f"{project}.env"

    def _asc_keys(self, project: str) -> list[str]:
        directory = self._project_dir(project)
        if not directory.is_dir():
            return []
        return sorted(
            path.stem
            for path in directory.glob(f"*{_ASC_SUFFIX}")
            if path.is_file() and KEY_RE.fullmatch(path.stem)
        )

    # --- reads ---------------------------------------------------------------

    def names(self, project: str) -> list[tuple[str, str]]:
        found = {key: PLAINTEXT for key in read_secrets(self._legacy_path(project))}
        found.update({key: ENCRYPTED for key in self._asc_keys(project)})
        return sorted(found.items())

    def read_all(self, project: str) -> dict[str, str]:
        """Every secret of `project` as plaintext. `.asc` files win over legacy
        keys. gpg is only called when at least one `.asc` exists and a host key
        is present."""
        values = dict(read_secrets(self._legacy_path(project)))
        asc_keys = self._asc_keys(project)
        if asc_keys and not self.encrypted():
            raise pgp.PgpError(_NO_KEY)
        for key in asc_keys:
            values[key] = self._read_asc(project, key)
        return values

    def _read_asc(self, project: str, key: str) -> str:
        path = self._project_dir(project) / f"{key}{_ASC_SUFFIX}"
        try:
            armored = path.read_text(encoding="utf-8")
        except (UnicodeDecodeError, OSError):
            raise pgp.PgpError(f"unreadable encrypted secret {key}") from None
        return pgp.decrypt(self._paths.gnupg, armored, gpg=self._gpg)

    # --- writes --------------------------------------------------------------

    def _write_asc(self, project: str, key: str, armored: str) -> None:
        directory = self._project_dir(project)
        self._paths.project_secrets.mkdir(mode=0o700, parents=True, exist_ok=True)
        directory.mkdir(mode=0o700, exist_ok=True)
        os.chmod(directory, 0o700)
        text = armored if armored.endswith("\n") else armored + "\n"
        _atomic_write(directory / f"{key}{_ASC_SUFFIX}", text)

    def _drop_legacy(self, project: str, keys: set[str]) -> bool:
        """Remove `keys` from the legacy file (deleting the file when nothing
        is left). True if the file changed."""
        path = self._legacy_path(project)
        if not path.exists():
            return False
        current = read_secrets(path)
        remaining = {key: value for key, value in current.items() if key not in keys}
        if len(remaining) == len(current):
            return False
        if remaining:
            _atomic_write(path, "".join(f"{k}={v}\n" for k, v in remaining.items()))
        else:
            path.unlink()
        return True

    def set(self, project: str, key: str, value: str) -> str:
        """Store one secret: encrypted when a host key exists, else in the
        legacy plaintext file (inert until `fleet keys init`). Returns
        "encrypted" or "plaintext"."""
        validate_key(key)
        self._project_dir(project)
        if not value:
            raise SecretStoreError("the secret value is empty; nothing stored")
        if not self.encrypted():
            if "\n" in value or "\r" in value:
                raise SecretStoreError(
                    "a plaintext secret must be a single line; run `fleet keys init` "
                    "to store multi-line values encrypted"
                )
            write_secret(self._legacy_path(project), key, value)
            return PLAINTEXT
        armored = pgp.encrypt(self._paths.gnupg, value, gpg=self._gpg)
        self._write_asc(project, key, armored)
        self._drop_legacy(project, {key})
        return ENCRYPTED

    def unset(self, project: str, key: str) -> bool:
        validate_key(key)
        removed = False
        asc = self._project_dir(project) / f"{key}{_ASC_SUFFIX}"
        if asc.exists():
            asc.unlink()
            removed = True
        if self._drop_legacy(project, {key}):
            removed = True
        return removed

    def set_armored(self, project: str, key: str, armored: str) -> None:
        """Browser path: store a message that was encrypted client-side.
        `pgp.inspect_message` runs first and is structural only (it never
        decrypts), so it checks that this is a single armored message for this
        host's key, not that it will decrypt; X"""
        validate_key(key)
        self._project_dir(project)
        if not self.encrypted():
            raise self._no_key_error()
        pgp.inspect_message(self._paths.gnupg, armored, gpg=self._gpg)
        self._write_asc(project, key, armored)
        self._drop_legacy(project, {key})

    def legacy_projects(self) -> list[str]:
        """Projects that still have a plaintext `secrets/<project>.env`."""
        root = self._paths.project_secrets
        if not root.is_dir():
            return []
        names: list[str] = []
        for path in sorted(root.glob("*.env")):
            if not path.is_file():
                continue
            try:
                validate_part(path.stem)
            except ValidationError:
                continue
            names.append(path.stem)
        return names

    def migrate(self, project: str) -> int:
        """Encrypt every legacy key of `project`, verify each by decrypting it,
        then delete `<project>.env`. All-or-nothing and re-runnable; returns
        the number of keys newly encrypted. A key that already has an `.asc`
        keeps the encrypted value (the stale legacy one is dropped with the
        file). Old plaintext may linger in disk blocks and backups: rotate."""
        legacy = self._legacy_path(project)
        if not legacy.exists():
            return 0
        if not self.encrypted():
            raise self._no_key_error()
        values = read_secrets(legacy)
        invalid = sorted(key for key in values if not KEY_RE.fullmatch(key))
        if invalid:
            raise SecretStoreError(
                f"cannot migrate {project!r}: legacy names that are not valid secret names "
                f"({', '.join(invalid)}); rename or remove them in {legacy} first"
            )
        already = set(self._asc_keys(project))
        for key in sorted(already & values.keys()):
            # "Encrypted value wins" only if that value is readable: otherwise
            # deleting the legacy file would destroy the only readable copy.
            try:
                self._read_asc(project, key)
            except pgp.PgpError:
                raise SecretStoreError(
                    f"existing encrypted {key} is unreadable; nothing was changed"
                ) from None
        prepared: dict[str, str] = {}
        for key, value in values.items():
            if key in already:
                continue
            armored = pgp.encrypt(self._paths.gnupg, value, gpg=self._gpg)
            if pgp.decrypt(self._paths.gnupg, armored, gpg=self._gpg) != value:
                raise SecretStoreError(
                    f"verification failed while migrating {key}; nothing was changed"
                )
            prepared[key] = armored
        for key, armored in prepared.items():
            self._write_asc(project, key, armored)
        legacy.unlink()
        return len(prepared)
