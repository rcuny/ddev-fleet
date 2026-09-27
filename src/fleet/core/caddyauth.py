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

Two per-instance snippet writers share that one path per instance id:
`write_instance_auth_snippet` (basic mode, `basic_auth`) and
`write_instance_authelia_snippet` (Authelia mode, `forward_auth` +
Remote-Groups authorization) — `enable_instance_auth`'s `auth_mode` kwarg
picks which one runs. The two are never both written for the same server:
`auth_mode` is a server-wide setting (`Registry.auth_mode`), not a
per-instance choice.
"""

import os
import re
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


def ensure_snippet_dir(directory: Path, *, create: bool = True) -> None:
    """Guard a fleet-owned Caddy snippet directory before writing into it.

    Under MANAGED_SNIPPET_ROOT the directory must already exist AND carry the
    **setgid** bit; anywhere else (tests, local runs) it is simply created.

    `create=False` (used by `core/instances.py`'s pre-destroy preflight,
    which must never have side effects) skips the create-on-demand `mkdir`
    for an unmanaged directory, turning this into a pure read-only check —
    the managed-root branch below was already read-only (stat only, never
    mkdir), so `create=False` just makes the unmanaged branch match it.

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
        if create:
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
    alias_fqdns: Sequence[str] = (),
    snippet_dir: Path = DEFAULT_INSTANCE_SNIPPET_DIR,
) -> Path:
    """Atomically write `instance_id`'s own BASIC-AUTH snippet: a named
    matcher scoped to `fqdn` (plus any `alias_fqdns`) via `host`, plus a
    `basic_auth` block guarded by that matcher. Returns the path written.
    Imported by the `*.{{ fleet_domain }}` site in Caddyfile.j2 via a glob —
    see the module docstring. For Authelia mode, see
    `write_instance_authelia_snippet` instead — the two write the SAME
    path (one snippet per instance) but never both at once, since a given
    server only ever runs in one auth_mode.

    `alias_fqdns` (from `core.instances.alias_fqdns()`) are listed alongside
    `fqdn` in the SAME `host` matcher — Caddy's `host` matcher accepts
    multiple space-separated hosts and matches if any one matches. Without
    this, an alias host would bypass basic auth entirely."""
    snippet_path = instance_snippet_path(instance_id, snippet_dir=snippet_dir)
    matcher = instance_matcher_name(instance_id)
    hosts = " ".join((fqdn, *alias_fqdns))
    content = (
        f"@{matcher} host {hosts}\n" f"basic_auth @{matcher} {{\n    {username} {bcrypt_hash}\n}}\n"
    )
    _atomic_write(snippet_path, content, prefix=f".{instance_id}-auth-")
    return snippet_path


DEFAULT_AUTHELIA_ADDR = "127.0.0.1:9091"


def _groups_regex(project: str) -> str:
    """Compose the `header_regexp` pattern that matches `project` (or
    `admins`) as a WHOLE comma-delimited element of Authelia's
    `Remote-Groups` header, never a substring. `Remote-Groups` arrives with
    no space around commas (e.g. `demo,fern`), but the pattern still
    tolerates optional whitespace around each element defensively.
    `project` is `re.escape`d so a project name containing regex
    metacharacters (`.`, `-`, etc.) matches itself literally, never as a
    pattern fragment — e.g. project `my.proj-1` must not also match
    `myxproj-1` (a literal `.` would otherwise mean "any character")."""
    return rf"(^|,)\s*({re.escape(project)}|admins)\s*(,|$)"


