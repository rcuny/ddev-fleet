"""Write the one fleet-owned file per instance, .ddev/config.fleet.yaml (spec §8)."""

from pathlib import Path

from ruamel.yaml import YAML

_yaml = YAML()
_yaml.default_flow_style = False
_yaml.indent(mapping=2, sequence=4, offset=2)


def write_fleet_config(
    instance_dir: Path, instance_id: str, domain: str, claude_token: str | None
) -> Path:
    ddev_dir = instance_dir / ".ddev"
    ddev_dir.mkdir(parents=True, exist_ok=True)
    config_path = ddev_dir / "config.fleet.yaml"

    data: dict = {"name": instance_id, "project_tld": domain}
    if claude_token:
        data["web_environment"] = [f"CLAUDE_CODE_OAUTH_TOKEN={claude_token}"]

    with open(config_path, "w", encoding="utf-8") as fh:
        _yaml.dump(data, fh)
    return config_path


def ensure_git_exclude(instance_dir: Path, patterns: list[str]) -> None:
    exclude_path = instance_dir / ".git" / "info" / "exclude"
    exclude_path.parent.mkdir(parents=True, exist_ok=True)

    existing_lines: list[str] = []
    if exclude_path.exists():
        existing_lines = exclude_path.read_text(encoding="utf-8").splitlines()

    missing = [p for p in patterns if p not in existing_lines]
    if not missing:
        return

    with open(exclude_path, "a", encoding="utf-8") as fh:
        for pattern in missing:
            fh.write(pattern + "\n")
