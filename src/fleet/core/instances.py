"""Orchestrates deploy/destroy/start/stop/list using the other core modules
(spec §5, §6, §11)."""

import logging
import os
import shutil
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

from ruamel.yaml import YAML

from fleet.core import assets as assets_mod
from fleet.core import caddyauth, caddyports, ddev, gitops, naming, tmux, ttycmds, typesense
from fleet.core.errors import (
    CaddyAuthError,
    CaddyPortsError,
    DeployError,
    FleetError,
    TokenError,
    TypesenseError,
)
from fleet.core.fleetconfig import (
    ensure_git_exclude,
    set_env_file_var,
    write_ddev_env,
    write_fleet_config,
    write_settings_local,
    write_web_build,
)
from fleet.core.locks import ALLOCATION_LOCK_ID, instance_lock
from fleet.core.registry import Registry, ResolvedInstance
from fleet.core.runner import run_streamed
from fleet.core.secrets import read_secrets, secret_tokens
from fleet.core.tokens import build_context, env_vars, extract_issue_id, substitute_text

logger = logging.getLogger(__name__)

_yaml = YAML()
_yaml.default_flow_style = False


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
    host_config: Path

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
            # Deliberately OUTSIDE config_dir/ — config_dir is the shared,
            # git-tracked `fleet.yml` registry repo that multiple servers can
            # point at, while host.yml is per-host (rendered by the `caddy`
            # Ansible role from `fleet_domain`, see docs/configuration.md's
            # "per-host domain" section) and must never live inside a repo
            # that gets committed/shared across hosts.
            host_config=home / "host.yml",
        )


def load_registry(paths: FleetPaths) -> Registry:
    """THE one constructor every CLI/daemon call site uses to load the
    registry — wraps `Registry.load()` with `paths.host_config` so a
    per-host `domain` override (see `FleetPaths.host_config`'s docstring
    note above and `Registry.load`'s `host_config_path` parameter) is never
    forgotten at some call site. Prefer this over calling `Registry.load()`
    directly anywhere `paths` is already in hand."""
    return Registry.load(paths.registry, host_config_path=paths.host_config)


def _now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _append_log(log_path: Path, message: str) -> None:
    log_path.parent.mkdir(parents=True, exist_ok=True)
    with open(log_path, "a", encoding="utf-8") as fh:
        fh.write(f"[{_now_iso()}] {message}\n")


def _write_instance_yaml(
    instance_dir: Path,
    project: str,
    instance: str,
    template: str,
    branch: str,
    auth_enabled: bool,
    auth_password: str,
) -> None:
    fleet_dir = instance_dir / ".fleet"
    fleet_dir.mkdir(parents=True, exist_ok=True)
    info_path = fleet_dir / "instance.yml"

    created_at = _now_iso()
    if info_path.exists():
        with open(info_path, "r", encoding="utf-8") as fh:
            existing = _yaml.load(fh) or {}
        created_at = existing.get("created-at", created_at)

    # `template` is what lets a future `fleet tmux` recover this instance's
    # tty1/tty2 commands from disk alone (core/ttycmds.py:plan_for_instance)
    # — it reaches new deploys only; instances deployed before this field
    # existed simply resolve to no tty commands (today's behaviour).
    #
    # `auth-enabled`/`auth-password` record the basic-auth state THIS
    # deploy call configured, so a later `redeploy()` can reproduce it
    # exactly instead of silently falling back to the default (design
    # decision 5) — instances deployed before this field existed simply
    # have no recorded auth state, same "reaches new deploys only" pattern
    # as `template` above.
    data = {
        "project": project,
        "instance": instance,
        "template": template,
        "branch": branch,
        "auth-enabled": auth_enabled,
        "auth-password": auth_password,
        "created-at": created_at,
        "last-deployed-at": _now_iso(),
    }
    with open(info_path, "w", encoding="utf-8") as fh:
        _yaml.dump(data, fh)
    # This file now holds a plaintext credential (auth-password) — 0600 on
    # EVERY write, not just creation, since a redeploy rewrites it at the
    # default umask otherwise. Same pattern as core/secrets.py:write_secret.
    os.chmod(info_path, 0o600)


def project_for_instance(instance_dir: Path, instance_id: str) -> str:
    """Resolve an instance directory's owning project: the `project:` field
    recorded in `.fleet/instance.yml` at deploy time, falling back to the
    `<project>--<label>` id split for instances deployed before that field
    existed (or with no recorded instance.yml at all). The one shared
    implementation of a pattern otherwise duplicated across this module —
    used by `refresh_instance_config()`, `sync_instance_auth()`, and
    daemon.py's `/api/tls-authorize` (none of which already have the
    project in hand the way `deploy()` does, as a plain function
    parameter)."""
    info_path = instance_dir / ".fleet" / "instance.yml"
    if info_path.exists():
        with open(info_path, "r", encoding="utf-8") as fh:
            data = _yaml.load(fh) or {}
        return str(data.get("project") or instance_id.split("--", 1)[0])
    return instance_id.split("--", 1)[0]


# Mirrors fleet.core.naming's 63-character DNS label limit (RFC 1035). Kept
# as its own constant rather than importing naming's private
# _MAX_INSTANCE_ID_LENGTH: an alias label (`<hostname>-<instance-id>`) is a
# DIFFERENT label from the instance id itself and validated independently —
# see `alias_fqdns()` below.
_MAX_ALIAS_LABEL_LENGTH = 63


