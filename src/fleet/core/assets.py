"""Asset mirror copy (rsync wrapper) + token substitution pass (spec §7)."""

import shutil
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


def push(assets_dir: Path, src: Path, dest_rel: str) -> Path:
    if not src.exists():
        raise FleetError(f"asset source not found: {src}")

    assets_root = assets_dir.resolve()
    dest = (assets_dir / dest_rel).resolve()
    if not dest.is_relative_to(assets_root):
        raise FleetError(f"{dest_rel!r} escapes the asset tree {assets_dir}")

    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dest)
    return dest
