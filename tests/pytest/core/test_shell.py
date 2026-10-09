import pytest

from fleet.core import shell
from fleet.core.errors import FleetError
from fleet.core.instances import FleetPaths


def _make_instance_dirs(fleet_home, *instance_ids):
    paths = FleetPaths.from_home(fleet_home)
    for instance_id in instance_ids:
        (paths.instances / instance_id).mkdir(parents=True)
    return paths


def test_list_instance_ids_empty_when_no_instances_dir(tmp_path):
    paths = FleetPaths.from_home(tmp_path / "fleet-home")
    assert shell.list_instance_ids(paths) == []


def test_list_instance_ids_sorted(fleet_home):
    paths = _make_instance_dirs(fleet_home, "zeta--main", "alpha--main")
    assert shell.list_instance_ids(paths) == ["alpha--main", "zeta--main"]


def test_resolve_instance_dir_unknown_instance_lists_available(fleet_home):
    paths = _make_instance_dirs(fleet_home, "demo--develop")

    with pytest.raises(FleetError) as excinfo:
        shell.resolve_instance_dir(paths, "demo--nonexistent")

    assert "demo--nonexistent" in excinfo.value.message
    assert "demo--develop" in excinfo.value.message


def test_resolve_instance_dir_no_instances_deployed(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    with pytest.raises(FleetError) as excinfo:
        shell.resolve_instance_dir(paths, "demo--develop")
    assert "none deployed" in excinfo.value.message


def test_shell_argv_no_instance_targets_fleet_home(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    argv, cwd = shell.shell_argv(paths, None)
    assert argv == ["bash"]
    assert cwd == paths.home


def test_shell_argv_with_instance_targets_instance_dir(fleet_home):
    paths = _make_instance_dirs(fleet_home, "demo--develop")
    argv, cwd = shell.shell_argv(paths, "demo--develop")
    assert argv == ["bash"]
    assert cwd == paths.instances / "demo--develop"


def test_shell_argv_unknown_instance_raises(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    with pytest.raises(FleetError):
        shell.shell_argv(paths, "demo--nonexistent")


def test_ddev_argv_defaults_to_ssh(fleet_home):
    paths = _make_instance_dirs(fleet_home, "demo--develop")
    argv, cwd = shell.ddev_argv(paths, "demo--develop", [])
    assert argv == ["ddev", "ssh"]
    assert cwd == paths.instances / "demo--develop"


def test_ddev_argv_passes_through_trailing_args(fleet_home):
    paths = _make_instance_dirs(fleet_home, "demo--develop")
    argv, cwd = shell.ddev_argv(paths, "demo--develop", ["drush", "uli"])
    assert argv == ["ddev", "drush", "uli"]
    assert cwd == paths.instances / "demo--develop"


def test_ddev_argv_unknown_instance_raises(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    with pytest.raises(FleetError):
        shell.ddev_argv(paths, "demo--nonexistent", [])


def test_prompt_for_instance_no_instances_raises(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    with pytest.raises(FleetError):
        shell.prompt_for_instance(paths)


def test_prompt_for_instance_returns_selected_id(fleet_home):
    paths = _make_instance_dirs(fleet_home, "alpha--main", "zeta--main")
    picked = shell.prompt_for_instance(paths, reader=lambda _prompt: "2")
    assert picked == "zeta--main"


def test_prompt_for_instance_invalid_selection_raises(fleet_home):
    paths = _make_instance_dirs(fleet_home, "alpha--main")
    with pytest.raises(FleetError):
        shell.prompt_for_instance(paths, reader=lambda _prompt: "99")


def test_prompt_for_instance_non_numeric_selection_raises(fleet_home):
    paths = _make_instance_dirs(fleet_home, "alpha--main")
    with pytest.raises(FleetError):
        shell.prompt_for_instance(paths, reader=lambda _prompt: "nope")


def test_prompt_for_instance_eof_raises_fleet_error(fleet_home):
    paths = _make_instance_dirs(fleet_home, "alpha--main")

    def raising_reader(_prompt):
        raise EOFError

    with pytest.raises(FleetError):
        shell.prompt_for_instance(paths, reader=raising_reader)


def test_exec_in_dir_chdirs_and_execs(monkeypatch, fleet_home):
    paths = _make_instance_dirs(fleet_home, "demo--develop")
    target = paths.instances / "demo--develop"

    calls = {}

    def fake_chdir(path):
        calls["chdir"] = path

    def fake_execvp(file, args):
        calls["execvp"] = (file, args)

    monkeypatch.setattr(shell.os, "chdir", fake_chdir)
    monkeypatch.setattr(shell.os, "execvp", fake_execvp)

    shell.exec_in_dir(["ddev", "ssh"], target)

    assert calls["chdir"] == target
    assert calls["execvp"] == ("ddev", ["ddev", "ssh"])
