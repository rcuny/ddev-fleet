"""Host system stats shown in the web UI footer: free memory and free disk
space on the filesystem that holds the fleet instances.

Only the instances mount matters here (that's where deploys consume disk); we
deliberately don't report on any other mount. Memory comes from
``/proc/meminfo`` (Linux); when it's unreadable (e.g. a non-Linux dev box) the
memory fields are ``None`` and render as ``n/a`` rather than raising.
"""

import shutil
from dataclasses import dataclass
from pathlib import Path

from fleet.core.errors import DiskSpaceError
from fleet.core.reboot import (
    MARKER_PATH,
    PKGS_PATH,
    RebootStatus,
    format_duration_since,
    read_reboot_status,
)

_MEMINFO = Path("/proc/meminfo")


def _parse_meminfo(text: str) -> tuple[int | None, int | None]:
    """Return ``(available_bytes, total_bytes)`` parsed from /proc/meminfo
    text. Values there are in kB (1024 bytes). Missing keys yield ``None``."""
    fields: dict[str, int] = {}
    for line in text.splitlines():
        key, _, rest = line.partition(":")
        parts = rest.split()
        if parts and parts[0].isdigit():
            fields[key.strip()] = int(parts[0]) * 1024
    return fields.get("MemAvailable"), fields.get("MemTotal")


def _read_meminfo(meminfo_path: Path = _MEMINFO) -> tuple[int | None, int | None]:
    try:
        return _parse_meminfo(meminfo_path.read_text(encoding="utf-8"))
    except OSError:
        return None, None


@dataclass(frozen=True)
class SystemStats:
    mem_available_bytes: int | None
    mem_total_bytes: int | None
    disk_free_bytes: int
    disk_total_bytes: int
    mount: str
    reboot_required: RebootStatus | None = None

    @classmethod
    def gather(
        cls,
        instances_dir: Path,
        *,
        meminfo_path: Path = _MEMINFO,
        reboot_marker: Path = MARKER_PATH,
        reboot_pkgs: Path = PKGS_PATH,
    ) -> "SystemStats":
        """Free memory (host-wide) plus free/total disk on the filesystem that
        contains ``instances_dir`` — the mount where instances live."""
        avail, total = _read_meminfo(meminfo_path)
        usage = shutil.disk_usage(instances_dir)
        return cls(
            mem_available_bytes=avail,
            mem_total_bytes=total,
            disk_free_bytes=usage.free,
            disk_total_bytes=usage.total,
            mount=str(instances_dir),
            reboot_required=read_reboot_status(reboot_marker, reboot_pkgs),
        )

    def display(self) -> dict[str, str]:
        """Pre-formatted, template-ready strings."""
        status = self.reboot_required
        pending = bool(status and status.pending)
        since_human = ""
        packages_str = ""
        if pending:
            since_human = format_duration_since(status.since) if status.since else ""
            shown = status.packages[:5]
            packages_str = ", ".join(shown)
            if len(status.packages) > 5:
                packages_str += f" (+{len(status.packages) - 5} more)"
        return {
            "mem_free": fmt_bytes(self.mem_available_bytes),
            "mem_total": fmt_bytes(self.mem_total_bytes),
            "disk_free": fmt_bytes(self.disk_free_bytes),
            "disk_total": fmt_bytes(self.disk_total_bytes),
            "mount": self.mount,
            "reboot_pending": pending,
            "reboot_since_human": since_human,
            "reboot_packages": packages_str,
        }


def fmt_bytes(n: int | None) -> str:
    """Human-readable binary size, e.g. ``12.3 GiB``; ``n/a`` for ``None``."""
    if n is None:
        return "n/a"
    value = float(n)
    for unit in ("B", "KiB", "MiB", "GiB", "TiB"):
        if value < 1024 or unit == "TiB":
            return f"{int(value)} {unit}" if unit == "B" else f"{value:.1f} {unit}"
        value /= 1024
    return f"{value:.1f} PiB"  # pragma: no cover


MIN_FREE_PERCENT_DEFAULT = 10.0


def check_disk_headroom(
    instances_dir: Path, *, min_free_percent: float = MIN_FREE_PERCENT_DEFAULT
) -> None:
    """Raise DiskSpaceError if less than `min_free_percent` free on the
    filesystem holding `instances_dir`. Reuses SystemStats.gather — no new
    disk-probing logic. Scoped to multi-deploy callers only (Task 4); an
    ordinary single-instance `fleet deploy` never calls this."""
    stats = SystemStats.gather(instances_dir)
    if stats.disk_total_bytes == 0:
        return
    free_percent = 100.0 * stats.disk_free_bytes / stats.disk_total_bytes
    if free_percent < min_free_percent:
        raise DiskSpaceError(
            f"only {free_percent:.1f}% free on {instances_dir} "
            f"({fmt_bytes(stats.disk_free_bytes)} of {fmt_bytes(stats.disk_total_bytes)}) "
            f"— need at least {min_free_percent:.0f}% free; pass --skip-disk-check to override"
        )
