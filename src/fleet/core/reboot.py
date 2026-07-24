"""Single shared reader for Debian's reboot-required marker
(`/var/run/reboot-required` + `.pkgs`), plus the anti-spam notification
cadence and msmtp email send backing `fleet reboot-notify [--test]`
(spec: 2026-07-24-fleet-security-hardening-design.md §6.3/§6.4).

Three consumers, one reader: `tmux_sidebar.py` (sidebar banner),
`core/sysinfo.py` (web UI footer badge), and this module's own
`reboot_notify()` (email). Nothing else re-implements the read.
"""

from __future__ import annotations

import json
import time
from dataclasses import dataclass
from pathlib import Path

from fleet.core.runner import run_streamed

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


def format_duration_since(since: float, *, now: float | None = None) -> str:
    """Human-readable elapsed time, e.g. '3d 4h'; '<1h' under an hour.
    The single shared formatter — both the tmux sidebar banner and the
    web UI footer badge call this, so the two never drift."""
    now = now if now is not None else time.time()
    seconds = max(0.0, now - since)
    days, rem = divmod(int(seconds), 86400)
    hours, _ = divmod(rem, 3600)
    if days == 0 and hours == 0:
        return "<1h"
    if days == 0:
        return f"{hours}h"
    return f"{days}d {hours}h"


@dataclass(frozen=True)
class NotifyState:
    first_seen: float
    last_notified: float


def _read_state(path: Path) -> NotifyState | None:
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    try:
        return NotifyState(
            first_seen=float(raw["first_seen"]), last_notified=float(raw["last_notified"])
        )
    except (KeyError, TypeError, ValueError):
        return None


def _write_state(path: Path, state: NotifyState) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps({"first_seen": state.first_seen, "last_notified": state.last_notified}),
        encoding="utf-8",
    )


def _clear_state(path: Path) -> None:
    try:
        path.unlink()
    except FileNotFoundError:
        pass


def compose_email(status: RebootStatus, *, hostname: str, now: float) -> tuple[str, str]:
    since_human = (
        format_duration_since(status.since, now=now) if status.since is not None else "unknown"
    )
    subject = f"[ddev-fleet] reboot required on {hostname}"
    pkg_list = "\n".join(f"  - {p}" for p in status.packages) or "  (package list unavailable)"
    body = (
        f"A reboot is required on {hostname}.\n\n"
        f"Pending for: {since_human}\n"
        f"Triggering packages:\n{pkg_list}\n\n"
        "To reboot: sudo reboot\n"
    )
    return subject, body


def send_email(
    subject: str,
    body: str,
    *,
    to_addr: str,
    from_addr: str,
    msmtprc_path: Path = DEFAULT_MSMTPRC_PATH,
    runner=run_streamed,
) -> bool:
    """Send via msmtp using the fleet-owned msmtprc. Never raises — the
    reboot-notify pipeline is best-effort; a bad relay must never crash
    the caller. Returns False (no-op, not an error) if msmtprc or a
    recipient is missing."""
    if not msmtprc_path.exists() or not to_addr:
        return False
    message = f"Subject: {subject}\nFrom: {from_addr}\nTo: {to_addr}\n\n{body}"
    result = runner(
        ["msmtp", "--file", str(msmtprc_path), "-a", "default", to_addr],
        input_text=message,
        echo=False,
    )
    return result.returncode == 0
