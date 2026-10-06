"""FLE-8: the security_probe role, the scoped playbook, and the report script.

The role's tasks are checked at YAML level (the suite does not run Ansible);
the report script (`files/fleet-security-report`) is exercised for real against
stub `systemd-analyze` / `systemctl` executables."""

from __future__ import annotations

import importlib.machinery
import importlib.util
import json
import os
import stat
import subprocess
import sys
from pathlib import Path

import pytest
import yaml
from jinja2 import Environment, FileSystemLoader

ANSIBLE = Path(__file__).resolve().parents[1] / "ansible"
ROLE = ANSIBLE / "roles" / "security_probe"
SCRIPT = ROLE / "files" / "fleet-security-report"
SNIPPET = "/etc/ssh/sshd_config.d/53fleet-security-probe.conf"


def _load_yaml(path: Path):
    return yaml.safe_load(path.read_text(encoding="utf-8"))


def _walk(tasks):
    for t in tasks:
        yield t
        for key in ("block", "rescue", "always"):
            yield from _walk(t.get(key, []))


def _tasks() -> dict[str, dict]:
    return {t["name"]: t for t in _walk(_load_yaml(ROLE / "tasks" / "main.yml"))}


def _task(fragment: str) -> dict:
    (match,) = [t for n, t in _tasks().items() if fragment in n]
    return match


def _blocks() -> tuple[dict, dict]:
    top = _load_yaml(ROLE / "tasks" / "main.yml")
    enabled = next(t for t in top if t["name"].startswith("Install the security probe"))
    disabled = next(t for t in top if t["name"].startswith("Remove the security probe"))
    return enabled, disabled


# --- role structure ----------------------------------------------------------


def test_defaults():
    d = _load_yaml(ROLE / "defaults" / "main.yml")
    assert d["fleet_security_probe_user"] == "fleet-probe"
    assert d["fleet_security_probe_home"] == "/var/lib/fleet-probe"
    assert d["fleet_security_probe_authorized_keys"] == []
    assert d["fleet_security_probe_units"] == [
        "fleet.service",
        "fleet-boot.service",
        "fleet-reboot-notify.service",
        "caddy.service",
        "authelia.service",
    ]
    assert d["fleet_security_probe_enabled"] == (
        "{{ fleet_security_probe_authorized_keys | length > 0 }}"
    )


def test_inert_by_default_and_enabled_by_keys():
    enabled, disabled = _blocks()
    assert enabled["when"] == "fleet_security_probe_enabled | bool"
    assert disabled["when"] == "not (fleet_security_probe_enabled | bool)"


def test_role_is_in_site_yml_after_security_hardening():
    roles = _load_yaml(ANSIBLE / "site.yml")[0]["roles"]
    assert roles.index("security_probe") == roles.index("security_hardening") + 1


def test_scoped_playbook_exists_beside_site_yml():
    play = _load_yaml(ANSIBLE / "security-probe.yml")[0]
    assert play["hosts"] == "localhost" and play["become"] is True
    assert play["roles"] == ["security_probe"]
    # same local-vars override as site.yml, so the keys come from the host
    assert any("/etc/ddev-fleet/local-vars.yml" in str(t) for t in play["pre_tasks"])


def test_user_is_a_no_sudo_system_account_with_a_shell_and_no_password():
    user = _task("Create the probe user (no sudo")["ansible.builtin.user"]
    assert user["name"] == "{{ fleet_security_probe_user }}"
    assert user["system"] is True and user["create_home"] is True
    assert user["shell"] == "/bin/sh"  # sshd runs the forced command through it
    assert user["password"] == "*"
    assert "groups" not in user  # no sudo/docker membership


def test_script_and_ssh_files_have_tight_ownership_and_modes():
    script = _task("Install the report script")["ansible.builtin.copy"]
    assert script["dest"] == "/usr/local/bin/fleet-security-report"
    assert (script["owner"], script["mode"]) == ("root", "0755")
    ssh_dir = _task("Create the probe user's ~/.ssh")["ansible.builtin.file"]
    keys = _task("forced-command authorized_keys")["ansible.builtin.template"]
    assert ssh_dir["mode"] == "0700" and keys["mode"] == "0600"
    for item in (ssh_dir, keys):
        assert item["owner"] == "{{ fleet_security_probe_user }}"
    assert keys["src"] == "authorized_keys.j2"


