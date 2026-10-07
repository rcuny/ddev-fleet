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
  publish-report   (FLE-16) turn the compare JSONs of the live hosts (and the offline
                   one) into the Markdown page SYSTEMD-SECURITY-REPORT.md.
  should-publish   exit 0 when this run should commit that page (develop, or
                   PUBLISH_REPORT=1), 1 when not.
  jira-alert       post one Jira comment (with a real @mention) when a host regressed
                   or could not be fetched; configured by JIRA_ALERT_* variables.

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
import base64
import datetime
import importlib.machinery
import importlib.util
import json
import os
import shutil
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
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


def _unit_table(rows: list[dict]) -> list[str]:
    """Unit | Score | Baseline | Delta | Status, one line per row as given."""
    out = ["| Unit | Score | Baseline | Delta | Status |", "|---|---|---|---|---|"]
    for r in rows:
        status = f"**{r['status']}**" if r["status"] == "REGRESSION" else r["status"]
        out.append(
            f"| `{r['unit']}` | {_num(r['current'])} | {_num(r['baseline'])} | "
            f"{_delta(r['delta'])} | {status} |"
        )
    return out


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


# --- publish-report, should-publish, jira-alert (FLE-16) ----------------------


REPORT_NAME = "SYSTEMD-SECURITY-REPORT.md"
# The units the product owns, in the order the report lists them.
PRODUCT_UNITS = (
    "fleet.service",
    "fleet-boot.service",
    "fleet-reboot-notify.service",
    "caddy.service",
    "authelia.service",
)
ALERT_VARS = (
    "JIRA_ALERT_SITE",
    "JIRA_ALERT_EMAIL",
    "JIRA_ALERT_TOKEN",
    "JIRA_ALERT_ISSUE",
    "JIRA_ALERT_MENTION",
)
ALERT_TIMEOUT = 30
ALERT_MAX_DIRECTIVES = 8


def parse_hosts(value: str) -> list[str]:
    hosts = sorted(set(value.split()))
    if not hosts:
        raise CiError("--hosts is empty (expected space-separated host names)")
    return hosts


def pipeline_url(environ: dict[str, str] | os._Environ = os.environ) -> str | None:
    """Link to this Bitbucket pipeline run, or None outside Bitbucket."""
    origin = environ.get("BITBUCKET_GIT_HTTP_ORIGIN")
    build = environ.get("BITBUCKET_BUILD_NUMBER")
    if not origin or not build:
        return None
    if origin.startswith("http://"):
        origin = "https://" + origin[len("http://") :]
    return f"{origin.rstrip('/')}/pipelines/results/{build}"


def load_compare(reports_dir: Path, name: str) -> dict | None:
    """The compare JSON of one environment, or None when the run did not produce one."""
    path = reports_dir / f"systemd-security-{name}.json"
    return load_json(path) if path.exists() else None


def regressions_of(data: dict) -> list[dict]:
    return [r for r in data["rows"] if r["status"] == "REGRESSION"]


def has_no_baseline(data: dict) -> bool:
    rows = data["rows"]
    return data.get("baseline_systemd") is None or (
        bool(rows) and all(r["status"] == "NEW" for r in rows)
    )


def status_label(data: dict | None) -> str:
    if data is None:
        return "**could not be fetched**"
    count = len(regressions_of(data))
    if count:
        return f"**{count} regression(s)**"
    return "OK (no baseline yet)" if has_no_baseline(data) else "OK"


def _product_rows(rows: list[dict]) -> list[dict]:
    by_unit = {r["unit"]: r for r in rows}
    absent = {"baseline": None, "current": None, "delta": None, "status": "not loaded"}
    return [by_unit.get(u) or {"unit": u, **absent} for u in PRODUCT_UNITS]


def _host_section(name: str, data: dict | None) -> list[str]:
    out = [f"## {name}", ""]
    if data is None:
        return out + [
            f"Status: {status_label(data)}. No comparison was produced for this host in this "
            "run: the SSH fetch failed (see the pipeline log).",
            "",
        ]
    out.append(
        f"Status: {status_label(data)}. systemd {data.get('current_systemd') or '?'}, "
        f"report generated {data.get('generated') or '?'}."
    )
    out.append("")
    baseline_systemd = data.get("baseline_systemd")
    if baseline_systemd and baseline_systemd != data.get("current_systemd"):
        out += [
            f"**Warning:** systemd {data.get('current_systemd')} here, baseline recorded with "
            f"systemd {baseline_systemd}: scores can move without any code change.",
            "",
        ]
    if has_no_baseline(data):
        out += [
            f"No baseline is recorded for `{name}` yet, so nothing can regress "
            "(see docs/README-ci.md to record one).",
            "",
        ]
    missing = data.get("missing") or []
    if missing:
        out += [f"Not loaded on this host: {', '.join(f'`{u}`' for u in missing)}.", ""]
    out += ["The units the product owns:", ""]
    out += _unit_table(_product_rows(data["rows"]))
    out.append("")
    out += _regression_details(data["rows"])
    rows = sorted(data["rows"], key=lambda r: r["unit"])
    out += [
        f"<details><summary>All {len(rows)} units on {name}</summary>",
        "",
        *_unit_table(rows),
        "",
        "</details>",
        "",
    ]
    return out


