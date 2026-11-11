#!/usr/bin/env bash
# Container idempotency job for the host playbook (FLE-301, item 3 of FLE-262).
#
#   ci/ansible-idempotency.sh [--report-dir DIR] [--keep] [--dry-run]
#
# Starts a Debian 13 container that runs systemd as PID 1, copies install/ into it, runs install/ansible/site.yml
# twice with test host vars and FAILS unless the second run reports changed=0 (and neither run reports a failure).
# The roles that cannot work in a container are skipped with --skip-tags, never by editing a role:
#
#   docker      installs Docker and starts the daemon (a container has no Docker; no docker-in-docker)
#   ddev        needs the Docker daemon (ddev config global, mkcert)
#   app_code    builds the application from a release checkout the bootstrap makes (not part of this test)
#   units       starts the services the application needs, which need the built application
#   hardening   sshd, ufw/iptables, fail2ban, auditd, sysctl and the firewall dead-man's switch
#
# What stays covered: packages, fleet_user, shell, caddy, authelia, php_app, privileged, the shared pre_tasks (the
# Debian 13 assertion, the host-vars loading) and the handlers they notify. The skipped roles still get
# `ansible-playbook --syntax-check` (it ignores tags) and ansible-lint in ci/run-gates.sh. The hardening role's
# first-enable and dead-man's switch paths need a real VM (the throw-away host skill, FLE-262 items 1 and 2).
#
# Where it runs: on the CI HOST (it needs a container engine), not in the DDEV web container where ci/run-gates.sh
# runs. Fleet CI calls it after run-gates.sh (see docs/contributing/03-ci.md, "Ansible idempotency"). It can also be
# run by hand on any Linux box with docker or podman: ~5 to 8 minutes cold (image build and apt downloads).
#
# Environment:
#   FLEET_CT_ENGINE        docker | podman (default: docker if present, else podman)
#   FLEET_CT_BASE_IMAGE    default debian:trixie
#   FLEET_CT_SKIP_TAGS     default docker,ddev,app_code,units,hardening
#   FLEET_CT_PRIVILEGED    1 = run the container with --privileged (fallback when systemd does not boot without it)
#   FLEET_CT_RUN_TIMEOUT   seconds allowed per playbook run (default 480)
#   FLEET_CT_BOOT_TIMEOUT  seconds allowed for systemd to boot (default 60)
#
# Exit codes: 0 second run changed=0; 1 a run failed, the second run changed something, or systemd did not boot;
# 2 usage; 3 no container engine (nothing was tested; the caller decides whether that is a failure).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
report_dir="$root/var/ci-report"
keep=0
dry_run=0

usage() {
    sed -n '2,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
    case "$1" in
        --report-dir)
            [ $# -ge 2 ] || { echo "ansible-idempotency: --report-dir needs a directory" >&2; exit 2; }
            report_dir="$2"
            shift
            ;;
        --report-dir=*) report_dir="${1#--report-dir=}" ;;
        --keep) keep=1 ;;
        --dry-run) dry_run=1 ;;
        -h | --help) usage; exit 0 ;;
        *) echo "ansible-idempotency: unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

base_image="${FLEET_CT_BASE_IMAGE:-debian:trixie}"
skip_tags="${FLEET_CT_SKIP_TAGS:-docker,ddev,app_code,units,hardening}"
run_timeout="${FLEET_CT_RUN_TIMEOUT:-480}"
boot_timeout="${FLEET_CT_BOOT_TIMEOUT:-60}"
engine="${FLEET_CT_ENGINE:-}"
if [ -z "$engine" ]; then
    if command -v docker > /dev/null 2>&1; then engine=docker; elif command -v podman > /dev/null 2>&1; then engine=podman; fi
fi
case "$engine" in
    docker | podman) ;;
    '')
        echo "ansible-idempotency: no container engine (docker or podman) on this host; nothing was tested" >&2
        exit 3
        ;;
    *) echo "ansible-idempotency: FLEET_CT_ENGINE must be docker or podman, not '$engine'" >&2; exit 2 ;;
esac

