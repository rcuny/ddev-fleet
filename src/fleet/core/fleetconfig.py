"""Write the one fleet-owned file per instance, .ddev/config.fleet.yaml (spec §8)."""

from pathlib import Path

from ruamel.yaml import YAML
from ruamel.yaml.scalarstring import LiteralScalarString

_yaml = YAML()
_yaml.default_flow_style = False
_yaml.indent(mapping=2, sequence=4, offset=2)

# Seeds the container's ~/.claude.json past Claude Code's first-run
# onboarding wizard (spec: .claude/user/docs/specs/
# 2026-07-17-claude-onboarding-and-fleet-remote-design.md, Part B step 3).
# CLAUDE_CODE_OAUTH_TOKEN authenticates API calls but does NOT mark
# onboarding complete — the interactive TUI still shows the theme picker /
# trust dialog unless hasCompletedOnboarding is set. This must MERGE, not
# overwrite: ~/.claude.json also holds runtime keys (userID, machineID,
# firstStartTime, per-project history) written by Claude Code itself, which
# must survive. Runs as a `post-start` DDEV hook (not baked into the image
# via homeadditions/) because DDEV creates the container user — and thus
# /home/<user> — at runtime from the host uid, and re-copies homeadditions
# on every start, which would clobber those runtime keys each time.
#
# ORDERING (load-bearing, verified on ddev v1.25.3): DDEV merges `hooks` from
# config.yaml and every config.*.yaml ADDITIVELY, in filename order — it does
# not replace them, so this cannot clobber a project's own hooks. That order
# matters: a project may relink ~/.claude.json into its own tree (oak's
# config.claude-code.yaml does exactly this, seeding it with `{}`), and this
# seed must run AFTER that relink or it writes to a file that is about to be
# replaced. "config.fleet.yaml" sorts after "config.claude-code.yaml", so it
# does. A project shipping a later-sorting config.*.yaml that relinks
# ~/.claude.json would defeat this.
#
# Writing through such a symlink is intentional: it lands the seed in the
# project tree, where it persists across restarts.
#
# No single quotes inside the JS itself, so it can be wrapped in a
# single-quoted `node -e '...'` shell argument without escaping.
_CLAUDE_ONBOARDING_SEED_SCRIPT = (
    'const fs = require("fs");\n'
    'const path = (process.env.HOME || "/home/fleet") + "/.claude.json";\n'
    "let data = {};\n"
    'try { data = JSON.parse(fs.readFileSync(path, "utf8")); } catch (e) {}\n'
    "data.hasCompletedOnboarding = true;\n"
    'if (data.theme === undefined) { data.theme = "dark"; }\n'
    "data.projects = data.projects || {};\n"
    'const proj = data.projects["/var/www/html"] || {};\n'
    "proj.hasTrustDialogAccepted = true;\n"
    'data.projects["/var/www/html"] = proj;\n'
    "fs.writeFileSync(path, JSON.stringify(data, null, 2));\n"
)

_CLAUDE_ONBOARDING_HOOK_EXEC = LiteralScalarString(
    "node -e '\n" + _CLAUDE_ONBOARDING_SEED_SCRIPT + "'\n"
)


