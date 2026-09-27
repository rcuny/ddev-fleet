import pytest

from fleet.core import authelia
from fleet.core.errors import AutheliaError
from fleet.core.registry import Registry


def _registry(fleet_home, extra_projects: str = "") -> Registry:
    (fleet_home / "config").mkdir(parents=True, exist_ok=True)
    text = f"""\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    users:
      - name: fleet
        password: fleet
  fern: &fern
    git: git@example.test:org/fern.git
    users:
      - name: fleet
        password: fleet
      - name: fern
        password: fern
  oak: *fern
{extra_projects}
"""
    path = fleet_home / "fleet.yml"
    path.write_text(text, encoding="utf-8")
    return Registry.load(path)


def test_hash_password_produces_argon2id_phc_string():
    result = authelia.hash_password("s3cret")
    assert result.startswith("$argon2id$")


def test_hash_password_is_nondeterministic_but_both_verify():
    a = authelia.hash_password("s3cret")
    b = authelia.hash_password("s3cret")
    assert a != b  # random salt each time
    assert authelia._verify("s3cret", a)
    assert authelia._verify("s3cret", b)


def test_set_admin_password_then_load_admin_roundtrips(tmp_path):
    path = tmp_path / "authelia" / "admin.yml"
    authelia.set_admin_password("admin", "adminpass", path=path)

    admin = authelia.load_admin(path=path)

    assert admin.name == "admin"
    assert admin.password_hash.startswith("$argon2id$")
    assert authelia._verify("adminpass", admin.password_hash)


def test_load_admin_returns_none_when_file_missing(tmp_path):
    assert authelia.load_admin(path=tmp_path / "does-not-exist.yml") is None


def test_render_users_includes_every_project_user_with_correct_groups(fleet_home):
    registry = _registry(fleet_home)
    admin = authelia.AdminAccount(name="admin", password_hash=authelia.hash_password("adminpass"))

    data = authelia.render_users(
        registry, admin, existing_path=fleet_home / "authelia" / "users.yml"
    )

    users = data["users"]
    assert set(users["fleet"]["groups"]) == {"demo", "fern", "oak"}
    assert set(users["fern"]["groups"]) == {"fern", "oak"}
    assert users["admin"]["groups"] == ["admins"]
    assert users["admin"]["password"] == admin.password_hash


def test_render_users_reuses_existing_hash_when_password_unchanged(fleet_home, tmp_path):
    registry = _registry(fleet_home)
    admin = authelia.AdminAccount(name="admin", password_hash=authelia.hash_password("adminpass"))
    users_path = tmp_path / "users.yml"

    first = authelia.render_users(registry, admin, existing_path=users_path)
    authelia.write_users(first, path=users_path)
    second = authelia.render_users(registry, admin, existing_path=users_path)

    assert second["users"]["fleet"]["password"] == first["users"]["fleet"]["password"]


def test_render_users_rehashes_when_password_changed(fleet_home, tmp_path):
    registry = _registry(fleet_home)
    admin = authelia.AdminAccount(name="admin", password_hash=authelia.hash_password("adminpass"))
    users_path = tmp_path / "users.yml"
    first = authelia.render_users(registry, admin, existing_path=users_path)
    authelia.write_users(first, path=users_path)

    # simulate fleet's password changing in fleet.yml (must change everywhere it appears)
    changed_text = (
        (fleet_home / "fleet.yml").read_text().replace("password: fleet", "password: newpass")
    )
    (fleet_home / "fleet.yml").write_text(changed_text, encoding="utf-8")
    changed_registry = Registry.load(fleet_home / "fleet.yml")

    second = authelia.render_users(changed_registry, admin, existing_path=users_path)
    assert second["users"]["fleet"]["password"] != first["users"]["fleet"]["password"]


def test_render_users_rejects_admin_name_colliding_with_a_project_user(fleet_home):
    registry = _registry(fleet_home)
    admin = authelia.AdminAccount(name="fleet", password_hash=authelia.hash_password("adminpass"))

    with pytest.raises(AutheliaError, match="fleet"):
        authelia.render_users(registry, admin, existing_path=fleet_home / "authelia" / "users.yml")


def test_write_users_skips_write_when_content_unchanged(tmp_path):
    path = tmp_path / "authelia" / "users.yml"
    data = {
        "users": {"fleet": {"displayname": "fleet", "password": "$argon2id$x", "groups": ["demo"]}}
    }

    assert authelia.write_users(data, path=path) is True
    mtime_before = path.stat().st_mtime_ns
    assert authelia.write_users(data, path=path) is False
    assert path.stat().st_mtime_ns == mtime_before


def test_write_users_creates_parent_directory_mode_0640(tmp_path):
    path = tmp_path / "authelia" / "users.yml"
    authelia.write_users({"users": {}}, path=path)
    assert path.exists()
    assert oct(path.stat().st_mode)[-3:] == "640"
