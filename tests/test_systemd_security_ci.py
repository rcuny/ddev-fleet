"""FLE-8: ci/systemd_security.py (offline scoring, baseline compare, regression
detection) and ci/systemd-security-live.sh.

ci/ is not a package, so the script is loaded by path. The end-to-end tests
score real units and need systemd-analyze; like tests/test_systemd_sandbox.py
they skip without it unless FLEET_REQUIRE_SYSTEMD_ANALYZE is set (CI), in which
case a missing binary fails the run."""

from __future__ import annotations

import importlib.util
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]


def _load(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


ci = _load("systemd_security_ci", ROOT / "ci" / "systemd_security.py")
sandbox = _load("systemd_sandbox_tests", ROOT / "tests" / "test_systemd_sandbox.py")

BASELINE_FILE = ROOT / "ci" / "systemd-security-baseline.json"
HAVE_ANALYZE = shutil.which("systemd-analyze") is not None


def _require_analyze():
    if HAVE_ANALYZE:
        return
    if os.environ.get("FLEET_REQUIRE_SYSTEMD_ANALYZE"):
        pytest.fail("FLEET_REQUIRE_SYSTEMD_ANALYZE is set but systemd-analyze is not installed")
    pytest.skip("systemd-analyze not installed")


@pytest.fixture
def analyze():
    _require_analyze()


def _report(units: dict[str, dict], *, systemd="257", missing=(), overview=None) -> dict:
    return {
        "schema": 1,
        "host": "test",
        "systemd": systemd,
        "generated": "2026-10-06T00:00:00Z",
        "overview": overview if overview is not None else {u: d["score"] for u, d in units.items()},
        "units": units,
        "missing": list(missing),
    }


def _unit(score: float, **directives: float) -> dict:
    return {"score": score, "directives": directives}


def _write(path: Path, data: dict) -> Path:
    path.write_text(json.dumps(data), encoding="utf-8")
    return path


def _baseline(tmp_path: Path, **envs: dict) -> Path:
    env_entries = {
        name: {"systemd": rep["systemd"], "recorded": "2026-10-01", **rep}
        for name, rep in envs.items()
    }
    return _write(tmp_path / "baseline.json", {"tolerance": 0.1, "environments": env_entries})


def _run(tmp_path, capsys, *argv) -> tuple[int, str, str]:
    code = ci.main([str(a) for a in argv])
    out = capsys.readouterr()
    return code, out.out, out.err


def _compare(tmp_path, capsys, baseline: dict | None, current: dict, env="ddev3", *extra):
    base = _baseline(tmp_path, **({env: baseline} if baseline else {}))
    cur = _write(tmp_path / "current.json", current)
    return _run(
        tmp_path,
        capsys,
        "compare",
        "--baseline",
        base,
        "--env",
        env,
        "--current",
        cur,
        "--report-md",
        tmp_path / "out" / "r.md",
        "--report-json",
        tmp_path / "out" / "r.json",
        *extra,
    )


def _statuses(rows):
    return {r["unit"]: r["status"] for r in rows}


# --- compare logic -------------------------------------------------------------


def test_every_status_is_reported():
    base = _report(
        {
            "a.service": _unit(1.8),
            "b.service": _unit(2.0),
            "gone.service": _unit(3.0),
            "ok.service": _unit(1.0),
            "c.service": _unit(4.0),
        }
    )
    cur = _report(
        {
            "a.service": _unit(2.6),
            "b.service": _unit(1.5),
            "new.service": _unit(5.0),
            "ok.service": _unit(1.05),
            "c.service": _unit(4.0),
        }
    )
    rows = ci.compare_reports(base, cur, 0.1)
    assert _statuses(rows) == {
        "a.service": "REGRESSION",
        "b.service": "IMPROVED",
        "gone.service": "GONE",
        "new.service": "NEW",
        "ok.service": "OK",
        "c.service": "OK",
    }
    assert rows[0]["unit"] == "a.service"  # regressions first
    assert rows[0]["delta"] == pytest.approx(0.8)


@pytest.mark.parametrize(
    "current,status",
    [(1.8, "OK"), (1.9, "OK"), (2.0, "REGRESSION"), (1.7, "OK"), (1.6, "IMPROVED")],
)
def test_tolerance_edges(current, status):
    base = _report({"a.service": _unit(1.8)})
    rows = ci.compare_reports(base, _report({"a.service": _unit(current)}), 0.1)
    assert rows[0]["status"] == status


def test_tolerance_is_configurable():
    base = _report({"a.service": _unit(1.8)})
    cur = _report({"a.service": _unit(2.2)})
    assert ci.compare_reports(base, cur, 0.1)[0]["status"] == "REGRESSION"
    assert ci.compare_reports(base, cur, 0.5)[0]["status"] == "OK"


def test_overview_scores_are_compared_too():
    base = _report(
        {"fleet.service": _unit(1.8)}, overview={"fleet.service": 1.8, "ssh.service": 9.6}
    )
    cur = _report(
        {"fleet.service": _unit(1.8)}, overview={"fleet.service": 1.8, "ssh.service": 9.9}
    )
    assert _statuses(ci.compare_reports(base, cur, 0.1)) == {
        "fleet.service": "OK",
        "ssh.service": "REGRESSION",
    }


def test_directive_changes_list_what_moved_with_missing_as_zero():
    base = _report({"fleet.service": _unit(1.8, A=0.1, B=0.2, C=0.3)})
    cur = _report({"fleet.service": _unit(2.6, A=0.1, B=0.9, D=0.2)})
    (row,) = ci.compare_reports(base, cur, 0.1)
    assert row["status"] == "REGRESSION"
    changes = {c["directive"]: (c["baseline"], c["current"]) for c in row["directives"]}
    assert changes == {"B": (0.2, 0.9), "C": (0.3, 0.0), "D": (0.0, 0.2)}
    assert [c["directive"] for c in row["directives"]][0] == "B"  # biggest increase first


def test_regression_without_directive_detail_still_reports():
    base = {"overview": {"ssh.service": 5.0}}
    (row,) = ci.compare_reports(base, {"overview": {"ssh.service": 6.0}}, 0.1)
    assert row["status"] == "REGRESSION" and row["directives"] == []


# --- the compare command ---------------------------------------------------------


def test_clean_compare_exits_zero_and_writes_both_reports(tmp_path, capsys):
    rep = _report({"fleet.service": _unit(1.8, A=0.1)})
    code, out, err = _compare(tmp_path, capsys, rep, rep)
    assert code == 0 and err == ""
    assert "| Unit | Baseline | Current | Delta | Status |" in out
    assert "| `fleet.service` | 1.8 | 1.8 | 0.0 | OK |" in out
    assert (tmp_path / "out" / "r.md").read_text() == out
    data = json.loads((tmp_path / "out" / "r.json").read_text())
    assert (
        data["ok"] is True and data["env"] == "ddev3" and data["rows"][0]["unit"] == "fleet.service"
    )


def test_regression_exits_one_names_the_unit_and_lists_directives(tmp_path, capsys):
    base = _report({"fleet.service": _unit(1.8, A=0.1), "other.service": _unit(1.0)})
    cur = _report(
        {"fleet.service": _unit(2.6, A=0.1, ProtectSystem=0.7), "other.service": _unit(1.0)}
    )
    code, out, err = _compare(tmp_path, capsys, base, cur)
    assert code == 1
    assert err.strip() == "REGRESSION fleet.service (ddev3): 1.8 -> 2.6 (+0.8)"
    lines = out.splitlines()
    assert lines.index("| `fleet.service` | 1.8 | 2.6 | +0.8 | **REGRESSION** |") < lines.index(
        "| `other.service` | 1.0 | 1.0 | 0.0 | OK |"
    )
    assert "### Regression: `fleet.service` 1.8 -> 2.6 (+0.8)" in out
    assert "| `ProtectSystem` | 0.0 | 0.7 | +0.7 |" in out
    assert json.loads((tmp_path / "out" / "r.json").read_text())["ok"] is False


def test_improvement_is_reported_with_a_baseline_update_hint_and_does_not_fail(tmp_path, capsys):
    base = _report({"fleet.service": _unit(2.6)})
    cur = _report({"fleet.service": _unit(1.8)})
    code, out, err = _compare(tmp_path, capsys, base, cur)
    assert code == 0 and err == ""
    assert "| IMPROVED |" in out and "consider `update-baseline --env ddev3`" in out


def test_new_and_gone_units_are_reported_but_never_fail(tmp_path, capsys):
    base = _report({"old.service": _unit(2.0), "keep.service": _unit(1.0)})
    cur = _report({"new.service": _unit(9.0), "keep.service": _unit(1.0)})
    code, out, _ = _compare(tmp_path, capsys, base, cur)
    assert code == 0
    assert "| `old.service` | 2.0 | - | - | GONE |" in out
    assert "| `new.service` | - | 9.0 | - | NEW |" in out


def test_tolerance_option_overrides_the_baseline_file(tmp_path, capsys):
    base = _report({"a.service": _unit(1.8)})
    cur = _report({"a.service": _unit(2.2)})
    assert _compare(tmp_path, capsys, base, cur)[0] == 1
    assert _compare(tmp_path, capsys, base, cur, "ddev3", "--tolerance", "0.5")[0] == 0


def test_environment_without_a_baseline_reports_everything_as_new(tmp_path, capsys):
    cur = _report({"fleet.service": _unit(1.8)}, missing=["authelia.service"])
    code, out, err = _compare(tmp_path, capsys, None, cur, "ddev3")
    assert code == 0
    assert "No baseline recorded for `ddev3` yet" in out and "| NEW |" in out
    assert "authelia.service" in out  # not-loaded units are mentioned
    assert "update-baseline" in err and "ddev3" in err


def test_missing_baseline_file_is_the_same_as_no_environment(tmp_path, capsys):
    cur = _write(tmp_path / "c.json", _report({"fleet.service": _unit(1.8)}))
    code, out, err = _run(
        tmp_path,
        capsys,
        "compare",
        "--baseline",
        tmp_path / "nope.json",
        "--env",
        "x",
        "--current",
        cur,
    )
    assert code == 0 and "| NEW |" in out and "NOTICE" in err


def test_a_different_systemd_major_version_is_called_out(tmp_path, capsys):
    base = _report({"a.service": _unit(1.0)}, systemd="257")
    cur = _report({"a.service": _unit(1.0)}, systemd="258")
    code, out, _ = _compare(tmp_path, capsys, base, cur)
    assert code == 0 and "systemd 258 here, baseline recorded with systemd 257" in out


def test_unreadable_current_report_is_an_error_not_a_pass(tmp_path, capsys):
    bad = tmp_path / "c.json"
    bad.write_text("not json")
    code, _, err = _run(tmp_path, capsys, "compare", "--env", "ddev3", "--current", bad)
    assert code == 2 and "invalid JSON" in err


# --- update-baseline ---------------------------------------------------------------


def test_update_baseline_replaces_one_environment_and_keeps_the_rest(tmp_path, capsys):
    base_file = tmp_path / "baseline.json"
    _write(
        base_file,
        {
            "tolerance": 0.3,
            "environments": {
                "offline": {
                    "systemd": "257",
                    "recorded": "2026-01-01",
                    "overview": {},
                    "units": {},
                },
                "ddev3": {
                    "systemd": "257",
                    "recorded": "2026-01-01",
                    "overview": {"x.service": 1.0},
                    "units": {},
                },
            },
        },
    )
    cur = _write(tmp_path / "c.json", _report({"fleet.service": _unit(1.8, A=0.1)}, systemd="258"))
    code, out, _ = _run(
        tmp_path,
        capsys,
        "update-baseline",
        "--baseline",
        base_file,
        "--env",
        "ddev3",
        "--current",
        cur,
    )
    assert code == 0 and "recorded baseline for 'ddev3'" in out
    text = base_file.read_text()
    data = json.loads(text)
    assert data["tolerance"] == 0.3
    assert data["environments"]["offline"]["recorded"] == "2026-01-01"  # untouched
    ddev3 = data["environments"]["ddev3"]
    assert ddev3["systemd"] == "258" and ddev3["units"]["fleet.service"] == _unit(1.8, A=0.1)
    assert ddev3["overview"] == {"fleet.service": 1.8}
    assert ddev3["recorded"] != "2026-01-01"
    # stable formatting: indent=2, sorted keys, trailing newline
    assert text == json.dumps(data, indent=2, sort_keys=True) + "\n"


def test_update_baseline_creates_the_file_with_the_default_tolerance(tmp_path, capsys):
    cur = _write(tmp_path / "c.json", _report({"fleet.service": _unit(1.8)}))
    target = tmp_path / "new" / "baseline.json"
    assert (
        _run(
            tmp_path,
            capsys,
            "update-baseline",
            "--baseline",
            target,
            "--env",
            "offline",
            "--current",
            cur,
        )[0]
        == 0
    )
    data = json.loads(target.read_text())
    assert data["tolerance"] == 0.1 and list(data["environments"]) == ["offline"]


# --- the committed baseline ------------------------------------------------------------


def test_committed_baseline_has_an_offline_environment():
    data = json.loads(BASELINE_FILE.read_text())
    assert data["tolerance"] == 0.1
    offline = data["environments"]["offline"]
    assert set(offline["units"]) == {
        "caddy.service",
        "fleet-boot.service",
        "fleet-reboot-notify.service",
        "fleet.service",
    }
    assert all(u["score"] <= sandbox.THRESHOLD for u in offline["units"].values())
    assert BASELINE_FILE.read_text() == json.dumps(data, indent=2, sort_keys=True) + "\n"


# --- no drift from tests/test_systemd_sandbox.py -------------------------------------------


def test_ci_rendering_matches_the_sandbox_tests():
    assert ci.TEMPLATE_VARS == sandbox.VARS
    assert ci.CADDY_VENDOR_UNIT == sandbox.CADDY_VENDOR_UNIT
    rendered = ci.render_all()
    assert rendered["fleet.service"] == sandbox._fleet()
    assert rendered["fleet-boot.service"] == sandbox._boot()
    assert rendered["fleet-reboot-notify.service"] == sandbox._notify()
    assert rendered["caddy.service"] == sandbox._caddy_dropin()


def test_the_report_script_is_loaded_from_the_role():
    assert ci.REPORT_SCRIPT == ROOT / "ansible/roles/security_probe/files/fleet-security-report"
    assert ci.load_report_module().SCHEMA == 1


# --- end to end with the real systemd-analyze -------------------------------------------------


def test_offline_scores_match_the_baseline(analyze, tmp_path, capsys):
    out = tmp_path / "offline.json"
    code, stdout, _ = _run(tmp_path, capsys, "offline", "--out", out)
    assert code == 0 and "fleet.service" in stdout
    report = json.loads(out.read_text())
    assert report["host"] == "offline" and set(report["units"]) == set(ci.render_all())
    assert all(d["score"] <= sandbox.THRESHOLD for d in report["units"].values())
    code, table, err = _run(
        tmp_path, capsys, "compare", "--env", "offline", "--current", out,
        "--report-md", tmp_path / "r.md", "--report-json", tmp_path / "r.json",
    )  # fmt: skip
    assert code == 0, table + err
    assert "REGRESSION" not in table


def test_dropping_a_sandbox_directive_is_caught_end_to_end(analyze, tmp_path, capsys):
    rendered = ci.render_all()
    assert "ProtectSystem=strict\n" in rendered["fleet.service"]
    rendered["fleet.service"] = rendered["fleet.service"].replace("ProtectSystem=strict\n", "")
    report = ci.collect_offline(rendered, tmp_path)
    out = _write(tmp_path / "regressed.json", report)
    code, table, err = _run(
        tmp_path, capsys, "compare", "--env", "offline", "--current", out,
        "--report-md", tmp_path / "r.md", "--report-json", tmp_path / "r.json",
    )  # fmt: skip
    assert code == 1
    assert err.startswith("REGRESSION fleet.service (offline): 1.8 -> ")
    assert "ProtectSystem" in table  # the directive that moved is listed
    assert "fleet-boot.service` | 1.8 | 1.8 | 0.0 | OK" in table  # the others are untouched


def test_offline_without_systemd_analyze_is_a_clear_error(tmp_path, capsys, monkeypatch):
    monkeypatch.setenv("SYSTEMD_ANALYZE", str(tmp_path / "missing"))
    code, _, err = _run(tmp_path, capsys, "offline", "--out", tmp_path / "o.json")
    assert code == 2 and "not found" in err and "systemd" in err


def test_require_flag_fails_when_systemd_analyze_is_missing(monkeypatch):
    monkeypatch.setattr(sys.modules[__name__], "HAVE_ANALYZE", False)
    monkeypatch.setenv("FLEET_REQUIRE_SYSTEMD_ANALYZE", "1")
    with pytest.raises(pytest.fail.Exception, match="FLEET_REQUIRE_SYSTEMD_ANALYZE"):
        _require_analyze()
    monkeypatch.delenv("FLEET_REQUIRE_SYSTEMD_ANALYZE")
    with pytest.raises(pytest.skip.Exception):
        _require_analyze()


# --- ci/systemd-security-live.sh ------------------------------------------------------------------

LIVE = ROOT / "ci" / "systemd-security-live.sh"
FAKE_SSH = """\
#!/bin/sh
echo "$@" >> "$FAKE_SSH_LOG"
case "$*" in
  *broken*) exit 255 ;;
esac
cat "$FAKE_SSH_REPORT"
"""


@pytest.fixture
def live_env(tmp_path):
    work = tmp_path / "work"
    work.mkdir()
    (work / "ci").symlink_to(ROOT / "ci")
    bin_dir = tmp_path / "bin"
    bin_dir.mkdir()
    ssh = bin_dir / "ssh"
    ssh.write_text(FAKE_SSH)
    ssh.chmod(0o755)
    (bin_dir / "python").symlink_to(sys.executable)
    report = _write(tmp_path / "report.json", _report({"fleet.service": _unit(1.8)}))
    env = {
        **os.environ,
        "PATH": f"{bin_dir}:{os.environ['PATH']}",
        "FAKE_SSH_LOG": str(tmp_path / "ssh.log"),
        "FAKE_SSH_REPORT": str(report),
    }
    env.pop("SECURITY_PROBE_TARGETS", None)
    return work, env


def _live(work, env, **extra):
    return subprocess.run(
        ["bash", str(LIVE)],
        cwd=work,
        env={**env, **extra},
        capture_output=True,
        text=True,
        timeout=60,
    )


def test_live_check_skips_when_no_targets_are_configured(live_env):
    work, env = live_env
    for value in (None, "", "   "):
        extra = {} if value is None else {"SECURITY_PROBE_TARGETS": value}
        done = _live(work, env, **extra)
        assert done.returncode == 0 and "skipping" in done.stdout
    assert not (work / "reports").exists()


def test_live_check_fetches_compares_and_still_checks_every_target_on_failure(live_env):
    work, env = live_env
    done = _live(
        work,
        env,
        SECURITY_PROBE_TARGETS="down=fleet-probe@broken.example fresh=fleet-probe@fresh.example",
    )
    assert done.returncode == 1  # the unreachable host fails the run ...
    assert (work / "reports" / "fresh.json").exists()  # ... but the other one was still checked
    assert (work / "reports" / "systemd-security-fresh.md").exists()
    assert "NEW" in done.stdout  # a host with no baseline yet: reported, not failed
    # ("fresh" never gets a baseline; the live check reads the real baseline file)
    assert "could not fetch the security report from down" in done.stderr
    log = (Path(env["FAKE_SSH_LOG"])).read_text()
    for opt in ("-T", "BatchMode=yes", "StrictHostKeyChecking=yes", "ConnectTimeout=20"):
        assert opt in log
    assert "fleet-probe@fresh.example" in log


def test_live_check_succeeds_when_every_target_is_fine(live_env):
    work, env = live_env
    done = _live(work, env, SECURITY_PROBE_TARGETS="ddev3=fleet-probe@ddev3.example")
    assert done.returncode == 0, done.stderr


def test_live_check_rejects_a_malformed_target(live_env):
    work, env = live_env
    done = _live(work, env, SECURITY_PROBE_TARGETS="just-a-host")
    assert done.returncode == 1 and "bad SECURITY_PROBE_TARGETS entry" in done.stderr
