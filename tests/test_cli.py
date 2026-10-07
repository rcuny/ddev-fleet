import argparse
import inspect
import stat
from pathlib import Path

from fleet import cli
from fleet.core import caddyports
from fleet.core import instances as real_instances_mod
from fleet.core.errors import CaddyPortsError, DeployError
from fleet.core.instances import FleetPaths, InstanceStatus
from fleet.core.registry import Registry
from fleet.core.runner import RunResult


def _write_minimal_registry(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default: {}
""",
        encoding="utf-8",
    )


def test_deploy_happy_path_prints_url(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)

    def fake_deploy(
        paths,
        registry,
        project,
        template,
        *,
        branch=None,
        label=None,
        replace=False,
        force=False,
        auth_enabled=True,
        auth_password="fleet",
        create_tmux_session=False,
        runner=None,
    ):
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(cli.instances_mod, "deploy", fake_deploy)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "deploy", "demo", "default", "--branch=main"]
    )

    assert exit_code == 0
    assert "https://demo--develop.fleet.example.test" in capsys.readouterr().out


def test_deploy_call_args_match_real_deploy_signature(fleet_home, monkeypatch, capsys):
    """Regression guard for the 2026-07-27 breakage where cli.py kept
    passing `fresh=` after core/instances.py's deploy() renamed that
    parameter to `replace` — every deploy() call raised TypeError at
    runtime, and NONE of the existing tests caught it because their
    fake_deploy stand-ins declare their own (hand-copied, and in that case
    stale) explicit signature rather than checking against the real one.

    This test binds the CLI's actual call args against
    `inspect.signature(instances_mod.deploy)` — the REAL function, imported
    before any monkeypatching — so a future rename/removal of a keyword
    argument raises a loud TypeError here instead of silently passing."""
    _write_minimal_registry(fleet_home)
    real_sig = inspect.signature(real_instances_mod.deploy)

    def fake_deploy(*args, **kwargs):
        real_sig.bind(*args, **kwargs)
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(cli.instances_mod, "deploy", fake_deploy)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "deploy", "demo", "default", "--branch=main"]
    )

    assert exit_code == 0
    assert "https://demo--develop.fleet.example.test" in capsys.readouterr().out


def test_deploy_defaults_to_auth_enabled_with_fleet_password(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    captured = {}

    def fake_deploy(paths, registry, project, template, *, auth_enabled, auth_password, **kw):
        captured["auth_enabled"] = auth_enabled
        captured["auth_password"] = auth_password
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(cli.instances_mod, "deploy", fake_deploy)

    cli.main(["--fleet-home", str(fleet_home), "deploy", "demo", "default", "--branch=main"])

    assert captured == {"auth_enabled": True, "auth_password": "fleet"}


def test_deploy_no_auth_flag_disables_auth(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    captured = {}

    def fake_deploy(paths, registry, project, template, *, auth_enabled, auth_password, **kw):
        captured["auth_enabled"] = auth_enabled
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(cli.instances_mod, "deploy", fake_deploy)

    cli.main(
        ["--fleet-home", str(fleet_home), "deploy", "demo", "default", "--branch=main", "--no-auth"]
    )

    assert captured["auth_enabled"] is False


def _write_authelia_registry(fleet_home):
    _write_minimal_registry(fleet_home)
    (fleet_home / "host.yml").write_text("auth_mode: authelia\n", encoding="utf-8")


_AUTH_PASSWORD_REJECTED_MESSAGE = "auth passwords are managed in fleet.yml users: (Authelia mode)"


def test_deploy_rejects_auth_password_in_authelia_mode(fleet_home, capsys):
    _write_authelia_registry(fleet_home)

    rc = cli.main(["--fleet-home", str(fleet_home), "deploy", "demo", "--auth-password", "secret"])

    assert rc == 1
    assert capsys.readouterr().err.strip() == _AUTH_PASSWORD_REJECTED_MESSAGE


def test_redeploy_rejects_auth_password_in_authelia_mode(fleet_home, capsys):
    _write_authelia_registry(fleet_home)
    instance_dir = fleet_home / "instances" / "demo--main"
    (instance_dir / ".fleet").mkdir(parents=True)
    (instance_dir / ".fleet" / "instance.yml").write_text(
        "project: demo\nbranch: main\ntemplate: default\n", encoding="utf-8"
    )

    rc = cli.main(
        ["--fleet-home", str(fleet_home), "redeploy", "demo--main", "--auth-password", "secret"]
    )

    assert rc == 1
    assert capsys.readouterr().err.strip() == _AUTH_PASSWORD_REJECTED_MESSAGE


def test_bulk_redeploy_rejects_auth_password_in_authelia_mode_before_confirmation(
    fleet_home, capsys, monkeypatch
):
    """The reject check must fire BEFORE the multi-target confirmation
    prompt (spec: no --yes, no stdin needed to hit the rejection) — a
    non-interactive run must never hang on `input()` first and only then
    fail for an unrelated reason."""
    _write_authelia_registry(fleet_home)
    for label in ("one", "two"):
        instance_dir = fleet_home / "instances" / f"demo--{label}"
        (instance_dir / ".fleet").mkdir(parents=True)
        (instance_dir / ".fleet" / "instance.yml").write_text(
            "project: demo\nbranch: main\ntemplate: default\n", encoding="utf-8"
        )

    def _boom(*args, **kwargs):
        raise AssertionError("input() must not be reached — reject happens first")

    monkeypatch.setattr("builtins.input", _boom)

    rc = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "redeploy",
            "demo--one",
            "demo--two",
            "--auth-password",
            "secret",
        ]
    )

    assert rc == 1
    assert capsys.readouterr().err.strip() == _AUTH_PASSWORD_REJECTED_MESSAGE


def test_deploy_auth_password_flag_overrides_default(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    captured = {}

    def fake_deploy(paths, registry, project, template, *, auth_enabled, auth_password, **kw):
        captured["auth_password"] = auth_password
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(cli.instances_mod, "deploy", fake_deploy)

    cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "deploy",
            "demo",
            "default",
            "--branch=main",
            "--auth-password=s3cret",
        ]
    )

    assert captured["auth_password"] == "s3cret"


def test_deploy_fleet_error_exits_1_and_prints_to_stderr(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)

    def failing_deploy(*args, **kwargs):
        raise DeployError("something specific went wrong")

    monkeypatch.setattr(cli.instances_mod, "deploy", failing_deploy)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "deploy", "demo", "default"])

    assert exit_code == 1
    assert "something specific went wrong" in capsys.readouterr().err


def test_deploy_fresh_flag_removed_argparse_rejects_it(fleet_home, capsys):
    """`--fresh` is gone (design decision 3,
    2026-07-27-fleet-redeploy-and-no-overwrite-design.md) — `redeploy`
    replaces it. argparse must reject the flag outright rather than
    silently ignoring it."""
    import pytest

    with pytest.raises(SystemExit) as exc:
        cli.main(
            [
                "--fleet-home",
                str(fleet_home),
                "deploy",
                "demo",
                "default",
                "--branch=main",
                "--fresh",
            ]
        )

    assert exc.value.code == 2
    assert "unrecognized arguments: --fresh" in capsys.readouterr().err


def test_list_renders_table(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)

    fake_statuses = [
        InstanceStatus(
            instance_id="demo--develop",
            project="demo",
            instance="develop",
            branch="main",
            state="running",
            url="https://demo--develop.fleet.example.test",
            ram_mib=250,
        )
    ]
    monkeypatch.setattr(
        cli.instances_mod, "list_instances", lambda paths, registry, **kw: fake_statuses
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "list"])

    assert exit_code == 0
    out = capsys.readouterr().out
    assert "demo--develop" in out
    assert "running" in out
    assert "https://demo--develop.fleet.example.test" in out


def test_ssh_key_missing_exits_1(fleet_home, capsys):
    exit_code = cli.main(["--fleet-home", str(fleet_home), "ssh-key"])
    assert exit_code == 1
    assert "no deploy key found" in capsys.readouterr().err


def test_ssh_key_prints_existing_key(fleet_home, capsys):
    (fleet_home / "fleet-deploy-key.pub").write_text("ssh-ed25519 AAAA...\n", encoding="utf-8")
    exit_code = cli.main(["--fleet-home", str(fleet_home), "ssh-key"])
    assert exit_code == 0
    assert "ssh-ed25519 AAAA..." in capsys.readouterr().out


def test_init_skip_claude_creates_skeleton_non_interactively(tmp_path):
    fleet_home = tmp_path / "new-fleet-home"

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "init", "--domain=fleet.example.test", "--skip-claude"]
    )

    assert exit_code == 0
    assert (fleet_home / "config" / "assets").is_dir()
    assert (fleet_home / "instances").is_dir()
    assert (fleet_home / "locks").is_dir()
    registry = Registry.load(fleet_home / "config" / "fleet.yml")
    assert registry.domain == "fleet.example.test"
    assert registry.project_keys() == ["example"]
    assert not (fleet_home / ".secrets").exists()


def test_init_local_file_mode_copies_dist_verbatim_and_patches_domain(tmp_path):
    fleet_home = tmp_path / "new-fleet-home"

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "init", "--domain=fleet.example.test", "--skip-claude"]
    )

    assert exit_code == 0
    registry_text = (fleet_home / "config" / "fleet.yml").read_text(encoding="utf-8")
    # Copied from the real fleet.yml.dist verbatim (comments included),
    # not the old hand-built skeleton dict.
    assert "Example fleet registry" in registry_text
    assert "post_deploy: [ddev start]" in registry_text
    registry = Registry.load(fleet_home / "config" / "fleet.yml")
    assert registry.domain == "fleet.example.test"


def test_init_config_repo_mode_clones_when_not_already_a_checkout(tmp_path, monkeypatch):
    fleet_home = tmp_path / "new-fleet-home"
    recorder = []

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        recorder.append(cmd)
        # Simulate a real clone by creating the destination + a fake .git
        dest = Path(cmd[-1])
        (dest / ".git").mkdir(parents=True)
        (dest / "fleet.yml").write_text(
            "fleet:\n  domain: fleet.example.test\nprojects: {}\n", encoding="utf-8"
        )
        return RunResult(returncode=0, lines=[])

    monkeypatch.setenv("FLEET_CONFIG_REPO", "git@example.test:org/fleet-config.git")
    monkeypatch.setattr(cli, "run_streamed", fake_runner)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "init", "--domain=fleet.example.test", "--skip-claude"]
    )

    assert exit_code == 0
    assert recorder == [
        ["git", "clone", "git@example.test:org/fleet-config.git", str(fleet_home / "config")]
    ]
    registry = Registry.load(fleet_home / "config" / "fleet.yml")
    assert registry.domain == "fleet.example.test"


def test_init_config_repo_mode_never_re_clones_an_existing_checkout(tmp_path, monkeypatch, capsys):
    fleet_home = tmp_path / "new-fleet-home"
    config_dir = fleet_home / "config"
    (config_dir / ".git").mkdir(parents=True)
    (config_dir / "fleet.yml").write_text(
        "fleet:\n  domain: fleet.example.test\nprojects: {}\n", encoding="utf-8"
    )

    recorder = []

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        recorder.append(cmd)
        return RunResult(returncode=0, lines=[])

    monkeypatch.setenv("FLEET_CONFIG_REPO", "git@example.test:org/fleet-config.git")
    monkeypatch.setattr(cli, "run_streamed", fake_runner)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "init", "--domain=fleet.example.test", "--skip-claude"]
    )

    assert exit_code == 0
    assert recorder == []  # never re-cloned
    assert "already exists" in capsys.readouterr().err


def test_destroy_dispatch(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)

    recorder = []

    def fake_destroy(paths, registry, instance_id, *, runner=None):
        recorder.append(instance_id)

    monkeypatch.setattr(cli.instances_mod, "destroy", fake_destroy)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "destroy", "demo--develop"])

    assert exit_code == 0
    assert recorder == ["demo--develop"]


def test_start_dispatch(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)

    recorder = []

    def fake_start(paths, registry, instance_id, *, runner=None):
        recorder.append(instance_id)

    monkeypatch.setattr(cli.instances_mod, "start", fake_start)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "start", "demo--develop"])

    assert exit_code == 0
    assert recorder == ["demo--develop"]


def test_stop_dispatch(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)

    recorder = []

    def fake_stop(paths, registry, instance_id, *, runner=None):
        recorder.append(instance_id)

    monkeypatch.setattr(cli.instances_mod, "stop", fake_stop)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "stop", "demo--develop"])

    assert exit_code == 0
    assert recorder == ["demo--develop"]


def test_fleet_home_flag_overrides_env(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)

    env_home = fleet_home / "from-env"
    flag_home = fleet_home / "from-flag"
    (flag_home / "instances").mkdir(parents=True)
    (flag_home / "config" / "assets").mkdir(parents=True)
    (flag_home / "locks").mkdir(parents=True)
    (flag_home / "config" / "fleet.yml").write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default: {}
""",
        encoding="utf-8",
    )

    monkeypatch.setenv("FLEET_HOME", str(env_home))

    received_paths = []

    def fake_list(paths, registry, **kw):
        received_paths.append(paths)
        return []

    monkeypatch.setattr(cli.instances_mod, "list_instances", fake_list)

    exit_code = cli.main(["--fleet-home", str(flag_home), "list"])

    assert exit_code == 0
    assert len(received_paths) == 1
    assert received_paths[0].home == flag_home
    assert received_paths[0].registry == flag_home / "config" / "fleet.yml"


