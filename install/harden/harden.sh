# shellcheck shell=bash
# shellcheck disable=SC2034  # settings below are read by the functions of this file
#
# Opt-in host hardening (FLE-211): sourced by install/steps/99-harden.sh, and ONLY when `--harden` was given or an
# earlier run left ENABLED=1 in /etc/ddev-fleet/hardening.conf. A plain install or upgrade never gets here.
#
# The only place in the installer that touches the SSH daemon, the firewall or the login-ban daemon; InstallerSafetyTest
# allows exactly this directory and nothing else. Ported from the 0.x Ansible roles `security_hardening` and
# `network_hardening` (what to configure), re-implemented for the 1.x layout (no Python, no fleet.yml). Not ported:
# Docker daemon.json (live-restore restarts Docker, dangerous on CI hosts: its own ticket), msmtp and reboot-notify
# (they belong to the 0.x CLI) and unattended-upgrades (already enabled on 1.x hosts).
#
# What it does, in this order, each part idempotent (a re-run converges, and an unchanged file is not rewritten):
#   1. SSH: a drop-in with key-only logins, MaxAuthTries 3 and AllowUsers. Refused beforehand when an allowed account has
#      no usable key, or when another account that has keys is not in the list; checked with `sshd -t` (the drop-in
#      alone, then the whole configuration) before the daemon is reloaded (a reload never ends a running session) and
#      with `sshd -T` afterwards (the drop-in must be the effective configuration).
#   2. fail2ban: the sshd jail with explicit maxretry/findtime/bantime and the trusted addresses in ignoreip.
#   3. LLMNR off (systemd-resolved drop-in).
#   4. sysctl drop-in, auditd with scoped rules; /tmp nosuid,nodev,noexec only with the separate `--harden-tmp`.
#   5. The firewall (UFW, chosen over bare nftables because Debian ships it, the 0.x hosts have run it for months and
#      it carries the DOCKER-USER guard in its own after.rules): deny incoming except SSH, 80, 443, the trusted
#      addresses and the named ports Fleet reports (`fleet:port:firewall --json`); a minute timer keeps those in step
#      (fleet-firewall-sync); the dead-man's switch turns the firewall OFF again after the grace period (10 minutes by
#      default) unless `sudo fleet-firewall-confirm` is run from a second SSH session.
#
# Dry-run: every write goes through write_file/ensure_dir/run, which print instead of acting; probes only read.

H_CONF_NAME=hardening.conf

# Paths: the real ones, moved under FLEET_ROOT_PREFIX in the unprivileged test harness (common.sh clears the prefix for root).
hpath() {
  printf '%s%s' "${FLEET_ROOT_PREFIX%/}" "$1"
}

harden_paths() {
  H_CONF=$FLEET_ETC_DIR/$H_CONF_NAME
  H_SBIN=$(hpath /usr/local/sbin)
  H_STATE_DIR=$(hpath /var/lib/ddev-fleet)
  H_SSH_DIR=$(hpath /etc/ssh/sshd_config.d)
  H_F2B_DIR=$(hpath /etc/fail2ban/jail.d)
  H_SYSCTL_DIR=$(hpath /etc/sysctl.d)
  H_AUDIT_DIR=$(hpath /etc/audit/rules.d)
  H_RESOLVED_DIR=$(hpath /etc/systemd/resolved.conf.d)
  H_TMPMOUNT_DIR=$SYSTEMD_DIR/tmp.mount.d
  H_UFW_AFTER=$(hpath /etc/ufw/after.rules)
  H_MARKER=$FLEET_ETC_DIR/firewall-confirmed
  H_DDEV_GLOBAL=$FLEET_HOME_DIR/.ddev/global_config.yaml
}

# install/harden/etc/<file> -> <destination>
install_etc_harden() {
  write_file "$2" "$3" "$4" <"$INSTALL_DIR/harden/etc/$1"
}

# KEY from the settings file ("" when absent).
harden_conf_get() {
  [ -f "$H_CONF" ] || return 0
  sed -n "s/^$1=//p" "$H_CONF" | head -n 1 | tr -d '"\r'
}

