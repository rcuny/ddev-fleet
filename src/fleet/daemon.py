"""FastAPI daemon: tls-authorize + in-memory job registry (spec §3.4, §9.3,
§13). Grows into the full web UI across this plan's remaining tasks.
"""

import asyncio
import base64
import hashlib
import hmac
import os
import re
import time
from pathlib import Path

from fastapi import FastAPI, Form, Query, WebSocket, WebSocketDisconnect
from fastapi.responses import HTMLResponse, JSONResponse, PlainTextResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.requests import Request

from fleet.core import caddyauth, naming, sysinfo
from fleet.core import instances as instances_mod
from fleet.core.errors import FleetError
from fleet.core.registry import Registry
from fleet.jobs import JobManager

_STATIC_DIR = Path(__file__).parent / "static"
_TEMPLATES_DIR = Path(__file__).parent / "templates"

_INSTANCE_ID_RE = re.compile(r"^[a-z0-9][a-z0-9-]*$")

_HEARTBEAT_EVERY = 15.0


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


def create_app(fleet_home: Path, *, heartbeat_every: float = _HEARTBEAT_EVERY) -> FastAPI:
    app = FastAPI()
    app.state.jobs = JobManager()
    app.state.ws_secret = os.urandom(32)
    app.mount("/static", StaticFiles(directory=_STATIC_DIR), name="static")
    templates = Jinja2Templates(directory=_TEMPLATES_DIR)

    @app.exception_handler(FleetError)
    async def fleet_error_handler(request: Request, exc: FleetError):
        return JSONResponse(status_code=400, content={"error": exc.message})

    def _paths_and_registry():
        paths = instances_mod.FleetPaths.from_home(fleet_home)
        registry = Registry.load(paths.registry)
        return paths, registry

    @app.get("/api/tls-authorize")
    def tls_authorize(domain: str = Query(...)):
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
        for instance_id in known_ids:
            if label == instance_id or label.endswith(f"-{instance_id}"):
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
        project_templates = {p: registry.template_keys(p) for p in registry.project_keys()}
        sys_stats = await asyncio.to_thread(sysinfo.SystemStats.gather, paths.instances)
        return templates.TemplateResponse(
            request,
            "instances.html",
            {
                "statuses": statuses,
                "project_templates": project_templates,
                "sys_stats": sys_stats.display(),
            },
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

    @app.post("/ui/deploy")
    async def ui_deploy(
        request: Request,
        project: str = Form(...),
        template: str = Form(...),
        branch: str = Form(""),
        label: str = Form(""),
        fresh: str = Form(""),
        auth: str = Form(""),
        auth_password: str = Form(""),
    ):
        naming.validate_part(project)
        paths, registry = _paths_and_registry()
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
                fresh=bool(fresh),
                # HTML checkboxes submit NOTHING when unchecked — `auth`
                # arrives as "" (Form default) in that case, and bool("") is
                # False, so an unchecked box means auth OFF, not a silent
                # fall-through to the default-ON behavior. Deliberately NOT
                # `auth_enabled=True if not auth else bool(auth)` — that
                # would make "unchecked" indistinguishable from "field never
                # sent" and always resolve to ON, which is the classic bug.
                auth_enabled=bool(auth),
                auth_password=auth_password or caddyauth.DEFAULT_INSTANCE_PASSWORD,
            )

        job = await app.state.jobs.submit("deploy", inst_id, run_deploy, log_path=log_path)
        ws_token = mint_ws_token(app.state.ws_secret, job.instance_id)
        return templates.TemplateResponse(
            request, "partials/job_panel.html", {"job": job, "ws_token": ws_token}
        )

    @app.get("/ui/jobs/{job_id}/panel")
    async def ui_job_panel(request: Request, job_id: str):
        job = app.state.jobs.get(job_id)
        if job is None:
            return JSONResponse(status_code=404, content={"detail": "unknown job"})
        ws_token = mint_ws_token(app.state.ws_secret, job.instance_id)
        return templates.TemplateResponse(
            request, "partials/job_panel.html", {"job": job, "ws_token": ws_token}
        )

    return app


app = create_app(Path(os.environ.get("FLEET_HOME", "/srv/fleet")))
