"""FastAPI daemon: tls-authorize + in-memory job registry (spec §3.4, §9.3,
§13). Grows into the full web UI across this plan's remaining tasks.
"""

import asyncio
import base64
import hashlib
import hmac
import json
import logging
import os
import re
import time
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, Form, Query, WebSocket, WebSocketDisconnect
from fastapi.responses import HTMLResponse, JSONResponse, PlainTextResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.requests import Request

from fleet.core import bulk as bulk_mod
from fleet.core import caddyauth, caddyports, naming, sysinfo
from fleet.core import instances as instances_mod
from fleet.core.errors import CaddyPortsError, DeployError, FleetError
from fleet.core.registry import Registry
from fleet.jobs import JobManager

_STATIC_DIR = Path(__file__).parent / "static"
_TEMPLATES_DIR = Path(__file__).parent / "templates"

_INSTANCE_ID_RE = re.compile(r"^[a-z0-9][a-z0-9-]*$")

_HEARTBEAT_EVERY = 15.0

logger = logging.getLogger(__name__)


def _validate_instance_id(instance_id: str) -> None:
    if not _INSTANCE_ID_RE.match(instance_id):
        raise FleetError(f"invalid instance id {instance_id!r}")


def mint_ws_token(secret: bytes, instance_id: str, *, ttl: float = 3600.0) -> str:
    exp = int(time.time() + ttl)
    msg = f"{instance_id}:{exp}".encode()
    sig = hmac.new(secret, msg, hashlib.sha256).digest()
    sig_b64 = base64.urlsafe_b64encode(sig).decode().rstrip("=")
    return f"{exp}.{sig_b64}"


def verify_ws_token(secret: bytes, token: str | None, instance_id: str) -> bool:
    if not token or "." not in token:
        return False
    exp_str, sig_b64 = token.split(".", 1)
    try:
        exp = int(exp_str)
    except ValueError:
        return False
    if time.time() > exp:
        return False
    msg = f"{instance_id}:{exp}".encode()
    expected = hmac.new(secret, msg, hashlib.sha256).digest()
    try:
        got = base64.urlsafe_b64decode(sig_b64 + "=" * (-len(sig_b64) % 4))
    except Exception:
        return False
    return hmac.compare_digest(expected, got)


def _bulk_progress_payload(outcome) -> dict:
    total = len(outcome.results)
    failed = len(outcome.failed)
    partial = failed > 0 and len(outcome.succeeded) > 0
    return {
        "total": total,
        "done": total,
        "failed": failed,
        "current": None,
        "partial": partial,
        "results": [
            {"instance_id": r.instance_id, "ok": r.ok, "error": r.error} for r in outcome.results
        ],
    }


class _BulkJobFailed(Exception):
    """Raised by a bulk job's `fn` to force JobManager to mark the job
    'failed' while `str(exc)` still carries the full JSON progress blob
    as `job.detail` — see JobManager._run's except branch, which sets
    `job.detail = str(exc)`."""


def _parse_bulk_progress(detail: str | None) -> dict | None:
    if not detail:
        return None
    try:
        return json.loads(detail)
    except (TypeError, ValueError):
        return None


def _is_bulk_kind(kind: str) -> bool:
    # Mirrors job_panel.html's `is_bulk` Jinja test — kept in sync so the
    # WS token subject and the socket URL the template renders always agree.
    return kind.startswith("bulk-") or kind == "multi-deploy"


def _job_ws_token(secret: bytes, job) -> str | None:
    """Mint the live-log WS token for a job panel.

    Bulk/multi-deploy jobs are submitted with `instance_id=""` (the real
    instance ids live in `job.instance_ids`, and the one currently being
    worked on is `progress.current`, parsed from `job.detail`) — minting
    from `job.instance_id` would sign a token for "" that can never verify
    against the instance job_panel.html actually points the socket at.
    Single-instance jobs are unaffected and keep minting for
    `job.instance_id` as before.

    Returns None when a bulk job has no current instance yet (e.g. right
    after submit, or momentarily between steps) rather than minting a
    token bound to "" — job_panel.html's `{% if progress.current %}` guard
    means no log element is rendered in that case anyway, and the panel's
    2s poll will remint once `progress.current` is set.
    """
    if _is_bulk_kind(job.kind):
        progress = _parse_bulk_progress(job.detail)
        current = progress.get("current") if progress else None
        if not current:
            return None
        return mint_ws_token(secret, current)
    return mint_ws_token(secret, job.instance_id)


