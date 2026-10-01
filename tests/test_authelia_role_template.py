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
VARS_FILE = ROLE_DIR / "vars" / "main.yml"

_yaml = YAML(typ="safe")


def _role_vars() -> dict:
    return _yaml.load(VARS_FILE.read_text(encoding="utf-8"))


def _rendered_secret_env() -> dict:
    """The role's authelia_secret_env, with its own {{ authelia_secrets_dir }}
    self-reference resolved — mirrors what Ansible's own templating of role
    vars would produce."""
    role_vars = _role_vars()
    secrets_dir = role_vars["authelia_secrets_dir"]
    return {
        k: v.replace("{{ authelia_secrets_dir }}", secrets_dir)
        for k, v in role_vars["authelia_secret_env"].items()
    }


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
    role_vars = _role_vars()
    return env.get_template("configuration.yml.j2").render(
        fleet_srv_dir="/srv/fleet",
        fleet_domain="fleet.example.test",
        authelia_state_dir=role_vars["authelia_state_dir"],
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
    assert data["storage"]["local"]["path"] == "/var/lib/authelia/db.sqlite3"
    assert data["notifier"]["filesystem"]["filename"] == "/var/lib/authelia/notification.txt"
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

    # Each line's value is a Jinja reference into vars/main.yml's
    # authelia_secret_env (see test_drop_in_and_validate_share_the_same_secret_env_mapping),
    # not a hardcoded literal path — that's the DRY fix for the ddev2 rollout's
    # `authelia config validate` failure below.
    referenced_keys = {
        re.match(
            r"^Environment=([A-Z_]+_FILE)=\{\{ authelia_secret_env\.([A-Z_]+) \}\}$", line
        ).groups()
        for line in env_lines
    }
    assert all(env_var == key for env_var, key in referenced_keys), referenced_keys
    assert {env_var for env_var, _ in referenced_keys} == set(_rendered_secret_env().keys())


def test_state_dir_and_config_paths_point_under_var_lib_authelia():
    """Bug 1 regression: storage.local.path and notifier.filesystem.filename
    must NOT live under {{ fleet_srv_dir }}/authelia — that directory is
    2750 <fleet user>:<authelia group> (group-READ only for Authelia by
    design; fleet writes users.yml there). The live rollout failed to start
    Authelia with "unable to open database file: permission denied" until
    both paths moved to /var/lib/authelia, which the role now creates as
    <service user>:<service group> 0750 before Authelia starts."""
    role_vars = _role_vars()
    assert role_vars["authelia_state_dir"] == "/var/lib/authelia"

    rendered = _render_configuration_yml()
    data = _yaml.load(rendered)
    assert data["storage"]["local"]["path"].startswith(role_vars["authelia_state_dir"])
    assert data["notifier"]["filesystem"]["filename"].startswith(role_vars["authelia_state_dir"])
    # users.yml stays under fleet_srv_dir/authelia — Ansible never writes there.
    assert data["authentication_backend"]["file"]["path"] == "/srv/fleet/authelia/users.yml"


def _find_task(name: str) -> dict:
    tasks = _yaml.load(TASKS_FILE.read_text(encoding="utf-8"))
    for task in tasks:
        if task.get("name") == name:
            return task
    raise AssertionError(f"could not find task {name!r} in {TASKS_FILE}")


def test_validate_task_has_the_three_secret_env_vars():
    """Bug 3 regression: `authelia config validate` failed live with
    "storage: option 'encryption_key' is required" because the task ran
    with none of the AUTHELIA_*_FILE secret env vars the systemd drop-in
    normally supplies. The validate task must set `environment:` to the
    exact same mapping the drop-in uses."""
    task = _find_task("Validate the rendered Authelia configuration")
    assert task["environment"] == "{{ authelia_secret_env }}"


def test_drop_in_and_validate_share_the_same_secret_env_mapping():
    """DRY requirement: both the systemd drop-in and the validate task must
    derive their secret env vars from the SAME single mapping
    (vars/main.yml's authelia_secret_env), not two independently-maintained
    lists that can drift apart (which is exactly how bug 3 happened)."""
    validate_task = _find_task("Validate the rendered Authelia configuration")
    assert validate_task["environment"] == "{{ authelia_secret_env }}"

    drop_in_content = _secrets_conf_content()
    referenced_keys = set(re.findall(r"authelia_secret_env\.([A-Z_]+)", drop_in_content))

    role_vars = _role_vars()
    expected_keys = set(role_vars["authelia_secret_env"].keys())
    assert expected_keys == {
        "AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET_FILE",
        "AUTHELIA_SESSION_SECRET_FILE",
        "AUTHELIA_STORAGE_ENCRYPTION_KEY_FILE",
    }
    assert referenced_keys == expected_keys


def test_state_dir_and_acl_tasks_run_before_validate_and_start():
    """Ordering requirement: /var/lib/authelia and the /srv/fleet traverse
    ACL must exist before Authelia is validated/started, or the live
    failures this fix addresses (permission denied opening the DB;
    permission denied stat'ing /srv/fleet/authelia) reappear."""
    tasks = _yaml.load(TASKS_FILE.read_text(encoding="utf-8"))
    names = [t.get("name") for t in tasks]

    state_dir_idx = names.index(
        "Ensure the Authelia state directory exists (SQLite DB + filesystem notifier)"
    )
    acl_grant_idx = names.index(
        "Grant the Authelia service user traverse-only (execute) access on {{ fleet_srv_dir }}"
    )
    validate_idx = names.index("Validate the rendered Authelia configuration")
    start_idx = names.index("Ensure authelia.service is enabled and started")

    assert state_dir_idx < validate_idx < start_idx
    assert acl_grant_idx < validate_idx < start_idx


def test_acl_package_and_setfacl_are_guarded_to_non_root_service_user():
    """The setfacl/getfacl tasks (and the acl package install) must only
    run when the Authelia service does NOT run as root — root needs no ACL
    to traverse anything it already owns."""
    for task_name in (
        "Ensure the acl package is installed",
        "Grant the Authelia service user traverse-only (execute) access on {{ fleet_srv_dir }}",
    ):
        task = _find_task(task_name)
        when = task["when"]
        when_list = when if isinstance(when, list) else [when]
        assert "authelia_service_user != ''" in when_list


def test_tmpfiles_mask_keeps_only_the_e_rule_and_precedes_secret_generation():
    """Reboot regression (ddev4.fleet.example.com, 2026-10-01): the Debian authelia
    package's /usr/lib/tmpfiles.d/authelia.conf has `Z /etc/authelia/* 0640
    authelia authelia`, which at every boot turned /etc/authelia/secrets
    into 0640 (no execute bit) so Authelia couldn't open its secrets and
    every gated URL 502'd. The role must mask it with a same-basename
    /etc/tmpfiles.d/authelia.conf containing ONLY the harmless `e` rule —
    never Z/z rules (z under an authelia-owned parent is refused as an
    unsafe path transition) — written before the secrets are generated."""
    tasks = _yaml.load(TASKS_FILE.read_text(encoding="utf-8"))

    mask_idx = next(
        i
        for i, t in enumerate(tasks)
        if t.get("ansible.builtin.copy", {}).get("dest") == "/etc/tmpfiles.d/authelia.conf"
    )
    mask_copy = tasks[mask_idx]["ansible.builtin.copy"]
    assert mask_copy["owner"] == "root"
    assert mask_copy["group"] == "root"
    assert mask_copy["mode"] == "0644"

    lines = mask_copy["content"].splitlines()
    assert "e /etc/authelia 0755 authelia authelia -" in lines
    assert not [
        line for line in lines if line.startswith(("Z", "z"))
    ], "tmpfiles Z/z rules must never be written (recursive chmod / unsafe path transition)"
    # Only comments and the single `e` rule — nothing else sneaks in.
    rules = [line for line in lines if line.strip() and not line.startswith("#")]
    assert rules == ["e /etc/authelia 0755 authelia authelia -"]

    secrets_idx = next(
        i
        for i, t in enumerate(tasks)
        if t.get("ansible.builtin.copy", {}).get("dest") == "/etc/authelia/secrets/{{ item }}"
    )
    assert mask_idx < secrets_idx


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
        (tmp_path / "lib" / "authelia").mkdir(parents=True)

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
        role_vars = _role_vars()
        config_path.write_text(
            rendered.replace("/srv/fleet/authelia", str(tmp_path / "srv" / "authelia")).replace(
                role_vars["authelia_state_dir"], str(tmp_path / "lib" / "authelia")
            ),
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