# The user who runs the installer over SSH: the one who called sudo, else whoever we are.
harden_invoking_user() {
  if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != root ]; then
    printf '%s\n' "$SUDO_USER"
  else
    id -un
  fi
}

# --- settings ---------------------------------------------------------------------------------------------------

# Fills H_USERS, H_IPS and H_GRACE from the flags of this run, else from the settings file, else the defaults.
# A list flag replaces that list; `--harden` alone keeps what the last run stored (so a bare upgrade never forgets a
# trusted address and gets the operator banned).
harden_resolve() {
  local invoking tok
  invoking=$(harden_invoking_user)

  H_USERS=()
  H_IPS=()
  if [ "${#HARDEN_SSH_USERS[@]}" -gt 0 ]; then
    H_USERS=("${HARDEN_SSH_USERS[@]}")
  else
    for tok in $(harden_conf_get SSH_ALLOW_USERS); do H_USERS+=("$tok"); done
  fi
  if [ "${#HARDEN_TRUSTED_IPS[@]}" -gt 0 ]; then
    H_IPS=("${HARDEN_TRUSTED_IPS[@]}")
  else
    for tok in $(harden_conf_get TRUSTED_IPS); do H_IPS+=("$tok"); done
  fi
  [ "${#H_USERS[@]}" -gt 0 ] || H_USERS=("$invoking")
  H_GRACE=${HARDEN_GRACE:-$(harden_conf_get FIREWALL_GRACE_MINUTES)}
  H_GRACE=${H_GRACE:-10}
  # /tmp is its own opt-in: the flag, else what an earlier run stored.
  H_TMP=0
  if [ "${HARDEN_TMP:-0}" = 1 ] || [ "$(harden_conf_get HARDEN_TMP)" = 1 ]; then H_TMP=1; fi
  # What the last run stored for the firewall inputs: a change re-arms the dead-man's switch (harden_firewall).
  H_OLD_PORTS=$(harden_conf_get SSH_PORTS)
  H_OLD_IPS=$(harden_conf_get TRUSTED_IPS)

  # The stored file is an input too: refuse anything parse_args would not have accepted.
  for tok in "${H_USERS[@]}"; do
    validate_unix_user "$tok" || die "$EX_USAGE" "hardening: '${tok:0:40}' is not a valid user name"
  done
  for tok in "${H_IPS[@]}"; do
    validate_ip_or_cidr "$tok" || die "$EX_USAGE" "hardening: '${tok:0:60}' is not an IP address or CIDR"
  done
  validate_grace "$H_GRACE" || die "$EX_USAGE" "hardening: the firewall grace period must be 2 to 120 minutes"

  # Session safety: the account this run came in with must stay able to log in. Checked on an explicit --harden only;
  # a stored re-run (an upgrade started by another account) does not change who may log in.
  if [ "${HARDEN:-0}" = 1 ]; then
    in_list "$invoking" "$(printf '%s\n' "${H_USERS[@]}")" ||
      die "$EX_USAGE" "hardening would lock you out: the account you are using ($invoking) is not in the SSH allow list (${H_USERS[*]}). Add --ssh-allow-user=$invoking"
  fi
}

# The SSH daemon's listening ports (space separated), 22 when it cannot be asked.
harden_ssh_ports() {
  local ports=''
  if command -v sshd >/dev/null 2>&1; then
    ports=$(sshd -T 2>/dev/null | awk '$1 == "port" && $2 ~ /^[0-9]+$/ {print $2}' | sort -un | tr '\n' ' ' || true)
  fi
  # Socket-activated sshd (Debian 13): the listeners come from ssh.socket, which `sshd -T` does not show.
  if systemctl is-active --quiet ssh.socket 2>/dev/null; then
    ports="$ports $(systemctl show ssh.socket -p Listen --value 2>/dev/null | grep -Eo ':[0-9]+ ' | tr -d ': ' | sort -un | tr '\n' ' ' || true)"
    ports=$(printf '%s\n' $ports | sort -un | tr '\n' ' ')
  fi
  ports=${ports:-22}
  printf '%s\n' "${ports% }"
}

