# shellcheck shell=bash
# shellcheck disable=SC2034  # the constants are read by the tests, which source this file
# Step 35: the interactive shell profile (fleet prompt, colours, aliases), skipped with --no-shell-profile.
#   - /etc/profile.d/10-fleet-shell.sh (0644 root:root), the same file for every account;
#   - one guarded hook block that sources it, appended to /etc/bash.bashrc and to the ~/.bashrc of root, the fleet user
#     and the user that ran the installer (sudo), each only when the block is missing. Debian's stock ~/.bashrc sets PS1
#     after the system files, so the hook must come last in each user's file.
# The uninstaller (uninstall.sh) removes the file and exactly this block; keep the two in step (UninstallTest).

SHELL_PROFILE_FILE=/etc/profile.d/10-fleet-shell.sh
SHELL_PROFILE_BASHRC=/etc/bash.bashrc
SHELL_HOOK_BEGIN='# BEGIN ddev-fleet shell profile'
SHELL_HOOK_END='# END ddev-fleet shell profile'

# The hook block, one line per line.
shell_hook_block() {
  printf '%s\n' "$SHELL_HOOK_BEGIN" \
    "if [ -f $SHELL_PROFILE_FILE ]; then" \
    "  . $SHELL_PROFILE_FILE" \
    'fi' \
    "$SHELL_HOOK_END"
}

# The ~/.bashrc files to hook: root, the fleet user and the sudo user, once each.
shell_hook_targets() {
  local homes=(/root "$FLEET_HOME_DIR") user home entry
  user=${SUDO_USER:-}
  if [ -n "$user" ] && [ "$user" != root ] && [ "$user" != "$FLEET_USER" ]; then
    home=$(getent passwd "$user" | cut -d: -f6 || true)
    if [ -n "$home" ] && [ "$home" != / ]; then
      homes+=("$home")
    fi
  fi
  for entry in "${homes[@]}"; do
    printf '%s/.bashrc\n' "$entry"
  done
}

# hook_file <file>: appends the block unless the file already has its first line. A missing file is left alone
# (the account has no bash profile to hook; creating one would also need the right owner).
hook_file() {
  local file=$1
  if is_dry; then
    printf '+ hook %s\n' "$file"
    return 0
  fi
  [ -f "$file" ] || return 0
  if grep -qxF -- "$SHELL_HOOK_BEGIN" "$file"; then
    return 0
  fi
  # Append in place (keeps the owner and mode); start on a fresh line.
  if [ -s "$file" ] && [ -n "$(tail -c 1 "$file")" ]; then
    printf '\n' >>"$file"
  fi
  shell_hook_block >>"$file"
}

step_main() {
  if [ "$SHELL_PROFILE" != 1 ]; then
    log_info "skipped: --no-shell-profile"
    return 0
  fi
  # A file copied by hand before this step existed is replaced without a word; any other foreign content is replaced
  # with a warning.
  if ! is_dry && [ -f "$SHELL_PROFILE_FILE" ] && ! cmp -s "$INSTALL_DIR/etc/profile.d/10-fleet-shell.sh" "$SHELL_PROFILE_FILE"; then
    case $(head -n 1 "$SHELL_PROFILE_FILE") in
      '# Copied from '* | '# Managed by the ddev-fleet installer'*) ;;
      *) log_warn "replacing $SHELL_PROFILE_FILE, which this installer did not write" ;;
    esac
  fi
  install_etc profile.d/10-fleet-shell.sh "$SHELL_PROFILE_FILE" 0644 root:root
  hook_file "$SHELL_PROFILE_BASHRC"
  local target
  while IFS= read -r target; do
    hook_file "$target"
  done < <(shell_hook_targets)
}
