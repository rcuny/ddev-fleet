"""`fleet schedule list | run | history | pause | resume` (FLE-31)."""

import json

import pytest

from fleet import cli
from fleet.core.instances import FleetPaths
from fleet.core.locks import instance_lock
from fleet.core.schedules import ScheduleStore

REGISTRY = """\
fleet:
  domain: ddev3.fleet.pm

projects:
  demo:
    git: git@example.test:org/demo.git

schedules:
  nightly:
    cron: "0 3 * * *"
    timezone: Europe/Paris
    action: redeploy
    instance: demo--main
  off:
    cron: "0 4 * * *"
    timezone: UTC
    action: redeploy
    instance: demo--main
    enabled: false
  elsewhere:
    cron: "0 5 * * *"
    timezone: UTC
    action: redeploy
    instance: demo--main
    servers: [ddev4.fleet.pm]
"""


@pytest.fixture
def home(fleet_home):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(REGISTRY, encoding="utf-8")
    return fleet_home


def _run(home, *args):
    return cli.main(["--fleet-home", str(home), "schedule", *args])


def _store(home) -> ScheduleStore:
    return ScheduleStore(FleetPaths.from_home(home).schedules)


def test_list_shows_state_and_next_run(home, capsys):
    assert _run(home, "list") == 0
    out = capsys.readouterr().out
    lines = out.splitlines()
    assert lines[0].split()[:3] == ["NAME", "STATE", "CRON"]
    nightly = next(line for line in lines if line.startswith("nightly"))
    assert "active" in nightly and "Europe/Paris" in nightly and "redeploy demo--main" in nightly
    assert "(in " in nightly and "03:00" in nightly
    assert "disabled in fleet.yml" in next(line for line in lines if line.startswith("off"))
    assert "not on this server" in next(line for line in lines if line.startswith("elsewhere"))


def test_list_without_schedules(fleet_home, capsys):
    paths = FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.registry.write_text(REGISTRY.split("schedules:")[0], encoding="utf-8")
    assert _run(fleet_home, "list") == 0
    assert "no schedules declared in fleet.yml" in capsys.readouterr().out


def test_pause_and_resume_edit_the_state_file(home, capsys):
    assert _run(home, "pause", "nightly") == 0
    assert _store(home).load_state()["nightly"]["paused"] is True
    _run(home, "list")
    assert "paused" in next(
        line for line in capsys.readouterr().out.splitlines() if line.startswith("nightly")
    )
    assert _run(home, "resume", "nightly") == 0
    entry = _store(home).load_state()["nightly"]
    assert entry["paused"] is False and entry["last_fired"]


def test_unknown_and_foreign_schedules_are_errors(home, capsys):
    for command in ("pause", "resume", "run"):
        assert _run(home, command, "ghost") == 1
        assert "unknown schedule 'ghost'" in capsys.readouterr().err
        assert _run(home, command, "elsewhere") == 1
        assert "not on this server" in capsys.readouterr().err
    assert _store(home).load_state() == {}


def test_run_executes_in_process_and_records_manual_cli(home, monkeypatch, capsys):
    paths = FleetPaths.from_home(home)
    (paths.instances / "demo--main").mkdir(parents=True)
    calls = []

    def fake_redeploy(paths_, registry, instance_id, **kw):
        calls.append((instance_id, kw))
        return "https://demo--main.ddev3.fleet.pm"

    monkeypatch.setattr(cli.instances_mod, "redeploy", fake_redeploy)
    assert _run(home, "run", "nightly") == 0
    assert "nightly: succeeded" in capsys.readouterr().out
    assert calls == [("demo--main", {"create_tmux_session": True})]
    (entry,) = _store(home).tail_history(10)
    assert entry["schedule"] == "nightly"
    assert entry["trigger"] == "manual-cli"
    assert entry["result"] == "succeeded"
    assert entry["job_id"] is None
    assert _store(home).load_state()["nightly"]["last_run"]["result"] == "succeeded"


def test_run_works_for_a_disabled_schedule(home, monkeypatch):
    paths = FleetPaths.from_home(home)
    (paths.instances / "demo--main").mkdir(parents=True)
    monkeypatch.setattr(cli.instances_mod, "redeploy", lambda *a, **k: "ok")
    assert _run(home, "run", "off") == 0


