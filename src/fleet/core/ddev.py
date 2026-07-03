"""Subprocess wrappers around `ddev`/`docker` (spec §11, §16d/e)."""

import json
from pathlib import Path

from fleet.core.runner import RunResult, run_streamed


def start(instance_dir: Path, *, runner=run_streamed) -> RunResult:
    return runner(["ddev", "start"], cwd=instance_dir)


def stop(instance_dir: Path, *, runner=run_streamed) -> RunResult:
    return runner(["ddev", "stop"], cwd=instance_dir)


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
