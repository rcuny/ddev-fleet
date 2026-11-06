#!/usr/bin/env python3
"""FLE-238: offline systemd exposure scores for the 1.x units, a committed baseline and regression detection.

Subcommands (see docs/README-ci.md):

  offline          score the unit files the installer ships (install/etc/*.service,
                   install/harden/etc/*.service, the Caddy sandbox drop-in) with
                   `systemd-analyze security --offline=yes`, write a report (and
                   print a table).
  compare          compare a report with the committed baseline; print a Markdown
                   report, exit 1 on any regression.
  update-baseline  record a report as the baseline of one environment.
  report           turn the compare JSON into the Markdown page SYSTEMD-SECURITY-REPORT.md.

Report schema:
  {"schema": 1, "host": "offline", "systemd": "257", "generated": ISO-8601,
   "overview": {unit: score}, "units": {unit: {"score": float,
   "directives": {json_field: exposure}}}, "missing": []}

Standard library only. The units are static files (no templating), so there is nothing to render.
"""

from __future__ import annotations

import argparse
import datetime
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_BASELINE = ROOT / "ci" / "systemd-security-baseline.json"
DEFAULT_TOLERANCE = 0.1
SCHEMA = 1
TIMEOUT = 120
ENV = "offline"

# Where the installer's unit files live. Every *.service found here is scored (timers carry no sandbox).
UNIT_DIRS = (ROOT / "install" / "etc", ROOT / "install" / "harden" / "etc")

# The installer ships a sandbox drop-in for the Debian caddy package's unit, not a unit of its own: it is scored
# layered over a stand-in for the vendor unit (/usr/lib/systemd/system/caddy.service).
CADDY_DROPIN = ROOT / "install" / "etc" / "caddy-sandbox.conf"
CADDY_VENDOR_UNIT = """\
[Unit]
Description=Caddy
After=network.target network-online.target
Requires=network.target

[Service]
Type=notify
User=caddy
Group=caddy
ExecStart=/usr/bin/caddy run --environ --config /etc/caddy/Caddyfile
ExecReload=/usr/bin/caddy reload --config /etc/caddy/Caddyfile --force
TimeoutStopSec=5s
LimitNOFILE=1048576
PrivateTmp=true
ProtectSystem=full
AmbientCapabilities=CAP_NET_ADMIN CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
"""

CAT_ORDER = ["REGRESSION", "GONE", "NEW", "IMPROVED", "OK"]
REPORT_NAME = "SYSTEMD-SECURITY-REPORT.md"

_SCORE_RE = re.compile(r"Overall exposure level for \S+: ([0-9.]+)")


class CiError(Exception):
    """A usage or environment problem (exit code 2)."""


# --- systemd-analyze --------------------------------------------------------


def _analyze_bin() -> str:
    return os.environ.get("SYSTEMD_ANALYZE", "systemd-analyze")


def _run(cmd: list[str]) -> subprocess.CompletedProcess:
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=TIMEOUT)
    except FileNotFoundError as exc:
        raise CiError(f"{cmd[0]}: command not found") from exc
    except subprocess.TimeoutExpired as exc:
        raise CiError(f"{' '.join(cmd)}: timed out") from exc


def analyze_unit(unit: str, extra_args: list[str]) -> dict:
    """Score one unit: {"score": float, "directives": {json_field: exposure}}.

    `directives` keeps only the settings that contribute exposure (> 0); a missing directive means 0."""
    base = [_analyze_bin(), "security", "--no-pager", *extra_args]
    text = _run([*base, unit])
    match = _SCORE_RE.search(text.stdout)
    if text.returncode != 0 or not match:
        raise CiError(f"cannot score {unit}: {(text.stderr or text.stdout).strip()[:300]}")
    detail = _run([*base, "--json=short", unit])
    if detail.returncode != 0:
        raise CiError(f"cannot read {unit} details: {detail.stderr.strip()[:300]}")
    try:
        rows = json.loads(detail.stdout)
    except json.JSONDecodeError as exc:
        raise CiError(f"{unit}: systemd-analyze printed invalid JSON") from exc
    directives: dict[str, float] = {}
    for row in rows:
        try:
            exposure = float(row.get("exposure") or 0)
        except (TypeError, ValueError):
            continue
        if exposure > 0:
            field = str(row["json_field"])
            directives[field] = round(directives.get(field, 0.0) + exposure, 2)
    return {"score": float(match.group(1)), "directives": directives}