def alias_fqdns(registry: Registry, project: str, instance_id: str) -> list[str]:
    """Compute `instance_id`'s Domain-Access alias FQDNs from
    `Registry.additional_hostnames(project)` — the single choke point every
    caller (deploy(), refresh_instance_config(), sync_instance_auth(),
    daemon.py's /api/tls-authorize) uses so they can never drift apart.

    Aliases are FLATTENED, not nested: `<h>-<instance_id>.<domain>`, e.g.
    `news-oak--translations-test.fleet.example.test` — NOT
    `news.oak--translations-test.fleet...`. Caddy's site block for this
    fleet is a SINGLE-label wildcard (`*.{{ fleet_domain }}`), which can
    never match a nested (multi-label) alias, and `/api/tls-authorize`
    rejects any label containing a dot outright — so a nested alias would
    be unreachable and uncertifiable. This function is the only place that
    composes an alias FQDN; nothing else should string-format one by hand.

    Raises DeployError if any resulting label `<h>-<instance_id>` exceeds
    the 63-character DNS label limit, naming the offending hostname and the
    resulting length — a `deploy()`/`refresh_instance_config()` call must
    fail loudly here rather than silently write a DDEV `additional_fqdns`
    entry (or a Caddy auth-matcher host) that no CA could ever issue a
    certificate for. The bare instance label itself is untouched by this
    check — it was already validated against the same limit by
    `naming.instance_id()` at resolve time.
    """
    domain = registry.domain
    fqdns: list[str] = []
    for hostname in registry.additional_hostnames(project):
        label = f"{hostname}-{instance_id}"
        if len(label) > _MAX_ALIAS_LABEL_LENGTH:
            raise DeployError(
                f"additional_hostnames entry {hostname!r} for project {project!r} "
                f"composes the alias label {label!r} ({len(label)} characters) for "
                f"instance {instance_id!r}, which exceeds the "
                f"{_MAX_ALIAS_LABEL_LENGTH}-character DNS label limit"
            )
        fqdns.append(f"{label}.{domain}")
    return fqdns


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


def _preflight_rebuild(
    registry: Registry,
    instance_id: str,
    *,
    project: str | None = None,
    template: str | None = None,
    branch: str | None = None,
    label: str | None = None,
    snippet_dir: Path,
    caddyfile_path: Path,
    runner=run_streamed,
) -> None:
    """Cheap, side-effect-free checks run BEFORE any destroy-then-rebuild
    starts tearing anything down: `deploy()`'s `replace=True` path (and thus
    `redeploy()`, its only real caller — see design decision 2) and, more
    lightly, `destroy()` itself.

    Incident 2026-09-22: `fleet redeploy --all` destroyed 5 instances, then
    failed removing each one's basic-auth snippet because `caddy validate`
    was failing for a reason unrelated to any of them (a fleet-wide Caddy
    config problem, not something about those instances). The rebuild never
    ran, so those 5 instances were simply gone. None of that failure mode
    involves writing/destroying anything itself — it's pure validation — so
    every check below is cheap and side-effect-free, and a failure here
    leaves the existing instance completely untouched.

    Checks, in order (each is skipped when the caller passes no
    project/template/branch — see `destroy()`, which has no rebuild target
    to resolve and only runs the last two, Caddy-focused checks):

      1. The registry still resolves this instance's deploy target — same
         path `deploy()`/`redeploy()` themselves use
         (`resolve_target`/`Registry.resolve`). If the project, template, or
         branch default has since vanished from `fleet.yml`, the rebuild
         could never complete anyway. Through the current call sites this is
         also caught even earlier, by `deploy()`'s own unconditional
         `resolve_target()` call before the replace/destroy decision is
         made — this check stays part of the contract anyway (defense in
         depth for any future caller of this primitive, and directly
         testable in isolation).
      2. This instance's Domain-Access alias FQDNs
         (`additional_hostnames`) still compose without exceeding the DNS
         label limit (`alias_fqdns`) — today this is only computed by
         `deploy()` AFTER the destroy+re-clone, so checking it here is new
         protection against the exact same "destroyed but not rebuilt"
         failure shape as the incident, just from a different error source.
      3. The Caddy snippet directory `_destroy_locked()` is about to touch
         still passes `caddyauth.ensure_snippet_dir()`'s guard — called with
         `create=False` so this is a pure read even for the "unmanaged"
         (tests/local) branch.
      4. The CURRENT (pre-destroy) Caddy config still validates. If it
         doesn't, `_destroy_locked()`'s own `disable_instance_auth()` call
         would fail AFTER the instance directory is already gone — exactly
         the incident above. Skipped gracefully when `caddyfile_path`
         doesn't exist yet — nothing live to validate against, the same
         "nothing to do" skip `caddyports.sync()` applies when a sync
         writes/removes nothing.

    Raises DeployError, always prefixed so the operator knows the existing
    instance was left untouched, naming which check failed and why.
    """
    prefix = f"preflight failed for instance {instance_id!r} — NOTHING WAS DESTROYED: "

    if project is not None:
        try:
            resolve_target(registry, project, template, branch, label)
        except FleetError as exc:
            raise DeployError(
                prefix + "the registry no longer resolves this instance's deploy target: "
                f"{exc.message}"
            ) from exc

        try:
            alias_fqdns(registry, project, instance_id)
        except FleetError as exc:
            raise DeployError(prefix + exc.message) from exc

    try:
        caddyauth.ensure_snippet_dir(snippet_dir, create=False)
    except FleetError as exc:
        raise DeployError(
            prefix + f"the Caddy snippet directory check failed: {exc.message}"
        ) from exc

    if caddyfile_path.exists():
        try:
            caddyauth.validate_caddyfile(caddyfile_path=caddyfile_path, runner=runner)
        except FleetError as exc:
            raise DeployError(
                prefix + "the CURRENT Caddy config does not validate — fix Caddy first, "
                f"then retry: {exc.message}"
            ) from exc


