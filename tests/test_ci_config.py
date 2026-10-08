"""Structure checks for the Bitbucket Pipelines CI and the Renovate config (FLE-2).

The pipelines can't run in the test suite, so this pins the properties that
matter: gates run before any mirror step, mirror pushes are never forced, a
release tag can only publish the tip of main, and the Renovate htmx regex
matches the marker line in docs/vendored-assets.md.
"""

from __future__ import annotations

import json
import re
from pathlib import Path

from ruamel.yaml import YAML

ROOT = Path(__file__).resolve().parents[1]
PIPELINES = YAML(typ="safe").load((ROOT / "bitbucket-pipelines.yml").read_text())
RENOVATE = json.loads((ROOT / "renovate-config.json").read_text())
GATES = ["pytest -q", "ruff check .", "black --check .", "node --test tests/js/*.test.mjs"]


def _steps(pipeline):
    """Every step of a pipeline, in order; a `parallel` group is flattened."""
    steps = []
    for item in pipeline:
        if "parallel" in item:
            steps += [sub["step"] for sub in item["parallel"]]
        else:
            steps.append(item["step"])
    return steps


def _script(step) -> str:
    return "\n".join(step["script"])


def _gates(pipeline):
    (step,) = [s for s in _steps(pipeline) if s["name"].startswith("Gates")]
    return step


def _mirror_steps():
    for pipeline in (
        PIPELINES["pipelines"]["branches"]["develop"],
        PIPELINES["pipelines"]["tags"]["v*"],
    ):
        yield pipeline, _steps(pipeline)[-1]


def test_pull_request_pipeline_runs_the_gates():
    assert all(
        cmd in _gates(PIPELINES["pipelines"]["pull-requests"]["**"])["script"] for cmd in GATES
    )


def test_develop_and_tag_pipelines_run_gates_before_the_mirror_step():
    for pipeline, mirror in _mirror_steps():
        # the mirror step is a separate, later top-level item: it only starts
        # once everything before it (the gates) has passed
        assert "parallel" not in pipeline[-1] and pipeline[-1]["step"] is mirror
        assert all(cmd in _gates(pipeline)["script"] for cmd in GATES)
        assert "push" in _script(mirror)


def test_mirror_steps_clone_full_history_and_skip_without_a_mirror_url():
    for _, mirror in _mirror_steps():
        assert mirror["clone"]["depth"] == "full"
        assert 'if [ -z "${GITHUB_MIRROR_URL:-}" ]' in _script(mirror)


def test_mirror_pushes_are_never_forced():
    for _, mirror in _mirror_steps():
        pushes = [ln for ln in _script(mirror).splitlines() if "git push" in ln]
        assert pushes
        for line in pushes:
            assert "--force" not in line and " -f" not in line and "--mirror" not in line
            assert not re.search(r"(^|\s)\+", line.split("git push", 1)[1])


def test_tag_step_refuses_a_tag_that_is_not_the_tip_of_main():
    script = _script(_steps(PIPELINES["pipelines"]["tags"]["v*"])[-1])
    assert 'git rev-parse origin/main)" != "$BITBUCKET_COMMIT"' in script
    assert script.index("rev-parse origin/main") < script.index("git push")


def test_develop_step_only_mirrors_the_latest_develop_commit():
    script = _script(_steps(PIPELINES["pipelines"]["branches"]["develop"])[-1])
    assert 'git rev-parse origin/develop)" != "$BITBUCKET_COMMIT"' in script
    assert script.index("rev-parse origin/develop") < script.index("git push")


def test_gates_run_on_debian_13_with_systemd_and_require_systemd_analyze():
    assert PIPELINES["image"].startswith("python:3.11-trixie")
    gates = _gates(PIPELINES["pipelines"]["pull-requests"]["**"])
    script = _script(gates)
    assert re.search(r"apt-get install .*\brsync systemd\b", script)
    assert script.index("FLEET_REQUIRE_SYSTEMD_ANALYZE=1") < script.index("pytest -q")
    assert re.search(r"apt-get install .*\bgnupg\b.*\bnodejs\b", script)
    assert script.index("FLEET_REQUIRE_PGP_TOOLS=1") < script.index("pytest -q")
    assert script.index("FLEET_REQUIRE_E2E=1") < script.index("pytest -q")
    install = script.index('pip install -q -e ".[dev]"')
    browser = script.index("python -m playwright install --with-deps --only-shell chromium")
    assert install < browser < script.index("pytest -q")
    node_gate = script.index("node --test tests/js/*.test.mjs")
    assert script.index("black --check .") < node_gate
    assert node_gate < script.index("systemd_security.py offline")
    assert script.index("systemd_security.py offline") < script.index("systemd_security.py compare")
    assert gates["artifacts"] == ["reports/**"]


def test_playwright_is_a_pinned_dev_dependency():
    # FLE-24: the browser E2E suite. The pin must equal the companion's
    # test/playwright/package.json (the Chromium build comes from the Playwright version).
    pyproject = (ROOT / "pyproject.toml").read_text()
    assert re.search(r'"playwright==\d+\.\d+\.\d+"', pyproject)
    dev = pyproject[pyproject.index("dev = [") : pyproject.index("infra = [")]
    assert '"playwright==' in dev


