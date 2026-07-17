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


def test_reload_caddy_uses_caddy_reload_with_no_sudo(tmp_path):
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    caddyfile_path = tmp_path / "Caddyfile"
    caddyauth.reload_caddy(caddyfile_path=caddyfile_path, runner=fake)
    assert fake.calls[0]["cmd"] == ["caddy", "reload", "--config", str(caddyfile_path)]


def test_reload_caddy_raises_caddy_auth_error_on_failure(tmp_path):
    fake = FakeRunner(
        default=RunResult(
            returncode=1, lines=["dial tcp 127.0.0.1:2019: connect: connection refused"]
        )
    )
    with pytest.raises(CaddyAuthError, match="reload manually"):
        caddyauth.reload_caddy(caddyfile_path=tmp_path / "Caddyfile", runner=fake)


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
        f"caddy reload --config {caddyfile_path}": RunResult(returncode=0, lines=[]),
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
        ["caddy", "reload"],
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
    reload_calls = [c for c in fake.calls if c["cmd"][:2] == ["caddy", "reload"]]
    assert reload_calls == []


# --- per-instance auth (fleet.core.caddyauth.enable_instance_auth/etc.) ---


def test_instance_matcher_name_prefixes_with_auth_and_keeps_double_dash():
    """Instance ids are composed as `<project>--<label>` (fleet.core.naming),
    so the matcher name must tolerate a double dash unmodified — verified
    directly against `caddy validate` (v2.8.4, 2026-07-16): a matcher name
    containing `--` parses without error, there is no hyphen-doubling
    restriction in Caddyfile matcher tokens."""
    assert caddyauth.instance_matcher_name("oak--slacktest") == "auth-oak--slacktest"


def test_write_instance_auth_snippet_writes_scoped_matcher_and_basic_auth(tmp_path):
    snippet_dir = tmp_path / "instances"
    path = caddyauth.write_instance_auth_snippet(
        "oak--slacktest",
        "oak--slacktest.fleet.example.test",
        "fleet",
        "$2a$14$fakehash",
        snippet_dir=snippet_dir,
    )
    assert path == snippet_dir / "oak--slacktest.conf"
    content = path.read_text(encoding="utf-8")
    assert content == (
        "@auth-oak--slacktest host oak--slacktest.fleet.example.test\n"
        "basic_auth @auth-oak--slacktest {\n"
        "    fleet $2a$14$fakehash\n"
        "}\n"
    )


def test_write_instance_auth_snippet_scopes_to_the_right_fqdn_only(tmp_path):
    """The host matcher must name exactly this instance's FQDN — not a
    wildcard, not another instance's — so auth on one instance can never
    leak onto (or block) another sharing the `*.<domain>` site block."""
    snippet_dir = tmp_path / "instances"
    caddyauth.write_instance_auth_snippet(
        "demo--one",
        "demo--one.fleet.example.test",
        "fleet",
        "$2a$14$hash1",
        snippet_dir=snippet_dir,
    )
    caddyauth.write_instance_auth_snippet(
        "demo--two",
        "demo--two.fleet.example.test",
        "fleet",
        "$2a$14$hash2",
        snippet_dir=snippet_dir,
    )
    one = (snippet_dir / "demo--one.conf").read_text(encoding="utf-8")
    two = (snippet_dir / "demo--two.conf").read_text(encoding="utf-8")
    assert "host demo--one.fleet.example.test" in one
    assert "demo--two" not in one
    assert "host demo--two.fleet.example.test" in two
    assert "demo--one" not in two


def test_remove_instance_auth_snippet_returns_true_when_removed(tmp_path):
    snippet_dir = tmp_path / "instances"
    caddyauth.write_instance_auth_snippet(
        "demo--one",
        "demo--one.fleet.example.test",
        "fleet",
        "$2a$14$hash1",
        snippet_dir=snippet_dir,
    )
    assert caddyauth.remove_instance_auth_snippet("demo--one", snippet_dir=snippet_dir) is True
    assert not (snippet_dir / "demo--one.conf").exists()


