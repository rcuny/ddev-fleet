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

import logging
from dataclasses import dataclass, field
from pathlib import Path

from fleet.core import caddyauth
from fleet.core.errors import CaddyAuthError, CaddyPortsError, FleetError
from fleet.core.naming import validate_part
from fleet.core.registry import PortProfile, Registry
from fleet.core.runner import run_streamed

logger = logging.getLogger(__name__)

DEFAULT_PORTS_SNIPPET_DIR = Path("/etc/caddy/fleet/ports")


def port_snippet_path(port_name: str, *, snippet_dir: Path = DEFAULT_PORTS_SNIPPET_DIR) -> Path:
    """Raises ``ValidationError`` (via ``naming.validate_part``) for any
    name outside ``^[a-z0-9]([a-z0-9-]*[a-z0-9])?$``. `Registry` already
    runs every `fleet.ports` key through this same check before it ever
    reaches here, but the guard is repeated at this layer too — defence in
    depth against a future caller that hands this function a raw string
    (e.g. `../../etc/evil`), which would otherwise be an unguarded
    path-traversal primitive."""
    validate_part(port_name)
    return snippet_dir / f"{port_name}.conf"


def render_port_snippet(domain: str, profile: PortProfile) -> str:
    """Pure, no I/O. Structurally identical to today's static Typesense
    site block, generalized to any named port.

    The `tls { on_demand }` block is rendered in MULTI-LINE form, matching
    the static block it replaces (`ansible/roles/caddy/templates/
    Caddyfile.j2`). The single-line `tls { on_demand }` form is INVALID —
    proven against a real `caddy validate` (v2.11) on the live server,
    2026-07-25: `Unexpected next token after '{' on same line`. Do not
    "simplify" this back to one line."""
    return (
        f"*.{domain}:{profile.public} {{\n"
        f"    reverse_proxy 127.0.0.1:{profile.router}\n"
        f"    tls {{\n"
        f"        on_demand\n"
        f"    }}\n"
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
    reload window when several ports change together.

    Rollback contract: the prior on-disk content of every snippet this
    call touches (write OR remove) is snapshotted before any mutation.
    If `caddy validate` fails — either returns non-zero or raises (e.g.
    the `caddy` binary is missing, see `runner()`'s wrapping below) — every
    touched snippet is restored EXACTLY to its prior state (created →
    deleted, modified → previous content, deleted → restored) before
    CaddyPortsError is raised. This matters because the running Caddy
    process is unaffected by a bad write (it keeps serving its in-memory
    config), so leaving an invalid snippet on disk looks harmless — until
    an out-of-band `systemctl restart caddy` (reboot, package upgrade,
    unattended-upgrades) re-reads the Caddyfile from disk, hits the
    invalid snippet, and fails to start, taking every instance's public
    URL down. Reload failure does NOT roll back — validate already
    passed, so the on-disk config is known-good, matching caddyauth's
    existing contract (files are written but Caddy is not reloaded).

    Both `runner()` call sites (validate, reload) wrap a bare FleetError
    (raised by `run_streamed` when the `caddy` binary is missing/Popen
    fails) in CaddyPortsError, so a caller doing `except CaddyPortsError`
    to detect "ports out of sync" cannot silently miss that case.
    """
    wanted = {p.name: p for p in registry.all_port_profiles()}
    # Same guard as every fleet-owned snippet write: a provisioned dir under
    # /etc/caddy must never be recreated on the fly (setgid would be lost and
    # Caddy could no longer read what we write) — see
    # caddyauth.ensure_snippet_dir.
    try:
        caddyauth.ensure_snippet_dir(snippet_dir)
    except CaddyAuthError as exc:
        # Re-raise in THIS module's error type so callers keep their single
        # `except CaddyPortsError` contract (instances.deploy/destroy).
        raise CaddyPortsError(exc.message) from exc
    existing_names = {p.stem for p in snippet_dir.glob("*.conf")}

    written: list[str] = []
    removed: list[str] = []
    prior_snapshot: dict[str, str | None] = {}

    def _snapshot(name: str, path: Path) -> None:
        if name not in prior_snapshot:
            prior_snapshot[name] = path.read_text(encoding="utf-8") if path.exists() else None

    def _rollback() -> None:
        for name, prior_content in prior_snapshot.items():
            path = port_snippet_path(name, snippet_dir=snippet_dir)
            if prior_content is None:
                try:
                    path.unlink()
                except FileNotFoundError:
                    pass
            else:
                caddyauth._atomic_write(path, prior_content, prefix=f".{name}-port-rollback-")

    def _safe_rollback(primary_detail: str) -> None:
        """Run `_rollback()`; if restoring state itself raises (disk full,
        permission denied), that secondary failure must NOT destroy the
        original failure that triggered the rollback attempt (CRITICAL 2) —
        both are named in one CaddyPortsError, chained from the rollback
        exception, since at that point the on-disk snippet directory may be
        inconsistent and a human must inspect it before Caddy is next
        restarted."""
        try:
            _rollback()
        except Exception as rollback_exc:
            raise CaddyPortsError(
                "port snippet sync failed AND the automatic rollback also "
                "failed — the on-disk snippet directory may now be "
                "inconsistent and must be inspected by hand before Caddy is "
                f"next restarted (do not restart it in the meantime).\n"
                f"original failure: {primary_detail}\n"
                f"rollback failure: {rollback_exc}"
            ) from rollback_exc

    for name, profile in wanted.items():
        path = port_snippet_path(name, snippet_dir=snippet_dir)
        new_content = render_port_snippet(registry.domain, profile)
        if path.exists() and path.read_text(encoding="utf-8") == new_content:
            continue
        _snapshot(name, path)
        try:
            write_port_snippet(registry.domain, profile, snippet_dir=snippet_dir)
        except Exception as exc:
            _safe_rollback(f"failed to write port snippet {name!r}: {exc}")
            raise CaddyPortsError(
                f"failed to write port snippet {name!r} mid-batch — the "
                "snippet changes made so far in this sync() call were "
                f"rolled back and Caddy was NOT reloaded: {exc}"
            ) from exc
        written.append(name)

    for name in existing_names - wanted.keys():
        path = port_snippet_path(name, snippet_dir=snippet_dir)
        _snapshot(name, path)
        try:
            actually_removed = remove_port_snippet(name, snippet_dir=snippet_dir)
        except Exception as exc:
            _safe_rollback(f"failed to remove port snippet {name!r}: {exc}")
            raise CaddyPortsError(
                f"failed to remove port snippet {name!r} mid-batch — the "
                "snippet changes made so far in this sync() call were "
                f"rolled back and Caddy was NOT reloaded: {exc}"
            ) from exc
        if actually_removed:
            removed.append(name)
            logger.warning(
                "sync: removed port snippet %s — %r is no longer referenced "
                "by any project in the registry",
                path,
                name,
            )

    if not written and not removed:
        return SyncResult(written=[], removed=[])

    try:
        validate_result = runner(
            ["caddy", "validate", "--config", str(caddyfile_path), "--adapter", "caddyfile"],
            echo=False,
        )
    except FleetError as exc:
        _safe_rollback(f"failed to run 'caddy validate': {exc.message}")
        raise CaddyPortsError(
            "failed to run 'caddy validate' after writing port snippets — "
            "the snippet changes were rolled back and Caddy was NOT "
            f"reloaded. Is 'caddy' installed and on PATH? ({exc.message})"
        ) from exc

    if validate_result.returncode != 0:
        detail = "\n".join(validate_result.lines)
        _safe_rollback(f"caddy validate failed: {detail}")
        raise CaddyPortsError(
            "caddy validate failed after writing port snippets — the "
            "snippet changes were rolled back and Caddy was NOT reloaded, "
            "so the running config is unchanged. Fix the Caddyfile/snippet "
            f"and retry:\n{detail}"
        )

    try:
        reload_result = runner(["caddy", "reload", "--config", str(caddyfile_path)], echo=False)
    except FleetError as exc:
        raise CaddyPortsError(
            "failed to run 'caddy reload' after writing and validating port "
            "snippets — the new/removed ports may not be live yet. Check "
            f"'journalctl -u caddy' and reload manually. ({exc.message})"
        ) from exc

    if reload_result.returncode != 0:
        detail = "\n".join(reload_result.lines)
        raise CaddyPortsError(
            "port snippets were written and validated, but 'caddy reload' "
            "failed, so the new ports may not be live yet. Check "
            f"'journalctl -u caddy' and reload manually:\n{detail}"
        )

    return SyncResult(written=sorted(written), removed=sorted(removed))
