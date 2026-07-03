"""Streamed subprocess execution with line-by-line logging (spec §12)."""

import os
import subprocess
from dataclasses import dataclass
from pathlib import Path

from fleet.core.errors import FleetError


@dataclass
class RunResult:
    returncode: int
    lines: list[str]


def run_streamed(
    cmd: list[str],
    *,
    cwd: Path | None = None,
    env: dict[str, str] | None = None,
    log_path: Path | None = None,
    echo: bool = True,
) -> RunResult:
    full_env = os.environ.copy()
    if env:
        full_env.update(env)

    try:
        process = subprocess.Popen(
            cmd,
            cwd=str(cwd) if cwd else None,
            env=full_env,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            bufsize=1,
        )
    except FileNotFoundError as exc:
        raise FleetError(f"command not found: {cmd[0]}") from exc

    lines: list[str] = []
    log_fh = None
    if log_path:
        log_path.parent.mkdir(parents=True, exist_ok=True)
        log_fh = open(log_path, "a", encoding="utf-8")
    try:
        assert process.stdout is not None
        for raw_line in process.stdout:
            line = raw_line.rstrip("\r\n")
            lines.append(line)
            if echo:
                print(line)
            if log_fh:
                log_fh.write(line + "\n")
                log_fh.flush()
    finally:
        if log_fh:
            log_fh.close()

    process.wait()
    return RunResult(returncode=process.returncode, lines=lines)
