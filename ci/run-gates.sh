#!/usr/bin/env bash
# The gates of the product in one script (FLE-120), used by dispatched agents, the orchestrator and Fleet CI,
# so the three can never drift. The source of truth for the gate list is bitbucket-pipelines.yml (steps Gates,
# Infra tests, Ansible lint); keep both in step.
#
#   ci/run-gates.sh [--quick|--full] [--report-dir DIR] [--select-only]
#
#   --select-only  print the Playwright selection (phone, targeted specs) and exit; used by the tests
#
#   --quick   composer install, `composer run gates` (assets, cs, stan, lint, phpunit) and `composer run prod-check`
#   --full    quick + Playwright (npm ci, browser install, typecheck, the whole suite) + infra tests (pytest,
#             systemd security score) + ansible-lint (non-blocking)      [default]
#
# FLE-172: optional Playwright parts are chosen from the diff (ci/pw_select.py). The phone project runs only on a UI
# change, and the quick tier adds back the *-coverage / *-validation specs whose area the diff touches. The choice is
# written to <report>/selection.txt and into the playwright row of gates.tsv.
# FLEET_PW_PHONE=on|off|auto (default auto = from the diff; the full tier always runs it unless set to off),
# FLEET_CI_DIFF_BASE (default origin/develop) and FLEET_CI_DIFF_HEAD (default HEAD): the diff is
# merge-base(BASE, HEAD)..HEAD. Fleet CI sets HEAD to the pushed commit, since it merges develop in before the run.
#
# Environment: FLEET_CI_WORKERS (Playwright --workers, default unset), FLEET_PW_TIER (quick|full, read by the Playwright
# config, FLE-126), FLEET_CI_INSTALL_DEPS=1 (install the Chromium system libraries with sudo first; Fleet CI, FLE-128), FLEET_PHP (run composer through this PHP binary,
# default: composer as found on PATH). Every gate runs with a throw-away FLEET_HOME and without APP_ENV.
# The report directory (default var/ci-report/, git-ignored) gets <gate>.log per gate, gates.tsv, summary.json,
# summary.txt and, in full mode, playwright-report/. Exit 0 only if every blocking gate passed.
# Run from anywhere; needs network (Composer, npm, pip). Works in the companion container and in a DDEV web container.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mode=full
select_only=0
report_dir="$root/var/ci-report"

usage() {
    sed -n '2,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
    case "$1" in
        --quick) mode=quick ;;
        --full) mode=full ;;
        --select-only) select_only=1 ;;
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

# Playwright selection (FLE-172), computed before any gate so --select-only and a failing pw_select.py are testable.
if [ "$mode" = full ]; then
    # Optional parts from the diff (FLE-172). Without python3 or a diff the answer is the safe one: everything runs.
    pw_tier="${FLEET_PW_TIER:-full}"
    pw_phone="${FLEET_PW_PHONE:-auto}"
    pw_targeted="${FLEET_PW_TARGETED:-}"
    PW_PHONE_REASON=''
    PW_TARGETED_REASON=''
    sel="$(python3 "${FLEET_CI_PW_SELECT:-$root/ci/pw_select.py}" --repo "$root" --base "${FLEET_CI_DIFF_BASE:-origin/develop}" --head "${FLEET_CI_DIFF_HEAD:-HEAD}" 2> /dev/null || true)"
    if [ -n "$sel" ]; then
        eval "$(grep -E '^(FLEET_PW_PHONE|FLEET_PW_TARGETED|PW_PHONE_REASON|PW_TARGETED_REASON)=' <<< "$sel" | sed -E 's/^FLEET_PW_PHONE=/sel_phone=/; s/^FLEET_PW_TARGETED=/sel_targeted=/')"
        [ "$pw_phone" != auto ] || { [ "$pw_tier" = full ] && pw_phone=on || pw_phone="$sel_phone"; }
        [ -n "$pw_targeted" ] || pw_targeted="$sel_targeted"
    else
        # Unsure means run: phone on and every coverage / validation spec (FLE-172 review).
        [ "$pw_phone" != auto ] || pw_phone=on
        [ -n "$pw_targeted" ] || pw_targeted="$(cd "$root/tests/playwright" && ls -- *-coverage.spec.ts *-validation.spec.ts 2> /dev/null | tr '\n' ' ')"
        PW_PHONE_REASON='selection unavailable'
    fi
    [ "${FLEET_PW_PHONE:-auto}" = auto ] || PW_PHONE_REASON="FLEET_PW_PHONE=${FLEET_PW_PHONE}"
    [ "$pw_tier" = quick ] || pw_targeted=''   # the full tier runs every spec anyway
    if [ "$pw_phone" = off ]; then pw_phone_note='phone: skipped'; else pw_phone_note='phone: ran'; fi
    [ -z "$PW_PHONE_REASON" ] || pw_phone_note="$pw_phone_note ($PW_PHONE_REASON)"
    pw_note="$pw_tier tier; $pw_phone_note"
    if [ "$pw_tier" = quick ]; then
        pw_note="$pw_note; coverage specs: $(wc -w <<< "$pw_targeted" | tr -d ' ') targeted"
    fi
