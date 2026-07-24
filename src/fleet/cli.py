"""Thin argparse CLI exposing the fleet.core command surface (spec §11)."""

import argparse
import os
import re
import secrets as _stdlib_secrets
import sys
from pathlib import Path

from ruamel.yaml import YAML

from fleet import tmux_sidebar
from fleet.core import assets as assets_mod
from fleet.core import caddyauth, caddyports, ddev, fleetconfig
from fleet.core import instances as instances_mod
from fleet.core import shell as shell_mod
from fleet.core import tmux as tmux_mod
from fleet.core.errors import FleetError
from fleet.core.registry import Registry
from fleet.core.runner import run_interactive, run_streamed
from fleet.core.secrets import write_secret

DEFAULT_FLEET_HOME = "/srv/fleet"

_FLEET_UFW_SYNC_HELPER = Path("/usr/local/sbin/fleet-ufw-sync")

_yaml = YAML()
_yaml.default_flow_style = False

_CLAUDE_TOKEN_RE = re.compile(r"sk-ant-oat01-[A-Za-z0-9_-]+")
_VALID_CLAUDE_TOKEN_RE = re.compile(r"^sk-ant-oat01-[A-Za-z0-9_-]+$")


def _is_valid_claude_token(token: str) -> bool:
    return bool(_VALID_CLAUDE_TOKEN_RE.match(token))


def _mint_claude_token(runner=run_streamed) -> str | None:
    """Legacy piped/non-interactive mint. Kept only because some historic
    tests exercise it directly; init/refresh use
    `_mint_claude_token_interactive` (below) since `claude setup-token`
    needs a real terminal to show its auth URL."""
    result = runner(["claude", "setup-token"], echo=False)
    if result.returncode != 0:
        return None
    for line in result.lines:
        match = _CLAUDE_TOKEN_RE.search(line)
        if match:
            return match.group(0)
    return None


def _mint_claude_token_interactive(*, runner=run_interactive, reader=input) -> str | None:
    """Run `claude setup-token` with inherited stdio so its auth URL and the
    minted token are actually visible in the terminal, then ask the operator
    to paste the printed `sk-ant-oat01-...` token back. Returns None if the
    command failed or the pasted value isn't a valid token."""
    returncode = runner(["claude", "setup-token"])
    if returncode != 0:
        return None
    token = reader("\nPaste the sk-ant-oat01-… token shown above: ").strip()
    return token if _is_valid_claude_token(token) else None


