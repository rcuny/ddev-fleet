"""Minimal FastAPI stub exposing only /api/tls-authorize (spec §3.4, §9.3).

Replaced by the full daemon + web UI in a later plan (spec §13).
"""

import os
from pathlib import Path

from fastapi import FastAPI, Query
from fastapi.responses import JSONResponse

from fleet.core.registry import Registry


def create_app(fleet_home: Path) -> FastAPI:
    app = FastAPI()

    @app.get("/api/tls-authorize")
    def tls_authorize(domain: str = Query(...)):
        registry = Registry.load(fleet_home / "fleet.yml")
        suffix = f".{registry.domain}"
        if not domain.endswith(suffix):
            return JSONResponse(status_code=404, content={"detail": "unknown domain"})

        label = domain[: -len(suffix)]
        if "." in label:
            return JSONResponse(status_code=404, content={"detail": "unknown domain"})

        instances_path = registry.instances_path
        if not instances_path.exists():
            return JSONResponse(status_code=404, content={"detail": "unknown domain"})

        known_ids = {p.name for p in instances_path.iterdir() if p.is_dir()}
        for instance_id in known_ids:
            if label == instance_id or label.endswith(f"-{instance_id}"):
                return {"ok": True}
        return JSONResponse(status_code=404, content={"detail": "unknown domain"})

    return app


app = create_app(Path(os.environ.get("FLEET_HOME", "/srv/fleet")))
