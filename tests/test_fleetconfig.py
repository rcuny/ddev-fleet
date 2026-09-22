import json
import shutil
import subprocess

import pytest

from fleet.core.fleetconfig import (
    ensure_git_exclude,
    set_web_env_var,
    write_ddev_env,
    write_fleet_config,
    write_settings_local,
    write_web_build,
)
from fleet.core.registry import PortProfile


def _extract_hook_exec(config_path):
    """Load config.fleet.yaml with ruamel and return the raw `exec:` string
    of the first post-start hook (the full `node -e '...'` shell command)."""
    from fleet.core.fleetconfig import _yaml

    with open(config_path, "r", encoding="utf-8") as fh:
        data = _yaml.load(fh)
    return data["hooks"]["post-start"][0]["exec"]


def _extract_hook_js(config_path):
    """Strip the `node -e '...'` shell wrapper off the hook exec string and
    return just the JS source."""
    exec_str = _extract_hook_exec(config_path).strip()
    assert exec_str.startswith("node -e '")
    assert exec_str.endswith("'")
    return exec_str[len("node -e '") : -1]


# The full `hooks:` block write_fleet_config() now appends to every
# config.fleet.yaml, verbatim (captured from real write_fleet_config()
# output, not hand-typed) — reused by the exact-content assertions below so
# a change to the hook script only needs updating in one place.
_EXPECTED_HOOKS_YAML = (
    "hooks:\n"
    "  post-start:\n"
    "    - exec: |\n"
    "        node -e '\n"
    '        const fs = require("fs");\n'
    '        const path = (process.env.HOME || "/home/fleet") + "/.claude.json";\n'
    "        let data = {};\n"
    '        try { data = JSON.parse(fs.readFileSync(path, "utf8")); } catch (e) {}\n'
    "        data.hasCompletedOnboarding = true;\n"
    '        if (data.theme === undefined) { data.theme = "dark"; }\n'
    "        data.projects = data.projects || {};\n"
    '        const proj = data.projects["/var/www/html"] || {};\n'
    "        proj.hasTrustDialogAccepted = true;\n"
    '        data.projects["/var/www/html"] = proj;\n'
    "        fs.writeFileSync(path, JSON.stringify(data, null, 2));\n"
    "        '\n"
)


def test_write_fleet_config_with_token(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir, "oak--develop", "fleet.example.test", "sk-ant-oat01-xyz"
    )

    assert path == instance_dir / ".ddev" / "config.fleet.yaml"
    assert path.read_text(encoding="utf-8") == (
        "name: oak--develop\n"
        "project_tld: fleet.example.test\n"
        "performance_mode: none\n"
        "web_environment:\n"
        "  - DRUSH_OPTIONS_URI=https://oak--develop.fleet.example.test\n"
        "  - FLEET_INSTANCE_HOST=oak--develop.fleet.example.test\n"
        "  - CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-xyz\n" + _EXPECTED_HOOKS_YAML
    )


