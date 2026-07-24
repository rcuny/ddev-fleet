import pytest

from fleet.core import caddyauth, caddyports
from fleet.core.errors import CaddyPortsError, FleetError, ValidationError
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
    profile = PortProfile(name="playwright", public=9324, router=8323)
    path = caddyports.write_port_snippet(
        "fleet.example.test",
        profile,
        snippet_dir=snippet_dir,
    )
    assert path.exists()
    assert path == snippet_dir / "playwright.conf"
    assert path.read_text(encoding="utf-8") == caddyports.render_port_snippet(
        "fleet.example.test", profile
    )


def test_port_snippet_path_rejects_path_traversal_name(tmp_path):
    with pytest.raises(ValidationError):
        caddyports.port_snippet_path("../../etc/evil", snippet_dir=tmp_path)


def test_write_port_snippet_rejects_path_traversal_name(tmp_path):
    with pytest.raises(ValidationError):
        caddyports.write_port_snippet(
            "fleet.example.test",
            PortProfile(name="../../etc/evil", public=9324, router=8323),
            snippet_dir=tmp_path,
        )
    # nothing should have escaped the snippet directory
    assert list(tmp_path.rglob("*")) == []


def test_remove_port_snippet_rejects_path_traversal_name(tmp_path):
    with pytest.raises(ValidationError):
        caddyports.remove_port_snippet("../../etc/evil", snippet_dir=tmp_path)


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

    # Rollback contract: a snippet CREATED by this sync() call must be
    # rolled back (deleted) on validate failure, not left on disk — an
    # out-of-band `systemctl restart caddy` re-reads the Caddyfile from
    # disk and would otherwise hit the invalid snippet, taking every
    # instance's public URL down.
    assert not (snippet_dir / "playwright.conf").exists()
    reload_calls = [c for c in fake.calls if c["cmd"][:2] == ["caddy", "reload"]]
    assert reload_calls == []