def systemd_version() -> str:
    """Major systemd version, from `systemd-analyze --version` ("systemd 257 (...)")."""
    try:
        result = _run([_analyze_bin(), "--version"])
    except CiError:
        return "unknown"
    match = re.match(r"systemd (\d+)", (result.stdout.splitlines() or [""])[0])
    return match.group(1) if match else "unknown"


# --- offline: score the shipped units ---------------------------------------


def unit_files(dirs: tuple[Path, ...] = UNIT_DIRS) -> dict[str, Path]:
    """Unit name -> path of every shipped *.service. A name found twice is an error."""
    found: dict[str, Path] = {}
    for directory in dirs:
        for path in sorted(directory.glob("*.service")):
            if path.name in found:
                raise CiError(f"{path.name} ships twice ({found[path.name]} and {path})")
            found[path.name] = path
    return found


def collect_offline(files: dict[str, Path], caddy_dropin: Path | None, root: Path) -> dict:
    """Score the shipped units offline and return a report."""
    units: dict[str, dict] = {}
    for name, path in sorted(files.items()):
        # Copy to a 0644 file in a scratch dir: systemd-analyze warns about group/world-writable unit files.
        # A template unit (fleet-worker@.service) needs an instance name to be loadable.
        target = root / name.replace("@.", "@test_instance.")
        shutil.copyfile(path, target)
        target.chmod(0o644)
        units[name] = analyze_unit(str(target), ["--offline=yes"])
    if caddy_dropin is not None:
        system = root / "caddy-root" / "etc" / "systemd" / "system"
        (system / "caddy.service.d").mkdir(parents=True, exist_ok=True)
        (system / "caddy.service").write_text(CADDY_VENDOR_UNIT, encoding="utf-8")
        shutil.copyfile(caddy_dropin, system / "caddy.service.d" / "50-fleet-sandbox.conf")
        units["caddy.service"] = analyze_unit(
            "caddy.service", ["--offline=yes", f"--root={root / 'caddy-root'}"]
        )
    return {
        "schema": SCHEMA,
        "host": ENV,
        "systemd": systemd_version(),
        "generated": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "overview": {u: d["score"] for u, d in units.items()},
        "units": units,
        "missing": [],
    }


def score_table(report: dict) -> str:
    lines = [f"{'Unit':<36} Score", f"{'-' * 36} -----"]
    for unit, data in sorted(report["units"].items()):
        lines.append(f"{unit:<36} {data['score']:.1f}")
    return "\n".join(lines)


def cmd_offline(args: argparse.Namespace) -> int:
    analyze = _analyze_bin()
    if shutil.which(analyze) is None:
        raise CiError(
            f"{analyze} not found: the offline check needs systemd (Debian: apt-get install "
            "systemd). Run it in the Debian 13 CI image or a container."
        )
    files = unit_files()
    if not files:
        raise CiError("no install/etc/*.service found: run it from a product checkout")
    dropin = CADDY_DROPIN if CADDY_DROPIN.exists() else None
    if args.root:
        Path(args.root).mkdir(parents=True, exist_ok=True)
        report = collect_offline(files, dropin, Path(args.root))
    else:
        with tempfile.TemporaryDirectory(prefix="fleet-systemd-security-") as tmp:
            report = collect_offline(files, dropin, Path(tmp))
    write_json(Path(args.out), report)
    print(f"systemd {report['systemd']}, offline scores (0 = locked down, 10 = no sandbox)")
    print(score_table(report))
    return 0


