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
    paths = instances.FleetPaths.from_home(fleet_home)
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
    return Registry.load(paths.registry)


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
            "ddev list --json-output": RunResult(returncode=0, lines=[json.dumps({"raw": []})]),
            "docker stats --no-stream --format {{json .}}": RunResult(returncode=0, lines=[]),
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


def test_list_instances_prefers_live_git_branch_with_fallback(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    inst_root = fleet_home / "instances"
    _write_instance(inst_root, "demo--develop", "demo", "develop", "main")  # recorded=main
    _write_instance(inst_root, "demo--piano", "demo", "piano", "feature-x")  # recorded=feature-x

    list_json = json.dumps({"raw": []})
    git_key = " ".join(
        ["git", "-C", str(inst_root / "demo--develop"), "rev-parse", "--abbrev-ref", "HEAD"]
    )
    fake = FakeRunner(
        scripted={
            "ddev list --json-output": RunResult(returncode=0, lines=[list_json]),
            "docker stats --no-stream --format {{json .}}": RunResult(returncode=0, lines=[]),
            git_key: RunResult(returncode=0, lines=["hotfix-9"]),  # live != recorded
        }
        # demo--piano's git call is unscripted -> default RunResult(0, []) -> live "" -> fallback
    )

    statuses = instances.list_instances(paths, registry, runner=fake)
    by_id = {s.instance_id: s for s in statuses}

    assert by_id["demo--develop"].branch == "hotfix-9"  # live wins over recorded "main"
    assert by_id["demo--piano"].branch == "feature-x"  # falls back to recorded


def test_list_instances_includes_short_head(fleet_home):
    registry = _registry(fleet_home)
    paths = instances.FleetPaths.from_home(fleet_home)
    inst_root = fleet_home / "instances"
    _write_instance(inst_root, "demo--develop", "demo", "develop", "main")

    list_json = json.dumps({"raw": []})
    head_key = " ".join(
        ["git", "-C", str(inst_root / "demo--develop"), "rev-parse", "--short", "HEAD"]
    )
    fake = FakeRunner(
        scripted={
            "ddev list --json-output": RunResult(returncode=0, lines=[list_json]),
            "docker stats --no-stream --format {{json .}}": RunResult(returncode=0, lines=[]),
            head_key: RunResult(returncode=0, lines=["9201b89b53"]),
        }
    )

    statuses = instances.list_instances(paths, registry, runner=fake)
    assert statuses[0].head == "9201b89b53"


def test_list_instances_missing_instances_dir_returns_empty_early(fleet_home):
    registry = _registry(fleet_home)
    base_paths = instances.FleetPaths.from_home(fleet_home)
    nonexistent = fleet_home / "does-not-exist"
    paths = instances.FleetPaths(
        home=base_paths.home,
        registry=base_paths.registry,
        assets=base_paths.assets,
        instances=nonexistent,
        logs=base_paths.logs,
        secrets=base_paths.secrets,
        project_secrets=base_paths.project_secrets,
        locks=base_paths.locks,
        push_key_dir=base_paths.push_key_dir,
        host_config=base_paths.host_config,
    )

    assert not nonexistent.exists()
    assert instances.list_instances(paths, registry, runner=FakeRunner()) == []
