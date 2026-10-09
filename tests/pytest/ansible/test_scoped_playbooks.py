"""FLE-25: hardening on a live server.

Scoped playbooks beside site.yml (hardening.yml, fleet-units.yml), the
fleet_service unit/clone split, named ports staged before `ufw enable`, Docker
reloaded (never restarted) and a `--check`-safe UFW assert. Checked at YAML
level, like the other role tests (the suite does not run Ansible)."""

from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[3] / "ansible"
ROLES = ANSIBLE / "roles"


def _load(path: Path):
    return yaml.safe_load(path.read_text(encoding="utf-8"))


def _tasks(role: str, filename: str = "main.yml") -> list[dict]:
    return _load(ROLES / role / "tasks" / filename)


def _task(tasks: list[dict], fragment: str) -> dict:
    (found,) = [t for t in tasks if fragment in t["name"]]
    return found


def _index(tasks: list[dict], fragment: str) -> int:
    return tasks.index(_task(tasks, fragment))


def _module(task: dict) -> dict:
    (key,) = [k for k in task if k.startswith("ansible.builtin.") or k.startswith("community.")]
    return task[key] or {}


def _notified(task: dict) -> list[str]:
    notify = task["notify"]
    return [notify] if isinstance(notify, str) else list(notify)


# --- scoped playbooks -------------------------------------------------------


def _play(name: str) -> dict:
    (play,) = _load(ANSIBLE / name)
    return play


def _assert_scoped_play_shape(play: dict) -> None:
    assert play["hosts"] == "localhost"
    assert play["become"] is True
    assert play["force_handlers"] is True
    assert play.get("gather_facts", True) is True  # security_hardening reads ansible_env
    # same local-vars.yml override as site.yml / caddy-only.yml
    pre = [str(t) for t in play["pre_tasks"]]
    assert any("/etc/ddev-fleet/local-vars.yml" in t and "stat" in t for t in pre)
    assert any("include_vars" in t and "_fleet_local_vars.stat.exists" in t for t in pre)


def test_hardening_playbook_runs_exactly_the_three_hardening_roles_in_site_order():
    play = _play("hardening.yml")
    _assert_scoped_play_shape(play)
    assert play["roles"] == ["network_hardening", "security_hardening", "security_probe"]
    site_roles = _load(ANSIBLE / "site.yml")[0]["roles"]
    assert [r for r in site_roles if r in play["roles"]] == play["roles"]


def test_hardening_playbook_header_documents_the_safe_procedure():
    text = (ANSIBLE / "hardening.yml").read_text(encoding="utf-8")
    for needle in (
        "fleet_network_hardening_enabled",
        "fleet_security_hardening_enabled",
        "fleet_ssh_allow_users",
        "--check --diff",
        "fleet-firewall-confirm",
        "beside site.yml",
    ):
        assert needle in text, needle


def test_fleet_units_playbook_applies_only_units_yml_of_fleet_service():
    play = _play("fleet-units.yml")
    _assert_scoped_play_shape(play)
    assert "roles" not in play  # a plain role would run main.yml (git + pip)
    (task,) = play["tasks"]
    role = task["ansible.builtin.import_role"]  # static: the fleet_boot/fleet_tmux tags still work
    assert role == {"name": "fleet_service", "tasks_from": "units.yml"}


# --- fleet_service split ----------------------------------------------------


def test_fleet_service_main_keeps_clone_and_pip_and_imports_units_last():
    main = _tasks("fleet_service")
    names = [t["name"] for t in main]
    assert main[-1]["ansible.builtin.import_tasks"] == "units.yml"
    assert any(t.get("ansible.builtin.git") for t in main)
    assert any(t.get("ansible.builtin.pip") for t in main)
    # no unit/wrapper task stayed behind in main.yml
    for task in main[:-1]:
        assert "ansible.builtin.systemd" not in task, task["name"]
        assert "ansible.builtin.template" not in task, task["name"]
    assert len(names) == len(set(names))


