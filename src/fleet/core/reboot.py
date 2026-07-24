"""Single shared reader for Debian's reboot-required marker
(`/var/run/reboot-required` + `.pkgs`), plus the anti-spam notification
cadence and msmtp email send backing `fleet reboot-notify [--test]`
(spec: 2026-07-24-fleet-security-hardening-design.md §6.3/§6.4).

Three consumers, one reader: `tmux_sidebar.py` (sidebar banner),
`core/sysinfo.py` (web UI footer badge), and this module's own
`reboot_notify()` (email). Nothing else re-implements the read.
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

MARKER_PATH = Path("/var/run/reboot-required")
PKGS_PATH = Path("/var/run/reboot-required.pkgs")
DEFAULT_STATE_PATH = Path("/srv/fleet/reboot-notify-state.json")
DEFAULT_MSMTPRC_PATH = Path("/srv/fleet/msmtprc")


@dataclass(frozen=True)
class RebootStatus:
    pending: bool
    since: float | None  # marker file mtime, epoch seconds; None if not pending
    packages: list[str]  # from reboot-required.pkgs; empty if unreadable/absent


def read_reboot_status(
    marker: Path = MARKER_PATH,
    pkgs_file: Path = PKGS_PATH,
) -> RebootStatus:
    """Returns pending=False whenever the marker is absent — the common,
    expected state, never an error. Needs no elevated privilege: both
    files are world-readable."""
    try:
        since = marker.stat().st_mtime
    except OSError:
        return RebootStatus(pending=False, since=None, packages=[])

    try:
        packages = [
            line.strip()
            for line in pkgs_file.read_text(encoding="utf-8").splitlines()
            if line.strip()
        ]
    except OSError:
        packages = []

    return RebootStatus(pending=True, since=since, packages=packages)
