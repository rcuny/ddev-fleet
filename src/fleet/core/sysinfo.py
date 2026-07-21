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

    @classmethod
    def gather(cls, instances_dir: Path, *, meminfo_path: Path = _MEMINFO) -> "SystemStats":
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
        )

    def display(self) -> dict[str, str]:
        """Pre-formatted, template-ready strings."""
        return {
            "mem_free": fmt_bytes(self.mem_available_bytes),
            "mem_total": fmt_bytes(self.mem_total_bytes),
            "disk_free": fmt_bytes(self.disk_free_bytes),
            "disk_total": fmt_bytes(self.disk_total_bytes),
            "mount": self.mount,
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
