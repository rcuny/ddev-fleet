#!/usr/bin/env python3
"""FLE-8: systemd exposure scores, a committed baseline and regression detection.

Subcommands (see docs/README-ci.md):

  offline          render the sandboxed units from the Ansible templates, score
                   them with `systemd-analyze security --offline=yes`, write a
                   report (and print a table).
  compare          compare a report (offline run, or the JSON the live host's
                   forced command printed) with the committed baseline; print a
                   Markdown report, exit 1 on any regression.
  update-baseline  record a report as one environment's baseline.

Report schema (also what ansible/roles/security_probe/files/fleet-security-report
prints):
  {"schema": 1, "host": str, "systemd": "257", "generated": ISO-8601,
   "overview": {unit: score}, "units": {unit: {"score": float,
   "directives": {json_field: exposure}}}, "missing": [unit]}

Standard library only, except that the offline path imports jinja2 lazily to
render the templates the way Ansible's template module does.
"""

from __future__ import annotations

import argparse
import datetime
import importlib.machinery
import importlib.util
import json
import os
import shutil
import sys
import tempfile
from pathlib import Path
from types import ModuleType

ROOT = Path(__file__).resolve().parents[1]
ROLES = ROOT / "ansible" / "roles"
REPORT_SCRIPT = ROLES / "security_probe" / "files" / "fleet-security-report"
DEFAULT_BASELINE = ROOT / "ci" / "systemd-security-baseline.json"
DEFAULT_TOLERANCE = 0.1

# Keep in step with tests/test_systemd_sandbox.py (tests/test_systemd_security_ci.py
# fails when the two drift apart).
TEMPLATE_VARS = dict(
    fleet_user="fleet",
    fleet_opt_dir="/opt/ddev-fleet",
    fleet_srv_dir="/srv/fleet",
    fleet_home_dir="/home/fleet",
    fleet_caddy_snippet_dir="/etc/caddy/fleet",
    fleet_daemon_port=8765,
    fleet_reboot_notify_interval_hours=24,
)

# Stand-in for the Debian caddy package's vendor unit (/usr/lib/systemd/system/caddy.service).
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

# (unit file, role, template). authelia.service ships with the Debian package and
# the repo has no unit or drop-in template for it, so it has nothing to render
# offline: the live check covers it.
STANDALONE_UNITS = (
    ("fleet.service", "fleet_service", "fleet.service.j2"),
    ("fleet-boot.service", "fleet_service", "fleet-boot.service.j2"),
    ("fleet-reboot-notify.service", "security_hardening", "fleet-reboot-notify.service.j2"),
)
CADDY_DROPIN = ("caddy", "caddy-sandbox.conf.j2")
CADDY_DROPIN_PATH = Path("caddy.service.d") / "50-fleet-sandbox.conf"

CAT_ORDER = ["REGRESSION", "GONE", "NEW", "IMPROVED", "OK"]


class CiError(Exception):
    """A usage or environment problem (exit code 2)."""


# --- the report script (single source of the collection logic) --------------


def load_report_module() -> ModuleType:
    loader = importlib.machinery.SourceFileLoader("fleet_security_report", str(REPORT_SCRIPT))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    assert spec is not None
    module = importlib.util.module_from_spec(spec)
    previous, sys.dont_write_bytecode = sys.dont_write_bytecode, True  # no __pycache__ in the role
    try:
        loader.exec_module(module)
    finally:
        sys.dont_write_bytecode = previous
    return module


# --- offline: render and score ----------------------------------------------


def _render(role: str, name: str) -> str:
    from jinja2 import Environment, FileSystemLoader  # lazy: only the offline path needs it

    # trim_blocks=True: what Ansible's template module uses.
    env = Environment(
        loader=FileSystemLoader(str(ROLES / role / "templates")),
        trim_blocks=True,
        keep_trailing_newline=True,
    )
    return env.get_template(name).render(**TEMPLATE_VARS)


def render_all() -> dict[str, str]:
    """Rendered text of every sandboxed unit: unit file name -> content.

    caddy.service maps to the rendered *drop-in*; collect_offline layers it over
    CADDY_VENDOR_UNIT."""
    rendered = {unit: _render(role, tpl) for unit, role, tpl in STANDALONE_UNITS}
    rendered["caddy.service"] = _render(*CADDY_DROPIN)
    return rendered


