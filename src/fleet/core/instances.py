"""Orchestrates deploy/destroy/start/stop/list using the other core modules
(spec §5, §6, §11)."""

import shutil
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

from ruamel.yaml import YAML

from fleet.core import assets as assets_mod
from fleet.core import ddev, gitops
from fleet.core.errors import DeployError, FleetError
from fleet.core.fleetconfig import (
    ensure_git_exclude,
    write_fleet_config,
    write_settings_local,
    write_web_build,
)
from fleet.core.locks import instance_lock
from fleet.core.registry import Registry
from fleet.core.runner import run_streamed
from fleet.core.secrets import read_secrets
from fleet.core.tokens import build_context, env_vars

_yaml = YAML()
_yaml.default_flow_style = False


@dataclass
class FleetPaths:
    home: Path
    registry: Path
    assets: Path
    instances: Path
    secrets: Path
    locks: Path
    push_key_dir: Path

    @classmethod
    def from_home(cls, home: Path) -> "FleetPaths":
        config_dir = home / "config"
        return cls(
            home=home,
            registry=config_dir / "fleet.yml",
            assets=config_dir / "assets",
            instances=home / "instances",
            secrets=home / ".secrets",
            locks=home / "locks",
            push_key_dir=home / ".push-key",
        )


def _now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _append_log(log_path: Path, message: str) -> None:
    log_path.parent.mkdir(parents=True, exist_ok=True)
    with open(log_path, "a", encoding="utf-8") as fh:
        fh.write(f"[{_now_iso()}] {message}\n")


def _write_instance_yaml(instance_dir: Path, project: str, instance: str, branch: str) -> None:
    fleet_dir = instance_dir / ".fleet"
    fleet_dir.mkdir(parents=True, exist_ok=True)
    info_path = fleet_dir / "instance.yml"

    created_at = _now_iso()
    if info_path.exists():
        with open(info_path, "r", encoding="utf-8") as fh:
            existing = _yaml.load(fh) or {}
        created_at = existing.get("created-at", created_at)

    data = {
        "project": project,
        "instance": instance,
        "branch": branch,
        "created-at": created_at,
        "last-deployed-at": _now_iso(),
    }
    with open(info_path, "w", encoding="utf-8") as fh:
        _yaml.dump(data, fh)


def deploy(
    paths: FleetPaths,
    registry: Registry,
    project: str,
    template: str | None = None,
    *,
    branch: str | None = None,
    label: str | None = None,
    fresh: bool = False,
    force: bool = False,
    runner=run_streamed,
) -> str:
    if not registry.has_project(project):
        raise DeployError(f"unknown project {project!r}")

    default_template, default_branch = registry.project_defaults(project)
    resolved_template = template or default_template
    resolved_branch = branch or default_branch
    if not resolved_template:
        raise DeployError(
            f"template is required to deploy project {project!r} "
            "(no default_template configured)"
        )
    if not resolved_branch:
        raise DeployError(
            f"branch is required to deploy project {project!r} (no default_branch configured)"
        )

    resolved = registry.resolve(project, resolved_template, resolved_branch, label=label)
    inst_id = resolved.instance_id
    instance_dir = paths.instances / inst_id
    deploy_log = instance_dir / ".fleet" / "deploy.log"

    with instance_lock(paths.locks, inst_id):
        if fresh and instance_dir.exists():
            _destroy_locked(paths, inst_id, runner=runner)

        clone_result = None
        if instance_dir.exists():
            gitops.update(
                instance_dir, resolved.branch, force=force, log_path=deploy_log, runner=runner
            )
        else:
            # Clone must run before anything (even the deploy log) is created
            # inside instance_dir — git refuses to clone into a non-empty dir.
            # So no log_path here: clone's captured output is appended to the
            # deploy log after the fact, once instance_dir exists.
            clone_result = gitops.clone(
                registry.git_url(project),
                resolved.branch,
                instance_dir,
                runner=runner,
            )

        _append_log(
            deploy_log,
            f"deploy start: project={project} template={resolved_template} "
            f"label={resolved.label} branch={resolved.branch}",
        )
        if clone_result is not None:
            for line in clone_result.lines:
                _append_log(deploy_log, line)

        secrets = read_secrets(paths.secrets)
        claude_token = secrets.get("CLAUDE_CODE_OAUTH_TOKEN")
        if not claude_token:
            raise DeployError(
                f"CLAUDE_CODE_OAUTH_TOKEN not found in {paths.secrets}; "
                "run 'fleet init' or set it before deploying"
            )
        write_fleet_config(instance_dir, inst_id, registry.domain, claude_token)
        write_web_build(instance_dir)
        excludes = [
            ".ddev/config.fleet.yaml",
            ".ddev/web-build/Dockerfile.fleet-claude",
            ".fleet/",
        ]
        for injected in write_settings_local(instance_dir, registry.domain):
            excludes.append(str(injected.relative_to(instance_dir)))
        ensure_git_exclude(instance_dir, excludes)

        context = build_context(project, resolved.label, resolved.branch, registry.domain)
        copied = assets_mod.inject(paths.assets / project, instance_dir, context, runner=runner)
        ensure_git_exclude(instance_dir, [str(path.relative_to(instance_dir)) for path in copied])

        start_result = ddev.start(instance_dir, log_path=deploy_log, runner=runner)
        if start_result.returncode != 0:
            raise DeployError(
                f"ddev start failed in {instance_dir} with exit code {start_result.returncode}"
            )

        env = env_vars(context)
        for command in resolved.post_deploy:
            result = runner(
                ["bash", "-c", command],
                cwd=instance_dir,
                env=env,
                log_path=deploy_log,
            )
            if result.returncode != 0:
                raise DeployError(
                    f"post_deploy command {command!r} failed with exit code {result.returncode}"
                )

        _write_instance_yaml(instance_dir, project, resolved.label, resolved.branch)
        _append_log(deploy_log, "deploy complete")

    return f"https://{inst_id}.{registry.domain}"


