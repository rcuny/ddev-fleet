"""FLE-16: the published systemd security report (`publish-report`), the publish
decision (`should-publish`), the Jira alert (`jira-alert`) and how
ci/systemd-security-live.sh commits and pushes the report.

Everything runs offline: the compare JSONs are produced from fixture baselines in
tmp_path (never the real ci/systemd-security-baseline.json, which changes whenever
a real baseline is recorded), `ssh` and `git` are fake binaries and the Jira POST
goes through a monkeypatched urlopen."""

from __future__ import annotations

import json
import re
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

import pytest

from tests.test_systemd_security_ci import (
    LIVE,
    _baseline,
    _report,
    _unit,
    _write,
    ci,
    live_env,  # noqa: F401  (a fixture, used by name)
)

PRODUCT_UNITS = [
    "fleet.service",
    "fleet-boot.service",
    "fleet-reboot-notify.service",
    "caddy.service",
    "authelia.service",
]
BITBUCKET_VARS = (
    "BITBUCKET_GIT_HTTP_ORIGIN",
    "BITBUCKET_BUILD_NUMBER",
    "BITBUCKET_BRANCH",
    "PUBLISH_REPORT",
)
ALERT_VARS = {
    "JIRA_ALERT_SITE": "acme",
    "JIRA_ALERT_EMAIL": "bot@example.com",
    "JIRA_ALERT_TOKEN": "s3cr3t-token-value",
    "JIRA_ALERT_ISSUE": "FLE-17",
    "JIRA_ALERT_MENTION": "557058:abc-def",
}


@pytest.fixture(autouse=True)
def clean_environment(monkeypatch):
    for name in (*BITBUCKET_VARS, *ALERT_VARS, "JIRA_ALERT_CLOUD_ID"):
        monkeypatch.delenv(name, raising=False)


def _compare(reports: Path, baseline_file: Path, name: str, current: dict) -> int:
    """What the live script does per host: write reports/systemd-security-<name>.json."""
    raw = _write(reports / f"{name}.json", current)
    return ci.main(
        [
            "compare",
            "--baseline", str(baseline_file),
            "--env", name,
            "--current", str(raw),
            "--report-json", str(reports / f"systemd-security-{name}.json"),
        ]
    )  # fmt: skip


def _fleet_units(fleet_score: float, **directives: float) -> dict:
    units = {u: _unit(1.8) for u in PRODUCT_UNITS if u not in ("authelia.service",)}
    units["fleet.service"] = _unit(fleet_score, **directives)
    return units


@pytest.fixture
def reports(tmp_path):
    """alpha: fleet.service regressed 1.8 -> 2.5; beta: all fine; offline: fine."""
    out = tmp_path / "reports"
    out.mkdir()
    base_units = _fleet_units(1.8, PrivateTmp=0.0, ProtectHome=0.0)
    base_units["zeta.service"] = _unit(5.0)
    baseline = _baseline(
        tmp_path,
        alpha=_report(base_units),
        beta=_report(base_units),
        offline=_report(base_units),
    )
    bad = _fleet_units(2.5, PrivateTmp=0.5, ProtectHome=0.2)
    bad["zeta.service"] = _unit(5.0)
    good = _fleet_units(1.8, PrivateTmp=0.0, ProtectHome=0.0)
    good["zeta.service"] = _unit(5.0)
    assert _compare(out, baseline, "alpha", _report(bad)) == 1
    assert _compare(out, baseline, "beta", _report(good)) == 0
    assert _compare(out, baseline, "offline", _report(good)) == 0
    return out


def _publish(tmp_path, capsys, reports, hosts, *extra) -> str:
    out = tmp_path / "page" / "REPORT.md"
    code = ci.main(
        ["publish-report", "--hosts", hosts, "--reports-dir", str(reports), "--out", str(out)]
        + ["--date", "2026-10-07 09:00 UTC", *extra]
    )
    capsys.readouterr()
    assert code == 0
    return out.read_text()


# --- publish-report -------------------------------------------------------------------------------