def test_script_is_executable_stdlib_only_python():
    assert SCRIPT.stat().st_mode & stat.S_IXUSR
    text = SCRIPT.read_text(encoding="utf-8")
    assert text.startswith("#!/usr/bin/env python3\n")
    imports = {
        ln.split()[1].split(".")[0]
        for ln in text.splitlines()
        if ln.startswith(("import ", "from "))
    }
    assert imports <= set(sys.stdlib_module_names) | {"__future__"}, imports


# --- authorized_keys template --------------------------------------------------


def _authorized_keys(**vars_) -> str:
    env = Environment(
        loader=FileSystemLoader(str(ROLE / "templates")),
        trim_blocks=True,
        keep_trailing_newline=True,
    )
    defaults = _load_yaml(ROLE / "defaults" / "main.yml")
    return env.get_template("authorized_keys.j2").render(**{**defaults, **vars_})


def test_every_key_is_a_restricted_forced_command():
    keys = ["ssh-ed25519 AAAAC3one bitbucket", "ssh-rsa AAAAB3two  "]
    lines = [ln for ln in _authorized_keys(fleet_security_probe_authorized_keys=keys).splitlines()]
    entries = [ln for ln in lines if not ln.startswith("#")]
    assert len(entries) == 2
    units = " ".join(_load_yaml(ROLE / "defaults" / "main.yml")["fleet_security_probe_units"])
    assert entries[0] == (
        f'restrict,command="/usr/local/bin/fleet-security-report {units}" '
        "ssh-ed25519 AAAAC3one bitbucket"
    )
    assert entries[1].endswith('" ssh-rsa AAAAB3two')  # surrounding whitespace trimmed
    assert all(e.startswith('restrict,command="') for e in entries)


def test_no_key_lines_when_the_list_is_empty():
    out = _authorized_keys(fleet_security_probe_authorized_keys=[])
    assert [ln for ln in out.splitlines() if not ln.startswith("#")] == []


def test_units_are_configurable_in_the_forced_command():
    out = _authorized_keys(
        fleet_security_probe_authorized_keys=["ssh-ed25519 AAAA k"],
        fleet_security_probe_units=["a.service", "b.service"],
    )
    assert 'fleet-security-report a.service b.service" ssh-ed25519' in out


def test_inputs_are_validated_before_they_reach_authorized_keys():
    task = _task("Refuse unit names and keys")
    text = " ".join(task["ansible.builtin.assert"]["that"])
    assert "fleet_security_probe_units" in text and "fleet_security_probe_authorized_keys" in text
    assert "[\\r\\n]" in text  # no newline smuggling a second, unrestricted line
    # it runs before anything is installed
    first = _blocks()[0]["block"][0]
    assert first["name"] == task["name"]


# --- the AllowUsers snippet ----------------------------------------------------


def test_allowusers_snippet_is_only_written_when_an_allowusers_line_exists():
    check = _task("Look for an AllowUsers line")
    argv = check["ansible.builtin.command"]["argv"]
    assert argv[0] == "grep" and any("AllowUsers" in a for a in argv)
    assert "--exclude=53fleet-security-probe.conf" in argv  # our own file must not count
    assert check["register"] == "sp_existing_allowusers"
    assert check["changed_when"] is False and check["check_mode"] is False

    write = _task("Let the probe user in")
    assert write["when"] == "sp_existing_allowusers.rc == 0"
    copy = write["ansible.builtin.copy"]
    assert copy["dest"] == SNIPPET
    assert "AllowUsers {{ fleet_security_probe_user }}" in copy["content"]
    assert copy["validate"] == "sshd -t -f %s"
    assert write["notify"] == "Reload sshd (security_probe)"


def test_allowusers_snippet_is_removed_when_no_other_allowusers_line_exists():
    drop = _task("Drop the AllowUsers snippet when no other")
    assert drop["when"] == "sp_existing_allowusers.rc != 0"
    assert drop["ansible.builtin.file"] == {"path": SNIPPET, "state": "absent"}
    assert drop["notify"] == "Reload sshd (security_probe)"


