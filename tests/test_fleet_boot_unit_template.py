import re
from pathlib import Path

from jinja2 import Environment, FileSystemLoader

TEMPLATE_DIR = (
    Path(__file__).resolve().parents[1] / "ansible" / "roles" / "fleet_service" / "templates"
)


def _render() -> str:
    env = Environment(loader=FileSystemLoader(str(TEMPLATE_DIR)), keep_trailing_newline=True)
    return env.get_template("fleet-boot.service.j2").render(
        fleet_user="fleet",
        fleet_opt_dir="/opt/ddev-fleet",
        fleet_srv_dir="/srv/fleet",
    )


def _directives(out: str) -> list[str]:
    return [ln for ln in out.splitlines() if ln.strip() and not ln.lstrip().startswith("#")]


def test_fleet_boot_is_type_exec_not_oneshot():
    # FLE-4: oneshot kept multi-user.target (and thus Authelia) waiting for the whole batch.
    lines = _directives(_render())
    assert "Type=exec" in lines
    assert not any(ln.startswith("Type=oneshot") for ln in lines)


def test_fleet_boot_has_no_before_ordering():
    assert not any(re.match(r"Before\s*=", ln) for ln in _directives(_render()))


def test_fleet_boot_wanted_by_multi_user_target():
    assert "WantedBy=multi-user.target" in _directives(_render())


def test_fleet_boot_execstart_and_user():
    lines = _directives(_render())
    assert "User=fleet" in lines
    exec_start = next(ln for ln in lines if ln.startswith("ExecStart="))
    assert exec_start.startswith("ExecStart=/opt/ddev-fleet/venv/bin/fleet ")
    assert "fleet start --all --sequential --timeout 1800 --retry-port-conflict" in exec_start
