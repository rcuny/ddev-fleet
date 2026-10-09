"""FLE-1: systemd sandboxing of fleet.service / fleet-boot.service /
fleet-reboot-notify.service and the Caddy drop-in, plus the
`systemd-analyze security` check in the security_hardening role.

Templates are rendered the way Ansible's template module does (trim_blocks).
Where `systemd-analyze` exists the rendered units are scored offline; the
sandbox must stay at or under 2.5 (systemd threshold 25)."""

import os
import re
import shutil
import subprocess
from pathlib import Path

import pytest
import yaml
from jinja2 import Environment, FileSystemLoader

ROLES = Path(__file__).resolve().parents[3] / "ansible" / "roles"
FLEET_TEMPLATES = ROLES / "fleet_service" / "templates"
CADDY_ROLE = ROLES / "caddy"
HARDENING = ROLES / "security_hardening"

VARS = dict(
    fleet_user="fleet",
    fleet_opt_dir="/opt/ddev-fleet",
    fleet_srv_dir="/srv/fleet",
    fleet_home_dir="/home/fleet",
    fleet_caddy_snippet_dir="/etc/caddy/fleet",
    fleet_daemon_port=8765,
    fleet_reboot_notify_interval_hours=24,
)

THRESHOLD = 2.5

FLEET_REQUIRED = [
    "NoNewPrivileges=yes",
    "ProtectSystem=strict",
    "ProtectHome=read-only",
    "CapabilityBoundingSet=",
    "RestrictSUIDSGID=yes",
    "LockPersonality=yes",
    "RestrictRealtime=yes",
    "ProtectClock=yes",
    "ProtectKernelTunables=yes",
    "ProtectKernelModules=yes",
    "ProtectKernelLogs=yes",
    "ProtectControlGroups=yes",
    "ProtectHostname=yes",
    "RestrictNamespaces=yes",
    "SystemCallArchitectures=native",
    "SystemCallFilter=@system-service",
    "SystemCallErrorNumber=EPERM",
    "RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6 AF_NETLINK",
    "MemoryDenyWriteExecute=yes",
    "PrivateDevices=yes",
    "ProtectProc=invisible",
]
# Each of these breaks the daemon (see fleet-sandbox.inc.j2 / memory
# fleet-daemon-systemd-sandbox) — they must never creep in.
FLEET_FORBIDDEN = ["PrivateTmp", "ProcSubset", "PrivateUsers", "UMask", "RemoveIPC"]

CADDY_REQUIRED = [
    "NoNewPrivileges=yes",
    "CapabilityBoundingSet=CAP_NET_BIND_SERVICE CAP_NET_ADMIN",
    "AmbientCapabilities=CAP_NET_BIND_SERVICE CAP_NET_ADMIN",
    "ProtectSystem=strict",
    "ReadWritePaths=/var/lib/caddy",
    "ProtectHome=yes",
    "PrivateTmp=yes",
    "PrivateDevices=yes",
    "ProtectClock=yes",
    "ProtectKernelTunables=yes",
    "ProtectKernelModules=yes",
    "ProtectKernelLogs=yes",
    "ProtectControlGroups=yes",
    "ProtectHostname=yes",
    "ProtectProc=invisible",
    "RestrictNamespaces=yes",
    "LockPersonality=yes",
    "MemoryDenyWriteExecute=yes",
    "RestrictRealtime=yes",
    "RestrictSUIDSGID=yes",
    "RemoveIPC=yes",
    "SystemCallArchitectures=native",
    "SystemCallFilter=@system-service",
    "SystemCallFilter=~@privileged @resources",
    "SystemCallErrorNumber=EPERM",
    "RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6 AF_NETLINK",
    "UMask=0027",
]

# Stand-in for the Debian caddy package's vendor unit (/usr/lib/systemd/system/caddy.service).
CADDY_VENDOR_UNIT = """\
[Unit]
Description=Caddy
After=network.target network-online.target
Requires=network.target

[Service]
Type=notify
User=caddy
Group=caddy
ExecStart=/usr/bin/caddy run --environ --config /etc/caddy/Caddyfile
ExecReload=/usr/bin/caddy reload --config /etc/caddy/Caddyfile --force
TimeoutStopSec=5s
LimitNOFILE=1048576
PrivateTmp=true
ProtectSystem=full
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
"""

needs_analyze = pytest.mark.skipif(
    shutil.which("systemd-analyze") is None, reason="systemd-analyze not installed"
)


