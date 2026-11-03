"""FLE-120: ci/gates_summary.py (the count parsers and the summary of ci/run-gates.sh) and the
syntax of ci/run-gates.sh itself."""

from __future__ import annotations

import importlib.util
import json
import shutil
import subprocess
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[3]


def _load():
    spec = importlib.util.spec_from_file_location("gates_summary", ROOT / "ci" / "gates_summary.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules["gates_summary"] = module
    spec.loader.exec_module(module)
    return module


gs = _load()


def test_phpunit_ok_line():
    assert gs.parse_phpunit("Time: 00:01\n\nOK (1234 tests, 5678 assertions)\n") == {
        "tests": 1234, "assertions": 5678, "failures": 0, "errors": 0, "skipped": 0,
    }


def test_phpunit_issues_line():
    out = "OK, but there were issues!\nTests: 10, Assertions: 20, Skipped: 2, Warnings: 1.\n"
    counts = gs.parse_phpunit(out)
    assert counts["tests"] == 10 and counts["skipped"] == 2 and counts["failures"] == 0


def test_phpunit_failures_and_several_suites_are_summed():
    out = "\x1b[31mTests: 5, Assertions: 9, Failures: 2, Errors: 1.\x1b[0m\nOK (3 tests, 4 assertions)\n"
    counts = gs.parse_phpunit(out)
    assert counts == {"tests": 8, "assertions": 13, "failures": 2, "errors": 1, "skipped": 0}


def test_phpunit_no_summary():
    assert gs.parse_phpunit("PHP Fatal error: boom") == {}


def test_playwright_summary_block():
    out = "  ok 1 [desktop] a (1s)\n" + "\n" * 10 + "  3 skipped\n  120 passed (2.1m)\n"
    assert gs.parse_playwright(out) == {"passed": 120, "skipped": 3}


def test_playwright_failed_and_flaky():
    out = "  1 failed\n    [desktop] a.spec.ts:3\n  1 flaky\n  2 did not run\n  40 passed (1m)\n"
    assert gs.parse_playwright(out) == {"failed": 1, "flaky": 1, "did_not_run": 2, "passed": 40}


def test_playwright_nothing():
    assert gs.parse_playwright("Error: browser missing") == {}


def test_pytest_summary_line():
    assert gs.parse_pytest("....\n12 passed, 3 skipped in 4.20s\n") == {"passed": 12, "skipped": 3}


def test_pytest_failures_and_errors():
    out = "FAILED x\n1 failed, 2 passed, 1 error, 1 warning in 0.5s\n"
    assert gs.parse_pytest(out) == {"failed": 1, "passed": 2, "errors": 1, "warnings": 1}


def _report(tmp_path: Path, rows: list[str], logs: dict[str, str]) -> Path:
    (tmp_path / "gates.tsv").write_text("\n".join(rows) + "\n")
    for name, text in logs.items():
        (tmp_path / f"{name}.log").write_text(text)
    return tmp_path


def test_build_pass_writes_json_and_text(tmp_path, capsys):
    _report(
        tmp_path,
        ["composer-gates\tpassed\t90\t1\t", "pytest\tpassed\t5\t1\t", "ansible-lint\tfailed\t7\t0\t"],
        {"composer-gates": "OK (10 tests, 20 assertions)\n", "pytest": "4 passed in 1s\n"},
    )
    assert gs.build(tmp_path) == 0
    data = json.loads((tmp_path / "summary.json").read_text())
    assert data["ok"] is True
    assert data["gates"][0]["counts"]["tests"] == 10
    assert data["gates"][1]["counts"] == {"passed": 4}
    assert data["gates"][2]["blocking"] is False
    text = (tmp_path / "summary.txt").read_text()
    assert "RESULT: PASS" in text and "(non-blocking)" in text
    assert "RESULT: PASS" in capsys.readouterr().out


def test_build_blocking_failure_fails(tmp_path):
    _report(tmp_path, ["composer-gates\tfailed\t3\t1\t", "playwright\tskipped\t0\t1\tinstall failed"], {})
    assert gs.build(tmp_path) == 1
    assert "RESULT: FAIL" in (tmp_path / "summary.txt").read_text()


def test_build_without_gates_fails(tmp_path):
    assert gs.build(tmp_path) == 1


def test_cli_counts(tmp_path):
    log = tmp_path / "p.log"
    log.write_text("2 passed in 0.1s\n")
    out = subprocess.run(
        [sys.executable, str(ROOT / "ci" / "gates_summary.py"), "counts", "pytest", str(log)],
        capture_output=True, text=True, check=True,
    ).stdout
    assert json.loads(out) == {"passed": 2}


def test_run_gates_script_syntax_and_usage():
    script = ROOT / "ci" / "run-gates.sh"
    subprocess.run(["bash", "-n", str(script)], check=True)
    helped = subprocess.run(["bash", str(script), "--help"], capture_output=True, text=True)
    assert helped.returncode == 0 and "--report-dir" in helped.stdout
    bad = subprocess.run(["bash", str(script), "--nope"], capture_output=True, text=True)
    assert bad.returncode == 2


@pytest.mark.skipif(shutil.which("shellcheck") is None, reason="shellcheck not installed")
def test_run_gates_script_shellcheck():
    subprocess.run(["shellcheck", "-x", str(ROOT / "ci" / "run-gates.sh")], check=True)
