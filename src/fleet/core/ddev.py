"""Subprocess wrappers around `ddev`/`docker` (spec §11, §16d/e)."""

import json
import subprocess
from pathlib import Path

from fleet.core.errors import FleetError
from fleet.core.runner import RunResult, run_streamed


def _run_with_timeout_guard(argv: list[str], instance_dir: Path, *, log_path, timeout, runner):
    # Only forward `timeout=` when actually set. Most callers (and most
    # test-double runners across the suite) never pass one, so omitting the
    # kwarg entirely when it's None keeps today's exact call signature
    # unchanged for them — only a caller that opts into a timeout sees the
    # new kwarg at all.
    kwargs = {"cwd": instance_dir, "log_path": log_path}
    if timeout is not None:
        kwargs["timeout"] = timeout
    try:
        return runner(argv, **kwargs)
    except subprocess.TimeoutExpired as exc:
        verb = argv[1] if len(argv) > 1 else argv[0]
        raise FleetError(f"ddev {verb} timed out after {timeout}s for {instance_dir.name}") from exc


def start(
    instance_dir: Path,
    *,
    log_path: Path | None = None,
    timeout: float | None = None,
    runner=run_streamed,
) -> RunResult:
    return _run_with_timeout_guard(
        ["ddev", "start"], instance_dir, log_path=log_path, timeout=timeout, runner=runner
    )


def stop(
    instance_dir: Path,
    *,
    log_path: Path | None = None,
    timeout: float | None = None,
    runner=run_streamed,
) -> RunResult:
    return _run_with_timeout_guard(
        ["ddev", "stop"], instance_dir, log_path=log_path, timeout=timeout, runner=runner
    )


def restart(
    instance_dir: Path,
    *,
    log_path: Path | None = None,
    timeout: float | None = None,
    runner=run_streamed,
) -> RunResult:
    return _run_with_timeout_guard(
        ["ddev", "restart"], instance_dir, log_path=log_path, timeout=timeout, runner=runner
    )


def delete(instance_dir: Path, *, runner=run_streamed) -> RunResult:
    # Flag names verified against the DDEV CLI at the time of writing; spec
    # §16 flags this as a verify-at-implementation point if a future DDEV
    # release renames or removes them.
    return runner(["ddev", "delete", "--omit-snapshot", "--yes"], cwd=instance_dir)


def list_projects(*, runner=run_streamed) -> list[dict]:
    result = runner(["ddev", "list", "--json-output"])
    text = "\n".join(result.lines).strip()
    if not text:
        return []
    try:
        data = json.loads(text)
    except json.JSONDecodeError:
        return []
    if isinstance(data, dict):
        return list(data.get("raw") or [])
    if isinstance(data, list):
        return data
    return []


def ram_usage(*, runner=run_streamed) -> dict[str, int]:
    usage: dict[str, int] = {}
    try:
        result = runner(["docker", "stats", "--no-stream", "--format", "{{json .}}"])
        for line in result.lines:
            stripped = line.strip()
            if not stripped:
                continue
            entry = json.loads(stripped)
            name = entry.get("Name", "")
            if not name.startswith("ddev-"):
                continue
            without_prefix = name[len("ddev-") :]
            instance_id = without_prefix.rsplit("-", 1)[0]
            mib = _parse_mem_to_mib(entry.get("MemUsage", ""))
            usage[instance_id] = usage.get(instance_id, 0) + mib
    except Exception:
        return {}
    return usage


def _parse_mem_to_mib(mem_usage: str) -> int:
    # e.g. "150MiB / 2GiB"
    amount = mem_usage.split("/")[0].strip()
    if amount.endswith("GiB"):
        return int(float(amount[:-3]) * 1024)
    if amount.endswith("MiB"):
        return int(float(amount[:-3]))
    if amount.endswith("KiB"):
        return int(float(amount[:-3]) / 1024)
    return 0
