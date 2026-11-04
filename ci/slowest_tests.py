#!/usr/bin/env python3
"""FLE-172/174: print the slowest tests of a Playwright JSON report (`--reporter=json`) or of a PHPUnit JUnit XML
log (`--log-junit`), default the 20 slowest. The format is told from the first character of the file.

  slowest_tests.py REPORT.json|REPORT.xml|DIR_OF_XML [N]

Standard library only. One line per test: seconds, project (`phpunit` for JUnit), file, title.
"""

from __future__ import annotations

import glob
import json
import os
import sys
import xml.etree.ElementTree as ET


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


def junit_rows(path: str):
    """One row per <testcase> of a PHPUnit JUnit log; a data-set case keeps its own name."""
    for case in ET.parse(path).getroot().iter("testcase"):
        cls = case.get("class") or case.get("classname") or ""
        yield float(case.get("time") or 0), "phpunit", case.get("file") or cls, case.get("name", "")


def main(argv: list[str]) -> int:
    if not argv:
        print(__doc__, file=sys.stderr)
        return 2
    n = int(argv[1]) if len(argv) > 1 else 20
    if os.path.isdir(argv[0]):
        files = sorted(glob.glob(os.path.join(argv[0], "*.xml")))
        rows = sorted((r for f in files for r in junit_rows(f)), key=lambda r: r[0], reverse=True)[:n]
        for secs, project, file, title in rows:
            print(f"{secs:7.1f}s  {project:8}  {file}  {title}")
        return 0
    with open(argv[0], encoding="utf-8") as fh:
        is_xml = fh.read(1).lstrip() == "<"
    if is_xml:
        rows = sorted(junit_rows(argv[0]), key=lambda r: r[0], reverse=True)[:n]
    else:
        with open(argv[0], encoding="utf-8") as fh:
            rows = slowest(json.load(fh), n)
    for secs, project, file, title in rows:
        print(f"{secs:7.1f}s  {project:8}  {file}  {title}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
