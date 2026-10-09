"""FLE-14: the branch/HEAD indicator follows a submodule when the project sets
`display_submodule_branch` — `list_instances()` (CLI + web UI) and the tmux
sidebar's `_branches()`. Display only: these tests use REAL git checkouts with a
real submodule, because the point is which checkout git is asked about."""

import json
import logging
import subprocess

import pytest

from fleet import tmux_sidebar
from fleet.core import instances
from fleet.core.registry import Registry
from fleet.core.runner import RunResult, run_streamed

SUBMODULE = "ddev-fleet"
_IDENT = ["-c", "user.name=Test", "-c", "user.email=test@example.test"]


def _git(cwd, *args):
    out = subprocess.run(
        ["git", *_IDENT, "-c", "protocol.file.allow=always", *args],
        cwd=str(cwd),
        check=True,
        capture_output=True,
        text=True,
    )
    return out.stdout.strip()


def _commit(repo, name):
    (repo / name).write_text(f"{name}\n", encoding="utf-8")
    _git(repo, "add", name)
    _git(repo, "commit", "-m", name)


def _registry(fleet_home, *, with_key=True):
    paths = instances.FleetPaths.from_home(fleet_home)
    paths.registry.parent.mkdir(parents=True, exist_ok=True)
    key = f"    display_submodule_branch: {SUBMODULE}\n" if with_key else ""
    paths.registry.write_text(
        "fleet:\n  domain: fleet.example.test\n\n"
        "projects:\n  demo:\n    git: git@example.test:org/demo.git\n"
        f"{key}    templates:\n      default: {{}}\n",
        encoding="utf-8",
    )
    return paths, Registry.load(paths.registry)


@pytest.fixture
def instance(fleet_home, tmp_path):
    """`demo--develop`: a real checkout on `main` whose submodule `ddev-fleet`
    sits on branch `feature/FLE-12-x`, one commit ahead of the instance's own
    HEAD, so branch AND head tell the two checkouts apart."""
    sub_origin = tmp_path / "sub-origin"
    sub_origin.mkdir()
    _git(sub_origin, "init", "--initial-branch=main")
    _commit(sub_origin, "sub.txt")

    inst = fleet_home / "instances" / "demo--develop"
    (inst / ".fleet").mkdir(parents=True)
    (inst / ".fleet" / "instance.yml").write_text(
        "project: demo\ninstance: develop\nbranch: main\n"
        "created-at: '2026-07-01T00:00:00Z'\nlast-deployed-at: '2026-07-01T00:00:00Z'\n",
        encoding="utf-8",
    )
    _git(inst, "init", "--initial-branch=main")
    _commit(inst, "README.md")
    _git(inst, "submodule", "add", str(sub_origin), SUBMODULE)
    _git(inst, "commit", "-m", "add submodule")

    sub = inst / SUBMODULE
    _git(sub, "checkout", "-b", "feature/FLE-12-x")
    _commit(sub, "work.txt")
    return inst


def _fake_runner(cmd, **kwargs):
    # git runs for real; ddev/docker are out of scope here.
    if cmd[0] == "git":
        return run_streamed(cmd, **kwargs)
    if cmd[:2] == ["ddev", "list"]:
        return RunResult(returncode=0, lines=[json.dumps({"raw": []})])
    return RunResult(returncode=0, lines=[])


def _only(paths, registry):
    (status,) = instances.list_instances(paths, registry, runner=_fake_runner)
    return status


def _sub_head(inst):
    return _git(inst / SUBMODULE, "rev-parse", "--short", "HEAD")


def test_list_instances_shows_prefixed_submodule_branch_and_head(fleet_home, instance):
    paths, registry = _registry(fleet_home)
    status = _only(paths, registry)
    assert status.branch == f"{SUBMODULE}: feature/FLE-12-x"
    assert status.head == _sub_head(instance)
    assert status.head != _git(instance, "rev-parse", "--short", "HEAD")


def test_list_instances_submodule_detached_head_shows_prefixed_short_sha(fleet_home, instance):
    _git(instance / SUBMODULE, "checkout", "--detach")
    paths, registry = _registry(fleet_home)
    status = _only(paths, registry)
    assert status.branch == f"{SUBMODULE}: {_sub_head(instance)}"
    assert status.head == _sub_head(instance)


def test_list_instances_missing_submodule_dir_falls_back_without_prefix(fleet_home, instance):
    subprocess.run(["rm", "-rf", str(instance / SUBMODULE)], check=True)
    paths, registry = _registry(fleet_home)
    status = _only(paths, registry)
    assert status.branch == "main"
    assert status.head == _git(instance, "rev-parse", "--short", "HEAD")


def test_list_instances_uninitialised_submodule_dir_falls_back(fleet_home, instance):
    """An empty `ddev-fleet/` (submodule not initialised yet) must NOT be asked
    about by git: it would walk up and report the PARENT checkout under the
    submodule's prefix."""
    subprocess.run(["rm", "-rf", str(instance / SUBMODULE)], check=True)
    (instance / SUBMODULE).mkdir()
    paths, registry = _registry(fleet_home)
    status = _only(paths, registry)
    assert status.branch == "main"
    assert status.head == _git(instance, "rev-parse", "--short", "HEAD")


