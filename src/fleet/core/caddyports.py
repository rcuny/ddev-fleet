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
from fleet.core.errors import CaddyPortsError
from fleet.core.registry import PortProfile, Registry
from fleet.core.runner import run_streamed

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


def sync(
    registry: Registry,
    *,
    snippet_dir: Path = DEFAULT_PORTS_SNIPPET_DIR,
    caddyfile_path: Path = caddyauth.DEFAULT_CADDYFILE_PATH,
    runner=run_streamed,
) -> SyncResult:
    """Reconcile `snippet_dir` to `registry.all_port_profiles()`: write/
    update a snippet for every port with ≥1 subscriber, remove any
    snippet no longer referenced. ONE validate+reload for the whole
    batch (not per port) — avoids N reloads and a confusing partial-
    reload window when several ports change together. Raises
    CaddyPortsError on validate/reload failure, following caddyauth's
    contract: files are written but Caddy is not reloaded, and the
    error says so explicitly."""
    wanted = {p.name: p for p in registry.all_port_profiles()}
    snippet_dir.mkdir(parents=True, exist_ok=True)
    existing_names = {p.stem for p in snippet_dir.glob("*.conf")}

    written: list[str] = []
    removed: list[str] = []

    for name, profile in wanted.items():
        path = port_snippet_path(name, snippet_dir=snippet_dir)
        new_content = render_port_snippet(registry.domain, profile)
        if path.exists() and path.read_text(encoding="utf-8") == new_content:
            continue
        write_port_snippet(registry.domain, profile, snippet_dir=snippet_dir)
        written.append(name)

    for name in existing_names - wanted.keys():
        if remove_port_snippet(name, snippet_dir=snippet_dir):
            removed.append(name)

    if not written and not removed:
        return SyncResult(written=[], removed=[])

    validate_result = runner(
        ["caddy", "validate", "--config", str(caddyfile_path), "--adapter", "caddyfile"],
        echo=False,
    )
    if validate_result.returncode != 0:
        detail = "\n".join(validate_result.lines)
        raise CaddyPortsError(
            "caddy validate failed after writing port snippets — Caddy was "
            "NOT reloaded, so the running config is unchanged. Fix the "
            f"Caddyfile/snippet and retry:\n{detail}"
        )

    reload_result = runner(["caddy", "reload", "--config", str(caddyfile_path)], echo=False)
    if reload_result.returncode != 0:
        detail = "\n".join(reload_result.lines)
        raise CaddyPortsError(
            "port snippets were written and validated, but 'caddy reload' "
            "failed, so the new ports may not be live yet. Check "
            f"'journalctl -u caddy' and reload manually:\n{detail}"
        )

    return SyncResult(written=sorted(written), removed=sorted(removed))
