# shellcheck shell=bash
# Step 50: Caddy from its apt repository, plus a custom build that has the OVH DNS module (needed for the wildcard
# certificate mode). The package binary is diverted to /usr/bin/caddy.default so apt can keep upgrading it without
# ever replacing the custom build, and update-alternatives points /usr/bin/caddy at the custom one. Re-running
# refreshes the custom build when it is older than the packaged Caddy or the pinned minimum (CADDY_MIN_VERSION):
# that is how the Internet-facing process gets security fixes.

CADDY_DEFAULT_BIN=/usr/bin/caddy.default
CADDY_CUSTOM_BIN=/usr/bin/caddy.custom

# "v2.11.7" from `caddy version`
caddy_version() {
  "$1" version 2>/dev/null | awk '{print $1; exit}'
}

# Not `... | grep -q`: with pipefail the early exit of grep would turn a hit into a failure.
caddy_has_ovh() {
  local modules
  modules=$("$1" list-modules 2>/dev/null) || return 1
  [[ $modules == *dns.providers.ovh* ]]
}

# The version the custom build must reach: the packaged Caddy's, but never less than the pinned minimum.
caddy_wanted_version() {
  local packaged=''
  if [ -x "$CADDY_DEFAULT_BIN" ]; then
    packaged=$(caddy_version "$CADDY_DEFAULT_BIN" || true)
  fi
  if [ -n "$packaged" ] && version_lt "$CADDY_MIN_VERSION" "$packaged"; then
    printf '%s\n' "$packaged"
  else
    printf '%s\n' "$CADDY_MIN_VERSION"
  fi
}

caddy_custom_is_current() {
  local have
  [ -x "$CADDY_CUSTOM_BIN" ] || return 1
  caddy_has_ovh "$CADDY_CUSTOM_BIN" || return 1
  have=$(caddy_version "$CADDY_CUSTOM_BIN" || true)
  [ -n "$have" ] || return 1
  ! version_lt "$have" "$1"
}

# Downloads, verifies and installs the custom build. Returns 1 (message on stderr, old build untouched) instead of
# exiting: the caller decides whether an already installed build is good enough. No `set -e` inside: this function is
# called in an `||` list, where errexit is off, so every command is checked by hand.
caddy_download_custom() {
  local wanted=$1 new="$CADDY_CUSTOM_BIN.new" have sum
  fetch "https://caddyserver.com/api/download?os=linux&arch=$(detect_arch)&p=github.com%2Fcaddy-dns%2Fovh" "$new" || {
    rm -f "$new"
    printf 'WARNING: could not download the custom Caddy build\n' >&2
    return 1
  }
  if is_dry; then
    printf '+ verify %s: runs, lists dns.providers.ovh, version >= %s\n' "$new" "$wanted"
    printf '+ mv %s %s\n' "$new" "$CADDY_CUSTOM_BIN"
    return 0
  fi
  if ! chown root:root "$new" || ! chmod 0755 "$new"; then
    rm -f "$new"
    return 1
  fi
  have=$(caddy_version "$new" || true)
  if [ -z "$have" ]; then
    rm -f "$new"
    printf 'WARNING: the downloaded Caddy does not run\n' >&2
    return 1
  fi
  if ! caddy_has_ovh "$new"; then
    rm -f "$new"
    printf 'WARNING: the downloaded Caddy has no dns.providers.ovh module\n' >&2
    return 1
  fi
  if version_lt "$have" "$wanted"; then
    rm -f "$new"
    printf 'WARNING: the build service returned Caddy %s, older than the required %s\n' "$have" "$wanted" >&2
    return 1
  fi
  sum=$(sha256sum "$new" | cut -d' ' -f1)
  log_info "custom caddy $have sha256 $sum"
  mv -f "$new" "$CADDY_CUSTOM_BIN"
}

# Brings the custom build up to date. A refresh that cannot be done (build service down or serving an older build) is
# only fatal when no working build is installed yet; an installed one that still meets the pinned minimum is kept.
caddy_refresh_custom() {
  local wanted=$1
  if ! is_dry && caddy_custom_is_current "$wanted"; then
    return 0
  fi
  if caddy_download_custom "$wanted"; then
    return 0
  fi
  if ! is_dry && caddy_custom_is_current "$CADDY_MIN_VERSION"; then
    log_warn "keeping the installed custom Caddy $(caddy_version "$CADDY_CUSTOM_BIN"); re-run later to refresh it"
    return 0
  fi
  die 1 "no custom Caddy with the OVH DNS module could be installed"
}

step_main() {
  local diverted wanted changed=0

  apt_repo caddy https://dl.cloudsmith.io/public/caddy/stable/gpg.key "$KEYRING_DIR/caddy.asc" \
    "deb [signed-by=$KEYRING_DIR/caddy.asc] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main"
  # The package would start its stock service on :80 with the default Caddyfile; ours starts it once configured.
  policy_deny_start
  apt_install caddy
  policy_allow_start

  # Divert once (a kill between the divert and the alternatives below is repaired by the next run).
  diverted=$(dpkg-divert --list /usr/bin/caddy 2>/dev/null || true)
  if is_dry || [ -z "$diverted" ]; then
    run dpkg-divert --divert "$CADDY_DEFAULT_BIN" --rename /usr/bin/caddy
  fi

  wanted=$(caddy_wanted_version)
  caddy_refresh_custom "$wanted"
  run update-alternatives --install /usr/bin/caddy caddy "$CADDY_DEFAULT_BIN" 10
  run update-alternatives --install /usr/bin/caddy caddy "$CADDY_CUSTOM_BIN" 50
  run update-alternatives --auto caddy
  if ! is_dry; then
    caddy_has_ovh /usr/bin/caddy || die 1 "/usr/bin/caddy has no dns.providers.ovh module after the install"
  fi

  ensure_dir "$SYSTEMD_DIR/caddy.service.d" 0755 root:root
  install_etc caddy-sandbox.conf "$SYSTEMD_DIR/caddy.service.d/10-fleet-sandbox.conf" 0644 root:root
  [ "$FILE_CHANGED" = 0 ] || changed=1
  install_etc caddy-env.conf "$SYSTEMD_DIR/caddy.service.d/20-fleet-env.conf" 0644 root:root
  [ "$FILE_CHANGED" = 0 ] || changed=1
  if [ "$changed" = 1 ]; then
    run systemctl daemon-reload
  fi

  # fleet writes, caddy reads: the setgid bit is what hands the group over (a lost bit makes Caddy fail to start).
  ensure_dir "$CADDY_FLEET_DIR" 2750 fleet:caddy
  ensure_dir "$CADDY_FLEET_DIR/instances" 2750 fleet:caddy
  ensure_dir "$CADDY_FLEET_DIR/ports" 2750 fleet:caddy
  run find "$CADDY_FLEET_DIR" -mindepth 1 -type d -exec chmod 2750 '{}' +
  run find "$CADDY_FLEET_DIR" -mindepth 1 -type f -exec chgrp -h caddy '{}' + -exec chmod 0640 '{}' +
}
