"""Thin argparse CLI exposing the fleet.core command surface (spec §11)."""

import argparse
import os
import re
import sys
from pathlib import Path

from ruamel.yaml import YAML

from fleet.core import assets as assets_mod
from fleet.core import ddev, fleetconfig
from fleet.core import instances as instances_mod
from fleet.core.errors import FleetError
from fleet.core.registry import Registry
from fleet.core.runner import run_streamed
from fleet.core.secrets import write_secret

DEFAULT_FLEET_HOME = "/srv/fleet"

_yaml = YAML()
_yaml.default_flow_style = False

_CLAUDE_TOKEN_RE = re.compile(r"sk-ant-oat01-[A-Za-z0-9_-]+")


def _mint_claude_token(runner=run_streamed) -> str | None:
    result = runner(["claude", "setup-token"], echo=False)
    if result.returncode != 0:
        return None
    for line in result.lines:
        match = _CLAUDE_TOKEN_RE.search(line)
        if match:
            return match.group(0)
    return None


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
    snapshot_parser.add_argument("--dest-rel", default="dumps/db.sql.gz")

    subparsers.add_parser("refresh-claude-token")

    subparsers.add_parser("refresh-config")

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
        elif args.command == "refresh-config":
            _cmd_refresh_config(fleet_home, runner=run_streamed)
    except FleetError as exc:
        print(exc.message, file=sys.stderr)
        return 1

    return 0


def _cmd_init(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    paths.assets.mkdir(parents=True, exist_ok=True)
    paths.instances.mkdir(exist_ok=True)
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
        token = _mint_claude_token(run_streamed)
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
    token = _mint_claude_token(run_streamed)
    if not token:
        raise FleetError("'claude setup-token' did not produce a token; refresh aborted")
    write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", token)

    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    instances_root = paths.instances

    try:
        running_ids = {
            p.get("name")
            for p in ddev.list_projects(runner=run_streamed)
            if str(p.get("status", "")).lower() == "running"
        }
    except Exception:
        running_ids = set()

    failed: list[str] = []
    if instances_root.exists():
        for entry in sorted(instances_root.iterdir()):
            if not entry.is_dir():
                continue
            info_path = entry / ".fleet" / "instance.yml"
            if not info_path.exists():
                print(f"{entry.name}: no .fleet/instance.yml — skipping", file=sys.stderr)
                continue
            try:
                fleetconfig.write_fleet_config(entry, entry.name, registry.domain, token)
                if entry.name in running_ids:
                    result = ddev.restart(entry, runner=run_streamed)
                    if result.returncode != 0:
                        raise FleetError(
                            f"ddev restart failed for {entry.name!r} with exit code "
                            f"{result.returncode}"
                        )
            except FleetError as exc:
                print(f"{entry.name}: {exc.message}", file=sys.stderr)
                failed.append(entry.name)

    return 1 if failed else 0


def _cmd_refresh_config(fleet_home: Path, *, runner=run_streamed) -> None:
    cfg = fleet_home / "config"
    if (cfg / ".git").is_dir():
        runner(["git", "-C", str(cfg), "fetch", "--quiet"])
        runner(["git", "-C", str(cfg), "pull", "--ff-only"])
        print(f"refreshed {cfg} (git pull)")
    else:
        print(f"{cfg} is not a git checkout — edit in place; nothing to pull")


if __name__ == "__main__":
    sys.exit(main())
