import json
import subprocess

import pytest

from fleet.core import ddev
from fleet.core.errors import FleetError
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def test_start_composes_correct_argv(tmp_path):
    fake = FakeRunner()
    ddev.start(tmp_path, runner=fake)
    assert fake.calls == [
        {
            "cmd": ["ddev", "start"],
            "cwd": tmp_path,
            "env": None,
            "log_path": None,
            "input_text": None,
            "timeout": None,
        }
    ]


def test_stop_composes_correct_argv(tmp_path):
    fake = FakeRunner()
    ddev.stop(tmp_path, runner=fake)
    assert fake.calls[0]["cmd"] == ["ddev", "stop"]
    assert fake.calls[0]["cwd"] == tmp_path


def test_delete_composes_correct_argv(tmp_path):
    fake = FakeRunner()
    ddev.delete(tmp_path, runner=fake)
    assert fake.calls[0]["cmd"] == ["ddev", "delete", "--omit-snapshot", "--yes"]
    assert fake.calls[0]["cwd"] == tmp_path


def test_list_projects_parses_sample_json():
    sample = json.dumps({"raw": [{"name": "oak--develop", "status": "running"}]})
    fake = FakeRunner(default=RunResult(returncode=0, lines=[sample]))
    assert ddev.list_projects(runner=fake) == [{"name": "oak--develop", "status": "running"}]


def test_list_projects_tolerant_of_empty_output():
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    assert ddev.list_projects(runner=fake) == []


def test_list_projects_tolerant_of_garbage_output():
    fake = FakeRunner(default=RunResult(returncode=0, lines=["not json"]))
    assert ddev.list_projects(runner=fake) == []


def test_ram_usage_aggregates_two_containers_of_one_project():
    lines = [
        json.dumps({"Name": "ddev-oak--develop-web", "MemUsage": "150MiB / 2GiB"}),
        json.dumps({"Name": "ddev-oak--develop-db", "MemUsage": "100MiB / 2GiB"}),
    ]
    fake = FakeRunner(default=RunResult(returncode=0, lines=lines))
    assert ddev.ram_usage(runner=fake) == {"oak--develop": 250}


def test_ram_usage_returns_empty_dict_on_garbage():
    fake = FakeRunner(default=RunResult(returncode=0, lines=["not json at all"]))
    assert ddev.ram_usage(runner=fake) == {}


def test_ram_usage_returns_empty_dict_when_runner_raises():
    def exploding_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        raise FileNotFoundError("docker not found")

    assert ddev.ram_usage(runner=exploding_runner) == {}


def test_list_projects_timeout_defaults_to_none():
    fake = FakeRunner(default=RunResult(returncode=0, lines=[json.dumps({"raw": []})]))
    ddev.list_projects(runner=fake)
    assert fake.calls[0]["timeout"] is None


def test_list_projects_forwards_timeout_to_runner():
    fake = FakeRunner(default=RunResult(returncode=0, lines=[json.dumps({"raw": []})]))
    ddev.list_projects(timeout=ddev.LIST_TIMEOUT, runner=fake)
    assert fake.calls[0]["timeout"] == ddev.LIST_TIMEOUT


def test_list_projects_wraps_timeout_expired_in_fleet_error():
    def timing_out_runner(cmd, **kwargs):
        raise subprocess.TimeoutExpired(cmd, kwargs.get("timeout"))

    with pytest.raises(FleetError) as exc_info:
        ddev.list_projects(timeout=ddev.LIST_TIMEOUT, runner=timing_out_runner)
    assert f"timed out after {ddev.LIST_TIMEOUT:g}s" in str(exc_info.value)


def test_ram_usage_timeout_defaults_to_none():
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    ddev.ram_usage(runner=fake)
    assert fake.calls[0]["timeout"] is None


def test_ram_usage_forwards_timeout_to_runner():
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    ddev.ram_usage(timeout=ddev.STATS_TIMEOUT, runner=fake)
    assert fake.calls[0]["timeout"] == ddev.STATS_TIMEOUT


