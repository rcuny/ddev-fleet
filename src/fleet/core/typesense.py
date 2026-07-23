"""Generate and register per-project Typesense API keys so the browser
(fern_facets widget) can search an instance's Typesense over the fleet's
dedicated public port, without ever seeing the admin key (spec: typesense
edge exposure)."""

import json
import secrets as _stdlib_secrets
import urllib.request
from pathlib import Path

from fleet.core.errors import TypesenseError
from fleet.core.secrets import read_secrets, write_secret

_SEARCH_KEY_DESCRIPTION = "fleet-search-only"


def generate_key() -> str:
    """Return a fresh random key suitable for use as a Typesense API key."""
    return _stdlib_secrets.token_hex(24)


def ensure_project_keys(secrets_path: Path) -> tuple[str, str]:
    """Read the per-project secrets file at `secrets_path`; generate and
    persist `TYPESENSE_API_KEY` (admin) and `FLEET_TYPESENSE_SEARCH_KEY`
    (search-only) if either is missing. Idempotent — existing keys are
    preserved, never rotated. Returns `(admin_key, search_key)`."""
    existing = read_secrets(secrets_path)

    admin_key = existing.get("TYPESENSE_API_KEY")
    if not admin_key:
        admin_key = generate_key()
        write_secret(secrets_path, "TYPESENSE_API_KEY", admin_key)

    search_key = existing.get("FLEET_TYPESENSE_SEARCH_KEY")
    if not search_key:
        search_key = generate_key()
        write_secret(secrets_path, "FLEET_TYPESENSE_SEARCH_KEY", search_key)

    return admin_key, search_key


def register_search_key(
    *,
    http_port: int,
    host: str,
    admin_key: str,
    search_key: str,
    opener=None,
) -> None:
    """Register `search_key` as a documents:search-only Typesense key on the
    instance reachable via the shared ddev-router HTTP entrypoint at
    `127.0.0.1:{http_port}`, routed to the right instance by the `Host`
    header. Idempotent: does nothing if a key described
    "fleet-search-only" already exists. Raises `TypesenseError` if the
    Typesense admin API can't be reached or returns an error."""
    if opener is None:
        opener = urllib.request.build_opener()

    base_url = f"http://127.0.0.1:{http_port}/keys"

    get_request = urllib.request.Request(base_url, method="GET")
    get_request.add_header("X-TYPESENSE-API-KEY", admin_key)
    get_request.add_header("Host", host)

    try:
        with opener.open(get_request, timeout=10) as response:
            body = json.loads(response.read().decode("utf-8"))
    except Exception as exc:
        raise TypesenseError(
            f"failed to list Typesense keys on {host} via 127.0.0.1:{http_port}: {exc}"
        ) from exc

    existing_keys = body.get("keys") or []
    for existing_key in existing_keys:
        if existing_key.get("description") == _SEARCH_KEY_DESCRIPTION:
            return  # already registered

    payload = {
        "value": search_key,
        "description": _SEARCH_KEY_DESCRIPTION,
        "actions": ["documents:search"],
        "collections": ["*"],
    }
    post_request = urllib.request.Request(
        base_url,
        data=json.dumps(payload).encode("utf-8"),
        method="POST",
    )
    post_request.add_header("X-TYPESENSE-API-KEY", admin_key)
    post_request.add_header("Host", host)
    post_request.add_header("Content-Type", "application/json")

    try:
        with opener.open(post_request, timeout=10) as response:
            response.read()
    except Exception as exc:
        raise TypesenseError(
            f"failed to register fleet-search-only key on {host} via "
            f"127.0.0.1:{http_port}: {exc}"
        ) from exc
