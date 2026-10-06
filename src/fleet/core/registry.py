"""Load/validate/resolve the fleet.yml project→templates registry (spec §4).

The registry is declarative and read-only at runtime: `fleet.yml` is
authored/edited by hand (or by provisioning tooling), never mutated by the
fleet CLI/daemon. A project lists reusable deploy `templates` (post_deploy
commands, etc.); `branch` and the instance `label` are resolved per-deploy,
never stored in the registry.
"""

import ipaddress
import logging
import re
from dataclasses import dataclass, field
from pathlib import Path, PurePosixPath

from ruamel.yaml import YAML

from fleet.core.errors import RegistryError, ValidationError
from fleet.core.naming import instance_id, slugify, validate_part

logger = logging.getLogger(__name__)

_yaml = YAML()
_yaml.preserve_quotes = True
_yaml.width = 4096

_RESERVED_PORTS = frozenset({22, 80, 443, 8765})
_ROUTER_RESERVED_PORTS = frozenset({8080, 8443})
_MIN_PORT = 1
_MAX_PORT = 65535
_TTY_KEYS = ("tty1", "tty2")
_TTY_KEY_RE = re.compile(r"^tty\d+$")
# Keys allowed in the MAPPING form of a template's `post_deploy` (FLE-6):
# `exec` is the host-side, strict, deploy-aborting command list (what the
# legacy list form always was); `tty1`/`tty2` are typed once into the new
# window's panes by deploy() after `exec` succeeded.
_POST_DEPLOY_KEYS = ("exec", "tty1", "tty2")
_USER_NAME_RE = re.compile(r"^[a-z0-9._-]+$")
_VALID_AUTH_MODES = frozenset({"basic", "authelia"})


@dataclass
class ResolvedInstance:
    project: str
    template: str
    branch: str
    label: str
    post_deploy: list[str]
    instance_id: str
    # Defaults must trail every field above (dataclass field-order rule), so
    # these live after `instance_id` rather than immediately after
    # `post_deploy` despite the "place after post_deploy" framing upstream.
    tty1: list[str] = field(default_factory=list)
    tty2: list[str] = field(default_factory=list)
    # `drupal_env` from the template, or None when the template does not set
    # one — None means "leave the project's own .env alone".
    drupal_env: str | None = None


@dataclass(frozen=True)
class PortProfile:
    name: str
    public: int
    router: int


@dataclass(frozen=True)
class JiraHookRule:
    """One `projects.<p>.jira_hooks` entry: when a Jira issue moves INTO
    `on_status`, run `action` (v1: only "deploy") with `template`, on `branch`
    (None = the project's default branch). Consumed by `core/webhooks.py`."""

    on_status: str
    action: str
    template: str
    branch: str | None = None


_JIRA_HOOK_KEYS = frozenset({"on_status", "action", "template", "branch"})
_JIRA_HOOK_ACTIONS = frozenset({"deploy"})

_TYPESENSE_LEGACY_DEFAULT = PortProfile(name="typesense", public=9108, router=8108)


@dataclass(frozen=True)
class UserRecord:
    """One Authelia user, merged across every project that lists it —
    `groups` is every project key that declared this name in its `users:`
    (spec §4.2). Consumed by `fleet.core.authelia.render_users()`."""

    name: str
    password: str
    groups: tuple[str, ...]


def _load_host_domain(host_config_path: Path) -> str | None:
    """Read the per-host `domain` override from `host_config_path` (a
    `host.yml` — see `FleetPaths.host_config` / docs/configuration.md's
    "per-host domain" section), or `None` if the file doesn't exist or
    carries no `domain` key. `host.yml`'s schema is deliberately a small,
    open mapping (more host-level keys may be added later) — unknown keys
    are ignored here, not rejected.

    Raises RegistryError if the file exists but isn't a mapping, or if
    `domain` is present but not a bare hostname (non-empty string, no
    scheme, no slashes — it is composed straight into instance FQDNs and
    Caddy site blocks, so a URL-shaped value would silently break both)."""
    if not host_config_path.exists():
        return None
    with open(host_config_path, "r", encoding="utf-8") as fh:
        data = _yaml.load(fh)
    if data is None:
        return None
    if not isinstance(data, dict):
        raise RegistryError(f"{host_config_path}: host config must be a mapping")
    if "domain" not in data:
        return None
    value = data["domain"]
    if not isinstance(value, str) or not value.strip():
        raise RegistryError(f"{host_config_path}: 'domain' must be a non-empty string")
    if "://" in value or "/" in value:
        raise RegistryError(
            f"{host_config_path}: 'domain' must be a bare hostname — no scheme or "
            f"slashes — got {value!r}"
        )
    return value