def create_app(fleet_home: Path, *, heartbeat_every: float = _HEARTBEAT_EVERY) -> FastAPI:
    def _paths_and_registry():
        paths = instances_mod.FleetPaths.from_home(fleet_home)
        registry = Registry.load(paths.registry)
        return paths, registry

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        """Safety net for fleet.yml port edits made while the daemon was
        down (spec §5) — reconciles Caddy port snippets to the registry's
        current state. Never fails app startup: a broken registry/Caddy
        at boot is logged, not fatal, so the daemon (and its own
        dashboard — the only way to fix a broken registry) stays
        reachable."""
        try:
            _, registry = _paths_and_registry()
        except FleetError as exc:
            logger.warning("startup port sync skipped: registry error: %s", exc.message)
        else:
            try:
                await asyncio.to_thread(
                    caddyports.sync,
                    registry,
                    snippet_dir=caddyports.DEFAULT_PORTS_SNIPPET_DIR,
                )
            except CaddyPortsError as exc:
                logger.warning("startup port sync failed: %s", exc.message)
            except Exception:
                # `sync()` probes the snippet dir (ensure_snippet_dir) and
                # `.glob()`s it before its own try/except wrapping
                # (core/caddyports.py), so a bare
                # PermissionError/OSError (or anything else unanticipated)
                # can escape uncaught. Startup must NEVER be blocked by a
                # broken port sync — a fleet manager that refuses to boot
                # over one bad port snippet is worse than one that boots and
                # reports the problem. Logged with a full traceback (not just
                # `.message`) precisely because this branch catches failures
                # `CaddyPortsError` was never designed to describe.
                logger.exception("startup port sync failed with an unexpected error")
        yield

    app = FastAPI(lifespan=lifespan)
    app.state.jobs = JobManager()
    app.state.ws_secret = os.urandom(32)
    app.mount("/static", StaticFiles(directory=_STATIC_DIR), name="static")

    templates = Jinja2Templates(directory=_TEMPLATES_DIR)
    templates.env.filters["bulk_progress"] = _parse_bulk_progress

    @app.exception_handler(FleetError)
    async def fleet_error_handler(request: Request, exc: FleetError):
        # htmx 1.9.12 does NOT swap non-2xx responses into hx-target by
        # default — it only fires `htmx:responseError`, which nothing
        # handled for the deploy form (bulk.js's old handler only covered
        # `/ui/bulk/*`). Before ui-errors.js's generic `htmx:beforeSwap`
        # fix, a validation error here was a correct-but-invisible 400: the
        # Deploy button looked dead with no message anywhere. Every htmx
        # request carries `HX-Request: true` (case-insensitive header name,
        # per Starlette's Headers — the value itself is always lowercase
        # "true" from htmx's own JS), so that's the signal to render an
        # HTML body ui-errors.js can swap in, instead of the raw JSON the
        # `/api/*` clients (never HTMX requests) still need unchanged.
        if request.headers.get("hx-request", "").lower() == "true":
            return templates.TemplateResponse(
                request, "partials/error.html", {"message": exc.message}, status_code=400
            )
        return JSONResponse(status_code=400, content={"error": exc.message})

    @app.get("/api/tls-authorize")
    def tls_authorize(domain: str = Query(...)):
        # Authorizes on-demand TLS issuance for Caddy (Caddyfile.j2's
        # `on_demand_tls.ask`). Deliberately narrow: only a bare known
        # instance id, or a label matching one of THAT instance's project's
        # registered `additional_hostnames` aliases — never a generic
        # `*-<instance_id>` suffix match (removed 2026-09-22; it would have
        # let anyone mint a cert for an unregistered `foo-<instance_id>`
        # hostname pointed at a real instance).
        paths, registry = _paths_and_registry()
        suffix = f".{registry.domain}"
        if not domain.endswith(suffix):
            return JSONResponse(status_code=404, content={"authorized": False})

        label = domain[: -len(suffix)]
        if "." in label:
            return JSONResponse(status_code=404, content={"authorized": False})

        instances_path = paths.instances
        if not instances_path.exists():
            return JSONResponse(status_code=404, content={"authorized": False})

        known_ids = {p.name for p in instances_path.iterdir() if p.is_dir()}
        if label in known_ids:
            return JSONResponse(status_code=200, content={"authorized": True})

        for instance_id in known_ids:
            project = instances_mod.project_for_instance(instances_path / instance_id, instance_id)
            if not registry.has_project(project):
                # Deployed from a project since removed from the registry —
                # only the bare instance label above authorizes; no aliases.
                continue
            try:
                aliases = instances_mod.alias_fqdns(registry, project, instance_id)
            except DeployError:
                # A misconfigured additional_hostnames entry for SOME OTHER
                # instance must never block TLS issuance for this request —
                # deploy()/refresh-instance-config are what surface that
                # loudly; this endpoint just skips it.
                continue
            if domain in aliases:
                return JSONResponse(status_code=200, content={"authorized": True})
        return JSONResponse(status_code=404, content={"authorized": False})

    @app.get("/api/jobs/{job_id}")
    def get_job(job_id: str):
        job = app.state.jobs.get(job_id)
        if job is None:
            return JSONResponse(status_code=404, content={"detail": "unknown job"})
        return {
            "id": job.id,
            "kind": job.kind,
            "instance_id": job.instance_id,
            "state": job.state,
            "detail": job.detail,
            "log_path": job.log_path,
        }

    @app.websocket("/ws/instances/{instance_id}/log")
    async def ws_instance_log(websocket: WebSocket, instance_id: str):
        await websocket.accept()
        token = websocket.query_params.get("token")
        if not _INSTANCE_ID_RE.match(instance_id) or not verify_ws_token(
            app.state.ws_secret, token, instance_id
        ):
            await websocket.close(code=1008)
            return

        paths, _ = _paths_and_registry()
        log_path = paths.logs / instance_id / "deploy.log"

        try:
            if log_path.exists():
                offset = log_path.stat().st_size
                await websocket.send_text(log_path.read_text(encoding="utf-8"))
            else:
                offset = 0
                await websocket.send_text("waiting for log...\n")

            idle_elapsed = 0.0
            while True:
                await asyncio.sleep(0.3)
                if not log_path.exists():
                    idle_elapsed += 0.3
                    if idle_elapsed >= heartbeat_every:
                        await websocket.send_text("")
                        idle_elapsed = 0.0
                    continue
                size = log_path.stat().st_size
                if size > offset:
                    with open(log_path, "r", encoding="utf-8") as fh:
                        fh.seek(offset)
                        chunk = fh.read()
                    offset = size
                    await websocket.send_text(chunk)
                    idle_elapsed = 0.0
                else:
                    idle_elapsed += 0.3
                    if idle_elapsed >= heartbeat_every:
                        await websocket.send_text("")
                        idle_elapsed = 0.0
        except WebSocketDisconnect:
            return

    @app.get("/ui/instances/{instance_id}/log")
    async def instance_log(instance_id: str):
        # Same reject-before-touching-the-filesystem pattern as
        # ws_instance_log above: instance_id is user-supplied (comes
        # straight off the URL path), so it MUST be validated against
        # _INSTANCE_ID_RE before it is ever joined onto paths.logs — that
        # regex only allows lowercase alnum/hyphen, which rules out `..`,
        # `/`, and other traversal characters. A 404 here (rather than a
        # 400/FleetError) is deliberate: it doesn't distinguish "malformed
        # id" from "no such log" to a caller, which is the least
        # information to leak for what is otherwise a route requiring no
        # separate auth token (it sits behind the dashboard's Caddy
        # basic_auth, same as every other /ui/* route — see
        # ansible/roles/caddy/templates/Caddyfile.j2's `@protected not path
        # /ws/*` matcher).
        if not _INSTANCE_ID_RE.match(instance_id):
            return PlainTextResponse("not found\n", status_code=404)
        paths, _ = _paths_and_registry()
        log_path = paths.logs / instance_id / "deploy.log"
        if not log_path.exists():
            return PlainTextResponse("no deploy log yet\n", status_code=404)
        return PlainTextResponse(log_path.read_text(encoding="utf-8"))

    @app.get("/")
    async def index(request: Request):
        paths, registry = _paths_and_registry()
        statuses = await asyncio.to_thread(instances_mod.list_instances, paths, registry)
        project_keys = registry.project_keys()
        project_templates = {p: registry.template_keys(p) for p in project_keys}
        # The Template <select>'s initial render must match the Project
        # <select>'s default selection (its first `<option>`), NOT every
        # project's templates flattened together — that flattening let a
        # user pick a template belonging to a different project, the
        # second bug in this fix. `/ui/deploy/templates` (below) refreshes
        # this same partial via hx-get whenever the Project select changes.
        initial_templates = registry.template_keys(project_keys[0]) if project_keys else []
        sys_stats = await asyncio.to_thread(sysinfo.SystemStats.gather, paths.instances)
        return templates.TemplateResponse(
            request,
            "instances.html",
            {
                "statuses": statuses,
                "project_templates": project_templates,
                "template_options": initial_templates,
                "sys_stats": sys_stats.display(),
            },
        )

    @app.get("/ui/deploy/templates")
    async def ui_deploy_templates(request: Request, project: str = Query(...)):
        # Backs the Project select's `hx-get` (instances.html): re-renders
        # ONLY `partials/template_options.html`, the same partial the index
        # route above uses for the initial render, so there is one source
        # of truth for "what templates does this project have" instead of
        # two templates drifting apart. `registry.template_keys()` raises
        # RegistryError (a FleetError) for an unknown project, which
        # `fleet_error_handler` above turns into a renderable 400 for this
        # htmx-originated request — no separate validation needed here.
        _, registry = _paths_and_registry()
        template_options = registry.template_keys(project)
        return templates.TemplateResponse(
            request, "partials/template_options.html", {"template_options": template_options}
        )

    @app.post("/ui/instances/{instance_id}/start")
    async def ui_start(request: Request, instance_id: str):
        _validate_instance_id(instance_id)
        paths, registry = _paths_and_registry()
        await asyncio.to_thread(instances_mod.start, paths, registry, instance_id)
        statuses = await asyncio.to_thread(instances_mod.list_instances, paths, registry)
        try:
            status = next(s for s in statuses if s.instance_id == instance_id)
        except StopIteration:
            raise FleetError(f"instance {instance_id!r} no longer exists")
        return templates.TemplateResponse(request, "partials/instance_row.html", {"status": status})

    @app.post("/ui/instances/{instance_id}/stop")
    async def ui_stop(request: Request, instance_id: str):
        _validate_instance_id(instance_id)
        paths, registry = _paths_and_registry()
        await asyncio.to_thread(instances_mod.stop, paths, registry, instance_id)
        statuses = await asyncio.to_thread(instances_mod.list_instances, paths, registry)
        try:
            status = next(s for s in statuses if s.instance_id == instance_id)
        except StopIteration:
            raise FleetError(f"instance {instance_id!r} no longer exists")
        return templates.TemplateResponse(request, "partials/instance_row.html", {"status": status})

    @app.post("/ui/instances/{instance_id}/destroy")
    async def ui_destroy(instance_id: str):
        _validate_instance_id(instance_id)
        paths, registry = _paths_and_registry()
        await asyncio.to_thread(instances_mod.destroy, paths, registry, instance_id)
        return HTMLResponse("")

    @app.post("/ui/instances/{instance_id}/redeploy")
    async def ui_redeploy(request: Request, instance_id: str):
        # A redeploy destroys the instance before rebuilding it, so — like
        # `/ui/deploy` — it is long-running and must return a job panel with
        # a live log, NOT the row: the row-swapping start/stop/destroy routes
        # above are all fast, fire-and-forget operations, but this one isn't.
        # Follows `/ui/deploy`'s single-instance job submission exactly
        # (log_path, app.state.jobs.submit, _job_ws_token, job_panel.html) so
        # the two forms of "long job with a live log" never drift apart.
        _validate_instance_id(instance_id)
        paths, registry = _paths_and_registry()
        log_path = str(paths.logs / instance_id / "deploy.log")

        def run_redeploy():
            return instances_mod.redeploy(paths, registry, instance_id)

        job = await app.state.jobs.submit("redeploy", instance_id, run_redeploy, log_path=log_path)
        ws_token = _job_ws_token(app.state.ws_secret, job)
        return templates.TemplateResponse(
            request, "partials/job_panel.html", {"job": job, "ws_token": ws_token}
        )

    @app.post("/ui/deploy")
    async def ui_deploy(
        request: Request,
        project: str = Form(...),
        template: str = Form(...),
        branch: str = Form(""),
        label: str = Form(""),
        count: int = Form(1),
        auth: str = Form(""),
        auth_password: str = Form(""),
        skip_disk_check: str = Form(""),
    ):
        naming.validate_part(project)
        if not (0 <= count <= 20):
            raise FleetError(f"--count must be between 0 and 20 (got {count})")
        auth_password = auth_password or caddyauth.DEFAULT_INSTANCE_PASSWORD
        if auth:
            # Synchronous 400 rather than a failed background job — the
            # credential doubles as the basic-auth username.
            caddyauth.validate_instance_credential(auth_password)
        paths, registry = _paths_and_registry()

        if count == 1:
            resolved = instances_mod.resolve_target(
                registry, project, template or None, branch or None, label or None
            )
            inst_id = resolved.instance_id
            log_path = str(paths.logs / inst_id / "deploy.log")

            def run_deploy():
                return instances_mod.deploy(
                    paths,
                    registry,
                    project,
                    template or None,
                    branch=branch or None,
                    label=label or None,
                    # HTML checkboxes submit NOTHING when unchecked — `auth`
                    # arrives as "" (Form default) in that case, and bool("") is
                    # False, so an unchecked box means auth OFF, not a silent
                    # fall-through to the default-ON behavior. Deliberately NOT
                    # `auth_enabled=True if not auth else bool(auth)` — that
                    # would make "unchecked" indistinguishable from "field never
                    # sent" and always resolve to ON, which is the classic bug.
                    auth_enabled=bool(auth),
                    auth_password=auth_password,
                )

            job = await app.state.jobs.submit("deploy", inst_id, run_deploy, log_path=log_path)
            ws_token = _job_ws_token(app.state.ws_secret, job)
            return templates.TemplateResponse(
                request, "partials/job_panel.html", {"job": job, "ws_token": ws_token}
            )

        if count == 0:
            return HTMLResponse("<p>nothing to deploy (--count=0)</p>")

        # Pre-flight validation done SYNCHRONOUSLY, before any job is
        # created, so a tripped disk gate surfaces as an immediate 400
        # (spec §9: pre-flight failures abort before the loop begins) —
        # same pattern as naming.validate_part(project) above.
        if not bool(skip_disk_check):
            sysinfo.check_disk_headroom(paths.instances)

        job_holder: dict[str, object] = {}

        def on_progress(progress: dict) -> None:
            job_holder["job"].detail = json.dumps(progress)

        def run_multi_deploy():
            outcome = bulk_mod.multi_deploy(
                paths,
                registry,
                project,
                template or None,
                branch=branch or None,
                label=label or None,
                count=count,
                auth_enabled=bool(auth),
                auth_password=auth_password,
                skip_disk_check=True,  # already checked synchronously above
                on_progress=on_progress,
            )
            final = _bulk_progress_payload(outcome)
            if not outcome.all_ok:
                raise _BulkJobFailed(json.dumps(final))
            return json.dumps(final)

        job = await app.state.jobs.submit("multi-deploy", "", run_multi_deploy)
        job_holder["job"] = job
        ws_token = _job_ws_token(app.state.ws_secret, job)
        return templates.TemplateResponse(
            request, "partials/job_panel.html", {"job": job, "ws_token": ws_token}
        )

    async def _ui_bulk_start_stop(request: Request, instance_ids: list[str], *, kind: str, op):
        paths, registry = _paths_and_registry()
        job_holder: dict[str, object] = {}

        def on_progress(progress: dict) -> None:
            job_holder["job"].detail = json.dumps(progress)

        def run_bulk():
            outcome = bulk_mod.run_concurrent(
                paths, registry, instance_ids, op, kind=kind, on_progress=on_progress
            )
            final = _bulk_progress_payload(outcome)
            if not outcome.all_ok:
                raise _BulkJobFailed(json.dumps(final))
            return json.dumps(final)

        job = await app.state.jobs.submit(kind, "", run_bulk, instance_ids=instance_ids)
        job_holder["job"] = job
        ws_token = _job_ws_token(app.state.ws_secret, job)
        return templates.TemplateResponse(
            request, "partials/job_panel.html", {"job": job, "ws_token": ws_token}
        )

    @app.post("/ui/bulk/start")
    async def ui_bulk_start(request: Request, instance_id: list[str] = Form(...)):
        return await _ui_bulk_start_stop(
            request, instance_id, kind="bulk-start", op=instances_mod.start
        )

    @app.post("/ui/bulk/stop")
    async def ui_bulk_stop(request: Request, instance_id: list[str] = Form(...)):
        return await _ui_bulk_start_stop(
            request, instance_id, kind="bulk-stop", op=instances_mod.stop
        )

    @app.post("/ui/bulk/destroy")
    async def ui_bulk_destroy(
        request: Request,
        instance_id: list[str] = Form(...),
        confirm_count: int = Form(...),
    ):
        if confirm_count != len(instance_id):
            raise FleetError(
                f"confirmation count {confirm_count} does not match "
                f"{len(instance_id)} selected instance(s); nothing destroyed"
            )
        paths, registry = _paths_and_registry()
        job_holder: dict[str, object] = {}

        def on_progress(progress: dict) -> None:
            job_holder["job"].detail = json.dumps(progress)

        def run_bulk():
            outcome = bulk_mod.run_sequential(
                paths,
                registry,
                instance_id,
                instances_mod.destroy,
                kind="bulk-destroy",
                on_progress=on_progress,
            )
            final = _bulk_progress_payload(outcome)
            if not outcome.all_ok:
                raise _BulkJobFailed(json.dumps(final))
            return json.dumps(final)

        job = await app.state.jobs.submit("bulk-destroy", "", run_bulk, instance_ids=instance_id)
        job_holder["job"] = job
        ws_token = _job_ws_token(app.state.ws_secret, job)
        return templates.TemplateResponse(
            request, "partials/job_panel.html", {"job": job, "ws_token": ws_token}
        )

    @app.post("/ui/bulk/redeploy")
    async def ui_bulk_redeploy(request: Request, instance_id: list[str] = Form(...)):
        # Closest analogue is /ui/bulk/destroy above (destructive, job +
        # bulk_progress) — but unlike bulk destroy, the confirmation on the
        # button itself is a plain hx-confirm (instances.html), not the
        # typed-count flow, so there is no confirm_count to check here.
        # Sequential, like bulk destroy and multi_deploy — a redeploy is a
        # full destroy + clone + DB import per instance.
        paths, registry = _paths_and_registry()
        job_holder: dict[str, object] = {}

        def on_progress(progress: dict) -> None:
            job_holder["job"].detail = json.dumps(progress)

        def run_bulk():
            outcome = bulk_mod.run_sequential(
                paths,
                registry,
                instance_id,
                instances_mod.redeploy,
                kind="bulk-redeploy",
                on_progress=on_progress,
            )
            final = _bulk_progress_payload(outcome)
            if not outcome.all_ok:
                raise _BulkJobFailed(json.dumps(final))
            return json.dumps(final)

        job = await app.state.jobs.submit("bulk-redeploy", "", run_bulk, instance_ids=instance_id)
        job_holder["job"] = job
        ws_token = _job_ws_token(app.state.ws_secret, job)
        return templates.TemplateResponse(
            request, "partials/job_panel.html", {"job": job, "ws_token": ws_token}
        )

    @app.get("/ui/jobs/{job_id}/panel")
    async def ui_job_panel(request: Request, job_id: str):
        job = app.state.jobs.get(job_id)
        if job is None:
            return JSONResponse(status_code=404, content={"detail": "unknown job"})
        ws_token = _job_ws_token(app.state.ws_secret, job)
        return templates.TemplateResponse(
            request, "partials/job_panel.html", {"job": job, "ws_token": ws_token}
        )

    return app


app = create_app(Path(os.environ.get("FLEET_HOME", "/srv/fleet")))
