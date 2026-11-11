# FLE-261: stdout callback for `fleet host:drift` (root helper `apply drift`).
#
# Runs with `ansible-playbook --check --diff` and prints ONE JSON document between two marker lines, per role:
# the tasks that would change something (with their diff), the "DRIFT:" messages a role emits for what a check run
# cannot see as a changed task (a download, a build), and the tasks that failed. Nothing else goes to stdout.
# A task with no_log has no diff (Ansible censors it); the report says so instead of showing anything.
# The app parses this document, masks anything that looks like a secret and keeps the last report. The convention for
# roles is in install/ansible/README.md.
from __future__ import annotations

import difflib
import json

from ansible.plugins.callback import CallbackBase

DOCUMENTATION = """
    name: fleet_drift
    type: stdout
    short_description: JSON drift report for ddev-fleet hosts
    description:
      - Collects changed, failed and DRIFT-prefixed debug results of a check run and prints them as one JSON document.
"""

BEGIN = "FLEET-DRIFT-REPORT-BEGIN"
END = "FLEET-DRIFT-REPORT-END"
PREFIX = "DRIFT:"
MAX_DIFF_LINES = 200
MAX_MESSAGE = 2000


class CallbackModule(CallbackBase):
    CALLBACK_VERSION = 2.0
    CALLBACK_TYPE = "stdout"
    CALLBACK_NAME = "fleet_drift"

    def __init__(self):
        super().__init__()
        self._roles = {}
        self._order = []
        self._failed_play = False

    # -- collection -----------------------------------------------------------------------------------------------

    def _role(self, result):
        task = result._task
        name = None
        role = getattr(task, "_role", None)
        if role is not None:
            try:
                name = role.get_name(include_role_fqcn=False)
            except TypeError:
                name = role.get_name()
        name = name or "(playbook)"
        if name not in self._roles:
            self._roles[name] = {"role": name, "changed": [], "drift": [], "failed": []}
            self._order.append(name)
        return self._roles[name]

    @staticmethod
    def _text(value):
        text = value if isinstance(value, str) else json.dumps(value, default=str)
        return text[:MAX_MESSAGE]

    @staticmethod
    def _unified(entry):
        if not isinstance(entry, dict):
            return ""
        if entry.get("prepared"):
            lines = str(entry["prepared"]).splitlines()
        else:
            before = str(entry.get("before", "")).splitlines()
            after = str(entry.get("after", "")).splitlines()
            lines = list(
                difflib.unified_diff(
                    before,
                    after,
                    fromfile=str(entry.get("before_header", "before")),
                    tofile=str(entry.get("after_header", "after")),
                    lineterm="",
                )
            )
        if len(lines) > MAX_DIFF_LINES:
            lines = lines[:MAX_DIFF_LINES] + ["... (diff truncated)"]
        return "\n".join(lines)

    def _diffs(self, res):
        diff = res.get("diff")
        if isinstance(diff, dict):
            diff = [diff]
        return [text for text in (self._unified(d) for d in (diff or [])) if text]

    def _results(self, res):
        """The result itself, or each item result of a loop."""
        items = res.get("results")
        return [r for r in items if isinstance(r, dict)] if isinstance(items, list) else [res]

    def v2_runner_on_ok(self, result):
        bucket = self._role(result)
        res = result._result
        is_debug = result._task.action in ("ansible.builtin.debug", "debug")
        for one in self._results(res):
            msg = one.get("msg")
            if is_debug and isinstance(msg, str) and msg.startswith(PREFIX):
                bucket["drift"].append(self._text(msg[len(PREFIX):].strip()))
        if not res.get("changed"):
            return
        if result._task.no_log or res.get("_ansible_no_log"):
            diff = "(hidden: the task has no_log)"
        else:
            diff = "\n".join(d for one in self._results(res) for d in self._diffs(one))
        bucket["changed"].append({"task": result._task.name or result._task.action, "diff": diff})

    def v2_runner_on_skipped(self, result):
        self._role(result)

    @staticmethod
    def _rescued(task):
        """True when a block around the task has a rescue section that will handle this failure."""
        child = task
        parent = getattr(task, "_parent", None)
        while parent is not None:
            if getattr(parent, "rescue", None) and any(
                getattr(t, "_uuid", None) == getattr(child, "_uuid", object()) for t in (getattr(parent, "block", None) or [])
            ):
                return True
            child = parent
            parent = getattr(parent, "_parent", None)
        return False

    def v2_runner_on_failed(self, result, ignore_errors=False):
        bucket = self._role(result)
        if ignore_errors or self._rescued(result._task):
            return
        self._failed_play = True
        if result._task.no_log:
            msg = "(hidden: the task has no_log)"
        else:
            msg = self._text(result._result.get("msg", "the task failed"))
        bucket["failed"].append({"task": result._task.name or result._task.action, "msg": msg})

    def v2_runner_on_unreachable(self, result):
        self._failed_play = True
        self._role(result)["failed"].append(
            {"task": result._task.name or result._task.action, "msg": self._text(result._result.get("msg", "unreachable"))}
        )

    # -- output ---------------------------------------------------------------------------------------------------

    def v2_playbook_on_stats(self, stats):
        report = {"schema": 1, "roles": [self._roles[name] for name in self._order]}
        self._display.display(BEGIN, screen_only=True)
        self._display.display(json.dumps(report, sort_keys=True), screen_only=True)
        self._display.display(END, screen_only=True)
