# shellcheck shell=bash
# Step 99: opt-in host hardening (FLE-211). Off unless `--harden` was given or an earlier run stored ENABLED=1 in
# /etc/ddev-fleet/hardening.conf; then a plain install or upgrade changes nothing here. The work is in
# install/harden/harden.sh (see its header); docs/reference/hardening.md describes it for operators.

step_main() {
  local conf=$FLEET_ETC_DIR/hardening.conf
  if [ "${HARDEN:-0}" != 1 ] && ! { [ -f "$conf" ] && grep -q '^ENABLED=1$' "$conf"; }; then
    log_info "host hardening: off (opt-in: pass --harden; see docs/reference/hardening.md)"
    return 0
  fi
  # shellcheck source=../harden/harden.sh
  . "$INSTALL_DIR/harden/harden.sh"
  harden_main
}
