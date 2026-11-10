# shellcheck shell=bash
#
# The Ansible engine (--engine=ansible): writes /etc/ddev-fleet/host-vars.yml from the flags and runs install/ansible/site.yml.
# Sourced by run.sh after lib/common.sh, never executed. The schema is documented in docs/reference/04-installer.md; the app
# renders the same file with App\Fleet\System\HostVarsRenderer (InstallerHostVarsTest keeps the two byte-identical).
#
# host-vars.yml holds the host's own answers and nothing else: the domain, the ACME contact, the shell-profile switch and the
# hardening settings. The release ref and the lock-out strictness are per run, so they travel as -e extra vars, not in the file.

HOST_VARS_FILE_NAME=host-vars.yml

# host_vars_list <key> [item...]: a YAML list at the top level, one quoted item per line; [] when empty.
host_vars_list() {
  local key=$1 item
  shift
  if [ "$#" -eq 0 ]; then
    printf '%s: []\n' "$key"
    return 0
  fi
  printf '%s:\n' "$key"
  for item in "$@"; do
    printf '  - "%s"\n' "$item"
  done
}

# A KEY=value line of the stored hardening settings (empty when the file or the key is missing).
host_vars_stored() {
  local conf=$FLEET_ETC_DIR/hardening.conf
  [ -f "$conf" ] || return 0
  sed -n "s/^$1=//p" "$conf" | tail -n 1
}

# Fills HV_* from the parsed flags and the stored hardening settings: the same merge as the hardening step (99). Hardening is
# on with --harden or when an earlier run stored ENABLED=1; a list flag replaces that list, --harden alone keeps what was stored.
host_vars_resolve() {
  local tok
  HV_HARDEN=0
  HV_USERS=()
  HV_IPS=()
  HV_GRACE=10
  HV_TMP=0
  if [ "$HARDEN" = 1 ] || [ "$(host_vars_stored ENABLED)" = 1 ]; then
    HV_HARDEN=1
  fi
  [ "$HV_HARDEN" = 1 ] || return 0

  if [ "${#HARDEN_SSH_USERS[@]}" -gt 0 ]; then
    HV_USERS=("${HARDEN_SSH_USERS[@]}")
  else
    for tok in $(host_vars_stored SSH_ALLOW_USERS); do HV_USERS+=("$tok"); done
  fi
  if [ "${#HARDEN_TRUSTED_IPS[@]}" -gt 0 ]; then
    HV_IPS=("${HARDEN_TRUSTED_IPS[@]}")
  else
    for tok in $(host_vars_stored TRUSTED_IPS); do HV_IPS+=("$tok"); done
  fi
  HV_GRACE=${HARDEN_GRACE:-$(host_vars_stored FIREWALL_GRACE_MINUTES)}
  HV_GRACE=${HV_GRACE:-10}
  if [ "$HARDEN_TMP" = 1 ] || [ "$(host_vars_stored HARDEN_TMP)" = 1 ]; then HV_TMP=1; fi

  # The stored file is an input too: what ends up in YAML is validated, so no value can add a key or a line.
  for tok in ${HV_USERS[@]+"${HV_USERS[@]}"}; do
    validate_unix_user "$tok" || die "$EX_USAGE" "host vars: '${tok:0:40}' is not a valid user name"
  done
  for tok in ${HV_IPS[@]+"${HV_IPS[@]}"}; do
    validate_ip_or_cidr "$tok" || die "$EX_USAGE" "host vars: '${tok:0:60}' is not an IP address or CIDR"
  done
  validate_grace "$HV_GRACE" || die "$EX_USAGE" "host vars: the firewall grace period must be 2 to 120 minutes"
}

# The text of host-vars.yml on stdout (after host_vars_resolve).
host_vars_render() {
  printf '%s\n' '---'
  printf '%s\n' '# Written by the ddev-fleet installer from its flags. A re-run rewrites it; the hardening part follows the stored settings.'
  printf 'fleet_domain: "%s"\n' "$DOMAIN"
  printf 'fleet_acme_email: "%s"\n' "$ACME_EMAIL"
  if [ "$SHELL_PROFILE" = 1 ]; then
    printf '%s\n' 'fleet_shell_profile: true'
  else
    printf '%s\n' 'fleet_shell_profile: false'
  fi
  if [ "$HV_HARDEN" != 1 ]; then
    printf '%s\n' 'fleet_hardening_enabled: false'
    return 0
  fi
  printf '%s\n' 'fleet_hardening_enabled: true'
  host_vars_list fleet_hardening_ssh_allow_users ${HV_USERS[@]+"${HV_USERS[@]}"}
  host_vars_list fleet_hardening_trusted_ips ${HV_IPS[@]+"${HV_IPS[@]}"}
  printf 'fleet_hardening_firewall_grace_minutes: %s\n' "$((10#$HV_GRACE))"
  if [ "$HV_TMP" = 1 ]; then
    printf '%s\n' 'fleet_hardening_tmp: true'
  else
    printf '%s\n' 'fleet_hardening_tmp: false'
  fi
}

# Writes host-vars.yml (0644 root:root; no secret in it) when its content changed.
write_host_vars() {
  host_vars_resolve
  # The directory is the one the fleet_user role keeps (root:fleet, 0750), so creating it here changes nothing later.
  local group=$FLEET_USER
  [ -n "$SUDO_OWN" ] || group=$(id -gn)
  run install -d -m 0750 -o "$(if [ -n "$SUDO_OWN" ]; then echo root; else id -un; fi)" -g "$group" -- "$FLEET_ETC_DIR"
  write_file "$FLEET_ETC_DIR/$HOST_VARS_FILE_NAME" 0644 root:root < <(host_vars_render)
}

# --- the engine ---------------------------------------------------------------------------------------------------

# ansible-core and python3-apt (the apt module needs it on the target) from Debian's own repository, never pip.
ansible_engine_packages() {
  apt_mark_dirty
  apt_refresh
  apt_get install ansible-core python3-apt
}

# Replaces the numbered steps: host vars, Ansible, the playbook from the root-owned export of install/.
ansible_engine_main() {
  STEP_NAME=ansible-packages
  log_step ansible-packages
  ansible_engine_packages

  STEP_NAME=host-vars
  log_step host-vars
  write_host_vars

  STEP_NAME=ansible-playbook
  log_step ansible-playbook
  # An explicit --harden refuses a lock-out; a stored re-run (an upgrade) only warns, as in the bash installer.
  local strict=false
  [ "$HARDEN" != 1 ] || strict=true
  # The installer runs with LC_ALL=C; Ansible refuses anything but a UTF-8 locale, and C.UTF-8 always exists on Debian 13.
  local -a cmd=(env LC_ALL=C.UTF-8 LANG=C.UTF-8 ANSIBLE_FORCE_COLOR=0 ANSIBLE_NOCOLOR=1 ansible-playbook -i localhost, -c local "$INSTALL_DIR/ansible/site.yml"
    -e "fleet_ref=$REF" -e "fleet_hardening_strict=$strict")
  # The playbook reads the host answers and the roles from the root-owned export; ansible.cfg sits beside site.yml.
  if is_dry; then
    printf '+ (cd %q &&' "$INSTALL_DIR/ansible"
    printf ' %q' "${cmd[@]}"
    printf ')\n'
    return 0
  fi
  (cd "$INSTALL_DIR/ansible" && "${cmd[@]}" </dev/null)
}