def test_usage_error_exits_2(fleet_home):
    _write_minimal_registry(fleet_home)

    import pytest

    with pytest.raises(SystemExit) as exc:
        cli.main(["--fleet-home", str(fleet_home), "definitely-not-a-command"])

    assert exc.value.code == 2


def test_assets_push_dispatch(fleet_home, monkeypatch, capsys, tmp_path):
    _write_minimal_registry(fleet_home)
    recorder = []

    def fake_push(assets_dir, src, dest_rel):
        recorder.append((assets_dir, src, dest_rel))
        return assets_dir / dest_rel

    monkeypatch.setattr(cli.assets_mod, "push", fake_push)

    src = tmp_path / "dump.sql.gz"
    src.write_text("x", encoding="utf-8")

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "assets", "push", "demo", str(src), "dumps/db.sql.gz"]
    )

    assert exit_code == 0
    assert recorder == [(fleet_home / "config" / "assets" / "demo", src, "dumps/db.sql.gz")]
    assert "dumps/db.sql.gz" in capsys.readouterr().out


def test_snapshot_dispatch(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    recorder = []

    def fake_snapshot(paths, registry, instance_id, *, dest_rel=None, runner=None):
        recorder.append((instance_id, dest_rel))
        computed = dest_rel or f"dumps/default-{instance_id}.sql"
        return fleet_home / "config" / "assets" / "demo" / computed

    monkeypatch.setattr(cli.instances_mod, "snapshot", fake_snapshot)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "snapshot", "demo--develop"])

    assert exit_code == 0
    # CLI passes None through when --dest-rel is omitted; instances.snapshot
    # is the one that computes the per-instance default (see its own tests).
    assert recorder == [("demo--develop", None)]


def test_snapshot_dispatch_custom_dest_rel(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)
    recorder = []

    def fake_snapshot(paths, registry, instance_id, *, dest_rel=None, runner=None):
        recorder.append((instance_id, dest_rel))
        computed = dest_rel or f"dumps/default-{instance_id}.sql"
        return fleet_home / "config" / "assets" / "demo" / computed

    monkeypatch.setattr(cli.instances_mod, "snapshot", fake_snapshot)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "snapshot", "demo--develop", "--dest-rel=custom.sql.gz"]
    )

    assert exit_code == 0
    assert recorder == [("demo--develop", "custom.sql.gz")]


def test_mint_claude_token_parses_first_matching_line():
    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        return RunResult(returncode=0, lines=["some banner", "sk-ant-oat01-abc123XYZ_-"])

    assert cli._mint_claude_token(fake_runner) == "sk-ant-oat01-abc123XYZ_-"


def test_mint_claude_token_returns_none_on_nonzero():
    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        return RunResult(returncode=1, lines=["sk-ant-oat01-should-be-ignored"])

    assert cli._mint_claude_token(fake_runner) is None


def test_mint_claude_token_returns_none_when_no_match():
    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        return RunResult(returncode=0, lines=["nothing matches here"])

    assert cli._mint_claude_token(fake_runner) is None