def test_run_on_a_missing_instance_is_recorded_as_failed(home, capsys):
    assert _run(home, "run", "nightly") == 1
    assert "failed: instance demo--main not found" in capsys.readouterr().err
    (entry,) = _store(home).tail_history(10)
    assert entry["result"] == "failed" and entry["trigger"] == "manual-cli"


def test_run_respects_the_instance_lock(home, monkeypatch, capsys):
    paths = FleetPaths.from_home(home)
    (paths.instances / "demo--main").mkdir(parents=True)
    called = []
    monkeypatch.setattr(cli.instances_mod, "redeploy", lambda *a, **k: called.append(1))
    with instance_lock(paths.locks, "demo--main"):
        assert _run(home, "run", "nightly") == 1
    assert "skipped: instance busy" in capsys.readouterr().err
    assert called == []
    assert _store(home).tail_history(10)[-1]["result"] == "skipped"


def test_run_reports_a_failing_redeploy(home, monkeypatch, capsys):
    from fleet.core.errors import DeployError

    paths = FleetPaths.from_home(home)
    (paths.instances / "demo--main").mkdir(parents=True)

    def boom(*a, **k):
        raise DeployError("clone failed")

    monkeypatch.setattr(cli.instances_mod, "redeploy", boom)
    assert _run(home, "run", "nightly") == 1
    assert "failed: clone failed" in capsys.readouterr().err


def test_history_filters_and_limits(home, capsys):
    store = _store(home)
    for i in range(5):
        store.append_history(
            {
                "schedule": "nightly" if i % 2 == 0 else "off",
                "ts": f"2026-10-0{i + 1}T03:00:00Z",
                "trigger": "cron",
                "instance": "demo--main",
                "result": "succeeded",
                "reason": None,
                "job_id": f"job{i}",
            }
        )
    assert _run(home, "history") == 0
    assert len(capsys.readouterr().out.strip().splitlines()) == 5
    assert _run(home, "history", "nightly") == 0
    lines = capsys.readouterr().out.strip().splitlines()
    assert len(lines) == 3 and all("nightly" in line for line in lines)
    assert _run(home, "history", "-n", "2") == 0
    lines = capsys.readouterr().out.strip().splitlines()
    assert len(lines) == 2 and "job4" in lines[-1]  # oldest first, newest last


def test_history_empty(home, capsys):
    assert _run(home, "history") == 0
    assert "no scheduled runs recorded" in capsys.readouterr().out


def test_history_survives_garbage_lines(home, capsys):
    store = _store(home)
    store.root.mkdir(parents=True)
    store.history_file.write_text(
        "garbage\n" + json.dumps({"schedule": "nightly", "result": "failed"}) + "\n",
        encoding="utf-8",
    )
    assert _run(home, "history") == 0
    assert "failed" in capsys.readouterr().out


@pytest.mark.parametrize(
    "command", [["list"], ["pause", "nightly"], ["resume", "nightly"], ["run", "nightly"]]
)
@pytest.mark.parametrize("kind", ["corrupt", "unreadable"])
def test_bad_state_makes_the_cli_fail_with_the_message(home, capsys, monkeypatch, command, kind):
    store = _store(home)
    store.root.mkdir(parents=True, exist_ok=True)
    if kind == "corrupt":
        store.state_file.write_text("{ not json", encoding="utf-8")
    else:
        store.state_file.mkdir()
    called = []
    monkeypatch.setattr(cli.instances_mod, "redeploy", lambda *a, **k: called.append(a))
    (FleetPaths.from_home(home).instances / "demo--main").mkdir(parents=True)
    assert _run(home, *command) == 1
    err = capsys.readouterr().err
    assert str(store.state_file) in err and "Fix:" in err
    assert called == []
    assert not store.state_file.is_file() or store.state_file.read_text() == "{ not json"


def test_history_still_works_with_a_bad_state(home, capsys):
    store = _store(home)
    store.append_history({"schedule": "nightly", "result": "failed"})
    store.state_file.write_text("{ not json", encoding="utf-8")
    assert _run(home, "history") == 0
    assert "failed" in capsys.readouterr().out
