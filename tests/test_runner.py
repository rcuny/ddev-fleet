import pytest

from fleet.core.errors import FleetError
from fleet.core.runner import run_streamed


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