def test_init_warns_when_claude_token_minting_fails(tmp_path, monkeypatch, capsys):
    fleet_home = tmp_path / "new-fleet-home"

    monkeypatch.setattr(cli, "run_interactive", lambda cmd, **kw: 1)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "init", "--domain=fleet.example.test"])

    assert exit_code == 0
    assert "did not produce a token" in capsys.readouterr().err
    assert not (fleet_home / ".secrets").exists()


def test_init_skips_when_registry_already_exists(fleet_home, capsys):
    _write_minimal_registry(fleet_home)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "init", "--domain=fleet.example.test", "--skip-claude"]
    )

    assert exit_code == 0
    assert "already exists — skipping" in capsys.readouterr().err


def _write_two_demo_instances_with_old_token(fleet_home):
    for name in ("demo--develop", "demo--piano"):
        inst_dir = fleet_home / "instances" / name
        (inst_dir / ".fleet").mkdir(parents=True)
        (inst_dir / ".fleet" / "instance.yml").write_text(
            f"project: demo\ninstance: {name.split('--')[1]}\nbranch: main\n"
            "created-at: '2026-07-01T00:00:00Z'\nlast-deployed-at: '2026-07-01T00:00:00Z'\n",
            encoding="utf-8",
        )
        ddev_dir = inst_dir / ".ddev"
        ddev_dir.mkdir(parents=True)
        (ddev_dir / "config.fleet.yaml").write_text(
            f"name: {name}\n"
            "project_tld: fleet.example.test\n"
            "web_environment:\n"
            "  - CLAUDE_CODE_OAUTH_TOKEN=old-token\n"
            "  - GIT_AUTHOR_NAME=ddev-fleet bot\n"
            "  - GIT_AUTHOR_EMAIL=bot@x\n"
            "  - FLEET_TYPESENSE_HOST=x\n"
            "  - FLEET_TYPESENSE_SEARCH_KEY=k\n",
            encoding="utf-8",
        )


def _fake_runner_with_one_running_one_stopped(calls):
    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        calls.append(list(cmd))
        if cmd == ["ddev", "list", "--json-output"]:
            return RunResult(
                returncode=0,
                lines=[
                    '{"raw": [{"name": "demo--develop", "status": "running"}, '
                    '{"name": "demo--piano", "status": "stopped"}]}'
                ],
            )
        return RunResult(returncode=0, lines=[])

    return fake_runner


def test_refresh_claude_token_default_does_not_restart_and_updates_config(
    fleet_home, monkeypatch, capsys
):
    """Default behaviour (no --restart): .secrets and every instance's
    config.fleet.yaml are updated unconditionally, but no `ddev restart` is
    issued — restarting 10+ live instances just to rotate a token is slow,
    so that's left to the user."""
    _write_minimal_registry(fleet_home)
    _write_two_demo_instances_with_old_token(fleet_home)

    calls = []
    monkeypatch.setattr(cli, "run_streamed", _fake_runner_with_one_running_one_stopped(calls))
    monkeypatch.setattr(cli, "run_interactive", lambda cmd, **kw: 0)
    monkeypatch.setattr(cli, "input", lambda prompt: "sk-ant-oat01-newtoken", raising=False)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token"])

    assert exit_code == 0
    restart_calls = [c for c in calls if c == ["ddev", "restart"]]
    assert restart_calls == []

    for name in ("demo--develop", "demo--piano"):
        config = (fleet_home / "instances" / name / ".ddev" / "config.fleet.yaml").read_text(
            encoding="utf-8"
        )
        assert "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-newtoken" in config
        assert "CLAUDE_CODE_OAUTH_TOKEN=old-token" not in config
        # Non-Claude web_environment entries (git bot, Typesense) must survive
        # the refresh — this is the bug the rewrite-based approach caused.
        assert "GIT_AUTHOR_NAME=ddev-fleet bot" in config
        assert "GIT_AUTHOR_EMAIL=bot@x" in config
        assert "FLEET_TYPESENSE_HOST=x" in config
        assert "FLEET_TYPESENSE_SEARCH_KEY=k" in config

    secrets = (fleet_home / ".secrets").read_text(encoding="utf-8")
    assert "sk-ant-oat01-newtoken" in secrets


def test_refresh_claude_token_default_prints_needs_restart_hint_for_running_only(
    fleet_home, monkeypatch, capsys
):
    """The 'needs restart' hint must name the running instance (demo--develop)
    with the exact command to restart it, and must NOT name the stopped one
    (demo--piano) — it's not carrying a stale in-memory token."""
    _write_minimal_registry(fleet_home)
    _write_two_demo_instances_with_old_token(fleet_home)

    calls = []
    monkeypatch.setattr(cli, "run_streamed", _fake_runner_with_one_running_one_stopped(calls))
    monkeypatch.setattr(cli, "run_interactive", lambda cmd, **kw: 0)
    monkeypatch.setattr(cli, "input", lambda prompt: "sk-ant-oat01-newtoken", raising=False)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token"])

    assert exit_code == 0
    out = capsys.readouterr().out
    assert "demo--develop" in out
    develop_dir = fleet_home / "instances" / "demo--develop"
    assert f"cd {develop_dir} && ddev restart" in out
    piano_dir = fleet_home / "instances" / "demo--piano"
    assert f"cd {piano_dir} && ddev restart" not in out


def test_refresh_claude_token_restart_flag_restarts_only_running_instances(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)
    _write_two_demo_instances_with_old_token(fleet_home)

    calls = []
    monkeypatch.setattr(cli, "run_streamed", _fake_runner_with_one_running_one_stopped(calls))
    monkeypatch.setattr(cli, "run_interactive", lambda cmd, **kw: 0)
    monkeypatch.setattr(cli, "input", lambda prompt: "sk-ant-oat01-newtoken", raising=False)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token", "--restart"])

    assert exit_code == 0
    restart_calls = [c for c in calls if c == ["ddev", "restart"]]
    assert len(restart_calls) == 1

    for name in ("demo--develop", "demo--piano"):
        config = (fleet_home / "instances" / name / ".ddev" / "config.fleet.yaml").read_text(
            encoding="utf-8"
        )
        assert "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-newtoken" in config
        assert "CLAUDE_CODE_OAUTH_TOKEN=old-token" not in config