def test_report_has_the_explanation_status_lines_and_sections(tmp_path, capsys, reports):
    text = _publish(tmp_path, capsys, reports, "alpha beta")
    assert text.startswith("# systemd security report\n")
    assert "0 (best: locked down) to 10 (worst: no sandbox at all)" in text
    assert "more than the tolerance (+0.1)" in text and "ci/systemd-security-baseline.json" in text
    assert "**Run:** 2026-10-07 09:00 UTC" in text
    assert "| [alpha](#alpha) | 257 | **1 regression(s)** |" in text
    assert "| [beta](#beta) | 257 | OK |" in text
    assert "## alpha" in text and "## beta" in text
    assert "## Offline: the unit files the product ships" in text
    assert text.rstrip().endswith("[docs/README-ci.md](docs/README-ci.md).")


def test_product_units_come_first_in_the_fixed_order(tmp_path, capsys, reports):
    text = _publish(tmp_path, capsys, reports, "alpha")
    section = text[text.index("## alpha") : text.index("<details>")]
    positions = [section.index(f"| `{u}` |") for u in PRODUCT_UNITS]
    assert positions == sorted(positions)
    assert "| `fleet.service` | 2.5 | 1.8 | +0.7 | **REGRESSION** |" in section
    assert "| `authelia.service` | - | - | - | not loaded |" in section
    # the host overview lists every unit (not only the product's), inside <details>
    assert "<details><summary>All 5 units on alpha</summary>\n\n| Unit |" in text
    overview = text[text.index("<details>") : text.index("</details>")]
    assert "`zeta.service`" in overview
    assert "</details>\n\n## Offline" in text


def test_regression_lists_the_changed_directives(tmp_path, capsys, reports):
    text = _publish(tmp_path, capsys, reports, "alpha beta")
    assert "### Regression: `fleet.service` 1.8 -> 2.5 (+0.7)" in text
    assert "| `PrivateTmp` | 0.0 | 0.5 | +0.5 |" in text
    assert "| `ProtectHome` | 0.0 | 0.2 | +0.2 |" in text
    beta = text[text.index("## beta") :]
    assert "### Regression" not in beta.split("## Offline")[0]


def test_output_is_deterministic_whatever_the_host_order(tmp_path, capsys, reports):
    first = _publish(tmp_path, capsys, reports, "alpha beta")
    assert _publish(tmp_path, capsys, reports, "beta alpha") == first
    assert _publish(tmp_path, capsys, reports, "beta alpha beta") == first
    assert first.index("## alpha") < first.index("## beta")


def test_host_without_a_comparison_is_could_not_be_fetched(tmp_path, capsys, reports):
    text = _publish(tmp_path, capsys, reports, "alpha beta ghost")
    assert "| [ghost](#ghost) | - | **could not be fetched** |" in text
    assert "## ghost" in text and "the SSH fetch failed" in text
    assert "| [beta](#beta) | 257 | OK |" in text  # the others are still published


def test_host_without_a_baseline_says_so(tmp_path, capsys, reports):
    baseline = _baseline(tmp_path)
    assert _compare(reports, baseline, "fresh", _report(_fleet_units(1.8))) == 0
    text = _publish(tmp_path, capsys, reports, "fresh")
    assert "| [fresh](#fresh) | 257 | OK (no baseline yet) |" in text
    assert "No baseline is recorded for `fresh` yet" in text


def test_offline_section_without_offline_scores(tmp_path, capsys, reports):
    (reports / "systemd-security-offline.json").unlink()
    text = _publish(tmp_path, capsys, reports, "beta")
    assert "Offline scores were not produced in this run" in text
    assert "| `fleet.service` |" in text  # the live section is unaffected


def test_offline_section_lists_the_scores(tmp_path, capsys, reports):
    text = _publish(tmp_path, capsys, reports, "beta")
    offline = text[text.index("## Offline") :]
    assert "systemd 257" in offline and "| `fleet.service` | 1.8 | 1.8 | 0.0 | OK |" in offline


