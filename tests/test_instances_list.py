import json

from fleet.core import instances
from fleet.core.registry import Registry
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def _write_instance(instances_root, instance_id, project, instance, branch):
    instance_dir = instances_root / instance_id
    fleet_dir = instance_dir / ".fleet"
    fleet_dir.mkdir(parents=True)
    (fleet_dir / "instance.yml").write_text(
        f"project: {project}\n"
        f"instance: {instance}\n"
        f"branch: {branch}\n"
        "created-at: '2026-07-01T00:00:00Z'\n"
        "last-deployed-at: '2026-07-01T00:00:00Z'\n",
        encoding="utf-8",
    )
    return instance_dir


def _registry(fleet_home):
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
    return Registry.load(path)


def test_list_instances_reports_running_and_deployed_with_ram(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    _write_instance(fleet_home / "instances", "demo--develop", "demo", "develop", "main")
    _write_instance(fleet_home / "instances", "demo--piano", "demo", "piano", "feature-x")

    list_json = json.dumps({"raw": [{"name": "demo--develop", "status": "running"}]})
    stats_lines = [json.dumps({"Name": "ddev-demo--develop-web", "MemUsage": "200MiB / 2GiB"})]
    fake = FakeRunner(
        scripted={
            "ddev list --json-output": RunResult(returncode=0, lines=[list_json]),
            "docker stats --no-stream --format {{json .}}": RunResult(
                returncode=0, lines=stats_lines
            ),
        }
    )

    statuses = instances.list_instances(paths, registry, runner=fake)
    by_id = {s.instance_id: s for s in statuses}

    assert by_id["demo--develop"].state == "running"
    assert by_id["demo--develop"].ram_mib == 200
    assert by_id["demo--develop"].url == "https://demo--develop.fleet.example.test"
    assert by_id["demo--piano"].state == "deployed"
    assert by_id["demo--piano"].ram_mib is None


def test_list_instances_empty_when_no_instances_dir(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    assert instances.list_instances(paths, registry, runner=FakeRunner()) == []


def test_list_instances_degrades_gracefully_when_runner_raises(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    _write_instance(fleet_home / "instances", "demo--develop", "demo", "develop", "main")

    def raising_runner(cmd, **kwargs):
        raise RuntimeError("docker daemon unreachable")

    statuses = instances.list_instances(paths, registry, runner=raising_runner)

    assert len(statuses) == 1
    assert statuses[0].instance_id == "demo--develop"
    assert statuses[0].state == "deployed"
    assert statuses[0].ram_mib is None


def test_list_instances_fallback_for_dir_without_instance_yml(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)

    # Create instance dir WITHOUT .fleet/instance.yml
    instance_dir = fleet_home / "instances" / "demo--legacy"
    instance_dir.mkdir(parents=True)

    # Script the runner with empty/degraded responses
    fake = FakeRunner(
        scripted={
            "ddev list --json-output": RunResult(
                returncode=0, lines=[json.dumps({"raw": []})]
            ),
            "docker stats --no-stream --format {{json .}}": RunResult(
                returncode=0, lines=[]
            ),
        }
    )

    statuses = instances.list_instances(paths, registry, runner=fake)

    assert len(statuses) == 1
    assert statuses[0].instance_id == "demo--legacy"
    assert statuses[0].project == "demo"
    assert statuses[0].instance == "legacy"
    assert statuses[0].branch == ""
    assert statuses[0].state == "deployed"
    assert statuses[0].ram_mib is None
