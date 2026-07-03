"""Shared pytest fixtures for the ddev-fleet test suite.

Fixtures are added incrementally as later tasks need them:
task 6 adds ``FakeRunner``/``git_repo``.
"""

import subprocess

import pytest

from fleet.core.runner import RunResult

SAMPLE_REGISTRY_YAML = """\
fleet:
  domain: fleet.example.test   # Wildcard DNS root
  assets_path: {assets_path}
  instances_path: {instances_path}

projects:
  demo:
    git: git@example.test:org/demo.git
    post_deploy:
      - echo project-default
    instances:
      develop:
        branch: main
      custom:
        branch: feature-x
        post_deploy:
          - echo instance-override
"""


@pytest.fixture
def fleet_home(tmp_path):
    home = tmp_path / "fleet-home"
    (home / "assets").mkdir(parents=True)
    (home / "instances").mkdir(parents=True)
    (home / "locks").mkdir(parents=True)
    return home


@pytest.fixture
def sample_registry_text(fleet_home):
    return SAMPLE_REGISTRY_YAML.format(
        assets_path=str(fleet_home / "assets"),
        instances_path=str(fleet_home / "instances"),
    )


class FakeRunner:
    """Records every call and returns a scripted RunResult per exact argv join,
    falling back to a default RunResult otherwise."""

    def __init__(
        self,
        scripted: dict[str, RunResult] | None = None,
        default: RunResult | None = None,
    ):
        self.calls: list[dict] = []
        self._scripted = scripted or {}
        self._default = default if default is not None else RunResult(returncode=0, lines=[])

    def __call__(self, cmd, *, cwd=None, env=None, log_path=None, echo=True):
        key = " ".join(str(c) for c in cmd)
        self.calls.append({"cmd": list(cmd), "cwd": cwd, "env": env, "log_path": log_path})
        return self._scripted.get(key, self._default)


def _run_git(cmd, cwd):
    subprocess.run(cmd, cwd=str(cwd), check=True, capture_output=True, text=True)


@pytest.fixture
def git_repo(tmp_path):
    origin = tmp_path / "origin.git"
    origin.mkdir()
    _run_git(["git", "init", "--bare", "--initial-branch=main", str(origin)], cwd=tmp_path)

    work = tmp_path / "work"
    work.mkdir()
    _run_git(["git", "init", "--initial-branch=main", str(work)], cwd=tmp_path)
    _run_git(["git", "config", "user.email", "test@example.test"], cwd=work)
    _run_git(["git", "config", "user.name", "Test"], cwd=work)
    (work / "README.md").write_text("hello\n", encoding="utf-8")
    _run_git(["git", "add", "README.md"], cwd=work)
    _run_git(["git", "commit", "-m", "initial commit"], cwd=work)
    _run_git(["git", "remote", "add", "origin", str(origin)], cwd=work)
    _run_git(["git", "push", "-u", "origin", "main"], cwd=work)

    return {"origin": origin, "work": work}