def test_fleet_units_tasks_have_no_git_venv_or_pip_modules():
    units = _tasks("fleet_service", "units.yml")
    modules = {k for t in units for k in t if k.startswith("ansible.builtin.")}
    assert modules <= {"ansible.builtin.template", "ansible.builtin.systemd"}
    text = (ROLES / "fleet_service" / "tasks" / "units.yml").read_text(encoding="utf-8")
    for forbidden in ("ansible.builtin.git", "ansible.builtin.pip", "ansible.builtin.command"):
        assert forbidden not in text, forbidden


def test_fleet_units_keep_their_tags_and_cover_all_three_units_and_the_wrapper():
    units = _tasks("fleet_service", "units.yml")
    dests = [_module(t).get("dest") for t in units]
    for dest in (
        "/etc/systemd/system/fleet.service",
        "/etc/systemd/system/fleet-boot.service",
        "/etc/systemd/system/fleet-tmux.service",
        "/usr/local/bin/fleet",
    ):
        assert dest in dests
    assert _task(units, "Deploy the fleet-boot.service")["tags"] == ["fleet_boot"]
    assert _task(units, "Enable fleet-boot.service")["tags"] == ["fleet_boot"]
    assert _task(units, "Deploy the fleet-tmux.service")["tags"] == ["fleet_tmux"]
    assert _task(units, "Enable and start fleet-tmux.service")["tags"] == ["fleet_tmux"]


def test_changed_fleet_service_unit_reloads_systemd_then_try_restarts_the_daemon():
    units = _tasks("fleet_service", "units.yml")
    assert _notified(_task(units, "Deploy the fleet.service")) == [
        "Reload systemd daemon",
        "Restart fleet.service if running",
    ]
    handlers = {h["name"]: h for h in _load(ROLES / "fleet_service" / "handlers" / "main.yml")}
    restart = handlers["Restart fleet.service if running"]
    # try-restart: never starts a daemon that is not running (fresh bootstrap)
    assert restart["ansible.builtin.command"]["cmd"] == "systemctl try-restart fleet.service"


def test_fleet_tmux_is_still_never_restarted_by_ansible():
    units = _tasks("fleet_service", "units.yml")
    start = _module(_task(units, "Enable and start fleet-tmux.service"))
    assert start["state"] == "started"
    assert _notified(_task(units, "Deploy the fleet-tmux.service")) == ["Reload systemd daemon"]


# --- docker: reload, never restart ------------------------------------------


def test_daemon_json_notifies_a_docker_reload_not_a_restart():
    harden = _tasks("security_hardening", "harden.yml")
    assert _notified(_task(harden, "Docker daemon.json")) == ["Reload docker"]
    handlers = {h["name"]: h for h in _load(ROLES / "security_hardening" / "handlers" / "main.yml")}
    assert "Restart docker" not in handlers
    assert handlers["Reload docker"]["ansible.builtin.systemd"] == {
        "name": "docker",
        "state": "reloaded",
    }


def test_no_hardening_role_restarts_docker():
    for role in ("network_hardening", "security_hardening"):
        files = [
            *(ROLES / role / "tasks").glob("*.yml"),
            *(ROLES / role / "handlers").glob("*.yml"),
        ]
        for path in files:
            for task in _load(path):
                systemd = task.get("ansible.builtin.systemd") or {}
                assert not (systemd.get("name") == "docker" and systemd.get("state") == "restarted")
            assert "Restart docker" not in path.read_text(encoding="utf-8")


# --- network_hardening: named ports + --check -------------------------------


def _nh() -> list[dict]:
    return _tasks("network_hardening", "harden.yml")