# Refuse (real run) or announce the refusal (dry-run, which must show everything and change nothing).
harden_refuse() {
  if is_dry; then
    log_warn "(dry-run) this run would be refused: $1"
  else
    die "$EX_USAGE" "$1"
  fi
}

# The directory of an account's home as the SSH daemon sees it.
harden_home_of() {
  local home=
  home=$(getent passwd "$1" 2>/dev/null | cut -d: -f6 || true)
  if [ -z "$home" ]; then
    if [ "$1" = root ]; then home=/root; else home=/home/$1; fi
  fi
  hpath "$home"
}

# Succeeds when the account has at least one key line in a file the daemon would read (AuthorizedKeysFile, with %h %u %%).
harden_has_key() {
  local user=$1 home pat file patterns
  home=$(harden_home_of "$user")
  patterns=$(sshd -T 2>/dev/null | sed -n 's/^authorizedkeysfile //p' | head -n 1 || true)
  patterns=${patterns:-.ssh/authorized_keys .ssh/authorized_keys2}
  for pat in $patterns; do
    [ "$pat" != none ] || continue
    pat=${pat//%%/%}
    pat=${pat//%h/${home#"${FLEET_ROOT_PREFIX%/}"}}
    pat=${pat//%u/$user}
    case $pat in
      /*) file=$(hpath "$pat") ;;
      *) file=$home/$pat ;;
    esac
    if [ -f "$file" ] && grep -Eq '^[[:space:]]*[^#[:space:]]' "$file" 2>/dev/null; then return 0; fi
  done
  return 1
}

# Lock-out preflight: every allowed account needs a key (at least the one you came in with), and no other account that
# has a key may be shut out silently (colleagues on a shared host).
harden_preflight() {
  local invoking u other dir missing=() shut=()
  invoking=$(harden_invoking_user)
  # Only on an explicit --harden: a stored re-run (an upgrade started by another account) changes nobody's access.
  if [ "${HARDEN:-0}" = 1 ] && ! harden_has_key "$invoking"; then
    harden_refuse "key-only SSH would lock you out: the account you are using ($invoking) has no usable public key in its authorized_keys. Add one first (ssh-copy-id), then run again"
  fi
  for u in "${H_USERS[@]}"; do
    harden_has_key "$u" || missing+=("$u")
  done
  [ "${#missing[@]}" -eq 0 ] || log_warn "these allowed accounts have no authorized key and will not be able to log in: ${missing[*]}"
  for dir in "$(hpath /home)"/* "$(hpath /root)"; do
    [ -d "$dir" ] || continue
    other=${dir##*/}
    validate_unix_user "$other" || continue
    in_list "$other" "$(printf '%s\n' "${H_USERS[@]}")" && continue
    harden_has_key "$other" && shut+=("$other")
  done
  if [ "${#shut[@]}" -gt 0 ]; then
    if [ "${HARDEN:-0}" = 1 ]; then
      harden_refuse "AllowUsers would lock out other accounts that have SSH keys: ${shut[*]}. List every account that must keep access, e.g. --ssh-allow-user=${shut[0]}"
    else
      log_warn "accounts with SSH keys that the allow list excludes: ${shut[*]}"
    fi
  fi
}

# After the reload: the drop-in must be what the daemon really uses (a missing Include or an earlier file would win).
harden_ssh_verify() {
  local invoking eff want
  invoking=$(harden_invoking_user)
  eff=$(sshd -T -C "user=$invoking,host=localhost,addr=127.0.0.1" 2>/dev/null || sshd -T 2>/dev/null || true)
  want=0
  printf '%s\n' "$eff" | grep -qx 'passwordauthentication no' || want=1
  printf '%s\n' "$eff" | grep -qx 'maxauthtries 3' || want=1
  printf '%s\n' "$eff" | grep -Eq '^allowusers ' || want=1
  if [ "$want" = 1 ]; then
    log_warn "SSH hardening is NOT effective: sshd -T does not show passwordauthentication no, maxauthtries 3 and allowusers. Check that /etc/ssh/sshd_config includes sshd_config.d and that no earlier file overrides $H_SSH_DIR/10-fleet-hardening.conf"
    add_final_note "WARNING: the SSH hardening drop-in is not the effective configuration (sshd -T). Investigate before relying on it."
  fi
}

# --- 1. SSH -------------------------------------------------------------------------------------------------------

harden_ssh() {
  local dropin=$H_SSH_DIR/10-fleet-hardening.conf content tmp old=''
  content="# Managed by ddev-fleet host hardening (install --harden); edits are overwritten on the next run.
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
MaxAuthTries 3
AllowUsers ${H_USERS[*]}"
  ensure_dir "$H_SSH_DIR" 0755 root:root
  if is_dry; then
    printf '+ sshd -t -f <new drop-in>, then sshd -t on the whole configuration\n'
    write_file "$dropin" 0644 root:root <<<"$content"
    printf '+ systemctl try-reload-or-restart ssh (a reload keeps running sessions)\n'
    return 0
  fi
  command -v sshd >/dev/null 2>&1 || die 1 "the SSH server is not installed; nothing was changed"
  tmp=$(mktemp)
  printf '%s\n' "$content" >"$tmp"
  if ! sshd -t -f "$tmp"; then
    rm -f "$tmp"
    die 1 "the SSH drop-in does not pass sshd -t; nothing was changed"
  fi
  rm -f "$tmp"
  [ ! -f "$dropin" ] || old=$(cat "$dropin")
  write_file "$dropin" 0644 root:root <<<"$content"
  [ "$FILE_CHANGED" = 1 ] || { harden_ssh_verify; return 0; }
  if ! sshd -t; then
    # Put the previous drop-in back before anything reloads.
    if [ -n "$old" ]; then printf '%s\n' "$old" >"$dropin"; else rm -f "$dropin"; fi
    die 1 "the SSH configuration does not pass sshd -t with the new drop-in; the previous state was restored"
  fi
  run systemctl try-reload-or-restart ssh
  harden_ssh_verify
}

# --- 2. fail2ban ----------------------------------------------------------------------------------------------------

harden_login_bans() {
  local ignore='127.0.0.1/8 ::1' here=
  [ "${#H_IPS[@]}" -eq 0 ] || ignore="$ignore ${H_IPS[*]}"
  # The address this run came in from is never banned (until the next run from elsewhere); sudo may hide it.
  here=${SSH_CONNECTION:-}
  here=${here%% *}
  if [ -n "$here" ] && validate_ip_or_cidr "$here" && ! in_list "$here" "$(printf '%s\n' "${H_IPS[@]}")"; then
    ignore="$ignore $here"
    log_info "fail2ban ignores your current address ($here) for this run; use --trusted-ip to keep it"
  fi
  ensure_dir "$H_F2B_DIR" 0755 root:root
  write_file "$H_F2B_DIR/fleet-sshd.conf" 0644 root:root <<FLEETEOF
# Managed by ddev-fleet host hardening (install --harden); edits are overwritten on the next run.
[DEFAULT]
ignoreip = $ignore

[sshd]
enabled = true
backend = systemd
maxretry = 5
findtime = 10m
bantime = 1h
FLEETEOF
  if [ "$FILE_CHANGED" = 1 ]; then
    run systemctl enable fail2ban
    run systemctl restart fail2ban
  else
    run systemctl enable --now fail2ban
  fi
}

# --- 3 and 4. LLMNR, sysctl, auditd, /tmp -----------------------------------------------------------------------------

harden_llmnr() {
  ensure_dir "$H_RESOLVED_DIR" 0755 root:root
  install_etc_harden resolved-llmnr.conf "$H_RESOLVED_DIR/fleet-llmnr.conf" 0644 root:root
  if [ "$FILE_CHANGED" = 1 ] && { is_dry || systemctl is-active --quiet systemd-resolved; }; then
    run systemctl restart systemd-resolved
  fi
}

harden_kernel_and_audit() {
  ensure_dir "$H_SYSCTL_DIR" 0755 root:root
  install_etc_harden sysctl.conf "$H_SYSCTL_DIR/60-fleet-hardening.conf" 0644 root:root
  if [ "$FILE_CHANGED" = 1 ]; then
    run sysctl -q -p "$H_SYSCTL_DIR/60-fleet-hardening.conf"
  fi

  ensure_dir "$H_AUDIT_DIR" 0750 root:root
  install_etc_harden audit.rules "$H_AUDIT_DIR/50-fleet.rules" 0640 root:root
  if [ "$FILE_CHANGED" = 1 ]; then
    # The rule set may be locked (-e 2) until the next boot: that is not a failure of this run.
    if is_dry; then
      printf '+ augenrules --load (a locked rule set takes the new rules at the next boot)\n'
    else
      augenrules --load </dev/null || log_warn "auditd refused the new rules now (locked rule set?); they apply at the next boot"
    fi
  fi
  run systemctl enable --now auditd
}

# /tmp: only with --harden-tmp (stored as HARDEN_TMP=1), and only when /tmp is already its own mount point. The override
# keeps the options systemd has now (Debian's size= and nr_inodes= included) and adds the missing nosuid,nodev,noexec.
# Takes effect at the next boot, with no dead-man protection: it can break anything that runs a file from /tmp (composer,
# npm, Playwright, DDEV helpers). Undo: delete the override file and reboot.
harden_tmp() {
  local target= opts= o
  if [ "$H_TMP" != 1 ]; then
    log_info "/tmp: left alone (opt in with --harden-tmp; docs/installation.md section 8)"
    return 0
  fi
  if is_dry; then
    printf '+ findmnt -no TARGET /tmp (only an existing /tmp mount point is touched), keep its options, add nosuid,nodev,noexec\n'
  else
    target=$(findmnt -no TARGET /tmp 2>/dev/null || true)
    if [ "$target" != /tmp ]; then
      log_warn "/tmp is not its own mount point (findmnt: '${target:-n/a}'): leaving it alone rather than risking the build pipeline"
      return 0
    fi
    opts=$(systemctl show tmp.mount -p Options --value 2>/dev/null || true)
    opts=${opts:-$(findmnt -no OPTIONS /tmp 2>/dev/null | sed 's/\<rw,\?//;s/\<relatime,\?//' || true)}
    [ -n "$opts" ] || {
      log_warn "cannot read the current /tmp mount options: leaving /tmp alone"
      return 0
    }
  fi
  for o in nosuid nodev noexec; do
    case ",$opts," in *",$o,"*) ;; *) opts="${opts:+$opts,}$o" ;; esac
  done
  ensure_dir "$H_TMPMOUNT_DIR" 0755 root:root
  write_file "$H_TMPMOUNT_DIR/override.conf" 0644 root:root <<FLEETEOF
# Installed by ddev-fleet host hardening (install --harden --harden-tmp). Takes effect at the next boot.
# Undo: delete this file and reboot (or systemctl daemon-reload and remount /tmp).
[Mount]
Options=${opts:-<current options>}
FLEETEOF
  if [ "$FILE_CHANGED" = 1 ]; then
    run systemctl daemon-reload
    log_info "/tmp gets nosuid,nodev,noexec at the next boot (no dead-man protection: see docs/installation.md section 8)"
  fi
}