# --- baseline ---------------------------------------------------------------


def write_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def load_json(path: Path) -> dict:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError:
        raise CiError(f"{path}: file not found") from None
    except json.JSONDecodeError as exc:
        raise CiError(f"{path}: invalid JSON ({exc})") from None


def load_baseline(path: Path) -> dict:
    if not path.exists():
        return {"tolerance": DEFAULT_TOLERANCE, "environments": {}}
    data = load_json(path)
    data.setdefault("tolerance", DEFAULT_TOLERANCE)
    data.setdefault("environments", {})
    return data


def scores_of(report: dict) -> dict[str, float]:
    """Every score in a report: the overview, with each unit's own score on top."""
    scores = {u: float(s) for u, s in report.get("overview", {}).items()}
    scores.update({u: float(d["score"]) for u, d in report.get("units", {}).items()})
    return scores


def cmd_update_baseline(args: argparse.Namespace) -> int:
    path = Path(args.baseline)
    baseline = load_baseline(path)
    current = load_json(Path(args.current))
    baseline["environments"][args.env] = {
        "systemd": current.get("systemd", "unknown"),
        "recorded": datetime.date.today().isoformat(),
        "overview": dict(sorted((current.get("overview") or {}).items())),
        "units": current.get("units", {}),
    }
    write_json(path, baseline)
    print(f"recorded baseline for {args.env!r} in {path} ({len(scores_of(current))} units)")
    return 0


# --- compare ----------------------------------------------------------------


def directive_changes(base: dict[str, float], cur: dict[str, float]) -> list[dict]:
    """Directives whose exposure changed (a missing directive counts as 0), worst first."""
    changes = []
    for field in sorted(set(base) | set(cur)):
        before, after = float(base.get(field, 0)), float(cur.get(field, 0))
        if abs(after - before) > 1e-9:
            changes.append(
                {"directive": field, "baseline": before, "current": after, "delta": after - before}
            )
    return sorted(changes, key=lambda c: (-c["delta"], c["directive"]))


def compare_reports(env: dict | None, current: dict, tolerance: float) -> list[dict]:
    """One row per unit: unit, baseline, current, delta, status (+ directive changes)."""
    cur_scores = scores_of(current)
    base_scores = scores_of(env) if env else {}
    rows = []
    for unit in sorted(set(cur_scores) | set(base_scores)):
        base, cur = base_scores.get(unit), cur_scores.get(unit)
        row: dict = {
            "unit": unit,
            "baseline": base,
            "current": cur,
            "delta": None,
            "directives": [],
        }
        if base is None:
            row["status"] = "NEW"
        elif cur is None:
            row["status"] = "GONE"
        else:
            delta = round(cur - base, 2)
            row["delta"] = delta
            if delta > tolerance + 1e-9:
                row["status"] = "REGRESSION"
                base_dir = (env or {}).get("units", {}).get(unit, {}).get("directives")
                cur_dir = current.get("units", {}).get(unit, {}).get("directives")
                if base_dir is not None and cur_dir is not None:
                    row["directives"] = directive_changes(base_dir, cur_dir)
            elif delta < -tolerance - 1e-9:
                row["status"] = "IMPROVED"
            else:
                row["status"] = "OK"
        rows.append(row)
    return sorted(rows, key=lambda r: (CAT_ORDER.index(r["status"]), r["unit"]))


def _num(value: float | None) -> str:
    return "-" if value is None else f"{value:.1f}"


def _delta(value: float | None) -> str:
    return "-" if value is None else f"{value:+.1f}" if abs(value) > 1e-9 else "0.0"


