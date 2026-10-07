import importlib.metadata
import subprocess

import pytest

from fleet.core import hostinfo


def test_hostname_returns_gethostname(monkeypatch):
    monkeypatch.setattr(hostinfo.socket, "gethostname", lambda: "ddev9")
    assert hostinfo.hostname() == "ddev9"


def test_hostname_falls_back_to_unknown_when_empty(monkeypatch):
    monkeypatch.setattr(hostinfo.socket, "gethostname", lambda: "")
    assert hostinfo.hostname() == "unknown"


def _git(repo, *args):
    subprocess.run(
        ["git", "-c", "user.email=t@example.test", "-c", "user.name=T", *args],
        cwd=repo,
        check=True,
        capture_output=True,
        text=True,
    )


def _commit(repo, name):
    (repo / name).write_text(name, encoding="utf-8")
    _git(repo, "add", name)
    _git(repo, "commit", "-m", name)


@pytest.fixture
def repo(tmp_path):
    path = tmp_path / "repo"
    path.mkdir()
    _git(path, "init", "--initial-branch=main")
    return path


def _fake_metadata(monkeypatch, value):
    def fake(name):
        assert name == "ddev-fleet"
        if value is None:
            raise importlib.metadata.PackageNotFoundError(name)
        return value

    monkeypatch.setattr(hostinfo.importlib.metadata, "version", fake)


def test_fleet_version_exactly_on_tag(repo):
    _commit(repo, "a")
    _git(repo, "tag", "-a", "v1.2.3", "-m", "x")
    assert hostinfo.fleet_version(repo) == "v1.2.3"


def test_fleet_version_past_tag_shows_commit_count(repo):
    _commit(repo, "a")
    _git(repo, "tag", "-a", "v1.2.3", "-m", "x")
    _commit(repo, "b")
    assert hostinfo.fleet_version(repo).startswith("v1.2.3-1-g")


def test_fleet_version_falls_back_to_metadata_without_git(tmp_path, monkeypatch):
    _fake_metadata(monkeypatch, "9.9.9")
    assert hostinfo.fleet_version(tmp_path) == "v9.9.9"


def test_fleet_version_unknown_without_git_or_metadata(tmp_path, monkeypatch):
    _fake_metadata(monkeypatch, None)
    assert hostinfo.fleet_version(tmp_path) == "unknown"


def test_fleet_version_falls_back_to_metadata_when_describe_fails(repo, monkeypatch):
    _commit(repo, "a")  # a git repo with no tags: `git describe --tags` exits 128
    _fake_metadata(monkeypatch, "9.9.9")
    assert hostinfo.fleet_version(repo) == "v9.9.9"


def test_fleet_version_falls_back_when_git_missing(repo, monkeypatch):
    def boom(*a, **k):
        raise FileNotFoundError("git")

    monkeypatch.setattr(hostinfo.subprocess, "run", boom)
    _fake_metadata(monkeypatch, "9.9.9")
    assert hostinfo.fleet_version(repo) == "v9.9.9"