def _offline_section(data: dict | None) -> list[str]:
    out = ["## Offline: the unit files the product ships", ""]
    if data is None:
        return out + [
            "Offline scores were not produced in this run (`systemd-analyze` is missing in the "
            "pipeline image, or the offline run failed).",
            "",
        ]
    out += [
        "The units rendered from the Ansible templates and scored with "
        f"`systemd-analyze security --offline=yes`, systemd {data.get('current_systemd') or '?'}. "
        f"Status: {status_label(data)}.",
        "",
        *_unit_table(sorted(data["rows"], key=lambda r: r["unit"])),
        "",
        *_regression_details(data["rows"]),
    ]
    return out


def render_report(
    hosts: dict[str, dict | None],
    offline: dict | None,
    *,
    when: str,
    branch: str | None,
    run_url: str | None,
) -> str:
    """The published page. `hosts` maps host name -> compare JSON (None = not fetched)."""
    names = sorted(hosts)
    tolerance = next((d["tolerance"] for d in (*hosts.values(), offline) if d), DEFAULT_TOLERANCE)
    out = [
        "# systemd security report",
        "",
        "`systemd-analyze security` scores how much of the system a service's sandbox leaves "
        "exposed to it, on a scale from 0 (best: locked down) to 10 (worst: no sandbox at all). "
        "The weekly live check reads these scores from each server and the offline check scores "
        "the unit files this repository ships.",
        "",
        "Every score is compared with the committed baseline "
        "`ci/systemd-security-baseline.json`. A unit counts as a regression only when its score "
        f"rises by more than the tolerance (+{tolerance:g}) over that baseline.",
        "",
    ]
    run = [f"**Run:** {when}"]
    if branch:
        run.append(f"branch `{branch}`")
    if run_url:
        run.append(f"[pipeline run]({run_url})")
    out += [", ".join(run), ""]
    out += ["| Host | systemd | Status |", "|---|---|---|"]
    for name in names:
        data = hosts[name]
        version = (data or {}).get("current_systemd") or "-"
        out.append(f"| [{name}](#{name.lower()}) | {version} | {status_label(data)} |")
    out.append("")
    for name in names:
        out += _host_section(name, hosts[name])
    out += _offline_section(offline)
    out += [
        "---",
        "",
        "How the check works, the baseline and how to update it: "
        "[docs/README-ci.md](docs/README-ci.md).",
    ]
    return "\n".join(out) + "\n"


def cmd_publish_report(args: argparse.Namespace) -> int:
    reports = Path(args.reports_dir)
    hosts = {name: load_compare(reports, name) for name in parse_hosts(args.hosts)}
    when = args.date or datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    text = render_report(
        hosts,
        load_compare(reports, "offline"),
        when=when,
        branch=os.environ.get("BITBUCKET_BRANCH") or None,
        run_url=pipeline_url(),
    )
    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(text, encoding="utf-8")
    print(out)
    return 0


def publish_wanted(branch: str | None, flag: str | None) -> bool:
    """Commit the report on develop, or on any branch run with PUBLISH_REPORT=1 (testing)."""
    return branch == "develop" or flag == "1"


def cmd_should_publish(args: argparse.Namespace) -> int:
    branch = os.environ.get("BITBUCKET_BRANCH") or None
    flag = os.environ.get("PUBLISH_REPORT")
    if not publish_wanted(branch, flag):
        print(
            f"not publishing: branch {branch or '(none)'} is not develop "
            "and PUBLISH_REPORT=1 is not set"
        )
        return 1
    why = "branch develop" if branch == "develop" else "PUBLISH_REPORT=1"
    print(f"publishing the report ({why})")
    return 0


# Jira alert: a comment with a real mention node (plain "@email" text notifies nobody).


def _adf_text(text: str, *marks: dict) -> dict:
    node: dict = {"type": "text", "text": text}
    if marks:
        node["marks"] = list(marks)
    return node


def _adf_link(label: str, href: str) -> dict:
    return _adf_text(label, {"type": "link", "attrs": {"href": href}})


def _adf_paragraph(*content: dict) -> dict:
    return {"type": "paragraph", "content": list(content)}


def alert_findings(reports: Path, hosts: list[str]) -> list[tuple[str, list[dict] | None]]:
    """(host, regressed rows) for each host that needs an alert; None = report not fetched."""
    findings: list[tuple[str, list[dict] | None]] = []
    for name in hosts:
        data = load_compare(reports, name)
        if data is None:
            findings.append((name, None))
        elif regressions_of(data):
            findings.append((name, regressions_of(data)))
    return findings


