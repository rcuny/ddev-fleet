# shellcheck shell=bash
# Step 90: the only things that give the fleet user a root-side effect, installed from the ROOT-OWNED export of
# install/ (INSTALL_DIR), never from the fleet-writable checkout:
#   /usr/local/libexec/fleet/apply   the root helper (root:root 0755)
#   /etc/sudoers.d/fleet-apply       one line: fleet may run `apply caddy` and `apply authelia`, nothing else
#   /usr/local/bin/fleet             the command-line wrapper
# The sudoers file is checked with visudo before it lands: a broken sudoers can lock everybody out of sudo.

install_sudoers() {
  local tmp
  if is_dry; then
    printf '+ visudo -cf %s/etc/sudoers-fleet-apply\n' "$INSTALL_DIR"
    write_file "$SUDOERS_DIR/fleet-apply" 0440 root:root <"$INSTALL_DIR/etc/sudoers-fleet-apply"
    return 0
  fi
  tmp=$(mktemp)
  cat "$INSTALL_DIR/etc/sudoers-fleet-apply" >"$tmp"
  if ! visudo -cf "$tmp" >/dev/null; then
    rm -f "$tmp"
    die 1 "the sudoers file for the apply helper does not parse; nothing was installed"
  fi
  write_file "$SUDOERS_DIR/fleet-apply" 0440 root:root <"$tmp"
  rm -f "$tmp"
}

step_main() {
  ensure_dir "$HELPER_DIR" 0755 root:root
  write_file "$HELPER_DIR/apply" 0755 root:root <"$INSTALL_DIR/libexec/apply"
  install_sudoers
  write_file /usr/local/bin/fleet 0755 root:root <"$INSTALL_DIR/bin/fleet"
}
