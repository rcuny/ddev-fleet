"""Fleet-owned Caddy dashboard admin-auth: hash, write, validate, reload —
all rotatable WITHOUT running Ansible.

Design (see ansible/roles/caddy/tasks/main.yml + templates/Caddyfile.j2):
the `{{ fleet_domain }}` site's `basic_auth` block does not inline a
password hash. Instead it `import`s a single, literal (non-glob) snippet
file, `/etc/caddy/fleet/admin-auth.conf`, owned by the `fleet` user
(group `caddy`, mode 0640). Ansible seeds that file ONCE, with the
distribution-default credentials, and never touches it again if it already
exists — so a rotated password survives a `site.yml` re-run. Everything
*after* that first seed is owned by this module: hash a new password with
`caddy hash-password`, write the snippet atomically, validate the resulting
Caddyfile, then reload Caddy via a narrow sudoers grant
(`fleet ALL=(root) NOPASSWD: /usr/bin/systemctl reload caddy`).

Caddy import-error note (verified against the Caddy docs, 2026-07-16): a
*glob* pattern that matches zero files is NOT an error — Caddy silently
does nothing. A *literal* file path that does not exist IS an error at
adapt/validate time. We deliberately import a literal path (not a glob) —
so the only zero-file risk is "the snippet was never seeded", which
Ansible's seed task (main.yml) guarantees against on every provisioning
run.

Never log or print `bcrypt_hash` or a plaintext password from this module
— the one deliberate exception (printing a freshly rotated plaintext
password once) lives in the CLI layer (cli.py), not here.

Per-instance auth (added 2026-07-16, see `enable_instance_auth`/
`disable_instance_auth` below): each deployed instance that opts in gets its
OWN snippet, `{{ fleet_caddy_snippet_dir }}/instances/<instance-id>.conf`,
imported by the `*.{{ fleet_domain }}` site in Caddyfile.j2 via a GLOB
(`import .../instances/*.conf`) — deliberately NOT a literal path like the
admin-auth import above. A glob matching zero files (e.g. no instances have
auth enabled yet, or the dir is empty right after provisioning) is a
verified silent no-op in Caddy; only a *literal* missing path is a hard
validate/reload error. Since the set of instances with auth enabled is
dynamic (grows/shrinks as instances deploy/destroy), a literal import here
would require Ansible to keep re-rendering the Caddyfile on every
deploy/destroy — the whole point of the fleet-owned-snippet design is to
avoid that. Verified directly with `caddy validate` (v2.8.4, 2026-07-16):
both an empty `instances/` dir and a missing `instances/` dir adapt cleanly
with only a `No files matching import glob pattern` warning.

Each snippet defines its own named matcher, `@auth-<instance-id>`, scoped
with a `host` matcher to that instance's FQDN — verified that a matcher name
containing the instance-id separator `--` (e.g. `@auth-oak--slacktest`)
parses without error; Caddyfile matcher-name tokens accept any bareword of
letters/digits/hyphens, there is no hyphen-doubling restriction. The
`auth-` prefix is still kept (not just the raw instance id) so the matcher
name can never begin with a digit, even though `fleet.core.naming` already
guarantees instance ids never contain `--` except as the project/label
separator.
"""

import os
import tempfile
from pathlib import Path

from fleet.core.errors import CaddyAuthError
from fleet.core.runner import run_streamed

DEFAULT_ADMIN_USERNAME = "admin"
DEFAULT_SNIPPET_PATH = Path("/etc/caddy/fleet/admin-auth.conf")
DEFAULT_CADDYFILE_PATH = Path("/etc/caddy/Caddyfile")

DEFAULT_INSTANCE_USERNAME = "fleet"
DEFAULT_INSTANCE_PASSWORD = "fleet"
DEFAULT_INSTANCE_SNIPPET_DIR = Path("/etc/caddy/fleet/instances")


def hash_password(password: str, *, runner=run_streamed) -> str:
    """Hash `password` to a bcrypt string via `caddy hash-password`.

    Shells out rather than adding a bcrypt dependency: `caddy` is already
    installed on every host that runs this code (it's the reverse proxy in
    front of the daemon), `caddy hash-password` is already the documented
    method (docs/runbook-server-rollout.md §3), and pyproject.toml carries
    no bcrypt/passlib dependency today — adding one just to duplicate a
    hash format `caddy` already implements natively would be pure
    redundancy. Raises CaddyAuthError if the command fails or is missing.
    """
    result = runner(["caddy", "hash-password", "--plaintext", password], echo=False)
    if result.returncode != 0:
        raise CaddyAuthError(
            "'caddy hash-password' failed (exit code "
            f"{result.returncode}) — is caddy installed and on PATH?"
        )
    for line in result.lines:
        stripped = line.strip()
        if stripped:
            return stripped
    raise CaddyAuthError("'caddy hash-password' produced no output")