def test_pipeline_link_only_with_both_bitbucket_variables(tmp_path, capsys, reports, monkeypatch):
    assert "pipelines/results" not in _publish(tmp_path, capsys, reports, "beta")
    monkeypatch.setenv("BITBUCKET_GIT_HTTP_ORIGIN", "http://bitbucket.org/acme/repo")
    assert "pipelines/results" not in _publish(tmp_path, capsys, reports, "beta")
    monkeypatch.setenv("BITBUCKET_BUILD_NUMBER", "60")
    monkeypatch.setenv("BITBUCKET_BRANCH", "develop")
    text = _publish(tmp_path, capsys, reports, "beta")
    assert "[pipeline run](https://bitbucket.org/acme/repo/pipelines/results/60)" in text
    assert "branch `develop`" in text
    monkeypatch.delenv("BITBUCKET_GIT_HTTP_ORIGIN")
    assert "pipelines/results" not in _publish(tmp_path, capsys, reports, "beta")


def test_publish_report_prints_the_output_path_and_defaults_the_date(tmp_path, capsys, reports):
    capsys.readouterr()  # the compare runs of the fixture
    out = tmp_path / "x" / "r.md"
    code = ci.main(
        ["publish-report", "--hosts", "beta", "--reports-dir", str(reports), "--out", str(out)]
    )
    assert code == 0 and capsys.readouterr().out.strip() == str(out)
    assert " UTC" in out.read_text().split("**Run:** ")[1].splitlines()[0]


def test_publish_report_rejects_an_empty_host_list(tmp_path, capsys, reports):
    code = ci.main(["publish-report", "--hosts", "  ", "--reports-dir", str(reports)])
    assert code == 2 and "--hosts is empty" in capsys.readouterr().err


# --- should-publish -------------------------------------------------------------------------------


@pytest.mark.parametrize(
    ("branch", "flag", "expected"),
    [
        ("develop", None, True),
        ("develop", "0", True),
        ("feature/FLE-16-x", None, False),
        ("feature/FLE-16-x", "1", True),
        ("feature/FLE-16-x", "0", False),
        ("feature/FLE-16-x", "true", False),
        ("feature/FLE-16-x", "", False),
        ("main", None, False),
        (None, None, False),
        (None, "1", True),
    ],
)
def test_publish_wanted(branch, flag, expected):
    assert ci.publish_wanted(branch, flag) is expected


def test_should_publish_reads_the_environment_and_exits_accordingly(capsys, monkeypatch):
    assert ci.main(["should-publish"]) == 1
    assert "not publishing" in capsys.readouterr().out
    monkeypatch.setenv("BITBUCKET_BRANCH", "develop")
    assert ci.main(["should-publish"]) == 0
    assert "branch develop" in capsys.readouterr().out
    monkeypatch.setenv("BITBUCKET_BRANCH", "feature/x")
    assert ci.main(["should-publish"]) == 1
    monkeypatch.setenv("PUBLISH_REPORT", "1")
    assert ci.main(["should-publish"]) == 0
    assert "PUBLISH_REPORT=1" in capsys.readouterr().out


# --- jira-alert -----------------------------------------------------------------------------------


class _Response:
    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def read(self):
        return b"{}"


@pytest.fixture
def posts(monkeypatch):
    """Record every Jira POST instead of sending it; set `.fail` to make it raise."""

    class Posts(list):
        fail: Exception | None = None

    seen = Posts()

    def fake_urlopen(request, timeout=None):
        seen.append((request, timeout))
        if seen.fail:
            raise seen.fail
        return _Response()

    monkeypatch.setattr(urllib.request, "urlopen", fake_urlopen)
    return seen


@pytest.fixture
def configured(monkeypatch):
    for name, value in ALERT_VARS.items():
        monkeypatch.setenv(name, value)


def _alert(reports, hosts="alpha beta", *extra):
    return ci.main(["jira-alert", "--hosts", hosts, "--reports-dir", str(reports), *extra])


def _body(request) -> dict:
    return json.loads(request.data)["body"]


def _flatten(node) -> str:
    if isinstance(node, dict):
        return " ".join([node.get("text", ""), *(_flatten(c) for c in node.get("content", []))])
    return " ".join(_flatten(n) for n in node)