# --- 5. firewall ----------------------------------------------------------------------------------------------------

# The DOCKER-USER guard block of /etc/ufw/after.rules (textually first, its own *filter stanza: iptables-restore applies
# several stanzas of one table in turn, so this appends to the chain Docker created). Same rules as the 0.x role.
harden_guard_block() {
  cat <<'FLEETEOF'
# BEGIN ddev-fleet DOCKER-USER guard
*filter
:DOCKER-USER - [0:0]
-A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
-A DOCKER-USER -i docker0 -o docker0 -j ACCEPT
-A DOCKER-USER -j RETURN
COMMIT
# END ddev-fleet DOCKER-USER guard
FLEETEOF
}

# after.rules with our block as the first thing in the file (any earlier copy of it removed first).
harden_after_rules_content() {
  local file=$1
  harden_guard_block
  if [ -f "$file" ]; then
    sed '/^# BEGIN ddev-fleet DOCKER-USER guard$/,/^# END ddev-fleet DOCKER-USER guard$/d' "$file"
  fi
}

# The threat model of the guard is that DDEV's router listens on loopback only. Refuse when it does not.
harden_assert_loopback_router() {
  if [ -f "$H_DDEV_GLOBAL" ] && grep -Eq '^router_bind_all_interfaces:[[:space:]]*true' "$H_DDEV_GLOBAL"; then
    die 1 "DDEV's router listens on all interfaces ($H_DDEV_GLOBAL: router_bind_all_interfaces: true); the Docker guard assumes loopback only. Not enabling the firewall"
  fi
}