def test_write_fleet_config_without_token(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(instance_dir, "oak--develop", "fleet.example.test", None)

    assert path.read_text(encoding="utf-8") == (
        "name: oak--develop\n"
        "project_tld: fleet.example.test\n"
        "performance_mode: none\n"
        "web_environment:\n"
        "  - DRUSH_OPTIONS_URI=https://oak--develop.fleet.example.test\n"
        "  - FLEET_INSTANCE_HOST=oak--develop.fleet.example.test\n" + _EXPECTED_HOOKS_YAML
    )


def test_write_fleet_config_sets_performance_mode_none(tmp_path):
    """Bug fix: fleet-owned config must force performance_mode: none on
    every instance, so it overrides a project's own committed config.yaml
    (e.g. oak ships performance_mode: mutagen for macOS devs) — Mutagen is
    pure overhead on this Linux fleet host."""
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(instance_dir, "oak--develop", "fleet.example.test", None)

    content = path.read_text(encoding="utf-8")
    assert "performance_mode: none" in content


def test_write_fleet_config_sets_drush_options_uri_to_instance_fqdn(tmp_path):
    """Bug fix: DRUSH_OPTIONS_URI must reflect the instance's real fleet
    FQDN (instance_id.domain), not a stale project-hardcoded URI, so
    `drush uli`/status emit correct URLs."""
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(instance_dir, "oak--develop", "fleet.example.test", None)

    content = path.read_text(encoding="utf-8")
    assert "DRUSH_OPTIONS_URI=https://oak--develop.fleet.example.test" in content


def test_write_fleet_config_sets_fleet_instance_host(tmp_path):
    """FLEET_INSTANCE_HOST is what a project's Drupal Domain Access config
    builds alias hostnames from (`"<h>-" . getenv('FLEET_INSTANCE_HOST')`),
    so it must always be the instance's bare fleet host (no scheme)."""
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(instance_dir, "oak--develop", "fleet.example.test", None)

    content = path.read_text(encoding="utf-8")
    assert "FLEET_INSTANCE_HOST=oak--develop.fleet.example.test" in content


def test_write_fleet_config_with_additional_fqdns(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir,
        "oak--develop",
        "fleet.example.test",
        None,
        additional_fqdns=["albania.oak--develop.fleet.example.test"],
    )

    content = path.read_text(encoding="utf-8")
    assert "additional_fqdns:" in content
    assert "albania.oak--develop.fleet.example.test" in content


def test_write_fleet_config_with_token_and_git_bot(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir,
        "oak--develop",
        "fleet.example.test",
        "sk-ant-oat01-xyz",
        git_bot=("ddev-fleet bot", "bot@x"),
    )

    content = path.read_text(encoding="utf-8")
    assert "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-xyz" in content
    assert "GIT_AUTHOR_NAME=ddev-fleet bot" in content
    assert "GIT_AUTHOR_EMAIL=bot@x" in content
    assert "GIT_COMMITTER_NAME=ddev-fleet bot" in content
    assert "GIT_COMMITTER_EMAIL=bot@x" in content
    assert path.read_text(encoding="utf-8") == (
        "name: oak--develop\n"
        "project_tld: fleet.example.test\n"
        "performance_mode: none\n"
        "web_environment:\n"
        "  - DRUSH_OPTIONS_URI=https://oak--develop.fleet.example.test\n"
        "  - FLEET_INSTANCE_HOST=oak--develop.fleet.example.test\n"
        "  - CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-xyz\n"
        "  - GIT_AUTHOR_NAME=ddev-fleet bot\n"
        "  - GIT_AUTHOR_EMAIL=bot@x\n"
        "  - GIT_COMMITTER_NAME=ddev-fleet bot\n"
        "  - GIT_COMMITTER_EMAIL=bot@x\n" + _EXPECTED_HOOKS_YAML
    )


def test_write_fleet_config_without_token_with_git_bot_creates_web_environment(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir,
        "oak--develop",
        "fleet.example.test",
        None,
        git_bot=("ddev-fleet bot", "bot@x"),
    )

    assert path.read_text(encoding="utf-8") == (
        "name: oak--develop\n"
        "project_tld: fleet.example.test\n"
        "performance_mode: none\n"
        "web_environment:\n"
        "  - DRUSH_OPTIONS_URI=https://oak--develop.fleet.example.test\n"
        "  - FLEET_INSTANCE_HOST=oak--develop.fleet.example.test\n"
        "  - GIT_AUTHOR_NAME=ddev-fleet bot\n"
        "  - GIT_AUTHOR_EMAIL=bot@x\n"
        "  - GIT_COMMITTER_NAME=ddev-fleet bot\n"
        "  - GIT_COMMITTER_EMAIL=bot@x\n" + _EXPECTED_HOOKS_YAML
    )


def test_write_fleet_config_with_typesense(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir,
        "oak--develop",
        "fleet.example.test",
        "sk-ant-oat01-xyz",
        typesense=True,
    )

    assert path.read_text(encoding="utf-8") == (
        "name: oak--develop\n"
        "project_tld: fleet.example.test\n"
        "performance_mode: none\n"
        "web_environment:\n"
        "  - DRUSH_OPTIONS_URI=https://oak--develop.fleet.example.test\n"
        "  - FLEET_INSTANCE_HOST=oak--develop.fleet.example.test\n"
        "  - CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-xyz\n"
        "  - FLEET_TYPESENSE_HOST=oak--develop.fleet.example.test\n"
        "  - FLEET_TYPESENSE_PORT=9108\n" + _EXPECTED_HOOKS_YAML
    )


def test_write_fleet_config_without_typesense_omits_vars(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir, "oak--develop", "fleet.example.test", "sk-ant-oat01-xyz"
    )
    content = path.read_text(encoding="utf-8")
    assert "FLEET_TYPESENSE_HOST" not in content


def test_write_fleet_config_typesense_custom_port(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir,
        "oak--develop",
        "fleet.example.test",
        None,
        typesense=True,
        typesense_port=9999,
    )
    content = path.read_text(encoding="utf-8")
    assert "FLEET_TYPESENSE_PORT=9999" in content


def test_write_fleet_config_typesense_with_keys(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir,
        "oak--develop",
        "fleet.example.test",
        None,
        typesense=True,
        typesense_admin_key="admin-key-abc",
        typesense_search_key="search-key-def",
    )
    content = path.read_text(encoding="utf-8")
    assert "TYPESENSE_API_KEY=admin-key-abc" in content
    assert "FLEET_TYPESENSE_SEARCH_KEY=search-key-def" in content


def test_write_fleet_config_typesense_without_keys_omits_key_vars(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir,
        "oak--develop",
        "fleet.example.test",
        None,
        typesense=True,
    )
    content = path.read_text(encoding="utf-8")
    assert "TYPESENSE_API_KEY" not in content
    assert "FLEET_TYPESENSE_SEARCH_KEY" not in content


def test_write_fleet_config_no_longer_writes_typesense_path(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir,
        "oak--develop",
        "fleet.example.test",
        None,
        typesense=True,
    )
    content = path.read_text(encoding="utf-8")
    assert "FLEET_TYPESENSE_PATH" not in content


def test_write_fleet_config_with_ports_injects_fleet_port_vars(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir,
        "oak--develop",
        "fleet.example.test",
        "sk-ant-oat01-xyz",
        typesense=True,
        ports=[
            PortProfile(name="typesense", public=9108, router=8108),
            PortProfile(name="playwright", public=9324, router=8323),
        ],
    )
    content = path.read_text(encoding="utf-8")
    assert "FLEET_PORT_PLAYWRIGHT=9324" in content
    assert "FLEET_TYPESENSE_HOST=oak--develop.fleet.example.test" in content
    assert "FLEET_TYPESENSE_PORT=9108" in content
    # the typesense PortProfile passed via `ports` must NOT also produce a
    # generic FLEET_PORT_TYPESENSE — its bespoke FLEET_TYPESENSE_* names
    # (above) are the only ones settings.project.php reads.
    assert "FLEET_PORT_TYPESENSE" not in content


def test_write_fleet_config_ports_name_with_dash_becomes_upper_snake_case(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir,
        "oak--develop",
        "fleet.example.test",
        None,
        ports=[PortProfile(name="ts-dashboard", public=9111, router=8110)],
    )
    content = path.read_text(encoding="utf-8")
    assert "FLEET_PORT_TS_DASHBOARD=9111" in content


def test_write_fleet_config_without_ports_omits_fleet_port_vars(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir, "oak--develop", "fleet.example.test", "sk-ant-oat01-xyz"
    )
    content = path.read_text(encoding="utf-8")
    assert "FLEET_PORT_" not in content


def test_write_ddev_env_creates_file(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_ddev_env(instance_dir, {"TYPESENSE_API_KEY": "abc123"})

    assert path == instance_dir / ".ddev" / ".env"
    assert path.read_text(encoding="utf-8") == "TYPESENSE_API_KEY=abc123\n"


def test_write_ddev_env_upserts_and_preserves_existing_keys(tmp_path):
    instance_dir = tmp_path / "instance"
    write_ddev_env(instance_dir, {"FOO": "bar"})
    path = write_ddev_env(instance_dir, {"TYPESENSE_API_KEY": "abc123"})

    content = path.read_text(encoding="utf-8")
    assert "FOO=bar" in content
    assert "TYPESENSE_API_KEY=abc123" in content


def test_write_ddev_env_upsert_overwrites_same_key(tmp_path):
    instance_dir = tmp_path / "instance"
    write_ddev_env(instance_dir, {"FOO": "bar"})
    path = write_ddev_env(instance_dir, {"FOO": "baz"})

    lines = path.read_text(encoding="utf-8").splitlines()
    assert lines.count("FOO=baz") == 1
    assert "FOO=bar" not in lines


def test_write_web_build(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_web_build(instance_dir)

    assert path == instance_dir / ".ddev" / "web-build" / "Dockerfile.fleet-claude"
    assert path.read_text(encoding="utf-8") == "RUN npm install -g @anthropic-ai/claude-code\n"


def test_ensure_git_exclude_creates_file_and_adds_pattern(tmp_path):
    instance_dir = tmp_path / "instance"
    ensure_git_exclude(instance_dir, [".ddev/config.fleet.yaml"])

    exclude_path = instance_dir / ".git" / "info" / "exclude"
    assert exclude_path.read_text(encoding="utf-8") == ".ddev/config.fleet.yaml\n"


def test_ensure_git_exclude_is_idempotent(tmp_path):
    instance_dir = tmp_path / "instance"
    ensure_git_exclude(instance_dir, [".ddev/config.fleet.yaml"])
    first = (instance_dir / ".git" / "info" / "exclude").read_text(encoding="utf-8")

    ensure_git_exclude(instance_dir, [".ddev/config.fleet.yaml"])
    second = (instance_dir / ".git" / "info" / "exclude").read_text(encoding="utf-8")

    assert first == second


def test_ensure_git_exclude_appends_only_missing_patterns(tmp_path):
    instance_dir = tmp_path / "instance"
    ensure_git_exclude(instance_dir, [".ddev/config.fleet.yaml"])
    ensure_git_exclude(instance_dir, [".ddev/config.fleet.yaml", "another.file"])

    exclude_path = instance_dir / ".git" / "info" / "exclude"
    assert exclude_path.read_text(encoding="utf-8") == ".ddev/config.fleet.yaml\nanother.file\n"


def test_ensure_git_exclude_handles_missing_trailing_newline(tmp_path):
    instance = tmp_path / "inst"
    (instance / ".git" / "info").mkdir(parents=True)
    exclude = instance / ".git" / "info" / "exclude"
    exclude.write_text("existing-pattern", encoding="utf-8")  # no trailing newline

    ensure_git_exclude(instance, [".ddev/config.fleet.yaml"])

    lines = exclude.read_text(encoding="utf-8").splitlines()
    assert "existing-pattern" in lines
    assert ".ddev/config.fleet.yaml" in lines


def test_write_settings_local_writes_trusted_host(tmp_path):
    instance_dir = tmp_path / "instance"
    ddev_dir = instance_dir / ".ddev"
    ddev_dir.mkdir(parents=True)
    (ddev_dir / "config.yaml").write_text("docroot: web\n", encoding="utf-8")
    (instance_dir / "web" / "sites" / "default").mkdir(parents=True)

    result = write_settings_local(instance_dir, "fleet.example.test")

    assert result == [
        instance_dir / "web" / "sites" / "default" / "settings.local.php",
        instance_dir / "web" / "sites" / "default" / "services.fleet.yml",
    ]
    assert result[0].read_text(encoding="utf-8") == (
        "<?php\n"
        "// fleet-managed: trust this instance's fleet hostname(s).\n"
        "$settings['trusted_host_patterns'][] = '^.+\\.fleet\\.example\\.test$';\n"
        "// fleet-managed: neutralise any hardcoded session cookie_domain so login cookies\n"
        "// are set for the current fleet host (services.fleet.yml is appended last, so it\n"
        "// overrides the project's / DDEV's services container yamls).\n"
        "$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.fleet.yml';\n"
        "// fleet-managed: load the project's own (fleet-agnostic) overrides if present.\n"
        "if (file_exists(__DIR__ . '/settings.project.php')) {\n"
        "    include __DIR__ . '/settings.project.php';\n"
        "}\n"
    )
    assert "if (file_exists(__DIR__ . '/settings.project.php'))" in result[0].read_text(
        encoding="utf-8"
    )
    assert "include __DIR__ . '/settings.project.php';" in result[0].read_text(encoding="utf-8")
    assert result[1].read_text(encoding="utf-8") == (
        "parameters:\n" "  session.storage.options:\n" "    cookie_domain: ''\n"
    )


def test_write_settings_local_no_docroot_uses_root(tmp_path):
    instance_dir = tmp_path / "instance"
    ddev_dir = instance_dir / ".ddev"
    ddev_dir.mkdir(parents=True)
    (ddev_dir / "config.yaml").write_text("name: demo--develop\n", encoding="utf-8")
    (instance_dir / "sites" / "default").mkdir(parents=True)

    result = write_settings_local(instance_dir, "fleet.example.test")

    assert result == [
        instance_dir / "sites" / "default" / "settings.local.php",
        instance_dir / "sites" / "default" / "services.fleet.yml",
    ]
    assert result[0].exists()
    assert result[1].exists()


def test_write_settings_local_non_drupal_returns_none(tmp_path):
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir(parents=True)

    result = write_settings_local(instance_dir, "fleet.example.test")

    assert result == []
    assert not (instance_dir / "sites").exists()


def test_set_web_env_var_updates_only_target_key_preserves_others(tmp_path):
    instance_dir = tmp_path / "instance"
    ddev_dir = instance_dir / ".ddev"
    ddev_dir.mkdir(parents=True)
    config_path = ddev_dir / "config.fleet.yaml"
    config_path.write_text(
        "name: oak--develop\n"
        "project_tld: fleet.example.test\n"
        "web_environment:\n"
        "  - CLAUDE_CODE_OAUTH_TOKEN=old\n"
        "  - GIT_AUTHOR_NAME=bot\n"
        "  - FLEET_TYPESENSE_HOST=x\n"
        "  - FLEET_TYPESENSE_SEARCH_KEY=k\n",
        encoding="utf-8",
    )

    result = set_web_env_var(instance_dir, "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-new")

    assert result is True
    content = config_path.read_text(encoding="utf-8")
    assert "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-new" in content
    assert "CLAUDE_CODE_OAUTH_TOKEN=old" not in content
    assert "GIT_AUTHOR_NAME=bot" in content
    assert "FLEET_TYPESENSE_HOST=x" in content
    assert "FLEET_TYPESENSE_SEARCH_KEY=k" in content
    assert "name: oak--develop" in content
    assert "project_tld: fleet.example.test" in content


def test_set_web_env_var_missing_config_file_returns_false(tmp_path):
    instance_dir = tmp_path / "instance"

    result = set_web_env_var(instance_dir, "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-new")

    assert result is False


def test_set_web_env_var_appends_missing_key(tmp_path):
    instance_dir = tmp_path / "instance"
    ddev_dir = instance_dir / ".ddev"
    ddev_dir.mkdir(parents=True)
    config_path = ddev_dir / "config.fleet.yaml"
    config_path.write_text(
        "name: oak--develop\nproject_tld: fleet.example.test\n",
        encoding="utf-8",
    )

    result = set_web_env_var(instance_dir, "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-new")

    assert result is True
    content = config_path.read_text(encoding="utf-8")
    assert "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-new" in content


def test_write_fleet_config_hook_present_in_output(tmp_path):
    """config.fleet.yaml must carry a post-start hook seeding
    ~/.claude.json, so the interactive TUI boots past the first-run
    onboarding wizard (spec: 2026-07-17 claude-onboarding-and-fleet-remote-
    design.md, Part B step 3)."""
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir, "oak--develop", "fleet.example.test", "sk-ant-oat01-xyz"
    )

    content = path.read_text(encoding="utf-8")
    assert "hooks:" in content
    assert "post-start:" in content
    assert "node -e" in content
    assert "hasCompletedOnboarding" in content


def test_write_fleet_config_hook_trusts_container_mount_path(tmp_path):
    """The trust path baked into the hook must be the *container* mount
    path (/var/www/html), not any host path — verified fact from the
    design spec."""
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(instance_dir, "oak--develop", "fleet.example.test", None)

    js = _extract_hook_js(path)
    assert "/var/www/html" in js
    assert "hasTrustDialogAccepted" in js


def test_write_fleet_config_hook_present_regardless_of_token(tmp_path):
    """write_web_build() bakes Claude into every instance's image
    unconditionally (core/instances.py), so the onboarding-seed hook must
    likewise be unconditional — not gated on claude_token being set."""
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(instance_dir, "oak--develop", "fleet.example.test", None)

    content = path.read_text(encoding="utf-8")
    assert "hooks:" in content
    assert "post-start:" in content


@pytest.mark.skipif(shutil.which("node") is None, reason="node not available in this container")
def test_write_fleet_config_hook_js_is_syntactically_valid(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir, "oak--develop", "fleet.example.test", "sk-ant-oat01-xyz"
    )

    js = _extract_hook_js(path)
    js_file = tmp_path / "onboarding.js"
    js_file.write_text(js, encoding="utf-8")

    result = subprocess.run(
        ["node", "--check", str(js_file)],
        capture_output=True,
        text=True,
    )
    assert result.returncode == 0, result.stderr


@pytest.mark.skipif(shutil.which("node") is None, reason="node not available in this container")
def test_write_fleet_config_hook_merges_preserves_runtime_keys(tmp_path):
    """The hook's JS must MERGE into an existing ~/.claude.json, preserving
    runtime keys (userID, machineID, firstStartTime, per-project history)
    while setting the onboarding/theme/trust keys."""
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir, "oak--develop", "fleet.example.test", "sk-ant-oat01-xyz"
    )
    js = _extract_hook_js(path)

    fake_home = tmp_path / "fake_home"
    fake_home.mkdir()
    existing = {
        "userID": "abc123",
        "machineID": "mach1",
        "firstStartTime": "2026-01-01T00:00:00Z",
        "projects": {"/var/www/html": {"history": ["ls"]}},
    }
    (fake_home / ".claude.json").write_text(json.dumps(existing), encoding="utf-8")

    subprocess.run(
        ["node", "-e", js],
        env={**__import__("os").environ, "HOME": str(fake_home)},
        check=True,
        capture_output=True,
        text=True,
    )

    result = json.loads((fake_home / ".claude.json").read_text(encoding="utf-8"))
    assert result["userID"] == "abc123"
    assert result["machineID"] == "mach1"
    assert result["firstStartTime"] == "2026-01-01T00:00:00Z"
    assert result["projects"]["/var/www/html"]["history"] == ["ls"]
    assert result["hasCompletedOnboarding"] is True
    assert result["theme"] == "dark"
    assert result["projects"]["/var/www/html"]["hasTrustDialogAccepted"] is True


@pytest.mark.skipif(shutil.which("node") is None, reason="node not available in this container")
def test_write_fleet_config_hook_is_idempotent(tmp_path):
    """Re-running the hook against an already-seeded ~/.claude.json must be
    a no-op (same resulting content)."""
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir, "oak--develop", "fleet.example.test", "sk-ant-oat01-xyz"
    )
    js = _extract_hook_js(path)

    fake_home = tmp_path / "fake_home"
    fake_home.mkdir()
    (fake_home / ".claude.json").write_text("{}", encoding="utf-8")

    env = {**__import__("os").environ, "HOME": str(fake_home)}
    subprocess.run(["node", "-e", js], env=env, check=True, capture_output=True, text=True)
    first = (fake_home / ".claude.json").read_text(encoding="utf-8")

    subprocess.run(["node", "-e", js], env=env, check=True, capture_output=True, text=True)
    second = (fake_home / ".claude.json").read_text(encoding="utf-8")

    assert first == second


