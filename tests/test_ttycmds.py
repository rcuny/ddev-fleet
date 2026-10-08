from pathlib import Path

from fleet.core import instances, pgp
from fleet.core.registry import Registry
from fleet.core.secrets import write_secret
from fleet.core.secretstore import SecretStore
from fleet.core.ttycmds import TtyPlan, plan_from_resolved

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
          exec:
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
        post_deploy:
          tty1:
            - echo [[slack-token]]
  other:
    git: git@example.test:org/other.git
    templates:
      jira-pull:
        # deprecated template-level spelling must keep working
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


def plan_from_template(registry, paths, project, label, branch, template):
    """Resolve the template exactly as deploy() does, then plan it."""
    resolved = registry.resolve(project, template, branch, label=label)
    return plan_from_resolved(registry, paths, resolved)


# --- plan_from_resolved --------------------------------------------------


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


def test_plan_uses_the_resolved_object_not_the_live_registry(fleet_home):
    """FLE-6: deploy-time resolution wins — editing fleet.yml afterwards
    (here: a registry whose template changed) cannot alter an already
    resolved instance's plan."""
    registry = _registry(fleet_home)
    paths = _paths(fleet_home)
    resolved = registry.resolve("oak", "jira-pull", "dev", label="oaks-1781")
    resolved.tty1 = ["echo deploy-time"]
    resolved.tty2 = []

    plan = plan_from_resolved(registry, paths, resolved)

    assert plan.tty1 == ["echo deploy-time"]
    assert plan.tty2 == []


def test_plan_from_template_resolves_an_encrypted_secret(gpg_fleet_home):
    registry = _registry(gpg_fleet_home)
    paths = _paths(gpg_fleet_home)
    pgp.init_host_key(paths.gnupg, "ddev-fleet test host")
    SecretStore(paths).set("oak", "SLACK_TOKEN", "xoxb-encrypted")

    plan = plan_from_template(registry, paths, "oak", "oaks-1781", "dev", "with-secret")

    assert plan.tty1 == ["echo xoxb-encrypted"]
    assert plan.skipped == []
