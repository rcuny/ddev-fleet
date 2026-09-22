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
Caddyfile, then reload Caddy via `caddy reload` — which talks to the local
Caddy admin API (127.0.0.1:2019, unauthenticated by default) and needs no
privilege escalation at all (no sudo, no setuid), so it works unchanged
under `fleet.service`'s `NoNewPrivileges=yes` sandbox.

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
import stat
import tempfile
from collections.abc import Sequence
from pathlib import Path

from fleet.core.errors import CaddyAuthError, ValidationError
from fleet.core.runner import run_streamed

DEFAULT_ADMIN_USERNAME = "admin"
DEFAULT_SNIPPET_PATH = Path("/etc/caddy/fleet/admin-auth.conf")
DEFAULT_CADDYFILE_PATH = Path("/etc/caddy/Caddyfile")

# Per-instance credentials are SYMMETRIC: the one "auth password" an
# operator types is used as both the basic-auth username and password
# (e.g. `fern` -> `fern`/`fern`). It is a cheap privacy layer for handing
# an instance URL to a client, not a security boundary.
DEFAULT_INSTANCE_PASSWORD = "fleet"
DEFAULT_INSTANCE_SNIPPET_DIR = Path("/etc/caddy/fleet/instances")

# Snippet directories under this root are PROVISIONED (Ansible's `caddy`
# role), never created on the fly — see ensure_snippet_dir(). Tests and
# local runs write to tmp dirs outside it and keep the old create-on-demand
# behaviour.
MANAGED_SNIPPET_ROOT = Path("/etc/caddy")


