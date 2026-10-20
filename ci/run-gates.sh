#!/usr/bin/env bash
# The gates of the product in one script (FLE-120), used by dispatched agents, the orchestrator and Fleet CI,
# so the three can never drift. The source of truth for the gate list is bitbucket-pipelines.yml (steps Gates,
# Infra tests, Ansible lint); keep both in step.
#
#   ci/run-gates.sh [--quick|--full] [--report-dir DIR]
#
#   --quick   composer install, `composer run gates` (assets, cs, stan, lint, phpunit) and `composer run prod-check`
#   --full    quick + Playwright (npm ci, browser install, typecheck, the whole suite) + infra tests (pytest,
#             systemd security score) + ansible-lint (non-blocking)      [default]
#
# Environment: FLEET_CI_WORKERS (Playwright --workers, default unset), FLEET_PHP (PHP binary, default php8.4/php).
# The report directory (default var/ci-report/, git-ignored) gets <gate>.log per gate, gates.tsv, summary.json,
# summary.txt and, in full mode, playwright-report/. Exit 0 only if every blocking gate passed.
# Run from anywhere; needs network (Composer, npm, pip). Works in the companion container and in a DDEV web container.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mode=full
report_dir="$root/var/ci-report"

usage() {
    sed -n '2,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
    case "$1" in
        --quick) mode=quick ;;
        --full) mode=full ;;
        --report-dir)
            [ $# -ge 2 ] || { echo "run-gates: --report-dir needs a directory" >&2; exit 2; }
            report_dir="$2"
            shift
            ;;
        --report-dir=*) report_dir="${1#--report-dir=}" ;;
        -h | --help) usage; exit 0 ;;
        *) echo "run-gates: unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

mkdir -p "$report_dir"
report_dir="$(cd "$report_dir" && pwd)"
rm -rf "$report_dir/playwright-report"
rm -f "$report_dir"/*.log "$report_dir/gates.tsv" "$report_dir/summary.json" "$report_dir/summary.txt"
: > "$report_dir/gates.tsv"

php="${FLEET_PHP:-$(command -v php8.4 || command -v php)}"
composer="$(command -v composer)"
export COMPOSER_NO_INTERACTION=1

# run_gate NAME BLOCKING(1|0) CMD...   runs CMD with its output in <report>/NAME.log, records the result.
# Returns the status of CMD, but never aborts the script: every independent gate still runs.
run_gate() {
    local name="$1" blocking="$2" start status=passed rc=0
    shift 2
    start=$SECONDS
    printf '==> %s\n' "$name"
    "$@" > "$report_dir/$name.log" 2>&1 || rc=$?
    [ "$rc" -eq 0 ] || status=failed
    printf '%s\t%s\t%s\t%s\t\n' "$name" "$status" "$((SECONDS - start))" "$blocking" >> "$report_dir/gates.tsv"
    [ "$status" = passed ] || { printf '    FAILED, last lines of %s.log:\n' "$name"; tail -n 15 "$report_dir/$name.log" | sed 's/^/    /'; }
    return "$rc"
}

# skip_gate NAME BLOCKING NOTE
skip_gate() {
    printf '==> %s skipped: %s\n' "$1" "$3"
    printf '%s\tskipped\t0\t%s\t%s\n' "$1" "$2" "$3" >> "$report_dir/gates.tsv"
}

app() { (cd "$root/app" && "$@"); }

# Stale Symfony caches give false Playwright and PHPUnit reds after a branch switch.
rm -rf "$root/app/var/cache/dev" "$root/app/var/cache/test"

if run_gate composer-install 1 app "$php" "$composer" install --no-interaction --prefer-dist; then
    run_gate composer-gates 1 app "$php" "$composer" run gates || true
    scripts="$(app "$php" "$composer" run-script --list 2>/dev/null || true)"
    if grep -q '^ *prod-check\b' <<< "$scripts"; then
        run_gate prod-check 1 app "$php" "$composer" run prod-check || true
    else
        skip_gate prod-check 1 "no prod-check script in app/composer.json"
    fi
else
    skip_gate composer-gates 1 "composer install failed"
    skip_gate prod-check 1 "composer install failed"
fi

if [ "$mode" = full ]; then
    pw="$root/tests/playwright"
    in_pw() { (cd "$pw" && "$@"); }
    pw_workers=()
    [ -z "${FLEET_CI_WORKERS:-}" ] || pw_workers=(--workers="$FLEET_CI_WORKERS")

    if run_gate playwright-install 1 in_pw bash -c 'npm ci && npx playwright install --only-shell chromium'; then
        run_gate playwright-typecheck 1 in_pw npm run typecheck || true
        # The merge gate runs with FLEET_E2E_BASE_URL unset: Playwright starts its own throw-away server.
        run_gate playwright 1 in_pw env -u FLEET_E2E_BASE_URL npx playwright test "${pw_workers[@]}" || true
        [ ! -d "$pw/playwright-report" ] || cp -R "$pw/playwright-report" "$report_dir/playwright-report"
    else
        skip_gate playwright-typecheck 1 "playwright install failed"
        skip_gate playwright 1 "playwright install failed"
    fi

    venv="$root/.venv"
    infra_ok=0
    if run_gate infra-install 1 bash -c "python3 -m venv '$venv' && '$venv/bin/pip' install -q -r '$root/ci/requirements-dev.txt'"; then
        infra_ok=1
    fi
    if [ "$infra_ok" -eq 1 ]; then
        # Like the pipeline: a missing systemd-analyze fails the scoring tests instead of skipping them.
        # Only demanded when the binary exists here, so a container without systemd still runs the rest.
        require_systemd=0
        command -v systemd-analyze > /dev/null 2>&1 && require_systemd=1
        run_gate pytest 1 env FLEET_REQUIRE_SYSTEMD_ANALYZE="$require_systemd" "$venv/bin/pytest" -q "$root/tests/pytest" || true
        if [ "$require_systemd" -eq 1 ]; then
            run_gate systemd-security 1 bash -c "'$venv/bin/python' '$root/ci/systemd_security.py' offline --out '$report_dir/systemd-offline.json' && '$venv/bin/python' '$root/ci/systemd_security.py' compare --env offline --current '$report_dir/systemd-offline.json'" || true
        else
            skip_gate systemd-security 1 "systemd-analyze not installed"
        fi
    else
        skip_gate pytest 1 "infra install failed"
        skip_gate systemd-security 1 "infra install failed"
    fi

    # Non-blocking, like the pipeline: ansible-core >= 2.21 needs Python >= 3.12.
    if python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 12) else 1)'; then
        lint_venv="$root/.venv-lint"
        run_gate ansible-lint 0 bash -c "python3 -m venv '$lint_venv' && '$lint_venv/bin/pip' install -q -r '$root/ci/requirements-infra.txt' && cd '$root' && '$lint_venv/bin/ansible-lint' ansible/ && '$lint_venv/bin/yamllint' ansible/" || true
    else
        skip_gate ansible-lint 0 "Python $(python3 -c 'import sys; print("%d.%d" % sys.version_info[:2])') is older than 3.12"
    fi
fi

echo
python3 "$root/ci/gates_summary.py" build "$report_dir"
