import os
import shutil
import subprocess

import pytest

from fleet.core import caddyauth
from fleet.core.errors import CaddyAuthError, ValidationError
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner

# Optional real-`caddy` validation of the rendered snippets — skipped when no
# `caddy` binary is reachable (CI/dev boxes without it). Looks on PATH first,
# then the local e2e scratch dir a prior session verified against
# (`.claude/user/tmp/authelia-e2e/caddy`, v2.11.4) — see findings.md in that
# directory for the syntax this pins.
_CADDY_BIN = shutil.which("caddy") or next(
    (
        p
        for p in ("/var/www/html/.claude/user/tmp/authelia-e2e/caddy",)
        if os.path.isfile(p) and os.access(p, os.X_OK)
    ),
    None,
)


def _caddy_validate(caddyfile_path):
    result = subprocess.run(
        [_CADDY_BIN, "validate", "--config", str(caddyfile_path), "--adapter", "caddyfile"],
        capture_output=True,
        text=True,
    )
    return result


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


def test_enable_instance_auth_uses_password_as_username(tmp_path):
    snippet_dir = tmp_path / "instances"
    caddyfile_path = tmp_path / "Caddyfile"
    scripted = {
        "caddy hash-password --plaintext fern": RunResult(returncode=0, lines=["$2a$14$fernhash"]),
        f"caddy validate --config {caddyfile_path} --adapter caddyfile": RunResult(
            returncode=0, lines=[]
        ),
        f"caddy reload --config {caddyfile_path}": RunResult(returncode=0, lines=[]),
    }
    caddyauth.enable_instance_auth(
        "oak--client",
        "oak--client.fleet.example.test",
        "fern",
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=FakeRunner(scripted=scripted),
    )
    content = (snippet_dir / "oak--client.conf").read_text(encoding="utf-8")
    assert "    fern $2a$14$fernhash\n" in content
    assert "fleet" not in content.replace("fleet.example.test", "")


@pytest.mark.parametrize(
    "bad", ["", "two words", 'q"uote', "br{ace", "back\\slash", "#hash", "tab\tx"]
)
def test_validate_instance_credential_rejects_non_token_values(bad):
    with pytest.raises(ValidationError):
        caddyauth.validate_instance_credential(bad)


@pytest.mark.parametrize("good", ["fleet", "fern", "Client-2026!", "a#b"])
def test_validate_instance_credential_accepts_single_words(good):
    caddyauth.validate_instance_credential(good)


# --- Authelia mode (mode-aware enable_instance_auth + the forward_auth
# snippet) ---


def test_write_instance_authelia_snippet_strips_client_headers_and_forwards(tmp_path):
    snippet_dir = tmp_path / "instances"

    caddyauth.write_instance_authelia_snippet(
        "demo--main", "demo--main.fleet.example.test", "demo", snippet_dir=snippet_dir
    )

    content = (snippet_dir / "demo--main.conf").read_text(encoding="utf-8")
    assert "request_header -Remote-User" in content
    assert "request_header -Remote-Groups" in content
    assert "request_header -Remote-Name" in content
    assert "request_header -Remote-Email" in content
    assert "forward_auth 127.0.0.1:9091 {" in content
    assert "uri /api/authz/forward-auth" in content
    assert "copy_headers Remote-User Remote-Groups Remote-Name Remote-Email" in content


def test_write_instance_authelia_snippet_wraps_sequence_in_a_route_block_in_order(tmp_path):
    """Per findings.md: Caddy reorders bare top-level directives by
    precedence, which runs the header strips AFTER forward_auth and
    silently deletes the Remote-Groups header forward_auth just set. The
    whole sequence must be wrapped in `route @matcher { ... }`, with the
    strip directives appearing (in source order, which `route` preserves)
    before `forward_auth`."""
    snippet_dir = tmp_path / "instances"

    caddyauth.write_instance_authelia_snippet(
        "demo--main", "demo--main.fleet.example.test", "demo", snippet_dir=snippet_dir
    )

    content = (snippet_dir / "demo--main.conf").read_text(encoding="utf-8")
    assert "route @auth-demo--main {" in content
    strip_pos = content.index("request_header -Remote-Groups")
    forward_auth_pos = content.index("forward_auth")
    assert strip_pos < forward_auth_pos


def test_write_instance_authelia_snippet_requires_project_or_admins_group(tmp_path):
    snippet_dir = tmp_path / "instances"

    caddyauth.write_instance_authelia_snippet(
        "fern--main", "fern--main.fleet.example.test", "fern", snippet_dir=snippet_dir
    )

    content = (snippet_dir / "fern--main.conf").read_text(encoding="utf-8")
    assert "Remote-Groups" in content
    assert "fern" in content
    assert "admins" in content
    assert "403" in content


def test_write_instance_authelia_snippet_scopes_host_matcher_and_aliases(tmp_path):
    snippet_dir = tmp_path / "instances"

    caddyauth.write_instance_authelia_snippet(
        "oak--main",
        "oak--main.fleet.example.test",
        "oak",
        alias_fqdns=["es-oak--main.fleet.example.test"],
        snippet_dir=snippet_dir,
    )

    content = (snippet_dir / "oak--main.conf").read_text(encoding="utf-8")
    assert "oak--main.fleet.example.test" in content
    assert "es-oak--main.fleet.example.test" in content