fi
if [ "$select_only" = 1 ]; then
    printf 'FLEET_PW_PHONE=%s\nFLEET_PW_TARGETED=%s\nNOTE=%s\n' "${pw_phone:-}" "${pw_targeted:-}" "${pw_note:-}"
    exit 0
fi

mkdir -p "$report_dir"
report_dir="$(cd "$report_dir" && pwd)"
rm -rf "$report_dir/playwright-report"
rm -f "$report_dir"/*.log "$report_dir/gates.tsv" "$report_dir/summary.json" "$report_dir/summary.txt"
: > "$report_dir/gates.tsv"

# Throw-away state for every gate. The container exports FLEET_HOME=/tmp/sqlite/fleet-home (the served dev app's
# database, which the tests wipe) and APP_ENV=dev; neither may reach the gates. PHPUnit forces APP_ENV=test itself,
# the other gates use the app's own default from .env. Playwright additionally makes its own FLEET_HOME.
gates_home="$(mktemp -d)"
trap 'rm -rf "$gates_home"' EXIT
export FLEET_HOME="$gates_home"
unset APP_ENV
export COMPOSER_NO_INTERACTION=1

# Composer: the executable on PATH (a phar or a wrapper), run through FLEET_PHP only when that is set.
# A missing tool is a failed gate in the summary, not a crash before it.
composer=()
if [ -n "${FLEET_PHP:-}" ]; then
    composer=("$FLEET_PHP" "$(command -v composer || true)")
elif command -v composer > /dev/null 2>&1; then
    composer=(composer)
fi

# run_gate NAME BLOCKING(1|0) CMD...   runs CMD with its output in <report>/NAME.log, records the result.
# Returns the status of CMD, but never aborts the script: every independent gate still runs.
run_gate() {
    local name="$1" blocking="$2" start status=passed rc=0 note="${gate_note:-}"
    shift 2
    start=$SECONDS
    printf '==> %s\n' "$name"
    "$@" > "$report_dir/$name.log" 2>&1 || rc=$?
    [ "$rc" -eq 0 ] || status=failed
    printf '%s\t%s\t%s\t%s\t%s\n' "$name" "$status" "$((SECONDS - start))" "$blocking" "$note" >> "$report_dir/gates.tsv"
    [ "$status" = passed ] || { printf '    FAILED, last lines of %s.log:\n' "$name"; tail -n 15 "$report_dir/$name.log" | sed 's/^/    /'; }
    return "$rc"
}

# skip_gate NAME BLOCKING NOTE
skip_gate() {
    printf '==> %s skipped: %s\n' "$1" "$3"
    printf '%s\tskipped\t0\t%s\t%s\n' "$1" "$2" "$3" >> "$report_dir/gates.tsv"
}

app() { (cd "$root/app" && "$@"); }

if [ "$mode" = full ]; then
    pw="$root/tests/playwright"
    in_pw() { (cd "$pw" && "$@"); }
    venv="$root/.venv"
    # Stamps (FLE-174): a dependency step is skipped when its lock file is unchanged since the last successful run in this
    # tree. The CI instance persists between runs, so node_modules, .venv and the Chromium cache survive.
    stamp_ok() { [ "$(cat "$1" 2> /dev/null || true)" = "$(sha256sum "$2" | cut -d' ' -f1)$3" ]; }
    stamp_set() { sha256sum "$2" | cut -d' ' -f1 | tr -d '\n' > "$1"; printf '%s\n' "$3" >> "$1"; }

    # Parallel plan (FLE-174). The composer gates and Playwright both use app/var/cache/dev (stan and lint warm it, the
    # throw-away server runs in dev), so they stay sequential. Everything else is independent and runs beside them:
    #   background A: npm ci + Chromium install      (while the composer gates run)
    #   background B: infra venv, pytest, systemd security, ansible-lint   (while Playwright runs)
    # Every gate still writes its own log and gates.tsv row (single short appended lines, atomic).
    pw_install() {
        local pw_deps='' lockstamp="$pw/node_modules/.ci-lock-hash"
        [ "${FLEET_CI_INSTALL_DEPS:-}" != 1 ] || pw_deps='sudo -E env "PATH=$PATH" npx playwright install-deps chromium && '
        local depstamp="$HOME/.cache/fleet-ci/pw-deps-$(sha256sum "$pw/package-lock.json" | cut -c1-16)"
        if [ -n "$pw_deps" ] && [ -f "$depstamp" ]; then pw_deps=''; fi
        local npm_ci='npm ci'
        if stamp_ok "$lockstamp" "$pw/package-lock.json" '' && [ -d "$pw/node_modules/.bin" ]; then npm_ci='echo "npm ci skipped: package-lock.json unchanged"'; fi
        gate_note='cached when the lock files are unchanged' run_gate playwright-install 1 in_pw bash -c "$npm_ci && ${pw_deps}npx playwright install --only-shell chromium" || return 1
        stamp_set "$lockstamp" "$pw/package-lock.json" ''
        if [ -n "${FLEET_CI_INSTALL_DEPS:-}" ]; then mkdir -p "$(dirname "$depstamp")" && : > "$depstamp"; fi
    }
    infra_chain() {
        local venvstamp="$venv/.ci-req-hash" pipcmd
        pipcmd="python3 -m venv '$venv' && '$venv/bin/pip' install -q -r '$root/ci/requirements-dev.txt'"
        if stamp_ok "$venvstamp" "$root/ci/requirements-dev.txt" '' && [ -x "$venv/bin/pytest" ]; then pipcmd='echo "venv reused: requirements-dev.txt unchanged"'; fi
        if run_gate infra-install 1 bash -c "$pipcmd"; then
            stamp_set "$venvstamp" "$root/ci/requirements-dev.txt" ''
            # Like the pipeline: a missing systemd-analyze fails the scoring tests instead of skipping them.
            # Only demanded when the binary exists here, so a container without systemd still runs the rest.
            local require_systemd=0
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
            local lint_venv="$root/.venv-lint" lintstamp="$root/.venv-lint/.ci-req-hash" lintcmd
            lintcmd="python3 -m venv '$lint_venv' && '$lint_venv/bin/pip' install -q -r '$root/ci/requirements-infra.txt'"
            if stamp_ok "$lintstamp" "$root/ci/requirements-infra.txt" '' && [ -x "$lint_venv/bin/ansible-lint" ]; then lintcmd='echo "venv reused: requirements-infra.txt unchanged"'; fi
            run_gate ansible-lint 0 bash -c "$lintcmd && cd '$root' && '$lint_venv/bin/ansible-lint' ansible/ && '$lint_venv/bin/yamllint' ansible/" || true
            [ ! -x "$lint_venv/bin/ansible-lint" ] || stamp_set "$lintstamp" "$root/ci/requirements-infra.txt" ''
        else
            skip_gate ansible-lint 0 "Python $(python3 -c 'import sys; print("%d.%d" % sys.version_info[:2])') is older than 3.12"
        fi
    }
    pw_install_ok="$report_dir/.pw-install-ok"
    rm -f "$pw_install_ok"
    ( pw_install && : > "$pw_install_ok" ) &
    pid_pw_install=$!
    infra_pid=''
    pw_workers=()
    # Workers: FLEET_CI_WORKERS wins. Otherwise a container with a CPU quota (docker cpus: N, cgroup v2 cpu.max) gets
    # two thirds of its CPUs, at least 1: every worker is a chromium plus the shared php server, and FLE-143 showed
    # 5 workers on 3 CPUs turning a 1 s page load into 10 s and axe runs into 18 s (60 s test timeouts, lost clicks).
    # 6 CPUs (the Fleet CI cap since 2026-11-04) = 4 workers.
    pw_wanted="${FLEET_CI_WORKERS:-}"
    if [ -z "$pw_wanted" ] && [ -r /sys/fs/cgroup/cpu.max ]; then
        read -r pw_quota pw_period < /sys/fs/cgroup/cpu.max || true
        if [[ "${pw_quota:-max}" =~ ^[0-9]+$ ]] && [[ "${pw_period:-}" =~ ^[0-9]+$ ]] && [ "$pw_period" -gt 0 ]; then
            pw_wanted=$(( pw_quota * 2 / (pw_period * 3) ))
            [ "$pw_wanted" -ge 1 ] || pw_wanted=1
        fi
    fi
    [ -z "$pw_wanted" ] || pw_workers=(--workers="$pw_wanted")

    printf '%s\n' "$pw_note" > "$report_dir/selection.txt"
    echo "playwright selection: $pw_note"
fi

# FLE-274: a pinned, checksummed stock Caddy so the real-Caddy tests (Repo suite) run instead of skipping.
# FLEET_CADDY_STOCK_BIN is deliberately not FLEET_CADDY_BIN: that one must have the OVH DNS module (a different test).
# An operator-set value wins; if the fetch fails the tests skip, as before.
if [ -z "${FLEET_CADDY_STOCK_BIN:-}" ]; then
    FLEET_CADDY_STOCK_BIN="$(bash "$root/ci/fetch-caddy.sh" 2> /dev/null || true)"
fi
[ -z "$FLEET_CADDY_STOCK_BIN" ] || export FLEET_CADDY_STOCK_BIN
echo "real-Caddy API tests: ${FLEET_CADDY_STOCK_BIN:-skipped (no Caddy binary)}"

# Stale Symfony caches give false Playwright and PHPUnit reds after a branch switch.
rm -rf "$root/app/var/cache/dev" "$root/app/var/cache/test"

if [ "${#composer[@]}" -eq 0 ] || [ -z "${composer[${#composer[@]}-1]}" ]; then
    printf '==> composer-install\n    composer not found on PATH\n'
    printf 'composer-install\tfailed\t0\t1\tcomposer not found on PATH\n' >> "$report_dir/gates.tsv"
    skip_gate composer-gates 1 "composer not found"
    skip_gate prod-check 1 "composer not found"
elif run_gate composer-install 1 app "${composer[@]}" install --no-interaction --prefer-dist; then
    # FLE-174: composer-gates is the `gates` script (assets, cs, stan, lint, test) run as parallel lanes: `assets` first
    # (it writes the built assets every other step reads), then one lane for cs + stan + lint and one PHPUnit process per
    # suite (Unit, Integration, Functional, Repo). Each PHPUnit process makes its own FLEET_HOME and uses APP_ENV=test,
    # while stan and lint warm the dev cache, so the lanes share nothing. FLEET_CI_SERIAL=1 runs plain `composer run gates`.
    # Keep the lane list in step with app/composer.json (`gates`).
    parallel_gates() {
        local suite lane_pids=() rc=0 junit_dir="$report_dir/junit"
        mkdir -p "$junit_dir"
        printf '==> assets\n'
        app "${composer[@]}" run assets || return 1
        (app "${composer[@]}" run cs && app "${composer[@]}" run stan && app "${composer[@]}" run lint) > "$report_dir/.lane-static.log" 2>&1 &
        lane_pids+=($!)
        for suite in Unit Integration Functional Repo; do
            (cd "$root/app" && "${FLEET_PHP:-php}" vendor/bin/phpunit --testsuite "$suite" --log-junit "$junit_dir/$suite.xml") > "$report_dir/.lane-$suite.log" 2>&1 &
            lane_pids+=($!)
        done
        local pid
        for pid in "${lane_pids[@]}"; do wait "$pid" || rc=1; done
        # One log, the lanes in order: gates_summary.py sums every PHPUnit summary it finds in it.
        for suite in static Unit Integration Functional Repo; do
            printf '##### lane: %s\n' "$suite"
            cat "$report_dir/.lane-$suite.log"
        done
        rm -f "$report_dir"/.lane-*.log
        python3 "$root/ci/slowest_tests.py" "$junit_dir" > "$report_dir/slowest-phpunit.txt" 2> /dev/null || true
        return "$rc"
    }
    if [ "${FLEET_CI_SERIAL:-}" = 1 ]; then
        run_gate composer-gates 1 app "${composer[@]}" run gates || true
    else
        run_gate composer-gates 1 parallel_gates || true
    fi
    scripts="$(app "${composer[@]}" run-script --list 2>/dev/null || true)"
    if grep -q '^ *prod-check\b' <<< "$scripts"; then
        run_gate prod-check 1 app "${composer[@]}" run prod-check || true
    else
        skip_gate prod-check 1 "no prod-check script in app/composer.json"
    fi
else
    skip_gate composer-gates 1 "composer install failed"
    skip_gate prod-check 1 "composer install failed"
fi


if [ "$mode" = full ]; then
    # Playwright proper: needs the composer install (vendor) and the npm install, both finished by now.
    wait "$pid_pw_install" || true
    if [ -f "$pw_install_ok" ]; then
        # The infra chain (pytest, systemd score, ansible-lint) shares nothing with the app, so it runs beside Playwright.
        infra_chain &
        infra_pid=$!
        run_gate playwright-typecheck 1 in_pw npm run typecheck || true
        # The merge gate runs with FLEET_E2E_BASE_URL unset: Playwright starts its own throw-away server.
        gate_note="$pw_note"
        run_gate playwright 1 in_pw env -u FLEET_E2E_BASE_URL FLEET_PW_PHONE="$pw_phone" FLEET_PW_TARGETED="$pw_targeted" \
            PLAYWRIGHT_JSON_OUTPUT_NAME="$report_dir/playwright-results.json" \
            npx playwright test "${pw_workers[@]}" --reporter=list,html,json || true
        gate_note=''
        [ ! -f "$report_dir/playwright-results.json" ] || python3 "$root/ci/slowest_tests.py" "$report_dir/playwright-results.json" > "$report_dir/slowest-tests.txt" 2> /dev/null || true
        [ ! -d "$pw/playwright-report" ] || cp -R "$pw/playwright-report" "$report_dir/playwright-report"
    else
        skip_gate playwright-typecheck 1 "playwright install failed"
        skip_gate playwright 1 "playwright install failed"
        infra_chain &
        infra_pid=$!
    fi
    wait "$infra_pid" || true
    rm -f "$pw_install_ok"
fi

echo
python3 "$root/ci/gates_summary.py" build "$report_dir"