def test_alert_payload_starts_with_a_mention_node(reports, posts, configured, monkeypatch):
    monkeypatch.setenv("BITBUCKET_BRANCH", "feature/FLE-16-x")
    monkeypatch.setenv("BITBUCKET_GIT_HTTP_ORIGIN", "http://bitbucket.org/acme/repo")
    monkeypatch.setenv("BITBUCKET_BUILD_NUMBER", "61")
    assert _alert(reports, "alpha beta", "--report-url", "https://example.com/report.md") == 0
    ((request, timeout),) = posts
    assert timeout and timeout > 0
    body = _body(request)
    assert body["type"] == "doc" and body["version"] == 1
    first = body["content"][0]
    assert first["type"] == "paragraph"
    assert first["content"][0] == {
        "type": "mention",
        "attrs": {"id": "557058:abc-def", "text": "@maintainer"},
    }
    text = _flatten(body["content"])
    for expected in (
        "systemd security check failed",
        "alpha",
        "1 regression(s)",
        "fleet.service",
        "1.8 -> 2.5 (+0.7)",
        "PrivateTmp 0.0 -> 0.5",
        "Branch: feature/FLE-16-x",
        "https://bitbucket.org/acme/repo/pipelines/results/61",
        "https://example.com/report.md",
    ):
        assert expected in text, expected
    assert "beta" not in text  # a host that is fine is not named
    links = [
        mark["attrs"]["href"]
        for para in body["content"]
        if para["type"] == "paragraph"
        for node in para["content"]
        for mark in node.get("marks", [])
        if mark["type"] == "link"
    ]
    assert links == [
        "https://bitbucket.org/acme/repo/pipelines/results/61",
        "https://example.com/report.md",
    ]


def test_alert_names_a_host_that_could_not_be_fetched(reports, posts, configured):
    assert _alert(reports, "beta ghost") == 0
    text = _flatten(_body(posts[0][0])["content"])
    assert "ghost" in text and "could not fetch the report" in text
    assert "Branch: local" in text
    assert "in the pipeline run's artifacts, under reports/" in text


def test_alert_uses_the_site_url_without_a_cloud_id(reports, posts, configured):
    assert _alert(reports) == 0
    request, _ = posts[0]
    assert request.full_url == "https://acme.atlassian.net/rest/api/3/issue/FLE-17/comment"
    assert request.get_method() == "POST"
    assert request.get_header("Content-type") == "application/json"
    import base64

    assert (
        request.get_header("Authorization")
        == "Basic " + base64.b64encode(b"bot@example.com:s3cr3t-token-value").decode()
    )


def test_alert_uses_the_gateway_url_with_a_cloud_id(reports, posts, configured, monkeypatch):
    monkeypatch.setenv("JIRA_ALERT_CLOUD_ID", "f05a0fe7-1111")
    assert _alert(reports) == 0
    assert posts[0][0].full_url == (
        "https://api.atlassian.com/ex/jira/f05a0fe7-1111/rest/api/3/issue/FLE-17/comment"
    )


@pytest.mark.parametrize("missing", sorted(ALERT_VARS))
def test_alert_skips_when_any_variable_is_unset_or_empty(
    reports, posts, configured, monkeypatch, capsys, missing
):
    for value in (None, ""):
        if value is None:
            monkeypatch.delenv(missing)
        else:
            monkeypatch.setenv(missing, value)
        assert _alert(reports) == 0
        assert capsys.readouterr().out.strip() == "Jira alert not configured, skipping"
    assert posts == []


def test_alert_posts_nothing_when_every_host_is_fine(reports, posts, configured, capsys):
    assert _alert(reports, "beta") == 0
    assert posts == [] and "no Jira alert needed" in capsys.readouterr().out


@pytest.mark.parametrize(
    "failure",
    [
        urllib.error.HTTPError("https://x", 401, "Unauthorized", {}, None),
        urllib.error.URLError("name resolution failed"),
        TimeoutError("timed out"),
    ],
)
def test_a_failed_post_warns_and_still_exits_zero(reports, posts, configured, capsys, failure):
    posts.fail = failure
    assert _alert(reports) == 0
    captured = capsys.readouterr()
    assert "WARNING: Jira alert failed:" in captured.err
    assert "s3cr3t-token-value" not in captured.out + captured.err


