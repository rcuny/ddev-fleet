from fleet import cli
from fleet.core.errors import DeployError
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
        fresh=False,
        force=False,
        runner=None,
    ):
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(cli.instances_mod, "deploy", fake_deploy)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "deploy", "demo", "default", "--branch=main"]
    )

    assert exit_code == 0
    assert "https://demo--develop.fleet.example.test" in capsys.readouterr().out


def test_deploy_fleet_error_exits_1_and_prints_to_stderr(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)

    def failing_deploy(*args, **kwargs):
        raise DeployError("something specific went wrong")

    monkeypatch.setattr(cli.instances_mod, "deploy", failing_deploy)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "deploy", "demo", "default"])

    assert exit_code == 1
    assert "something specific went wrong" in capsys.readouterr().err


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
    assert registry.project_keys() == []
    assert not (fleet_home / ".secrets").exists()


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

    def fake_snapshot(paths, registry, instance_id, *, dest_rel="dumps/db.sql.gz", runner=None):
        recorder.append((instance_id, dest_rel))
        return fleet_home / "config" / "assets" / "demo" / dest_rel

    monkeypatch.setattr(cli.instances_mod, "snapshot", fake_snapshot)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "snapshot", "demo--develop"])

    assert exit_code == 0
    assert recorder == [("demo--develop", "dumps/db.sql.gz")]


def test_snapshot_dispatch_custom_dest_rel(fleet_home, monkeypatch):
    _write_minimal_registry(fleet_home)
    recorder = []

    def fake_snapshot(paths, registry, instance_id, *, dest_rel="dumps/db.sql.gz", runner=None):
        recorder.append((instance_id, dest_rel))
        return fleet_home / "config" / "assets" / "demo" / dest_rel

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

    def failing_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        return RunResult(returncode=1, lines=[])

    monkeypatch.setattr(cli, "run_streamed", failing_runner)

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


def test_refresh_claude_token_rewrites_config_and_restarts_running_instance(
    fleet_home, monkeypatch
):
    _write_minimal_registry(fleet_home)
    for name in ("demo--develop", "demo--piano"):
        inst_dir = fleet_home / "instances" / name
        (inst_dir / ".fleet").mkdir(parents=True)
        (inst_dir / ".fleet" / "instance.yml").write_text(
            f"project: demo\ninstance: {name.split('--')[1]}\nbranch: main\n"
            "created-at: '2026-07-01T00:00:00Z'\nlast-deployed-at: '2026-07-01T00:00:00Z'\n",
            encoding="utf-8",
        )

    calls = []

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        calls.append(list(cmd))
        if cmd == ["claude", "setup-token"]:
            return RunResult(returncode=0, lines=["sk-ant-oat01-newtoken"])
        if cmd == ["ddev", "list", "--json-output"]:
            return RunResult(
                returncode=0,
                lines=[
                    '{"raw": [{"name": "demo--develop", "status": "running"}, '
                    '{"name": "demo--piano", "status": "stopped"}]}'
                ],
            )
        return RunResult(returncode=0, lines=[])

    monkeypatch.setattr(cli, "run_streamed", fake_runner)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token"])

    assert exit_code == 0
    restart_calls = [c for c in calls if c == ["ddev", "restart"]]
    assert len(restart_calls) == 1

    for name in ("demo--develop", "demo--piano"):
        config = (fleet_home / "instances" / name / ".ddev" / "config.fleet.yaml").read_text(
            encoding="utf-8"
        )
        assert "sk-ant-oat01-newtoken" in config

    secrets = (fleet_home / ".secrets").read_text(encoding="utf-8")
    assert "sk-ant-oat01-newtoken" in secrets


def test_refresh_claude_token_skips_dirs_without_instance_yml(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    (fleet_home / "instances" / "demo--legacy").mkdir(parents=True)

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        if cmd == ["claude", "setup-token"]:
            return RunResult(returncode=0, lines=["sk-ant-oat01-newtoken"])
        return RunResult(returncode=0, lines=[])

    monkeypatch.setattr(cli, "run_streamed", fake_runner)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token"])

    assert exit_code == 0
    assert "no .fleet/instance.yml" in capsys.readouterr().err
    assert not (fleet_home / "instances" / "demo--legacy" / ".ddev").exists()


def test_refresh_claude_token_nonzero_exit_on_restart_failure(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)
    inst_dir = fleet_home / "instances" / "demo--develop"
    (inst_dir / ".fleet").mkdir(parents=True)
    (inst_dir / ".fleet" / "instance.yml").write_text(
        "project: demo\ninstance: develop\nbranch: main\n"
        "created-at: '2026-07-01T00:00:00Z'\nlast-deployed-at: '2026-07-01T00:00:00Z'\n",
        encoding="utf-8",
    )

    def fake_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        if cmd == ["claude", "setup-token"]:
            return RunResult(returncode=0, lines=["sk-ant-oat01-newtoken"])
        if cmd == ["ddev", "list", "--json-output"]:
            return RunResult(
                returncode=0, lines=['{"raw": [{"name": "demo--develop", "status": "running"}]}']
            )
        if cmd == ["ddev", "restart"]:
            return RunResult(returncode=1, lines=["boom"])
        return RunResult(returncode=0, lines=[])

    monkeypatch.setattr(cli, "run_streamed", fake_runner)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token"])

    assert exit_code == 1
    assert "demo--develop" in capsys.readouterr().err


def test_refresh_claude_token_raises_when_minting_fails(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)

    def failing_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        return RunResult(returncode=1, lines=[])

    monkeypatch.setattr(cli, "run_streamed", failing_runner)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "refresh-claude-token"])

    assert exit_code == 1
    assert "did not produce a token" in capsys.readouterr().err


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