def test_systemd_analyze_is_present_when_the_pipeline_requires_it():
    # The scoring tests below skip without systemd-analyze. CI (Debian 13 image
    # with systemd) sets FLEET_REQUIRE_SYSTEMD_ANALYZE=1 so a missing binary
    # fails the run instead of silently skipping the sandbox checks (FLE-8).
    if os.environ.get("FLEET_REQUIRE_SYSTEMD_ANALYZE"):
        assert shutil.which(
            "systemd-analyze"
        ), "FLEET_REQUIRE_SYSTEMD_ANALYZE is set but systemd-analyze is not installed"


def _render(role_dir: Path, name: str, **overrides) -> str:
    # trim_blocks=True: what Ansible's template module uses.
    env = Environment(
        loader=FileSystemLoader(str(role_dir / "templates")),
        trim_blocks=True,
        keep_trailing_newline=True,
    )
    return env.get_template(name).render(**{**VARS, **overrides})


def _fleet(**kw) -> str:
    return _render(ROLES / "fleet_service", "fleet.service.j2", **kw)


def _boot(**kw) -> str:
    return _render(ROLES / "fleet_service", "fleet-boot.service.j2", **kw)


def _notify(**kw) -> str:
    return _render(HARDENING, "fleet-reboot-notify.service.j2", **kw)


def _caddy_dropin(**kw) -> str:
    return _render(CADDY_ROLE, "caddy-sandbox.conf.j2", **kw)


def _directives(out: str) -> list[str]:
    return [ln for ln in out.splitlines() if ln.strip() and not ln.lstrip().startswith("#")]


def _names(lines: list[str]) -> set[str]:
    return {ln.split("=", 1)[0] for ln in lines if "=" in ln}


def _value(lines: list[str], key: str) -> str:
    return next(ln.split("=", 1)[1] for ln in lines if ln.startswith(key + "="))


# --- fleet.service / fleet-boot.service ------------------------------------


@pytest.mark.parametrize("render", [_fleet, _boot], ids=["fleet", "fleet-boot"])
def test_daemon_units_carry_the_full_sandbox(render):
    lines = _directives(render())
    for required in FLEET_REQUIRED:
        assert required in lines, required
    rw = _value(lines, "ReadWritePaths").split()
    assert rw == ["/srv/fleet", "/etc/caddy/fleet", "/home/fleet", "/tmp", "/var/tmp"]


@pytest.mark.parametrize("render", [_fleet, _boot], ids=["fleet", "fleet-boot"])
def test_daemon_units_never_set_the_directives_that_break_them(render):
    names = _names(_directives(render()))
    for forbidden in FLEET_FORBIDDEN:
        assert forbidden not in names, forbidden


def test_fleet_service_uses_the_configured_paths():
    out = _fleet(
        fleet_srv_dir="/x/srv", fleet_caddy_snippet_dir="/x/caddy", fleet_home_dir="/x/home"
    )
    assert "ReadWritePaths=/x/srv /x/caddy /x/home /tmp /var/tmp" in out
    assert "{{" not in out and "{%" not in out


def test_boot_unit_keeps_its_existing_content():
    out = _boot()
    lines = _directives(out)
    assert "Type=exec" in lines and "RemainAfterExit=no" in lines
    assert any(ln.startswith("ExecStart=") and "start --all --sequential" in ln for ln in lines)
    assert "FLE-4" in out  # the long explanatory comments survive


def test_sandbox_is_the_same_block_in_both_daemon_units():
    sandbox_names = {r.split("=", 1)[0] for r in FLEET_REQUIRED} | {"ReadWritePaths"}

    def block(out):
        return [ln for ln in _directives(out) if ln.split("=", 1)[0] in sandbox_names]

    assert block(_fleet()) == block(_boot())


def test_fleet_service_toggle_off_renders_the_pre_fle1_directives():
    out = _fleet(fleet_systemd_sandbox_enabled=False)
    lines = _directives(out)
    assert "NoNewPrivileges=yes" in lines
    assert "ProtectSystem=full" in lines
    assert "ReadWritePaths=/srv/fleet /etc/caddy/fleet" in lines
    hardening = {"ProtectSystem", "ReadWritePaths", "NoNewPrivileges"}
    extra = {
        n
        for n in _names(lines)
        if n
        not in {
            "Description",
            "After",
            "Requires",
            "User",
            "WorkingDirectory",
            "EnvironmentFile",
            "ExecStart",
            "Restart",
            "WantedBy",
        }
        | hardening
    }
    assert extra == set()


