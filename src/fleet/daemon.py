"""FastAPI daemon: tls-authorize + in-memory job registry (spec §3.4, §9.3,
§13). Grows into the full web UI across this plan's remaining tasks.
"""

import asyncio
import os
import re
from pathlib import Path

from fastapi import FastAPI, Form, Query, WebSocket, WebSocketDisconnect
from fastapi.responses import HTMLResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.requests import Request

from fleet.core import instances as instances_mod
from fleet.core import naming
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


def create_app(fleet_home: Path, *, heartbeat_every: float = _HEARTBEAT_EVERY) -> FastAPI:
    app = FastAPI()
    app.state.jobs = JobManager()
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
        registry = Registry.load(fleet_home / "fleet.yml")
        suffix = f".{registry.domain}"
        if not domain.endswith(suffix):
            return JSONResponse(status_code=404, content={"authorized": False})

        label = domain[: -len(suffix)]
        if "." in label:
            return JSONResponse(status_code=404, content={"authorized": False})

        instances_path = registry.instances_path
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
        if not _INSTANCE_ID_RE.match(instance_id):
            await websocket.close(code=1008)
            return

        registry = Registry.load(fleet_home / "fleet.yml")
        log_path = registry.instances_path / instance_id / ".fleet" / "deploy.log"

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

    @app.get("/")
    async def index(request: Request):
        paths, registry = _paths_and_registry()
        statuses = await asyncio.to_thread(instances_mod.list_instances, paths, registry)
        return templates.TemplateResponse(
            request,
            "instances.html",
            {"statuses": statuses, "project_keys": registry.project_keys()},
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
        instance: str = Form(...),
        branch: str = Form(""),
        fresh: str = Form(""),
    ):
        paths, registry = _paths_and_registry()
        inst_id = naming.instance_id(project, instance)
        log_path = str(registry.instances_path / inst_id / ".fleet" / "deploy.log")

        def run_deploy():
            return instances_mod.deploy(
                paths, registry, project, instance, branch=branch or None, fresh=bool(fresh)
            )

        job = await app.state.jobs.submit("deploy", inst_id, run_deploy, log_path=log_path)
        return templates.TemplateResponse(request, "partials/job_panel.html", {"job": job})

    @app.get("/ui/jobs/{job_id}/panel")
    async def ui_job_panel(request: Request, job_id: str):
        job = app.state.jobs.get(job_id)
        if job is None:
            return JSONResponse(status_code=404, content={"detail": "unknown job"})
        return templates.TemplateResponse(request, "partials/job_panel.html", {"job": job})

    return app


app = create_app(Path(os.environ.get("FLEET_HOME", "/srv/fleet")))
