#!/usr/bin/env python3
"""FLE-120: build the summary of a ci/run-gates.sh run.

ci/run-gates.sh runs every gate with its output in <report-dir>/<gate>.log and records one line per gate in
<report-dir>/gates.tsv (name, status, duration_s, blocking, note). This script reads those files, parses the
test counts out of the PHPUnit, Playwright and pytest logs, and writes summary.json and summary.txt.

  gates_summary.py build REPORT_DIR      write summary.json + summary.txt, print summary.txt
  gates_summary.py counts KIND FILE      print the counts parsed from FILE as JSON (KIND: phpunit|playwright|pytest)

Standard library only. Exit status of `build` is 0 only if every blocking gate passed or was skipped on purpose.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ANSI = re.compile(r"\x1b\[[0-9;?]*[A-Za-z]")

PHPUNIT_OK = re.compile(r"^OK \((\d+) tests?, (\d+) assertions?\)", re.M)
PHPUNIT_ISSUES = re.compile(r"^Tests: (\d+), Assertions: (\d+)(.*?)\.?$", re.M)
PHPUNIT_FIELD = re.compile(r"(\w+): (\d+)")
PLAYWRIGHT_LINE = re.compile(r"^\s+(\d+) (passed|failed|flaky|skipped|did not run|interrupted)\b", re.M)
PYTEST_LINE = re.compile(r"(\d+) (passed|failed|skipped|errors?|xfailed|xpassed|deselected|warnings?)")


def _clean(text: str) -> str:
    return ANSI.sub("", text)


def parse_phpunit(text: str) -> dict[str, int]:
    """Sum every PHPUnit summary in the log (composer run test may run several suites)."""
    text = _clean(text)
    counts = {"tests": 0, "assertions": 0, "failures": 0, "errors": 0, "skipped": 0}
    found = False
    for m in PHPUNIT_OK.finditer(text):
        found = True
        counts["tests"] += int(m.group(1))
        counts["assertions"] += int(m.group(2))
    for m in PHPUNIT_ISSUES.finditer(text):
        found = True
        counts["tests"] += int(m.group(1))
        counts["assertions"] += int(m.group(2))
        for key, value in PHPUNIT_FIELD.findall(m.group(3)):
            key = key.lower()
            if key in counts:
                counts[key] += int(value)
    return counts if found else {}


def parse_playwright(text: str) -> dict[str, int]:
    """Counts of the final summary block (the last run of count lines), e.g. '  120 passed (2.1m)'."""
    text = _clean(text)
    matches = list(PLAYWRIGHT_LINE.finditer(text))
    if not matches:
        return {}
    # The summary block is the last group of adjacent count lines; the list reporter prints none before it.
    block: list[re.Match[str]] = []
    prev_end = None
    for m in reversed(matches):
        if prev_end is not None and text[m.end():prev_end].count("\n") > 3:
            break
        block.append(m)
        prev_end = m.start()
    counts: dict[str, int] = {}
    for m in block:
        key = m.group(2).replace(" ", "_")
        counts[key] = counts.get(key, 0) + int(m.group(1))
    return counts


def parse_pytest(text: str) -> dict[str, int]:
    """Counts from pytest's last summary line: '5 passed, 2 skipped in 3.2s' (also with -q)."""
    text = _clean(text)
    for line in reversed(text.splitlines()):
        pairs = PYTEST_LINE.findall(line)
        if pairs and re.search(r"\bin \d", line):
            counts: dict[str, int] = {}
            for value, key in pairs:
                key = {"error": "errors", "warning": "warnings"}.get(key, key)
                counts[key] = counts.get(key, 0) + int(value)
            return counts
    return {}


PARSERS = {"phpunit": parse_phpunit, "playwright": parse_playwright, "pytest": parse_pytest}

# gate name -> parser of its log
GATE_PARSERS = {"composer-gates": "phpunit", "playwright": "playwright", "pytest": "pytest"}


def read_gates(report_dir: Path) -> list[dict]:
    gates = []
    tsv = report_dir / "gates.tsv"
    if not tsv.is_file():
        return gates
    for raw in tsv.read_text(encoding="utf-8").splitlines():
        if not raw.strip():
            continue
        name, status, duration, blocking, *note = raw.split("\t")
        gate = {
            "gate": name,
            "status": status,
            "duration_s": int(duration or 0),
            "blocking": blocking == "1",
            "log": f"{name}.log",
        }
        if note and note[0]:
            gate["note"] = note[0]
        log = report_dir / f"{name}.log"
        parser = GATE_PARSERS.get(name)
        if parser and log.is_file():
            counts = PARSERS[parser](log.read_text(encoding="utf-8", errors="replace"))
            if counts:
                gate["counts"] = counts
        gates.append(gate)
    return gates


def is_ok(gates: list[dict]) -> bool:
    return all(g["status"] in ("passed", "skipped") for g in gates if g["blocking"])


def render_text(gates: list[dict]) -> str:
    lines = []
    for g in gates:
        counts = ", ".join(f"{v} {k}" for k, v in g.get("counts", {}).items())
        extra = " | ".join(x for x in (counts, g.get("note", "")) if x)
        flag = "" if g["blocking"] else " (non-blocking)"
        lines.append(f"{g['status'].upper():8} {g['gate']}{flag}  {g['duration_s']}s" + (f"  {extra}" if extra else ""))
    lines.append("RESULT: " + ("PASS" if is_ok(gates) else "FAIL"))
    return "\n".join(lines) + "\n"


def build(report_dir: Path) -> int:
    gates = read_gates(report_dir)
    ok = is_ok(gates)
    (report_dir / "summary.json").write_text(
        json.dumps({"ok": ok, "gates": gates}, indent=2) + "\n", encoding="utf-8"
    )
    text = render_text(gates)
    (report_dir / "summary.txt").write_text(text, encoding="utf-8")
    sys.stdout.write(text)
    return 0 if ok and gates else 1


def main(argv: list[str]) -> int:
    if len(argv) == 3 and argv[1] == "build":
        return build(Path(argv[2]))
    if len(argv) == 4 and argv[1] == "counts" and argv[2] in PARSERS:
        print(json.dumps(PARSERS[argv[2]](Path(argv[3]).read_text(encoding="utf-8", errors="replace"))))
        return 0
    sys.stderr.write(__doc__ or "")
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))
