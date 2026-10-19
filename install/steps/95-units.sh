# shellcheck shell=bash
# shellcheck disable=SC2034  # the change flags and results are read by the other steps (one shell sources them all)
# Step 95: the services, written but not started (step 98 starts them once the configuration exists).
#   - php-fpm: the `fleet` pool on /run/php/fleet.sock (the stock www pool is disabled), the 90-fleet.ini settings and
#     the unit drop-in that loads fleet.env and lets the pool write /etc/caddy and /etc/authelia;
#   - the job worker template unit fleet-worker@.service;
#   - the Mercure hub: pinned release, sha256-checked, plus its unit and configuration.
# Every configuration is validated (php-fpm -t, mercure validate) before a service is asked to use it.

FPM_DIR=/etc/php/8.4/fpm
MERCURE_VERSION=1.1.2
MERCURE_SHA256_AMD64=64d67a1189c96c0131229e309679695086eca0d4cff0d14f9fef94f109438e30
MERCURE_SHA256_ARM64=60a016f6e838c888f76081944e0607500e8d9f1eb280db6812838714e67a3abb
MERCURE_BIN=/usr/local/bin/mercure
MERCURE_MARKER_DIR=/usr/local/lib/ddev-fleet
MERCURE_MARKER=$MERCURE_MARKER_DIR/mercure.version

# Downloads, verifies and installs the Mercure hub. The marker holds "<version> <sha256>" of the tarball that produced
# the installed binary, so a re-run downloads again only when the pin changed.
install_mercure() {
  local arch asset sha url tmp
  arch=$(detect_arch)
  if [ "$arch" = amd64 ]; then
    asset=x86_64
    sha=$MERCURE_SHA256_AMD64
  else
    asset=arm64
    sha=$MERCURE_SHA256_ARM64
  fi
  url="https://github.com/dunglas/mercure/releases/download/v$MERCURE_VERSION/mercure_Linux_$asset.tar.gz"

  if ! is_dry && [ -x "$MERCURE_BIN" ] && [ "$(cat "$MERCURE_MARKER" 2>/dev/null || true)" = "$MERCURE_VERSION $sha" ]; then
    return 0
  fi
  MERCURE_CHANGED=1
  if is_dry; then
    printf '+ fetch %s -> <tmp>/mercure.tar.gz\n' "$url"
    printf '+ sha256sum -c: %s  mercure.tar.gz\n' "$sha"
    printf '+ tar -xzf <tmp>/mercure.tar.gz mercure; install -m 0755 -o root -g root mercure %s\n' "$MERCURE_BIN"
    return 0
  fi
  tmp=$(mktemp -d)
  fetch "$url" "$tmp/mercure.tar.gz"
  if ! (cd "$tmp" && printf '%s  mercure.tar.gz\n' "$sha" | sha256sum -c --status -); then
    rm -rf "$tmp"
    die 1 "the downloaded Mercure archive does not match the pinned sha256 ($sha); nothing was installed"
  fi
  tar -xzf "$tmp/mercure.tar.gz" --no-same-owner -C "$tmp" mercure
  install -m 0755 -o root -g root "$tmp/mercure" "$MERCURE_BIN.new"
  mv -f "$MERCURE_BIN.new" "$MERCURE_BIN"
  ensure_dir "$MERCURE_MARKER_DIR" 0755 root:root
  write_file "$MERCURE_MARKER" 0644 root:root <<<"$MERCURE_VERSION $sha"
  rm -rf "$tmp"
}

step_main() {
  local units_changed=0

  # --- php-fpm ----------------------------------------------------------------------------------------------------
  install_etc php-fpm-fleet.conf "$FPM_DIR/pool.d/fleet.conf" 0644 root:root
  [ "$FILE_CHANGED" = 0 ] || FPM_CHANGED=1
  if is_dry || [ -f "$FPM_DIR/pool.d/www.conf" ]; then
    run mv -f "$FPM_DIR/pool.d/www.conf" "$FPM_DIR/pool.d/www.conf.disabled"
    FPM_CHANGED=1
  fi
  install_etc php-fleet.ini "$FPM_DIR/conf.d/90-fleet.ini" 0644 root:root
  [ "$FILE_CHANGED" = 0 ] || FPM_CHANGED=1
  ensure_dir "$SYSTEMD_DIR/php8.4-fpm.service.d" 0755 root:root
  install_etc php-fpm-env.conf "$SYSTEMD_DIR/php8.4-fpm.service.d/fleet-env.conf" 0644 root:root
  [ "$FILE_CHANGED" = 0 ] || { FPM_CHANGED=1; units_changed=1; }
  run php-fpm8.4 --test

  # --- the job worker ---------------------------------------------------------------------------------------------
  install_etc 'fleet-worker@.service' "$SYSTEMD_DIR/fleet-worker@.service" 0644 root:root
  [ "$FILE_CHANGED" = 0 ] || { WORKER_UNIT_CHANGED=1; units_changed=1; }

  # --- the Mercure hub --------------------------------------------------------------------------------------------
  install_mercure
  run env MERCURE_PUBLISHER_JWT_KEY=validate-only-0123456789abcdef0123456789 MERCURE_SUBSCRIBER_JWT_KEY=validate-only-0123456789abcdef0123456789 \
    "$MERCURE_BIN" validate --config "$INSTALL_DIR/etc/mercure.Caddyfile" --adapter caddyfile
  ensure_dir "$MERCURE_ETC_DIR" 0755 root:root
  install_etc mercure.Caddyfile "$MERCURE_ETC_DIR/Caddyfile" 0644 root:root
  [ "$FILE_CHANGED" = 0 ] || MERCURE_CHANGED=1
  install_etc fleet-mercure.service "$SYSTEMD_DIR/fleet-mercure.service" 0644 root:root
  [ "$FILE_CHANGED" = 0 ] || { MERCURE_CHANGED=1; units_changed=1; }

  if [ "$units_changed" = 1 ]; then
    run systemctl daemon-reload
  fi
}