def test_sshd_effective_config_is_checked_for_the_probe_and_the_operators():
    assert _task("Read the effective sshd")["ansible.builtin.command"]["cmd"] == "sshd -T"
    facts = _task("Work out who the effective AllowUsers")["ansible.builtin.set_fact"]
    assert "allowusers" in facts["sp_allowed_users"]
    assert "SUDO_USER" in facts["sp_real_operator"]
    check = _task("Assert the probe user is admitted")
    that = " ".join(check["ansible.builtin.assert"]["that"])
    assert "fleet_security_probe_user in sp_allowed_users" in that
    assert "sp_real_operator in sp_allowed_users" in that
    assert "fleet_ssh_allow_users" in that
    assert check["when"] == "sp_allowed_users | length > 0"


def test_reload_handler_exists():
    handlers = _load_yaml(ROLE / "handlers" / "main.yml")
    assert [h["name"] for h in handlers] == ["Reload sshd (security_probe)"]
    assert handlers[0]["ansible.builtin.systemd"] == {"name": "ssh", "state": "reloaded"}


def test_smoke_test_runs_the_report_as_the_probe_user_and_checks_the_json():
    smoke = _task("Smoke test")
    cmd = smoke["ansible.builtin.command"]["cmd"]
    assert "runuser -u {{ fleet_security_probe_user }}" in cmd
    assert "/usr/local/bin/fleet-security-report" in cmd
    check = " ".join(_task("Assert the report parses")["ansible.builtin.assert"]["that"])
    assert "from_json" in check and "units" in check


# --- disabled path -------------------------------------------------------------


def test_disabled_path_removes_keys_snippet_user_and_script():
    _, disabled = _blocks()
    tasks = list(_walk(disabled["block"]))
    by_name = {t["name"]: t for t in tasks}
    snippet = by_name["Remove the probe's AllowUsers snippet"]
    assert snippet["ansible.builtin.file"] == {"path": SNIPPET, "state": "absent"}
    assert snippet["notify"] == "Reload sshd (security_probe)"
    keys = by_name["Remove the probe's authorized_keys"]["ansible.builtin.file"]
    assert keys["state"] == "absent" and keys["path"].endswith("/.ssh/authorized_keys")
    user = by_name["Remove the probe user and its home"]["ansible.builtin.user"]
    assert user["state"] == "absent" and user["remove"] is True
    script = by_name["Remove the report script"]["ansible.builtin.file"]
    assert script == {"path": "/usr/local/bin/fleet-security-report", "state": "absent"}


# --- the report script, against stub systemd tools -----------------------------

STUB_ANALYZE = """\
#!__PYTHON__
import json, sys
args = sys.argv[1:]
if args == ["--version"]:
    print("systemd 257 (257.13-1~deb13u1)")
    print("+PAM +AUDIT")
    sys.exit(0)
assert args[0] == "security" and args[1] == "--no-pager", args
rest = args[2:]
if not rest:
    print("UNIT                         EXPOSURE PREDICATE HAPPY")
    print("fleet.service                     1.8 OK        \\N{SLIGHTLY SMILING FACE}")
    print("ssh.service                       9.6 UNSAFE    \\N{FEARFUL FACE}")
    print("\\N{BULLET} weird-but-not-a-row")
    sys.exit(0)
json_mode = "--json=short" in rest
unit = rest[-1]
if unit == "broken.service":
    print("Failed to load unit", file=sys.stderr)
    sys.exit(1)
if json_mode:
    print(json.dumps([
        {"set": False, "name": "A=", "json_field": "Alpha", "description": "a", "exposure": "0.4"},
        {"set": None, "name": "B=", "json_field": "Beta", "description": "b", "exposure": None},
        {"set": True, "name": "C=", "json_field": "Gamma", "description": "c", "exposure": "0.0"},
        {"set": False, "name": "D=", "json_field": "Delta", "description": "d", "exposure": "0.1"},
    ]))
else:
    print("NAME  DESCRIPTION  EXPOSURE")
    print("\\N{RIGHTWARDS ARROW} Overall exposure level for " + unit + ": 1.8 OK")
"""

STUB_SYSTEMCTL = """\
#!__PYTHON__
import sys
unit = sys.argv[-1]
print("not-found" if unit.startswith("gone") else "loaded")
"""


