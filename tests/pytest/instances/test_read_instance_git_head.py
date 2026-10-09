from pathlib import Path

from fleet.core.instances import read_instance_git_head
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def _key(d: Path, *rest: str) -> str:
    return " ".join(["git", "-C", str(d), *rest])


def test_returns_short_head_against_real_git(git_repo):
    """DEFAULT runner (real subprocess) against an actual checkout — the short
    HEAD is a non-empty hex string."""
    work = git_repo["work"]
    head = read_instance_git_head(work)
    assert head and all(c in "0123456789abcdef" for c in head)


def test_returns_short_head(tmp_path):
    d = tmp_path / "oak--dev-1"
    fake = FakeRunner(
        scripted={
            _key(d, "rev-parse", "--short", "HEAD"): RunResult(0, ["9201b89b53"]),
        }
    )
    assert read_instance_git_head(d, runner=fake) == "9201b89b53"


def test_returns_empty_on_git_error(tmp_path):
    d = tmp_path / "not-a-repo"
    fake = FakeRunner(
        scripted={
            _key(d, "rev-parse", "--short", "HEAD"): RunResult(
                128, ["fatal: not a git repository"]
            ),
        }
    )
    assert read_instance_git_head(d, runner=fake) == ""


def test_returns_empty_when_runner_raises(tmp_path):
    d = tmp_path / "oak--dev-1"

    def boom(*a, **k):
        raise OSError("git missing")

    assert read_instance_git_head(d, runner=boom) == ""


def test_returns_empty_on_timeout(tmp_path):
    import subprocess

    d = tmp_path / "oak--dev-1"

    def timing_out(cmd, **kwargs):
        raise subprocess.TimeoutExpired(cmd, kwargs.get("timeout"))

    assert read_instance_git_head(d, timeout=10.0, runner=timing_out) == ""


def test_timeout_defaults_to_none(tmp_path):
    d = tmp_path / "oak--dev-1"
    fake = FakeRunner(
        scripted={
            _key(d, "rev-parse", "--short", "HEAD"): RunResult(0, ["abc1234"]),
        }
    )
    read_instance_git_head(d, runner=fake)
    assert fake.calls[0]["timeout"] is None


def test_forwards_timeout_to_runner(tmp_path):
    d = tmp_path / "oak--dev-1"
    fake = FakeRunner(
        scripted={
            _key(d, "rev-parse", "--short", "HEAD"): RunResult(0, ["abc1234"]),
        }
    )
    read_instance_git_head(d, timeout=10.0, runner=fake)
    assert fake.calls[0]["timeout"] == 10.0
