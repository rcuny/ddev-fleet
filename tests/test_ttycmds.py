from pathlib import Path

from fleet.core import instances
from fleet.core.registry import Registry
from fleet.core.secrets import write_secret
from fleet.core.ttycmds import TtyPlan, plan_for_instance, plan_from_template

REGISTRY_YAML = """\
fleet:
  domain: fleet.example.test

projects:
  oak:
    git: git@example.test:org/oak.git
    issue_id_regexp: "(?i)OAKS-[0-9]+"
    templates:
      jira-pull:
        post_deploy:
          - echo hi
        tty1:
          - ddev exec claude "/jira pull [[issue-id]] --create-branch"
          - ddev drush uli
        tty2:
          - ddev drush watchdog:tail
      no-tty:
        post_deploy:
          - echo hi
      with-secret:
        tty1:
          - echo [[slack-token]]
  other:
    git: git@example.test:org/other.git
    templates:
      jira-pull:
        tty1:
          - ddev exec claude "/jira pull [[issue-id]] --create-branch"
"""


def _write(path: Path, text: str) -> Path:
    path.write_text(text, encoding="utf-8")
    return path


def _registry(fleet_home) -> Registry:
    path = _write(fleet_home / "config" / "fleet.yml", REGISTRY_YAML)
    return Registry.load(path)


def _paths(fleet_home):
    return instances.FleetPaths.from_home(fleet_home)


# --- plan_from_template ------------------------------------------------


def test_plan_from_template_full_happy_path(fleet_home):
    # NOTE: an instance *label* must satisfy naming.validate_part (lowercase
    # letters/digits/dashes only — see naming.py's `_PART_RE`), so a literal
    # "OAKS-1781" label (as the design doc's own worked-example table uses)
    # is rejected by registry.resolve()'s instance_id() check before it ever
    # reaches token substitution. Using a lowercase label keeps this test
    # realistic while still exercising the "label wins" priority path end to
    # end; extract_issue_id matches case-insensitively and uppercases the
    # result, so the recovered id is "OAKS-1781" even though the label is
    # lowercase (and even without the pattern's own inline `(?i)`).
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)

    plan = plan_from_template(registry, paths, "oak", "oaks-1781", "dev", "jira-pull")

    assert plan.tty1 == [
        'ddev exec claude "/jira pull OAKS-1781 --create-branch"',
        "ddev drush uli",
    ]
    assert plan.tty2 == ["ddev drush watchdog:tail"]
    assert plan.skipped == []
    assert plan.is_empty is False


def test_plan_from_template_issue_id_recovered_from_branch(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)

    plan = plan_from_template(
        registry,
        paths,
        "oak",
        "test2",
        "feature/OAKS-1781-seo-geo-improvements",
        "jira-pull",
    )

    assert plan.tty1[0] == 'ddev exec claude "/jira pull OAKS-1781 --create-branch"'
    assert plan.skipped == []


def test_plan_from_template_no_match_anywhere_drops_only_the_token_command(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)

    plan = plan_from_template(registry, paths, "oak", "test2", "develop", "jira-pull")

    # The plain command (no [[issue-id]]) still comes through, in order.
    assert plan.tty1 == ["ddev drush uli"]
    assert plan.tty2 == ["ddev drush watchdog:tail"]
    assert len(plan.skipped) == 1
    assert plan.skipped[0] == (
        "skipped tty1 (unresolved [[issue-id]]): "
        'ddev exec claude "/jira pull [[issue-id]] --create-branch"'
    )


def test_plan_from_template_no_issue_id_regexp_behaves_like_no_match(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)

    plan = plan_from_template(registry, paths, "other", "oaks-1781", "dev", "jira-pull")

    assert plan.tty1 == []
    assert len(plan.skipped) == 1
    assert "unresolved [[issue-id]]" in plan.skipped[0]