def _load_host_auth_mode(host_config_path: Path) -> str:
    """Read the per-host `auth_mode` from `host_config_path` (`host.yml`) —
    see `_load_host_domain`'s docstring for the file's schema. Absent file,
    absent key, or a `None` value all mean `"basic"` (spec §2 D2 — "absent
    = basic"). Raises RegistryError for any other value."""
    if not host_config_path.exists():
        return "basic"
    with open(host_config_path, "r", encoding="utf-8") as fh:
        data = _yaml.load(fh)
    if data is None or not isinstance(data, dict) or "auth_mode" not in data:
        return "basic"
    value = data["auth_mode"]
    if value is None:
        return "basic"
    if value not in _VALID_AUTH_MODES:
        raise RegistryError(
            f"{host_config_path}: 'auth_mode' must be one of {sorted(_VALID_AUTH_MODES)}, "
            f"got {value!r}"
        )
    return str(value)


def load_host_auth_mode(host_config_path: Path) -> str:
    """Public wrapper around `_load_host_auth_mode` — reads ONLY `host.yml`'s
    `auth_mode`, without loading/validating `fleet.yml` at all. Lets
    break-glass commands that don't otherwise need the registry (`fleet
    set-admin-password`/`rotate-admin-password` in basic mode) determine
    the auth mode even when `fleet.yml` is missing or invalid — see
    `cli.py`'s mode-aware admin-password commands, which only call
    `load_registry()` (and thus require a valid `fleet.yml`) inside the
    Authelia branch, where `render_users` actually needs the registry's
    users."""
    return _load_host_auth_mode(host_config_path)