def write_fleet_config(
    instance_dir: Path,
    instance_id: str,
    domain: str,
    claude_token: str | None,
    additional_fqdns: list[str] | None = None,
    git_bot: tuple[str, str] | None = None,
    typesense: bool = False,
    typesense_port: int = 9108,
    typesense_admin_key: str | None = None,
    typesense_search_key: str | None = None,
) -> Path:
    ddev_dir = instance_dir / ".ddev"
    ddev_dir.mkdir(parents=True, exist_ok=True)
    config_path = ddev_dir / "config.fleet.yaml"

    # performance_mode: none — this fleet only ever runs on native Linux
    # Docker hosts, where Mutagen is pure overhead. A project's own
    # config.yaml may commit performance_mode: mutagen (e.g. for macOS
    # devs); config.fleet.yaml merges after config.yaml (DDEV loads
    # config.yaml first, then config.*.yaml overrides), so this scalar
    # reliably wins over the project's setting.
    data: dict = {"name": instance_id, "project_tld": domain, "performance_mode": "none"}
    # DRUSH_OPTIONS_URI — always inject so `drush uli`/status report the
    # instance's real fleet hostname instead of a project-hardcoded URI
    # from a committed settings/config.local.yaml. Reuses the same
    # instance_id + domain FQDN pattern as FLEET_TYPESENSE_HOST below /
    # tokens.py's [[instance-fqdn]] token.
    web_environment: list[str] = [f"DRUSH_OPTIONS_URI=https://{instance_id}.{domain}"]
    if claude_token:
        web_environment.append(f"CLAUDE_CODE_OAUTH_TOKEN={claude_token}")
    if git_bot:
        bot_name, bot_email = git_bot
        web_environment.extend(
            [
                f"GIT_AUTHOR_NAME={bot_name}",
                f"GIT_AUTHOR_EMAIL={bot_email}",
                f"GIT_COMMITTER_NAME={bot_name}",
                f"GIT_COMMITTER_EMAIL={bot_email}",
            ]
        )
    if typesense:
        web_environment.extend(
            [
                f"FLEET_TYPESENSE_HOST={instance_id}.{domain}",
                f"FLEET_TYPESENSE_PORT={typesense_port}",
            ]
        )
        if typesense_admin_key:
            web_environment.append(f"TYPESENSE_API_KEY={typesense_admin_key}")
        if typesense_search_key:
            web_environment.append(f"FLEET_TYPESENSE_SEARCH_KEY={typesense_search_key}")
    data["web_environment"] = web_environment
    if additional_fqdns:
        data["additional_fqdns"] = list(additional_fqdns)
    # Unconditional — write_web_build() below bakes Claude Code into every
    # instance's web image regardless of claude_token, so every instance
    # needs the onboarding wizard seeded past, not just ones with a token.
    data["hooks"] = {"post-start": [{"exec": _CLAUDE_ONBOARDING_HOOK_EXEC}]}

    with open(config_path, "w", encoding="utf-8") as fh:
        _yaml.dump(data, fh)
    return config_path


def set_web_env_var(instance_dir: Path, key: str, value: str) -> bool:
    """Upsert a single `KEY=VALUE` entry into an existing instance's
    `.ddev/config.fleet.yaml` `web_environment` list, in place, preserving
    every other entry and top-level key (name, project_tld, additional_fqdns,
    ...). Unlike `write_fleet_config`, this never rewrites the whole file —
    it's the safe way to rotate a single secret (e.g. the Claude token)
    without dropping the git-bot / Typesense env vars a full rewrite would
    need but not have. Returns False if the config file doesn't exist yet
    (nothing to update)."""
    config_path = instance_dir / ".ddev" / "config.fleet.yaml"
    if not config_path.exists():
        return False

    with open(config_path, "r", encoding="utf-8") as fh:
        data = _yaml.load(fh) or {}

    web_environment = data.setdefault("web_environment", [])
    prefix = f"{key}="
    for index, entry in enumerate(web_environment):
        if entry == key or str(entry).startswith(prefix):
            web_environment[index] = f"{key}={value}"
            break
    else:
        web_environment.append(f"{key}={value}")

    with open(config_path, "w", encoding="utf-8") as fh:
        _yaml.dump(data, fh)
    return True


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


def write_ddev_env(instance_dir: Path, values: dict[str, str]) -> Path:
    """Upsert KEY=VALUE lines into `.ddev/.env`, preserving any existing keys
    not present in `values`. DDEV interpolates `.ddev/.env` into the
    project's docker-compose, so this is how a service container (e.g.
    Typesense) receives fleet-managed secrets like `TYPESENSE_API_KEY`."""
    ddev_dir = instance_dir / ".ddev"
    ddev_dir.mkdir(parents=True, exist_ok=True)
    env_path = ddev_dir / ".env"

    existing: dict[str, str] = {}
    if env_path.exists():
        for line in env_path.read_text(encoding="utf-8").splitlines():
            stripped = line.strip()
            if not stripped or stripped.startswith("#") or "=" not in stripped:
                continue
            key, _, value = stripped.partition("=")
            existing[key.strip()] = value.strip()

    existing.update(values)

    lines = [f"{key}={value}" for key, value in existing.items()]
    env_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return env_path


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
