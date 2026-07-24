"""Fleet-owned Caddy named-port exposure: compose, write, validate, reload
(spec: 2026-07-24-fleet-port-exposure-design.md §4).

Mirrors `caddyauth.py`'s write/validate/reload pattern, but targets one
snippet per PORT NAME (not per instance): `/etc/caddy/fleet/ports/<name>.conf`,
imported by the `*.{{ fleet_domain }}` Caddyfile via a glob
(`import {{ fleet_caddy_snippet_dir }}/ports/*.conf`) — same silent-no-op-
on-empty-glob reasoning as the per-instance auth snippets (see
`caddyauth`'s module docstring; verified against `caddy validate` v2.8.4).
Reconciled by `sync()`, called from `deploy()`/`destroy()`
(`fleet.core.instances`) and the `fleet refresh-ports` CLI command — never
rendered by Ansible past the initial empty-directory seed.

`CaddyPortsError` is a sibling of `CaddyAuthError`, not a reuse (spec
Assumption 4) — the failure domains match in shape (write/validate/reload)
but are conceptually distinct. Only the atomic-write primitive is shared
with `caddyauth` (spec Assumption 3); `validate`/`reload` are re-implemented
here so failures surface as `CaddyPortsError`.
"""

from dataclasses import dataclass, field
from pathlib import Path

from fleet.core import caddyauth
from fleet.core.registry import PortProfile

DEFAULT_PORTS_SNIPPET_DIR = Path("/etc/caddy/fleet/ports")


def port_snippet_path(port_name: str, *, snippet_dir: Path = DEFAULT_PORTS_SNIPPET_DIR) -> Path:
    return snippet_dir / f"{port_name}.conf"


def render_port_snippet(domain: str, profile: PortProfile) -> str:
    """Pure, no I/O. Structurally identical to today's static Typesense
    site block, generalized to any named port."""
    return (
        f"*.{domain}:{profile.public} {{\n"
        f"    reverse_proxy 127.0.0.1:{profile.router}\n"
        f"    tls {{ on_demand }}\n"
        f"}}\n"
    )


def write_port_snippet(
    domain: str, profile: PortProfile, *, snippet_dir: Path = DEFAULT_PORTS_SNIPPET_DIR
) -> Path:
    path = port_snippet_path(profile.name, snippet_dir=snippet_dir)
    content = render_port_snippet(domain, profile)
    caddyauth._atomic_write(path, content, prefix=f".{profile.name}-port-")
    return path


def remove_port_snippet(port_name: str, *, snippet_dir: Path = DEFAULT_PORTS_SNIPPET_DIR) -> bool:
    path = port_snippet_path(port_name, snippet_dir=snippet_dir)
    try:
        path.unlink()
        return True
    except FileNotFoundError:
        return False


@dataclass
class SyncResult:
    written: list[str] = field(default_factory=list)
    removed: list[str] = field(default_factory=list)
