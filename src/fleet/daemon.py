"""FastAPI daemon: tls-authorize + in-memory job registry (spec §3.4, §9.3,
§13). Grows into the full web UI across this plan's remaining tasks.
"""

import os
from pathlib import Path

from fastapi import FastAPI, Query
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

    return app


app = create_app(Path(os.environ.get("FLEET_HOME", "/srv/fleet")))