def test_remove_instance_auth_snippet_returns_false_when_nothing_to_remove(tmp_path):
    snippet_dir = tmp_path / "instances"
    assert caddyauth.remove_instance_auth_snippet("demo--ghost", snippet_dir=snippet_dir) is False


def test_enable_instance_auth_runs_full_pipeline_in_order(tmp_path):
    snippet_dir = tmp_path / "instances"
    caddyfile_path = tmp_path / "Caddyfile"
    scripted = {
        "caddy hash-password --plaintext fleet": RunResult(
            returncode=0, lines=["$2a$14$freshhash"]
        ),
        f"caddy validate --config {caddyfile_path} --adapter caddyfile": RunResult(
            returncode=0, lines=[]
        ),
        f"caddy reload --config {caddyfile_path}": RunResult(returncode=0, lines=[]),
    }
    fake = FakeRunner(scripted=scripted)

    caddyauth.enable_instance_auth(
        "oak--slacktest",
        "oak--slacktest.fleet.example.test",
        "fleet",
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=fake,
    )

    content = (snippet_dir / "oak--slacktest.conf").read_text(encoding="utf-8")
    assert "fleet $2a$14$freshhash" in content
    assert [c["cmd"][0:2] for c in fake.calls] == [
        ["caddy", "hash-password"],
        ["caddy", "validate"],
        ["caddy", "reload"],
    ]


def test_enable_instance_auth_raises_and_does_not_reload_on_validate_failure(tmp_path):
    snippet_dir = tmp_path / "instances"
    caddyfile_path = tmp_path / "Caddyfile"
    scripted = {
        "caddy hash-password --plaintext fleet": RunResult(
            returncode=0, lines=["$2a$14$freshhash"]
        ),
        f"caddy validate --config {caddyfile_path} --adapter caddyfile": RunResult(
            returncode=1, lines=["broken config"]
        ),
    }
    fake = FakeRunner(scripted=scripted)

    with pytest.raises(CaddyAuthError, match="NOT reloaded"):
        caddyauth.enable_instance_auth(
            "oak--slacktest",
            "oak--slacktest.fleet.example.test",
            "fleet",
            snippet_dir=snippet_dir,
            caddyfile_path=caddyfile_path,
            runner=fake,
        )

    # snippet was written (matches rotate()'s documented never-silently-
    # public-but-undetected contract) but reload was never attempted
    assert (snippet_dir / "oak--slacktest.conf").exists()
    reload_calls = [c for c in fake.calls if c["cmd"][:2] == ["caddy", "reload"]]
    assert reload_calls == []


def test_disable_instance_auth_removes_snippet_and_reloads(tmp_path):
    snippet_dir = tmp_path / "instances"
    caddyfile_path = tmp_path / "Caddyfile"
    caddyauth.write_instance_auth_snippet(
        "oak--slacktest",
        "oak--slacktest.fleet.example.test",
        "fleet",
        "$2a$14$hash",
        snippet_dir=snippet_dir,
    )
    scripted = {
        f"caddy validate --config {caddyfile_path} --adapter caddyfile": RunResult(
            returncode=0, lines=[]
        ),
        f"caddy reload --config {caddyfile_path}": RunResult(returncode=0, lines=[]),
    }
    fake = FakeRunner(scripted=scripted)

    caddyauth.disable_instance_auth(
        "oak--slacktest", snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
    )

    assert not (snippet_dir / "oak--slacktest.conf").exists()
    assert [c["cmd"][0:2] for c in fake.calls] == [["caddy", "validate"], ["caddy", "reload"]]


def test_disable_instance_auth_is_a_noop_when_nothing_to_remove(tmp_path):
    """Destroying/deploying-with-auth-off an instance that never had auth
    enabled must not trigger a pointless validate/reload."""
    snippet_dir = tmp_path / "instances"
    caddyfile_path = tmp_path / "Caddyfile"
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))

    caddyauth.disable_instance_auth(
        "demo--ghost", snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
    )

    assert fake.calls == []