# Debian's own ansible-core and python3-apt, the same as a real host gets from the bootstrap (install.sh).
dockerfile="FROM $base_image
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update -qq \\
 && apt-get install -y -qq --no-install-recommends systemd systemd-sysv dbus ansible-core python3-apt sudo ca-certificates iproute2 procps \\
 && rm -rf /var/lib/apt/lists/* \\
 && systemctl mask getty.target console-getty.service systemd-logind.service
STOPSIGNAL SIGRTMIN+3
CMD [\"/lib/systemd/systemd\"]
"
image="fleet-ci-ansible:$(printf '%s' "$dockerfile" | sha256sum | cut -c1-12)"
name="fleet-ct-$$"
playbook_cmd=(ansible-playbook -i localhost, -c local /opt/fleet-ct/install/ansible/site.yml -e fleet_ref=ci-container-test)

if [ "$dry_run" = 1 ]; then
    echo "engine:        $engine"
    echo "image:         $image (from $base_image, built once and reused)"
    echo "skipped tags:  $skip_tags"
    echo "run timeout:   ${run_timeout}s per run, systemd boot ${boot_timeout}s"
    echo "playbook:      ${playbook_cmd[*]} --skip-tags $skip_tags   (twice)"
    exit 0
fi

mkdir -p "$report_dir"
log1="$report_dir/ansible-idempotency-run1.log"
log2="$report_dir/ansible-idempotency-run2.log"
report="$report_dir/ansible-idempotency.txt"
: > "$report"

say() { printf '%s\n' "$*" | tee -a "$report"; }

cleanup() {
    local rc=$?
    if [ "$keep" = 1 ]; then
        echo "ansible-idempotency: container $name kept (--keep); remove it with: $engine rm -f $name" >&2
    else
        "$engine" rm -f "$name" > /dev/null 2>&1 || true
    fi
    return "$rc"
}
trap cleanup EXIT

if ! "$engine" image inspect "$image" > /dev/null 2>&1; then
    echo "ansible-idempotency: building $image"
    printf '%s' "$dockerfile" | "$engine" build -q -t "$image" - > /dev/null
fi

run_args=(run -d --name "$name" --tmpfs /tmp --tmpfs /run --tmpfs /run/lock)
if [ "${FLEET_CT_PRIVILEGED:-0}" = 1 ]; then
    run_args+=(--privileged)
elif [ "$engine" = podman ]; then
    run_args+=(--systemd=always)
else
    run_args+=(--cgroupns=host --cap-add SYS_ADMIN -v /sys/fs/cgroup:/sys/fs/cgroup:rw)
fi
"$engine" "${run_args[@]}" "$image" > /dev/null

booted=0
state=''
for ((i = 0; i < boot_timeout; i++)); do
    state="$("$engine" exec "$name" systemctl is-system-running 2> /dev/null || true)"
    case "$state" in running | degraded) booted=1; break ;; esac
    sleep 1
done
if [ "$booted" != 1 ]; then
    say "FAIL: systemd did not boot in ${boot_timeout}s (state: ${state:-none}); engine $engine."
    say "      Retry with FLEET_CT_PRIVILEGED=1 if the host forbids systemd in an unprivileged container."
    "$engine" logs "$name" 2>&1 | tail -n 20 | tee -a "$report" >&2 || true
    exit 1
fi

# The whole install/ tree: the roles read install/etc, install/libexec and install/lib relative to the playbook.
"$engine" exec "$name" mkdir -p /opt/fleet-ct /etc/ddev-fleet
"$engine" cp "$root/install" "$name:/opt/fleet-ct/install"
# The host's own answers (documentation addresses only). Hardening stays off, as on a default host.
"$engine" exec -i "$name" tee /etc/ddev-fleet/host-vars.yml > /dev/null << 'EOF'
---
fleet_domain: ci.example.test
fleet_acme_email: ci@example.test
fleet_hardening_enabled: false
EOF

ansible_env=(env ANSIBLE_FORCE_COLOR=0 ANSIBLE_NOCOLOR=1 ANSIBLE_STDOUT_CALLBACK=default ANSIBLE_LOCALHOST_WARNING=False)

# One run: output to a log file, the PLAY RECAP counters back as "ok changed failed unreachable".
run_playbook() {
    local log="$1" t0 rc=0
    t0=$(date +%s)
    timeout "$run_timeout" "$engine" exec "$name" "${ansible_env[@]}" "${playbook_cmd[@]}" --skip-tags "$skip_tags" > "$log" 2>&1 || rc=$?
    RUN_SECS=$(($(date +%s) - t0))
    RUN_RC=$rc
    RUN_RECAP="$(awk '/^localhost[[:space:]]*:/ {
        for (i = 1; i <= NF; i++) { split($i, kv, "="); v[kv[1]] = kv[2] }
        print v["ok"] + 0, v["changed"] + 0, v["failed"] + 0, v["unreachable"] + 0
    }' "$log" | tail -n 1)"
}

say "ansible-idempotency: $engine, image $image, skipped tags: $skip_tags"

"$engine" exec "$name" "${ansible_env[@]}" "${playbook_cmd[@]}" --syntax-check > "$report_dir/ansible-idempotency-syntax.log" 2>&1 || {
    say "FAIL: --syntax-check failed (see ansible-idempotency-syntax.log)"
    tail -n 20 "$report_dir/ansible-idempotency-syntax.log" >&2
    exit 1
}

fail=0
run_playbook "$log1"
r1="$RUN_RECAP"; s1="$RUN_SECS"; rc1="$RUN_RC"
run_playbook "$log2"
r2="$RUN_RECAP"; s2="$RUN_SECS"; rc2="$RUN_RC"

fmt() { # "ok changed failed unreachable" -> "ok=N changed=N failed=N unreachable=N"
    if [ -z "$1" ]; then echo "no PLAY RECAP"; return; fi
    read -r a b c d <<< "$1"
    echo "ok=$a changed=$b failed=$c unreachable=$d"
}
say "run 1 (cold):  rc=$rc1  $(fmt "$r1")  ${s1}s"
say "run 2 (no-op): rc=$rc2  $(fmt "$r2")  ${s2}s"

if [ "$rc1" -ne 0 ] || [ -z "$r1" ] || [ "$(awk '{print $3 + $4}' <<< "$r1")" -ne 0 ]; then
    say "FAIL: the first run did not finish cleanly (log: $log1)"
    tail -n 30 "$log1" | tee -a "$report" >&2
    fail=1
elif [ "$rc2" -ne 0 ] || [ -z "$r2" ] || [ "$(awk '{print $3 + $4}' <<< "$r2")" -ne 0 ]; then
    say "FAIL: the second run did not finish cleanly (log: $log2)"
    tail -n 30 "$log2" | tee -a "$report" >&2
    fail=1
elif [ "$(awk '{print $2}' <<< "$r2")" -ne 0 ]; then
    say "FAIL: the second run changed $(awk '{print $2}' <<< "$r2") task(s); the playbook is not idempotent. Changed tasks:"
    awk '/^TASK \[/ {task = $0} /^changed: / {print "  " task "\n    " $0}' "$log2" | tee -a "$report"
    fail=1
else
    say "PASS: the second run reports changed=0"
fi
exit "$fail"