def deploy(
    paths: FleetPaths,
    registry: Registry,
    project: str,
    template: str | None = None,
    *,
    branch: str | None = None,
    label: str | None = None,
    replace: bool = False,
    force: bool = False,
    auth_enabled: bool = True,
    auth_password: str = caddyauth.DEFAULT_INSTANCE_PASSWORD,
    auth_snippet_dir: Path | None = None,
    auth_caddyfile_path: Path | None = None,
    runner=run_streamed,
) -> str:
    """Deploy `project`/`template`/`branch`/`label` to an instance.

    A plain deploy (`replace=False`, the default — every CLI/UI entry
    point and `multi_deploy()` reach this) NEVER reuses an existing
    instance id (design decision 1,
    2026-07-27-fleet-redeploy-and-no-overwrite-design.md): if the resolved
    label is already taken by a live instance, `naming.allocate_free_label`
    appends `-1`, `-2`, … until a free one is found — whether the label
    came from an explicit `label=` or was derived from `branch`.

    `replace=True` is the internal destroy-then-rebuild-IN-PLACE primitive:
    it skips allocation entirely (landing on the SAME id is the whole
    point) and, if an instance already exists at the resolved id, destroys
    it first (see the `_destroy_locked` call below). `redeploy()` (a later
    step) is its only real caller for that reason. `multi_deploy()` also
    passes `replace=True`, for a different reason: it has already allocated
    a guaranteed-free id itself under the same allocation lock, so this
    function must not try to allocate (and thus re-lock) again.
    """
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

    if auth_enabled:
        caddyauth.validate_instance_credential(auth_password)

    resolved = resolve_target(registry, project, template, branch, label)
    slugified_label = resolved.label
    allocated = False

    if not replace:
        # Allocation is serialised fleet-wide via the SAME advisory lock
        # multi_deploy() uses (core/locks.py:ALLOCATION_LOCK_ID) — a single
        # deploy() and a bulk multi_deploy() must never allocate the same
        # id.
        #
        # The lock is released again as soon as a label is chosen here
        # (allocate-THEN-lock, rather than nested with the per-instance
        # instance_lock below): re-entering instance_lock() for the SAME
        # lock id from inside the same process is NOT reentrant (flock() is
        # per open-file-description, not per-process — see
        # tests/test_locks.py::test_nested_lock_on_same_instance_raises_lock_held)
        # and would raise LockHeldError rather than deadlock — but it's
        # still wrong, so we simply don't hold both at once. This leaves a
        # narrow, accepted TOCTOU window: the label chosen here isn't
        # reflected in a directory listing until this call's OWN clone
        # actually creates the directory, further down. Two deploys racing
        # in that window could in theory choose the same id — the
        # `if instance_dir.exists(): gitops.update(...)` branch below
        # exists partly to degrade that (extremely rare) race safely
        # rather than corrupt state.
        with instance_lock(paths.locks, ALLOCATION_LOCK_ID):
            # A directory that exists but has no `.git` is a partial/failed
            # -destroy stub (see the recovered_stub handling below), not a
            # live instance occupying this id. Excluding it here is what
            # keeps the stub-recovery path reachable — otherwise a leftover
            # stub would permanently "occupy" its id and every subsequent
            # deploy attempt would allocate an ever-growing suffix instead
            # of ever cleaning it up.
            existing_ids = (
                {p.name for p in paths.instances.iterdir() if p.is_dir() and (p / ".git").is_dir()}
                if paths.instances.exists()
                else set()
            )
            allocated_label = naming.allocate_free_label(existing_ids, project, slugified_label)
        if allocated_label != slugified_label:
            resolved = registry.resolve(
                project, resolved.template, resolved.branch, label=allocated_label
            )
            allocated = True

    inst_id = resolved.instance_id
    instance_dir = paths.instances / inst_id
    # Central, destroy-proof location — see FleetPaths.logs docstring above.
    deploy_log = paths.logs / inst_id / "deploy.log"

    with instance_lock(paths.locks, inst_id):
        if replace and instance_dir.exists():
            _preflight_rebuild(
                registry,
                inst_id,
                project=project,
                template=resolved.template,
                branch=resolved.branch,
                label=resolved.label,
                snippet_dir=snippet_dir,
                caddyfile_path=caddyfile_path,
                runner=runner,
            )
            _destroy_locked(
                paths,
                inst_id,
                registry,
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
        if label and label != slugified_label:
            _append_log(
                deploy_log,
                f"label {label!r} normalised to {slugified_label!r} (instance ids "
                "must be lowercase DNS labels)",
            )
        if allocated:
            _append_log(
                deploy_log,
                f"label {slugified_label!r} already in use — allocated {resolved.label!r} "
                "instead (deploy never overwrites an existing instance)",
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
            _append_log(
                deploy_log,
                f"WARNING: CLAUDE_CODE_OAUTH_TOKEN not found in {paths.secrets}; "
                "proceeding without injecting a Claude token into this instance "
                "(run 'fleet init' or 'fleet set-claude-token', then "
                "'fleet refresh-instance-config' to inject it later)",
            )
        alias_hosts = alias_fqdns(registry, project, inst_id)

        # Reconcile this instance's Caddy basic-auth state to what THIS
        # deploy call asked for (default: enabled, `fleet`/`fleet` — the
        # password doubles as the username). Runs
        # after the fresh-destroy above (which already tore down any prior
        # snippet via _destroy_locked) and before ddev start, so an instance
        # is never briefly live without the auth state its operator asked
        # for. A failure here must not leave a half-configured instance
        # silently public — raise an actionable DeployError rather than
        # continuing the pipeline.
        #
        # `alias_hosts` is passed through so the auth matcher covers the
        # instance's Domain-Access alias hosts too — without it, an alias
        # host would silently bypass basic auth entirely (it's a different
        # `host` than the one the matcher guards).
        try:
            if auth_enabled:
                caddyauth.enable_instance_auth(
                    inst_id,
                    f"{inst_id}.{registry.domain}",
                    auth_password,
                    bypass_cidrs=registry.auth_bypass_cidrs,
                    alias_fqdns=alias_hosts,
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

        # Reconcile the WHOLE registry's Caddy port snippets (not just this
        # instance's) so a newly-subscribed port gets its snippet before
        # `ddev start` brings the instance up. Runs after the fresh-destroy
        # above and before write_fleet_config so a failure here still leaves
        # this deploy call fully reported as a DeployError, not a
        # half-configured instance.
        try:
            caddyports.sync(
                registry,
                snippet_dir=caddyports.DEFAULT_PORTS_SNIPPET_DIR,
                caddyfile_path=caddyfile_path,
                runner=runner,
            )
        except CaddyPortsError as exc:
            raise DeployError(
                f"failed to reconcile Caddy port snippets for instance {inst_id!r}: "
                f"{exc.message}"
            ) from exc

        write_fleet_config(
            instance_dir,
            inst_id,
            registry.domain,
            claude_token,
            additional_fqdns=alias_hosts,
            git_bot=registry.git_bot(project),
            typesense=typesense_enabled,
            typesense_port=registry.port_profile("typesense").public,
            typesense_admin_key=typesense_admin_key,
            typesense_search_key=typesense_search_key,
            ports=registry.project_ports(project),
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

        context = build_context(
            project,
            resolved.label,
            resolved.branch,
            registry.domain,
            issue_id=extract_issue_id(
                registry.issue_id_regexp(project), resolved.label, resolved.branch
            ),
        )
        project_secrets = read_secrets(paths.project_secrets / f"{project}.env")
        context.update(secret_tokens(project_secrets))
        copied = assets_mod.inject(paths.assets / project, instance_dir, context, runner=runner)
        ensure_git_exclude(instance_dir, [str(path.relative_to(instance_dir)) for path in copied])

        # `drupal_env` from the resolved TEMPLATE overrides whatever the
        # project's own .env ships (assets/<project>/.env carries
        # `DRUPAL_ENV=dev`), so one project can run a `staging` template —
        # different modules/cache — from the same codebase. Runs AFTER asset
        # injection, which is what puts that .env in place; a template with
        # no `drupal_env` leaves the file untouched.
        if resolved.drupal_env:
            env_path = set_env_file_var(instance_dir / ".env", "DRUPAL_ENV", resolved.drupal_env)
            ensure_git_exclude(instance_dir, [str(env_path.relative_to(instance_dir))])
            _append_log(deploy_log, f"DRUPAL_ENV={resolved.drupal_env} written to .env")

        start_result = ddev.start(instance_dir, log_path=deploy_log, runner=runner)
        if start_result.returncode != 0:
            raise DeployError(
                f"ddev start failed in {instance_dir} with exit code {start_result.returncode}"
            )

        if typesense_enabled:
            try:
                typesense.register_search_key(
                    http_port=registry.port_profile("typesense").router,
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
            # STRICT substitution, unlike tty1/tty2 below: a post_deploy
            # command is deploy-critical, so silently skipping it (the tty
            # commands' lenient behaviour) would be worse than failing loudly
            # — matches the existing rule that a non-zero exit aborts deploy.
            try:
                substituted = substitute_text(command, context)
            except TokenError as exc:
                raise DeployError(f"post_deploy command {command!r} has {exc.message}") from exc
            result = runner(
                ["bash", "-c", substituted],
                cwd=instance_dir,
                env=env,
                log_path=deploy_log,
            )
            if result.returncode != 0:
                raise DeployError(
                    f"post_deploy command {command!r} failed with exit code {result.returncode}"
                )

        _write_instance_yaml(
            instance_dir,
            project,
            resolved.label,
            resolved.template,
            resolved.branch,
            auth_enabled,
            auth_password,
        )
        _append_log(deploy_log, "deploy complete")

    try:
        if tmux.session_exists(runner=runner):
            # LENIENT substitution here (decision 2 in the design doc): an
            # unresolvable tty command must not fail a deploy that otherwise
            # completed — it's just skipped, with a WARNING in the deploy
            # log, leaving that pane as a plain bash shell. Resolution itself
            # (registry/secrets lookups included) stays inside this whole
            # try/except, so any failure here — not just ensure_instance_window
            # raising — only ever warns, never fails a completed deploy.
            plan = ttycmds.plan_from_template(
                registry, paths, project, resolved.label, resolved.branch, resolved.template
            )
            for line in plan.skipped:
                _append_log(deploy_log, f"WARNING: {line}")
            tty = None if plan.is_empty else (plan.tty1, plan.tty2)
            tmux.ensure_instance_window(inst_id, instance_dir, tty=tty, runner=runner)
    except Exception as exc:  # noqa: BLE001 - tmux tab is best-effort
        _append_log(deploy_log, f"WARNING: tmux tab update failed: {exc}")

    return f"https://{inst_id}.{registry.domain}"


def redeploy(
    paths: FleetPaths,
    registry: Registry,
    instance_id: str,
    *,
    template: str | None = None,
    auth_password: str | None = None,
    force: bool = False,
    runner=run_streamed,
) -> str:
    """Destroy-and-rebuild `instance_id` IN PLACE, recovering its original
    project/template/branch/label/auth parameters from
    `<instance_dir>/.fleet/instance.yml` instead of requiring the caller to
    re-supply them (design decision 2,
    2026-07-27-fleet-redeploy-and-no-overwrite-design.md).

    `deploy(replace=True)` is the PRIMITIVE that actually destroys and
    rebuilds an instance at a fixed id; `redeploy()` is the operator-facing
    OPERATION built on top of it that answers "with what parameters?" by
    reading them back off disk. The registry is re-read by `deploy()` (it's
    a plain call-through, not a snapshot), so a redeploy picks up any edits
    made to the template's `post_deploy`/`tty1`/`tty2` since the original
    deploy — "same parameters" means the same project/template/branch/label
    IDENTITY, not a frozen copy of the recipe. Do not "fix" this into a
    literal replay of the original deploy; it is intentional.

    Contrast with `core/ttycmds.py:plan_for_instance`, the OTHER reader of
    this same `instance.yml` file: that function silently degrades to an
    EMPTY plan on anything short of a perfect read, because it runs inside
    `fleet tmux`, where one broken/legacy instance must never stop the rest
    of the tmux workspace from coming up. `redeploy()` has the opposite
    obligation — it is about to destroy real containers and disk, there is
    no "fall back to doing nothing" available once that starts, so every
    condition below that `plan_for_instance` would shrug off instead raises
    a loud, actionable `FleetError`. Guessing what to rebuild is how you
    destroy the wrong thing.
    """
    instance_dir = paths.instances / instance_id
    if not instance_dir.exists():
        raise FleetError(f"unknown instance {instance_id!r}: {instance_dir} does not exist")

    info_path = instance_dir / ".fleet" / "instance.yml"
    try:
        with open(info_path, "r", encoding="utf-8") as fh:
            data = _yaml.load(fh)
    except Exception:  # noqa: BLE001 - missing file, bad permissions, malformed
        # YAML all collapse to the same "cannot redeploy" failure below; the
        # exact cause is unrecoverable here.
        data = None
    if not isinstance(data, dict):
        raise FleetError(
            f"instance {instance_id!r} has no recorded deploy parameters "
            f"({info_path} is missing, unreadable, or not a mapping) and cannot "
            "be redeployed automatically"
        )

    recorded_project = data.get("project")
    if not recorded_project:
        raise FleetError(
            f"instance {instance_id!r}'s recorded deploy parameters ({info_path}) "
            "are missing 'project' and cannot be redeployed automatically"
        )
    recorded_project = str(recorded_project)
    if not registry.has_project(recorded_project):
        raise FleetError(
            f"instance {instance_id!r} was deployed from project {recorded_project!r}, "
            "which no longer exists in the registry — cannot redeploy automatically"
        )

    recorded_branch = data.get("branch")
    if not recorded_branch:
        raise FleetError(
            f"instance {instance_id!r}'s recorded deploy parameters ({info_path}) "
            "are missing 'branch' and cannot be redeployed automatically"
        )
    recorded_branch = str(recorded_branch)

    recorded_label = str(data.get("instance") or instance_id)

    # Precedence (design decision 4): the --template argument wins; else the
    # recorded template:; else REFUSE — no silent fallback, since guessing a
    # template for an instance predating template recording could rebuild it
    # running the wrong post_deploy/tty commands entirely.
    recorded_template = data.get("template")
    resolved_template = template or (str(recorded_template) if recorded_template else None)
    if not resolved_template:
        available = registry.template_keys(recorded_project)
        hint = f" (available: {', '.join(available)})" if available else ""
        raise FleetError(
            f"no template recorded for {instance_id!r} (deployed before template "
            f"recording existed) — pass --template <name>{hint}"
        )

    # Auth: recorded auth-enabled defaults to True when absent (matching
    # deploy()'s own default), and must NOT silently flip false->true just
    # because `auth_password` was passed — only the password argument
    # overrides the recorded password, never the enabled flag.
    recorded_auth_enabled = data.get("auth-enabled")
    resolved_auth_enabled = True if recorded_auth_enabled is None else bool(recorded_auth_enabled)
    recorded_auth_password = data.get("auth-password")
    resolved_auth_password = (
        auth_password
        if auth_password is not None
        else (
            str(recorded_auth_password)
            if recorded_auth_password
            else caddyauth.DEFAULT_INSTANCE_PASSWORD
        )
    )

    return deploy(
        paths,
        registry,
        recorded_project,
        resolved_template,
        branch=recorded_branch,
        label=recorded_label,
        replace=True,
        force=force,
        auth_enabled=resolved_auth_enabled,
        auth_password=resolved_auth_password,
        runner=runner,
    )


@dataclass
class AuthSyncResult:
    """Outcome of `sync_instance_auth`: which instances had their Caddy
    basic-auth snippet (re)written, which had one removed, and whether
    Caddy was actually reloaded."""

    written: list[str]
    removed: list[str]
    reloaded: bool


def sync_instance_auth(
    paths: FleetPaths,
    registry: Registry,
    *,
    snippet_dir: Path | None = None,
    caddyfile_path: Path | None = None,
    runner=run_streamed,
) -> AuthSyncResult:
    """Re-render EVERY deployed instance's Caddy basic-auth snippet from its
    recorded `.fleet/instance.yml` settings plus the registry's CURRENT
    `fleet.auth_bypass_cidrs`, then validate and reload Caddy ONCE.

    This is the "apply my whitelist edit now" command (`fleet refresh-auth`),
    the auth counterpart to `refresh_instance_config`/`caddyports.sync`: the
    bypass list is fleet-wide and lives in fleet.yml, so editing it must not
    require redeploying every live instance. Instances whose recorded
    `auth-enabled` is false get their snippet removed instead (keeping this
    command a true reconcile, not just a rewrite).

    Deliberately NOT per-instance validate+reload: a fleet with dozens of
    instances would otherwise reload Caddy dozens of times, and a mid-way
    failure would leave a partially-reloaded config. Writes are atomic per
    snippet; the single validate at the end is what gates the reload, so a
    bad whitelist means Caddy keeps serving the OLD config (the new snippets
    sit on disk, unloaded) and the error names what to fix."""
    resolved_snippet_dir = (
        snippet_dir if snippet_dir is not None else caddyauth.DEFAULT_INSTANCE_SNIPPET_DIR
    )
    resolved_caddyfile = (
        caddyfile_path if caddyfile_path is not None else caddyauth.DEFAULT_CADDYFILE_PATH
    )
    bypass_cidrs = registry.auth_bypass_cidrs

    written: list[str] = []
    removed: list[str] = []
    if not paths.instances.exists():
        return AuthSyncResult(written=written, removed=removed, reloaded=False)

    for entry in sorted(paths.instances.iterdir()):
        if not entry.is_dir():
            continue
        inst_id = entry.name
        info_path = entry / ".fleet" / "instance.yml"
        data = {}
        if info_path.exists():
            with open(info_path, "r", encoding="utf-8") as fh:
                data = _yaml.load(fh) or {}
        # Same defaults as redeploy(): auth on, password `fleet`, for
        # instances deployed before those fields were recorded.
        recorded_enabled = data.get("auth-enabled")
        auth_enabled = True if recorded_enabled is None else bool(recorded_enabled)
        recorded_password = data.get("auth-password")
        password = (
            str(recorded_password) if recorded_password else caddyauth.DEFAULT_INSTANCE_PASSWORD
        )

        if not auth_enabled:
            if caddyauth.remove_instance_auth_snippet(inst_id, snippet_dir=resolved_snippet_dir):
                removed.append(inst_id)
            continue

        # Same project resolution as project_for_instance(), inlined here
        # since `data` is already in hand from the instance.yml read above —
        # no need for a second file read. A project no longer in the
        # registry (deleted from fleet.yml since this instance was
        # deployed) falls back to no aliases rather than crashing the
        # whole `fleet refresh-auth` run over one stale instance.
        project = str(data.get("project") or inst_id.split("--", 1)[0])
        alias_hosts = (
            alias_fqdns(registry, project, inst_id) if registry.has_project(project) else []
        )

        caddyauth.validate_instance_credential(password)
        bcrypt_hash = caddyauth.hash_password(password, runner=runner)
        caddyauth.write_instance_auth_snippet(
            inst_id,
            f"{inst_id}.{registry.domain}",
            password,
            bcrypt_hash,
            bypass_cidrs=bypass_cidrs,
            alias_fqdns=alias_hosts,
            snippet_dir=resolved_snippet_dir,
        )
        written.append(inst_id)

    if not written and not removed:
        return AuthSyncResult(written=written, removed=removed, reloaded=False)

    try:
        caddyauth.validate_caddyfile(caddyfile_path=resolved_caddyfile, runner=runner)
        caddyauth.reload_caddy(caddyfile_path=resolved_caddyfile, runner=runner)
    except CaddyAuthError as exc:
        raise FleetError(f"failed to apply instance auth changes: {exc.message}") from exc
    return AuthSyncResult(written=written, removed=removed, reloaded=True)


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
    registry: Registry,
    *,
    auth_snippet_dir: Path | None = None,
    auth_caddyfile_path: Path | None = None,
    runner=run_streamed,
) -> None:
    """Destroy an instance's containers, directory, and Caddy auth snippet.
    Assumes the caller already holds the instance lock (used by deploy()'s
    `replace=True` path to avoid re-entering instance_lock, which would
    raise LockHeldError).

    The auth-snippet removal always runs, even if the instance never had
    auth enabled (disable_instance_auth() is a no-op in that case) — this is
    what keeps a destroyed instance from leaving a stale `@auth-<id>`
    matcher behind, which would otherwise either linger unused or collide
    with a later re-deploy of the same instance id."""
    try:
        tmux.kill_instance_window(instance_id, runner=runner)
    except Exception as exc:  # noqa: BLE001 - tmux teardown is best-effort
        logger.warning("tmux kill-window failed for %s: %s", instance_id, exc)

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

    try:
        caddyports.sync(
            registry,
            snippet_dir=caddyports.DEFAULT_PORTS_SNIPPET_DIR,
            caddyfile_path=caddyfile_path,
            runner=runner,
        )
    except CaddyPortsError as exc:
        raise FleetError(
            f"failed to reconcile Caddy port snippets after destroying instance "
            f"{instance_id!r}: {exc.message}"
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

    # Same None-sentinel resolution deploy() does above, resolved here (not
    # left to _destroy_locked's own defaulting) so the preflight check below
    # and _destroy_locked agree on the exact same paths.
    snippet_dir = (
        auth_snippet_dir if auth_snippet_dir is not None else caddyauth.DEFAULT_INSTANCE_SNIPPET_DIR
    )
    caddyfile_path = (
        auth_caddyfile_path if auth_caddyfile_path is not None else caddyauth.DEFAULT_CADDYFILE_PATH
    )

    with instance_lock(paths.locks, instance_id):
        # Lighter than deploy()'s preflight (no rebuild target to resolve —
        # a plain destroy has nothing to rebuild): just the two Caddy checks,
        # so a fleet-wide Caddy problem is surfaced before the instance
        # directory is removed rather than after (see _preflight_rebuild's
        # docstring for the incident this guards against).
        _preflight_rebuild(
            registry,
            instance_id,
            snippet_dir=snippet_dir,
            caddyfile_path=caddyfile_path,
            runner=runner,
        )
        _destroy_locked(
            paths,
            instance_id,
            registry,
            auth_snippet_dir=snippet_dir,
            auth_caddyfile_path=caddyfile_path,
            runner=runner,
        )

    lock_path = paths.locks / f"{instance_id}.lock"
    if lock_path.exists():
        lock_path.unlink()


def refresh_instance_config(
    paths: FleetPaths,
    registry: Registry,
    instance_id: str,
    *,
    restart: bool = False,
    runner=run_streamed,
) -> None:
    """Regenerate an instance's fleet-owned DDEV config
    (`.ddev/config.fleet.yaml`, incl. the Claude onboarding `post-start`
    hook, and `.ddev/web-build/Dockerfile.fleet-claude`) WITHOUT a full
    deploy — no clone/git-update/`ddev start`. This is the primitive that
    lets an already-deployed instance pick up config changes (e.g. a new
    hook) without redeploying.

    `write_fleet_config()` does a FULL rewrite of config.fleet.yaml, so
    every arg it takes is rebuilt here exactly as `deploy()` builds it —
    dropping one would silently drop that env var / hook from every
    refreshed instance.

    Per the "cheap authoritative write always; expensive propagation
    opt-in" rule (see feedback-no-forced-bulk-operations memory): the
    config rewrite always happens; restarting the instance's containers so
    the new config takes effect is opt-in via `restart=True`."""
    instance_dir = paths.instances / instance_id
    if not instance_dir.exists():
        raise FleetError(f"instance directory not found for {instance_id!r}")

    with instance_lock(paths.locks, instance_id):
        project = project_for_instance(instance_dir, instance_id)

        secrets = read_secrets(paths.secrets)
        claude_token = secrets.get("CLAUDE_CODE_OAUTH_TOKEN")
        if not claude_token:
            _append_log(
                paths.logs / instance_id / "deploy.log",
                f"WARNING: CLAUDE_CODE_OAUTH_TOKEN not found in {paths.secrets}; "
                "proceeding without injecting a Claude token into this instance "
                "(run 'fleet init' or 'fleet set-claude-token', then "
                "'fleet refresh-instance-config' to inject it later)",
            )
        alias_hosts = alias_fqdns(registry, project, instance_id)

        typesense_enabled = registry.typesense_enabled(project)
        typesense_admin_key = None
        typesense_search_key = None
        if typesense_enabled:
            typesense_admin_key, typesense_search_key = typesense.ensure_project_keys(
                paths.project_secrets / f"{project}.env"
            )

        write_fleet_config(
            instance_dir,
            instance_id,
            registry.domain,
            claude_token,
            additional_fqdns=alias_hosts,
            git_bot=registry.git_bot(project),
            typesense=typesense_enabled,
            typesense_port=registry.port_profile("typesense").public,
            typesense_admin_key=typesense_admin_key,
            typesense_search_key=typesense_search_key,
            ports=registry.project_ports(project),
        )
        write_web_build(instance_dir)

        if restart:
            result = ddev.restart(instance_dir, runner=runner)
            if result.returncode != 0:
                raise FleetError(
                    f"ddev restart failed for {instance_id!r} with exit code {result.returncode}"
                )


def start(
    paths: FleetPaths,
    registry: Registry,
    instance_id: str,
    *,
    timeout: float | None = None,
    retry_port_conflict: bool = False,
    runner=run_streamed,
) -> None:
    instance_dir = paths.instances / instance_id
    if not instance_dir.exists():
        raise FleetError(f"instance directory not found for {instance_id!r}")
    with instance_lock(paths.locks, instance_id):
        result = ddev.start(instance_dir, timeout=timeout, runner=runner)
        if result.returncode != 0:
            # Opt-in self-heal for the Docker port-allocation race (spec
            # §16d/fleet-boot.service): a FAST-FAIL `ddev start` whose output
            # names a port conflict gets exactly ONE clean stop-then-start
            # before we give up — this releases and reallocates the host
            # ports, which is the proven manual fix. Any other failure (or a
            # second consecutive port conflict) propagates immediately;
            # `retry_port_conflict=False` (the default) never changes
            # behaviour at all.
            if retry_port_conflict and ddev.is_port_conflict("\n".join(result.lines)):
                print(f"{instance_id}: port conflict on start — retrying with stop+start")
                ddev.stop(instance_dir, runner=runner)
                result = ddev.start(instance_dir, timeout=timeout, runner=runner)
                if result.returncode != 0:
                    raise FleetError(
                        f"ddev start failed for {instance_id!r} with exit code {result.returncode}"
                    )
                return
            raise FleetError(
                f"ddev start failed for {instance_id!r} with exit code {result.returncode}"
            )


def stop(
    paths: FleetPaths,
    registry: Registry,
    instance_id: str,
    *,
    timeout: float | None = None,
    runner=run_streamed,
) -> None:
    instance_dir = paths.instances / instance_id
    if not instance_dir.exists():
        raise FleetError(f"instance directory not found for {instance_id!r}")
    with instance_lock(paths.locks, instance_id):
        result = ddev.stop(instance_dir, timeout=timeout, runner=runner)
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


def read_instance_branch(instance_dir: Path) -> str:
    """Return the DEPLOY-TIME branch recorded in <instance_dir>/.fleet/instance.yml,
    or "" if the file is absent/unreadable. Deliberately does NOT call
    list_instances() (no ddev/docker), so it's cheap to call on a fast refresh
    loop. Currently unused/reserved: the tmux sidebar and web UI now read the
    LIVE branch via read_instance_git_branch() instead, since a checkout can be
    switched to a different branch after deploy without this recorded value
    changing."""
    info_path = instance_dir / ".fleet" / "instance.yml"
    if not info_path.exists():
        return ""
    try:
        with open(info_path, "r", encoding="utf-8") as fh:
            data = _yaml.load(fh) or {}
    except Exception:  # noqa: BLE001 - best-effort display helper
        return ""
    if not isinstance(data, dict):
        return ""
    return str(data.get("branch", ""))


def read_instance_git_branch(instance_dir: Path, *, runner=run_streamed) -> str:
    """Return the ACTUAL current git branch of the instance checkout, or "" if
    it can't be determined. Uses `git rev-parse` (works for both git worktrees
    and full clones). On a detached HEAD, returns the short commit SHA rather
    than the literal "HEAD". Best-effort: any git error/exception yields "" so a
    bad checkout never crashes the sidebar refresh loop."""
    try:
        result = runner(
            ["git", "-C", str(instance_dir), "rev-parse", "--abbrev-ref", "HEAD"],
            echo=False,
        )
    except Exception:  # noqa: BLE001 - best-effort display helper
        return ""
    if result.returncode != 0:
        return ""
    branch = "\n".join(result.lines).strip()
    if branch != "HEAD":
        return branch
    try:
        sha = runner(
            ["git", "-C", str(instance_dir), "rev-parse", "--short", "HEAD"],
            echo=False,
        )
    except Exception:  # noqa: BLE001 - best-effort display helper
        return ""
    if sha.returncode != 0:
        return ""
    return "\n".join(sha.lines).strip()


def read_instance_git_head(instance_dir: Path, *, runner=run_streamed) -> str:
    """Return the short commit SHA of the instance checkout's HEAD (e.g.
    "9201b89b53"), or "" if it can't be determined. Best-effort: any git
    error/exception yields "" so a bad checkout never breaks the sidebar refresh
    loop or the web UI list."""
    try:
        result = runner(
            ["git", "-C", str(instance_dir), "rev-parse", "--short", "HEAD"],
            echo=False,
        )
    except Exception:  # noqa: BLE001 - best-effort display helper
        return ""
    if result.returncode != 0:
        return ""
    return "\n".join(result.lines).strip()


@dataclass
class InstanceStatus:
    instance_id: str
    project: str
    instance: str
    branch: str
    state: str
    url: str
    ram_mib: int | None
    head: str = ""


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

        live_branch = read_instance_git_branch(entry, runner=runner)
        if live_branch:
            branch = live_branch

        head = read_instance_git_head(entry, runner=runner)

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
                head=head,
            )
        )
    return statuses