def test_the_token_is_never_printed(reports, posts, configured, capsys):
    assert _alert(reports) == 0
    captured = capsys.readouterr()
    assert "s3cr3t-token-value" not in captured.out + captured.err
    assert "posted on FLE-17" in captured.out


def test_long_directive_lists_are_cut_off(reports, posts, configured):
    path = reports / "systemd-security-alpha.json"
    data = json.loads(path.read_text())
    row = next(r for r in data["rows"] if r["status"] == "REGRESSION")
    row["directives"] = [
        {"directive": f"D{i:02d}", "baseline": 0.0, "current": 0.1, "delta": 0.1} for i in range(12)
    ]
    path.write_text(json.dumps(data))
    assert _alert(reports, "alpha") == 0
    text = _flatten(_body(posts[0][0])["content"])
    assert "D07" in text and "D08" not in text and "and 4 more" in text


# --- ci/systemd-security-live.sh: commit, push, alert ---------------------------------------------

FAKE_GIT = """\
#!PYTHON
import json
import os
import sys

argv = sys.argv[1:]
log = os.environ["FAKE_GIT_LOG"]
with open(log, "a") as handle:
    handle.write(json.dumps(argv) + "\\n")
args = list(argv)
while args[:1] == ["-c"]:
    args = args[2:]
sub = args[0]
if sub == "push" and os.environ.get("FAKE_GIT_PUSH_FAILS"):
    if os.environ["FAKE_GIT_PUSH_FAILS"] == "always" or not os.path.exists(log + ".pushed"):
        open(log + ".pushed", "w").close()
        print("! [rejected] (fetch first)", file=sys.stderr)
        sys.exit(1)
if sub == "diff":
    sys.exit(0 if os.environ.get("FAKE_GIT_UNCHANGED") else 1)
if sub == "rebase" and os.environ.get("FAKE_GIT_REBASE_FAILS") and args[1] != "--abort":
    sys.exit(1)
""".replace("PYTHON", sys.executable)


@pytest.fixture
def live(live_env, tmp_path):  # noqa: F811
    """live_env (fake ssh, fixture baseline, no offline step) plus a fake git that logs its argv."""
    work, env = live_env
    git = Path(env["PATH"].split(":")[0]) / "git"
    git.write_text(FAKE_GIT)
    git.chmod(0o755)
    env = {
        **env,
        "FAKE_GIT_LOG": str(tmp_path / "git.log"),
        "SECURITY_PROBE_TARGETS": "ddev3=fleet-probe@ddev3.example",
    }
    for name in ("FAKE_GIT_PUSH_FAILS", "FAKE_GIT_UNCHANGED", "FAKE_GIT_REBASE_FAILS"):
        env.pop(name, None)
    return work, env, tmp_path / "git.log"


def _run_live(work, env, **extra):
    return subprocess.run(
        ["bash", str(LIVE)],
        cwd=work,
        env={**env, **extra},
        capture_output=True,
        text=True,
        timeout=60,
    )