def test_ansible_lint_is_a_non_blocking_parallel_step_next_to_the_gates():
    for pipeline in (
        PIPELINES["pipelines"]["pull-requests"]["**"],
        PIPELINES["pipelines"]["branches"]["develop"],
    ):
        group = pipeline[0]["parallel"]
        names = [item["step"]["name"] for item in group]
        assert any(n.startswith("Gates") for n in names)
        (lint,) = [i["step"] for i in group if i["step"]["name"].startswith("Ansible lint")]
        script = _script(lint)
        assert ".[infra]" in script
        for tool in ("ansible-lint ansible/", "yamllint ansible/"):
            assert any(
                ln.startswith(tool) and "|| echo" in ln and "non-blocking" in ln
                for ln in lint["script"]
            ), tool
    tag_names = [s["name"] for s in _steps(PIPELINES["pipelines"]["tags"]["v*"])]
    assert not any(n.startswith("Ansible lint") for n in tag_names)


def test_live_systemd_security_pipeline_is_a_custom_pipeline_using_the_script():
    (step,) = _steps(PIPELINES["pipelines"]["custom"]["systemd-security-live"])
    # FLE-16: the full image has git (the report is committed); systemd scores the units offline.
    assert step["image"] == "python:3.11-trixie"
    script = _script(step)
    assert "openssh-client systemd" in script and "pip install -q -e ." in script
    assert script.index("umask 022") < script.index("bash ci/systemd-security-live.sh")
    assert "bash ci/systemd-security-live.sh" in script
    assert step["artifacts"] == ["reports/**"]


def test_the_report_commit_cannot_loop_back_into_the_live_check():
    # The report commit lands on develop and is NOT [skip ci]: the develop push pipeline must
    # not run the live check, or every weekly run would trigger the next one.
    for pipeline in (
        PIPELINES["pipelines"]["branches"]["develop"],
        PIPELINES["pipelines"]["tags"]["v*"],
    ):
        assert "systemd-security-live" not in json.dumps(pipeline)


def test_github_actions_workflow_is_gone():
    assert not (ROOT / ".github" / "workflows" / "ci.yml").exists()


def test_renovate_pipeline_uses_repo_variable_and_config_file():
    (step,) = _steps(PIPELINES["pipelines"]["custom"]["renovate"])
    script = _script(step)
    assert "$BITBUCKET_REPO_FULL_NAME" in script and "renovate-config.json" in script
    assert step["image"].startswith("renovate/renovate:")


def test_renovate_config_targets_develop_and_needs_no_onboarding():
    assert RENOVATE["baseBranchPatterns"] == ["develop"]
    assert RENOVATE["rangeStrategy"] == "bump"
    assert RENOVATE["onboarding"] is False


def test_renovate_never_bumps_the_python_interpreter():
    rules = [r for r in RENOVATE["packageRules"] if r.get("enabled") is False]
    # matchDepNames covers every datasource: the docker image of the pipelines
    assert any(r.get("matchDepNames") == ["python"] for r in rules)


def _vendored_version(dep_name: str) -> str:
    (manager,) = [m for m in RENOVATE["customManagers"] if m["depNameTemplate"] == dep_name]
    assert manager["managerFilePatterns"] == ["/^docs/vendored-assets\\.md$/"]
    assert manager["datasourceTemplate"] == "npm"
    # JS named groups (?<x>...) -> Python (?P<x>...)
    pattern = re.compile(manager["matchStrings"][0].replace("(?<", "(?P<"))
    match = pattern.search((ROOT / "docs" / "vendored-assets.md").read_text())
    assert match, f"{dep_name} marker row not matched"
    return match.group("currentValue")


def test_htmx_regex_manager_matches_the_vendored_assets_marker():
    version = _vendored_version("htmx.org")
    doc = (ROOT / "docs" / "vendored-assets.md").read_text()
    assert f"htmx.org@{version}/dist/htmx.min.js" in doc
    assert f'version:"{version}"' in (ROOT / "src/fleet/static/htmx.min.js").read_text()


def test_openpgp_regex_manager_matches_the_vendored_assets_marker():
    version = _vendored_version("openpgp")
    doc = (ROOT / "docs" / "vendored-assets.md").read_text()
    assert f"openpgp@{version}/dist/openpgp.min.mjs" in doc
    header = (ROOT / "src/fleet/static/openpgp.min.mjs").read_text(encoding="utf-8")[:200]
    assert f"OpenPGP.js v{version}" in header


def test_renovate_prefixes_commits_with_a_jira_key():
    # Renovate commits/PR titles carry the standing "Dependency updates" ticket key
    assert re.fullmatch(r"[A-Z][A-Z0-9]+-\d+", RENOVATE["commitMessagePrefix"])


def test_renovate_recreates_every_pr_that_is_behind_develop():
    # FLE-11: each PR is tested on the latest develop before it can be merged
    assert RENOVATE["rebaseWhen"] == "behind-base-branch"
    assert not RENOVATE.get("automerge")


def test_renovate_merge_pipeline_runs_renovate_only_when_requested():
    merge, after = _steps(PIPELINES["pipelines"]["custom"]["renovate-merge"])
    assert "scripts/renovate_merge.py" in _script(merge)
    assert "--renovate-flag renovate-requested.flag" in _script(merge)
    assert merge["artifacts"] == ["renovate-requested.flag"]
    assert after["image"].startswith("renovate/renovate:")
    script = _script(after)
    assert script.index("renovate-requested.flag") < script.index("renovate\n")
    assert 'RENOVATE_REPOSITORIES="$BITBUCKET_REPO_FULL_NAME"' in script


def test_renovate_merge_pipeline_holds_no_secret():
    text = (ROOT / "bitbucket-pipelines.yml").read_text()
    section = text[text.index("renovate-merge:\n") :]
    assert "RENOVATE_PASSWORD" not in section and "Authorization" not in section