def _fleet_home(args: argparse.Namespace) -> Path:
    if args.fleet_home:
        return Path(args.fleet_home)
    return Path(os.environ.get("FLEET_HOME", DEFAULT_FLEET_HOME))


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="fleet")
    parser.add_argument("--fleet-home", default=None)
    subparsers = parser.add_subparsers(dest="command", required=True)

    init_parser = subparsers.add_parser("init")
    init_parser.add_argument("--domain", default=None)
    init_parser.add_argument("--skip-claude", action="store_true")

    deploy_parser = subparsers.add_parser("deploy")
    deploy_parser.add_argument("project")
    deploy_parser.add_argument("template", nargs="?", default=None)
    deploy_parser.add_argument("--branch", default=None)
    deploy_parser.add_argument("--label", default=None)
    deploy_parser.add_argument("--fresh", action="store_true")
    deploy_parser.add_argument("--force", action="store_true")
    deploy_parser.add_argument(
        "--no-auth",
        dest="auth",
        action="store_false",
        default=True,
        help="disable basic auth for this instance (default: enabled)",
    )
    deploy_parser.add_argument(
        "--auth-password",
        default=caddyauth.DEFAULT_INSTANCE_PASSWORD,
        help=(
            "basic auth password for this instance "
            f"(default: {caddyauth.DEFAULT_INSTANCE_PASSWORD!r})"
        ),
    )

    destroy_parser = subparsers.add_parser("destroy")
    destroy_parser.add_argument("instance_id")

    start_parser = subparsers.add_parser("start")
    start_parser.add_argument("instance_id")

    stop_parser = subparsers.add_parser("stop")
    stop_parser.add_argument("instance_id")

    subparsers.add_parser("list")

    subparsers.add_parser("ssh-key")

    assets_parser = subparsers.add_parser("assets")
    assets_subparsers = assets_parser.add_subparsers(dest="assets_command", required=True)
    assets_push_parser = assets_subparsers.add_parser("push")
    assets_push_parser.add_argument("project")
    assets_push_parser.add_argument("src")
    assets_push_parser.add_argument("dest_rel")

    secret_parser = subparsers.add_parser("secret")
    secret_subparsers = secret_parser.add_subparsers(dest="secret_command", required=True)
    secret_set_parser = secret_subparsers.add_parser("set")
    secret_set_parser.add_argument("project")
    secret_set_parser.add_argument("key")
    secret_set_parser.add_argument("value")

    snapshot_parser = subparsers.add_parser("snapshot")
    snapshot_parser.add_argument("instance_id")
    snapshot_parser.add_argument(
        "--dest-rel",
        default=None,
        help=(
            "asset-relative dump path (default: dumps/default-<instance_id>.sql, "
            "computed from the instance id if omitted)"
        ),
    )

    refresh_claude_token_parser = subparsers.add_parser("refresh-claude-token")
    refresh_claude_token_parser.add_argument(
        "--restart",
        action="store_true",
        help=(
            "restart every running instance immediately so it picks up the new "
            "token (default: leave restarts to the operator — slow with many "
            "instances)"
        ),
    )

    set_claude_token_parser = subparsers.add_parser("set-claude-token")
    set_claude_token_parser.add_argument("token")
    set_claude_token_parser.add_argument(
        "--restart",
        action="store_true",
        help=(
            "restart every running instance immediately so it picks up the new "
            "token (default: leave restarts to the operator — slow with many "
            "instances)"
        ),
    )

    set_admin_password_parser = subparsers.add_parser("set-admin-password")
    set_admin_password_parser.add_argument("password")

    subparsers.add_parser("rotate-admin-password")

    subparsers.add_parser("refresh-config")

    refresh_instance_config_parser = subparsers.add_parser("refresh-instance-config")
    refresh_instance_config_parser.add_argument("instance_id")
    refresh_instance_config_parser.add_argument(
        "--restart",
        action="store_true",
        help=(
            "restart this instance immediately so it picks up the new config "
            "(default: leave the restart to the operator)"
        ),
    )

    subparsers.add_parser("refresh-ports")

    shell_parser = subparsers.add_parser("shell")
    shell_parser.add_argument("instance_id", nargs="?", default=None)
    shell_parser.add_argument("-l", "--list", action="store_true", dest="list_instances")

    ddev_parser = subparsers.add_parser("ddev")
    ddev_parser.add_argument("instance_id", nargs="?", default=None)
    ddev_parser.add_argument("ddev_args", nargs=argparse.REMAINDER)

    subparsers.add_parser("tmux")

    tmux_sidebar_parser = subparsers.add_parser("tmux-sidebar")
    tmux_sidebar_parser.add_argument("--window", required=True)
    tmux_sidebar_parser.add_argument("--once", action="store_true")

    tmux_reset_parser = subparsers.add_parser("tmux-reset")
    tmux_reset_parser.add_argument("window", nargs="?")

    return parser


