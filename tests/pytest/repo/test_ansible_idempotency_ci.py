"""FLE-301: ci/ansible-idempotency.sh fails unless the second playbook run reports changed=0 (fake container engine)."""

import os
import subprocess
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[3] / "ci" / "ansible-idempotency.sh"

FAKE_DOCKER = r"""#!/usr/bin/env bash
# Fake engine: records calls; `exec ... ansible-playbook` (not --syntax-check) prints the next canned recap.
echo "$*" >> "$FAKE_LOG"
case "$1" in
  exec)
    if [[ "$*" == *--syntax-check* ]]; then exit 0; fi
    if [[ "$*" == *ansible-playbook* ]]; then
      n=$(cat "$FAKE_STATE/n" 2>/dev/null || echo 0); n=$((n + 1)); echo "$n" > "$FAKE_STATE/n"
      cat "$FAKE_STATE/run$n.txt"; exit "$(cat "$FAKE_STATE/rc$n" 2>/dev/null || echo 0)"
    fi
    if [[ "$*" == *is-system-running* ]]; then echo running; fi
    ;;
esac
exit 0
"""


def _run(tmp_path, run1, run2, rc1=0, rc2=0, with_engine=True, extra=()):
    bin_dir = tmp_path / "bin"
    state = tmp_path / "state"
    bin_dir.mkdir()
    state.mkdir()
    if with_engine:
        fake = bin_dir / "docker"
        fake.write_text(FAKE_DOCKER)
        fake.chmod(0o755)
    (state / "run1.txt").write_text(run1)
    (state / "run2.txt").write_text(run2)
    (state / "rc1").write_text(str(rc1))
    (state / "rc2").write_text(str(rc2))
    env = {
        "PATH": f"{bin_dir}:/usr/bin:/bin",
        "FAKE_LOG": str(tmp_path / "calls.log"),
        "FAKE_STATE": str(state),
        "HOME": str(tmp_path),
    }
    return subprocess.run(
        ["bash", str(SCRIPT), "--report-dir", str(tmp_path / "report"), *extra],
        capture_output=True, text=True, env=env,
    )


def _recap(changed, failed=0):
    return f"PLAY RECAP ****\nlocalhost                  : ok=40   changed={changed}    unreachable=0    failed={failed}    skipped=3    rescued=0    ignored=0\n"


def test_passes_when_the_second_run_changes_nothing(tmp_path):
    r = _run(tmp_path, _recap(30), _recap(0))
    assert r.returncode == 0, r.stdout + r.stderr
    assert "PASS: the second run reports changed=0" in r.stdout
    calls = (tmp_path / "calls.log").read_text()
    assert "--skip-tags docker,ddev,app_code,units,hardening" in calls
    assert "fleet_ref=ci-container-test" in calls
    assert (tmp_path / "report" / "ansible-idempotency.txt").exists()


def test_fails_and_names_the_task_when_the_second_run_changes_something(tmp_path):
    run2 = "TASK [caddy : Write the thing] ****\nchanged: [localhost]\n" + _recap(1)
    r = _run(tmp_path, _recap(30), run2)
    assert r.returncode == 1
    assert "second run changed 1 task" in r.stdout
    assert "caddy : Write the thing" in r.stdout


def test_fails_when_a_run_reports_a_failure(tmp_path):
    r = _run(tmp_path, _recap(3, failed=1), _recap(0), rc1=2)
    assert r.returncode == 1
    assert "first run did not finish cleanly" in r.stdout


def test_fails_when_another_host_changed_something_on_the_second_run(tmp_path):
    other = "PLAY RECAP ****\n" + _recap(0).split("\n")[1] + "\nweb2                      : ok=5    changed=2    unreachable=0    failed=0    skipped=0    rescued=0    ignored=0\n"
    r = _run(tmp_path, _recap(30), other)
    assert r.returncode == 1
    assert "second run changed 2 task" in r.stdout


def test_fails_when_another_host_is_unreachable(tmp_path):
    bad = _recap(0) + "web2                      : ok=0    changed=0    unreachable=1    failed=0    skipped=0    rescued=0    ignored=0\n"
    r = _run(tmp_path, _recap(30), bad)
    assert r.returncode == 1
    assert "second run did not finish cleanly" in r.stdout


def test_fails_when_there_is_no_recap(tmp_path):
    r = _run(tmp_path, "boom\n", _recap(0))
    assert r.returncode == 1


def test_exit_3_without_a_container_engine(tmp_path):
    r = _run(tmp_path, "", "", with_engine=False)
    assert r.returncode == 3
    assert "no container engine" in r.stderr


def test_dry_run_prints_the_plan_and_runs_nothing(tmp_path):
    r = _run(tmp_path, "", "", extra=("--dry-run",))
    assert r.returncode == 0
    assert "docker,ddev,app_code,units,hardening" in r.stdout
    assert not (tmp_path / "calls.log").exists()
