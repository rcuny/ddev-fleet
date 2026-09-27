"""Regression tests for the `authelia` Ansible role's rendered artefacts.

Covers two real bugs found by a live integration run against the actual
Caddy/Authelia binaries (.claude/user/tmp/authelia-integration/results.md
in the companion repo, not shipped here):

1. `configuration.yml.j2` had a plain YAML `#` comment containing a
   literal, unescaped `{{ secret "path" }}` — Jinja (and, confirmed
   separately, a real `ansible.builtin.template` task) fails to parse the
   whole file over it. `ansible-playbook site.yml`/`authelia.yml` would
   fail outright the first time this role's template task ran, in
   authelia mode, on every host.
2. The systemd drop-in wrote `X_AUTHELIA_*_FILE` environment variables for
   Authelia's secret-loading mechanism. Authelia 4.39 reserves the
   `X_AUTHELIA_` prefix for a small, fixed set of meta/behavioural vars
   (`X_AUTHELIA_CONFIG`, ..._HEALTHCHECK*) — none secret-related. Generic
   `<KEY_PATH>_FILE` secret loading uses the plain `AUTHELIA_` prefix. With
   the wrong prefix, `storage.encryption_key` (no default, always
   required) came back unset and Authelia refused to start every time.
"""

import os
import re
import shutil
import subprocess
import tempfile
from pathlib import Path

import pytest
from jinja2 import Environment, FileSystemLoader, StrictUndefined
from ruamel.yaml import YAML

ROLE_DIR = Path(__file__).resolve().parents[1] / "ansible" / "roles" / "authelia"
TEMPLATE_DIR = ROLE_DIR / "templates"
TASKS_FILE = ROLE_DIR / "tasks" / "main.yml"

_yaml = YAML(typ="safe")


def _render_configuration_yml() -> str:
    # Configured the way Ansible's own Templar configures its Jinja2
    # environment for `ansible.builtin.template` (trim_blocks=True,
    # StrictUndefined so a missing var fails loudly instead of silently
    # rendering empty) — this is what actually caught bug 1 in the
    # integration run, not a hand-rolled lenient environment.
    env = Environment(
        loader=FileSystemLoader(str(TEMPLATE_DIR)),
        trim_blocks=True,
        undefined=StrictUndefined,
        keep_trailing_newline=True,
    )
    return env.get_template("configuration.yml.j2").render(
        fleet_srv_dir="/srv/fleet",
        fleet_domain="fleet.example.test",
    )


def test_configuration_yml_renders_and_parses_as_valid_yaml():
    """Bug 1 regression: the template must render under a strict,
    trim_blocks Jinja2 environment (matching ansible.builtin.template's own
    configuration) and produce valid, expected-shape YAML. Before the fix,
    this raised `jinja2.exceptions.TemplateSyntaxError: expected token 'end
    of print statement', got 'string'` on the comment at (then) line 15."""
    rendered = _render_configuration_yml()
    data = _yaml.load(rendered)

    assert data["authentication_backend"]["file"]["path"] == "/srv/fleet/authelia/users.yml"
    assert data["authentication_backend"]["file"]["watch"] is True
    assert data["session"]["cookies"][0]["domain"] == "fleet.example.test"
    assert data["session"]["cookies"][0]["authelia_url"] == "https://auth.fleet.example.test"
    assert data["storage"]["local"]["path"] == "/srv/fleet/authelia/db.sqlite3"
    assert data["access_control"]["default_policy"] == "deny"

    # The secrets fix (bug 2) is a sibling concern to this file: confirm
    # configuration.yml itself carries NO inline secret keys (they are
    # supplied entirely via the AUTHELIA_*_FILE env vars asserted below).
    assert "encryption_key" not in data["storage"]
    assert "secret" not in data["session"]
    assert "identity_validation" not in data


def test_configuration_yml_never_contains_a_literal_double_brace():
    """Belt-and-suspenders: whatever comment wording changes in the future,
    the rendered output must never contain a literal `{{` — that is
    exactly what broke bug 1 (a YAML "#" comment isn't a Jinja comment;
    Jinja parses the whole file before Authelia ever sees it)."""
    rendered = _render_configuration_yml()
    assert "{{" not in rendered
    assert "}}" not in rendered


