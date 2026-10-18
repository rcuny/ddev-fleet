"""FLE-31: `schedules:` in fleet.yml -> `Registry.schedules` and its validation."""

from pathlib import Path

import pytest

from fleet.core.errors import RegistryError
from fleet.core.registry import Registry, Schedule

_HEAD = """\
fleet:
  domain: fleet.example.test
projects:
  demo:
    git: git@example.test:org/demo.git
"""


def _load(tmp_path: Path, schedules_yaml: str, *, host_domain: str | None = None) -> Registry:
    path = tmp_path / "fleet.yml"
    path.write_text(_HEAD + schedules_yaml, encoding="utf-8")
    host = None
    if host_domain:
        host = tmp_path / "host.yml"
        host.write_text(f"domain: {host_domain}\n", encoding="utf-8")
    return Registry.load(path, host_config_path=host)


_OK = """\
schedules:
  pw-baseline-nightly:
    cron: "0 3 * * *"
    timezone: Europe/Paris
    action: redeploy
    instance: osce--pw-regression
"""


def test_no_schedules_key_is_fine(tmp_path):
    assert _load(tmp_path, "").schedules == []
    assert _load(tmp_path, "schedules:\n").schedules == []
    assert _load(tmp_path, "schedules: {}\n").schedules == []


def test_valid_schedule_defaults(tmp_path):
    (sched,) = _load(tmp_path, _OK).schedules
    assert sched == Schedule(
        name="pw-baseline-nightly",
        cron="0 3 * * *",
        timezone="Europe/Paris",
        action="redeploy",
        instance="osce--pw-regression",
        servers=(),
        enabled=True,
    )


def test_schedule_is_frozen(tmp_path):
    (sched,) = _load(tmp_path, _OK).schedules
    with pytest.raises(AttributeError):
        sched.cron = "* * * * *"  # type: ignore[misc]


def test_servers_enabled_and_file_order(tmp_path):
    reg = _load(
        tmp_path,
        _OK + """\
  zz-second:
    cron: "*/10   * * * *"
    timezone: UTC
    action: redeploy
    instance: demo--main
    servers: [ddev3.fleet.pm, ddev4.fleet.pm]
    enabled: false
""",
    )
    assert [s.name for s in reg.schedules] == ["pw-baseline-nightly", "zz-second"]
    second = reg.schedules[1]
    assert second.servers == ("ddev3.fleet.pm", "ddev4.fleet.pm")
    assert second.enabled is False
    assert second.cron == "*/10 * * * *"  # whitespace normalised


def test_schedules_do_not_depend_on_host_domain(tmp_path):
    reg = _load(tmp_path, _OK, host_domain="ddev3.fleet.pm")
    assert reg.domain == "ddev3.fleet.pm"
    assert len(reg.schedules) == 1


@pytest.mark.parametrize(
    "body, fragment",
    [
        ("schedules: [a]\n", "schedules: must be a mapping"),
        ("schedules:\n  Bad_Name:\n    cron: '* * * * *'\n", "schedules.Bad_Name"),
        ("schedules:\n  -lead:\n    cron: '* * * * *'\n", "invalid schedule name"),
        ("schedules:\n  " + "a" * 64 + ":\n    cron: '* * * * *'\n", "invalid schedule name"),
        ("schedules:\n  nightly: 5\n", "schedules.nightly: must be a mapping"),
        (
            "schedules:\n  nightly:\n    timezone: UTC\n    action: redeploy\n    instance: a\n",
            "schedules.nightly.cron: missing",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    action: redeploy\n"
            "    instance: a\n",
            "schedules.nightly.timezone: missing",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    timezone: UTC\n    instance: a\n",
            "schedules.nightly.action: missing",
        ),
        (
            "schedules:\n  nightly:\n    cron: '61 * * * *'\n    timezone: UTC\n"
            "    action: redeploy\n    instance: a\n",
            "schedules.nightly.cron:",
        ),
        (
            "schedules:\n  nightly:\n    cron: [1]\n    timezone: UTC\n"
            "    action: redeploy\n    instance: a\n",
            "schedules.nightly.cron:",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    timezone: Mars/Base\n"
            "    action: redeploy\n    instance: a\n",
            "schedules.nightly.timezone: unknown timezone",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    timezone: UTC\n"
            "    action: explode\n    instance: a\n",
            "schedules.nightly.action: unknown action 'explode'",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    timezone: UTC\n"
            "    action: redeploy\n",
            "schedules.nightly.instance: required for action 'redeploy'",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    timezone: UTC\n"
            "    action: redeploy\n    instance: Not/An_Id\n",
            "schedules.nightly.instance:",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    timezone: UTC\n"
            "    action: redeploy\n    instance: a\n    surprise: 1\n",
            "schedules.nightly: unknown key(s) ['surprise']",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    timezone: UTC\n"
            "    action: redeploy\n    instance: a\n    servers: []\n",
            "schedules.nightly.servers:",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    timezone: UTC\n"
            "    action: redeploy\n    instance: a\n    servers: ddev3.fleet.pm\n",
            "schedules.nightly.servers:",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    timezone: UTC\n"
            "    action: redeploy\n    instance: a\n    servers: ['https://x/']\n",
            "schedules.nightly.servers:",
        ),
        (
            "schedules:\n  nightly:\n    cron: '* * * * *'\n    timezone: UTC\n"
            "    action: redeploy\n    instance: a\n    enabled: 'yes'\n",
            "schedules.nightly.enabled: must be true or false",
        ),
    ],
)
def test_validation_errors_name_the_schedule(tmp_path, body, fragment):
    with pytest.raises(RegistryError) as exc:
        _load(tmp_path, body)
    assert fragment in exc.value.message
