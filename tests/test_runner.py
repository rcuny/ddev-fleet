import os
import subprocess
import time

import pytest

from fleet.core.errors import FleetError
from fleet.core.runner import run_interactive, run_streamed


def test_run_streamed_captures_stdout_lines(capsys):
    result = run_streamed(["echo", "hello"], echo=False)
    assert result.returncode == 0
    assert result.lines == ["hello"]


def test_run_streamed_appends_to_log_file(tmp_path):
    log_path = tmp_path / "log.txt"
    run_streamed(["echo", "hello"], log_path=log_path, echo=False)
    assert log_path.read_text(encoding="utf-8") == "hello\n"

    run_streamed(["echo", "world"], log_path=log_path, echo=False)
    assert log_path.read_text(encoding="utf-8") == "hello\nworld\n"


def test_run_streamed_reports_nonzero_returncode():
    result = run_streamed(["false"], echo=False)
    assert result.returncode == 1
    assert result.lines == []


def test_run_streamed_merges_env_into_os_environ(monkeypatch):
    monkeypatch.setenv("FLEET_TEST_PRESERVED", "still-here")
    result = run_streamed(
        ["bash", "-c", "printenv FLEET_TEST_PRESERVED; printenv FOO"],
        env={"FOO": "bar"},
        echo=False,
    )
    assert result.returncode == 0
    assert result.lines == ["still-here", "bar"]


def test_run_streamed_echoes_when_requested(capsys):
    run_streamed(["echo", "hello"], echo=True)
    captured = capsys.readouterr()
    assert "hello" in captured.out


def test_run_streamed_merges_stderr_into_lines(tmp_path):
    result = run_streamed(
        ["bash", "-c", "echo out-line; echo err-line >&2"],
        echo=False,
    )
    assert result.returncode == 0
    assert "out-line" in result.lines
    assert "err-line" in result.lines


def test_run_streamed_strips_trailing_cr(tmp_path):
    result = run_streamed(["bash", "-c", "printf 'hello\\r\\n'"], echo=False)
    assert result.lines == ["hello"]


def test_run_streamed_wraps_missing_binary_in_fleet_error():
    with pytest.raises(FleetError) as exc_info:
        run_streamed(["definitely-not-a-real-binary-xyz"], echo=False)
    assert "definitely-not-a-real-binary-xyz" in str(exc_info.value)


def test_run_streamed_creates_missing_log_parent_dir(tmp_path):
    log_path = tmp_path / "nested" / "dir" / "log.txt"
    assert not log_path.parent.exists()
    run_streamed(["echo", "hello"], log_path=log_path, echo=False)
    assert log_path.read_text(encoding="utf-8") == "hello\n"


def test_run_interactive_returns_child_exit_code():
    assert run_interactive(["true"]) == 0
    assert run_interactive(["false"]) == 1


def test_run_interactive_raises_fleet_error_on_missing_binary():
    with pytest.raises(FleetError) as exc_info:
        run_interactive(["definitely-not-a-real-binary-xyz"])
    assert "definitely-not-a-real-binary-xyz" in str(exc_info.value)


def test_run_interactive_merges_env_into_os_environ(tmp_path):
    log_path = tmp_path / "out.txt"
    script = f"import os; open({str(log_path)!r}, 'w').write(os.environ.get('FOO', ''))"
    returncode = run_interactive(["python3", "-c", script], env={"FOO": "bar"})
    assert returncode == 0
    assert log_path.read_text(encoding="utf-8") == "bar"


def test_run_streamed_pipes_input_text_to_stdin():
    result = run_streamed(["cat"], input_text="hello stdin\n", echo=False)
    assert result.returncode == 0
    assert result.lines == ["hello stdin"]


def test_run_streamed_without_timeout_waits_for_slow_command():
    # No timeout given (default None) — today's behaviour, preserved: a
    # command slower than any of the timeouts used elsewhere in this test
    # file must still be waited out in full.
    result = run_streamed(["sleep", "0.3"], echo=False)
    assert result.returncode == 0


def test_run_streamed_raises_timeout_expired_when_command_hangs():
    start = time.monotonic()
    with pytest.raises(subprocess.TimeoutExpired):
        run_streamed(["sleep", "5"], echo=False, timeout=0.2)
    elapsed = time.monotonic() - start
    # Generous ceiling — just proving it didn't wait out the full 5s sleep.
    assert elapsed < 3


def test_run_streamed_kills_the_child_process_on_timeout():
    process_holder = {}
    orig_popen = subprocess.Popen

    def spying_popen(*args, **kwargs):
        proc = orig_popen(*args, **kwargs)
        process_holder["proc"] = proc
        return proc

    import fleet.core.runner as runner_mod

    orig = runner_mod.subprocess.Popen
    runner_mod.subprocess.Popen = spying_popen
    try:
        with pytest.raises(subprocess.TimeoutExpired):
            run_streamed(["sleep", "5"], echo=False, timeout=0.2)
    finally:
        runner_mod.subprocess.Popen = orig

    proc = process_holder["proc"]
    # Give the killed child a moment to actually exit.
    proc.wait(timeout=5)
    assert proc.returncode is not None
    assert proc.returncode != 0


def test_run_streamed_completes_normally_within_a_generous_timeout():
    result = run_streamed(["echo", "hello"], echo=False, timeout=10)
    assert result.returncode == 0
    assert result.lines == ["hello"]


def test_run_streamed_returns_promptly_when_grandchild_holds_stdout_open(tmp_path):
    # Regression test for the ddev2 `fleet list` hang: a direct child that
    # exits (or is killed) can still leave a *grandchild* holding the write
    # end of the stdout pipe open, so the reader thread's
    # `for raw_line in process.stdout` never unblocks just because the
    # direct child was killed. `sleep 30 & wait` backgrounds a grandchild
    # (detached from the direct `sh` child once killed) that inherits the
    # pipe and outlives it.
    pidfile = tmp_path / "grandchild.pid"
    start = time.monotonic()
    with pytest.raises(subprocess.TimeoutExpired):
        run_streamed(
            ["/bin/sh", "-c", f"sleep 30 & echo $! > {pidfile}; wait"],
            echo=False,
            timeout=1.0,
        )
    elapsed = time.monotonic() - start
    assert elapsed < 10

    # The grandchild must not survive as an orphan.
    deadline = time.monotonic() + 5
    grandchild_pid = None
    while time.monotonic() < deadline:
        if pidfile.exists():
            content = pidfile.read_text().strip()
            if content:
                grandchild_pid = int(content)
                break
        time.sleep(0.05)
    assert grandchild_pid is not None, "grandchild never wrote its pid"

    deadline = time.monotonic() + 5
    alive = True
    while time.monotonic() < deadline:
        try:
            os.kill(grandchild_pid, 0)
        except ProcessLookupError:
            alive = False
            break
        time.sleep(0.1)
    assert not alive, f"grandchild pid {grandchild_pid} is still alive"


def test_run_streamed_uses_new_session_only_when_timeout_given(monkeypatch):
    captured_kwargs = []
    orig_popen = subprocess.Popen

    def spying_popen(*args, **kwargs):
        captured_kwargs.append(kwargs)
        return orig_popen(*args, **kwargs)

    import fleet.core.runner as runner_mod

    monkeypatch.setattr(runner_mod.subprocess, "Popen", spying_popen)

    run_streamed(["echo", "hello"], echo=False)
    run_streamed(["echo", "hello"], echo=False, timeout=10)

    assert len(captured_kwargs) == 2
    assert not captured_kwargs[0].get("start_new_session")
    assert captured_kwargs[1].get("start_new_session") is True
