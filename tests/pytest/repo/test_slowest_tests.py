"""FLE-174: ci/slowest_tests.py reads a PHPUnit JUnit log as well as a Playwright JSON report."""

import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "ci" / "slowest_tests.py"

JUNIT = """<?xml version="1.0"?>
<testsuites><testsuite name="a"><testsuite name="b">
<testcase name="fast" class="App\\FastTest" file="/x/FastTest.php" time="0.010"/>
<testcase name="slow" class="App\\SlowTest" file="/x/SlowTest.php" time="4.500"/>
<testcase name="mid" class="App\\MidTest" file="/x/MidTest.php" time="1.250"/>
</testsuite></testsuite></testsuites>
"""


def run(path, *extra):
    return subprocess.run([sys.executable, str(SCRIPT), str(path), *extra], capture_output=True, text=True, check=True).stdout


def test_junit_lists_slowest_first(tmp_path):
    f = tmp_path / "junit.xml"
    f.write_text(JUNIT)
    lines = run(f, "2").splitlines()
    assert len(lines) == 2
    assert "4.5s" in lines[0] and "slow" in lines[0] and "phpunit" in lines[0]
    assert "mid" in lines[1]


def test_playwright_json_still_works(tmp_path):
    f = tmp_path / "r.json"
    f.write_text('{"suites":[{"file":"a.spec.ts","specs":[{"title":"t","tests":[{"projectName":"desktop","results":[{"duration":2000}]}]}]}]}')
    assert "2.0s" in run(f)