class Registry:
    def __init__(
        self,
        data,
        path: Path,
        *,
        host_domain: str | None = None,
        auth_mode: str = "basic",
    ) -> None:
        self._data = data
        self._path = path
        # The per-host `host.yml` domain override, already read and
        # validated by `load()` — `None` means no override, so `domain`
        # falls back to `fleet.domain` in fleet.yml as before.
        self._host_domain = host_domain
        self._auth_mode = auth_mode

    @classmethod
    def load(cls, path: Path, *, host_config_path: Path | None = None) -> "Registry":
        """Load and validate `fleet.yml` at `path`.

        `host_config_path`, when given, is checked for a per-host `domain`
        override (`host.yml` — see `_load_host_domain`): several fleet
        servers can then share ONE `fleet.yml` (via the shared config repo)
        while each keeps its own `fleet.domain`. When `host_config_path` is
        omitted (the default), behaviour is unchanged from before this
        existed — `fleet.domain` in `path` is the only source, and it is
        REQUIRED. Prefer `fleet.core.instances.load_registry(paths)` over
        calling this directly wherever a `FleetPaths` is already in hand —
        it's the one call site that can never forget to pass
        `host_config_path`.
        """
        if not path.exists():
            raise RegistryError(f"{path}: registry not found; run 'fleet init' first")
        with open(path, "r", encoding="utf-8") as fh:
            data = _yaml.load(fh)
        if data is None:
            raise RegistryError(f"{path}: empty or invalid registry file")

        host_domain = _load_host_domain(host_config_path) if host_config_path is not None else None
        auth_mode = (
            _load_host_auth_mode(host_config_path) if host_config_path is not None else "basic"
        )

        fleet_block = data.get("fleet") or {}
        fleet_yml_domain = fleet_block.get("domain")
        if host_domain is not None and fleet_yml_domain and str(fleet_yml_domain) != host_domain:
            logger.debug(
                "domain override: host.yml (%s) wins over fleet.yml's fleet.domain "
                "(%s) — this is expected when fleet.yml is shared across hosts",
                host_domain,
                fleet_yml_domain,
            )

        registry = cls(data, path, host_domain=host_domain, auth_mode=auth_mode)
        registry._validate(host_config_path=host_config_path)
        return registry

    def _validate_fleet_ports(self, fleet_block: dict) -> dict:
        """Validate `fleet.ports` (spec §3.1) and return the raw mapping
        `{name: {"public": int, "router": int}}` for Task 3's per-project
        `ports:` unknown-reference check. `{}` if `fleet.ports` is absent —
        a legacy-only registry (just `typesense: true`) stays valid."""
        fleet_ports = fleet_block.get("ports") or {}
        seen_public: dict[int, str] = {}
        seen_router: dict[int, str] = {}
        for name, entry in fleet_ports.items():
            path = f"fleet.ports.{name}"
            try:
                validate_part(name)
            except ValidationError as exc:
                raise RegistryError(f"{path}: {exc.message}") from exc

            if not isinstance(entry, dict) or set(entry.keys()) != {"public", "router"}:
                raise RegistryError(
                    f"{path}: must be a mapping with exactly the keys 'public' and 'router'"
                )
            public, router = entry["public"], entry["router"]
            for field_name, value in (("public", public), ("router", router)):
                if not isinstance(value, int) or isinstance(value, bool):
                    raise RegistryError(f"{path}.{field_name}: must be an int, got {value!r}")
                if not (_MIN_PORT <= value <= _MAX_PORT):
                    raise RegistryError(
                        f"{path}.{field_name}: {value} is out of range "
                        f"[{_MIN_PORT}, {_MAX_PORT}]"
                    )
                if value in _RESERVED_PORTS:
                    raise RegistryError(
                        f"{path}.{field_name}: {value} is reserved (22/80/443/8765)"
                    )
            if router in _ROUTER_RESERVED_PORTS:
                raise RegistryError(
                    f"{path}.router: {router} collides with the reserved ddev-router "
                    "ports (8080/8443)"
                )
            if public == router:
                raise RegistryError(f"{path}: public and router must differ (both {public})")
            if public in seen_public:
                raise RegistryError(
                    f"{path}.public: {public} is already used by fleet.ports.{seen_public[public]}"
                )
            seen_public[public] = name
            if router in seen_router:
                raise RegistryError(
                    f"{path}.router: {router} is already used by fleet.ports.{seen_router[router]}"
                )
            seen_router[router] = name
        return fleet_ports

    @staticmethod
    def _validate_jira_hooks(project_key: str, project_block: dict, templates: dict) -> None:
        """Validate `projects.<p>.jira_hooks`. Trigger config decides what gets
        deployed from an unauthenticated-looking HTTP call, so it is strict:
        unknown keys and duplicate statuses fail loudly at load time rather
        than silently misfiring on the first webhook."""
        if "jira_hooks" not in project_block:
            return
        base = f"projects.{project_key}.jira_hooks"
        hooks = project_block["jira_hooks"]
        if hooks is None:
            return
        if not isinstance(hooks, list):
            raise RegistryError(f"{base}: must be a list of rules")
        seen: set[str] = set()
        for i, rule in enumerate(hooks):
            where = f"{base}[{i}]"
            if not isinstance(rule, dict):
                raise RegistryError(f"{where}: must be a mapping, got {rule!r}")
            unknown = sorted(set(rule) - _JIRA_HOOK_KEYS)
            if unknown:
                raise RegistryError(
                    f"{where}: unknown key(s) {', '.join(map(str, unknown))} "
                    f"(allowed: {', '.join(sorted(_JIRA_HOOK_KEYS))})"
                )
            on_status = rule.get("on_status")
            if not isinstance(on_status, str) or not on_status.strip():
                raise RegistryError(f"{where}.on_status: must be a non-empty string")
            # Jira status names are matched case-insensitively (core/webhooks.py),
            # so two rules differing only in case would be ambiguous.
            folded = on_status.strip().casefold()
            if folded in seen:
                raise RegistryError(
                    f"{where}.on_status: duplicate status {on_status.strip()!r} "
                    "(compared case-insensitively)"
                )
            seen.add(folded)
            action = rule.get("action")
            if action not in _JIRA_HOOK_ACTIONS:
                raise RegistryError(
                    f"{where}.action: must be one of {sorted(_JIRA_HOOK_ACTIONS)}, got {action!r}"
                )
            template = rule.get("template")
            if not isinstance(template, str) or not template:
                raise RegistryError(f"{where}.template: required for action 'deploy'")
            if template not in templates:
                raise RegistryError(
                    f"{where}.template: {template!r} is not a template of project {project_key!r}"
                )
            if "branch" in rule and (not isinstance(rule["branch"], str) or not rule["branch"]):
                raise RegistryError(f"{where}.branch: must be a non-empty string")

    def _validate(self, *, host_config_path: Path | None = None) -> None:
        data = self._data
        if "fleet" not in data:
            raise RegistryError("missing top-level key 'fleet'")
        fleet_block = data["fleet"] or {}
        # `fleet.domain` is only required when NO host.yml override resolved
        # it (see `load()`/`_load_host_domain`) — a shared fleet.yml across
        # several hosts may legitimately omit it entirely once every host
        # carries its own host.yml.
        if "domain" not in fleet_block and self._host_domain is None:
            where = f" or in {host_config_path}" if host_config_path is not None else ""
            raise RegistryError(f"missing key 'fleet.domain' in {self._path}{where}")

        fleet_ports = self._validate_fleet_ports(fleet_block)
        self._warn_auth_bypass_deprecated(fleet_block)

        projects = data.get("projects") or {}
        self._validate_users(projects)
        for project_key, project_block in projects.items():
            try:
                validate_part(project_key)
            except ValidationError as exc:
                raise RegistryError(f"projects.{project_key}: {exc.message}") from exc

            project_block = project_block or {}
            if "git" not in project_block:
                raise RegistryError(f"projects.{project_key}.git: missing")

            if "git_bot" in project_block:
                gb = project_block["git_bot"]
                if gb is not False and not isinstance(gb, dict):
                    raise RegistryError(
                        f"projects.{project_key}.git_bot: must be a mapping "
                        "(name/email) to override the identity, or false to "
                        "disable git identity injection for this project"
                    )

            if "issue_id_regexp" in project_block:
                pattern = project_block["issue_id_regexp"]
                if not isinstance(pattern, str):
                    raise RegistryError(f"projects.{project_key}.issue_id_regexp: must be a string")
                try:
                    re.compile(pattern)
                except re.error as exc:
                    raise RegistryError(
                        f"projects.{project_key}.issue_id_regexp: invalid regexp — {exc}"
                    ) from exc

            if "display_submodule_branch" in project_block:
                where = f"projects.{project_key}.display_submodule_branch"
                sub = project_block["display_submodule_branch"]
                if not isinstance(sub, str) or not sub.strip():
                    raise RegistryError(
                        f"{where}: must be a non-empty string (a submodule path "
                        "relative to the instance checkout)"
                    )
                # Joined onto the instance dir and handed to `git -C`, so it must
                # not be able to point outside that checkout.
                sub_path = PurePosixPath(sub)
                if sub_path.is_absolute() or ".." in sub_path.parts:
                    raise RegistryError(
                        f"{where}: must be a relative path inside the instance "
                        "checkout (no leading '/', no '..' segments)"
                    )

            templates = project_block.get("templates") or {}
            self._validate_jira_hooks(project_key, project_block, templates)
            for template_key, template_block in templates.items():
                try:
                    validate_part(template_key)
                except ValidationError as exc:
                    raise RegistryError(
                        f"projects.{project_key}.templates.{template_key}: {exc.message}"
                    ) from exc
                if template_block and "branch" in template_block:
                    raise RegistryError(
                        f"projects.{project_key}.templates.{template_key}.branch: not "
                        "allowed — branch is resolved per-deploy, never stored in a template"
                    )
                if template_block and "drupal_env" in template_block:
                    value = template_block["drupal_env"]
                    # Written verbatim as a shell-style `DRUPAL_ENV=<value>`
                    # line in the instance's .env, so it must be a plain
                    # single-token word — no quoting/escaping is applied.
                    if not isinstance(value, str) or not value or any(c.isspace() for c in value):
                        raise RegistryError(
                            f"projects.{project_key}.templates.{template_key}.drupal_env: "
                            "must be a non-empty single-word string (e.g. dev, staging)"
                        )

                if template_block:
                    self._validate_template_commands(project_key, template_key, template_block)

            for hostname in project_block.get("additional_hostnames") or []:
                # Each entry becomes the `<h>` half of a flattened alias
                # FQDN, `<h>-<instance-id>.<domain>` (core/instances.py's
                # `alias_fqdns`) — so it must itself be a bare DNS label: no
                # dots (an alias host is single-label, matching Caddy's
                # `*.{{ fleet_domain }}` site block), lowercase only. Same
                # pattern/error shape as `validate_part(project_key)` above.
                if not isinstance(hostname, str):
                    raise RegistryError(
                        f"projects.{project_key}.additional_hostnames: entries must be "
                        f"strings, got {hostname!r}"
                    )
                try:
                    validate_part(hostname)
                except ValidationError as exc:
                    raise RegistryError(
                        f"projects.{project_key}.additional_hostnames.{hostname}: {exc.message}"
                    ) from exc

            for port_name in project_block.get("ports") or []:
                if port_name not in fleet_ports:
                    raise RegistryError(
                        f"projects.{project_key}.ports: unknown port name {port_name!r} "
                        "(not defined in fleet.ports)"
                    )

    def _validate_template_commands(
        self, project_key: str, template_key: str, template_block: dict
    ) -> None:
        """Validate a template's command lists: `post_deploy` (a legacy list
        of strings == `exec:`, or a mapping with optional `exec`/`tty1`/`tty2`
        lists of strings) and the DEPRECATED template-level `tty1`/`tty2`
        (FLE-6: still accepted, with a one-time deprecation warning per
        template; setting the same pane in BOTH places is an error — which
        one wins would otherwise be a silent guess)."""
        where = f"projects.{project_key}.templates.{template_key}"

        post_deploy = template_block.get("post_deploy")
        mapping_ttys: set[str] = set()
        if isinstance(post_deploy, dict):
            for key in post_deploy:
                if key not in _POST_DEPLOY_KEYS:
                    raise RegistryError(
                        f"{where}.post_deploy.{key}: unknown key — a post_deploy mapping "
                        f"only supports {', '.join(_POST_DEPLOY_KEYS)}"
                    )
            for key in _POST_DEPLOY_KEYS:
                if post_deploy.get(key) is None:
                    continue  # `exec:` / `tty1:` left empty == no commands
                self._require_string_list(f"{where}.post_deploy.{key}", post_deploy[key])
                if key in _TTY_KEYS:
                    mapping_ttys.add(key)
        elif post_deploy is not None:
            self._require_string_list(f"{where}.post_deploy", post_deploy)

        legacy_ttys: list[str] = []
        for tty_key in _TTY_KEYS:
            if tty_key not in template_block:
                continue
            self._require_string_list(f"{where}.{tty_key}", template_block[tty_key])
            if tty_key in mapping_ttys:
                raise RegistryError(
                    f"{where}.{tty_key}: set both at template level (deprecated) and "
                    f"under post_deploy.{tty_key} — keep only post_deploy.{tty_key}"
                )
            legacy_ttys.append(tty_key)
        for key in template_block:
            if key in _TTY_KEYS:
                continue
            if _TTY_KEY_RE.match(str(key)):
                raise RegistryError(
                    f"{where}.{key}: not allowed — only tty1 and tty2 are supported"
                )

        if legacy_ttys:
            logger.warning(
                "%s: template-level %s is deprecated — move it under post_deploy "
                "(post_deploy: {exec: [...], %s: [...]}); still honoured for now",
                where,
                "/".join(legacy_ttys),
                legacy_ttys[0],
            )

    @staticmethod
    def _require_string_list(path: str, value) -> None:
        if not isinstance(value, list) or not all(isinstance(item, str) for item in value):
            raise RegistryError(f"{path}: must be a list of strings")

    def _validate_users(self, projects: dict) -> None:
        """Validate every project's `users:` list (spec §4.2): charset,
        non-empty password, no duplicate name within one project, `admins`
        reserved, and — across ALL projects — the same user name must carry
        the same password everywhere it appears (Authelia has exactly one
        password per username; two projects disagreeing is a fleet.yml
        authoring error, not something to silently pick a winner for)."""
        seen_passwords: dict[str, tuple[str, str]] = {}
        for project_key, project_block in projects.items():
            project_block = project_block or {}
            raw_users = project_block.get("users")
            if raw_users is None:
                continue
            if not isinstance(raw_users, list):
                raise RegistryError(f"projects.{project_key}.users: must be a list")
            seen_names: set[str] = set()
            for idx, entry in enumerate(raw_users):
                path = f"projects.{project_key}.users[{idx}]"
                if not isinstance(entry, dict) or "name" not in entry or "password" not in entry:
                    raise RegistryError(f"{path}: must be a mapping with 'name' and 'password'")
                name = entry["name"]
                password = entry["password"]
                if not isinstance(name, str) or not _USER_NAME_RE.match(name):
                    raise RegistryError(
                        f"{path}.name: must match {_USER_NAME_RE.pattern!r}, got {name!r}"
                    )
                if name == "admins":
                    raise RegistryError(
                        f"{path}.name: 'admins' is reserved for the installer admin group"
                    )
                if not isinstance(password, str) or not password:
                    raise RegistryError(f"{path}.password: must be a non-empty string")
                if name in seen_names:
                    raise RegistryError(
                        f"projects.{project_key}.users: duplicate user {name!r} in the "
                        "same project"
                    )
                seen_names.add(name)
                if name in seen_passwords:
                    other_password, other_project = seen_passwords[name]
                    if other_password != password:
                        raise RegistryError(
                            f"user {name!r} has different passwords in projects "
                            f"{other_project!r} and {project_key!r} — must match"
                        )
                else:
                    seen_passwords[name] = (password, project_key)

    def _warn_auth_bypass_deprecated(self, fleet_block: dict) -> None:
        """`fleet.auth_bypass_cidrs` (IP whitelisting) is deprecated:
        Authelia's per-project `users:` (auth_mode: authelia) replaces it
        entirely — see spec decision D7,
        2026-09-25-fleet-authelia-design.md. Accepted and IGNORED, never
        raised: the primary server's fleet.yml keeps this key until BOTH
        servers have switched to Authelia (removing it earlier would break
        the still-on-basic-auth server, since fleet.yml is shared).  Logged
        once per registry load; never removed even once every consumer of
        the VALUE is gone (see registry.auth_bypass_cidrs's own docstring
        for what "consumer" means here)."""
        if "auth_bypass_cidrs" in fleet_block:
            logger.warning(
                "fleet.auth_bypass_cidrs is deprecated and ignored — IP whitelisting "
                "was replaced by Authelia (host.yml's auth_mode: authelia); remove it "
                "from fleet.yml once every fleet server has switched"
            )

    @property
    def domain(self) -> str:
        """The fleet's wildcard domain. `host.yml`'s `domain` (per-host,
        see `load()`) wins when present, even if `fleet.yml`'s own
        `fleet.domain` also has a value — that's the whole point of sharing
        one `fleet.yml` across hosts with different domains."""
        if self._host_domain is not None:
            return self._host_domain
        return str(self._data["fleet"]["domain"])

    @property
    def auth_mode(self) -> str:
        """`"basic"` (default) or `"authelia"` — server-level, from
        `host.yml`'s `auth_mode` (see `_load_host_auth_mode`). Never comes
        from `fleet.yml` — a shared registry must be able to run in
        different modes on different hosts."""
        return self._auth_mode

    @property
    def auth_bypass_cidrs(self) -> list[str]:
        """DEPRECATED (spec D7) — kept only until Task 5 of the Authelia
        plan removes its last consumers in `core/instances.py`/`cli.py`.
        Because the registry no longer VALIDATES this key (see
        `_warn_auth_bypass_deprecated`), this property is now tolerant of
        malformed entries — it skips them rather than raising, since a bad
        entry in an already-deprecated, already-ignored-in-spirit key must
        never fail a registry load."""
        raw = (self._data.get("fleet") or {}).get("auth_bypass_cidrs")
        if not isinstance(raw, list):
            return []
        seen: dict[str, None] = {}
        for entry in raw:
            try:
                seen.setdefault(str(ipaddress.ip_network(str(entry), strict=False)), None)
            except ValueError:
                continue
        return list(seen)

    def git_bot(self, project: str | None = None) -> tuple[str, str] | None:
        """Resolve the git commit identity injected into an instance's
        ``web_environment`` as ``GIT_AUTHOR_*``/``GIT_COMMITTER_*``.

        Precedence (highest first):
          1. A project-level ``git_bot`` — a mapping ``{name, email}`` overrides
             the identity for that project (missing keys fall back to the fleet
             default); ``false`` disables injection for that project entirely
             (returns ``None``), letting the project's own git config decide.
          2. The fleet-level default: ``fleet.git_bot_name``/``git_bot_email``
             (or ``ddev-fleet bot`` / ``bot@<domain>``); ``fleet.git_bot: false``
             disables injection fleet-wide.

        Returning ``None`` means "inject no GIT_* env vars" — the mechanism that
        lets a project's committed identity (e.g. a post-start ``git config``
        hook) win, since those env vars otherwise override git config.
        """
        fb = self._data["fleet"]
        default_name = str(fb.get("git_bot_name") or "ddev-fleet bot")
        default_email = str(fb.get("git_bot_email") or f"bot@{self.domain}")
        fleet_default: tuple[str, str] | None = (
            None if fb.get("git_bot") is False else (default_name, default_email)
        )

        if project is None or not self.has_project(project):
            return fleet_default

        block = self._project_block(project)
        if "git_bot" not in block:
            return fleet_default
        override = block["git_bot"]
        if override is False:
            return None
        # dict override: fall back to the base identity strings (not the
        # possibly-None fleet_default) for any field the project omits.
        return (
            str(override.get("name") or default_name),
            str(override.get("email") or default_email),
        )

    def project_keys(self) -> list[str]:
        return list((self._data.get("projects") or {}).keys())

    def has_project(self, key: str) -> bool:
        return key in (self._data.get("projects") or {})

    def _project_block(self, project: str) -> dict:
        if not self.has_project(project):
            raise RegistryError(f"unknown project {project!r}")
        return self._data["projects"][project] or {}

    def template_keys(self, project: str) -> list[str]:
        block = self._project_block(project)
        return list((block.get("templates") or {}).keys())

    def git_url(self, project: str) -> str:
        return str(self._project_block(project)["git"])

    def project_defaults(self, project: str) -> tuple[str | None, str | None]:
        """Return ``(default_template, default_branch)`` for a project, each
        ``None`` when not configured."""
        block = self._project_block(project)
        default_template = block.get("default_template")
        default_branch = block.get("default_branch")
        return (
            str(default_template) if default_template is not None else None,
            str(default_branch) if default_branch is not None else None,
        )

    def additional_hostnames(self, project: str) -> list[str]:
        block = self._project_block(project)
        return [str(h) for h in (block.get("additional_hostnames") or [])]

    def project_users(self, project: str) -> list[tuple[str, str]]:
        """`(name, password)` pairs from `projects.<project>.users`, in
        declared order. `[]` if the project declares none."""
        block = self._project_block(project)
        return [(str(u["name"]), str(u["password"])) for u in (block.get("users") or [])]

    def all_users(self) -> list[UserRecord]:
        """Every user declared by any project, merged by name with the set
        of projects (groups) that declared it — the input
        `fleet.core.authelia.render_users()` renders into Authelia's
        `users.yml`. Password consistency across projects sharing a name is
        already enforced by `_validate_users` at load time, so any one
        project's recorded password is authoritative here. A YAML alias
        project (e.g. `oak: *fern`) is its own group even though it shares
        the same underlying users list — each project key is iterated
        independently."""
        merged: dict[str, str] = {}
        groups: dict[str, list[str]] = {}
        for project in self.project_keys():
            for name, password in self.project_users(project):
                merged[name] = password
                groups.setdefault(name, []).append(project)
        return [
            UserRecord(name=name, password=merged[name], groups=tuple(sorted(groups[name])))
            for name in merged
        ]

    def typesense_enabled(self, project: str) -> bool:
        block = self._project_block(project)
        return bool(block.get("typesense"))

    def issue_id_regexp(self, project: str) -> str | None:
        """The project's `[[issue-id]]` extraction pattern (`extract_issue_id`
        in `core/tokens.py`), or None when the project defines none — the
        token is then simply absent from a deploy's context."""
        block = self._project_block(project)
        pattern = block.get("issue_id_regexp")
        return str(pattern) if pattern is not None else None

    def display_submodule_branch(self, project: str) -> str | None:
        """Path (relative to an instance checkout) of the submodule whose branch
        and short HEAD `fleet list`, the web UI and the tmux sidebar display in
        place of the instance's own, or None when the project defines none.
        Display only — deploy/redeploy never read it."""
        block = self._project_block(project)
        path = block.get("display_submodule_branch")
        return str(path) if path is not None else None

    def jira_hooks(self, project: str) -> list[JiraHookRule]:
        """The project's validated `jira_hooks` rules, in file order; `[]`
        when it defines none (the webhook route then answers 404)."""
        block = self._project_block(project)
        return [
            JiraHookRule(
                on_status=str(rule["on_status"]).strip(),
                action=str(rule["action"]),
                template=str(rule["template"]),
                branch=str(rule["branch"]) if rule.get("branch") is not None else None,
            )
            for rule in (block.get("jira_hooks") or [])
        ]

    def port_profile(self, name: str) -> PortProfile:
        """Look up one `fleet.ports` entry by name. `'typesense'` falls back
        to the built-in legacy default (spec §3.2) when `fleet.ports` has
        no explicit entry for it — so `typesense: true`-only registries need
        zero edits. Any other unknown name raises RegistryError."""
        fleet_ports = self._data["fleet"].get("ports") or {}
        if name in fleet_ports:
            entry = fleet_ports[name]
            return PortProfile(name=name, public=int(entry["public"]), router=int(entry["router"]))
        if name == "typesense":
            return _TYPESENSE_LEGACY_DEFAULT
        raise RegistryError(f"unknown port name {name!r} (not defined in fleet.ports)")

    def project_ports(self, project: str) -> list[PortProfile]:
        """`projects.<project>.ports` resolved by name, plus a synthetic
        'typesense' entry when `typesense_enabled(project)` and 'typesense'
        isn't already listed (dedupes the legacy/new overlap, spec §3.3)."""
        block = self._project_block(project)
        names = list(block.get("ports") or [])
        if self.typesense_enabled(project) and "typesense" not in names:
            names.append("typesense")
        return [self.port_profile(name) for name in names]

    def all_port_profiles(self) -> list[PortProfile]:
        """Union of every PortProfile referenced by ANY project, one each,
        sorted by name — the accessor the firewall spec's `fleet-ufw-sync`
        and `core/caddyports.py` both consume."""
        seen: dict[str, PortProfile] = {}
        for project in self.project_keys():
            for profile in self.project_ports(project):
                seen[profile.name] = profile
        return [seen[name] for name in sorted(seen)]

    def public_ports_in_use(self) -> list[int]:
        return sorted({p.public for p in self.all_port_profiles()})

    def resolve(
        self, project: str, template: str, branch: str, label: str | None = None
    ) -> ResolvedInstance:
        """Resolve `(project, template, branch, label)` into a `ResolvedInstance`.

        The instance label is always normalised via `slugify()` — lowercased,
        every run of non-`[a-z0-9]` characters collapsed to a single `-`,
        leading/trailing `-` stripped — whether it is derived from `branch`
        (no explicit `label`) or passed explicitly. An explicit label is
        normalised, not rejected: e.g. `label="ABC-1234"` resolves to
        `"abc-1234"`. This is the single choke point for the CLI, the web
        UI, and bulk/multi-deploy, which all reach it via
        `instances.resolve_target`.

        Raises RegistryError if `template` is unknown, `branch` is empty, or
        the resolved label/instance id fails validation (e.g. slugifies to
        empty, or the composed `<project>--<label>` exceeds the 63-character
        DNS label limit).
        """
        block = self._project_block(project)
        templates = block.get("templates") or {}
        if template not in templates:
            raise RegistryError(f"unknown template {template!r} for project {project!r}")
        if not branch:
            raise RegistryError(f"branch is required to resolve project {project!r}")

        template_block = templates[template] or {}
        raw_post_deploy = template_block.get("post_deploy")
        if isinstance(raw_post_deploy, dict):
            post_deploy_map = raw_post_deploy
        else:
            # Legacy list shorthand: `post_deploy: [cmd, ...]` == `exec: [...]`.
            post_deploy_map = {"exec": raw_post_deploy}
        post_deploy = [str(c) for c in (post_deploy_map.get("exec") or [])]
        drupal_env = template_block.get("drupal_env")
        drupal_env = str(drupal_env) if drupal_env is not None else None
        # `post_deploy.tty1/2` is the canonical home; template-level `tty1/2`
        # is the deprecated spelling (validation guarantees a pane is never
        # set in both).
        tty1 = [str(c) for c in (post_deploy_map.get("tty1") or template_block.get("tty1") or [])]
        tty2 = [str(c) for c in (post_deploy_map.get("tty2") or template_block.get("tty2") or [])]

        try:
            resolved_label = slugify(label) if label else slugify(branch)
            inst_id = instance_id(project, resolved_label)
        except ValidationError as exc:
            raise RegistryError(
                f"cannot resolve project {project!r} branch {branch!r}: {exc.message}"
            ) from exc

        return ResolvedInstance(
            project=project,
            template=template,
            branch=branch,
            label=resolved_label,
            post_deploy=post_deploy,
            instance_id=inst_id,
            tty1=tty1,
            tty2=tty2,
            drupal_env=drupal_env,
        )