def test_boot_unit_toggle_off_has_no_sandbox_at_all():
    names = _names(_directives(_boot(fleet_systemd_sandbox_enabled=False)))
    for n in ("ProtectSystem", "ReadWritePaths", "NoNewPrivileges", "SystemCallFilter"):
        assert n not in names, n


def test_toggle_defaults_to_on_when_the_var_is_undefined():
    # VARS deliberately has no fleet_systemd_sandbox_enabled.
    assert "ProtectSystem=strict" in _directives(_fleet())
    assert "ProtectSystem=strict" in _directives(_boot())
    assert "ProtectSystem=strict" in _directives(_notify())


def test_group_vars_turn_the_sandbox_on_by_default():
    gv = yaml.safe_load((ROLES.parent / "group_vars" / "all.yml").read_text(encoding="utf-8"))
    assert gv["fleet_systemd_sandbox_enabled"] is True


def test_fleet_tmux_unit_stays_unsandboxed():
    env = Environment(loader=FileSystemLoader(str(FLEET_TEMPLATES)), trim_blocks=True)
    out = env.get_template("fleet-tmux.service.j2").render(**VARS)
    assert "ProtectSystem" not in "\n".join(_directives(out))


# --- fleet-reboot-notify.service -------------------------------------------


def test_reboot_notify_sandbox():
    lines = _directives(_notify())
    assert _value(lines, "ReadWritePaths") == "/srv/fleet"
    assert "PrivateTmp=yes" in lines
    assert "RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6" in lines
    assert "Type=oneshot" in lines and "User=fleet" in lines
    for required in FLEET_REQUIRED:
        if required.startswith(("RestrictAddressFamilies", "ReadWritePaths")):
            continue
        assert required in lines, required


def test_reboot_notify_stays_in_step_with_the_daemon_sandbox():
    # The two blocks live in different roles (no cross-role include), so pin
    # that they differ only where intended.
    notify = set(_directives(_notify()))
    fleet = set(_directives(_fleet()))
    sandbox = {r for r in FLEET_REQUIRED}
    assert (sandbox & fleet) - notify == {
        "RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6 AF_NETLINK"
    }
    assert notify - fleet >= {"PrivateTmp=yes", "ReadWritePaths=/srv/fleet"}


def test_reboot_notify_toggle_off_is_the_pre_fle1_unit():
    lines = _directives(_notify(fleet_systemd_sandbox_enabled=False))
    assert lines == [
        "[Unit]",
        "Description=fleet reboot-required notification (spec §6.3)",
        "After=network.target",
        "[Service]",
        "Type=oneshot",
        "User=fleet",
        "NoNewPrivileges=yes",
        "ExecStart=/opt/ddev-fleet/venv/bin/fleet reboot-notify --interval-hours 24",
    ]


# --- Caddy drop-in ---------------------------------------------------------


def test_caddy_dropin_directives():
    out = _caddy_dropin()
    lines = _directives(out)
    assert lines[0] == "[Service]"
    assert lines[1:] == CADDY_REQUIRED
    assert "ReadWritePaths" in out and "logs to files" in out


def _caddy_tasks() -> dict[str, dict]:
    tasks = yaml.safe_load((CADDY_ROLE / "tasks" / "main.yml").read_text(encoding="utf-8"))
    return {t["name"]: t for t in tasks if "name" in t}


def test_caddy_role_installs_the_dropin_when_on_and_removes_it_when_off():
    tasks = _caddy_tasks()
    install = next(t for n, t in tasks.items() if "sandbox drop-in (FLE-1)" in n)
    remove = next(t for n, t in tasks.items() if n.startswith("Remove the caddy.service sandbox"))
    dest = "/etc/systemd/system/caddy.service.d/50-fleet-sandbox.conf"
    assert install["ansible.builtin.template"]["src"] == "caddy-sandbox.conf.j2"
    assert install["ansible.builtin.template"]["dest"] == dest
    assert remove["ansible.builtin.file"] == {"path": dest, "state": "absent"}
    assert "fleet_systemd_sandbox_enabled" in install["when"]
    assert "not" in remove["when"] and "fleet_systemd_sandbox_enabled" in remove["when"]
    for t in (install, remove):
        assert t["notify"] == ["Reload systemd daemon", "Restart caddy"]


def test_caddy_role_handlers_exist():
    handlers = yaml.safe_load((CADDY_ROLE / "handlers" / "main.yml").read_text(encoding="utf-8"))
    names = [h["name"] for h in handlers]
    assert names.index("Reload systemd daemon") < names.index("Restart caddy")


