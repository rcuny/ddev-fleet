"""FLE-172: ci/pw_select.py picks the phone decision and the targeted coverage specs from the changed files."""

import importlib.util
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location("pw_select", ROOT / "ci" / "pw_select.py")
pw = importlib.util.module_from_spec(SPEC)
sys.modules["pw_select"] = pw
SPEC.loader.exec_module(pw)

SPECS = {
    "assets-coverage.spec.ts": "assets",
    "schedules-coverage.spec.ts": "schedules",
    "setup-wizard-coverage.spec.ts": "setup-wizard",
    "projects-validation.spec.ts": "projects",
    "templates-validation.spec.ts": "templates",
    "users-coverage.spec.ts": "users",
}


def test_docs_only_change_runs_neither_phone_nor_coverage():
    r = pw.select(["docs/contributing/03-ci.md", "CHANGELOG.md", "ci/run-gates.sh"], SPECS)
    assert r["phone"] == "off"
    assert r["targeted"] == []


def test_backend_change_targets_its_area_only():
    r = pw.select(["app/src/Controller/Admin/SchedulesController.php"], SPECS)
    assert r["phone"] == "off"
    assert r["targeted"] == ["schedules-coverage.spec.ts"]


def test_camel_case_and_kebab_case_names_meet():
    r = pw.select(["app/src/Setup/SetupWizardState.php"], SPECS)
    assert r["targeted"] == ["setup-wizard-coverage.spec.ts"]


def test_templates_directory_does_not_match_the_templates_area():
    r = pw.select(["app/templates/admin/secrets/index.html.twig"], SPECS)
    assert r["phone"] == "on"
    assert "templates-validation.spec.ts" not in r["targeted"]


def test_area_template_directory_matches_its_spec():
    r = pw.select(["app/templates/admin/users/edit.html.twig"], SPECS)
    assert r["targeted"] == ["users-coverage.spec.ts"]


def test_a_changed_spec_of_the_area_pulls_in_its_coverage_spec():
    r = pw.select(["tests/playwright/assets.spec.ts"], SPECS)
    assert r["targeted"] == ["assets-coverage.spec.ts"]
    assert r["phone"] == "off"


def test_ui_paths_turn_the_phone_project_on():
    for path in (
        "app/templates/base.html.twig",
        "app/assets/controllers/foo_controller.js",
        "app/src/Twig/Components/MainNav.php",
        "app/src/Form/Project/ProjectType.php",
        "tests/playwright/nav.phone.spec.ts",
        "tests/playwright/playwright.config.ts",
        "tests/playwright/support/env.ts",
    ):
        assert pw.select([path], SPECS)["phone"] == "on", path


def test_shared_code_runs_every_spec():
    for path in ("app/composer.lock", "app/migrations/Version2026.php", "app/config/services.yaml", "app/templates/base.html.twig"):
        assert pw.select([path], SPECS)["targeted"] == sorted(SPECS), path


def test_unknown_diff_is_the_safe_answer():
    r = pw.select(None, SPECS)
    assert r["phone"] == "on"
    assert r["targeted"] == sorted(SPECS)


def test_specs_in_reads_the_real_directory():
    specs = pw.specs_in(ROOT / "tests" / "playwright")
    assert specs["schedules-coverage.spec.ts"] == "schedules"
    assert specs["projects-validation.spec.ts"] == "projects"
    assert "schedules.spec.ts" not in specs


def test_cli_env_format_with_files():
    out = subprocess.run(
        [sys.executable, str(ROOT / "ci" / "pw_select.py"), "--files", "app/src/Fleet/Secrets/SecretBox.php"],
        check=True, capture_output=True, text=True,
    ).stdout
    assert "FLEET_PW_PHONE=off" in out
    assert "FLEET_PW_TARGETED=secrets-coverage.spec.ts" in out


def test_slowest_tests_orders_by_total_duration():
    spec = importlib.util.spec_from_file_location("slowest_tests", ROOT / "ci" / "slowest_tests.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    report = {"suites": [{"file": "a.spec.ts", "specs": [
        {"title": "fast", "tests": [{"projectName": "desktop", "results": [{"duration": 100}]}]},
        {"title": "slow", "tests": [{"projectName": "phone", "results": [{"duration": 4000}, {"duration": 1000}]}]},
    ]}]}
    rows = mod.slowest(report, 1)
    assert rows[0][3] == "slow" and rows[0][0] == 5.0


def test_phone_tag_in_a_non_phone_spec_turns_phone_on():
    r = pw.select(["tests/playwright/projects.spec.ts"], SPECS, ["tests/playwright/projects.spec.ts"])
    assert r["phone"] == "on"
    assert "@phone" not in r["phone_reason"] or "projects.spec.ts" in r["phone_reason"]


def test_phone_tag_detected_in_diff_hunks(tmp_path):
    def git(*a):
        subprocess.run(["git", "-C", str(tmp_path), "-c", "user.name=t", "-c", "user.email=t@example.com", *a], check=True, capture_output=True)

    (tmp_path / "tests" / "playwright").mkdir(parents=True)
    spec = tmp_path / "tests" / "playwright" / "x.spec.ts"
    spec.write_text("test('a', async () => {});\n")
    git("init", "-q", "-b", "main")
    git("add", ".")
    git("commit", "-qm", "base")
    spec.write_text("test('a @phone', async () => {});\n")
    git("commit", "-qam", "tag")
    assert pw.phone_tag_files(tmp_path, "HEAD~1", "HEAD") == ["tests/playwright/x.spec.ts"]


def _select_only(env_extra):
    import os

    env = {**os.environ, **env_extra}
    out = subprocess.run(
        [str(ROOT / "ci" / "run-gates.sh"), "--full", "--select-only"], check=True, capture_output=True, text=True, env=env
    ).stdout
    return dict(line.split("=", 1) for line in out.splitlines() if "=" in line)


def test_run_gates_falls_back_to_phone_and_every_spec_when_selection_fails():
    got = _select_only({"FLEET_PW_TIER": "quick", "FLEET_CI_PW_SELECT": "/bin/false"})
    assert got["FLEET_PW_PHONE"] == "on"
    specs = set(got["FLEET_PW_TARGETED"].split())
    assert specs == set(pw.specs_in(ROOT / "tests" / "playwright"))
    assert "selection unavailable" in got["NOTE"]