def test_enable_instance_auth_basic_mode_is_unchanged_default(tmp_path):
    snippet_dir = tmp_path / "instances"
    caddyfile_path = tmp_path / "Caddyfile"
    scripted = {
        "caddy hash-password --plaintext fleet": RunResult(returncode=0, lines=["$2a$14$hash"]),
        f"caddy validate --config {caddyfile_path} --adapter caddyfile": RunResult(
            returncode=0, lines=[]
        ),
        f"caddy reload --config {caddyfile_path}": RunResult(returncode=0, lines=[]),
    }
    caddyauth.enable_instance_auth(
        "demo--main",
        "demo--main.fleet.example.test",
        "fleet",
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=FakeRunner(scripted=scripted),
    )
    content = (snippet_dir / "demo--main.conf").read_text(encoding="utf-8")
    assert "basic_auth" in content
    assert "forward_auth" not in content


def test_enable_instance_auth_authelia_mode_writes_forward_auth_snippet_no_hashing(tmp_path):
    snippet_dir = tmp_path / "instances"
    caddyfile_path = tmp_path / "Caddyfile"
    scripted = {
        f"caddy validate --config {caddyfile_path} --adapter caddyfile": RunResult(
            returncode=0, lines=[]
        ),
        f"caddy reload --config {caddyfile_path}": RunResult(returncode=0, lines=[]),
    }
    fake = FakeRunner(scripted=scripted)

    caddyauth.enable_instance_auth(
        "demo--main",
        "demo--main.fleet.example.test",
        "",
        auth_mode="authelia",
        project="demo",
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=fake,
    )

    content = (snippet_dir / "demo--main.conf").read_text(encoding="utf-8")
    assert "forward_auth" in content
    assert "basic_auth" not in content
    # never shells out to hash a password in authelia mode
    hash_calls = [c for c in fake.calls if c["cmd"][:2] == ["caddy", "hash-password"]]
    assert hash_calls == []
    assert [c["cmd"][0:2] for c in fake.calls] == [["caddy", "validate"], ["caddy", "reload"]]


def test_enable_instance_auth_authelia_mode_requires_project():
    with pytest.raises(CaddyAuthError, match="project"):
        caddyauth.enable_instance_auth(
            "demo--main", "demo--main.fleet.example.test", "", auth_mode="authelia"
        )


@pytest.mark.skipif(_CADDY_BIN is None, reason="no 'caddy' binary reachable")
def test_write_instance_authelia_snippet_validates_with_real_caddy(tmp_path):
    """Adapt/validate the rendered snippet inside a Caddyfile shaped like the
    real `*.{{ fleet_domain }}` site (Caddyfile.j2) — proves the exact
    `route @matcher { ... }` syntax findings.md pins is still accepted by a
    real `caddy validate`, not just plausible-looking text."""
    snippet_dir = tmp_path / "instances"
    caddyauth.write_instance_authelia_snippet(
        "demo--main",
        "demo--main.fleet.example.test",
        "demo",
        alias_fqdns=["es-demo--main.fleet.example.test"],
        snippet_dir=snippet_dir,
    )

    caddyfile_path = tmp_path / "Caddyfile"
    caddyfile_path.write_text(
        "{\n    admin off\n}\n\n"
        "*.fleet.example.test {\n"
        f"    import {snippet_dir}/*.conf\n"
        "    reverse_proxy 127.0.0.1:8080\n"
        "}\n",
        encoding="utf-8",
    )

    result = _caddy_validate(caddyfile_path)
    assert result.returncode == 0, result.stderr


@pytest.mark.skipif(_CADDY_BIN is None, reason="no 'caddy' binary reachable")
def test_write_instance_auth_snippet_basic_mode_validates_with_real_caddy(tmp_path):
    """Same real-`caddy` validation for the basic-auth snippet, so a
    regression in either mode's syntax is caught the same way."""
    snippet_dir = tmp_path / "instances"
    caddyauth.write_instance_auth_snippet(
        "demo--main",
        "demo--main.fleet.example.test",
        "fleet",
        "$2a$14$fakehash",
        snippet_dir=snippet_dir,
    )

    caddyfile_path = tmp_path / "Caddyfile"
    caddyfile_path.write_text(
        "{\n    admin off\n}\n\n"
        "*.fleet.example.test {\n"
        f"    import {snippet_dir}/*.conf\n"
        "    reverse_proxy 127.0.0.1:8080\n"
        "}\n",
        encoding="utf-8",
    )

    result = _caddy_validate(caddyfile_path)
    assert result.returncode == 0, result.stderr


# --- alias hosts (additional_hostnames) must be covered by the SAME
# matcher as the bare instance FQDN ---


