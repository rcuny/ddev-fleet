"""Asset mirror copy (rsync wrapper) + token substitution pass (spec §7)."""

import os
import shutil
from pathlib import Path

from fleet.core.errors import FleetError
from fleet.core.runner import run_streamed
from fleet.core.tokens import substitute_file

# DB dumps live under this well-known subdirectory of a project's asset tree
# (e.g. `dumps/default.sql`, per each project's own DDEV import command
# convention) and can be multi-GB. inject() excludes it from the rsync mirror
# and hard-links its files into the instance instead (see _link_shared_dir),
# so a dump is never duplicated on disk per instance.
_SHARED_DIR_NAME = "dumps"


def inject(
    assets_dir: Path, instance_dir: Path, context: dict[str, str], *, runner=run_streamed
) -> list[Path]:
    if not assets_dir.exists():
        return []

    instance_dir.mkdir(parents=True, exist_ok=True)
    result = runner(
        [
            "rsync",
            "-a",
            f"--exclude=/{_SHARED_DIR_NAME}/",
            str(assets_dir) + "/",
            str(instance_dir) + "/",
        ]
    )
    if result.returncode != 0:
        raise FleetError(
            f"rsync from {assets_dir} to {instance_dir} failed with exit code {result.returncode}"
        )

    copied: list[Path] = []
    for src_path in sorted(assets_dir.rglob("*")):
        if src_path.is_dir():
            continue
        relative = src_path.relative_to(assets_dir)
        if relative.parts[0] == _SHARED_DIR_NAME:
            # Handled below by _link_shared_dir(); never rsync-copied or
            # token-substituted (substituting in place would rewrite the
            # shared inode backing every instance — see its docstring).
            continue
        dest_path = instance_dir / relative
        copied.append(dest_path)
        substitute_file(dest_path, context)

    copied.extend(_link_shared_dir(assets_dir / _SHARED_DIR_NAME, instance_dir / _SHARED_DIR_NAME))
    return copied


def _link_shared_dir(src_dir: Path, dest_dir: Path) -> list[Path]:
    """Make `src_dir`'s files appear under `dest_dir` via hard links instead
    of copies, so multi-GB DB dumps are never duplicated per instance.

    A hard link is a second directory entry for the SAME inode/data blocks —
    from any reader (host or, critically, INSIDE the DDEV web container,
    since the whole instance_dir is already bind-mounted there) it is a
    completely ordinary file, indistinguishable from a byte-for-byte copy.
    This is what makes it safe regardless of whether the project's own
    DB-import step (a `.ddev/commands/**` script, fleet does not own or run
    it directly) executes on the host or inside the container: unlike a
    symlink pointing outside instance_dir — which would dangle inside the
    container, because DDEV only bind-mounts instance_dir itself, not the
    shared config-repo checkout the dump actually lives in — a hard link
    needs no extra container mount surface at all.

    Falls back to a real copy if src/dest end up on different filesystems
    (hard links can't cross devices, e.g. EXDEV) so a dump is still usable
    rather than silently missing.

    Never runs token substitution on these files (see inject()): a dump can
    be gigabytes, and worse, a small dump would pass the substitution size
    check while still being a shared inode — rewriting it in place would
    corrupt the one shared copy backing every instance.

    Idempotent: a file already correctly hard-linked (same inode as the
    source) is left alone. A stale file at the destination (e.g. a full copy
    left over from before this change, or from a prior fleet version) is
    replaced with a fresh hard link on the next deploy — this is how
    existing instances reclaim their duplicated dump space over time,
    without any separate migration step.
    """
    if not src_dir.exists():
        return []

    linked: list[Path] = []
    for src_file in sorted(src_dir.rglob("*")):
        if src_file.is_dir():
            continue
        relative = src_file.relative_to(src_dir)
        dest_file = dest_dir / relative
        dest_file.parent.mkdir(parents=True, exist_ok=True)

        if dest_file.exists() or dest_file.is_symlink():
            try:
                same_inode = dest_file.stat().st_ino == src_file.stat().st_ino
            except OSError:
                same_inode = False
            if same_inode:
                linked.append(dest_file)
                continue
            dest_file.unlink()  # stale copy (or wrong link) from a prior deploy

        try:
            os.link(src_file, dest_file)
        except OSError:
            shutil.copy2(src_file, dest_file)
        linked.append(dest_file)

    return linked


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