def test_sync_rolls_back_modified_snippet_to_prior_content_on_validate_failure(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    ok_runner = FakeRunner(default=RunResult(returncode=0, lines=[]))
    old_profile = PortProfile(name="playwright", public=9324, router=8323)
    caddyports.sync(
        _StubRegistry("fleet.example.test", [old_profile]),
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=ok_runner,
    )
    prior_content = (snippet_dir / "playwright.conf").read_text(encoding="utf-8")

    renumbered = PortProfile(name="playwright", public=9325, router=8324)
    failing_runner = FakeRunner(default=RunResult(returncode=1, lines=["broken config"]))
    with pytest.raises(CaddyPortsError):
        caddyports.sync(
            _StubRegistry("fleet.example.test", [renumbered]),
            snippet_dir=snippet_dir,
            caddyfile_path=caddyfile_path,
            runner=failing_runner,
        )

    assert (snippet_dir / "playwright.conf").read_text(encoding="utf-8") == prior_content


def test_sync_restores_removed_snippet_on_validate_failure(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    stale = PortProfile(name="stale", public=9200, router=8200)
    caddyports.write_port_snippet("fleet.example.test", stale, snippet_dir=snippet_dir)
    prior_content = (snippet_dir / "stale.conf").read_text(encoding="utf-8")

    fake = FakeRunner(default=RunResult(returncode=1, lines=["broken config"]))
    registry = _StubRegistry("fleet.example.test", [])  # stale lost its last subscriber

    with pytest.raises(CaddyPortsError):
        caddyports.sync(
            registry, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
        )

    assert (snippet_dir / "stale.conf").read_text(encoding="utf-8") == prior_content


def test_sync_rollback_compound_case_restores_directory_byte_for_byte(tmp_path):
    """Several snippets written/removed in one batch, validate fails: the
    directory afterwards must be byte-for-byte identical to before the
    call — covers created + modified + removed + untouched all at once."""
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    ok_runner = FakeRunner(default=RunResult(returncode=0, lines=[]))

    kept = PortProfile(name="typesense", public=9108, router=8108)
    to_be_removed = PortProfile(name="stale", public=9200, router=8200)
    to_be_modified = PortProfile(name="playwright", public=9324, router=8323)
    caddyports.sync(
        _StubRegistry("fleet.example.test", [kept, to_be_removed, to_be_modified]),
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=ok_runner,
    )

    before = {p.name: p.read_text(encoding="utf-8") for p in snippet_dir.glob("*.conf")}

    renumbered = PortProfile(name="playwright", public=9325, router=8324)
    new_port = PortProfile(name="ts-dashboard", public=9111, router=8110)
    failing_runner = FakeRunner(default=RunResult(returncode=1, lines=["broken config"]))
    with pytest.raises(CaddyPortsError):
        caddyports.sync(
            _StubRegistry("fleet.example.test", [kept, renumbered, new_port]),
            snippet_dir=snippet_dir,
            caddyfile_path=caddyfile_path,
            runner=failing_runner,
        )

    after = {p.name: p.read_text(encoding="utf-8") for p in snippet_dir.glob("*.conf")}
    assert after == before


def test_sync_wraps_missing_caddy_binary_in_caddy_ports_error_on_validate(tmp_path):
    """`run_streamed` raises a bare FleetError when the `caddy` binary is
    missing/Popen fails — sync() must wrap that in CaddyPortsError (so a
    caller doing `except CaddyPortsError` doesn't silently miss it), and
    still roll back the snippet it just created."""
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"

    def _raising_runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        raise FleetError("command not found: caddy")

    registry = _StubRegistry(
        "fleet.example.test", [PortProfile(name="playwright", public=9324, router=8323)]
    )

    with pytest.raises(CaddyPortsError) as excinfo:
        caddyports.sync(
            registry,
            snippet_dir=snippet_dir,
            caddyfile_path=caddyfile_path,
            runner=_raising_runner,
        )

    assert isinstance(excinfo.value.__cause__, FleetError)
    # validate never returned, so the newly-created snippet must not be
    # left on disk.
    assert not (snippet_dir / "playwright.conf").exists()


def test_sync_wraps_missing_caddy_binary_in_caddy_ports_error_on_reload(tmp_path):
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"

    def _runner(cmd, *, cwd=None, env=None, log_path=None, echo=True):
        if cmd[:2] == ["caddy", "validate"]:
            return RunResult(returncode=0, lines=[])
        raise FleetError("command not found: caddy")

    registry = _StubRegistry(
        "fleet.example.test", [PortProfile(name="playwright", public=9324, router=8323)]
    )

    with pytest.raises(CaddyPortsError) as excinfo:
        caddyports.sync(
            registry, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=_runner
        )

    assert isinstance(excinfo.value.__cause__, FleetError)
    # validate succeeded, so this is the existing (unchanged) reload-failure
    # contract: no rollback, the snippet stays written.
    assert (snippet_dir / "playwright.conf").exists()


def test_sync_logs_when_removing_an_unrecognised_snippet(tmp_path, caplog):
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    caddyports.write_port_snippet(
        "fleet.example.test",
        PortProfile(name="stale", public=9200, router=8200),
        snippet_dir=snippet_dir,
    )
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    registry = _StubRegistry("fleet.example.test", [])

    with caplog.at_level("WARNING", logger="fleet.core.caddyports"):
        result = caddyports.sync(
            registry, snippet_dir=snippet_dir, caddyfile_path=caddyfile_path, runner=fake
        )

    assert result.removed == ["stale"]
    assert any("stale" in record.message for record in caplog.records)


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


def test_sync_rolls_back_and_raises_caddy_ports_error_when_write_loop_fails_partway(
    tmp_path, monkeypatch
):
    """CRITICAL 1 (write side): the write loop has no try/except today, so a
    raise partway through a multi-file batch (permission error, ENOSPC) skips
    `_rollback()` entirely and the raw exception escapes unwrapped. Regression
    test: write a 3-port batch where the 2nd actual write (the 1st, "typesense",
    is unchanged and skipped) raises — earlier writes in the batch must be
    rolled back and the error must surface as CaddyPortsError chaining the
    original exception."""
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    ok_runner = FakeRunner(default=RunResult(returncode=0, lines=[]))

    kept = PortProfile(name="typesense", public=9108, router=8108)
    caddyports.sync(
        _StubRegistry("fleet.example.test", [kept]),
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=ok_runner,
    )
    before = {p.name: p.read_text(encoding="utf-8") for p in snippet_dir.glob("*.conf")}

    beta = PortProfile(name="beta", public=9200, router=8200)
    gamma = PortProfile(name="gamma", public=9300, router=8300)
    real_write = caddyports.write_port_snippet

    def _flaky_write(domain, profile, *, snippet_dir):
        if profile.name == "gamma":
            raise OSError("ENOSPC: no space left on device")
        return real_write(domain, profile, snippet_dir=snippet_dir)

    monkeypatch.setattr(caddyports, "write_port_snippet", _flaky_write)

    fake2 = FakeRunner(default=RunResult(returncode=0, lines=[]))
    with pytest.raises(CaddyPortsError) as excinfo:
        caddyports.sync(
            _StubRegistry("fleet.example.test", [kept, beta, gamma]),
            snippet_dir=snippet_dir,
            caddyfile_path=caddyfile_path,
            runner=fake2,
        )

    assert isinstance(excinfo.value.__cause__, OSError)
    after = {p.name: p.read_text(encoding="utf-8") for p in snippet_dir.glob("*.conf")}
    assert after == before
    # The exception must have been caught inside the write loop, before
    # validate/reload were ever attempted.
    assert fake2.calls == []


def test_sync_rolls_back_and_raises_caddy_ports_error_when_remove_loop_fails_partway(
    tmp_path, monkeypatch
):
    """CRITICAL 1 (remove side): same defect on the remove loop. Two orphaned
    snippets are queued for removal; the removal that runs second (by actual
    call order, not by name — `existing_names - wanted.keys()` is a set with
    unspecified iteration order) raises. The first removal already mutated
    disk before the exception, so this only reproduces if rollback restores
    it too."""
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    ok_runner = FakeRunner(default=RunResult(returncode=0, lines=[]))

    stale1 = PortProfile(name="stale1", public=9200, router=8200)
    stale2 = PortProfile(name="stale2", public=9201, router=8201)
    kept = PortProfile(name="typesense", public=9108, router=8108)
    caddyports.sync(
        _StubRegistry("fleet.example.test", [stale1, stale2, kept]),
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=ok_runner,
    )
    before = {p.name: p.read_text(encoding="utf-8") for p in snippet_dir.glob("*.conf")}

    real_remove = caddyports.remove_port_snippet
    call_count = []

    def _flaky_remove(name, *, snippet_dir):
        call_count.append(name)
        if len(call_count) == 2:
            raise PermissionError("EACCES: permission denied")
        return real_remove(name, snippet_dir=snippet_dir)

    monkeypatch.setattr(caddyports, "remove_port_snippet", _flaky_remove)

    fake2 = FakeRunner(default=RunResult(returncode=0, lines=[]))
    with pytest.raises(CaddyPortsError) as excinfo:
        caddyports.sync(
            _StubRegistry("fleet.example.test", [kept]),  # both stale1 & stale2 now orphaned
            snippet_dir=snippet_dir,
            caddyfile_path=caddyfile_path,
            runner=fake2,
        )

    assert isinstance(excinfo.value.__cause__, PermissionError)
    after = {p.name: p.read_text(encoding="utf-8") for p in snippet_dir.glob("*.conf")}
    assert after == before
    assert fake2.calls == []


def test_sync_raises_caddy_ports_error_naming_both_failures_when_rollback_itself_fails(
    tmp_path, monkeypatch
):
    """CRITICAL 2: `_rollback()` is unwrapped at every call site today, so a
    failing rollback (disk full, permission denied while restoring) destroys
    the original validate-failure error. The resulting error must name BOTH
    problems and chain the rollback failure (the exception actually raised
    at the point of the final `raise`) via `from exc`."""
    snippet_dir = tmp_path / "ports"
    caddyfile_path = tmp_path / "Caddyfile"
    ok_runner = FakeRunner(default=RunResult(returncode=0, lines=[]))

    old_profile = PortProfile(name="playwright", public=9324, router=8323)
    caddyports.sync(
        _StubRegistry("fleet.example.test", [old_profile]),
        snippet_dir=snippet_dir,
        caddyfile_path=caddyfile_path,
        runner=ok_runner,
    )

    real_atomic_write = caddyauth._atomic_write

    def _flaky_atomic_write(path, content, *, prefix, mode=0o640):
        if "rollback" in prefix:
            raise OSError("ENOSPC: no space left on device")
        return real_atomic_write(path, content, prefix=prefix, mode=mode)

    monkeypatch.setattr(caddyauth, "_atomic_write", _flaky_atomic_write)

    renumbered = PortProfile(name="playwright", public=9325, router=8324)
    failing_runner = FakeRunner(default=RunResult(returncode=1, lines=["broken config"]))

    with pytest.raises(CaddyPortsError) as excinfo:
        caddyports.sync(
            _StubRegistry("fleet.example.test", [renumbered]),
            snippet_dir=snippet_dir,
            caddyfile_path=caddyfile_path,
            runner=failing_runner,
        )

    message = str(excinfo.value).lower()
    assert "validat" in message
    assert "rollback" in message
    assert isinstance(excinfo.value.__cause__, OSError)