# --- offline scoring with systemd-analyze ----------------------------------


def _score(output: str) -> float:
    m = re.search(r"Overall exposure level for \S+: ([0-9.]+)", output)
    assert m, output
    return float(m.group(1))


def _analyze(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["systemd-analyze", "security", "--offline=yes", "--no-pager", *args],
        capture_output=True,
        text=True,
        timeout=120,
    )


@needs_analyze
@pytest.mark.parametrize(
    "name,render",
    [
        ("fleet.service", _fleet),
        ("fleet-boot.service", _boot),
        ("fleet-reboot-notify.service", _notify),
    ],
)
def test_rendered_units_score_under_the_threshold(tmp_path, name, render):
    unit = tmp_path / name
    unit.write_text(render())
    result = _analyze("--threshold=25", str(unit))
    assert result.returncode == 0, result.stdout + result.stderr
    assert _score(result.stdout) <= THRESHOLD


@needs_analyze
def test_legacy_fleet_service_would_fail_the_threshold(tmp_path):
    # Negative control: proves the scoring above actually measures the sandbox.
    unit = tmp_path / "fleet.service"
    unit.write_text(_fleet(fleet_systemd_sandbox_enabled=False))
    result = _analyze("--threshold=25", str(unit))
    assert result.returncode != 0
    assert _score(result.stdout) > THRESHOLD


def _caddy_root(tmp_path: Path, with_dropin: bool) -> Path:
    root = tmp_path / ("root-on" if with_dropin else "root-off")
    system = root / "etc" / "systemd" / "system"
    system.mkdir(parents=True)
    (system / "caddy.service").write_text(CADDY_VENDOR_UNIT)
    if with_dropin:
        dropin_dir = system / "caddy.service.d"
        dropin_dir.mkdir()
        (dropin_dir / "50-fleet-sandbox.conf").write_text(_caddy_dropin())
    return root


@needs_analyze
def test_caddy_dropin_on_top_of_the_vendor_unit_scores_under_the_threshold(tmp_path):
    root = _caddy_root(tmp_path, with_dropin=True)
    result = _analyze("--threshold=25", f"--root={root}", "caddy.service")
    assert result.returncode == 0, result.stdout + result.stderr
    assert _score(result.stdout) <= THRESHOLD


@needs_analyze
def test_vendor_caddy_unit_alone_fails_the_threshold(tmp_path):
    root = _caddy_root(tmp_path, with_dropin=False)
    result = _analyze("--threshold=25", f"--root={root}", "caddy.service")
    assert result.returncode != 0
    assert _score(result.stdout) > THRESHOLD


# --- the systemd-analyze security check in security_hardening --------------


def _walk(tasks):
    for t in tasks:
        yield t
        yield from _walk(t.get("block", []))


def test_security_check_defaults():
    d = yaml.safe_load((HARDENING / "defaults" / "main.yml").read_text(encoding="utf-8"))
    assert d["fleet_systemd_security_check_enabled"] is True
    assert d["fleet_systemd_security_enforce"] is False
    assert d["fleet_systemd_security_thresholds"] == {
        "fleet.service": 25,
        "fleet-boot.service": 25,
        "fleet-reboot-notify.service": 25,
        "caddy.service": 25,
        "authelia.service": 35,
    }


def test_security_check_tasks_are_wired_up():
    tasks = list(_walk(yaml.safe_load((HARDENING / "tasks" / "harden.yml").read_text("utf-8"))))
    # The check is the last thing the role does.
    last = yaml.safe_load((HARDENING / "tasks" / "harden.yml").read_text("utf-8"))[-1]
    assert "fleet_systemd_security_check_enabled" in last["when"]
    text = yaml.safe_dump(last)
    assert "systemd-analyze security" in text
    assert "--threshold=" in text
    assert "fleet_systemd_security_thresholds" in text
    assert "fleet_systemd_security_enforce" in text
    assert "list-unit-files" in text  # skips units that aren't installed
    scorer = next(
        t
        for t in tasks
        if "ansible.builtin.command" in t and "systemd-analyze" in str(t["ansible.builtin.command"])
    )
    assert scorer["changed_when"] is False and scorer["failed_when"] is False
    fail = next(t for t in tasks if "ansible.builtin.fail" in t)
    assert any("fleet_systemd_security_enforce" in c for c in fail["when"])
    assert "fleet_systemd_sandbox_enabled" in text  # the warning names the toggle
