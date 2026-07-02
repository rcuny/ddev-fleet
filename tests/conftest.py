"""Shared pytest fixtures for the ddev-fleet test suite.

Fixtures are added incrementally as later tasks need them:
task 6 adds ``FakeRunner``/``git_repo``.
"""

import pytest

SAMPLE_REGISTRY_YAML = """\
fleet:
  domain: fleet.example.test   # Wildcard DNS root
  assets_path: {assets_path}
  instances_path: {instances_path}

projects:
  demo:
    git: git@example.test:org/demo.git
    post_deploy:
      - echo project-default
    instances:
      develop:
        branch: main
      custom:
        branch: feature-x
        post_deploy:
          - echo instance-override
"""


@pytest.fixture
def fleet_home(tmp_path):
    home = tmp_path / "fleet-home"
    (home / "assets").mkdir(parents=True)
    (home / "instances").mkdir(parents=True)
    (home / "locks").mkdir(parents=True)
    return home


@pytest.fixture
def sample_registry_text(fleet_home):
    return SAMPLE_REGISTRY_YAML.format(
        assets_path=str(fleet_home / "assets"),
        instances_path=str(fleet_home / "instances"),
    )
