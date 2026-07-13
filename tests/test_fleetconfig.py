from fleet.core.fleetconfig import (
    ensure_git_exclude,
    write_fleet_config,
    write_settings_local,
    write_web_build,
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
        "web_environment:\n"
        "  - CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-xyz\n"
    )


def test_write_fleet_config_without_token(tmp_path):
    instance_dir = tmp_path / "instance"
    path = write_fleet_config(instance_dir, "oak--develop", "fleet.example.test", None)

    assert path.read_text(encoding="utf-8") == (
        "name: oak--develop\n" "project_tld: fleet.example.test\n"
    )


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

    path = write_settings_local(instance_dir, "fleet.example.test")

    assert path == instance_dir / "web" / "sites" / "default" / "settings.local.php"
    assert path.read_text(encoding="utf-8") == (
        "<?php\n"
        "// fleet-managed: trust this instance's fleet hostname(s).\n"
        "$settings['trusted_host_patterns'][] = '^.+\\.fleet\\.example\\.test$';\n"
    )


def test_write_settings_local_no_docroot_uses_root(tmp_path):
    instance_dir = tmp_path / "instance"
    ddev_dir = instance_dir / ".ddev"
    ddev_dir.mkdir(parents=True)
    (ddev_dir / "config.yaml").write_text("name: demo--develop\n", encoding="utf-8")
    (instance_dir / "sites" / "default").mkdir(parents=True)

    path = write_settings_local(instance_dir, "fleet.example.test")

    assert path == instance_dir / "sites" / "default" / "settings.local.php"
    assert path.exists()


def test_write_settings_local_non_drupal_returns_none(tmp_path):
    instance_dir = tmp_path / "instance"
    instance_dir.mkdir(parents=True)

    path = write_settings_local(instance_dir, "fleet.example.test")

    assert path is None
    assert not (instance_dir / "sites").exists()
