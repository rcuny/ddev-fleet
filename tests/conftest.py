"""Shared pytest fixtures for the ddev-fleet test suite.

Fixtures are added incrementally as later tasks need them:
task 6 adds ``FakeRunner``/``git_repo``.
"""

import os
import shutil
import subprocess
import tempfile
from pathlib import Path

import pytest

from fleet.core import caddyauth, caddyports
from fleet.core.runner import RunResult


@pytest.fixture(autouse=True)
def _isolate_caddy_paths(monkeypatch, tmp_path):
    """Redirect the default per-instance Caddy auth paths away from the real
    `/etc/caddy` for every test, fleet-suite-wide.

    `fleet.core.instances.deploy()`/`destroy()` resolve their
    `auth_snippet_dir`/`auth_caddyfile_path` at CALL time from
    `caddyauth.DEFAULT_INSTANCE_SNIPPET_DIR`/`DEFAULT_CADDYFILE_PATH` when
    the caller doesn't pass an explicit override (see the None-sentinel
    resolution in instances.py) — precisely so this one fixture can isolate
    every real (non-monkeypatched) deploy()/destroy() call in the suite
    without threading tmp_path overrides through ~30 individual call sites.
    Harmless for tests that don't touch instances.deploy()/destroy() at all.

    Also redirects `caddyports.DEFAULT_PORTS_SNIPPET_DIR` for the same
    reason — `deploy()`/`destroy()` now also call `caddyports.sync()`.
    """
    monkeypatch.setattr(caddyauth, "DEFAULT_INSTANCE_SNIPPET_DIR", tmp_path / "caddy-instances")
    monkeypatch.setattr(caddyauth, "DEFAULT_CADDYFILE_PATH", tmp_path / "Caddyfile")
    monkeypatch.setattr(caddyports, "DEFAULT_PORTS_SNIPPET_DIR", tmp_path / "caddy-ports")


SAMPLE_REGISTRY_YAML = """\
fleet:
  domain: fleet.example.test   # Wildcard DNS root

projects:
  demo:
    git: git@example.test:org/demo.git
    default_template: default
    default_branch: main
    templates:
      default:
        post_deploy:
          - echo project-default
      custom:
        post_deploy:
          - echo instance-override
"""


@pytest.fixture
def fleet_home(tmp_path):
    home = tmp_path / "fleet-home"
    (home / "config" / "assets").mkdir(parents=True)
    (home / "instances").mkdir(parents=True)
    (home / "logs").mkdir(parents=True)
    (home / "locks").mkdir(parents=True)
    return home


@pytest.fixture
def sample_registry_text():
    return SAMPLE_REGISTRY_YAML


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

    def __call__(
        self, cmd, *, cwd=None, env=None, log_path=None, echo=True, input_text=None, timeout=None
    ):
        key = " ".join(str(c) for c in cmd)
        self.calls.append(
            {
                "cmd": list(cmd),
                "cwd": cwd,
                "env": env,
                "log_path": log_path,
                "input_text": input_text,
                "timeout": timeout,
            }
        )
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


def require_tool(name: str) -> None:
    """Skip when `name` is not installed; fail instead when
    FLEET_REQUIRE_PGP_TOOLS=1 (CI), so a missing tool cannot silently turn a
    security test into a skip. Mirrors FLEET_REQUIRE_SYSTEMD_ANALYZE."""
    if shutil.which(name):
        return
    if os.environ.get("FLEET_REQUIRE_PGP_TOOLS") == "1":
        pytest.fail(f"FLEET_REQUIRE_PGP_TOOLS=1 but {name!r} is not installed")
    pytest.skip(f"{name} is not installed")


@pytest.fixture
def requires_gpg():
    require_tool("gpg")


def _short_tmp_root() -> str | None:
    # gpg-agent sockets live in GNUPGHOME and unix socket paths are limited to
    # about 100 characters; pytest's tmp_path can be longer than that.
    return "/tmp" if os.path.isdir("/tmp") else None


def _kill_gpg_agent(gnupghome: Path) -> None:
    if not gnupghome.is_dir():
        return
    try:
        subprocess.run(
            ["gpgconf", "--kill", "gpg-agent"],
            env={**os.environ, "GNUPGHOME": str(gnupghome)},
            capture_output=True,
            check=False,
            timeout=30,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired):
        pass


@pytest.fixture
def gpg_home_factory(requires_gpg):
    """Callable returning a fresh, short, throwaway GNUPGHOME (0700). Agents
    are killed and the directories removed on teardown."""
    created: list[Path] = []

    def make() -> Path:
        home = Path(tempfile.mkdtemp(prefix="fgpg-", dir=_short_tmp_root()))
        created.append(home)
        return home

    yield make
    for home in created:
        _kill_gpg_agent(home)
        shutil.rmtree(home, ignore_errors=True)


@pytest.fixture
def gpg_home(gpg_home_factory) -> Path:
    return gpg_home_factory()


@pytest.fixture
def gpg_fleet_home(requires_gpg):
    """Like `fleet_home` but short enough for a gpg-agent socket under
    `<home>/gnupg`. Use for tests that run the real gpg through FleetPaths."""
    home = Path(tempfile.mkdtemp(prefix="fl-", dir=_short_tmp_root()))
    (home / "config" / "assets").mkdir(parents=True)
    (home / "instances").mkdir()
    (home / "logs").mkdir()
    (home / "locks").mkdir()
    yield home
    _kill_gpg_agent(home / "gnupg")
    shutil.rmtree(home, ignore_errors=True)