def _secrets_conf_content() -> str:
    """Extract the systemd drop-in's rendered `content:` block from the
    role's tasks/main.yml by loading it as real Ansible task YAML (not a
    regex over the raw file) — this is exactly what
    `ansible.builtin.copy`'s `content:` argument would receive."""
    tasks = _yaml.load(TASKS_FILE.read_text(encoding="utf-8"))
    for task in tasks:
        if task.get("name") == "Point authelia.service at the secrets directory":
            return task["ansible.builtin.copy"]["content"]
    raise AssertionError(
        "could not find the 'Point authelia.service at the secrets directory' task"
    )


def test_secrets_conf_env_vars_use_authelia_prefix_not_x_authelia():
    """Bug 2 regression: every Environment= line in the systemd drop-in
    must use the plain AUTHELIA_ prefix. The exact three names below were
    verified directly against the real authelia v4.39.28 binary
    (`authelia config validate`) in the integration run — X_AUTHELIA_ silently
    leaves storage.encryption_key unset and Authelia refuses to start."""
    content = _secrets_conf_content()

    assert (
        "X_AUTHELIA_" not in content
    ), "the reserved X_AUTHELIA_ meta-var prefix must never be used for secrets"

    env_lines = [
        line.strip() for line in content.splitlines() if line.strip().startswith("Environment=")
    ]
    assert env_lines, "expected at least one Environment= line in the secrets drop-in"
    for line in env_lines:
        assert re.match(r"^Environment=AUTHELIA_[A-Z_]+_FILE=", line), line

    expected = {
        "Environment=AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET_FILE="
        "/etc/authelia/secrets/reset_password_jwt_secret",
        "Environment=AUTHELIA_SESSION_SECRET_FILE=/etc/authelia/secrets/session_secret",
        "Environment=AUTHELIA_STORAGE_ENCRYPTION_KEY_FILE=/etc/authelia/secrets/storage_encryption_key",
    }
    assert set(env_lines) == expected


def _find_authelia_bin() -> str | None:
    return os.environ.get("FLEET_TEST_AUTHELIA_BIN") or shutil.which("authelia")


@pytest.mark.skipif(
    not _find_authelia_bin(),
    reason="no authelia binary available (set FLEET_TEST_AUTHELIA_BIN or install authelia on PATH)",
)
def test_configuration_yml_validates_against_real_authelia_with_authelia_prefixed_secrets():
    """End-to-end: render configuration.yml.j2, supply the three secrets
    via files, set the exact AUTHELIA_*_FILE env vars the fixed systemd
    drop-in now uses, and run the real `authelia config validate` (the
    correct 4.39 subcommand — `authelia config validate --config <path>`,
    confirmed via `authelia config --help` against the real binary; NOT
    `authelia validate-config`). Must pass with zero errors."""
    authelia_bin = _find_authelia_bin()
    rendered = _render_configuration_yml()

    with tempfile.TemporaryDirectory() as tmp:
        tmp_path = Path(tmp)
        (tmp_path / "srv" / "authelia").mkdir(parents=True)
        (tmp_path / "srv" / "authelia" / "users.yml").write_text("users: {}\n", encoding="utf-8")

        secrets_dir = tmp_path / "secrets"
        secrets_dir.mkdir()
        secret_files = {
            "reset_password_jwt_secret": "jwt-secret-1234567890123456789012345678901",
            "session_secret": "sess-secret-1234567890123456789012345678",
            "storage_encryption_key": "enc-key-12345678901234567890123456789012",
        }
        for name, value in secret_files.items():
            (secrets_dir / name).write_text(value, encoding="utf-8")

        config_path = tmp_path / "configuration.yml"
        # Point the rendered config's paths at this tempdir instead of the
        # real /srv/fleet, so the check is fully self-contained.
        config_path.write_text(
            rendered.replace("/srv/fleet/authelia", str(tmp_path / "srv" / "authelia")),
            encoding="utf-8",
        )

        env = dict(os.environ)
        env["AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET_FILE"] = str(
            secrets_dir / "reset_password_jwt_secret"
        )
        env["AUTHELIA_SESSION_SECRET_FILE"] = str(secrets_dir / "session_secret")
        env["AUTHELIA_STORAGE_ENCRYPTION_KEY_FILE"] = str(secrets_dir / "storage_encryption_key")

        result = subprocess.run(
            [authelia_bin, "config", "validate", "--config", str(config_path)],
            capture_output=True,
            text=True,
            env=env,
            timeout=30,
        )
        assert result.returncode == 0, result.stdout + result.stderr
        assert "successfully" in result.stdout.lower()
