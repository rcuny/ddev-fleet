"""Orchestrates deploy/destroy/start/stop/list using the other core modules
(spec §5, §6, §11)."""

import shutil
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

from ruamel.yaml import YAML

from fleet.core import assets as assets_mod
from fleet.core import caddyauth, ddev, gitops, typesense
from fleet.core.errors import CaddyAuthError, DeployError, FleetError, TypesenseError
from fleet.core.fleetconfig import (
    ensure_git_exclude,
    write_ddev_env,
    write_fleet_config,
    write_settings_local,
    write_web_build,
)
from fleet.core.locks import instance_lock
from fleet.core.registry import Registry, ResolvedInstance
from fleet.core.runner import run_streamed
from fleet.core.secrets import read_secrets, secret_tokens
from fleet.core.tokens import build_context, env_vars

_yaml = YAML()
_yaml.default_flow_style = False

# Public port Caddy exposes each instance's Typesense on (spec: typesense
# edge exposure). MUST match `fleet_typesense_public_port` in
# ansible/group_vars/all.yml / the Caddyfile template — the two are
# independently configured (this side has no Ansible dependency) but must
# stay numerically in sync for the browser-facing URL to work.
TYPESENSE_PUBLIC_PORT = 9108

# Shared ddev-router HTTP entrypoint that all instances' Typesense containers
# sit behind (routed by Host header). Must match `ddev_typesense_http_port`
# in ansible/group_vars/all.yml.
TYPESENSE_ROUTER_HTTP_PORT = 8108


@dataclass
class FleetPaths:
    home: Path
    registry: Path
    assets: Path
    instances: Path
    logs: Path
    secrets: Path
    project_secrets: Path
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
            # Deliberately OUTSIDE instances/ — deploy logs must survive
            # `fleet destroy`, which removes the whole instance directory
            # (see _destroy_locked -> _remove_instance_dir). One growing
            # file per instance under a central, destroy-proof location.
            logs=home / "logs",
            secrets=home / ".secrets",
            project_secrets=home / "secrets",
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