def write_instance_authelia_snippet(
    instance_id: str,
    fqdn: str,
    project: str,
    *,
    alias_fqdns: Sequence[str] = (),
    snippet_dir: Path = DEFAULT_INSTANCE_SNIPPET_DIR,
    authelia_addr: str = DEFAULT_AUTHELIA_ADDR,
) -> Path:
    """Atomically write `instance_id`'s AUTHELIA-MODE snippet: strip any
    client-supplied `Remote-*` headers (anti-spoofing — Authelia's
    forward-auth response is the only legitimate source of these),
    `forward_auth` to Authelia's forward-auth endpoint, then require the
    visitor's `Remote-Groups` to contain `project` or `admins` — else 403.
    Writes to the SAME path `write_instance_auth_snippet` would (one
    snippet per instance id); the two are never both written for the same
    server, since `auth_mode` is server-wide.

    Syntax pinned by the local e2e verification
    (`.claude/user/tmp/authelia-e2e/findings.md` in the companion repo, not
    shipped here): Caddy does NOT execute directives in source order inside
    a plain site block — it reorders them by a fixed internal
    directive-precedence list, which sorts bare `request_header` directives
    to run AFTER `forward_auth`. That silently deletes the very
    `Remote-Groups` header `forward_auth`'s `copy_headers` just set,
    so the group matcher below would see an empty header on every request
    and reject every authenticated visitor, admins included. The fix,
    confirmed against a real Caddy v2.11.4 + Authelia v4.39 instance, is to
    wrap the whole header-strip + forward_auth + group-matcher sequence in a
    single `route @matcher { ... }` block — `route` disables the automatic
    reordering and runs its directives in the literal order written. The
    shared `reverse_proxy` for `*.{{ fleet_domain }}` (Caddyfile.j2) still
    applies afterwards for any request this route doesn't terminate with a
    403, exactly like the basic_auth snippet's `@protected`/`basic_auth`
    pairing already relies on.

    `Remote-Groups` arrives from Authelia as a comma-separated list with NO
    space (e.g. `demo,fern`) — the group-matcher regexp below matches
    `project` (or `admins`) as a whole comma-delimited element, not a
    substring, so `fern` does not also match a project literally named
    `ubuntu-fern-2`."""
    snippet_path = instance_snippet_path(instance_id, snippet_dir=snippet_dir)
    matcher = instance_matcher_name(instance_id)
    hosts = " ".join((fqdn, *alias_fqdns))
    group_pattern = _groups_regex(project)
    content = (
        f"@{matcher} host {hosts}\n"
        f"route @{matcher} {{\n"
        "    request_header -Remote-User\n"
        "    request_header -Remote-Groups\n"
        "    request_header -Remote-Name\n"
        "    request_header -Remote-Email\n"
        f"    forward_auth {authelia_addr} {{\n"
        "        uri /api/authz/forward-auth\n"
        "        copy_headers Remote-User Remote-Groups Remote-Name Remote-Email\n"
        "    }\n"
        f'    @denied not header_regexp Remote-Groups "{group_pattern}"\n'
        "    respond @denied 403\n"
        "}\n"
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
    auth_mode: str = "basic",
    project: str | None = None,
    username: str | None = None,
    alias_fqdns: Sequence[str] = (),
    snippet_dir: Path = DEFAULT_INSTANCE_SNIPPET_DIR,
    caddyfile_path: Path = DEFAULT_CADDYFILE_PATH,
    runner=run_streamed,
) -> None:
    """Full per-instance enable pipeline, mode-aware. Basic mode (default,
    unchanged): hash `password`, write the basic_auth snippet, validate,
    reload. Authelia mode (`auth_mode="authelia"`): write the forward_auth
    snippet naming `project` as the required group — no hashing, no
    per-instance credential at all (Authelia already authenticated the
    visitor; this only decides authorization). Called by
    `fleet.core.instances.deploy()`. Raises CaddyAuthError on any failure
    — same never-half-applied-undetectably contract as `rotate()`.

    `username` defaults to `password` — per-instance credentials are
    symmetric (see DEFAULT_INSTANCE_PASSWORD); only meaningful in basic
    mode. `alias_fqdns` — the instance's Domain-Access alias hosts
    (`core.instances.alias_fqdns()`) — are folded into the SAME matcher as
    `fqdn` in both modes; see `write_instance_auth_snippet` for why that
    matters."""
    if auth_mode == "authelia":
        if not project:
            raise CaddyAuthError(
                "enable_instance_auth(auth_mode='authelia') requires 'project' to "
                "compose the required Remote-Groups membership"
            )
        write_instance_authelia_snippet(
            instance_id, fqdn, project, alias_fqdns=alias_fqdns, snippet_dir=snippet_dir
        )
    else:
        if username is None:
            username = password
        bcrypt_hash = hash_password(password, runner=runner)
        write_instance_auth_snippet(
            instance_id,
            fqdn,
            username,
            bcrypt_hash,
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
