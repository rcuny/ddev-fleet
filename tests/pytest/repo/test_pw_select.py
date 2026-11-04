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
    r = pw.select(["docs/README-ci.md", "CHANGELOG.md", "ci/run-gates.sh"], SPECS)
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