def _regression_details(rows: list[dict]) -> list[str]:
    """One heading plus the per-directive table for every regressed unit."""
    out: list[str] = []
    for r in (r for r in rows if r["status"] == "REGRESSION"):
        out.append(
            f"### Regression: `{r['unit']}` {_num(r['baseline'])} -> {_num(r['current'])} "
            f"({_delta(r['delta'])})"
        )
        out.append("")
        if r["directives"]:
            out += ["| Directive | Baseline | Current | Delta |", "|---|---|---|---|"]
            for c in r["directives"]:
                out.append(
                    f"| `{c['directive']}` | {c['baseline']:.1f} | {c['current']:.1f} | "
                    f"{_delta(c['delta'])} |"
                )
        else:
            out.append("No per-directive detail in the baseline for this unit.")
        out.append("")
    return out


def _unit_table(rows: list[dict]) -> list[str]:
    out = ["| Unit | Score | Baseline | Delta | Status |", "|---|---|---|---|---|"]
    for r in rows:
        status = f"**{r['status']}**" if r["status"] == "REGRESSION" else r["status"]
        out.append(
            f"| `{r['unit']}` | {_num(r['current'])} | {_num(r['baseline'])} | "
            f"{_delta(r['delta'])} | {status} |"
        )
    return out


def render_markdown(
    env_name: str, rows: list[dict], current: dict, env: dict | None, tolerance: float
) -> str:
    out = [f"## systemd security: {env_name}", ""]
    out.append(
        f"systemd {current.get('systemd', '?')}, generated {current.get('generated', '?')}. "
        f"Tolerance +{tolerance:g} (exposure 0 = locked down, 10 = no sandbox)."
    )
    out.append("")
    if env is None:
        out += [
            f"**No baseline recorded for `{env_name}` yet**: every unit is reported as NEW and "
            "nothing can regress. Record this run with:",
            "",
            f"    python ci/systemd_security.py update-baseline --env {env_name} "
            "--current <this report's JSON>",
            "",
        ]
    elif env.get("systemd") and env["systemd"] != current.get("systemd"):
        out += [
            f"**Warning:** systemd {current.get('systemd')} here, baseline recorded with "
            f"systemd {env['systemd']}: scores can move without any code change.",
            "",
        ]
    out += ["| Unit | Baseline | Current | Delta | Status |", "|---|---|---|---|---|"]
    for r in rows:
        status = f"**{r['status']}**" if r["status"] == "REGRESSION" else r["status"]
        out.append(
            f"| `{r['unit']}` | {_num(r['baseline'])} | {_num(r['current'])} | "
            f"{_delta(r['delta'])} | {status} |"
        )
    out.append("")
    out += _regression_details(rows)
    improved = sum(1 for r in rows if r["status"] == "IMPROVED")
    if improved:
        out += [
            f"{improved} unit(s) improved: consider `update-baseline --env {env_name}` "
            "so the new, lower scores become the reference (see docs/README-ci.md).",
            "",
        ]
    counts = {s: sum(1 for r in rows if r["status"] == s) for s in CAT_ORDER}
    out.append("Summary: " + ", ".join(f"{n} {s}" for s, n in counts.items() if n) + ".")
    return "\n".join(out) + "\n"


def cmd_compare(args: argparse.Namespace) -> int:
    baseline = load_baseline(Path(args.baseline))
    tolerance = float(args.tolerance if args.tolerance is not None else baseline["tolerance"])
    current = load_json(Path(args.current))
    env = baseline["environments"].get(args.env)
    rows = compare_reports(env, current, tolerance)
    markdown = render_markdown(args.env, rows, current, env, tolerance)
    regressions = [r for r in rows if r["status"] == "REGRESSION"]
    if args.report_md:
        path = Path(args.report_md)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(markdown, encoding="utf-8")
    if args.report_json:
        write_json(
            Path(args.report_json),
            {
                "env": args.env,
                "ok": not regressions,
                "tolerance": tolerance,
                "baseline_systemd": (env or {}).get("systemd"),
                "current_systemd": current.get("systemd"),
                "generated": current.get("generated"),
                "rows": rows,
            },
        )
    print(markdown, end="")
    if env is None:
        print(
            f"NOTICE no baseline for environment {args.env!r} in {args.baseline}: nothing was "
            f"compared. Record it: python ci/systemd_security.py update-baseline "
            f"--env {args.env} --current {args.current}",
            file=sys.stderr,
        )
    for r in regressions:
        print(
            f"REGRESSION {r['unit']} ({args.env}): {_num(r['baseline'])} -> "
            f"{_num(r['current'])} ({_delta(r['delta'])})",
            file=sys.stderr,
        )
    return 1 if regressions else 0


