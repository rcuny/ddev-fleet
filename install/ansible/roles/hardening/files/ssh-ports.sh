#!/usr/bin/env bash
# Prints the SSH daemon's listening ports, space separated (22 when it cannot be asked). Read-only.
# Ported from harden_ssh_ports in install/harden/harden.sh.
set -uo pipefail
ports=''
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
