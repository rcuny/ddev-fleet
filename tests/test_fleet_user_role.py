"""FLE-21: the fleet_user role creates the encrypted-secret-store directories.
Checked at YAML level (the suite does not run Ansible), like the
security_probe role test."""

from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / "ansible"


def _tasks(role: str) -> list[dict]:
    return yaml.safe_load((ANSIBLE / "roles" / role / "tasks" / "main.yml").read_text())


def test_fleet_user_role_creates_secrets_and_gnupg_dirs_0700_owned_by_fleet():
    (task,) = [t for t in _tasks("fleet_user") if "secret store directories" in t["name"]]
    module = task["ansible.builtin.file"]
    assert module["state"] == "directory"
    assert module["mode"] == "0700"
    assert module["owner"] == "{{ fleet_user }}"
    assert module["group"] == "{{ fleet_group }}"
    assert task["loop"] == ["{{ fleet_srv_dir }}/secrets", "{{ fleet_srv_dir }}/gnupg"]


def test_base_role_installs_gnupg_for_the_gpg_binary():
    packages = []
    for task in _tasks("base"):
        apt = task.get("ansible.builtin.apt") or {}
        name = apt.get("name")
        if isinstance(name, list):
            packages.extend(name)
    assert "gnupg" in packages