def test_plan_from_template_secret_token_resolves(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)
    write_secret(paths.project_secrets / "oak.env", "SLACK_TOKEN", "xoxb-super-secret")

    plan = plan_from_template(registry, paths, "oak", "oaks-1781", "dev", "with-secret")

    assert plan.tty1 == ["echo xoxb-super-secret"]
    assert plan.skipped == []


def test_plan_from_template_no_tty_keys_is_empty(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)

    plan = plan_from_template(registry, paths, "oak", "oaks-1781", "dev", "no-tty")

    assert plan == TtyPlan(tty1=[], tty2=[], skipped=[])
    assert plan.is_empty is True


# --- plan_for_instance ---------------------------------------------------


def _write_instance_yaml(paths, instance_id: str, text: str) -> None:
    fleet_dir = paths.instances / instance_id / ".fleet"
    fleet_dir.mkdir(parents=True, exist_ok=True)
    (fleet_dir / "instance.yml").write_text(text, encoding="utf-8")


def test_plan_for_instance_happy_path(fleet_home):
    # instance:/label follows the same lowercase naming constraint noted in
    # test_plan_from_template_full_happy_path above, and the recovered id is
    # uppercased the same way.
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)
    _write_instance_yaml(
        paths,
        "oak--oaks-1781",
        """\
project: oak
instance: oaks-1781
branch: dev
template: jira-pull
""",
    )

    plan = plan_for_instance(registry, paths, "oak--oaks-1781")

    assert plan.tty1 == [
        'ddev exec claude "/jira pull OAKS-1781 --create-branch"',
        "ddev drush uli",
    ]
    assert plan.tty2 == ["ddev drush watchdog:tail"]
    assert plan.skipped == []


def test_plan_for_instance_missing_instance_directory_is_empty(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)

    plan = plan_for_instance(registry, paths, "oak--does-not-exist")

    assert plan == TtyPlan.empty()


def test_plan_for_instance_missing_instance_yaml_is_empty(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)
    (paths.instances / "oak--OAKS-1781" / ".fleet").mkdir(parents=True)

    plan = plan_for_instance(registry, paths, "oak--OAKS-1781")

    assert plan.is_empty is True


def test_plan_for_instance_without_template_key_is_empty_no_exception(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)
    _write_instance_yaml(
        paths,
        "oak--OAKS-1781",
        """\
project: oak
instance: OAKS-1781
branch: dev
""",
    )

    plan = plan_for_instance(registry, paths, "oak--OAKS-1781")

    assert plan == TtyPlan.empty()


def test_plan_for_instance_recorded_template_removed_from_registry_is_empty(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)
    _write_instance_yaml(
        paths,
        "oak--OAKS-1781",
        """\
project: oak
instance: OAKS-1781
branch: dev
template: this-template-no-longer-exists
""",
    )

    plan = plan_for_instance(registry, paths, "oak--OAKS-1781")

    assert plan == TtyPlan.empty()


def test_plan_for_instance_recorded_project_removed_from_registry_is_empty(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)
    _write_instance_yaml(
        paths,
        "ghost--OAKS-1781",
        """\
project: ghost
instance: OAKS-1781
branch: dev
template: jira-pull
""",
    )

    plan = plan_for_instance(registry, paths, "ghost--OAKS-1781")

    assert plan == TtyPlan.empty()


def test_plan_for_instance_malformed_yaml_is_empty_no_exception(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)
    _write_instance_yaml(paths, "oak--OAKS-1781", "foo: [1, 2\n  bar: unterminated")

    plan = plan_for_instance(registry, paths, "oak--OAKS-1781")

    assert plan == TtyPlan.empty()


def test_plan_for_instance_scalar_yaml_is_empty_no_exception(fleet_home):
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)
    _write_instance_yaml(paths, "oak--OAKS-1781", "just a plain string, not a mapping\n")

    plan = plan_for_instance(registry, paths, "oak--OAKS-1781")

    assert plan == TtyPlan.empty()
