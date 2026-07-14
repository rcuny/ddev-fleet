"""Write the one fleet-owned file per instance, .ddev/config.fleet.yaml (spec §8)."""

from pathlib import Path

from ruamel.yaml import YAML

_yaml = YAML()
_yaml.default_flow_style = False
_yaml.indent(mapping=2, sequence=4, offset=2)


def write_fleet_config(
    instance_dir: Path,
    instance_id: str,
    domain: str,
    claude_token: str | None,
    additional_fqdns: list[str] | None = None,
    git_bot: tuple[str, str] | None = None,
) -> Path:
    ddev_dir = instance_dir / ".ddev"
    ddev_dir.mkdir(parents=True, exist_ok=True)
    config_path = ddev_dir / "config.fleet.yaml"

    data: dict = {"name": instance_id, "project_tld": domain}
    if claude_token:
        data["web_environment"] = [f"CLAUDE_CODE_OAUTH_TOKEN={claude_token}"]
    if git_bot:
        bot_name, bot_email = git_bot
        web_environment = data.setdefault("web_environment", [])
        web_environment.extend(
            [
                f"GIT_AUTHOR_NAME={bot_name}",
                f"GIT_AUTHOR_EMAIL={bot_email}",
                f"GIT_COMMITTER_NAME={bot_name}",
                f"GIT_COMMITTER_EMAIL={bot_email}",
            ]
        )
    if additional_fqdns:
        data["additional_fqdns"] = list(additional_fqdns)

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


def _read_docroot(instance_dir: Path) -> str:
    config_path = instance_dir / ".ddev" / "config.yaml"
    if not config_path.exists():
        return ""
    with open(config_path, "r", encoding="utf-8") as fh:
        data = _yaml.load(fh) or {}
    return str(data.get("docroot") or "")


def write_settings_local(instance_dir: Path, domain: str) -> list[Path]:
    """Inject sites/default/settings.local.php trusting the instance's fleet
    hostname(s) so Drupal's trusted_host_patterns accepts the fleet domain,
    and sites/default/services.fleet.yml neutralising any hardcoded session
    cookie_domain (projects sometimes commit services.ddev.yml with a
    cookie_domain pinned to their own dev hostname, which breaks login on
    the fleet hostname). settings.local.php loads after settings.ddev.php
    (standard Drupal include) and appends services.fleet.yml last, so it
    overrides the project's / DDEV's services container yamls. Returns []
    (writes nothing) if the project has no <docroot>/sites/default
    (non-Drupal)."""
    docroot = _read_docroot(instance_dir)
    base = instance_dir / docroot if docroot else instance_dir
    sites_default = base / "sites" / "default"
    if not sites_default.is_dir():
        return []
    escaped = domain.replace(".", "\\.")
    settings_content = (
        "<?php\n"
        "// fleet-managed: trust this instance's fleet hostname(s).\n"
        f"$settings['trusted_host_patterns'][] = '^.+\\.{escaped}$';\n"
        "// fleet-managed: neutralise any hardcoded session cookie_domain so login cookies\n"
        "// are set for the current fleet host (services.fleet.yml is appended last, so it\n"
        "// overrides the project's / DDEV's services container yamls).\n"
        "$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.fleet.yml';\n"
        "// fleet-managed: load the project's own (fleet-agnostic) overrides if present.\n"
        "if (file_exists(__DIR__ . '/settings.project.php')) {\n"
        "    include __DIR__ . '/settings.project.php';\n"
        "}\n"
    )
    services_content = "parameters:\n" "  session.storage.options:\n" "    cookie_domain: ''\n"
    settings_local_path = sites_default / "settings.local.php"
    services_fleet_path = sites_default / "services.fleet.yml"
    settings_local_path.write_text(settings_content, encoding="utf-8")
    services_fleet_path.write_text(services_content, encoding="utf-8")
    return [settings_local_path, services_fleet_path]


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
