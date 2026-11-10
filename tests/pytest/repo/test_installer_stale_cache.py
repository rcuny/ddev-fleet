"""FLE-281: step 80 removes the stale prod container cache first; apt-get update is retried."""

import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3] / "install"


def _bash(script: str, tmp: Path) -> subprocess.CompletedProcess:
    return subprocess.run(["bash", "-c", script], capture_output=True, text=True, cwd=tmp)


def test_step_80_removes_the_stale_prod_cache_before_any_console_command(tmp_path):
    cache = tmp_path / "app" / "var" / "cache" / "prod" / "ContainerOld"
    cache.mkdir(parents=True)
    (cache / "App_KernelProdContainer.php").write_text("<?php // stale")
    keep = tmp_path / "app" / "var" / "cache" / "dev"
    keep.mkdir()
    script = f"""
set -e
run_as_fleet() {{ "$@"; }}
FLEET_SRC_DIR={tmp_path}
source {ROOT}/steps/80-code.sh
clear_stale_prod_cache
"""
    result = _bash(script, tmp_path)
    assert result.returncode == 0, result.stderr
    assert not (tmp_path / "app/var/cache/prod").exists()
    assert keep.exists()


def test_step_80_clears_the_cache_before_composer_and_console(tmp_path):
    body = (ROOT / "steps" / "80-code.sh").read_text().split("step_main() {", 1)[1]
    assert body.index("clear_stale_prod_cache") < body.index("fleet_app_run")
    assert body.index("clear_stale_prod_cache") < body.index("fleet_console")


def test_apt_update_is_retried_then_succeeds(tmp_path):
    counter = tmp_path / "n"
    script = f"""
set -e
log_warn() {{ :; }}
apt_get() {{ n=$(cat {counter} 2>/dev/null || echo 0); echo $((n+1)) > {counter}; [ $((n+1)) -ge 3 ]; }}
source <(sed -n '/^apt_update_retry() {{/,/^}}/p' {ROOT}/lib/common.sh)
FLEET_APT_RETRY_DELAY=0 apt_update_retry
"""
    result = _bash(script, tmp_path)
    assert result.returncode == 0, result.stderr
    assert counter.read_text().strip() == "3"


def test_apt_update_gives_up_after_the_attempts(tmp_path):
    script = f"""
log_warn() {{ :; }}
apt_get() {{ return 1; }}
source <(sed -n '/^apt_update_retry() {{/,/^}}/p' {ROOT}/lib/common.sh)
FLEET_APT_ATTEMPTS=2 FLEET_APT_RETRY_DELAY=0 apt_update_retry
"""
    assert _bash(script, tmp_path).returncode == 1