def test_instance_snippet_one_line_form_lists_alias_hosts(tmp_path):
    snippet_dir = tmp_path / "instances"

    caddyauth.write_instance_auth_snippet(
        "oak--main",
        "oak--main.fleet.example.test",
        "fleet",
        "$2a$14$hash",
        alias_fqdns=["es-oak--main.fleet.example.test", "news-oak--main.fleet.example.test"],
        snippet_dir=snippet_dir,
    )

    assert (snippet_dir / "oak--main.conf").read_text(encoding="utf-8") == (
        "@auth-oak--main host oak--main.fleet.example.test "
        "es-oak--main.fleet.example.test news-oak--main.fleet.example.test\n"
        "basic_auth @auth-oak--main {\n"
        "    fleet $2a$14$hash\n"
        "}\n"
    )


def test_instance_snippet_without_alias_fqdns_keeps_bare_host(tmp_path):
    """No `additional_hostnames` for the project — default empty
    `alias_fqdns` — must not change the existing one-host output."""
    snippet_dir = tmp_path / "instances"

    caddyauth.write_instance_auth_snippet(
        "oak--main",
        "oak--main.fleet.example.test",
        "fleet",
        "$2a$14$hash",
        snippet_dir=snippet_dir,
    )

    content = (snippet_dir / "oak--main.conf").read_text(encoding="utf-8")
    assert content.startswith("@auth-oak--main host oak--main.fleet.example.test\n")


def test_enable_instance_auth_passes_alias_fqdns_through(tmp_path):
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

    caddyauth.enable_instance_auth(
        "oak--main",
        "oak--main.fleet.example.test",
        "fleet",
        alias_fqdns=["es-oak--main.fleet.example.test"],
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=FakeRunner(scripted=scripted),
    )

    content = (snippet_dir / "oak--main.conf").read_text(encoding="utf-8")
    assert "es-oak--main.fleet.example.test" in content


# --- provisioned snippet dirs must never be created on the fly ---


def test_ensure_snippet_dir_creates_unmanaged_dirs(tmp_path):
    target = tmp_path / "instances"

    caddyauth.ensure_snippet_dir(target)

    assert target.is_dir()


def test_ensure_snippet_dir_create_false_does_not_create_unmanaged_dir(tmp_path):
    """`create=False` (used by the pre-destroy preflight in core/instances.py)
    must be a pure read — no mkdir, even for an unmanaged (tests/local) dir."""
    target = tmp_path / "instances"

    caddyauth.ensure_snippet_dir(target, create=False)

    assert not target.exists()


def test_ensure_snippet_dir_refuses_missing_managed_dir(tmp_path, monkeypatch):
    monkeypatch.setattr(caddyauth, "MANAGED_SNIPPET_ROOT", tmp_path)
    missing = tmp_path / "fleet" / "instances"

    with pytest.raises(CaddyAuthError) as exc:
        caddyauth.ensure_snippet_dir(missing)

    assert "install -d -o fleet -g caddy -m 2750" in str(exc.value)
    assert not missing.exists()


def test_ensure_snippet_dir_refuses_managed_dir_without_setgid(tmp_path, monkeypatch):
    monkeypatch.setattr(caddyauth, "MANAGED_SNIPPET_ROOT", tmp_path)
    target = tmp_path / "fleet" / "instances"
    target.mkdir(parents=True)
    target.chmod(0o750)

    with pytest.raises(CaddyAuthError) as exc:
        caddyauth.ensure_snippet_dir(target)

    assert "setgid" in str(exc.value)


def test_ensure_snippet_dir_accepts_managed_dir_with_setgid(tmp_path, monkeypatch):
    monkeypatch.setattr(caddyauth, "MANAGED_SNIPPET_ROOT", tmp_path)
    target = tmp_path / "fleet" / "instances"
    target.mkdir(parents=True)
    target.chmod(0o2750)

    caddyauth.ensure_snippet_dir(target)


def test_ensure_snippet_dir_create_false_still_guards_managed_dir(tmp_path, monkeypatch):
    """`create=False` only changes the unmanaged branch — the managed-root
    guard (missing dir / lost setgid) was always read-only and must still
    fire exactly the same."""
    monkeypatch.setattr(caddyauth, "MANAGED_SNIPPET_ROOT", tmp_path)
    missing = tmp_path / "fleet" / "instances"

    with pytest.raises(CaddyAuthError):
        caddyauth.ensure_snippet_dir(missing, create=False)


def test_write_instance_snippet_refuses_managed_dir_without_setgid(tmp_path, monkeypatch):
    """The guard fires on the real write path, not just when called directly —
    this is the ddev2 outage (2026-09-14): snippets written into a setgid-less
    dir are unreadable by Caddy, which then fails its next restart."""
    monkeypatch.setattr(caddyauth, "MANAGED_SNIPPET_ROOT", tmp_path)
    snippet_dir = tmp_path / "fleet" / "instances"
    snippet_dir.mkdir(parents=True)
    snippet_dir.chmod(0o750)

    with pytest.raises(CaddyAuthError):
        caddyauth.write_instance_auth_snippet(
            "demo--main",
            "demo--main.fleet.example.test",
            "fleet",
            "$2a$14$hash",
            snippet_dir=snippet_dir,
        )

    assert list(snippet_dir.iterdir()) == []