def test_refresh_claude_token_skips_dirs_without_fleet_config(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    (fleet_home / "instances" / "demo--legacy").mkdir(parents=True)

    monkeypatch.setattr(cli, "run_streamed", lambda cmd, **kw: RunResult(returncode=0, lines=[]))
    monkeypatch.setattr(cli, "run_interactive", lambda cmd, **kw: 0)
    monkeypatch.setattr(cli, "input", lambda prompt: "sk-ant-oat01-newtoken", raising=False)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token"])

    assert exit_code == 0
    assert "no config.fleet.yaml" in capsys.readouterr().err
    assert not (fleet_home / "instances" / "demo--legacy" / ".ddev").exists()


def test_refresh_claude_token_updates_instance_missing_instance_yml_marker(fleet_home, monkeypatch):
    """An instance whose deploy didn't finish its final step has
    `.ddev/config.fleet.yaml` + `.fleet/deploy.log` but no `.fleet/instance.yml`.
    It's still a real, running instance and must get the rotated token too —
    the propagation gate must key off config.fleet.yaml, not instance.yml."""
    _write_minimal_registry(fleet_home)

    complete_dir = fleet_home / "instances" / "demo--develop"
    (complete_dir / ".fleet").mkdir(parents=True)
    (complete_dir / ".fleet" / "instance.yml").write_text(
        "project: demo\ninstance: develop\nbranch: main\n"
        "created-at: '2026-07-01T00:00:00Z'\nlast-deployed-at: '2026-07-01T00:00:00Z'\n",
        encoding="utf-8",
    )
    (complete_dir / ".ddev").mkdir(parents=True)
    (complete_dir / ".ddev" / "config.fleet.yaml").write_text(
        "name: demo--develop\n"
        "project_tld: fleet.example.test\n"
        "web_environment:\n"
        "  - CLAUDE_CODE_OAUTH_TOKEN=old-token\n",
        encoding="utf-8",
    )

    partial_dir = fleet_home / "instances" / "demo--search-cards"
    (partial_dir / ".fleet").mkdir(parents=True)
    (partial_dir / ".fleet" / "deploy.log").write_text("deploy started\n", encoding="utf-8")
    (partial_dir / ".ddev").mkdir(parents=True)
    (partial_dir / ".ddev" / "config.fleet.yaml").write_text(
        "name: demo--search-cards\n"
        "project_tld: fleet.example.test\n"
        "web_environment:\n"
        "  - CLAUDE_CODE_OAUTH_TOKEN=old-token\n",
        encoding="utf-8",
    )
    assert not (partial_dir / ".fleet" / "instance.yml").exists()

    calls = []

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        calls.append(list(cmd))
        if cmd == ["ddev", "list", "--json-output"]:
            return RunResult(
                returncode=0,
                lines=[
                    '{"raw": [{"name": "demo--develop", "status": "running"}, '
                    '{"name": "demo--search-cards", "status": "running"}]}'
                ],
            )
        return RunResult(returncode=0, lines=[])

    monkeypatch.setattr(cli, "run_streamed", fake_runner)
    monkeypatch.setattr(cli, "run_interactive", lambda cmd, **kw: 0)
    monkeypatch.setattr(cli, "input", lambda prompt: "sk-ant-oat01-newtoken", raising=False)

    # --restart: this test's focus is instance discovery (config.fleet.yaml
    # as the gate, not instance.yml), which only exercises the restart path
    # when --restart is passed.
    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token", "--restart"])

    assert exit_code == 0

    for inst_dir in (complete_dir, partial_dir):
        config = (inst_dir / ".ddev" / "config.fleet.yaml").read_text(encoding="utf-8")
        assert "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-newtoken" in config
        assert "CLAUDE_CODE_OAUTH_TOKEN=old-token" not in config

    restart_calls = [c for c in calls if c == ["ddev", "restart"]]
    assert len(restart_calls) == 2


def test_refresh_claude_token_nonzero_exit_on_restart_failure(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    inst_dir = fleet_home / "instances" / "demo--develop"
    (inst_dir / ".fleet").mkdir(parents=True)
    (inst_dir / ".fleet" / "instance.yml").write_text(
        "project: demo\ninstance: develop\nbranch: main\n"
        "created-at: '2026-07-01T00:00:00Z'\nlast-deployed-at: '2026-07-01T00:00:00Z'\n",
        encoding="utf-8",
    )
    ddev_dir = inst_dir / ".ddev"
    ddev_dir.mkdir(parents=True)
    (ddev_dir / "config.fleet.yaml").write_text(
        "name: demo--develop\n"
        "project_tld: fleet.example.test\n"
        "web_environment:\n"
        "  - CLAUDE_CODE_OAUTH_TOKEN=old-token\n",
        encoding="utf-8",
    )

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        if cmd == ["ddev", "list", "--json-output"]:
            return RunResult(
                returncode=0, lines=['{"raw": [{"name": "demo--develop", "status": "running"}]}']
            )
        if cmd == ["ddev", "restart"]:
            return RunResult(returncode=1, lines=["boom"])
        return RunResult(returncode=0, lines=[])

    monkeypatch.setattr(cli, "run_streamed", fake_runner)
    monkeypatch.setattr(cli, "run_interactive", lambda cmd, **kw: 0)
    monkeypatch.setattr(cli, "input", lambda prompt: "sk-ant-oat01-newtoken", raising=False)

    # A restart failure can only occur when a restart is actually attempted.
    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token", "--restart"])

    assert exit_code == 1
    assert "demo--develop" in capsys.readouterr().err


def test_refresh_claude_token_raises_when_minting_fails(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)

    monkeypatch.setattr(cli, "run_interactive", lambda cmd, **kw: 1)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token"])

    assert exit_code == 1
    assert "did not produce a token" in capsys.readouterr().err


def test_refresh_claude_token_raises_when_pasted_token_invalid(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)

    monkeypatch.setattr(cli, "run_interactive", lambda cmd, **kw: 0)
    monkeypatch.setattr(cli, "input", lambda prompt: "not-a-valid-token", raising=False)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token"])

    assert exit_code == 1
    assert "did not produce a token" in capsys.readouterr().err


def test_mint_claude_token_interactive_returns_pasted_token_when_valid():
    def fake_runner(cmd, *, cwd=None, env=None):
        return 0

    def fake_reader(prompt):
        return "sk-ant-oat01-pasted"

    token = cli._mint_claude_token_interactive(runner=fake_runner, reader=fake_reader)
    assert token == "sk-ant-oat01-pasted"


def test_mint_claude_token_interactive_returns_none_when_pasted_token_invalid():
    def fake_runner(cmd, *, cwd=None, env=None):
        return 0

    def fake_reader(prompt):
        return "not-a-token"

    assert cli._mint_claude_token_interactive(runner=fake_runner, reader=fake_reader) is None


def test_mint_claude_token_interactive_returns_none_when_setup_token_exits_nonzero():
    def fake_runner(cmd, *, cwd=None, env=None):
        return 1

    def fake_reader(prompt):
        raise AssertionError("reader must not be called when setup-token failed")

    assert cli._mint_claude_token_interactive(runner=fake_runner, reader=fake_reader) is None


def test_set_claude_token_rejects_invalid_token(fleet_home, capsys):
    exit_code = cli.main(["--fleet-home", str(fleet_home), "set-claude-token", "not-a-valid-token"])

    assert exit_code == 1
    assert "sk-ant-oat01-" in capsys.readouterr().err
    assert not (fleet_home / ".secrets").exists()


def test_set_claude_token_default_does_not_restart_and_updates_config(fleet_home, monkeypatch):
    """Default behaviour (no --restart): .secrets and every instance's
    config.fleet.yaml are updated unconditionally, but no `ddev restart` is
    issued — restarting 10+ live instances just to set a token is slow, so
    that's left to the user."""
    _write_two_demo_instances_with_old_token(fleet_home)

    calls = []
    monkeypatch.setattr(cli, "run_streamed", _fake_runner_with_one_running_one_stopped(calls))

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "set-claude-token", "sk-ant-oat01-newtoken"]
    )

    assert exit_code == 0

    secrets = (fleet_home / ".secrets").read_text(encoding="utf-8")
    assert "sk-ant-oat01-newtoken" in secrets

    restart_calls = [c for c in calls if c == ["ddev", "restart"]]
    assert restart_calls == []

    for name in ("demo--develop", "demo--piano"):
        content = (fleet_home / "instances" / name / ".ddev" / "config.fleet.yaml").read_text(
            encoding="utf-8"
        )
        assert "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-newtoken" in content
        assert "CLAUDE_CODE_OAUTH_TOKEN=old-token" not in content
        assert "GIT_AUTHOR_NAME=ddev-fleet bot" in content
        assert "GIT_AUTHOR_EMAIL=bot@x" in content
        assert "FLEET_TYPESENSE_HOST=x" in content
        assert "FLEET_TYPESENSE_SEARCH_KEY=k" in content


def test_set_claude_token_default_prints_needs_restart_hint_for_running_only(
    fleet_home, monkeypatch, capsys
):
    _write_two_demo_instances_with_old_token(fleet_home)

    calls = []
    monkeypatch.setattr(cli, "run_streamed", _fake_runner_with_one_running_one_stopped(calls))

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "set-claude-token", "sk-ant-oat01-newtoken"]
    )

    assert exit_code == 0
    out = capsys.readouterr().out
    assert "demo--develop" in out
    develop_dir = fleet_home / "instances" / "demo--develop"
    assert f"cd {develop_dir} && ddev restart" in out
    piano_dir = fleet_home / "instances" / "demo--piano"
    assert f"cd {piano_dir} && ddev restart" not in out


def test_set_claude_token_restart_flag_restarts_only_running_instances(fleet_home, monkeypatch):
    _write_two_demo_instances_with_old_token(fleet_home)

    calls = []
    monkeypatch.setattr(cli, "run_streamed", _fake_runner_with_one_running_one_stopped(calls))

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "set-claude-token",
            "sk-ant-oat01-newtoken",
            "--restart",
        ]
    )

    assert exit_code == 0

    restart_calls = [c for c in calls if c == ["ddev", "restart"]]
    assert len(restart_calls) == 1

    for name in ("demo--develop", "demo--piano"):
        content = (fleet_home / "instances" / name / ".ddev" / "config.fleet.yaml").read_text(
            encoding="utf-8"
        )
        assert "CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat01-newtoken" in content
        assert "CLAUDE_CODE_OAUTH_TOKEN=old-token" not in content


