#!/usr/bin/env python3
"""FLE-172: print the slowest tests of a Playwright JSON report (`--reporter=json`), default the 20 slowest.

  slowest_tests.py REPORT.json [N]

Standard library only. One line per test: seconds, project, file, title.
"""

from __future__ import annotations

import json
import sys


def walk(suite: dict, path: str = ""):
    for spec in suite.get("specs", []):
        for test in spec.get("tests", []):
            secs = sum(r.get("duration", 0) for r in test.get("results", [])) / 1000
            yield secs, test.get("projectName", ""), suite.get("file") or path, spec.get("title", "")
    for child in suite.get("suites", []):
        yield from walk(child, suite.get("file") or path)


def slowest(report: dict, n: int = 20) -> list[tuple[float, str, str, str]]:
    rows = [row for suite in report.get("suites", []) for row in walk(suite)]
    return sorted(rows, key=lambda r: r[0], reverse=True)[:n]


def main(argv: list[str]) -> int:
    if not argv:
        print(__doc__, file=sys.stderr)
        return 2
    with open(argv[0], encoding="utf-8") as fh:
        report = json.load(fh)
    for secs, project, file, title in slowest(report, int(argv[1]) if len(argv) > 1 else 20):
        print(f"{secs:7.1f}s  {project:8}  {file}  {title}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
