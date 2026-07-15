"""Load/validate/resolve the fleet.yml project→templates registry (spec §4).

The registry is declarative and read-only at runtime: `fleet.yml` is
authored/edited by hand (or by provisioning tooling), never mutated by the
fleet CLI/daemon. A project lists reusable deploy `templates` (post_deploy
commands, etc.); `branch` and the instance `label` are resolved per-deploy,
never stored in the registry.
"""

from dataclasses import dataclass
from pathlib import Path

from ruamel.yaml import YAML

from fleet.core.errors import RegistryError, ValidationError
from fleet.core.naming import instance_id, slugify, validate_part

_yaml = YAML()
_yaml.preserve_quotes = True
_yaml.width = 4096


@dataclass
class ResolvedInstance:
    project: str
    template: str
    branch: str
    label: str
    post_deploy: list[str]
    instance_id: str


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

    def _validate(self) -> None:
        data = self._data
        if "fleet" not in data:
            raise RegistryError("missing top-level key 'fleet'")
        fleet_block = data["fleet"] or {}
        if "domain" not in fleet_block:
            raise RegistryError("missing key 'fleet.domain'")

        projects = data.get("projects") or {}
        for project_key, project_block in projects.items():
            try:
                validate_part(project_key)
            except ValidationError as exc:
                raise RegistryError(f"projects.{project_key}: {exc.message}") from exc

            project_block = project_block or {}
            if "git" not in project_block:
                raise RegistryError(f"projects.{project_key}.git: missing")

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

    @property
    def domain(self) -> str:
        return str(self._data["fleet"]["domain"])

    def git_bot(self) -> tuple[str, str]:
        fb = self._data["fleet"]
        name = fb.get("git_bot_name") or "ddev-fleet bot"
        email = fb.get("git_bot_email") or f"bot@{self.domain}"
        return (str(name), str(email))

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

    def resolve(
        self, project: str, template: str, branch: str, label: str | None = None
    ) -> ResolvedInstance:
        block = self._project_block(project)
        templates = block.get("templates") or {}
        if template not in templates:
            raise RegistryError(f"unknown template {template!r} for project {project!r}")
        if not branch:
            raise RegistryError(f"branch is required to resolve project {project!r}")

        template_block = templates[template] or {}
        post_deploy = [str(c) for c in (template_block.get("post_deploy") or [])]

        try:
            resolved_label = label if label else slugify(branch)
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
        )