def _git_calls(log: Path) -> list[list[str]]:
    return [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []


def _plain(argv: list[str]) -> list[str]:
    """The git command without its leading `-c key=value` options."""
    while argv[:1] == ["-c"]:
        argv = argv[2:]
    return argv


def _subcommands(log: Path) -> list[str]:
    return [_plain(argv)[0] for argv in _git_calls(log)]


def test_live_other_branch_writes_the_artifact_but_commits_nothing(live):
    work, env, log = live
    done = _run_live(work, env, BITBUCKET_BRANCH="feature/FLE-9-other")
    assert done.returncode == 0, done.stderr
    assert (work / "reports" / "SYSTEMD-SECURITY-REPORT.md").exists()
    assert not (work / "SYSTEMD-SECURITY-REPORT.md").exists()
    assert _git_calls(log) == []
    assert "not publishing" in done.stdout


def test_live_without_a_branch_variable_runs_locally_and_commits_nothing(live):
    work, env, log = live
    done = _run_live(work, env)
    assert done.returncode == 0, done.stderr
    assert (work / "reports" / "SYSTEMD-SECURITY-REPORT.md").exists()
    assert _git_calls(log) == []


def test_live_develop_commits_with_the_bot_identity_and_pushes(live):
    work, env, log = live
    done = _run_live(
        work,
        env,
        BITBUCKET_BRANCH="develop",
        BITBUCKET_GIT_HTTP_ORIGIN="http://bitbucket.org/acme/repo",
    )
    assert done.returncode == 0, done.stderr
    assert (work / "SYSTEMD-SECURITY-REPORT.md").read_text().startswith("# systemd security report")
    calls = _git_calls(log)
    assert _subcommands(log) == ["add", "diff", "commit", "push"]
    assert calls[0] == ["add", "SYSTEMD-SECURITY-REPORT.md"]
    commit = calls[2]
    assert commit[:4] == [
        "-c",
        "user.name=ddev-fleet security check",
        "-c",
        "user.email=security-check@noreply.fleet.pm",
    ]
    assert commit[4:6] == ["commit", "-m"]
    assert re.fullmatch(r"chore\(security\): systemd security report \d{4}-\d\d-\d\d", commit[6])
    assert calls[3] == ["push", "origin", "HEAD:refs/heads/develop"]
    assert not any(a in ("--force", "-f", "--force-with-lease") for c in calls for a in c)


def test_live_feature_branch_with_the_switch_pushes_that_branch(live):
    work, env, log = live
    done = _run_live(work, env, BITBUCKET_BRANCH="feature/FLE-16-x", PUBLISH_REPORT="1")
    assert done.returncode == 0, done.stderr
    assert _git_calls(log)[-1] == ["push", "origin", "HEAD:refs/heads/feature/FLE-16-x"]


@pytest.mark.parametrize("flag", ["0", "true", ""])
def test_live_other_switch_values_do_not_publish(live, flag):
    work, env, log = live
    _run_live(work, env, BITBUCKET_BRANCH="feature/x", PUBLISH_REPORT=flag)
    assert _git_calls(log) == []


def test_live_unchanged_report_is_not_committed(live):
    work, env, log = live
    done = _run_live(work, env, BITBUCKET_BRANCH="develop", FAKE_GIT_UNCHANGED="1")
    assert done.returncode == 0, done.stderr
    assert "report unchanged, nothing to commit" in done.stdout
    assert _subcommands(log) == ["add", "diff"]


def test_live_publishing_without_a_branch_is_refused(live):
    work, env, log = live
    done = _run_live(work, env, PUBLISH_REPORT="1")
    assert done.returncode == 1
    assert "refusing to publish the report without BITBUCKET_BRANCH" in done.stderr
    assert _git_calls(log) == []


def test_live_regression_still_commits_then_fails(live, tmp_path):
    work, env, log = live
    worse = _baseline(tmp_path, ddev3=_report({"fleet.service": _unit(1.0)}))
    done = _run_live(work, env, BITBUCKET_BRANCH="develop", SYSTEMD_SECURITY_BASELINE=str(worse))
    assert done.returncode == 1
    assert "REGRESSION fleet.service (ddev3)" in done.stderr
    assert _subcommands(log) == ["add", "diff", "commit", "push"]
    assert "**1 regression(s)**" in (work / "SYSTEMD-SECURITY-REPORT.md").read_text()
    assert "Jira alert not configured, skipping" in done.stdout  # the alert step ran


def test_live_regression_on_another_branch_does_not_commit(live, tmp_path):
    work, env, log = live
    worse = _baseline(tmp_path, ddev3=_report({"fleet.service": _unit(1.0)}))
    done = _run_live(work, env, BITBUCKET_BRANCH="acceptance", SYSTEMD_SECURITY_BASELINE=str(worse))
    assert done.returncode == 1
    assert _git_calls(log) == []
    assert "Jira alert not configured, skipping" in done.stdout


def test_live_push_rejected_once_fetches_rebases_and_pushes_again(live):
    work, env, log = live
    done = _run_live(work, env, BITBUCKET_BRANCH="develop", FAKE_GIT_PUSH_FAILS="once")
    assert done.returncode == 0, done.stderr
    calls = _git_calls(log)
    assert _subcommands(log) == ["add", "diff", "commit", "push", "fetch", "rebase", "push"]
    assert calls[4] == ["fetch", "origin", "+refs/heads/develop:refs/remotes/origin/develop"]
    assert _plain(calls[5]) == ["rebase", "origin/develop"]
    assert "user.name=ddev-fleet security check" in calls[5]  # a rebase needs an identity too
    assert calls[6] == ["push", "origin", "HEAD:refs/heads/develop"]
    assert not any(a in ("--force", "-f", "--force-with-lease") for c in calls for a in c)


def test_live_push_failing_twice_fails_the_step_but_still_alerts(live):
    work, env, log = live
    done = _run_live(work, env, BITBUCKET_BRANCH="develop", FAKE_GIT_PUSH_FAILS="always")
    assert done.returncode == 1
    assert _subcommands(log).count("push") == 2  # one retry, no more
    assert "the report was not published" in done.stderr


def test_live_rebase_conflict_aborts_and_fails(live):
    work, env, log = live
    done = _run_live(
        work, env, BITBUCKET_BRANCH="develop", FAKE_GIT_PUSH_FAILS="once", FAKE_GIT_REBASE_FAILS="1"
    )
    assert done.returncode == 1
    assert _subcommands(log)[-2:] == ["rebase", "rebase"]  # the second one is --abort
    assert _git_calls(log)[-1] == ["rebase", "--abort"]
    assert _subcommands(log).count("push") == 1
    assert "could not rebase the report commit" in done.stderr


def test_live_a_host_that_cannot_be_fetched_still_publishes_the_others(live):
    work, env, log = live
    done = _run_live(
        work,
        env,
        BITBUCKET_BRANCH="develop",
        SECURITY_PROBE_TARGETS="down=fleet-probe@broken.example ddev3=fleet-probe@ddev3.example",
    )
    assert done.returncode == 1
    assert _subcommands(log) == ["add", "diff", "commit", "push"]
    page = (work / "SYSTEMD-SECURITY-REPORT.md").read_text()
    assert "| [down](#down) | - | **could not be fetched** |" in page
    assert "| [ddev3](#ddev3) | 257 | OK |" in page
    assert "Jira alert not configured, skipping" in done.stdout


def test_live_link_to_the_report_is_not_needed_to_publish(live):
    work, env, log = live
    done = _run_live(work, env, BITBUCKET_BRANCH="develop")  # no BITBUCKET_GIT_HTTP_ORIGIN
    assert done.returncode == 0, done.stderr


def test_live_no_targets_means_no_report_no_git_no_alert(live):
    work, env, log = live
    env["SECURITY_PROBE_TARGETS"] = ""
    done = _run_live(work, env, BITBUCKET_BRANCH="develop", PUBLISH_REPORT="1")
    assert done.returncode == 0 and "skipping" in done.stdout
    assert "Jira" not in done.stdout + done.stderr
    assert _git_calls(log) == []
    assert not (work / "reports").exists()


def test_live_offline_scores_join_the_report_when_systemd_analyze_exists(live):
    from tests.test_systemd_security_ci import HAVE_ANALYZE

    if not HAVE_ANALYZE:
        pytest.skip("systemd-analyze not installed")
    pytest.importorskip("jinja2")
    work, env, _ = live
    env.pop("SYSTEMD_ANALYZE")
    # the real offline baseline is not used: a fixture baseline without "offline" makes it NEW
    done = _run_live(work, env, BITBUCKET_BRANCH="acceptance")
    assert done.returncode == 0, done.stderr
    page = (work / "reports" / "SYSTEMD-SECURITY-REPORT.md").read_text()
    offline = page[page.index("## Offline") :]
    assert "| `fleet.service` |" in offline and "NEW" in offline
