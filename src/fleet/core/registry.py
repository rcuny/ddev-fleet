"""Load/validate/resolve/save fleet.yml (spec §4)."""

from dataclasses import dataclass
from pathlib import Path

from ruamel.yaml import YAML

from fleet.core.errors import RegistryError, ValidationError
from fleet.core.naming import instance_id, validate_part

_yaml = YAML()
_yaml.preserve_quotes = True
_yaml.width = 4096


@dataclass
class ResolvedInstance:
    project: str
    instance: str
    branch: str
    post_deploy: list[str]
    instance_id: str


class Registry:
    def __init__(self, data, path: Path) -> None:
        self._data = data
        self._path = path

    @classmethod
    def load(cls, path: Path) -> "Registry":
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
        fleet_block = data["fleet"]
        for key in ("domain", "assets_path", "instances_path"):
            if key not in fleet_block:
                raise RegistryError(f"missing key 'fleet.{key}'")

        projects = data.get("projects") or {}
        for project_key, project_block in projects.items():
            try:
                validate_part(project_key)
            except ValidationError as exc:
                raise RegistryError(f"projects.{project_key}: {exc.message}") from exc
            if "git" not in project_block:
                raise RegistryError(f"projects.{project_key}.git: missing")

            instances = project_block.get("instances") or {}
            for instance_name, instance_block in instances.items():
                try:
                    instance_id(project_key, instance_name)
                except ValidationError as exc:
                    raise RegistryError(
                        f"projects.{project_key}.instances.{instance_name}: {exc.message}"
                    ) from exc
                if "branch" not in instance_block:
                    raise RegistryError(
                        f"projects.{project_key}.instances.{instance_name}.branch: missing"
                    )

    @property
    def domain(self) -> str:
        return str(self._data["fleet"]["domain"])

    @property
    def assets_path(self) -> Path:
        return Path(str(self._data["fleet"]["assets_path"]))

    @property
    def instances_path(self) -> Path:
        return Path(str(self._data["fleet"]["instances_path"]))

    def project_keys(self) -> list[str]:
        return list((self._data.get("projects") or {}).keys())

    def has_project(self, key: str) -> bool:
        return key in (self._data.get("projects") or {})

    def has_instance(self, project: str, instance: str) -> bool:
        if not self.has_project(project):
            return False
        instances = self._data["projects"][project].get("instances") or {}
        return instance in instances

    def resolve(self, project: str, instance: str) -> ResolvedInstance:
        if not self.has_project(project):
            raise RegistryError(f"unknown project {project!r}")
        project_block = self._data["projects"][project]
        instances = project_block.get("instances") or {}
        if instance not in instances:
            raise RegistryError(f"unknown instance {instance!r} for project {project!r}")

        instance_block = instances[instance]
        branch = str(instance_block["branch"])
        if "post_deploy" in instance_block:
            post_deploy = [str(c) for c in instance_block["post_deploy"]]
        else:
            post_deploy = [str(c) for c in (project_block.get("post_deploy") or [])]

        return ResolvedInstance(
            project=project,
            instance=instance,
            branch=branch,
            post_deploy=post_deploy,
            instance_id=instance_id(project, instance),
        )

    def register_instance(self, project: str, instance: str, branch: str) -> None:
        if not self.has_project(project):
            raise RegistryError(f"unknown project {project!r}")
        try:
            instance_id(project, instance)
        except ValidationError as exc:
            raise RegistryError(
                f"invalid instance name for projects.{project}.instances.{instance}: {exc.message}"
            ) from exc

        project_block = self._data["projects"][project]
        if project_block.get("instances") is None:
            project_block["instances"] = {}
        project_block["instances"][instance] = {"branch": branch}

    def add_project(self, key: str, git_url: str, post_deploy: list[str] | None = None) -> None:
        try:
            validate_part(key)
        except ValidationError as exc:
            raise RegistryError(f"invalid project key {key!r}: {exc.message}") from exc
        if self._data.get("projects") is None:
            self._data["projects"] = {}
        if key in self._data["projects"]:
            raise RegistryError(f"project {key!r} already exists")

        block: dict = {"git": git_url, "instances": {}}
        if post_deploy:
            block["post_deploy"] = list(post_deploy)
        self._data["projects"][key] = block

    def git_url(self, project: str) -> str:
        if not self.has_project(project):
            raise RegistryError(f"unknown project {project!r}")
        return str(self._data["projects"][project]["git"])

    def save(self) -> None:
        with open(self._path, "w", encoding="utf-8") as fh:
            _yaml.dump(self._data, fh)