def test_write_fleet_config_hook_does_not_overwrite_existing_theme(tmp_path):
    """If the user already picked a theme, the hook must not clobber it —
    only set a default when theme is absent."""
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(
        instance_dir, "oak--develop", "fleet.example.test", "sk-ant-oat01-xyz"
    )
    js = _extract_hook_js(path)
    if shutil.which("node") is None:
        pytest.skip("node not available in this container")

    fake_home = tmp_path / "fake_home"
    fake_home.mkdir()
    (fake_home / ".claude.json").write_text(json.dumps({"theme": "light"}), encoding="utf-8")

    env = {**__import__("os").environ, "HOME": str(fake_home)}
    subprocess.run(["node", "-e", js], env=env, check=True, capture_output=True, text=True)

    result = json.loads((fake_home / ".claude.json").read_text(encoding="utf-8"))
    assert result["theme"] == "light"


def test_set_web_env_var_appends_when_no_web_environment_key_at_all(tmp_path):
    instance_dir = tmp_path / "instance"
    ddev_dir = instance_dir / ".ddev"
    ddev_dir.mkdir(parents=True)
    config_path = ddev_dir / "config.fleet.yaml"
    config_path.write_text("name: oak--develop\n", encoding="utf-8")

    result = set_web_env_var(instance_dir, "FOO", "bar")

    assert result is True
    content = config_path.read_text(encoding="utf-8")
    assert "name: oak--develop" in content
    assert "FOO=bar" in content
