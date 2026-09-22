"""Streamed subprocess execution with line-by-line logging (spec §12)."""

import os
import signal
import subprocess
import threading
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
    input_text: str | None = None,
    timeout: float | None = None,
) -> RunResult:
    """Run `cmd`, streaming stdout/stderr line-by-line. `timeout` (seconds)
    is a hang guard, not a general-purpose deadline: if the child hasn't
    exited within `timeout`, it is killed and a FleetError is raised. Default
    None preserves today's behaviour (no timeout, wait forever)."""
    full_env = os.environ.copy()
    if env:
        full_env.update(env)

    # Only start the child in its own process group/session when a timeout
    # is in play. That lets the timeout path kill the whole group (see
    # below) so a grandchild that inherited the stdout pipe can't keep the
    # reader thread blocked forever. We deliberately do NOT set this for
    # the timeout=None path — interactive/long-running callers rely on the
    # child staying in our process group so Ctrl-C (SIGINT) propagates to
    # it normally.
    popen_kwargs: dict = {}
    if timeout is not None:
        popen_kwargs["start_new_session"] = True

    try:
        process = subprocess.Popen(
            cmd,
            cwd=str(cwd) if cwd else None,
            env=full_env,
            stdin=subprocess.PIPE if input_text is not None else None,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            bufsize=1,
            **popen_kwargs,
        )
    except FileNotFoundError as exc:
        raise FleetError(f"command not found: {cmd[0]}") from exc

    if input_text is not None:
        assert process.stdin is not None
        process.stdin.write(input_text)
        process.stdin.close()

    lines: list[str] = []
    log_fh = None
    if log_path:
        log_path.parent.mkdir(parents=True, exist_ok=True)
        log_fh = open(log_path, "a", encoding="utf-8")

    def _drain_output() -> None:
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

    if timeout is None:
        _drain_output()
        process.wait()
        return RunResult(returncode=process.returncode, lines=lines)

    # Read stdout on a background thread so we can enforce a wall-clock
    # deadline on the whole run — a blocking `for line in process.stdout`
    # on the main thread would otherwise wait forever for a hung child that
    # never produces (or stops producing) output.
    reader = threading.Thread(target=_drain_output, daemon=True)
    reader.start()
    reader.join(timeout=timeout)

    if reader.is_alive():
        # Kill the whole process group, not just the direct child: `ddev`/
        # `docker` (and shell wrappers in general) commonly spawn
        # grandchildren that inherit the stdout pipe's write end. Killing
        # only the direct child leaves that pipe open, so the reader
        # thread's `for raw_line in process.stdout` never unblocks and this
        # function would hang despite the timeout. `start_new_session=True`
        # above put the child in its own process group, so its pgid equals
        # its pid.
        try:
            os.killpg(os.getpgid(process.pid), signal.SIGKILL)
        except (ProcessLookupError, PermissionError, OSError):
            process.kill()

        # Bound every step below so this function can never hang, even if
        # something still doesn't die/close promptly.
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            pass

        if process.stdout is not None:
            try:
                process.stdout.close()
            except OSError:
                pass

        reader.join(timeout=5)

        # Mirror what subprocess.run(..., timeout=...) would raise, so
        # callers (fleet.core.ddev) can catch the same exception type
        # regardless of the streaming mechanics here.
        raise subprocess.TimeoutExpired(cmd, timeout)

    process.wait()
    return RunResult(returncode=process.returncode, lines=lines)


def run_interactive(
    cmd: list[str],
    *,
    cwd: Path | None = None,
    env: dict[str, str] | None = None,
) -> int:
    """Run `cmd` with inherited stdin/stdout/stderr (no pipes), so an
    interactive child (e.g. `claude setup-token`) can print its auth URL and
    read input directly from the real terminal. Returns the exit code."""
    full_env = os.environ.copy()
    if env:
        full_env.update(env)

    try:
        result = subprocess.run(
            cmd,
            cwd=str(cwd) if cwd else None,
            env=full_env,
            check=False,
        )
    except FileNotFoundError as exc:
        raise FleetError(f"command not found: {cmd[0]}") from exc

    return result.returncode
