"""Thin argparse CLI exposing the fleet.core command surface (spec §11)."""

import argparse
import os
import sys
from pathlib import Path

from fleet.core import instances as instances_mod
from fleet.core.errors import FleetError
from fleet.core.registry import Registry
from fleet.core.runner import run_streamed
from fleet.core.secrets import write_secret

DEFAULT_FLEET_HOME = "/srv/fleet"


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
    deploy_parser.add_argument("instance")
    deploy_parser.add_argument("--branch", default=None)
    deploy_parser.add_argument("--fresh", action="store_true")
    deploy_parser.add_argument("--force", action="store_true")

    destroy_parser = subparsers.add_parser("destroy")
    destroy_parser.add_argument("instance_id")

    start_parser = subparsers.add_parser("start")
    start_parser.add_argument("instance_id")

    stop_parser = subparsers.add_parser("stop")
    stop_parser.add_argument("instance_id")

    subparsers.add_parser("list")

    project_parser = subparsers.add_parser("project")
    project_subparsers = project_parser.add_subparsers(dest="project_command", required=True)
    project_add_parser = project_subparsers.add_parser("add")
    project_add_parser.add_argument("key")
    project_add_parser.add_argument("--git", required=True)
    project_add_parser.add_argument("--post-deploy", action="append", default=None)

    subparsers.add_parser("ssh-key")

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
        elif args.command == "project":
            _cmd_project(fleet_home, args)
        elif args.command == "ssh-key":
            _cmd_ssh_key(fleet_home, args)
    except FleetError as exc:
        print(exc.message, file=sys.stderr)
        return 1

    return 0


def _cmd_init(fleet_home: Path, args: argparse.Namespace) -> None:
    fleet_home.mkdir(parents=True, exist_ok=True)
    (fleet_home / "assets").mkdir(exist_ok=True)
    (fleet_home / "instances").mkdir(exist_ok=True)
    (fleet_home / "locks").mkdir(exist_ok=True)

    domain = args.domain
    if not domain:
        domain = input("Fleet domain: ").strip()

    registry_path = fleet_home / "fleet.yml"
    if not registry_path.exists():
        registry_path.write_text(
            "fleet:\n"
            f"  domain: {domain}\n"
            f"  assets_path: {fleet_home / 'assets'}\n"
            f"  instances_path: {fleet_home / 'instances'}\n"
            "\n"
            "projects: {}\n",
            encoding="utf-8",
        )

    if not args.skip_claude:
        result = run_streamed(["claude", "setup-token"], echo=False)
        if result.returncode == 0 and result.lines:
            token = result.lines[-1].strip()
            write_secret(fleet_home / ".secrets", "CLAUDE_CODE_OAUTH_TOKEN", token)


def _cmd_deploy(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    url = instances_mod.deploy(
        paths,
        registry,
        args.project,
        args.instance,
        branch=args.branch,
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


def _cmd_project(fleet_home: Path, args: argparse.Namespace) -> None:
    paths = instances_mod.FleetPaths.from_home(fleet_home)
    registry = Registry.load(paths.registry)
    if args.project_command == "add":
        registry.add_project(args.key, args.git, args.post_deploy)
        registry.save()


def _cmd_ssh_key(fleet_home: Path, args: argparse.Namespace) -> None:
    key_path = fleet_home / "fleet-deploy-key.pub"
    if not key_path.exists():
        raise FleetError(f"no deploy key found at {key_path}")
    print(key_path.read_text(encoding="utf-8").strip())


if __name__ == "__main__":
    sys.exit(main())
