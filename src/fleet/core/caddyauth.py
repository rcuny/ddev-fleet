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
"""

import os
import tempfile
from pathlib import Path

from fleet.core.errors import CaddyAuthError
from fleet.core.runner import run_streamed

DEFAULT_ADMIN_USERNAME = "admin"
DEFAULT_SNIPPET_PATH = Path("/etc/caddy/fleet/admin-auth.conf")
DEFAULT_CADDYFILE_PATH = Path("/etc/caddy/Caddyfile")


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


def write_admin_auth_snippet(
    username: str,
    bcrypt_hash: str,
    *,
    snippet_path: Path = DEFAULT_SNIPPET_PATH,
) -> None:
    """Atomically write the admin-auth snippet the Caddyfile imports.

    Writes to a temp file in the same directory, then `os.replace`s it into
    place — `os.replace` is an atomic rename on POSIX, so a crash mid-write
    can never leave Caddy importing a truncated/corrupt snippet (which
    would otherwise fail `caddy validate`/reload and take the dashboard's
    auth down with it).
    """
    snippet_path.parent.mkdir(parents=True, exist_ok=True)
    content = f"{username} {bcrypt_hash}\n"

    fd, tmp_name = tempfile.mkstemp(
        dir=str(snippet_path.parent), prefix=".admin-auth-", suffix=".tmp"
    )
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write(content)
        os.chmod(tmp_name, 0o640)
        os.replace(tmp_name, snippet_path)
    except BaseException:
        try:
            os.unlink(tmp_name)
        except OSError:
            pass
        raise


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
