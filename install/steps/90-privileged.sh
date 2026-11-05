# shellcheck shell=bash
# Step 90: the only things that give the fleet user a root-side effect, installed from the ROOT-OWNED export of
# install/ (INSTALL_DIR), never from the fleet-writable checkout:
#   /usr/local/libexec/fleet/apply   the root helper (root:root 0755)
#   /usr/local/libexec/fleet/upgrade the root helper of `sudo fleet upgrade` (root:root 0755; NO sudoers entry: root only)
#   /etc/tmpfiles.d/ddev-fleet.conf  creates /var/log/ddev-fleet and its root-owned upgrade-history.jsonl (0644)
#   /etc/sudoers.d/fleet-apply       one line: fleet may run `apply caddy`, `apply authelia` and `apply slices`, nothing else
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
  write_file "$HELPER_DIR/upgrade" 0755 root:root <"$INSTALL_DIR/libexec/upgrade"
  ensure_dir /etc/tmpfiles.d 0755 root:root
  install_etc fleet-tmpfiles.conf /etc/tmpfiles.d/ddev-fleet.conf 0644 root:root
  run systemd-tmpfiles --create /etc/tmpfiles.d/ddev-fleet.conf
  install_sudoers
  write_file /usr/local/bin/fleet 0755 root:root <"$INSTALL_DIR/bin/fleet"
}
