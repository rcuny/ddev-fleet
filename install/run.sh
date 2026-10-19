#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
# ddev-fleet installer, stage 2: validates the flags, checks DNS and runs install/steps/NN-*.sh in numeric order.
# Normally started by install.sh from a root-owned export of install/; it can also run from a checkout
# (bash install/run.sh --dry-run --domain=fleet.example.com --skip-dns-check).
#
# Every step is idempotent. A failed step prints its name; fix the cause and run the same command again.

main() {
  set -Eeuo pipefail
  export LC_ALL=C
  export DEBIAN_FRONTEND=noninteractive
  PATH="$PATH:/usr/local/sbin:/usr/sbin:/sbin"
  export PATH
  # A step that asks a question must fail, not wait for (or swallow) input.
  exec </dev/null
  umask 022

  INSTALL_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  # The `fleet` user runs DDEV and git from here: the caller's directory may be closed to it.
  cd /
  # shellcheck source=lib/common.sh
  . "$INSTALL_DIR/lib/common.sh"

  parse_args "$@"
  check_root
  check_os

  MAIN_PID=$BASHPID
  trap 'on_error $? "${BASH_SOURCE[0]}" "$LINENO"' ERR
  trap 'on_exit $?' EXIT

  LOCK_HELD=0
  local tee_pid=''
  if is_dry; then
    printf 'dry-run: nothing will be changed\n'
  else
    # One installer at a time.
    exec 9>/run/lock/ddev-fleet-install.lock
    flock -n 9 || die "$EX_TEMPFAIL" "another installer run is in progress"
    LOCK_HELD=1
    # A policy-rc.d left by a killed run must not outlive it.
    policy_allow_start
    # Everything is logged (mode 0600). fd 3 stays the terminal for output that must not reach the log (term_print).
    (umask 077 && : >>"$INSTALL_LOG")
    exec 3>&1
    exec > >(tee -a "$INSTALL_LOG") 2>&1
    tee_pid=$!
    printf '%s installer start (domain %s)\n' "$(date -u +%FT%TZ)" "$DOMAIN"
  fi

  STEP_NAME=preflight
  printf '[preflight] domain %s, ref %s\n' "$DOMAIN" "$REF"
  check_dns "$DOMAIN"

  local step name
  for step in "$INSTALL_DIR"/steps/[0-9][0-9]-*.sh; do
    [ -f "$step" ] || continue
    name=$(basename "$step" .sh)
    # shellcheck disable=SC1090  # the steps are discovered at run time
    . "$step"
    log_step "$name"
    step_main
    unset -f step_main
  done

  STEP_NAME=finish
  if is_dry; then
    printf 'dry-run complete: nothing was changed\n'
  else
    printf 'install complete; log: %s\n' "$INSTALL_LOG"
    # Let tee flush and exit before anything else writes to the terminal.
    exec 1>&3 2>&3
    wait "$tee_pid" 2>/dev/null || true
  fi
}

# Failures: the ERR trap reports the failing command's file and line; die() and other explicit exits are caught by
# the EXIT trap. Either way the step name and the re-run hint are printed once, by the main shell only.
on_error() {
  # Subshells (command substitutions) inherit the ERR trap; only the main shell reports.
  [ "$BASHPID" = "$MAIN_PID" ] || return 0
  report_failure "$1" "${2##*/}:$3"
}

on_exit() {
  local code=$1
  [ "$BASHPID" = "$MAIN_PID" ] || return 0
  if [ "$LOCK_HELD" = 1 ]; then
    policy_allow_start || true
  fi
  if [ "$code" -ne 0 ]; then
    report_failure "$code" ""
  fi
}

main "$@"