def main(argv: list[str] | None = None) -> int:
    parser = _build_parser()
    args = parser.parse_args(argv)
    fleet_home = _fleet_home(args)

    try:
        if args.command == "init":
            _cmd_init(fleet_home, args)
        elif args.command == "deploy":
            _cmd_deploy(fleet_home, args)
        elif args.command == "destroy":
            _cmd_destroy(fleet_home, args)
        elif args.command == "start":
            _cmd_start(fleet_home, args)
        elif args.command == "stop":
            _cmd_stop(fleet_home, args)
        elif args.command == "list":
            _cmd_list(fleet_home, args)
        elif args.command == "ssh-key":
            _cmd_ssh_key(fleet_home, args)
        elif args.command == "assets":
            _cmd_assets(fleet_home, args)
        elif args.command == "secret":
            _cmd_secret(fleet_home, args)
        elif args.command == "snapshot":
            _cmd_snapshot(fleet_home, args)
        elif args.command == "refresh-claude-token":
            return _cmd_refresh_claude_token(fleet_home, args)
        elif args.command == "set-claude-token":
            return _cmd_set_claude_token(fleet_home, args)
        elif args.command == "set-admin-password":
            _cmd_set_admin_password(fleet_home, args, runner=run_streamed)
        elif args.command == "rotate-admin-password":
            _cmd_rotate_admin_password(fleet_home, args, runner=run_streamed)
        elif args.command == "refresh-config":
            _cmd_refresh_config(fleet_home, runner=run_streamed)
        elif args.command == "refresh-instance-config":
            _cmd_refresh_instance_config(fleet_home, args)
        elif args.command == "refresh-ports":
            return _cmd_refresh_ports(fleet_home, args, runner=run_streamed)
        elif args.command == "shell":
            _cmd_shell(fleet_home, args)
        elif args.command == "ddev":
            _cmd_ddev(fleet_home, args)
        elif args.command == "tmux":
            _cmd_tmux(fleet_home, args)
        elif args.command == "tmux-sidebar":
            _cmd_tmux_sidebar(fleet_home, args)
        elif args.command == "tmux-reset":
            _cmd_tmux_reset(fleet_home, args)
    except FleetError as exc:
        print(exc.message, file=sys.stderr)
        return 1

    return 0


