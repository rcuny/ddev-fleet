#!/usr/bin/env bash
# Read-only SSH lock-out preflight (ported from harden_has_key and harden_preflight in install/harden/harden.sh).
#   ssh-preflight.sh has-key <user>        exit 0 when the account has a key line in a file sshd would read
#   ssh-preflight.sh scan <allowed-user>...  prints "nokey:<u>" for allowed accounts without a key and "shut:<u>" for
#                                          other accounts (not root, not fleet) that have keys and are not allowed
set -uo pipefail

has_key() {
  local user=$1 home pat file patterns
  home=$(getent passwd "$user" 2>/dev/null | cut -d: -f6 || true)
  if [ -z "$home" ]; then
    if [ "$user" = root ]; then home=/root; else home=/home/$user; fi
  fi
  patterns=$(sshd -T 2>/dev/null | sed -n 's/^authorizedkeysfile //p' | head -n 1 || true)
  patterns=${patterns:-.ssh/authorized_keys .ssh/authorized_keys2}
  for pat in $patterns; do
    [ "$pat" != none ] || continue
    pat=${pat//%%/%}
    pat=${pat//%h/$home}
    pat=${pat//%u/$user}
    case $pat in
      /*) file=$pat ;;
      *) file=$home/$pat ;;
    esac
    if [ -f "$file" ] && grep -Eq '^[[:space:]]*[^#[:space:]]' "$file" 2>/dev/null; then return 0; fi
  done
  return 1
}

case ${1:-} in
  has-key)
    has_key "$2"
    ;;
  scan)
    shift
    allowed=" $* "
    for u in "$@"; do has_key "$u" || echo "nokey:$u"; done
    for dir in /home/* /root; do
      [ -d "$dir" ] || continue
      other=${dir##*/}
      [[ $other =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || continue
      case $allowed in *" $other "*) continue ;; esac
      case $other in root | fleet) continue ;; esac
      has_key "$other" && echo "shut:$other"
    done
    exit 0
    ;;
  *)
    echo "usage: ssh-preflight.sh has-key <user> | scan <user>..." >&2
    exit 2
    ;;
esac
