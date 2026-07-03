import json

from fleet.core import ddev
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def test_start_composes_correct_argv(tmp_path):
    fake = FakeRunner()
    ddev.start(tmp_path, runner=fake)
    assert fake.calls == [
        {"cmd": ["ddev", "start"], "cwd": tmp_path, "env": None, "log_path": None}
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


def test_restart_composes_correct_argv(tmp_path):
    fake = FakeRunner()
    ddev.restart(tmp_path, runner=fake)
    assert fake.calls == [
        {"cmd": ["ddev", "restart"], "cwd": tmp_path, "env": None, "log_path": None}
    ]
