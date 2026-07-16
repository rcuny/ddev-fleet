"""Interactive helpers behind `fleet shell` and `fleet ddev`.

Reproduces the retired `fleet-shell` helper script (deleted in commit
064050c): drop into an interactive shell at the fleet home or a specific
instance directory, or exec `ddev` inside an instance.

Split into two layers so the one process-replacing call stays thin and
untested directly:
  - argv/cwd *construction* functions below (unit-testable, no exec)
  - `exec_in_dir()`, the single place that actually chdir()s + execvp()s.
"""

import os
from pathlib import Path

from fleet.core.errors import FleetError
from fleet.core.instances import FleetPaths

DEFAULT_SHELL = "bash"


def list_instance_ids(paths: FleetPaths) -> list[str]:
    """Return sorted instance ids currently on disk (empty list if none)."""
    if not paths.instances.exists():
        return []
    return sorted(entry.name for entry in paths.instances.iterdir() if entry.is_dir())


def resolve_instance_dir(paths: FleetPaths, instance_id: str) -> Path:
    """Return the on-disk directory for `instance_id`, or raise FleetError
    listing the available instances if it doesn't exist."""
    instance_dir = paths.instances / instance_id
    if not instance_dir.is_dir():
        available = list_instance_ids(paths)
        hint = ", ".join(available) if available else "(none deployed)"
        raise FleetError(f"unknown instance {instance_id!r}; available instances: {hint}")
    return instance_dir


def shell_target(paths: FleetPaths, instance_id: str | None) -> tuple[Path, str]:
    """Resolve the (cwd, description) for `fleet shell`. No instance_id means
    drop into the fleet home directory instead of a specific instance."""
    if instance_id is None:
        return paths.home, f"fleet home ({paths.home})"
    instance_dir = resolve_instance_dir(paths, instance_id)
    return instance_dir, f"instance {instance_id!r} ({instance_dir})"


def shell_argv(paths: FleetPaths, instance_id: str | None) -> tuple[list[str], Path]:
    """Build the argv + cwd for `fleet shell`."""
    cwd, _description = shell_target(paths, instance_id)
    return [DEFAULT_SHELL], cwd


def ddev_argv(paths: FleetPaths, instance_id: str, args: list[str]) -> tuple[list[str], Path]:
    """Build the argv + cwd for `fleet ddev`. With no trailing args, defaults
    to an interactive `ddev ssh` (matches the old fleet-shell behaviour)."""
    instance_dir = resolve_instance_dir(paths, instance_id)
    argv = ["ddev", *args] if args else ["ddev", "ssh"]
    return argv, instance_dir


def prompt_for_instance(paths: FleetPaths, *, reader=input) -> str:
    """Print a numbered list of instances and prompt the operator to pick
    one. Raises FleetError if there are no instances deployed, or if stdin
    is closed/non-interactive (EOFError from `reader`)."""
    ids = list_instance_ids(paths)
    if not ids:
        raise FleetError(f"no instances deployed under {paths.instances}")

    print("Select an instance:")
    for idx, instance_id in enumerate(ids, start=1):
        print(f"  {idx}) {instance_id}")

    try:
        choice = reader(f"Instance [1-{len(ids)}]: ").strip()
    except EOFError as exc:
        raise FleetError(
            "no instance_id given and stdin is not interactive; pass an instance_id "
            "explicitly (e.g. 'fleet ddev <instance_id>')"
        ) from exc

    if not choice.isdigit() or not (1 <= int(choice) <= len(ids)):
        raise FleetError(
            f"invalid selection {choice!r}: expected a number between 1 and {len(ids)}"
        )

    return ids[int(choice) - 1]


def exec_in_dir(argv: list[str], cwd: Path) -> None:
    """Replace the current process with `argv`, running in `cwd`. Never
    returns on success — `os.execvp` replaces the process image."""
    os.chdir(cwd)
    os.execvp(argv[0], argv)
