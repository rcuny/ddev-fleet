"""FLE-73: install/etc/fleet-tmux.service owns the `fleet` tmux server in its own cgroup.

These pin the systemd semantics that keep the panes alive across restarts of php-fpm and the workers."""

import shutil
import subprocess
from pathlib import Path

import pytest

UNIT = Path(__file__).resolve().parents[3] / "install" / "etc" / "fleet-tmux.service"


def _directives() -> list[str]:
    return [
        ln.strip()
        for ln in UNIT.read_text(encoding="utf-8").splitlines()
        if ln.strip() and not ln.lstrip().startswith("#")
    ]


def test_oneshot_remain_after_exit_keeps_the_tmux_server_alive():
    lines = _directives()
    assert "Type=oneshot" in lines
    # Without RemainAfterExit the exit of the short-lived `fleet:tmux:ensure` would end the unit and
    # SIGTERM the tmux server it left in the cgroup.
    assert "RemainAfterExit=yes" in lines


def test_execstart_runs_the_ensure_command_as_the_fleet_user():
    lines = _directives()
    assert (
        "ExecStart=/usr/bin/php8.4 /opt/ddev-fleet/app/bin/console fleet:tmux:ensure" in lines
    )
    assert "User=fleet" in lines
    assert "EnvironmentFile=/etc/ddev-fleet/fleet.env" in lines


def test_execstop_ends_only_the_fleet_session_and_tolerates_no_server():
    assert "ExecStop=-/usr/bin/tmux -L fleet kill-session -t fleet" in _directives()


def test_not_sandboxed_because_the_panes_inherit_the_unit():
    lines = _directives()
    for forbidden in ("NoNewPrivileges", "ProtectSystem", "PrivateTmp", "ReadWritePaths"):
        assert not any(ln.startswith(forbidden) for ln in lines), forbidden


def test_wanted_by_multi_user_target():
    assert "WantedBy=multi-user.target" in _directives()


@pytest.mark.skipif(shutil.which("systemd-analyze") is None, reason="systemd-analyze not installed")
def test_systemd_analyze_accepts_the_unit_syntax(tmp_path):
    # The real unit points at /opt and /usr/bin paths that do not exist on a CI runner: `verify` reports those,
    # so only the "unknown key / bad value" classes of message count here.
    unit = tmp_path / "fleet-tmux.service"
    unit.write_text(UNIT.read_text(encoding="utf-8"), encoding="utf-8")
    unit.chmod(0o644)
    result = subprocess.run(
        ["systemd-analyze", "verify", str(unit)], capture_output=True, text=True, timeout=60
    )
    for bad in ("Unknown key", "Unknown section", "Failed to parse", "Invalid"):
        assert bad not in result.stderr, result.stderr