def _atomic_write(path: Path, content: str, *, prefix: str, mode: int = 0o640) -> None:
    """Shared primitive behind every fleet-owned Caddy snippet write (both
    the single admin-auth snippet and per-instance auth snippets).

    Writes to a temp file in the same directory, then `os.replace`s it into
    place — `os.replace` is an atomic rename on POSIX, so a crash mid-write
    can never leave Caddy importing a truncated/corrupt snippet (which
    would otherwise fail `caddy validate`/reload and take that snippet's
    auth down with it).
    """
    path.parent.mkdir(parents=True, exist_ok=True)

    fd, tmp_name = tempfile.mkstemp(dir=str(path.parent), prefix=prefix, suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write(content)
        os.chmod(tmp_name, mode)
        os.replace(tmp_name, path)
    except BaseException:
        try:
            os.unlink(tmp_name)
        except OSError:
            pass
        raise


def write_admin_auth_snippet(
    username: str,
    bcrypt_hash: str,
    *,
    snippet_path: Path = DEFAULT_SNIPPET_PATH,
) -> None:
    """Atomically write the admin-auth snippet the Caddyfile imports."""
    _atomic_write(snippet_path, f"{username} {bcrypt_hash}\n", prefix=".admin-auth-")


def instance_matcher_name(instance_id: str) -> str:
    """Compose the Caddy named-matcher token (without the leading `@`) for
    `instance_id`. See the module docstring for the verification note on
    why a `--`-containing name is safe to use unmodified here."""
    return f"auth-{instance_id}"


def instance_snippet_path(
    instance_id: str, *, snippet_dir: Path = DEFAULT_INSTANCE_SNIPPET_DIR
) -> Path:
    return snippet_dir / f"{instance_id}.conf"


def write_instance_auth_snippet(
    instance_id: str,
    fqdn: str,
    username: str,
    bcrypt_hash: str,
    *,
    snippet_dir: Path = DEFAULT_INSTANCE_SNIPPET_DIR,
) -> Path:
    """Atomically write `instance_id`'s own auth snippet: a named matcher
    scoped to `fqdn` via `host`, plus a `basic_auth` block guarded by that
    matcher. Returns the path written. Imported by the `*.{{ fleet_domain }}`
    site in Caddyfile.j2 via a glob — see the module docstring."""
    snippet_path = instance_snippet_path(instance_id, snippet_dir=snippet_dir)
    matcher = instance_matcher_name(instance_id)
    content = (
        f"@{matcher} host {fqdn}\n"
        f"basic_auth @{matcher} {{\n"
        f"    {username} {bcrypt_hash}\n"
        f"}}\n"
    )
    _atomic_write(snippet_path, content, prefix=f".{instance_id}-auth-")
    return snippet_path


def remove_instance_auth_snippet(
    instance_id: str, *, snippet_dir: Path = DEFAULT_INSTANCE_SNIPPET_DIR
) -> bool:
    """Remove `instance_id`'s auth snippet if present. Returns True if a
    file was actually removed, False if there was nothing to remove — lets
    callers (e.g. `disable_instance_auth`) skip a pointless validate/reload
    when auth was never enabled for this instance in the first place."""
    snippet_path = instance_snippet_path(instance_id, snippet_dir=snippet_dir)
    try:
        snippet_path.unlink()
        return True
    except FileNotFoundError:
        return False


def validate_caddyfile(
    *, caddyfile_path: Path = DEFAULT_CADDYFILE_PATH, runner=run_streamed
) -> None:
    """Run `caddy validate` against the live Caddyfile before we ever
    attempt a reload. This is the pre-flight check that keeps a bad write
    (or any other drift in /etc/caddy) from being pushed live — if it
    fails, the snippet is already on disk with the new hash, but Caddy is
    NOT reloaded, so the currently-running process keeps serving the OLD
    credentials until the operator fixes the problem. Raises
    CaddyAuthError with the validator's own output on failure.
    """
    result = runner(
        ["caddy", "validate", "--config", str(caddyfile_path), "--adapter", "caddyfile"],
        echo=False,
    )
    if result.returncode != 0:
        detail = "\n".join(result.lines)
        raise CaddyAuthError(
            "caddy validate failed after writing the admin-auth snippet — Caddy was "
            "NOT reloaded, so the dashboard's current credentials are unchanged. "
            f"Fix the Caddyfile/snippet and retry:\n{detail}"
        )


def reload_caddy(*, runner=run_streamed) -> None:
    """Reload Caddy via the narrow sudoers grant installed by the caddy
    Ansible role (`fleet ALL=(root) NOPASSWD: /usr/bin/systemctl reload
    caddy` — nothing broader). Raises CaddyAuthError if the reload command
    itself fails; at that point the snippet is written but not yet live —
    the operator should check `journalctl -u caddy` and reload by hand.
    """
    result = runner(["sudo", "systemctl", "reload", "caddy"], echo=False)
    if result.returncode != 0:
        detail = "\n".join(result.lines)
        raise CaddyAuthError(
            "the admin-auth snippet was written and validated, but "
            "'sudo systemctl reload caddy' failed, so the new password may not be "
            f"live yet. Check 'journalctl -u caddy' and reload manually:\n{detail}"
        )


def rotate(
    username: str,
    password: str,
    *,
    snippet_path: Path = DEFAULT_SNIPPET_PATH,
    caddyfile_path: Path = DEFAULT_CADDYFILE_PATH,
    runner=run_streamed,
) -> None:
    """Full rotation: hash `password`, write the snippet atomically,
    validate the Caddyfile, then reload Caddy. Never leaves the system in
    an undetectable half-applied state — if validation or reload fails, the
    raised CaddyAuthError says explicitly whether the running Caddy process
    still has the OLD credentials (it does, in both failure cases here)."""
    bcrypt_hash = hash_password(password, runner=runner)
    write_admin_auth_snippet(username, bcrypt_hash, snippet_path=snippet_path)
    validate_caddyfile(caddyfile_path=caddyfile_path, runner=runner)
    reload_caddy(runner=runner)


def enable_instance_auth(
    instance_id: str,
    fqdn: str,
    password: str,
    *,
    username: str = DEFAULT_INSTANCE_USERNAME,
    snippet_dir: Path = DEFAULT_INSTANCE_SNIPPET_DIR,
    caddyfile_path: Path = DEFAULT_CADDYFILE_PATH,
    runner=run_streamed,
) -> None:
    """Full per-instance enable pipeline: hash `password`, write
    `instance_id`'s snippet atomically, validate the Caddyfile, then reload
    Caddy. Called by `fleet.core.instances.deploy()`. Raises CaddyAuthError
    (a FleetError) on any failure — same never-half-applied-undetectably
    contract as `rotate()`: if validation or reload fails, the snippet is
    already on disk but Caddy has NOT been reloaded, so it is not yet
    protecting anything live."""
    bcrypt_hash = hash_password(password, runner=runner)
    write_instance_auth_snippet(instance_id, fqdn, username, bcrypt_hash, snippet_dir=snippet_dir)
    validate_caddyfile(caddyfile_path=caddyfile_path, runner=runner)
    reload_caddy(runner=runner)


def disable_instance_auth(
    instance_id: str,
    *,
    snippet_dir: Path = DEFAULT_INSTANCE_SNIPPET_DIR,
    caddyfile_path: Path = DEFAULT_CADDYFILE_PATH,
    runner=run_streamed,
) -> None:
    """Remove `instance_id`'s auth snippet (if any), then validate + reload
    Caddy so the removal takes effect immediately. Called by both
    `fleet.core.instances.deploy()` (when auth is explicitly disabled for a
    deploy) and `destroy()` (so a destroyed instance never leaves a stale
    matcher behind — re-deploying the same id later would otherwise race a
    leftover snippet). A no-op (no validate/reload) when there was nothing
    to remove, so destroying an instance that never had auth enabled doesn't
    trigger a pointless Caddy reload."""
    removed = remove_instance_auth_snippet(instance_id, snippet_dir=snippet_dir)
    if not removed:
        return
    validate_caddyfile(caddyfile_path=caddyfile_path, runner=runner)
    reload_caddy(runner=runner)
