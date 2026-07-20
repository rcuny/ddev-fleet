from pathlib import Path

from fleet.core.instances import read_instance_git_branch
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def _key(d: Path, *rest: str) -> str:
    return " ".join(["git", "-C", str(d), *rest])


def test_returns_current_branch(tmp_path):
    d = tmp_path / "oak--dev-1"
    fake = FakeRunner(
        scripted={
            _key(d, "rev-parse", "--abbrev-ref", "HEAD"): RunResult(0, ["feature/live-branch"]),
        }
    )
    assert read_instance_git_branch(d, runner=fake) == "feature/live-branch"


def test_detached_head_returns_short_sha(tmp_path):
    d = tmp_path / "oak--dev-1"
    fake = FakeRunner(
        scripted={
            _key(d, "rev-parse", "--abbrev-ref", "HEAD"): RunResult(0, ["HEAD"]),
            _key(d, "rev-parse", "--short", "HEAD"): RunResult(0, ["abc1234"]),
        }
    )
    assert read_instance_git_branch(d, runner=fake) == "abc1234"


def test_returns_empty_on_git_error(tmp_path):
    d = tmp_path / "not-a-repo"
    fake = FakeRunner(
        scripted={
            _key(d, "rev-parse", "--abbrev-ref", "HEAD"): RunResult(
                128, ["fatal: not a git repository"]
            ),
        }
    )
    assert read_instance_git_branch(d, runner=fake) == ""


def test_returns_empty_when_runner_raises(tmp_path):
    d = tmp_path / "oak--dev-1"

    def boom(*a, **k):
        raise OSError("git missing")

    assert read_instance_git_branch(d, runner=boom) == ""