def test_refresh_config_pulls_when_git_checkout(fleet_home, monkeypatch, capsys):
    cfg = fleet_home / "config"
    (cfg / ".git").mkdir(parents=True, exist_ok=True)

    calls = []

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        calls.append(list(cmd))
        return RunResult(returncode=0, lines=[])

    monkeypatch.setattr(cli, "run_streamed", fake_runner)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-config"])

    assert exit_code == 0
    assert ["git", "-C", str(cfg), "pull", "--ff-only"] in calls
    assert f"refreshed {cfg} (git pull)" in capsys.readouterr().out


def test_refresh_config_non_git_checkout_prints_message_without_runner_calls(
    fleet_home, monkeypatch, capsys
):
    cfg = fleet_home / "config"
    assert not (cfg / ".git").exists()

    calls = []

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        calls.append(list(cmd))
        return RunResult(returncode=0, lines=[])

    monkeypatch.setattr(cli, "run_streamed", fake_runner)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-config"])

    assert exit_code == 0
    assert calls == []
    assert f"{cfg} is not a git checkout" in capsys.readouterr().out


def test_secret_set_writes_per_project_secret_file(fleet_home):
    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "secret", "set", "oak", "SLACK_BOT_TOKEN", "xoxb-abc"]
    )

    assert exit_code == 0
    secret_path = fleet_home / "secrets" / "oak.env"
    assert secret_path.exists()
    assert secret_path.read_text(encoding="utf-8") == "SLACK_BOT_TOKEN=xoxb-abc\n"
    mode = stat.S_IMODE(secret_path.stat().st_mode)
    assert mode == 0o600


def test_refresh_ports_prints_no_changes_when_sync_returns_empty(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    calls = []

    def fake_sync(registry, **kwargs):
        calls.append(registry.domain)
        return caddyports.SyncResult(written=[], removed=[])

    monkeypatch.setattr(cli.caddyports, "sync", fake_sync)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-ports"])

    assert exit_code == 0
    assert calls == ["fleet.example.test"]
    assert "caddy: no changes" in capsys.readouterr().out


def test_refresh_ports_prints_written_and_removed_snippet_names(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    monkeypatch.setattr(
        cli.caddyports,
        "sync",
        lambda registry, **kw: caddyports.SyncResult(written=["typesense"], removed=["stale"]),
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-ports"])
    out = capsys.readouterr().out

    assert exit_code == 0
    assert "wrote port snippet 'typesense'" in out
    assert "removed port snippet 'stale'" in out


def test_refresh_ports_caddy_ports_error_exits_1_and_prints_to_stderr(
    fleet_home, monkeypatch, capsys
):
    _write_minimal_registry(fleet_home)

    def failing_sync(registry, **kw):
        raise CaddyPortsError("caddy validate exploded")

    monkeypatch.setattr(cli.caddyports, "sync", failing_sync)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-ports"])

    assert exit_code == 1
    assert "caddy validate exploded" in capsys.readouterr().err


def test_refresh_ports_skips_ufw_sync_when_helper_absent(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    monkeypatch.setattr(
        cli.caddyports, "sync", lambda registry, **kw: caddyports.SyncResult([], [])
    )
    monkeypatch.setattr(cli, "_FLEET_UFW_SYNC_HELPER", Path("/does/not/exist/fleet-ufw-sync"))

    calls = []

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        calls.append(list(cmd))
        return RunResult(returncode=0, lines=[])

    monkeypatch.setattr(cli, "run_streamed", fake_runner)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-ports"])

    assert exit_code == 0
    assert calls == []
    assert "ufw:" not in capsys.readouterr().out


def test_refresh_ports_runs_ufw_sync_when_helper_present(fleet_home, monkeypatch, capsys, tmp_path):
    _write_minimal_registry(fleet_home)
    helper = tmp_path / "fleet-ufw-sync"
    helper.write_text("", encoding="utf-8")
    monkeypatch.setattr(cli, "_FLEET_UFW_SYNC_HELPER", helper)
    monkeypatch.setattr(
        cli.caddyports, "sync", lambda registry, **kw: caddyports.SyncResult([], [])
    )

    calls = []

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        calls.append(list(cmd))
        return RunResult(returncode=0, lines=[])

    monkeypatch.setattr(cli, "run_streamed", fake_runner)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-ports"])

    assert exit_code == 0
    assert ["sudo", str(helper)] in calls
    assert "ufw: synced" in capsys.readouterr().out


def test_refresh_ports_ufw_sync_failure_exits_1(fleet_home, monkeypatch, capsys, tmp_path):
    _write_minimal_registry(fleet_home)
    helper = tmp_path / "fleet-ufw-sync"
    helper.write_text("", encoding="utf-8")
    monkeypatch.setattr(cli, "_FLEET_UFW_SYNC_HELPER", helper)
    monkeypatch.setattr(
        cli.caddyports, "sync", lambda registry, **kw: caddyports.SyncResult([], [])
    )
    monkeypatch.setattr(
        cli, "run_streamed", lambda cmd, **kw: RunResult(returncode=1, lines=["permission denied"])
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-ports"])

    assert exit_code == 1
    assert "permission denied" in capsys.readouterr().err


def test_refresh_ports_real_sync_honours_isolated_snippet_dir(fleet_home, monkeypatch):
    """Regression: `_cmd_refresh_ports` must pass `snippet_dir=` explicitly to
    `caddyports.sync()`, not rely on `sync()`'s bound default.

    Every other test in this module mocks `cli.caddyports.sync` wholesale, so
    none of them would notice if the call site regressed to the bare
    `caddyports.sync(registry, runner=runner)` form — `sync()`'s own
    `snippet_dir=DEFAULT_PORTS_SNIPPET_DIR` default parameter is bound at
    caddyports.py's import time, so conftest's autouse monkeypatch of the
    *module attribute* `caddyports.DEFAULT_PORTS_SNIPPET_DIR` would silently
    stop applying, and the real (unmocked) `sync()` would `mkdir()` the real
    `/etc/caddy/fleet/ports` on the host. This test lets the REAL `sync()` run
    (no mock) and asserts it landed in the isolated tmp_path directory, not
    the real default.
    """
    _write_minimal_registry(fleet_home)
    real_default = Path("/etc/caddy/fleet/ports")
    assert not real_default.exists(), "precondition: real Caddy dir must not pre-exist"

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-ports"])

    assert exit_code == 0
    # sync() unconditionally does `snippet_dir.mkdir(parents=True,
    # exist_ok=True)` before comparing wanted/existing snippets, so if the
    # isolated dir (patched by conftest's autouse `_isolate_caddy_paths`
    # fixture) was actually used, it now exists on disk.
    assert caddyports.DEFAULT_PORTS_SNIPPET_DIR.exists()
    assert caddyports.DEFAULT_PORTS_SNIPPET_DIR.is_relative_to(fleet_home.parent)
    # And the real system default must remain untouched — this is the part
    # that fails if the call site reverts to the bare `caddyports.sync(...)`
    # default.
    assert not real_default.exists()


def _make_instance_dir(fleet_home, instance_id):
    paths = FleetPaths.from_home(fleet_home)
    (paths.instances / instance_id).mkdir(parents=True, exist_ok=True)
    return paths


def test_shell_list_prints_instance_ids(fleet_home, capsys):
    _make_instance_dir(fleet_home, "alpha--main")
    _make_instance_dir(fleet_home, "zeta--main")

    exit_code = cli.main(["--fleet-home", str(fleet_home), "shell", "--list"])

    assert exit_code == 0
    assert capsys.readouterr().out == "alpha--main\nzeta--main\n"


def test_shell_no_instance_execs_bash_in_fleet_home(fleet_home, monkeypatch):
    recorder = []
    monkeypatch.setattr(
        cli.shell_mod, "exec_in_dir", lambda argv, cwd: recorder.append((argv, cwd))
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "shell"])

    assert exit_code == 0
    assert recorder == [(["bash"], fleet_home)]


def test_shell_with_instance_execs_bash_in_instance_dir(fleet_home, monkeypatch):
    paths = _make_instance_dir(fleet_home, "demo--develop")
    recorder = []
    monkeypatch.setattr(
        cli.shell_mod, "exec_in_dir", lambda argv, cwd: recorder.append((argv, cwd))
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "shell", "demo--develop"])

    assert exit_code == 0
    assert recorder == [(["bash"], paths.instances / "demo--develop")]


def test_shell_unknown_instance_exits_1(fleet_home, monkeypatch, capsys):
    monkeypatch.setattr(cli.shell_mod, "exec_in_dir", lambda argv, cwd: None)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "shell", "demo--nonexistent"])

    assert exit_code == 1
    assert "demo--nonexistent" in capsys.readouterr().err


def test_ddev_dispatch_passes_through_trailing_args(fleet_home, monkeypatch):
    paths = _make_instance_dir(fleet_home, "demo--develop")
    recorder = []
    monkeypatch.setattr(
        cli.shell_mod, "exec_in_dir", lambda argv, cwd: recorder.append((argv, cwd))
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "ddev", "demo--develop", "drush", "uli"])

    assert exit_code == 0
    assert recorder == [(["ddev", "drush", "uli"], paths.instances / "demo--develop")]