def test_ram_usage_returns_empty_dict_and_warns_on_timeout(capsys):
    def timing_out_runner(cmd, **kwargs):
        raise subprocess.TimeoutExpired(cmd, kwargs.get("timeout"))

    assert ddev.ram_usage(timeout=ddev.STATS_TIMEOUT, runner=timing_out_runner) == {}
    err = capsys.readouterr().err
    assert "'docker stats' timed out" in err
    assert f"{ddev.STATS_TIMEOUT:g}s" in err
    assert "RAM column unavailable" in err


def test_ram_usage_separates_two_projects():
    lines = [
        json.dumps({"Name": "ddev-oak--develop-web", "MemUsage": "150MiB / 2GiB"}),
        json.dumps({"Name": "ddev-oak--develop-db", "MemUsage": "100MiB / 2GiB"}),
        json.dumps({"Name": "ddev-other--main-web", "MemUsage": "200MiB / 2GiB"}),
        json.dumps({"Name": "ddev-other--main-db", "MemUsage": "50MiB / 2GiB"}),
    ]
    fake = FakeRunner(default=RunResult(returncode=0, lines=lines))
    result = ddev.ram_usage(runner=fake)
    assert result == {"oak--develop": 250, "other--main": 250}


def test_start_forwards_log_path(tmp_path):
    fake = FakeRunner()
    log_path = tmp_path / "deploy.log"
    ddev.start(tmp_path, log_path=log_path, runner=fake)
    assert fake.calls[0]["log_path"] == log_path


def test_stop_forwards_log_path(tmp_path):
    fake = FakeRunner()
    log_path = tmp_path / "deploy.log"
    ddev.stop(tmp_path, log_path=log_path, runner=fake)
    assert fake.calls[0]["log_path"] == log_path


def test_start_forwards_timeout_to_runner(tmp_path):
    fake = FakeRunner()
    ddev.start(tmp_path, timeout=1800, runner=fake)
    assert fake.calls[0]["timeout"] == 1800


def test_stop_forwards_timeout_to_runner(tmp_path):
    fake = FakeRunner()
    ddev.stop(tmp_path, timeout=1800, runner=fake)
    assert fake.calls[0]["timeout"] == 1800


def test_start_timeout_defaults_to_none(tmp_path):
    fake = FakeRunner()
    ddev.start(tmp_path, runner=fake)
    assert fake.calls[0]["timeout"] is None


def test_start_wraps_timeout_expired_in_fleet_error(tmp_path):
    def timing_out_runner(
        cmd, *, cwd=None, env=None, log_path=None, echo=True, input_text=None, timeout=None
    ):
        raise subprocess.TimeoutExpired(cmd, timeout)

    with pytest.raises(FleetError) as exc_info:
        ddev.start(tmp_path, timeout=5, runner=timing_out_runner)
    assert "timed out after 5s" in str(exc_info.value)
    assert tmp_path.name in str(exc_info.value)


def test_stop_wraps_timeout_expired_in_fleet_error(tmp_path):
    def timing_out_runner(
        cmd, *, cwd=None, env=None, log_path=None, echo=True, input_text=None, timeout=None
    ):
        raise subprocess.TimeoutExpired(cmd, timeout)

    with pytest.raises(FleetError) as exc_info:
        ddev.stop(tmp_path, timeout=5, runner=timing_out_runner)
    assert "timed out after 5s" in str(exc_info.value)
    assert tmp_path.name in str(exc_info.value)


def test_restart_composes_correct_argv(tmp_path):
    fake = FakeRunner()
    ddev.restart(tmp_path, runner=fake)
    assert fake.calls == [
        {
            "cmd": ["ddev", "restart"],
            "cwd": tmp_path,
            "env": None,
            "log_path": None,
            "input_text": None,
            "timeout": None,
        }
    ]


# --- is_port_conflict() — the --retry-port-conflict detection predicate ---


def test_is_port_conflict_matches_port_already_allocated():
    assert ddev.is_port_conflict("Bind for 127.0.0.1:32839 failed: port is already allocated")


def test_is_port_conflict_matches_container_networking_marker():
    assert ddev.is_port_conflict(
        "failed to set up container networking: driver failed programming "
        "external connectivity on endpoint ddev-oak-a-db"
    )


def test_is_port_conflict_is_case_insensitive():
    assert ddev.is_port_conflict("PORT IS ALREADY ALLOCATED")


def test_is_port_conflict_false_for_unrelated_failure():
    assert not ddev.is_port_conflict("Error: some other ddev start failure\nexit status 1")
