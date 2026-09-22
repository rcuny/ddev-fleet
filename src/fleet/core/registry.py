"""Load/validate/resolve the fleet.yml project→templates registry (spec §4).

The registry is declarative and read-only at runtime: `fleet.yml` is
authored/edited by hand (or by provisioning tooling), never mutated by the
fleet CLI/daemon. A project lists reusable deploy `templates` (post_deploy
commands, etc.); `branch` and the instance `label` are resolved per-deploy,
never stored in the registry.
"""

import ipaddress
import re
from dataclasses import dataclass, field
from pathlib import Path

from ruamel.yaml import YAML

from fleet.core.errors import RegistryError, ValidationError
from fleet.core.naming import instance_id, slugify, validate_part

_yaml = YAML()
_yaml.preserve_quotes = True
_yaml.width = 4096

_RESERVED_PORTS = frozenset({22, 80, 443, 8765})
_ROUTER_RESERVED_PORTS = frozenset({8080, 8443})
_MIN_PORT = 1
_MAX_PORT = 65535
_TTY_KEYS = ("tty1", "tty2")
_TTY_KEY_RE = re.compile(r"^tty\d+$")


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


@dataclass(frozen=True)
class PortProfile:
    name: str
    public: int
    router: int


_TYPESENSE_LEGACY_DEFAULT = PortProfile(name="typesense", public=9108, router=8108)


class Registry:
    def __init__(self, data, path: Path) -> None:
        self._data = data
        self._path = path

    @classmethod
    def load(cls, path: Path) -> "Registry":
        if not path.exists():
            raise RegistryError(f"{path}: registry not found; run 'fleet init' first")
        with open(path, "r", encoding="utf-8") as fh:
            data = _yaml.load(fh)
        if data is None:
            raise RegistryError(f"{path}: empty or invalid registry file")
        registry = cls(data, path)
        registry._validate()
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

    def _validate(self) -> None:
        data = self._data
        if "fleet" not in data:
            raise RegistryError("missing top-level key 'fleet'")
        fleet_block = data["fleet"] or {}
        if "domain" not in fleet_block:
            raise RegistryError("missing key 'fleet.domain'")

        fleet_ports = self._validate_fleet_ports(fleet_block)
        self._validate_auth_bypass(fleet_block)

        projects = data.get("projects") or {}
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

            templates = project_block.get("templates") or {}
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
                if template_block:
                    for tty_key in _TTY_KEYS:
                        if tty_key not in template_block:
                            continue
                        value = template_block[tty_key]
                        if not isinstance(value, list) or not all(
                            isinstance(item, str) for item in value
                        ):
                            raise RegistryError(
                                f"projects.{project_key}.templates.{template_key}.{tty_key}: "
                                "must be a list of strings"
                            )
                    for key in template_block:
                        if key in _TTY_KEYS:
                            continue
                        if _TTY_KEY_RE.match(str(key)):
                            raise RegistryError(
                                f"projects.{project_key}.templates.{template_key}.{key}: not "
                                "allowed — only tty1 and tty2 are supported"
                            )

            for port_name in project_block.get("ports") or []:
                if port_name not in fleet_ports:
                    raise RegistryError(
                        f"projects.{project_key}.ports: unknown port name {port_name!r} "
                        "(not defined in fleet.ports)"
                    )

    def _validate_auth_bypass(self, fleet_block: dict) -> None:
        """Validate `fleet.auth_bypass_cidrs` (optional): a list of IPv4/IPv6
        addresses or CIDR ranges whose visitors skip per-instance basic auth."""
        raw = fleet_block.get("auth_bypass_cidrs")
        if raw is None:
            return
        if not isinstance(raw, list):
            raise RegistryError(
                "fleet.auth_bypass_cidrs: must be a list of IP addresses/CIDR ranges"
            )
        for entry in raw:
            try:
                ipaddress.ip_network(str(entry), strict=False)
            except ValueError as exc:
                raise RegistryError(f"fleet.auth_bypass_cidrs: invalid entry {entry!r} — {exc}")

    @property
    def domain(self) -> str:
        return str(self._data["fleet"]["domain"])

    @property
    def auth_bypass_cidrs(self) -> list[str]:
        """Networks whose visitors are NOT prompted for per-instance basic
        auth (`fleet.auth_bypass_cidrs` in fleet.yml — per fleet server).

        Entries are normalised to canonical CIDR form (host bits masked off,
        so `10.0.0.5/29` becomes `10.0.0.0/29`) and de-duplicated, preserving
        first-seen order: the list is written verbatim into every instance's
        Caddy snippet as `not remote_ip ...`, and hand-maintained allow-lists
        routinely carry both duplicates and un-masked prefixes. A bare address
        normalises to a /32 (or /128), which is what Caddy expects.

        Anything NOT matching one of these falls through to the basic-auth
        prompt — there is no explicit deny entry; the default IS deny."""
        raw = (self._data.get("fleet") or {}).get("auth_bypass_cidrs") or []
        seen: dict[str, None] = {}
        for entry in raw:
            seen.setdefault(str(ipaddress.ip_network(str(entry), strict=False)), None)
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
        post_deploy = [str(c) for c in (template_block.get("post_deploy") or [])]
        tty1 = [str(c) for c in (template_block.get("tty1") or [])]
        tty2 = [str(c) for c in (template_block.get("tty2") or [])]

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
        )