def test_ddev_dispatch_defaults_to_ssh(fleet_home, monkeypatch):
    paths = _make_instance_dir(fleet_home, "demo--develop")
    recorder = []
    monkeypatch.setattr(
        cli.shell_mod, "exec_in_dir", lambda argv, cwd: recorder.append((argv, cwd))
    )

    exit_code = cli.main(["--fleet-home", str(fleet_home), "ddev", "demo--develop"])

    assert exit_code == 0
    assert recorder == [(["ddev", "ssh"], paths.instances / "demo--develop")]


def test_ddev_missing_instance_prompts_and_execs_selection(fleet_home, monkeypatch):
    paths = _make_instance_dir(fleet_home, "demo--develop")
    recorder = []
    monkeypatch.setattr(
        cli.shell_mod, "exec_in_dir", lambda argv, cwd: recorder.append((argv, cwd))
    )
    monkeypatch.setattr(cli.shell_mod, "prompt_for_instance", lambda paths: "demo--develop")

    exit_code = cli.main(["--fleet-home", str(fleet_home), "ddev"])

    assert exit_code == 0
    assert recorder == [(["ddev", "ssh"], paths.instances / "demo--develop")]


def test_ddev_unknown_instance_exits_1(fleet_home, monkeypatch, capsys):
    monkeypatch.setattr(cli.shell_mod, "exec_in_dir", lambda argv, cwd: None)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "ddev", "demo--nonexistent"])

    assert exit_code == 1
    assert "demo--nonexistent" in capsys.readouterr().err


def _stub_caddyauth_rotate(monkeypatch, recorder):
    def fake_rotate(username, password, *, runner=None):
        recorder.append((username, password))

    monkeypatch.setattr(cli.caddyauth, "rotate", fake_rotate)


def test_set_admin_password_calls_rotate_with_given_password(fleet_home, monkeypatch, capsys):
    """Break-glass regression guard: `set-admin-password` must keep working
    in basic mode with NO fleet.yml/config dir present at all — it must
    never call `load_registry()` (which would raise RegistryError) outside
    the Authelia branch."""
    assert not (fleet_home / "config" / "fleet.yml").exists()
    recorder = []
    _stub_caddyauth_rotate(monkeypatch, recorder)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "set-admin-password", "correct-horse-battery"]
    )

    assert exit_code == 0
    assert recorder == [("admin", "correct-horse-battery")]
    assert "updated" in capsys.readouterr().out


def test_set_admin_password_propagates_caddy_auth_error(fleet_home, monkeypatch, capsys):
    from fleet.core.errors import CaddyAuthError

    def raising_rotate(username, password, *, runner=None):
        raise CaddyAuthError("caddy validate failed")

    monkeypatch.setattr(cli.caddyauth, "rotate", raising_rotate)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "set-admin-password", "whatever"])

    assert exit_code == 1
    assert "caddy validate failed" in capsys.readouterr().err


def test_rotate_admin_password_generates_and_prints_password_once(fleet_home, monkeypatch, capsys):
    recorder = []
    _stub_caddyauth_rotate(monkeypatch, recorder)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "rotate-admin-password"])

    assert exit_code == 0
    assert len(recorder) == 1
    username, generated_password = recorder[0]
    assert username == "admin"
    assert len(generated_password) >= 20  # secrets.token_urlsafe(18) -> 24 chars

    out = capsys.readouterr().out
    assert generated_password in out
    assert out.count(generated_password) == 1  # printed exactly once
    assert "will not be shown again" in out


def test_rotate_admin_password_generates_different_password_each_call(fleet_home, monkeypatch):
    recorder = []
    _stub_caddyauth_rotate(monkeypatch, recorder)

    cli.main(["--fleet-home", str(fleet_home), "rotate-admin-password"])
    cli.main(["--fleet-home", str(fleet_home), "rotate-admin-password"])

    assert recorder[0][1] != recorder[1][1]


def test_rotate_admin_password_propagates_caddy_auth_error(fleet_home, monkeypatch, capsys):
    from fleet.core.errors import CaddyAuthError

    def raising_rotate(username, password, *, runner=None):
        raise CaddyAuthError("caddy reload failed")

    monkeypatch.setattr(cli.caddyauth, "rotate", raising_rotate)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "rotate-admin-password"])

    assert exit_code == 1
    assert "reload failed" in capsys.readouterr().err


def test_set_admin_password_authelia_mode_writes_admin_and_users_yml_no_caddy_rotate(
    fleet_home, monkeypatch, capsys
):
    """In Authelia mode, set-admin-password must write admin.yml + re-render
    users.yml and must NEVER call caddyauth.rotate (no Caddy snippet, no
    reload — Authelia's file watcher picks up users.yml on its own)."""
    _write_authelia_registry(fleet_home)

    def _unexpected_rotate(*args, **kwargs):
        raise AssertionError("caddyauth.rotate must not be called in Authelia mode")

    monkeypatch.setattr(cli.caddyauth, "rotate", _unexpected_rotate)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "set-admin-password", "correct-horse-battery"]
    )

    assert exit_code == 0
    assert "Authelia mode" in capsys.readouterr().out

    from fleet.core import authelia
    from fleet.core import instances as instances_mod_real

    paths = instances_mod_real.FleetPaths.from_home(fleet_home)
    admin = authelia.load_admin(path=paths.authelia_admin)
    assert admin is not None
    assert admin.name == "admin"
    users_data = authelia._load_existing_hashes(paths.authelia_users)
    assert "admin" in users_data


def test_rotate_admin_password_authelia_mode_writes_admin_and_users_yml_no_caddy_rotate(
    fleet_home, monkeypatch, capsys
):
    _write_authelia_registry(fleet_home)

    def _unexpected_rotate(*args, **kwargs):
        raise AssertionError("caddyauth.rotate must not be called in Authelia mode")

    monkeypatch.setattr(cli.caddyauth, "rotate", _unexpected_rotate)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "rotate-admin-password"])

    assert exit_code == 0
    out = capsys.readouterr().out
    assert "Authelia mode" in out
    assert "will not be shown again" in out


# --- refresh-instance-config dispatch ---


def test_refresh_instance_config_dispatch_default_does_not_restart(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)

    recorder = []

    def fake_refresh(paths, registry, instance_id, *, restart=False, runner=None):
        recorder.append((instance_id, restart))

    monkeypatch.setattr(cli.instances_mod, "refresh_instance_config", fake_refresh)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "refresh-instance-config", "demo--develop"]
    )

    assert exit_code == 0
    assert recorder == [("demo--develop", False)]


def test_refresh_instance_config_dispatch_restart_flag(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)

    recorder = []

    def fake_refresh(paths, registry, instance_id, *, restart=False, runner=None):
        recorder.append((instance_id, restart))

    monkeypatch.setattr(cli.instances_mod, "refresh_instance_config", fake_refresh)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "refresh-instance-config",
            "demo--develop",
            "--restart",
        ]
    )

    assert exit_code == 0
    assert recorder == [("demo--develop", True)]


def test_refresh_instance_config_prints_restart_hint_when_not_restarting(
    fleet_home, monkeypatch, capsys
):
    _write_minimal_registry(fleet_home)
    inst_dir = fleet_home / "instances" / "demo--develop"
    inst_dir.mkdir(parents=True)

    monkeypatch.setattr(cli.instances_mod, "refresh_instance_config", lambda *a, **kw: None)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "refresh-instance-config", "demo--develop"]
    )

    assert exit_code == 0
    assert f"cd {inst_dir} && ddev restart" in capsys.readouterr().out


