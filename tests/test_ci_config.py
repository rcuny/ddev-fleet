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
GATES = ["pytest -q", "ruff check .", "black --check ."]


def _steps(pipeline):
    return [item["step"] for item in pipeline]


def _script(step) -> str:
    return "\n".join(step["script"])


def _mirror_steps():
    for pipeline in (
        PIPELINES["pipelines"]["branches"]["develop"],
        PIPELINES["pipelines"]["tags"]["v*"],
    ):
        yield pipeline, _steps(pipeline)[1]


def test_pull_request_pipeline_runs_the_gates():
    (step,) = _steps(PIPELINES["pipelines"]["pull-requests"]["**"])
    assert all(cmd in step["script"] for cmd in GATES)


def test_develop_and_tag_pipelines_run_gates_before_the_mirror_step():
    for pipeline, mirror in _mirror_steps():
        first = _steps(pipeline)[0]
        assert all(cmd in first["script"] for cmd in GATES)
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
    script = _script(_steps(PIPELINES["pipelines"]["tags"]["v*"])[1])
    assert 'git rev-parse origin/main)" != "$BITBUCKET_COMMIT"' in script
    assert script.index("rev-parse origin/main") < script.index("git push")


def test_develop_step_only_mirrors_the_latest_develop_commit():
    script = _script(_steps(PIPELINES["pipelines"]["branches"]["develop"])[1])
    assert 'git rev-parse origin/develop)" != "$BITBUCKET_COMMIT"' in script
    assert script.index("rev-parse origin/develop") < script.index("git push")


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
    # matchDepNames covers every datasource: the docker image and setup-python's version
    assert any(r.get("matchDepNames") == ["python"] for r in rules)


def test_htmx_regex_manager_matches_the_vendored_assets_marker():
    (manager,) = RENOVATE["customManagers"]
    assert manager["managerFilePatterns"] == ["/^docs/vendored-assets\\.md$/"]
    # JS named groups (?<x>...) -> Python (?P<x>...)
    pattern = re.compile(manager["matchStrings"][0].replace("(?<", "(?P<"))
    doc = (ROOT / "docs" / "vendored-assets.md").read_text()
    match = pattern.search(doc)
    assert match, "htmx marker line not matched"
    version = match.group("currentValue")
    assert f"htmx.org@{version}/dist/htmx.min.js" in doc
    assert f'version:"{version}"' in (ROOT / "src/fleet/static/htmx.min.js").read_text()
