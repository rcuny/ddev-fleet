"""Asset mirror copy (rsync wrapper) + token substitution pass (spec §7)."""

from pathlib import Path

from fleet.core.errors import FleetError
from fleet.core.runner import run_streamed
from fleet.core.tokens import substitute_file


def inject(
    assets_dir: Path, instance_dir: Path, context: dict[str, str], *, runner=run_streamed
) -> list[Path]:
    if not assets_dir.exists():
        return []

    instance_dir.mkdir(parents=True, exist_ok=True)
    result = runner(["rsync", "-a", str(assets_dir) + "/", str(instance_dir) + "/"])
    if result.returncode != 0:
        raise FleetError(
            f"rsync from {assets_dir} to {instance_dir} failed with exit code {result.returncode}"
        )

    copied: list[Path] = []
    for src_path in sorted(assets_dir.rglob("*")):
        if src_path.is_dir():
            continue
        relative = src_path.relative_to(assets_dir)
        dest_path = instance_dir / relative
        copied.append(dest_path)
        substitute_file(dest_path, context)
    return copied