def test_refresh_instance_config_no_restart_hint_when_restart_flag_passed(
    fleet_home, monkeypatch, capsys
):
    _write_minimal_registry(fleet_home)
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)

    monkeypatch.setattr(cli.instances_mod, "refresh_instance_config", lambda *a, **kw: None)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "refresh-instance-config",
            "demo--develop",
            "--restart",
        ]
    )

    assert exit_code == 0
    assert "ddev restart" not in capsys.readouterr().out


def test_refresh_instance_config_unknown_instance_exits_1(fleet_home, capsys):
    _write_minimal_registry(fleet_home)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "refresh-instance-config", "demo--nonexistent"]
    )

    assert exit_code == 1
    assert "demo--nonexistent" in capsys.readouterr().err


def test_tmux_dispatch_reconciles_and_attaches(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)
    seen = {}
    monkeypatch.setattr(
        cli.tmux_mod, "reconcile", lambda paths, ids, **kw: seen.setdefault("ids", sorted(ids))
    )
    monkeypatch.setattr(cli.tmux_mod, "attach", lambda: seen.setdefault("attached", True))

    exit_code = cli.main(["--fleet-home", str(fleet_home), "tmux"])

    assert exit_code == 0
    assert seen["ids"] == ["demo--develop"]
    assert seen["attached"] is True


def test_tmux_dispatch_reconciles_without_any_tty_resolver(fleet_home, monkeypatch):
    """FLE-6: `fleet tmux` never types tty commands, so it neither loads the
    registry for them nor passes a `tty_for` into reconcile — and works even
    with no fleet.yml at all (the workspace must always let you in)."""
    # Deliberately no registry written.
    seen = {}
    monkeypatch.setattr(
        cli.tmux_mod, "reconcile", lambda paths, ids, **kw: seen.update(kw=kw, ids=list(ids))
    )
    monkeypatch.setattr(cli.tmux_mod, "attach", lambda: seen.setdefault("attached", True))

    exit_code = cli.main(["--fleet-home", str(fleet_home), "tmux"])

    assert exit_code == 0
    assert seen["kw"] == {}
    assert seen["attached"] is True


def test_tmux_ensure_reconciles_without_attaching(fleet_home, monkeypatch):
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)
    seen = {}
    monkeypatch.setattr(
        cli.tmux_mod, "reconcile", lambda paths, ids, **kw: seen.setdefault("ids", sorted(ids))
    )

    def no_attach():
        raise AssertionError("--ensure must never attach")

    monkeypatch.setattr(cli.tmux_mod, "attach", no_attach)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "tmux", "--ensure"])

    assert exit_code == 0
    assert seen["ids"] == ["demo--develop"]


def test_tmux_ensure_creates_session_when_missing(fleet_home, monkeypatch):
    from fleet.core.runner import RunResult
    from tests.conftest import FakeRunner

    fake = FakeRunner(
        default=RunResult(0, ["%9"]), scripted={"tmux has-session -t fleet": RunResult(1, [])}
    )
    real_reconcile = cli.tmux_mod.reconcile
    monkeypatch.setattr(
        cli.tmux_mod, "reconcile", lambda paths, ids, **kw: real_reconcile(paths, ids, runner=fake)
    )
    monkeypatch.setattr(cli.tmux_mod, "attach", lambda: (_ for _ in ()).throw(AssertionError()))

    exit_code = cli.main(["--fleet-home", str(fleet_home), "tmux", "--ensure"])

    joined = [" ".join(str(c) for c in call["cmd"]) for call in fake.calls]
    assert exit_code == 0
    assert any(c.startswith("tmux new-session -d -s fleet") for c in joined)
    assert not any(c.startswith("tmux send-keys") for c in joined)


def test_tmux_ensure_is_a_noop_session_wise_when_it_already_exists(fleet_home, monkeypatch):
    from fleet.core.runner import RunResult
    from tests.conftest import FakeRunner

    fake = FakeRunner(default=RunResult(0, ["%9"]))  # has-session -> 0: session exists
    real_reconcile = cli.tmux_mod.reconcile
    monkeypatch.setattr(
        cli.tmux_mod, "reconcile", lambda paths, ids, **kw: real_reconcile(paths, ids, runner=fake)
    )
    monkeypatch.setattr(cli.tmux_mod, "attach", lambda: (_ for _ in ()).throw(AssertionError()))

    exit_code = cli.main(["--fleet-home", str(fleet_home), "tmux", "--ensure"])

    joined = [" ".join(str(c) for c in call["cmd"]) for call in fake.calls]
    assert exit_code == 0
    assert not any(c.startswith("tmux new-session") for c in joined)


def test_cli_deploy_and_redeploy_may_create_the_tmux_session(fleet_home, monkeypatch):
    """The CLI (unlike the daemon) passes create_tmux_session=True so a
    deploy with tty commands and no session can create it (outside
    fleet.service's cgroup)."""
    _write_minimal_registry(fleet_home)
    seen = {}

    def fake_deploy(*a, **kw):
        seen["deploy"] = kw.get("create_tmux_session")
        return "https://demo--develop.fleet.example.test"

    def fake_redeploy(*a, **kw):
        seen["redeploy"] = kw.get("create_tmux_session")
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(cli.instances_mod, "deploy", fake_deploy)
    monkeypatch.setattr(cli.instances_mod, "redeploy", fake_redeploy)
    (fleet_home / "instances" / "demo--develop").mkdir(parents=True)

    cli.main(["--fleet-home", str(fleet_home), "deploy", "demo", "default", "--branch=main"])
    cli.main(["--fleet-home", str(fleet_home), "redeploy", "demo--develop"])

    assert seen == {"deploy": True, "redeploy": True}


def test_tmux_sidebar_dispatch(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)
    seen = {}
    monkeypatch.setattr(
        cli.tmux_sidebar,
        "run",
        lambda paths, window, **kw: seen.update(window=window, once=kw.get("once")),
    )

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "tmux-sidebar", "--window", "general", "--once"]
    )

    assert exit_code == 0
    assert seen == {"window": "general", "once": True}


def test_build_parser_accepts_reboot_notify_and_test_flag():
    parser = cli._build_parser()
    args = parser.parse_args(["reboot-notify", "--test"])
    assert args.command == "reboot-notify"
    assert args.test is True

    args = parser.parse_args(["reboot-notify"])
    assert args.test is False


def test_build_parser_reboot_notify_interval_hours_defaults_and_overrides():
    parser = cli._build_parser()

    args = parser.parse_args(["reboot-notify"])
    assert args.interval_hours == 24.0

    args = parser.parse_args(["reboot-notify", "--interval-hours", "6"])
    assert args.interval_hours == 6.0


def test_cmd_reboot_notify_reads_to_from_env_file(tmp_path, monkeypatch):
    (tmp_path / "reboot-notify.env").write_text(
        "MSMTP_TO=ops@example.test\nMSMTP_FROM=fleet@example.test\n", encoding="utf-8"
    )
    captured = {}

    def fake_reboot_notify(**kwargs):
        captured.update(kwargs)
        return True

    monkeypatch.setattr(cli, "reboot_mod", type("M", (), {"reboot_notify": fake_reboot_notify}))
    args = argparse.Namespace(test=False, interval_hours=24.0)
    rc = cli._cmd_reboot_notify(tmp_path, args)
    assert rc == 0
    assert captured["to_addr"] == "ops@example.test"
    assert captured["from_addr"] == "fleet@example.test"


def test_cmd_reboot_notify_threads_interval_hours_through(tmp_path, monkeypatch):
    (tmp_path / "reboot-notify.env").write_text(
        "MSMTP_TO=ops@example.test\nMSMTP_FROM=fleet@example.test\n", encoding="utf-8"
    )
    captured = {}

    def fake_reboot_notify(**kwargs):
        captured.update(kwargs)
        return True

    monkeypatch.setattr(cli, "reboot_mod", type("M", (), {"reboot_notify": fake_reboot_notify}))
    args = argparse.Namespace(test=False, interval_hours=6.0)
    rc = cli._cmd_reboot_notify(tmp_path, args)
    assert rc == 0
    assert captured["interval_hours"] == 6.0