def _destroy_locked(paths: FleetPaths, instance_id: str, *, runner=run_streamed) -> None:
    """Destroy an instance's containers and directory. Assumes the caller
    already holds the instance lock (used by deploy()'s --fresh path to
    avoid re-entering instance_lock, which would deadlock/raise)."""
    instance_dir = paths.instances / instance_id
    if instance_dir.exists():
        try:
            ddev.delete(instance_dir, runner=runner)
        except Exception:
            pass  # tolerate failure if containers are already gone
        shutil.rmtree(instance_dir, ignore_errors=True)


def destroy(
    paths: FleetPaths, registry: Registry, instance_id: str, *, runner=run_streamed
) -> None:
    instance_dir = paths.instances / instance_id
    if not instance_dir.exists():
        raise FleetError(f"unknown instance {instance_id!r}: {instance_dir} does not exist")
    with instance_lock(paths.locks, instance_id):
        _destroy_locked(paths, instance_id, runner=runner)

    lock_path = paths.locks / f"{instance_id}.lock"
    if lock_path.exists():
        lock_path.unlink()


def start(paths: FleetPaths, registry: Registry, instance_id: str, *, runner=run_streamed) -> None:
    instance_dir = paths.instances / instance_id
    if not instance_dir.exists():
        raise FleetError(f"instance directory not found for {instance_id!r}")
    with instance_lock(paths.locks, instance_id):
        result = ddev.start(instance_dir, runner=runner)
        if result.returncode != 0:
            raise FleetError(
                f"ddev start failed for {instance_id!r} with exit code {result.returncode}"
            )


def stop(paths: FleetPaths, registry: Registry, instance_id: str, *, runner=run_streamed) -> None:
    instance_dir = paths.instances / instance_id
    if not instance_dir.exists():
        raise FleetError(f"instance directory not found for {instance_id!r}")
    with instance_lock(paths.locks, instance_id):
        result = ddev.stop(instance_dir, runner=runner)
        if result.returncode != 0:
            raise FleetError(
                f"ddev stop failed for {instance_id!r} with exit code {result.returncode}"
            )


def snapshot(
    paths: FleetPaths,
    registry: Registry,
    instance_id: str,
    *,
    dest_rel: str = "dumps/db.sql.gz",
    runner=run_streamed,
) -> Path:
    instance_dir = paths.instances / instance_id
    if not instance_dir.exists():
        raise FleetError(f"instance directory not found for {instance_id!r}")

    info_path = instance_dir / ".fleet" / "instance.yml"
    if info_path.exists():
        with open(info_path, "r", encoding="utf-8") as fh:
            data = _yaml.load(fh) or {}
        project = str(data.get("project") or instance_id.split("--", 1)[0])
    else:
        project = instance_id.split("--", 1)[0]

    dest = paths.assets / project / dest_rel
    dest.parent.mkdir(parents=True, exist_ok=True)

    with instance_lock(paths.locks, instance_id):
        result = runner(["ddev", "export-db", f"--file={dest}"], cwd=instance_dir)
        if result.returncode != 0:
            raise FleetError(
                f"ddev export-db failed for {instance_id!r} with exit code {result.returncode}"
            )

    return dest


@dataclass
class InstanceStatus:
    instance_id: str
    project: str
    instance: str
    branch: str
    state: str
    url: str
    ram_mib: int | None


def list_instances(
    paths: FleetPaths, registry: Registry, *, runner=run_streamed
) -> list[InstanceStatus]:
    instances_root = paths.instances
    if not instances_root.exists():
        return []

    try:
        ddev_projects = ddev.list_projects(runner=runner)
    except Exception:
        ddev_projects = []
    running_ids = {
        p.get("name") for p in ddev_projects if str(p.get("status", "")).lower() == "running"
    }

    try:
        ram = ddev.ram_usage(runner=runner)
    except Exception:
        ram = {}

    statuses: list[InstanceStatus] = []
    for entry in sorted(instances_root.iterdir()):
        if not entry.is_dir():
            continue
        current_id = entry.name
        info_path = entry / ".fleet" / "instance.yml"
        if info_path.exists():
            with open(info_path, "r", encoding="utf-8") as fh:
                data = _yaml.load(fh) or {}
            project = str(data.get("project", ""))
            instance = str(data.get("instance", ""))
            branch = str(data.get("branch", ""))
        else:
            parts = current_id.split("--", 1)
            project = parts[0] if parts else current_id
            instance = parts[1] if len(parts) > 1 else ""
            branch = ""

        state = "running" if current_id in running_ids else "deployed"
        statuses.append(
            InstanceStatus(
                instance_id=current_id,
                project=project,
                instance=instance,
                branch=branch,
                state=state,
                url=f"https://{current_id}.{registry.domain}",
                ram_mib=ram.get(current_id),
            )
        )
    return statuses
