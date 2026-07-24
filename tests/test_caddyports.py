import pytest

from fleet.core import caddyports
from fleet.core.errors import CaddyPortsError
from fleet.core.registry import PortProfile
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def test_render_port_snippet_shape():
    out = caddyports.render_port_snippet(
        "fleet.example.test", PortProfile(name="playwright", public=9324, router=8323)
    )
    assert out == (
        "*.fleet.example.test:9324 {\n"
        "    reverse_proxy 127.0.0.1:8323\n"
        "    tls { on_demand }\n"
        "}\n"
    )


def test_write_port_snippet_is_atomic_leaves_no_tmp_file_behind(tmp_path):
    snippet_dir = tmp_path / "ports"
    snippet_dir.mkdir()
    caddyports.write_port_snippet(
        "fleet.example.test",
        PortProfile(name="playwright", public=9324, router=8323),
        snippet_dir=snippet_dir,
    )
    leftover = [p for p in snippet_dir.iterdir() if p.name != "playwright.conf"]
    assert leftover == []


def test_write_port_snippet_creates_parent_directory(tmp_path):
    snippet_dir = tmp_path / "does" / "not" / "exist"
    path = caddyports.write_port_snippet(
        "fleet.example.test",
        PortProfile(name="playwright", public=9324, router=8323),
        snippet_dir=snippet_dir,
    )
    assert path.exists()
    assert path == snippet_dir / "playwright.conf"


def test_remove_port_snippet_returns_false_when_nothing_to_remove(tmp_path):
    snippet_dir = tmp_path / "ports"
    assert caddyports.remove_port_snippet("ghost", snippet_dir=snippet_dir) is False


def test_remove_port_snippet_returns_true_when_removed(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyports.write_port_snippet(
        "fleet.example.test",
        PortProfile(name="playwright", public=9324, router=8323),
        snippet_dir=snippet_dir,
    )
    assert caddyports.remove_port_snippet("playwright", snippet_dir=snippet_dir) is True
    assert not (snippet_dir / "playwright.conf").exists()


class _StubRegistry:
    def __init__(self, domain, profiles):
        self.domain = domain
        self._profiles = profiles

    def all_port_profiles(self):
        return self._profiles


def test_sync_writes_new_subscribers_snippet(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    registry = _StubRegistry(
        "fleet.example.test", [PortProfile(name="playwright", public=9324, router=8323)]
    )

    result = caddyports.sync(
        registry, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
    )

    assert result.written == ["playwright"]
    assert result.removed == []
    assert (snippet_dir / "playwright.conf").exists()
    assert [c["cmd"][0:2] for c in fake.calls] == [["caddy", "validate"], ["caddy", "reload"]]


def test_sync_removes_orphaned_snippet(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    caddyports.write_port_snippet(
        "fleet.example.test",
        PortProfile(name="stale", public=9200, router=8200),
        snippet_dir=snippet_dir,
    )
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    registry = _StubRegistry("fleet.example.test", [])

    result = caddyports.sync(
        registry, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
    )

    assert result.written == []
    assert result.removed == ["stale"]
    assert not (snippet_dir / "stale.conf").exists()


def test_sync_is_a_noop_when_nothing_changed(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    profile = PortProfile(name="playwright", public=9324, router=8323)
    registry = _StubRegistry("fleet.example.test", [profile])

    caddyports.sync(
        registry,
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=FakeRunner(default=RunResult(returncode=0, lines=[])),
    )

    # A runner that fails on ANY call, to prove the second sync() call
    # makes zero runner calls.
    fake = FakeRunner(default=RunResult(returncode=1, lines=["should never be called"]))
    result = caddyports.sync(
        registry, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
    )

    assert result.written == []
    assert result.removed == []
    assert fake.calls == []


def test_sync_renumbered_port_rewrites_snippet_in_place(tmp_path):
    """Same port NAME, different public/router numbers — must rewrite, not
    skip (the snippet is named by registry key, not port number, spec §4)."""
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    old = PortProfile(name="playwright", public=9324, router=8323)
    caddyports.sync(
        _StubRegistry("fleet.example.test", [old]),
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=fake,
    )

    renumbered = PortProfile(name="playwright", public=9325, router=8324)
    result = caddyports.sync(
        _StubRegistry("fleet.example.test", [renumbered]),
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=fake,
    )

    assert result.written == ["playwright"]
    content = (snippet_dir / "playwright.conf").read_text(encoding="utf-8")
    assert "9325" in content
    assert "8324" in content


def test_sync_does_exactly_one_validate_and_reload_for_a_multi_port_batch(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    registry = _StubRegistry(
        "fleet.example.test",
        [
            PortProfile(name="typesense", public=9108, router=8108),
            PortProfile(name="playwright", public=9324, router=8323),
            PortProfile(name="ts-dashboard", public=9111, router=8110),
        ],
    )

    result = caddyports.sync(
        registry, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
    )

    assert sorted(result.written) == ["playwright", "ts-dashboard", "typesense"]
    assert len(fake.calls) == 2
    assert [c["cmd"][0:2] for c in fake.calls] == [["caddy", "validate"], ["caddy", "reload"]]


def test_sync_raises_caddy_ports_error_and_does_not_reload_on_validate_failure(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    fake = FakeRunner(default=RunResult(returncode=1, lines=["broken config"]))
    registry = _StubRegistry(
        "fleet.example.test", [PortProfile(name="playwright", public=9324, router=8323)]
    )

    with pytest.raises(CaddyPortsError, match="NOT reloaded"):
        caddyports.sync(
            registry, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
        )

    # Rollback contract (spec §9): the snippet WAS written (atomic write
    # already landed) — Caddy is simply never told to load it. This is
    # not a file revert, matching caddyauth's documented behavior.
    assert (snippet_dir / "playwright.conf").exists()
    reload_calls = [c for c in fake.calls if c["cmd"][:2] == ["caddy", "reload"]]
    assert reload_calls == []


def test_sync_raises_caddy_ports_error_on_reload_failure(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    scripted = {
        f"caddy validate --config {caddyfile_path} --adapter caddyfile": RunResult(
            returncode=0, lines=[]
        ),
        f"caddy reload --config {caddyfile_path}": RunResult(
            returncode=1, lines=["dial tcp 127.0.0.1:2019: connect: connection refused"]
        ),
    }
    fake = FakeRunner(scripted=scripted)
    registry = _StubRegistry(
        "fleet.example.test", [PortProfile(name="playwright", public=9324, router=8323)]
    )

    with pytest.raises(CaddyPortsError, match="reload manually"):
        caddyports.sync(
            registry, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
        )
