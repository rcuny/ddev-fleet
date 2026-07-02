from fleet import cli
from fleet.core.errors import DeployError
from fleet.core.instances import InstanceStatus
from fleet.core.registry import Registry


def _write_minimal_registry(fleet_home):
    path = fleet_home / "fleet.yml"
    path.write_text(
        f"""\
fleet:
  domain: fleet.example.test
  assets_path: {fleet_home / "assets"}
  instances_path: {fleet_home / "instances"}

projects:
  demo:
    git: git@example.test:org/demo.git
    instances: {{}}
""",
        encoding="utf-8",
    )


def test_deploy_happy_path_prints_url(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)

    def fake_deploy(paths, registry, project, instance, *, branch=None, fresh=False, force=False, runner=None):
        return "https://demo--develop.fleet.example.test"

    monkeypatch.setattr(cli.instances_mod, "deploy", fake_deploy)

    exit_code = cli.main(
        ["--fleet-home", str(fleet_home), "deploy", "demo", "develop", "--branch=main"]
    )

    assert exit_code == 0
    assert "https://demo--develop.fleet.example.test" in capsys.readouterr().out


def test_deploy_fleet_error_exits_1_and_prints_to_stderr(fleet_home, monkeypatch, capsys):
    _write_minimal_registry(fleet_home)

    def failing_deploy(*args, **kwargs):
        raise DeployError("something specific went wrong")

    monkeypatch.setattr(cli.instances_mod, "deploy", failing_deploy)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "deploy", "demo", "develop"])

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
    monkeypatch.setattr(cli.instances_mod, "list_instances", lambda paths, registry, **kw: fake_statuses)

    exit_code = cli.main(["--fleet-home", str(fleet_home), "list"])

    assert exit_code == 0
    out = capsys.readouterr().out
    assert "demo--develop" in out
    assert "running" in out
    assert "https://demo--develop.fleet.example.test" in out


def test_project_add_writes_registry(fleet_home):
    _write_minimal_registry(fleet_home)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "project",
            "add",
            "newproj",
            "--git=git@example.test:org/newproj.git",
        ]
    )

    assert exit_code == 0
    reloaded = Registry.load(fleet_home / "fleet.yml")
    assert reloaded.has_project("newproj") is True
    assert reloaded.git_url("newproj") == "git@example.test:org/newproj.git"


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
    assert (fleet_home / "assets").is_dir()
    assert (fleet_home / "instances").is_dir()
    assert (fleet_home / "locks").is_dir()
    registry = Registry.load(fleet_home / "fleet.yml")
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
    (flag_home / "assets").mkdir(parents=True)
    (flag_home / "locks").mkdir(parents=True)
    (flag_home / "fleet.yml").write_text(
        f"""\
fleet:
  domain: fleet.example.test
  assets_path: {flag_home / "assets"}
  instances_path: {flag_home / "instances"}

projects:
  demo:
    git: git@example.test:org/demo.git
    instances: {{}}
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
    assert received_paths[0].registry == flag_home / "fleet.yml"


def test_usage_error_exits_2(fleet_home):
    _write_minimal_registry(fleet_home)

    import pytest

    with pytest.raises(SystemExit) as exc:
        cli.main(["--fleet-home", str(fleet_home), "definitely-not-a-command"])

    assert exc.value.code == 2


def test_project_add_post_deploy_round_trip(fleet_home):
    _write_minimal_registry(fleet_home)

    exit_code = cli.main(
        [
            "--fleet-home",
            str(fleet_home),
            "project",
            "add",
            "newproj",
            "--git=git@example.test:org/newproj.git",
            "--post-deploy=composer install",
            "--post-deploy=drush deploy",
        ]
    )

    assert exit_code == 0
    reloaded = Registry.load(fleet_home / "fleet.yml")
    assert reloaded.has_project("newproj") is True
    project_block = reloaded._data["projects"]["newproj"]
    assert project_block["post_deploy"] == ["composer install", "drush deploy"]