def collect_offline(rendered: dict[str, str], root: Path) -> dict:
    """Score the rendered units offline and return a report."""
    probe = load_report_module()
    units: dict[str, dict] = {}
    for unit, content in sorted(rendered.items()):
        if unit == "caddy.service":
            system = root / "caddy-root" / "etc" / "systemd" / "system"
            (system / "caddy.service.d").mkdir(parents=True, exist_ok=True)
            (system / "caddy.service").write_text(CADDY_VENDOR_UNIT, encoding="utf-8")
            (system / CADDY_DROPIN_PATH).write_text(content, encoding="utf-8")
            args = ["--offline=yes", f"--root={root / 'caddy-root'}"]
            target = unit
        else:
            path = root / unit
            path.write_text(content, encoding="utf-8")
            args = ["--offline=yes"]
            target = str(path)
        units[unit] = probe.analyze_unit(target, args)
    return {
        "schema": probe.SCHEMA,
        "host": "offline",
        "systemd": probe.systemd_version(),
        "generated": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "overview": {u: d["score"] for u, d in units.items()},
        "units": units,
        "missing": [],
    }


def score_table(report: dict) -> str:
    lines = [f"{'Unit':<32} Score", f"{'-' * 32} -----"]
    for unit, data in sorted(report["units"].items()):
        lines.append(f"{unit:<32} {data['score']:.1f}")
    return "\n".join(lines)


def cmd_offline(args: argparse.Namespace) -> int:
    analyze = os.environ.get("SYSTEMD_ANALYZE", "systemd-analyze")
    if shutil.which(analyze) is None:
        raise CiError(
            f"{analyze} not found: the offline check needs systemd (Debian: apt-get install "
            "systemd). Run it in the Debian 13 pipeline image or a container."
        )
    rendered = render_all()
    if args.root:
        Path(args.root).mkdir(parents=True, exist_ok=True)
        report = collect_offline(rendered, Path(args.root))
    else:
        with tempfile.TemporaryDirectory(prefix="fleet-systemd-security-") as tmp:
            report = collect_offline(rendered, Path(tmp))
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
    """Every score in a report: the overview, with watched units' own score on top."""
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


def render_markdown(
    env_name: str, rows: list[dict], current: dict, env: dict | None, tolerance: float
) -> str:
    out = [f"## systemd security: {env_name}", ""]
    out.append(
        f"Host `{current.get('host', '?')}`, systemd {current.get('systemd', '?')}, "
        f"generated {current.get('generated', '?')}. Tolerance +{tolerance:g} "
        "(exposure 0 = locked down, 10 = no sandbox)."
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
    missing = current.get("missing") or []
    if missing:
        out += [f"Not loaded on this host: {', '.join(f'`{u}`' for u in missing)}.", ""]
    out += ["| Unit | Baseline | Current | Delta | Status |", "|---|---|---|---|---|"]
    for r in rows:
        status = f"**{r['status']}**" if r["status"] == "REGRESSION" else r["status"]
        out.append(
            f"| `{r['unit']}` | {_num(r['baseline'])} | {_num(r['current'])} | "
            f"{_delta(r['delta'])} | {status} |"
        )
    out.append("")
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
                "host": current.get("host"),
                "generated": current.get("generated"),
                "missing": current.get("missing", []),
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


# --- CLI --------------------------------------------------------------------


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    sub = parser.add_subparsers(dest="command", required=True)

    p = sub.add_parser("offline", help="render and score the sandboxed units offline")
    p.add_argument("--out", default="reports/offline.json")
    p.add_argument("--root", help="keep the rendered units here (default: a temp dir)")
    p.set_defaults(func=cmd_offline)

    p = sub.add_parser("compare", help="compare a report with the baseline (exit 1 on regression)")
    p.add_argument("--baseline", default=str(DEFAULT_BASELINE))
    p.add_argument("--env", required=True, help="baseline environment: offline, ddev3, ...")
    p.add_argument("--current", required=True, help="report JSON to check")
    p.add_argument("--tolerance", type=float, help="override the baseline file's tolerance")
    p.add_argument("--report-md")
    p.add_argument("--report-json")
    p.set_defaults(func=cmd_compare)

    p = sub.add_parser("update-baseline", help="record a report as an environment's baseline")
    p.add_argument("--baseline", default=str(DEFAULT_BASELINE))
    p.add_argument("--env", required=True)
    p.add_argument("--current", required=True)
    p.set_defaults(func=cmd_update_baseline)
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        return args.func(args)
    except CiError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2
    except Exception as exc:  # the report script's AnalyzeError, jinja2 import errors
        print(f"ERROR: {type(exc).__name__}: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