# --- report -----------------------------------------------------------------


def render_report(data: dict, *, when: str) -> str:
    """The published page, from the compare JSON of the offline run."""
    regressions = [r for r in data["rows"] if r["status"] == "REGRESSION"]
    status = f"**{len(regressions)} regression(s)**" if regressions else "OK"
    out = [
        "# systemd security report",
        "",
        "`systemd-analyze security` scores how much of the system a service's sandbox leaves "
        "exposed to it, on a scale from 0 (best: locked down) to 10 (worst: no sandbox at all). "
        "The offline check scores the unit files the installer ships (`install/etc`, "
        "`install/harden/etc`, and the Caddy sandbox drop-in); no host is involved.",
        "",
        "Every score is compared with the committed baseline "
        "`ci/systemd-security-baseline.json`. A unit counts as a regression only when its score "
        f"rises by more than the tolerance (+{data['tolerance']:g}) over that baseline. "
        "Regenerate this page with `python ci/systemd_security.py report` (see "
        "[docs/README-ci.md](docs/README-ci.md)).",
        "",
        f"**Generated:** {when}, systemd {data.get('current_systemd') or '?'}. Status: {status}.",
        "",
        *_unit_table(sorted(data["rows"], key=lambda r: r["unit"])),
        "",
        *_regression_details(data["rows"]),
    ]
    return "\n".join(out).rstrip("\n") + "\n"


def cmd_report(args: argparse.Namespace) -> int:
    data = load_json(Path(args.compare))
    when = args.date or datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d")
    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(render_report(data, when=when), encoding="utf-8")
    print(out)
    return 0


# --- CLI --------------------------------------------------------------------


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    sub = parser.add_subparsers(dest="command", required=True)

    p = sub.add_parser("offline", help="score the shipped 1.x units offline")
    p.add_argument("--out", default="reports/offline.json")
    p.add_argument("--root", help="keep the scratch copies of the units here (default: a temp dir)")
    p.set_defaults(func=cmd_offline)

    p = sub.add_parser("compare", help="compare a report with the baseline (exit 1 on regression)")
    p.add_argument("--baseline", default=str(DEFAULT_BASELINE))
    p.add_argument("--env", default=ENV, help=f"baseline environment (default {ENV})")
    p.add_argument("--current", required=True, help="report JSON to check")
    p.add_argument("--tolerance", type=float, help="override the baseline file's tolerance")
    p.add_argument("--report-md")
    p.add_argument("--report-json")
    p.set_defaults(func=cmd_compare)

    p = sub.add_parser("update-baseline", help="record a report as an environment's baseline")
    p.add_argument("--baseline", default=str(DEFAULT_BASELINE))
    p.add_argument("--env", default=ENV)
    p.add_argument("--current", required=True)
    p.set_defaults(func=cmd_update_baseline)

    p = sub.add_parser("report", help=f"write the {REPORT_NAME} page from a compare JSON")
    p.add_argument("--compare", default="reports/systemd-security-offline.json")
    p.add_argument("--out", default=REPORT_NAME)
    p.add_argument("--date", help="date to print (default: today, UTC)")
    p.set_defaults(func=cmd_report)
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        return args.func(args)
    except CiError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