def _regression_item(row: dict) -> dict:
    parts = [
        _adf_text(row["unit"], {"type": "code"}),
        _adf_text(f" {_num(row['baseline'])} -> {_num(row['current'])} ({_delta(row['delta'])})"),
    ]
    changes = row.get("directives") or []
    if changes:
        shown = [
            f"{c['directive']} {c['baseline']:.1f} -> {c['current']:.1f}"
            for c in changes[:ALERT_MAX_DIRECTIVES]
        ]
        more = len(changes) - len(shown)
        text = ", changed directives: " + ", ".join(shown)
        parts.append(_adf_text(text + (f" and {more} more" if more > 0 else "")))
    return {"type": "listItem", "content": [_adf_paragraph(*parts)]}


def build_alert_adf(
    account_id: str,
    findings: list[tuple[str, list[dict] | None]],
    *,
    branch: str | None,
    run_url: str | None,
    report_url: str | None,
) -> dict:
    """The comment body (Atlassian Document Format): mention first, then one block per host."""
    content = [
        _adf_paragraph(
            {"type": "mention", "attrs": {"id": account_id, "text": "@maintainer"}},
            _adf_text(" systemd security check failed"),
        )
    ]
    for name, rows in findings:
        if rows is None:
            content.append(
                _adf_paragraph(
                    _adf_text(name, {"type": "strong"}),
                    _adf_text(": could not fetch the report (SSH failed or timed out)."),
                )
            )
            continue
        content.append(
            _adf_paragraph(
                _adf_text(name, {"type": "strong"}), _adf_text(f": {len(rows)} regression(s)")
            )
        )
        content.append({"type": "bulletList", "content": [_regression_item(row) for row in rows]})
    content.append(_adf_paragraph(_adf_text(f"Branch: {branch or 'local'}")))
    where = [_adf_text("Pipeline run: ")]
    where.append(_adf_link(run_url, run_url) if run_url else _adf_text("not available"))
    content.append(_adf_paragraph(*where))
    if report_url:
        report = [_adf_text("Report: "), _adf_link(report_url, report_url)]
    else:
        report = [_adf_text("Report: in the pipeline run's artifacts, under reports/")]
    content.append(_adf_paragraph(*report))
    return {"type": "doc", "version": 1, "content": content}


def post_jira_comment(url: str, email: str, token: str, adf: dict, opener=None) -> None:
    """POST the comment; raises urllib.error.URLError / OSError on any failure."""
    credentials = base64.b64encode(f"{email}:{token}".encode()).decode()
    request = urllib.request.Request(
        url,
        data=json.dumps({"body": adf}).encode("utf-8"),
        method="POST",
        headers={
            "Authorization": f"Basic {credentials}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    with (opener or urllib.request.urlopen)(request, timeout=ALERT_TIMEOUT) as response:
        response.read()


def alert_url(environ: dict[str, str] | os._Environ, issue: str) -> str:
    """Comment endpoint: the api.atlassian.com gateway for a scoped token, else the site."""
    cloud_id = (environ.get("JIRA_ALERT_CLOUD_ID") or "").strip()
    if cloud_id:
        base = f"https://api.atlassian.com/ex/jira/{cloud_id}"
    else:
        base = f"https://{environ['JIRA_ALERT_SITE']}.atlassian.net"
    return f"{base}/rest/api/3/issue/{urllib.parse.quote(issue, safe='')}/comment"


def cmd_jira_alert(args: argparse.Namespace) -> int:
    config = {name: os.environ.get(name, "").strip() for name in ALERT_VARS}
    if not all(config.values()):
        print("Jira alert not configured, skipping")
        return 0
    findings = alert_findings(Path(args.reports_dir), parse_hosts(args.hosts))
    if not findings:
        print("no regression and every host fetched: no Jira alert needed")
        return 0
    adf = build_alert_adf(
        config["JIRA_ALERT_MENTION"],
        findings,
        branch=os.environ.get("BITBUCKET_BRANCH") or None,
        run_url=pipeline_url(),
        report_url=args.report_url,
    )
    issue = config["JIRA_ALERT_ISSUE"]
    try:
        url = alert_url({**os.environ, **config}, issue)
        post_jira_comment(url, config["JIRA_ALERT_EMAIL"], config["JIRA_ALERT_TOKEN"], adf)
    except Exception as exc:  # a failed post must never change the step's result
        print(f"WARNING: Jira alert failed: {type(exc).__name__}: {exc}", file=sys.stderr)
        return 0
    print(f"Jira alert posted on {issue}")
    return 0


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

    p = sub.add_parser(
        "publish-report", help=f"write the {REPORT_NAME} page from the compare JSONs"
    )
    p.add_argument("--hosts", required=True, help="space-separated live host names")
    p.add_argument("--reports-dir", default="reports")
    p.add_argument("--out", default=f"reports/{REPORT_NAME}")
    p.add_argument("--date", help="run date to print (default: now, UTC)")
    p.set_defaults(func=cmd_publish_report)

    p = sub.add_parser("should-publish", help="exit 0 when this run should commit the report")
    p.set_defaults(func=cmd_should_publish)

    p = sub.add_parser("jira-alert", help="comment on a Jira issue when a host regressed")
    p.add_argument("--hosts", required=True, help="space-separated live host names")
    p.add_argument("--reports-dir", default="reports")
    p.add_argument("--report-url", help="where the committed report can be read")
    p.set_defaults(func=cmd_jira_alert)
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