def test_list_instances_without_key_is_unchanged(fleet_home, instance):
    paths, registry = _registry(fleet_home, with_key=False)
    status = _only(paths, registry)
    assert status.branch == "main"
    assert status.head == _git(instance, "rev-parse", "--short", "HEAD")


def test_list_instances_project_unknown_to_registry_is_unchanged(fleet_home, instance):
    """Registry lookups for the display option never raise (e.g. an instance
    whose project was removed from fleet.yml)."""
    (instance / ".fleet" / "instance.yml").write_text(
        "project: gone\ninstance: develop\nbranch: main\n", encoding="utf-8"
    )
    paths, registry = _registry(fleet_home)
    status = _only(paths, registry)
    assert status.branch == "main"


def test_list_instances_ignores_submodule_symlink_escaping_the_instance(
    fleet_home, instance, tmp_path
):
    outside = tmp_path / "outside"
    outside.mkdir()
    _git(outside, "init", "--initial-branch=other")
    _commit(outside, "x")
    subprocess.run(["rm", "-rf", str(instance / SUBMODULE)], check=True)
    (instance / SUBMODULE).symlink_to(outside)
    paths, registry = _registry(fleet_home)
    assert _only(paths, registry).branch == "main"


# --- tmux sidebar -----------------------------------------------------------


def test_sidebar_branches_shows_prefixed_submodule_branch_and_head(fleet_home, instance):
    paths, registry = _registry(fleet_home)
    out = tmux_sidebar._branches(paths, ["demo--develop"], registry)
    assert out == {"demo--develop": f"{SUBMODULE}: feature/FLE-12-x ({_sub_head(instance)})"}


def test_sidebar_branches_detached_submodule_shows_prefixed_sha(fleet_home, instance):
    _git(instance / SUBMODULE, "checkout", "--detach")
    paths, registry = _registry(fleet_home)
    sha = _sub_head(instance)
    out = tmux_sidebar._branches(paths, ["demo--develop"], registry)
    assert out == {"demo--develop": f"{SUBMODULE}: {sha} ({sha})"}


def test_sidebar_branches_missing_submodule_falls_back(fleet_home, instance):
    subprocess.run(["rm", "-rf", str(instance / SUBMODULE)], check=True)
    paths, registry = _registry(fleet_home)
    head = _git(instance, "rev-parse", "--short", "HEAD")
    out = tmux_sidebar._branches(paths, ["demo--develop"], registry)
    assert out == {"demo--develop": f"main ({head})"}


def test_sidebar_branches_without_key_is_unchanged(fleet_home, instance):
    paths, registry = _registry(fleet_home, with_key=False)
    head = _git(instance, "rev-parse", "--short", "HEAD")
    assert tmux_sidebar._branches(paths, ["demo--develop"], registry) == {
        "demo--develop": f"main ({head})"
    }


def test_sidebar_branches_loads_registry_itself_when_not_given(fleet_home, instance):
    paths, _ = _registry(fleet_home)
    out = tmux_sidebar._branches(paths, ["demo--develop"])
    assert out["demo--develop"].startswith(f"{SUBMODULE}: feature/FLE-12-x (")


def test_sidebar_branches_unloadable_registry_falls_back_without_crashing(fleet_home, instance):
    paths, _ = _registry(fleet_home)
    paths.registry.write_text("not: [valid", encoding="utf-8")
    head = _git(instance, "rev-parse", "--short", "HEAD")
    assert tmux_sidebar._branches(paths, ["demo--develop"]) == {"demo--develop": f"main ({head})"}


def test_sidebar_branches_head_only_keeps_the_submodule_prefix(fleet_home, instance, monkeypatch):
    monkeypatch.setattr(tmux_sidebar, "read_instance_git_branch", lambda d: "")
    paths, registry = _registry(fleet_home)
    out = tmux_sidebar._branches(paths, ["demo--develop"], registry)
    assert out == {"demo--develop": f"{SUBMODULE}: ({_sub_head(instance)})"}


def test_sidebar_registry_load_is_silent_and_restores_logger(fleet_home, instance, capsys):
    """`Registry.load` warns about the deprecated template-level tty1; with no
    logging configured that would hit stderr (the sidebar's tmux pane) on every
    branch tick. The mute must be scoped: the logger's level is restored."""
    paths, _ = _registry(fleet_home)
    paths.registry.write_text(
        "fleet:\n  domain: fleet.example.test\n\n"
        "projects:\n  demo:\n    git: git@example.test:org/demo.git\n"
        f"    display_submodule_branch: {SUBMODULE}\n"
        '    templates:\n      default:\n        tty1: ["echo hi"]\n',
        encoding="utf-8",
    )
    reg_logger = logging.getLogger("fleet.core.registry")
    before = reg_logger.level
    # Under pytest the last-resort stderr handler never fires (pytest installs
    # its own), so assert on emitted records directly.
    records: list[logging.LogRecord] = []
    handler = logging.Handler()
    handler.emit = records.append
    reg_logger.addHandler(handler)
    try:
        out = tmux_sidebar._branches(paths, ["demo--develop"])
    finally:
        reg_logger.removeHandler(handler)
    assert records == []
    assert out["demo--develop"].startswith(f"{SUBMODULE}: feature/FLE-12-x (")  # it did load
    captured = capsys.readouterr()
    assert captured.err == "" and captured.out == ""
    assert reg_logger.level == before
