"""FastAPI daemon: tls-authorize + in-memory job registry (spec §3.4, §9.3,
§13). Grows into the full web UI across this plan's remaining tasks.
"""

import asyncio
import os
from pathlib import Path

from fastapi import FastAPI, Query, WebSocket, WebSocketDisconnect
from fastapi.responses import JSONResponse

from fleet.core.registry import Registry
from fleet.jobs import JobManager


def create_app(fleet_home: Path) -> FastAPI:
    app = FastAPI()
    app.state.jobs = JobManager()

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
        registry = Registry.load(fleet_home / "fleet.yml")
        log_path = registry.instances_path / instance_id / ".fleet" / "deploy.log"

        try:
            if log_path.exists():
                offset = log_path.stat().st_size
                await websocket.send_text(log_path.read_text(encoding="utf-8"))
            else:
                offset = 0
                await websocket.send_text("waiting for log...\n")

            while True:
                await asyncio.sleep(0.3)
                if not log_path.exists():
                    continue
                size = log_path.stat().st_size
                if size > offset:
                    with open(log_path, "r", encoding="utf-8") as fh:
                        fh.seek(offset)
                        chunk = fh.read()
                    offset = size
                    await websocket.send_text(chunk)
        except WebSocketDisconnect:
            return

    return app


app = create_app(Path(os.environ.get("FLEET_HOME", "/srv/fleet")))
