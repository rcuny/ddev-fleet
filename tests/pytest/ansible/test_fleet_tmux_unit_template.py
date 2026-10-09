"""Rendered-content checks for fleet-tmux.service.j2 (FLE-6).

The unit owns the `fleet` tmux server in its own cgroup so that
`systemctl restart fleet` never kills operators' panes. These pin the
systemd semantics that make that true."""

import re
import shutil
import subprocess
from pathlib import Path

import pytest
from jinja2 import Environment, FileSystemLoader

TEMPLATE_DIR = (
    Path(__file__).resolve().parents[3] / "ansible" / "roles" / "fleet_service" / "templates"
)
TASKS = Path(__file__).resolve().parents[3] / "ansible" / "roles" / "fleet_service" / "tasks"


def _render(**overrides) -> str:
    env = Environment(loader=FileSystemLoader(str(TEMPLATE_DIR)), keep_trailing_newline=True)
    values = dict(fleet_user="fleet", fleet_opt_dir="/opt/ddev-fleet", fleet_srv_dir="/srv/fleet")
    values.update(overrides)
    return env.get_template("fleet-tmux.service.j2").render(**values)


def _directives(out: str) -> list[str]:
    return [ln for ln in out.splitlines() if ln.strip() and not ln.lstrip().startswith("#")]


def test_oneshot_remain_after_exit_keeps_the_tmux_server_alive():
    lines = _directives(_render())
    assert "Type=oneshot" in lines
    # Without RemainAfterExit the exit of the short-lived `fleet tmux --ensure`
    # would end the unit and SIGTERM the tmux server left in its cgroup.
    assert "RemainAfterExit=yes" in lines


def test_execstart_ensures_session_via_venv_entrypoint_non_interactively():
    lines = _directives(_render())
    exec_start = next(ln for ln in lines if ln.startswith("ExecStart="))
    assert exec_start == "ExecStart=/opt/ddev-fleet/venv/bin/fleet tmux --ensure"


def test_execstop_kills_the_fleet_session_tolerating_no_server():
    lines = _directives(_render())
    assert "ExecStop=-/usr/bin/tmux kill-session -t fleet" in lines


def test_identity_workdir_and_env_like_the_other_units():
    lines = _directives(_render())
    assert "User=fleet" in lines
    assert "WorkingDirectory=/opt/ddev-fleet" in lines
    assert "EnvironmentFile=/srv/fleet/.secrets" in lines


def test_ordered_after_fleet_service_only_not_after_fleet_boot_or_multi_user():
    lines = _directives(_render())
    assert "After=fleet.service" in lines
    joined = "\n".join(lines)
    assert "fleet-boot" not in joined
    assert not any(re.match(r"Before\s*=", ln) for ln in lines)
    assert not any("multi-user.target" in ln for ln in lines if ln.startswith("After"))


def test_wanted_by_multi_user_target():
    assert "WantedBy=multi-user.target" in _directives(_render())


def test_no_sandboxing_that_panes_would_inherit():
    lines = _directives(_render())
    for forbidden in ("NoNewPrivileges", "ProtectSystem", "PrivateTmp", "ReadWritePaths"):
        assert not any(ln.startswith(forbidden) for ln in lines), forbidden


def test_template_vars_are_substituted():
    out = _render(fleet_user="svc", fleet_opt_dir="/x/opt", fleet_srv_dir="/x/srv")
    assert "{{" not in out
    assert "User=svc" in out and "/x/opt/venv/bin/fleet tmux --ensure" in out


def test_role_deploys_enables_and_starts_the_unit():
    text = (TASKS / "main.yml").read_text(encoding="utf-8")
    assert "src: fleet-tmux.service.j2" in text
    assert "dest: /etc/systemd/system/fleet-tmux.service" in text
    assert "name: fleet-tmux.service" in text
    assert "state: started" in text
    assert "fleet_tmux" in text


@pytest.mark.skipif(shutil.which("systemd-analyze") is None, reason="systemd-analyze not installed")
def test_systemd_analyze_verify_accepts_the_rendered_unit(tmp_path):
    opt = tmp_path / "opt"
    (opt / "venv" / "bin").mkdir(parents=True)
    fleet_bin = opt / "venv" / "bin" / "fleet"
    fleet_bin.write_text("#!/bin/sh\n")
    fleet_bin.chmod(0o755)
    srv = tmp_path / "srv"
    srv.mkdir()
    (srv / ".secrets").write_text("")
    unit = tmp_path / "fleet-tmux.service"
    unit.write_text(_render(fleet_user="root", fleet_opt_dir=str(opt), fleet_srv_dir=str(srv)))
    # CI runners may use umask 000; systemd warns (on stderr) about world-writable units.
    unit.chmod(0o644)

    result = subprocess.run(
        ["systemd-analyze", "verify", str(unit)], capture_output=True, text=True, timeout=60
    )
    assert result.stderr.strip() == "" and result.returncode == 0, result.stderr