@pytest.fixture
def stubs(tmp_path, monkeypatch):
    for name, body in (("systemd-analyze", STUB_ANALYZE), ("systemctl", STUB_SYSTEMCTL)):
        path = tmp_path / name
        path.write_text(body.replace("__PYTHON__", sys.executable), encoding="utf-8")
        path.chmod(0o755)
    monkeypatch.setenv("SYSTEMD_ANALYZE", str(tmp_path / "systemd-analyze"))
    monkeypatch.setenv("SYSTEMCTL", str(tmp_path / "systemctl"))
    return tmp_path


@pytest.fixture(scope="module")
def probe():
    loader = importlib.machinery.SourceFileLoader("fleet_security_report_under_test", str(SCRIPT))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


def test_analyze_unit_parses_the_score_and_keeps_only_exposed_directives(stubs, probe):
    result = probe.analyze_unit("fleet.service")
    assert result == {"score": 1.8, "directives": {"Alpha": 0.4, "Delta": 0.1}}


def test_analyze_unit_passes_extra_args_through(stubs, probe, monkeypatch):
    seen = stubs / "args.txt"
    wrapper = stubs / "wrap"
    wrapper.write_text(
        f'#!/bin/sh\nprintf \'%s\\n\' "$*" >> {seen}\nexec {stubs}/systemd-analyze "$@"\n'
    )
    wrapper.chmod(0o755)
    monkeypatch.setenv("SYSTEMD_ANALYZE", str(wrapper))
    probe.analyze_unit("/x/fleet.service", ["--offline=yes", "--root=/r"])
    lines = seen.read_text().splitlines()
    assert "security --no-pager --offline=yes --root=/r /x/fleet.service" in lines
    assert "security --no-pager --offline=yes --root=/r --json=short /x/fleet.service" in lines


def test_analyze_unit_raises_for_an_unscorable_unit(stubs, probe):
    with pytest.raises(probe.AnalyzeError, match="broken.service"):
        probe.analyze_unit("broken.service")


def test_overview_parses_the_table_and_skips_the_header(stubs, probe):
    assert probe.overview() == {"fleet.service": 1.8, "ssh.service": 9.6}


def test_systemd_version_is_the_major_number(stubs, probe):
    assert probe.systemd_version() == "257"


def test_report_shape_and_missing_units(stubs, probe):
    report = probe.build_report(
        ["fleet.service", "gone.service", "broken.service"], host="ddev-test"
    )
    assert set(report) == {"schema", "host", "systemd", "generated", "overview", "units", "missing"}
    assert report["schema"] == 1 and report["host"] == "ddev-test" and report["systemd"] == "257"
    assert report["generated"].endswith("Z")
    assert report["overview"] == {"fleet.service": 1.8, "ssh.service": 9.6}
    assert report["units"] == {
        "fleet.service": {"score": 1.8, "directives": {"Alpha": 0.4, "Delta": 0.1}}
    }
    # not loaded, and loaded-but-unscorable, both land in "missing" without crashing
    assert report["missing"] == ["broken.service", "gone.service"]


def test_script_prints_json_ignores_the_ssh_command_and_exits_zero(stubs):
    env = {**os.environ, "SSH_ORIGINAL_COMMAND": "rm -rf /", "HOME": str(stubs)}
    done = subprocess.run(
        [sys.executable, str(SCRIPT), "fleet.service", "gone.service"],
        capture_output=True,
        text=True,
        env=env,
        timeout=60,
    )
    assert done.returncode == 0, done.stderr
    report = json.loads(done.stdout)
    assert list(report["units"]) == ["fleet.service"] and report["missing"] == ["gone.service"]


def test_script_defaults_to_the_five_watched_units(stubs, probe, capsys):
    assert probe.main([]) == 0
    report = json.loads(capsys.readouterr().out)
    assert set(report["units"]) | set(report["missing"]) == set(probe.DEFAULT_UNITS)


def test_script_survives_a_host_without_systemd_tools(tmp_path, monkeypatch, probe):
    monkeypatch.setenv("SYSTEMD_ANALYZE", str(tmp_path / "nope"))
    monkeypatch.setenv("SYSTEMCTL", str(tmp_path / "nope"))
    report = probe.build_report(["fleet.service"])
    assert report["units"] == {} and report["missing"] == ["fleet.service"]
    assert report["systemd"] == "unknown" and report["overview"] == {}