def test_ufw_sync_helper_is_deployed_and_run_before_ufw_is_enabled():
    nh = _nh()
    enable = _index(nh, "Enable UFW")
    assert _index(nh, "Deploy fleet-ufw-sync") < enable
    assert _index(nh, "Install the fleet-ufw-sync sudoers drop-in") < enable
    assert _index(nh, "Stage the registry's named ports") < enable
    # staged rules must be in place before the SSH-staged assert reads them
    assert _index(nh, "Stage the registry's named ports") < _index(nh, "Verify the SSH allow rule")
    assert _index(nh, "Verify the SSH allow rule") < _index(nh, "Assert the SSH port is present")
    assert _index(nh, "Assert the SSH port is present") < enable


def test_ufw_sync_stage_is_skipped_in_check_mode_and_without_the_fleet_venv():
    nh = _nh()
    venv = _task(nh, "Check that the fleet venv python")
    assert _module(venv)["path"] == "{{ fleet_opt_dir }}/venv/bin/python3"
    stage = _task(nh, "Stage the registry's named ports")
    assert _module(stage)["cmd"] == "/usr/local/sbin/fleet-ufw-sync"  # no-args contract
    assert "not ansible_check_mode" in stage["when"]
    assert "nh_fleet_venv_python.stat.exists" in stage["when"]
    assert _index(nh, "Check that the fleet venv python") < _index(nh, "Stage the registry's")


def test_ufw_sync_helper_stays_additive_only_and_argument_free():
    text = (ROLES / "network_hardening" / "templates" / "fleet-ufw-sync.sh.j2").read_text(
        encoding="utf-8"
    )
    assert 'ufw allow "${port}/tcp"' in text
    for destructive in ("ufw delete", "ufw reset", "ufw deny"):
        assert destructive not in text
    assert "$1" not in text and "$@" not in text


def test_ufw_enable_still_follows_the_ssh_allow_rule_and_the_deadman_still_arms_after_it():
    nh = _nh()
    assert _index(nh, "Allow SSH") < _index(nh, "Enable UFW")
    arm = _task(nh, "Arm the UFW dead-man's switch")
    assert "nh_ufw_enable.changed" in arm["when"]
    assert _index(nh, "Enable UFW") < _index(nh, "Arm the UFW dead-man's switch")


def test_ufw_show_added_read_is_check_mode_safe():
    nh = _nh()
    read = _task(nh, "Verify the SSH allow rule")
    assert _module(read)["cmd"] == "ufw show added"
    assert read["check_mode"] is False  # read-only: runs in --check too
    assert read["changed_when"] is False
    # the assert is not skipped in check mode: it accepts "the allow rule WOULD be added"
    assert_task = _task(nh, "Assert the SSH port is present")
    assert "when" not in assert_task
    expr = " ".join(assert_task["ansible.builtin.assert"]["that"])
    assert "regex_findall" in expr and "ansible_check_mode" in expr
    assert _task(nh, "Allow SSH")["register"] == "nh_ufw_ssh_allow"


def test_security_hardening_read_only_commands_run_in_check_mode():
    harden = _tasks("security_hardening", "harden.yml")
    for fragment in ("Determine whether /tmp", "Read MSMTP_USER", "Read MSMTP_PASSWORD"):
        assert _task(harden, fragment)["check_mode"] is False, fragment
    report = _task(harden, "Report the test-send result")
    assert "sh_msmtp_test_send is not skipped" in report["when"]


def test_package_dependent_hardening_tasks_tolerate_check_mode_only():
    # A dry run on a host that has never been hardened: apt only pretends to
    # install needrestart/fail2ban/auditd, so their config dirs and units do not
    # exist. Those tasks may fail in --check, never in a real run.
    tasks = _tasks("security_hardening", "harden.yml")
    for fragment in (
        "needrestart non-interactive trap",
        "Configure the sshd jail",
        "Ensure fail2ban is enabled",
        "Deploy scoped auditd watch rules",
        "Ensure auditd is enabled",
    ):
        assert _task(tasks, fragment).get("ignore_errors") == "{{ ansible_check_mode }}", fragment
