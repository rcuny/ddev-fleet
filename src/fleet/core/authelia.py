"""Authelia admin account + users.yml management (spec §4.3): argon2id
hashing, atomic writes, and rendering the file-backend `users.yml` from the
registry's per-project `users:` plus the installer admin. Never touches
Caddy or systemd — that's `core/caddyauth.py`'s and Ansible's job.

Hash reuse (see `render_users`): re-hashing on every render would churn
`users.yml`'s content (argon2's hash is salted, so the same plaintext
hashes differently every time) and defeat `write_users`'s
unchanged-content skip, which is what keeps `fleet refresh-auth` from
touching the file (and thus Authelia's file-watcher) when nothing actually
changed. So a user's EXISTING hash is reused whenever it still verifies
against the plaintext currently in fleet.yml; only a changed password (or
a brand new user) gets freshly hashed.
"""

import os
import tempfile
from dataclasses import dataclass
from pathlib import Path

from argon2 import PasswordHasher
from argon2.exceptions import VerifyMismatchError
from ruamel.yaml import YAML

from fleet.core.errors import AutheliaError
from fleet.core.registry import Registry

_yaml = YAML()
_yaml.default_flow_style = False

DEFAULT_ADMIN_PATH = Path("/srv/fleet/authelia/admin.yml")
DEFAULT_USERS_PATH = Path("/srv/fleet/authelia/users.yml")
ADMIN_GROUP = "admins"

_hasher = PasswordHasher()


def hash_password(password: str) -> str:
    """Hash `password` to an argon2id PHC string (e.g.
    `$argon2id$v=19$m=...,t=...,p=...$<salt>$<hash>`) — the format
    Authelia's file authentication backend accepts natively. Salted, so
    hashing the same plaintext twice yields two different strings; use
    `_verify` to check a plaintext against an existing hash, never `==`."""
    return _hasher.hash(password)


def _verify(password: str, existing_hash: str) -> bool:
    try:
        return _hasher.verify(existing_hash, password)
    except VerifyMismatchError:
        return False
    except Exception:  # noqa: BLE001 - a malformed/foreign hash also means "no match"
        return False


@dataclass(frozen=True)
class AdminAccount:
    """The installer's dashboard admin — server-local, mode-agnostic (spec
    D4): `fleet set-admin-password`/`rotate-admin-password` write this in
    BOTH auth modes, but only Authelia mode ever reads it back (to fold it
    into `users.yml`'s `admins` group)."""

    name: str
    password_hash: str


def _atomic_write_yaml(path: Path, data: dict, *, mode: int = 0o640) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_name = tempfile.mkstemp(dir=str(path.parent), prefix=f".{path.name}-", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            _yaml.dump(data, fh)
        os.chmod(tmp_name, mode)
        os.replace(tmp_name, path)
    except BaseException:
        try:
            os.unlink(tmp_name)
        except OSError:
            pass
        raise


def load_admin(path: Path = DEFAULT_ADMIN_PATH) -> AdminAccount | None:
    """Read the installer admin from `path` (`admin.yml`), or `None` if the
    file is missing/empty/incomplete — callers (`fleet refresh-auth`) turn
    that into an actionable "run fleet set-admin-password first" error."""
    if not path.exists():
        return None
    with open(path, "r", encoding="utf-8") as fh:
        data = _yaml.load(fh) or {}
    name = data.get("name")
    password_hash = data.get("password_hash")
    if not name or not password_hash:
        return None
    return AdminAccount(name=str(name), password_hash=str(password_hash))


def set_admin_password(name: str, plaintext: str, *, path: Path = DEFAULT_ADMIN_PATH) -> None:
    """Hash `plaintext` and atomically write `path` (`admin.yml`, mode
    0640). Does NOT touch `users.yml` or Caddy/Authelia — call
    `render_users`/`write_users` (and, in basic mode, `caddyauth.rotate`)
    afterwards to actually apply it; see `cli.py`'s mode-aware
    `set-admin-password`/`rotate-admin-password`."""
    _atomic_write_yaml(path, {"name": name, "password_hash": hash_password(plaintext)})


def _load_existing_hashes(path: Path) -> dict[str, str]:
    if not path.exists():
        return {}
    with open(path, "r", encoding="utf-8") as fh:
        data = _yaml.load(fh) or {}
    users = data.get("users") or {}
    return {
        name: entry["password"]
        for name, entry in users.items()
        if isinstance(entry, dict) and entry.get("password")
    }


def _user_entry(
    name: str, plaintext: str, groups: list[str], existing_hashes: dict[str, str]
) -> dict:
    existing = existing_hashes.get(name)
    password_hash = (
        existing if existing and _verify(plaintext, existing) else hash_password(plaintext)
    )
    return {"displayname": name, "password": password_hash, "groups": groups}


def render_users(
    registry: Registry,
    admin: AdminAccount,
    *,
    existing_path: Path = DEFAULT_USERS_PATH,
) -> dict:
    """Build the full `users.yml` document (Authelia file-backend shape:
    `{"users": {<name>: {displayname, password, groups}}}`) from every
    project user in `registry` plus `admin` (always in the sole `admins`
    group — spec D3). Reuses hashes from `existing_path` where the
    plaintext hasn't changed (see the module docstring). Raises
    AutheliaError if `admin.name` collides with a project user's name —
    the registry can't catch this itself (`_validate_users`, Task 2) since
    it never sees the admin account."""
    existing_hashes = _load_existing_hashes(existing_path)
    records = registry.all_users()

    if any(record.name == admin.name for record in records):
        raise AutheliaError(
            f"admin username {admin.name!r} collides with a project user of the same "
            "name in fleet.yml's users: — rename one of them"
        )

    users_block: dict[str, dict] = {
        record.name: _user_entry(record.name, record.password, list(record.groups), existing_hashes)
        for record in records
    }
    users_block[admin.name] = {
        "displayname": admin.name,
        "password": admin.password_hash,
        "groups": [ADMIN_GROUP],
    }
    return {"users": users_block}


def write_users(data: dict, *, path: Path = DEFAULT_USERS_PATH) -> bool:
    """Atomically write `data` to `path` (`users.yml`, mode 0640) UNLESS
    its current on-disk content already matches — Authelia's file backend
    `watch: true` re-reads on every write, so skipping a no-op write avoids
    pointless watcher churn (and keeps `fleet refresh-auth` silent when
    nothing actually changed). Returns whether a write happened."""
    if path.exists():
        with open(path, "r", encoding="utf-8") as fh:
            current = _yaml.load(fh) or {}
        if current == data:
            return False
    _atomic_write_yaml(path, data)
    return True