def hash_password(password: str, *, runner=run_streamed) -> str:
    """Hash `password` to a bcrypt string via `caddy hash-password`.

    Shells out rather than adding a bcrypt dependency: `caddy` is already
    installed on every host that runs this code (it's the reverse proxy in
    front of the daemon), `caddy hash-password` is already the documented
    method (docs/operations.md's admin-password-rotation section), and
    pyproject.toml carries
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


def ensure_snippet_dir(directory: Path) -> None:
    """Guard a fleet-owned Caddy snippet directory before writing into it.

    Under MANAGED_SNIPPET_ROOT the directory must already exist AND carry the
    **setgid** bit; anywhere else (tests, local runs) it is simply created.

    Why this is a hard error rather than a `mkdir` (learned on ddev2, where
    it took Caddy down for a week, 2026-09-14 → 2026-09-21): fleet writes
    these snippets, `caddy` reads them, and the `fleet` user is NOT in the
    `caddy` group. The ONLY thing making that handoff work is setgid on the
    directory — a new file inherits the dir's group (`caddy`), so mode 0640
    is readable by Caddy. A plain `mkdir` creates the dir WITHOUT setgid
    (Python applies `mode & ~umask`), every snippet written into it comes
    out `fleet:fleet 0640`, and Caddy then dies at startup with
    `Could not import …: permission denied`.

    Worse, that failure is invisible until Caddy restarts: `caddy reload` on
    a running process keeps serving the old config, so the box looks healthy
    and only fails to come back after a reboot. Hence: refuse up front, with
    the fix in the message.

    The unprivileged `fleet` user CANNOT repair this itself — Linux silently
    drops S_ISGID when a non-root caller chmods a directory whose group it
    does not belong to — so self-healing is not an option; provisioning owns
    these directories."""
    try:
        managed = directory.resolve().is_relative_to(MANAGED_SNIPPET_ROOT.resolve())
    except OSError:
        managed = False

    if not managed:
        directory.mkdir(parents=True, exist_ok=True)
        return

    if not directory.is_dir():
        raise CaddyAuthError(
            f"Caddy snippet directory {directory} does not exist. It is seeded by the "
            "'caddy' Ansible role and must NOT be created on the fly: a plain mkdir "
            "loses the setgid bit, and every snippet written afterwards is unreadable "
            "by Caddy (which then fails to start). Recreate it explicitly:\n"
            f"  sudo install -d -o fleet -g caddy -m 2750 {directory}"
        )

    if not directory.stat().st_mode & stat.S_ISGID:
        raise CaddyAuthError(
            f"Caddy snippet directory {directory} has lost its setgid bit, so snippets "
            "written here would be group-owned by 'fleet' instead of 'caddy' and Caddy "
            "could not read them (it would fail on its next restart). Refusing to write. "
            "Fix as root:\n"
            f"  sudo chgrp -R caddy {MANAGED_SNIPPET_ROOT / 'fleet'}\n"
            f"  sudo find {MANAGED_SNIPPET_ROOT / 'fleet'} -type d -exec chmod 2750 {{}} +\n"
            f"  sudo find {MANAGED_SNIPPET_ROOT / 'fleet'} -type f -exec chmod 0640 {{}} +"
        )


def _atomic_write(path: Path, content: str, *, prefix: str, mode: int = 0o640) -> None:
    """Shared primitive behind every fleet-owned Caddy snippet write (both
    the single admin-auth snippet and per-instance auth snippets).

    Writes to a temp file in the same directory, then `os.replace`s it into
    place — `os.replace` is an atomic rename on POSIX, so a crash mid-write
    can never leave Caddy importing a truncated/corrupt snippet (which
    would otherwise fail `caddy validate`/reload and take that snippet's
    auth down with it).
    """
    ensure_snippet_dir(path.parent)

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
    bypass_cidrs: Sequence[str] = (),
    alias_fqdns: Sequence[str] = (),
    snippet_dir: Path = DEFAULT_INSTANCE_SNIPPET_DIR,
) -> Path:
    """Atomically write `instance_id`'s own auth snippet: a named matcher
    scoped to `fqdn` (plus any `alias_fqdns`) via `host`, plus a
    `basic_auth` block guarded by that matcher. Returns the path written.
    Imported by the `*.{{ fleet_domain }}` site in Caddyfile.j2 via a glob —
    see the module docstring.

    `alias_fqdns` (from `core.instances.alias_fqdns()`, the instance's
    Domain-Access alias hosts) are listed alongside `fqdn` in the SAME
    `host` matcher — Caddy's `host` matcher accepts multiple space-separated
    hosts and matches if any one of them matches. Without this, an alias
    host would be a different `host` than the one the matcher guards and
    would bypass basic auth entirely — that was a real gap the alias
    feature would otherwise have opened.

    `bypass_cidrs` (from `fleet.auth_bypass_cidrs`, see
    `Registry.auth_bypass_cidrs`) narrows the matcher with `not remote_ip
    <ranges>`, so visitors from those networks are never prompted. The
    matcher is what guards `basic_auth`, so a non-match simply means "no
    auth for this request" — traffic is never blocked by this snippet, and
    everyone outside the list still gets the prompt (the implicit default
    IS the prompt; there is no deny entry). Emitted as a multi-line matcher
    block only when there is a bypass list, so an empty list keeps the
    original one-line `@m host <fqdn> [alias ...]` form.

    Caddy's `remote_ip` matches the DIRECT peer address, deliberately not
    `X-Forwarded-For` (which would need the `forwarded` keyword and a
    trusted-proxy config). Caddy is the edge here — it terminates TLS for
    `*.{{ fleet_domain }}` straight from the client — so the direct peer IS
    the visitor. If a CDN/proxy is ever put in front of Caddy, every request
    will appear to come from that proxy and this list must be revisited."""
    snippet_path = instance_snippet_path(instance_id, snippet_dir=snippet_dir)
    matcher = instance_matcher_name(instance_id)
    hosts = " ".join((fqdn, *alias_fqdns))
    if bypass_cidrs:
        header = (
            f"@{matcher} {{\n"
            f"    host {hosts}\n"
            f"    not remote_ip {' '.join(bypass_cidrs)}\n"
            f"}}\n"
        )
    else:
        header = f"@{matcher} host {hosts}\n"
    content = header + f"basic_auth @{matcher} {{\n    {username} {bcrypt_hash}\n}}\n"
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


def reload_caddy(*, caddyfile_path: Path = DEFAULT_CADDYFILE_PATH, runner=run_streamed) -> None:
    """Reload Caddy via `caddy reload`, which talks to the local Caddy admin
    API (127.0.0.1:2019, unauthenticated by default) to adapt-and-apply the
    Caddyfile in place. Requires NO privilege escalation — no sudo, no
    setuid — so it works unchanged under the `fleet.service` sandbox's
    `NoNewPrivileges=yes`. Raises CaddyAuthError if the reload command
    itself fails; at that point the snippet is written but not yet live —
    the operator should check `journalctl -u caddy` (is the admin API up on
    127.0.0.1:2019?) and reload by hand.
    """
    result = runner(["caddy", "reload", "--config", str(caddyfile_path)], echo=False)
    if result.returncode != 0:
        detail = "\n".join(result.lines)
        raise CaddyAuthError(
            "the admin-auth snippet was written and validated, but "
            "'caddy reload' failed, so the new password may not be live yet. This "
            "usually means the Caddy admin API (127.0.0.1:2019) is unreachable or "
            "'caddy' is not on PATH. Check 'journalctl -u caddy' and reload "
            f"manually:\n{detail}"
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
    reload_caddy(caddyfile_path=caddyfile_path, runner=runner)


def validate_instance_credential(credential: str) -> None:
    """Refuse a per-instance credential that can't be written as a bare
    Caddyfile token. Because the credential doubles as the basic-auth
    USERNAME (see DEFAULT_INSTANCE_PASSWORD), it lands unhashed in the
    snippet, where whitespace, quotes, braces or a leading `#` would break
    (or silently change) the parsed config. Called up front by deploy() /
    multi_deploy() so a bad value is rejected before anything is torn down."""
    if not credential:
        raise ValidationError("auth password must not be empty")
    if any(c.isspace() or c in '"`{}\\' for c in credential) or credential.startswith("#"):
        raise ValidationError(
            f"auth password {credential!r} is also used as the username, so it must be a "
            "single word: no spaces, quotes, braces, backslashes, or leading '#'"
        )


def enable_instance_auth(
    instance_id: str,
    fqdn: str,
    password: str,
    *,
    username: str | None = None,
    bypass_cidrs: Sequence[str] = (),
    alias_fqdns: Sequence[str] = (),
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
    protecting anything live.

    `username` defaults to `password` — per-instance credentials are
    symmetric (see DEFAULT_INSTANCE_PASSWORD). `alias_fqdns` — the
    instance's Domain-Access alias hosts (`core.instances.alias_fqdns()`) —
    are folded into the SAME matcher as `fqdn`; see
    `write_instance_auth_snippet` for why that matters."""
    if username is None:
        username = password
    bcrypt_hash = hash_password(password, runner=runner)
    write_instance_auth_snippet(
        instance_id,
        fqdn,
        username,
        bcrypt_hash,
        bypass_cidrs=bypass_cidrs,
        alias_fqdns=alias_fqdns,
        snippet_dir=snippet_dir,
    )
    validate_caddyfile(caddyfile_path=caddyfile_path, runner=runner)
    reload_caddy(caddyfile_path=caddyfile_path, runner=runner)


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
    reload_caddy(caddyfile_path=caddyfile_path, runner=runner)