def _cmd_init(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.assets.mkdir(parents=True, exist_ok=True)
    paths.instances.mkdir(exist_ok=True)
    paths.logs.mkdir(exist_ok=True)
    paths.locks.mkdir(exist_ok=True)
    paths.project_secrets.mkdir(mode=0o700, exist_ok=True)

    domain = args.domain
    if not domain:
        domain = input("Fleet domain: ").strip()

    registry_path = paths.registry
    if registry_path.exists():
        print(f"{registry_path} already exists — skipping", file=sys.stderr)
    else:
        skeleton = {
            "fleet": {
                "domain": domain,
            },
            "projects": {},
        }
        with open(registry_path, "w", encoding="utf-8") as fh:
            _yaml.dump(skeleton, fh)

    if not args.skip_claude:
        token = _mint_claude_token_interactive(runner=run_interactive, reader=input)
        if token:
            write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", token)
        else:
            print(
                "warning: 'claude setup-token' did not produce a token; "
                "CLAUDE_CODE_OAUTH_TOKEN was not written to .secrets",
                file=sys.stderr,
            )


def _cmd_deploy(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    url = instances_mod.deploy(
        paths,
        registry,
        args.project,
        args.template,
        branch=args.branch,
        label=args.label,
        fresh=args.fresh,
        force=args.force,
        auth_enabled=args.auth,
        auth_password=args.auth_password,
    )
    print(url)


def _cmd_destroy(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    instances_mod.destroy(paths, registry, args.instance_id)


def _cmd_start(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    instances_mod.start(paths, registry, args.instance_id)


def _cmd_stop(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    instances_mod.stop(paths, registry, args.instance_id)


def _cmd_list(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    statuses = instances_mod.list_instances(paths, registry)

    print(f"{'INSTANCE ID':30} {'PROJECT':20} {'BRANCH':15} {'STATE':10} {'RAM(MiB)':10} URL")
    for status in statuses:
        ram = str(status.ram_mib) if status.ram_mib is not None else "-"
        print(
            f"{status.instance_id:30} {status.project:20} {status.branch:15} "
            f"{status.state:10} {ram:10} {status.url}"
        )


def _cmd_ssh_key(fleet_home: Path, args: argparse.Namespace) -> None:
    key_path = fleet_home / "fleet-deploy-key.pub"
    if not key_path.exists():
        raise FleetError(f"no deploy key found at {key_path}")
    print(key_path.read_text(encoding="utf-8").strip())


def _cmd_assets(fleet_home: Path, args: argparse.Namespace) -> None:
    if args.assets_command == "push":
        paths = instances_mod.FleetPaths.from_home(fleet_home)
        registry = Registry.load(paths.registry)
        if not registry.has_project(args.project):
            raise FleetError(f"unknown project {args.project!r}")
        assets_dir = paths.assets / args.project
        dest = assets_mod.push(assets_dir, Path(args.src), args.dest_rel)
        print(dest)


def _cmd_secret(fleet_home: Path, args: argparse.Namespace) -> None:
    if args.secret_command == "set":
        paths = instances_mod.FleetPaths.from_home(fleet_home)
        write_secret(paths.project_secrets / f"{args.project}.env", args.key, args.value)


def _cmd_snapshot(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    dest = instances_mod.snapshot(paths, registry, args.instance_id, dest_rel=args.dest_rel)
    print(dest)


def _cmd_refresh_claude_token(fleet_home: Path, args: argparse.Namespace) -> int:
    token = _mint_claude_token_interactive(runner=run_interactive, reader=input)
    if not token:
        raise FleetError("'claude setup-token' did not produce a token; refresh aborted")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", token)
    return _propagate_claude_token(fleet_home, token, runner=run_streamed, restart=args.restart)


def _cmd_set_claude_token(fleet_home: Path, args: argparse.Namespace) -> int:
    if not _is_valid_claude_token(args.token):
        raise FleetError(
            "CLAUDE_CODE_OAUTH_TOKEN must be a Claude Code setup token of the form "
            "'sk-ant-oat01-…'"
        )
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", args.token)
    return _propagate_claude_token(
        fleet_home, args.token, runner=run_streamed, restart=args.restart
    )


def _propagate_claude_token(fleet_home: Path, token: str, *, runner, restart: bool = False) -> int:
    """Write a freshly minted/set CLAUDE_CODE_OAUTH_TOKEN into every deployed
    instance's `.ddev/config.fleet.yaml` (non-destructively — see
    `fleetconfig.set_web_env_var`). This config write always happens, so the
    new token is in place whenever an instance next starts.

    By default this does NOT restart any running instance — with 10+ live
    instances, restarting all of them just to rotate a token is slow, so
    that's left to the operator. Instead, every running instance still
    carrying the old token in its live environment is reported, along with
    the exact command to restart it. Pass `restart=True` to restore the old
    restart-everything-immediately behaviour.

    Returns 1 if any instance failed, else 0."""
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    instances_root = paths.instances

    try:
        running_ids = {
            p.get("name")
            for p in ddev.list_projects(runner=runner)
            if str(p.get("status", "")).lower() == "running"
        }
    except Exception:
        running_ids = set()

    failed: list[str] = []
    needs_restart: list[str] = []
    if instances_root.exists():
        for entry in sorted(instances_root.iterdir()):
            if not entry.is_dir():
                continue
            try:
                updated = fleetconfig.set_web_env_var(entry, "CLAUDE_CODE_OAUTH_TOKEN", token)
                if not updated:
                    print(f"{entry.name}: no config.fleet.yaml — skipping", file=sys.stderr)
                    continue
                if entry.name not in running_ids:
                    print(f"{entry.name}: updated")
                    continue
                if restart:
                    result = ddev.restart(entry, runner=runner)
                    if result.returncode != 0:
                        raise FleetError(
                            f"ddev restart failed for {entry.name!r} with exit code "
                            f"{result.returncode}"
                        )
                    print(f"{entry.name}: updated and restarted")
                else:
                    needs_restart.append(entry.name)
                    print(f"{entry.name}: updated (running — needs a restart to apply)")
            except FleetError as exc:
                print(f"{entry.name}: {exc.message}", file=sys.stderr)
                failed.append(entry.name)

    if needs_restart:
        print()
        print(
            f"{len(needs_restart)} running instance(s) still have the previous token "
            "loaded — restart each to apply the new one:"
        )
        for name in needs_restart:
            print(f"  cd {instances_root / name} && ddev restart")

    return 1 if failed else 0


def _cmd_set_admin_password(
    fleet_home: Path, args: argparse.Namespace, *, runner=run_streamed
) -> None:
    """Set an explicit dashboard admin password: hash it, write the
    fleet-owned Caddy snippet, validate, and reload — no Ansible run."""
    caddyauth.rotate(caddyauth.DEFAULT_ADMIN_USERNAME, args.password, runner=runner)
    print("Dashboard admin password updated; Caddy reloaded.")


def _cmd_rotate_admin_password(
    fleet_home: Path, args: argparse.Namespace, *, runner=run_streamed
) -> None:
    """Generate a strong random dashboard admin password, apply it, and
    print it exactly once — it is never stored in the clear anywhere."""
    password = _stdlib_secrets.token_urlsafe(18)
    caddyauth.rotate(caddyauth.DEFAULT_ADMIN_USERNAME, password, runner=runner)
    print("Dashboard admin password rotated; Caddy reloaded.")
    print(f"New password: {password}")
    print("Save this now — it will not be shown again.")


def _cmd_shell(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)

    if args.list_instances:
        for instance_id in shell_mod.list_instance_ids(paths):
            print(instance_id)
        return

    argv, cwd = shell_mod.shell_argv(paths, args.instance_id)
    target = f"instance {args.instance_id!r}" if args.instance_id else "the fleet home"
    print(f"Dropping into a shell in {target} ({cwd})")
    print("hint: ddev describe | ddev ssh | ddev drush uli")
    shell_mod.exec_in_dir(argv, cwd)


def _cmd_ddev(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)

    instance_id = args.instance_id
    if instance_id is None:
        instance_id = shell_mod.prompt_for_instance(paths)

    argv, cwd = shell_mod.ddev_argv(paths, instance_id, args.ddev_args or [])
    shell_mod.exec_in_dir(argv, cwd)


def _cmd_refresh_config(fleet_home: Path, *, runner=run_streamed) -> None:
    cfg = fleet_home / "config"
    if (cfg / ".git").is_dir():
        runner(["git", "-C", str(cfg), "fetch", "--quiet"])
        runner(["git", "-C", str(cfg), "pull", "--ff-only"])
        print(f"refreshed {cfg} (git pull)")
    else:
        print(f"{cfg} is not a git checkout — edit in place; nothing to pull")


def _cmd_refresh_instance_config(fleet_home: Path, args: argparse.Namespace) -> None:
    """Regenerate one instance's `.ddev/config.fleet.yaml` (incl. the Claude
    onboarding hook) without a full deploy. Always does the cheap config
    rewrite; a `ddev restart` to apply it is opt-in via `--restart` (same
    "cheap authoritative write always; expensive propagation opt-in" shape
    as `set-claude-token`/`refresh-claude-token` — see
    `_propagate_claude_token`)."""
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    instances_mod.refresh_instance_config(paths, registry, args.instance_id, restart=args.restart)

    if args.restart:
        print(f"{args.instance_id}: config refreshed and restarted")
    else:
        instance_dir = paths.instances / args.instance_id
        print(f"{args.instance_id}: config refreshed (not restarted)")
        print(f"  cd {instance_dir} && ddev restart")


def _cmd_refresh_ports(fleet_home: Path, args: argparse.Namespace, *, runner=run_streamed) -> int:
    """Reconcile Caddy port-exposure snippets to `fleet.yml`'s current
    `fleet.ports`/`ports:` state — the "apply my port edits now"
    command (spec §5). Also runs the UFW half via the sudo helper when
    the network_hardening role is installed; silently skipped when the
    helper is absent (not an error)."""
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)

    result = caddyports.sync(registry, runner=runner)
    if result.written or result.removed:
        for name in result.written:
            print(f"caddy: wrote port snippet {name!r}")
        for name in result.removed:
            print(f"caddy: removed port snippet {name!r}")
    else:
        print("caddy: no changes")

    if _FLEET_UFW_SYNC_HELPER.exists():
        ufw_result = runner(["sudo", str(_FLEET_UFW_SYNC_HELPER)], echo=False)
        if ufw_result.returncode != 0:
            detail = "\n".join(ufw_result.lines)
            raise FleetError(
                f"'sudo {_FLEET_UFW_SYNC_HELPER}' failed (exit "
                f"{ufw_result.returncode}):\n{detail}"
            )
        print("ufw: synced")

    return 0


def _cmd_tmux(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    ids = shell_mod.list_instance_ids(paths)
    tmux_mod.reconcile(paths, ids)
    tmux_mod.attach()


def _cmd_tmux_sidebar(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    tmux_sidebar.run(paths, args.window, once=args.once)


def _cmd_tmux_reset(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    window = args.window or tmux_mod.current_window()
    tmux_mod.reset_window(paths, window)


if __name__ == "__main__":
    sys.exit(main())
