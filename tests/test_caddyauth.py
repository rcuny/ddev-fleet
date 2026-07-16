import pytest

from fleet.core import caddyauth
from fleet.core.errors import CaddyAuthError
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def test_hash_password_invokes_caddy_hash_password_with_plaintext_flag():
    fake = FakeRunner(default=RunResult(returncode=0, lines=["$2a$14$fakehashvalue"]))
    result = caddyauth.hash_password("s3cret", runner=fake)
    assert result == "$2a$14$fakehashvalue"
    assert fake.calls[0]["cmd"] == ["caddy", "hash-password", "--plaintext", "s3cret"]


def test_hash_password_skips_blank_lines_and_returns_first_nonblank():
    fake = FakeRunner(default=RunResult(returncode=0, lines=["", "  ", "$2a$14$xyz", ""]))
    assert caddyauth.hash_password("s3cret", runner=fake) == "$2a$14$xyz"


def test_hash_password_raises_caddy_auth_error_on_nonzero_exit():
    fake = FakeRunner(default=RunResult(returncode=1, lines=["command not found"]))
    with pytest.raises(CaddyAuthError, match="caddy hash-password"):
        caddyauth.hash_password("s3cret", runner=fake)


def test_hash_password_raises_when_no_output_produced():
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    with pytest.raises(CaddyAuthError, match="no output"):
        caddyauth.hash_password("s3cret", runner=fake)


def test_write_admin_auth_snippet_writes_expected_content(tmp_path):
    snippet_path = tmp_path / "fleet" / "admin-auth.conf"
    caddyauth.write_admin_auth_snippet("admin", "$2a$14$fakehash", snippet_path=snippet_path)
    assert snippet_path.read_text(encoding="utf-8") == "admin $2a$14$fakehash\n"


def test_write_admin_auth_snippet_creates_parent_directory(tmp_path):
    snippet_path = tmp_path / "does" / "not" / "exist" / "admin-auth.conf"
    caddyauth.write_admin_auth_snippet("admin", "$2a$14$fakehash", snippet_path=snippet_path)
    assert snippet_path.exists()


def test_write_admin_auth_snippet_is_atomic_leaves_no_tmp_file_behind(tmp_path):
    snippet_dir = tmp_path / "fleet"
    snippet_dir.mkdir()
    snippet_path = snippet_dir / "admin-auth.conf"
    caddyauth.write_admin_auth_snippet("admin", "$2a$14$fakehash", snippet_path=snippet_path)
    leftover = [p for p in snippet_dir.iterdir() if p.name != "admin-auth.conf"]
    assert leftover == []


def test_write_admin_auth_snippet_overwrites_existing_file(tmp_path):
    snippet_path = tmp_path / "admin-auth.conf"
    snippet_path.write_text("admin $2a$14$oldhash\n", encoding="utf-8")
    caddyauth.write_admin_auth_snippet("admin", "$2a$14$newhash", snippet_path=snippet_path)
    assert snippet_path.read_text(encoding="utf-8") == "admin $2a$14$newhash\n"


def test_validate_caddyfile_composes_correct_argv(tmp_path):
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    caddyfile_path = tmp_path / "Caddyfile"
    caddyauth.validate_caddyfile(caddyfile_path=caddyfile_path, runner=fake)
    assert fake.calls[0]["cmd"] == [
        "caddy",
        "validate",
        "--config",
        str(caddyfile_path),
        "--adapter",
        "caddyfile",
    ]


def test_validate_caddyfile_raises_caddy_auth_error_on_failure(tmp_path):
    fake = FakeRunner(
        default=RunResult(returncode=1, lines=["Caddyfile:12: unrecognized directive"])
    )
    with pytest.raises(CaddyAuthError, match="NOT reloaded"):
        caddyauth.validate_caddyfile(caddyfile_path=tmp_path / "Caddyfile", runner=fake)


def test_reload_caddy_uses_sudo_systemctl_reload():
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    caddyauth.reload_caddy(runner=fake)
    assert fake.calls[0]["cmd"] == ["sudo", "systemctl", "reload", "caddy"]


def test_reload_caddy_raises_caddy_auth_error_on_failure():
    fake = FakeRunner(default=RunResult(returncode=1, lines=["permission denied"]))
    with pytest.raises(CaddyAuthError, match="reload manually"):
        caddyauth.reload_caddy(runner=fake)


def test_rotate_runs_full_pipeline_in_order(tmp_path):
    snippet_path = tmp_path / "admin-auth.conf"
    caddyfile_path = tmp_path / "Caddyfile"
    scripted = {
        "caddy hash-password --plaintext newpass": RunResult(
            returncode=0, lines=["$2a$14$freshhash"]
        ),
        f"caddy validate --config {caddyfile_path} --adapter caddyfile": RunResult(
            returncode=0, lines=[]
        ),
        "sudo systemctl reload caddy": RunResult(returncode=0, lines=[]),
    }
    fake = FakeRunner(scripted=scripted)

    caddyauth.rotate(
        "admin",
        "newpass",
        snippet_path=snippet_path,
        caddyfile_path=caddyfile_path,
        runner=fake,
    )

    assert snippet_path.read_text(encoding="utf-8") == "admin $2a$14$freshhash\n"
    assert [c["cmd"][0:2] for c in fake.calls] == [
        ["caddy", "hash-password"],
        ["caddy", "validate"],
        ["sudo", "systemctl"],
    ]


def test_rotate_does_not_reload_when_validate_fails(tmp_path):
    snippet_path = tmp_path / "admin-auth.conf"
    caddyfile_path = tmp_path / "Caddyfile"
    scripted = {
        "caddy hash-password --plaintext newpass": RunResult(
            returncode=0, lines=["$2a$14$freshhash"]
        ),
        f"caddy validate --config {caddyfile_path} --adapter caddyfile": RunResult(
            returncode=1, lines=["broken config"]
        ),
    }
    fake = FakeRunner(scripted=scripted)

    with pytest.raises(CaddyAuthError, match="NOT reloaded"):
        caddyauth.rotate(
            "admin",
            "newpass",
            snippet_path=snippet_path,
            caddyfile_path=caddyfile_path,
            runner=fake,
        )

    # the snippet WAS written (that's expected — see module docstring), but
    # reload was never attempted
    assert snippet_path.exists()
    reload_calls = [c for c in fake.calls if c["cmd"][:2] == ["sudo", "systemctl"]]
    assert reload_calls == []