harden_firewall_active() {
  ufw status 2>/dev/null | grep -q '^Status: active'
}

harden_firewall() {
  local sshp ip p staged content armed=0 changed=0
  sshp=$(harden_ssh_ports)
  harden_assert_loopback_router

  ensure_dir "$(dirname "$H_UFW_AFTER")" 0755 root:root
  ensure_dir "$H_SBIN" 0755 root:root
  ensure_dir "$H_STATE_DIR" 0755 root:root
  ensure_dir "$SYSTEMD_DIR" 0755 root:root
  install_etc_harden firewall-sync "$H_SBIN/fleet-firewall-sync" 0750 root:root
  install_etc_harden firewall-confirm "$H_SBIN/fleet-firewall-confirm" 0750 root:root
  install_etc_harden firewall-deadman-fire "$H_SBIN/fleet-firewall-deadman-fire" 0750 root:root

  # Allows first (SSH before anything), the default policies last: on an already active firewall `default deny incoming`
  # would otherwise take effect before the SSH allow exists.
  for p in $sshp; do run ufw allow "$p/tcp"; done
  run ufw allow 80/tcp
  run ufw allow 443/tcp
  for ip in "${H_IPS[@]}"; do run ufw allow from "$ip"; done
  run ufw default allow outgoing
  run ufw default deny incoming

  if is_dry; then
    printf '+ write %s (the DOCKER-USER guard block first)\n' "$H_UFW_AFTER"
    printf '+ ufw reload (only if the guard changed and the firewall is active)\n'
    printf '+ refuse unless the SSH port(s) (%s) are staged in `ufw show added`\n' "$sshp"
  else
    content=$(harden_after_rules_content "$H_UFW_AFTER")
    if [ ! -f "$H_UFW_AFTER" ] || [ "$content" != "$(cat "$H_UFW_AFTER")" ]; then
      write_file "$H_UFW_AFTER" 0640 root:root <<<"$content"
      if harden_firewall_active; then run ufw reload; fi
    fi
    staged=$(ufw show added 2>/dev/null || true)
    for p in $sshp; do
      printf '%s\n' "$staged" | grep -Eq "(^|[^0-9])$p([^0-9]|$)" ||
        die 1 "the SSH port $p is not staged in the firewall rules; refusing to enable the firewall"
    done
  fi

  install_etc_harden firewall-deadman.service "$SYSTEMD_DIR/fleet-firewall-deadman.service" 0644 root:root
  install_etc_harden firewall-deadman-bootcheck.service "$SYSTEMD_DIR/fleet-firewall-deadman-bootcheck.service" 0644 root:root
  write_file "$SYSTEMD_DIR/fleet-firewall-deadman.timer" 0644 root:root <<FLEETEOF
# Managed by ddev-fleet host hardening (install --harden). Fires once, $H_GRACE minutes after it is started.
[Unit]
Description=ddev-fleet firewall dead-man's switch timer ($H_GRACE minutes)

[Timer]
OnActiveSec=${H_GRACE}min
Unit=fleet-firewall-deadman.service
FLEETEOF
  install_etc_harden firewall-sync.service "$SYSTEMD_DIR/fleet-firewall-sync.service" 0644 root:root
  install_etc_harden firewall-sync.timer "$SYSTEMD_DIR/fleet-firewall-sync.timer" 0644 root:root
  run systemctl daemon-reload

  # Named ports are staged while the firewall may still be off ("ufw allow" is accepted then), so they are live the
  # moment it is enabled. Not fatal: the timer retries every minute, and Fleet may not be set up yet on a fresh host.
  if is_dry; then
    printf '+ %s/fleet-firewall-sync (named ports from `fleet:port:firewall --json`)\n' "$H_SBIN"
  else
    "$H_SBIN/fleet-firewall-sync" || log_warn "the named-port sync failed; the timer retries every minute"
  fi

  if is_dry; then
    printf '+ ufw --force enable (only if it is not active yet)\n'
  elif ! harden_firewall_active; then
    # (Re-)enabling always re-arms the switch: an earlier confirmation proved another firewall state.
    run rm -f "$H_MARKER"
    run ufw --force enable
    armed=1
  elif [ -f "$H_MARKER" ]; then
    # Already active and confirmed: a change of SSH ports or trusted addresses needs a new confirmation.
    if [ "$(printf '%s\n' $sshp | sort | tr '\n' ' ')" != "$(printf '%s\n' $H_OLD_PORTS | sort | tr '\n' ' ')" ] ||
      [ "$(printf '%s\n' "${H_IPS[@]}" | sort | tr '\n' ' ')" != "$(printf '%s\n' $H_OLD_IPS | sort | tr '\n' ' ')" ]; then
      changed=1
      run rm -f "$H_MARKER"
      armed=1
    fi
  fi

  # The boot check turns the firewall off whenever the marker is missing, so it is enabled only while the switch is
  # armed or an earlier arm was confirmed.
  if is_dry; then
    printf '+ arm the dead-man switch (start fleet-firewall-deadman.timer, enable the boot check) on a first enable, unless %s exists\n' "$H_MARKER"
  elif [ "$armed" = 1 ] || [ -f "$H_MARKER" ]; then
    run systemctl enable fleet-firewall-deadman-bootcheck.service
  else
    run systemctl disable fleet-firewall-deadman-bootcheck.service
  fi
  if [ "$armed" = 1 ]; then
    [ "$changed" = 0 ] || log_info "SSH ports or trusted addresses changed: the dead-man's switch is armed again"
    run systemctl start fleet-firewall-deadman.timer
    add_final_note "FIREWALL ENABLED with a dead-man's switch: it turns the firewall OFF again in $H_GRACE minutes unless you confirm. From a SECOND, independent SSH session run:  sudo $H_SBIN/fleet-firewall-confirm"
  fi
  run systemctl enable --now fleet-firewall-sync.timer
}

# --- settings file --------------------------------------------------------------------------------------------------

harden_persist() {
  write_file "$H_CONF" 0644 root:root <<FLEETEOF
# Written by the installer (install --harden). Read back as plain KEY=value lines on every run: while ENABLED=1 an
# upgrade re-applies these settings. Change them by re-running the installer with --harden and the flags you want.
ENABLED=1
SSH_ALLOW_USERS=${H_USERS[*]}
TRUSTED_IPS=${H_IPS[*]:-}
FIREWALL_GRACE_MINUTES=$H_GRACE
SSH_PORTS=$(harden_ssh_ports)
HARDEN_TMP=$H_TMP
FLEETEOF
}

harden_main() {
  harden_paths
  harden_resolve
  harden_preflight
  log_info "hardening: SSH users: ${H_USERS[*]}; trusted addresses: ${H_IPS[*]:-none}; firewall grace: ${H_GRACE} min"
  # Packages first: the later parts need their binaries.
  apt_install ufw fail2ban python3-systemd auditd
  ensure_dir "$FLEET_ETC_DIR" 0755 root:root
  harden_persist
  harden_ssh
  harden_login_bans
  harden_llmnr
  harden_kernel_and_audit
  harden_tmp
  harden_firewall
}
