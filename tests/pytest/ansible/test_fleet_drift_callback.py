"""FLE-280: the fleet_drift callback ignores failures a block rescue handles (stub Task and Block, no ansible needed)."""

from __future__ import annotations

import importlib.util
import sys
import types
from pathlib import Path

PLUGIN = Path(__file__).resolve().parents[3] / "install/ansible/callback_plugins/fleet_drift.py"


def _load():
    # The plugin only needs CallbackBase; a stub keeps the test independent of an ansible install.
    callback = types.ModuleType("ansible.plugins.callback")

    class CallbackBase:
        def __init__(self, *a, **k):
            pass

    callback.CallbackBase = CallbackBase
    saved = {n: sys.modules.get(n) for n in ("ansible", "ansible.plugins", "ansible.plugins.callback")}
    sys.modules["ansible"] = types.ModuleType("ansible")
    sys.modules["ansible.plugins"] = types.ModuleType("ansible.plugins")
    sys.modules["ansible.plugins.callback"] = callback
    try:
        spec = importlib.util.spec_from_file_location("fleet_drift_under_test", PLUGIN)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
    finally:
        for name, mod in saved.items():
            if mod is None:
                sys.modules.pop(name, None)
            else:
                sys.modules[name] = mod
    return module.CallbackModule


class Task:
    def __init__(self, uuid, parent=None, name="t"):
        self._uuid = uuid
        self._parent = parent
        self.name = name
        self.action = "command"
        self.no_log = False
        self._role = None


class Block:
    _n = 0

    def __init__(self, parent=None, rescue=(), always=()):
        Block._n += 1
        self._uuid = f"block-{Block._n}"  # a real Block has a _uuid too
        self._parent = parent
        self.block = []
        self.rescue = list(rescue)
        self.always = list(always)


class Result:
    def __init__(self, task):
        self._task = task
        self._result = {"msg": "boom"}


def _failed(task):
    cb = _load()()
    cb.v2_runner_on_failed(Result(task))
    return cb._roles.get("(playbook)", {}).get("failed", []), cb._failed_play


def _copy(task):
    """Ansible hands the callback a copy of the task: same _uuid, other object."""
    twin = Task(task._uuid, task._parent, task.name)
    return twin


def test_failure_inside_a_block_with_rescue_is_ignored():
    block = Block(rescue=[Task("r")])
    task = Task("a", block)
    block.block.append(task)
    failed, play_failed = _failed(_copy(task))
    assert failed == []
    assert play_failed is False


def test_failure_inside_the_rescue_section_is_reported():
    rescue_task = Task("r")
    block = Block(rescue=[rescue_task])
    rescue_task._parent = block
    failed, play_failed = _failed(_copy(rescue_task))
    assert [f["task"] for f in failed] == ["t"]
    assert play_failed is True


def test_failure_inside_the_always_section_is_reported():
    always_task = Task("z")
    block = Block(rescue=[Task("r")], always=[always_task])
    always_task._parent = block
    failed, _ = _failed(_copy(always_task))
    assert len(failed) == 1


def test_failure_in_a_block_without_rescue_is_reported():
    block = Block()
    task = Task("a", block)
    block.block.append(task)
    failed, _ = _failed(_copy(task))
    assert len(failed) == 1


def test_failure_in_an_included_task_is_rescued_by_the_including_block():
    outer = Block(rescue=[Task("r")])
    include = Task("inc")  # the include_tasks task is a Task, parent of the included ones
    include._parent = outer
    outer.block.append(include)
    task = Task("a", include)
    failed, _ = _failed(_copy(task))
    assert failed == []


def test_unmatched_uuid_in_the_parent_block_is_not_rescued():
    block = Block(rescue=[Task("r")])
    block.block.append(Task("other"))
    failed, _ = _failed(Task("a", block))
    assert len(failed) == 1
