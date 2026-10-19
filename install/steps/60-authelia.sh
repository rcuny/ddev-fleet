# shellcheck shell=bash
# Step 60: Authelia from its apt repository and everything it needs on disk BEFORE its first start:
#   - the tmpfiles mask (the package's `Z /etc/authelia/*` rule would strip the execute bit of the secrets
#     directory on every boot and Authelia would not start after the first reboot), installed before the package;
#   - the three secrets, each generated only when its own file is missing, atomically;
#   - an empty but valid users.yml (so a start can never fall back to a default account), in a setgid directory so
#     the file the app writes later inherits Authelia's group.
# Authelia's configuration.yml, the real users and the start are the later steps' and the wizard's business.

AUTHELIA_ETC_DIR=/etc/authelia
AUTHELIA_SECRETS_DIR=/etc/authelia/secrets
AUTHELIA_SECRET_NAMES=(session_secret storage_encryption_key reset_password_jwt_secret)

# The service account comes from the installed unit (never created or assumed). Empty means the unit runs as root.
authelia_account() {
  if is_dry; then
    AUTHELIA_USER=authelia
    AUTHELIA_GROUP=authelia
    return 0
  fi
  AUTHELIA_USER=$(systemctl show -p User --value authelia.service)
  AUTHELIA_GROUP=$(systemctl show -p Group --value authelia.service)
  AUTHELIA_USER=${AUTHELIA_USER:-root}
  AUTHELIA_GROUP=${AUTHELIA_GROUP:-root}
  getent passwd "$AUTHELIA_USER" >/dev/null || die 1 "authelia.service names the user $AUTHELIA_USER, which does not exist"
  getent group "$AUTHELIA_GROUP" >/dev/null || die 1 "authelia.service names the group $AUTHELIA_GROUP, which does not exist"
}

# One secret, generated once, atomically (temp file in the same directory, then rename): a kill leaves either no file
# or a complete one, and a re-run only fills what is missing.
authelia_secret() {
  local name=$1 dest="$AUTHELIA_SECRETS_DIR/$1" tmp
  if is_dry; then
    printf '+ secret %s (openssl rand -hex 32, only when missing) 0640 root:%s\n' "$dest" "$AUTHELIA_GROUP"
    return 0
  fi
  if [ -s "$dest" ]; then
    return 0
  fi
  tmp=$(mktemp "$AUTHELIA_SECRETS_DIR/.$name.XXXXXX")
  openssl rand -hex 32 >"$tmp"
  chown "root:$AUTHELIA_GROUP" "$tmp"
  chmod 0640 "$tmp"
  rm -f "$dest"
  mv "$tmp" "$dest"
}

# No account may exist that nobody created: neither a stock user database nor a users.yml without a `users` map.
authelia_assert_no_default_account() {
  local users_file=$1 stock
  if is_dry; then
    printf '+ assert no default account (no stock user database, %s is a valid users map)\n' "$users_file"
    return 0
  fi
  for stock in "$AUTHELIA_ETC_DIR/users_database.yml" "$AUTHELIA_ETC_DIR/users.yml"; do
    if [ -e "$stock" ] && grep -Eq '^[[:space:]]*"?authelia"?:[[:space:]]*$' "$stock"; then
      die 1 "$stock defines the default account 'authelia'; remove it and re-run"
    fi
  done
  grep -Eq '^"?users"?:' "$users_file" || die 1 "$users_file has no users map"
}

step_main() {
  local fresh=1 changed=0 users_file="$FLEET_SRV_DIR/authelia/users.yml" name

  # 1. The mask first, so not even the package's own postinst run (or a later upgrade) can touch the secrets.
  ensure_dir /etc/tmpfiles.d 0755 root:root
  install_etc authelia-tmpfiles.conf /etc/tmpfiles.d/authelia.conf 0644 root:root

  # 2. The package.
  if ! is_dry && dpkg -s authelia >/dev/null 2>&1; then
    fresh=0
  fi
  apt_repo authelia https://www.authelia.com/keys/authelia-security.gpg "$KEYRING_DIR/authelia-security.gpg" \
    "deb [signed-by=$KEYRING_DIR/authelia-security.gpg] https://apt.authelia.com stable main"
  # Nothing may start Authelia before its secrets and users.yml exist (below): no service starts during the install.
  policy_deny_start
  apt_install authelia
  policy_allow_start
  authelia_account

  # 3. A first install must not leave a running Authelia behind: it has no configuration yet.
  if [ "$fresh" = 1 ]; then
    if is_dry || systemctl is-active --quiet authelia.service; then
      run systemctl stop authelia.service
    fi
    # Exits 1 ("Unit authelia.service not loaded") when the unit never ran, which is the normal case here.
    run systemctl reset-failed authelia.service || true
  fi

  # 4. Secrets, one guard per file.
  ensure_dir "$AUTHELIA_SECRETS_DIR" 0750 "root:$AUTHELIA_GROUP"
  for name in "${AUTHELIA_SECRET_NAMES[@]}"; do
    authelia_secret "$name"
  done
  ensure_dir /var/lib/authelia 0750 "$AUTHELIA_USER:$AUTHELIA_GROUP"

  # 5. Authelia reaches users.yml through /srv/fleet (0750 fleet:fleet) with a traverse-only ACL: no read, no listing.
  if is_dry || [ "$AUTHELIA_USER" != root ]; then
    run setfacl -m "u:$AUTHELIA_USER:x" "$FLEET_SRV_DIR"
  fi
  ensure_dir "$FLEET_SRV_DIR/authelia" 2750 "fleet:$AUTHELIA_GROUP"
  write_file_once "$users_file" 0640 "fleet:$AUTHELIA_GROUP" <<<'"users": {}'
  authelia_assert_no_default_account "$users_file"

  # 6. Point the unit at the secrets.
  ensure_dir "$SYSTEMD_DIR/authelia.service.d" 0755 root:root
  install_etc authelia-secrets.conf "$SYSTEMD_DIR/authelia.service.d/secrets.conf" 0644 root:root
  [ "$FILE_CHANGED" = 0 ] || changed=1
  if [ "$changed" = 1 ]; then
    run systemctl daemon-reload
  fi

  # 7. Prove the mask works now rather than at the first reboot: apply tmpfiles and re-check the secrets directory.
  run systemd-tmpfiles --create --prefix=/etc/authelia
  if ! is_dry; then
    [ "$(stat -c %a "$AUTHELIA_SECRETS_DIR")" = 750 ] || die 1 "$AUTHELIA_SECRETS_DIR lost its mode after systemd-tmpfiles; the tmpfiles mask is not in effect"
  fi
}