def test_webhook_secret_prints_once_and_refuses(fleet_home, capsys):
    _write_minimal_registry(fleet_home)
    base = ["--fleet-home", str(fleet_home), "webhook", "secret", "demo"]

    assert cli.main(base) == 0
    first = capsys.readouterr().out
    assert "URL: https://fleet.example.test/hooks/jira/demo" in first
    secret = first.splitlines()[0]
    assert len(secret) >= 32
    # demo has no jira_hooks: the secret is still made, but the operator is told.
    assert "no jira_hooks" in first

    assert cli.main(base) == 1
    captured = capsys.readouterr()
    assert "--rotate" in captured.err + captured.out
    assert secret not in captured.err + captured.out

    assert cli.main([*base, "--rotate"]) == 0
    rotated = capsys.readouterr().out.splitlines()[0]
    assert rotated != secret


def test_webhook_secret_unknown_project(fleet_home, capsys):
    _write_minimal_registry(fleet_home)
    rc = cli.main(["--fleet-home", str(fleet_home), "webhook", "secret", "nope"])
    assert rc == 1
    assert "nope" in capsys.readouterr().err


def _write_registry_with_bitbucket_rule(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(
        """\
fleet:
  domain: fleet.example.test

projects:
  demo:
    git: git@example.test:org/demo.git
    templates:
      default: {}
    bitbucket_hooks:
      - {on_event: "pullrequest:approved", repo: acme/demo, action: run-pipeline, pattern: renovate-merge, ref: develop}
""",  # noqa: E501
        encoding="utf-8",
    )


def test_webhook_secret_bitbucket_prints_once_and_refuses(fleet_home, capsys):
    from fleet.core.webhooks import WebhookStore

    _write_minimal_registry(fleet_home)
    store = WebhookStore.from_paths(FleetPaths.from_home(fleet_home))
    jira_secret = store.create_secret("demo")
    base = ["--fleet-home", str(fleet_home), "webhook", "secret", "demo", "--source", "bitbucket"]

    assert cli.main(base) == 0
    first = capsys.readouterr().out
    assert "URL: https://fleet.example.test/hooks/bitbucket/demo" in first
    secret = first.splitlines()[0]
    assert len(secret) >= 32 and secret != jira_secret
    assert "no bitbucket_hooks" in first
    assert "bitbucket-token demo" in first  # no pipeline token stored yet
    assert store.read_secret("demo", "bitbucket") == secret
    assert store.read_secret("demo") == jira_secret  # Jira secret untouched

    assert cli.main(base) == 1
    captured = capsys.readouterr()
    assert "--rotate" in captured.err + captured.out
    assert secret not in captured.err + captured.out

    assert cli.main([*base, "--rotate"]) == 0
    assert capsys.readouterr().out.splitlines()[0] != secret
    assert store.read_secret("demo") == jira_secret


def test_webhook_secret_default_source_is_jira(fleet_home, capsys):
    from fleet.core.webhooks import WebhookStore

    _write_minimal_registry(fleet_home)
    assert cli.main(["--fleet-home", str(fleet_home), "webhook", "secret", "demo"]) == 0
    out = capsys.readouterr().out
    assert "/hooks/jira/demo" in out and "bitbucket" not in out
    store = WebhookStore.from_paths(FleetPaths.from_home(fleet_home))
    assert store.read_secret("demo", "bitbucket") is None


def test_webhook_bitbucket_token_from_stdin_is_not_echoed(fleet_home, capsys, monkeypatch):
    import io

    from fleet.core.webhooks import WebhookStore

    _write_registry_with_bitbucket_rule(fleet_home)
    monkeypatch.setattr("sys.stdin", io.StringIO("  ATCTT-secret-token  \n"))
    rc = cli.main(["--fleet-home", str(fleet_home), "webhook", "bitbucket-token", "demo"])
    assert rc == 0
    captured = capsys.readouterr()
    assert "ATCTT-secret-token" not in captured.out + captured.err
    assert "stored" in captured.out
    assert "no bitbucket_hooks" not in captured.out  # the project has a rule
    store = WebhookStore.from_paths(FleetPaths.from_home(fleet_home))
    assert store.read_pipeline_token("demo") == "ATCTT-secret-token"
    path = FleetPaths.from_home(fleet_home).webhooks / "secrets.env"
    assert stat.S_IMODE(path.stat().st_mode) == 0o600


def test_webhook_bitbucket_token_tty_uses_hidden_prompt(fleet_home, capsys, monkeypatch):
    from fleet.core.webhooks import WebhookStore

    _write_minimal_registry(fleet_home)
    monkeypatch.setattr("sys.stdin", type("Tty", (), {"isatty": lambda self: True})())
    prompts = []
    monkeypatch.setattr(cli.getpass, "getpass", lambda prompt: prompts.append(prompt) or "tok-1")
    rc = cli.main(["--fleet-home", str(fleet_home), "webhook", "bitbucket-token", "demo"])
    assert rc == 0
    assert len(prompts) == 1
    out = capsys.readouterr().out
    assert "tok-1" not in out
    assert "no bitbucket_hooks" in out  # warned: the token is unused for now
    store = WebhookStore.from_paths(FleetPaths.from_home(fleet_home))
    assert store.read_pipeline_token("demo") == "tok-1"


def test_webhook_bitbucket_token_rejects_empty_and_unknown_project(fleet_home, capsys, monkeypatch):
    import io

    _write_minimal_registry(fleet_home)
    monkeypatch.setattr("sys.stdin", io.StringIO("\n"))
    assert cli.main(["--fleet-home", str(fleet_home), "webhook", "bitbucket-token", "demo"]) == 1
    assert "non-empty" in capsys.readouterr().err
    assert cli.main(["--fleet-home", str(fleet_home), "webhook", "bitbucket-token", "nope"]) == 1
    assert "nope" in capsys.readouterr().err


def test_webhook_bitbucket_token_not_accepted_on_argv(fleet_home, capsys):
    import pytest

    _write_minimal_registry(fleet_home)
    with pytest.raises(SystemExit):
        cli.main(["--fleet-home", str(fleet_home), "webhook", "bitbucket-token", "demo", "tok"])


def test_webhook_log_bitbucket_source(fleet_home, capsys):
    from fleet.core.webhooks import WebhookStore

    _write_minimal_registry(fleet_home)
    base = ["--fleet-home", str(fleet_home), "webhook", "log", "--source", "bitbucket"]
    assert cli.main(base) == 0
    assert "no webhook deliveries logged" in capsys.readouterr().out

    store = WebhookStore.from_paths(FleetPaths.from_home(fleet_home))
    store.append_log(
        {
            "project": "demo",
            "event": "pullrequest:approved",
            "repo": "acme/demo",
            "pr": 7,
            "branch": "renovate/x",
            "result": "triggered",
            "pipeline": 17,
        },
        "bitbucket",
    )
    assert cli.main(base) == 0
    line = capsys.readouterr().out.strip()
    assert "pullrequest:approved" in line and "acme/demo#7" in line and "triggered" in line
    assert line.endswith("17")
    # The default (Jira) log stays empty.
    assert cli.main(["--fleet-home", str(fleet_home), "webhook", "log"]) == 0
    assert "no webhook deliveries logged" in capsys.readouterr().out


def test_webhook_log_empty_and_entries(fleet_home, capsys):
    from fleet.core.webhooks import WebhookStore

    _write_minimal_registry(fleet_home)
    base = ["--fleet-home", str(fleet_home), "webhook", "log"]

    assert cli.main(base) == 0
    assert "no webhook deliveries logged" in capsys.readouterr().out

    store = WebhookStore.from_paths(FleetPaths.from_home(fleet_home))
    store.append_log(
        {
            "project": "demo",
            "issue": "FLE-1",
            "from": "To Do",
            "to": "Dispatched",
            "result": "accepted",
            "instance": "demo--fle-1",
        }
    )
    store.append_log(
        {
            "project": "other",
            "issue": "FLE-2",
            "from": "To Do",
            "to": "Done",
            "result": "ignored",
            "reason": "no matching rule",
        }
    )

    assert cli.main(base) == 0
    out = capsys.readouterr().out
    assert "FLE-1" in out and "FLE-2" in out
    assert "To Do→Dispatched" in out
    assert "demo--fle-1" in out
    assert "no matching rule" in out

    assert cli.main([*base, "--project", "demo"]) == 0
    out = capsys.readouterr().out
    assert "FLE-1" in out and "FLE-2" not in out

    assert cli.main([*base, "-n", "1"]) == 0
    out = capsys.readouterr().out
    assert "FLE-2" in out and "FLE-1" not in out