def resolve_target(
    registry: Registry,
    project: str,
    template: str | None = None,
    branch: str | None = None,
    label: str | None = None,
) -> ResolvedInstance:
    """Apply project defaults to `template`/`branch`, then resolve the full
    deploy target. Single source of truth for default-resolution, shared by
    `deploy()` and the `ui_deploy` daemon route so they can never drift.

    Raises DeployError if `project` is unknown, or if `template`/`branch`
    are still unset after applying project defaults.
    """
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

    return registry.resolve(project, resolved_template, resolved_branch, label=label)


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
    auth_enabled: bool = True,
    auth_password: str = caddyauth.DEFAULT_INSTANCE_PASSWORD,
    auth_snippet_dir: Path | None = None,
    auth_caddyfile_path: Path | None = None,
    runner=run_streamed,
) -> str:
    # Resolved at call time (not baked into the parameter default) so tests
    # can redirect every real deploy()/destroy() call away from the real
    # /etc/caddy paths via a single monkeypatch of the caddyauth module
    # constants, without threading tmp_path overrides through every call
    # site — see tests/conftest.py's `_isolate_caddy_paths` fixture.
    snippet_dir = (
        auth_snippet_dir if auth_snippet_dir is not None else caddyauth.DEFAULT_INSTANCE_SNIPPET_DIR
    )
    caddyfile_path = (
        auth_caddyfile_path if auth_caddyfile_path is not None else caddyauth.DEFAULT_CADDYFILE_PATH
    )

    resolved = resolve_target(registry, project, template, branch, label)
    inst_id = resolved.instance_id
    instance_dir = paths.instances / inst_id
    # Central, destroy-proof location — see FleetPaths.logs docstring above.
    deploy_log = paths.logs / inst_id / "deploy.log"

    with instance_lock(paths.locks, inst_id):
        if fresh and instance_dir.exists():
            _destroy_locked(
                paths,
                inst_id,
                auth_snippet_dir=snippet_dir,
                auth_caddyfile_path=caddyfile_path,
                runner=runner,
            )

        recovered_stub = False
        if instance_dir.exists() and not (instance_dir / ".git").is_dir():
            # A dir with no .git is a partial/failed-destroy stub (e.g. a
            # bare .ddev/ left behind because a prior destroy couldn't fully
            # remove it) — remove it so we fall through to a fresh clone
            # below, instead of handing it to gitops.update() which would
            # fail with a cryptic "fatal: not a git repository".
            _remove_instance_dir(instance_dir)
            recovered_stub = True

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
            f"deploy start: project={project} template={resolved.template} "
            f"label={resolved.label} branch={resolved.branch}",
        )
        if clone_result is not None:
            for line in clone_result.lines:
                _append_log(deploy_log, line)
        if recovered_stub:
            _append_log(
                deploy_log,
                "recovered from a partial instance directory left by a prior destroy",
            )

        secrets = read_secrets(paths.secrets)
        claude_token = secrets.get("CLAUDE_CODE_OAUTH_TOKEN")
        if not claude_token:
            raise DeployError(
                f"CLAUDE_CODE_OAUTH_TOKEN not found in {paths.secrets}; "
                "run 'fleet init' or set it before deploying"
            )
        fqdns = [f"{h}.{inst_id}.{registry.domain}" for h in registry.additional_hostnames(project)]

        # Reconcile this instance's Caddy basic-auth state to what THIS
        # deploy call asked for (default: enabled, password "fleet"). Runs
        # after the fresh-destroy above (which already tore down any prior
        # snippet via _destroy_locked) and before ddev start, so an instance
        # is never briefly live without the auth state its operator asked
        # for. A failure here must not leave a half-configured instance
        # silently public — raise an actionable DeployError rather than
        # continuing the pipeline.
        try:
            if auth_enabled:
                caddyauth.enable_instance_auth(
                    inst_id,
                    f"{inst_id}.{registry.domain}",
                    auth_password,
                    snippet_dir=snippet_dir,
                    caddyfile_path=caddyfile_path,
                    runner=runner,
                )
                _append_log(deploy_log, f"basic auth enabled for {inst_id}.{registry.domain}")
            else:
                caddyauth.disable_instance_auth(
                    inst_id, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=runner
                )
                _append_log(deploy_log, f"basic auth disabled for {inst_id}.{registry.domain}")
        except CaddyAuthError as exc:
            raise DeployError(
                f"failed to configure basic auth for instance {inst_id!r}: {exc.message}"
            ) from exc

        typesense_enabled = registry.typesense_enabled(project)
        typesense_admin_key = None
        typesense_search_key = None
        if typesense_enabled:
            typesense_admin_key, typesense_search_key = typesense.ensure_project_keys(
                paths.project_secrets / f"{project}.env"
            )

        write_fleet_config(
            instance_dir,
            inst_id,
            registry.domain,
            claude_token,
            additional_fqdns=fqdns,
            git_bot=registry.git_bot(),
            typesense=typesense_enabled,
            typesense_port=TYPESENSE_PUBLIC_PORT,
            typesense_admin_key=typesense_admin_key,
            typesense_search_key=typesense_search_key,
        )
        write_web_build(instance_dir)
        excludes = [
            ".ddev/config.fleet.yaml",
            ".ddev/web-build/Dockerfile.fleet-claude",
            ".fleet/",
        ]
        if typesense_enabled:
            write_ddev_env(instance_dir, {"TYPESENSE_API_KEY": typesense_admin_key})
            excludes.append(".ddev/.env")
        for injected in write_settings_local(instance_dir, registry.domain):
            excludes.append(str(injected.relative_to(instance_dir)))
        ensure_git_exclude(instance_dir, excludes)

        context = build_context(project, resolved.label, resolved.branch, registry.domain)
        project_secrets = read_secrets(paths.project_secrets / f"{project}.env")
        context.update(secret_tokens(project_secrets))
        copied = assets_mod.inject(paths.assets / project, instance_dir, context, runner=runner)
        ensure_git_exclude(instance_dir, [str(path.relative_to(instance_dir)) for path in copied])

        start_result = ddev.start(instance_dir, log_path=deploy_log, runner=runner)
        if start_result.returncode != 0:
            raise DeployError(
                f"ddev start failed in {instance_dir} with exit code {start_result.returncode}"
            )

        if typesense_enabled:
            try:
                typesense.register_search_key(
                    http_port=TYPESENSE_ROUTER_HTTP_PORT,
                    host=f"{inst_id}.{registry.domain}",
                    admin_key=typesense_admin_key,
                    search_key=typesense_search_key,
                )
            except TypesenseError as exc:
                _append_log(
                    deploy_log,
                    f"WARNING: Typesense search-key registration failed: {exc}; "
                    "search may not work until it is re-registered",
                )

        # Push-key setup runs AFTER `ddev start` (it needs the web container for
        # `ddev exec`). Clear the SHARED ddev ssh-agent first, then load ONLY the
        # read-write push key — otherwise a read-only deploy key lingering in the
        # shared agent is offered first and Bitbucket denies the in-container push.
        runner(["ddev", "exec", "ssh-add", "-D"], cwd=instance_dir, log_path=deploy_log)
        auth_result = runner(
            ["ddev", "auth", "ssh", "-d", str(paths.push_key_dir)],
            cwd=instance_dir,
            log_path=deploy_log,
        )
        if auth_result.returncode != 0:
            _append_log(
                deploy_log,
                f"WARNING: ddev auth ssh returned {auth_result.returncode}; "
                "in-container git push may fail",
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


def _remove_instance_dir(instance_dir: Path) -> None:
    """Remove an instance directory, failing LOUDLY if it cannot be fully
    removed — never leave a partial stub (which would break the next deploy)."""
    if not instance_dir.exists():
        return
    last_exc: Exception | None = None
    for _ in range(3):
        try:
            shutil.rmtree(instance_dir)
        except OSError as exc:
            last_exc = exc
        if not instance_dir.exists():
            return
    detail = f": {last_exc}" if last_exc is not None else ""
    raise FleetError(
        f"could not fully remove instance directory {instance_dir}{detail} — "
        "something may still be holding files (mounts/containers); resolve it and retry"
    )


def _destroy_locked(
    paths: FleetPaths,
    instance_id: str,
    *,
    auth_snippet_dir: Path | None = None,
    auth_caddyfile_path: Path | None = None,
    runner=run_streamed,
) -> None:
    """Destroy an instance's containers, directory, and Caddy auth snippet.
    Assumes the caller already holds the instance lock (used by deploy()'s
    --fresh path to avoid re-entering instance_lock, which would
    deadlock/raise).

    The auth-snippet removal always runs, even if the instance never had
    auth enabled (disable_instance_auth() is a no-op in that case) — this is
    what keeps a destroyed instance from leaving a stale `@auth-<id>`
    matcher behind, which would otherwise either linger unused or collide
    with a later re-deploy of the same instance id."""
    snippet_dir = (
        auth_snippet_dir if auth_snippet_dir is not None else caddyauth.DEFAULT_INSTANCE_SNIPPET_DIR
    )
    caddyfile_path = (
        auth_caddyfile_path if auth_caddyfile_path is not None else caddyauth.DEFAULT_CADDYFILE_PATH
    )

    instance_dir = paths.instances / instance_id
    if instance_dir.exists():
        try:
            ddev.delete(instance_dir, runner=runner)
        except Exception:
            pass  # tolerate failure if containers are already gone
        _remove_instance_dir(instance_dir)
    try:
        caddyauth.disable_instance_auth(
            instance_id, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=runner
        )
    except CaddyAuthError as exc:
        raise FleetError(
            f"failed to remove basic-auth snippet for instance {instance_id!r}: {exc.message}"
        ) from exc


def destroy(
    paths: FleetPaths,
    registry: Registry,
    instance_id: str,
    *,
    auth_snippet_dir: Path | None = None,
    auth_caddyfile_path: Path | None = None,
    runner=run_streamed,
) -> None:
    instance_dir = paths.instances / instance_id
    if not instance_dir.exists():
        raise FleetError(f"unknown instance {instance_id!r}: {instance_dir} does not exist")
    with instance_lock(paths.locks, instance_id):
        _destroy_locked(
            paths,
            instance_id,
            auth_snippet_dir=auth_snippet_dir,
            auth_caddyfile_path=auth_caddyfile_path,
            runner=runner,
        )

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


# The shared project dump every instance hard-links and imports from
# (`.ddev/commands/web/install-site-from-db` reads `dumps/<SITE>.sql`, and
# `default` is the DDEV default site). snapshot() must never write here in
# place — see the module docstring in core/assets.py:_link_shared_dir for why
# writing through a hard-linked inode corrupts every instance sharing it.
_SHARED_DUMP_BASENAME = "default.sql"


def snapshot(
    paths: FleetPaths,
    registry: Registry,
    instance_id: str,
    *,
    dest_rel: str | None = None,
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

    if dest_rel is None:
        # Per-instance dump name (never the shared `default.sql`), importable
        # via `ddev install-site-from-db default-<instance_id>` per the
        # project's own `.ddev/commands/web/install-site-from-db` convention
        # (`dumps/${SITE}.sql`, plain uncompressed SQL — see --gzip=false below).
        dest_rel = f"dumps/default-{instance_id}.sql"

    dest = paths.assets / project / dest_rel

    if dest.name == _SHARED_DUMP_BASENAME:
        raise FleetError(
            f"refusing to snapshot to {dest_rel!r}: {_SHARED_DUMP_BASENAME!r} is the "
            "shared project dump that every instance hard-links and imports from "
            "(see core/assets.py:_link_shared_dir) — writing to it in place would "
            "corrupt it for every instance and the config repo's source file. "
            "Use the default per-instance name (omit --dest-rel) or another "
            "explicit --dest-rel."
        )

    dest.parent.mkdir(parents=True, exist_ok=True)
    if dest.exists() or dest.is_symlink():
        # dest may be a hard link sharing an inode with a file mirrored into
        # other instances (or the config repo's own copy) by
        # core/assets.py:_link_shared_dir. Writing into it in place would
        # write through that shared inode. Unlinking first guarantees
        # `ddev export-db` creates a fresh, unshared inode at this path,
        # regardless of the existing file's current link count.
        dest.unlink()

    with instance_lock(paths.locks, instance_id):
        result = runner(["ddev", "export-db", f"--file={dest}", "--gzip=false"], cwd=instance_dir)
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
