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


_CLAUDE_WEB_BUILD = "RUN npm install -g @anthropic-ai/claude-code\n"


def write_web_build(instance_dir: Path) -> Path:
    """Bake the Claude Code CLI into the instance's DDEV web image so
    `ddev exec claude` works (the token is injected separately via
    config.fleet.yaml's web_environment). DDEV concatenates
    .ddev/web-build/Dockerfile.* onto the web image build."""
    web_build_dir = instance_dir / ".ddev" / "web-build"
    web_build_dir.mkdir(parents=True, exist_ok=True)
    dockerfile = web_build_dir / "Dockerfile.fleet-claude"
    dockerfile.write_text(_CLAUDE_WEB_BUILD, encoding="utf-8")
    return dockerfile


def ensure_git_exclude(instance_dir: Path, patterns: list[str]) -> None:
    exclude_path = instance_dir / ".git" / "info" / "exclude"
    exclude_path.parent.mkdir(parents=True, exist_ok=True)

    existing_text = ""
    existing_lines: list[str] = []
    if exclude_path.exists():
        existing_text = exclude_path.read_text(encoding="utf-8")
        existing_lines = existing_text.splitlines()

    missing = [p for p in patterns if p not in existing_lines]
    if not missing:
        return

    with open(exclude_path, "a", encoding="utf-8") as fh:
        needs_leading_newline = existing_text != "" and not existing_text.endswith("\n")
        if needs_leading_newline:
            fh.write("\n")
        for pattern in missing:
            fh.write(pattern + "\n")
