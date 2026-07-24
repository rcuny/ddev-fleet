#!/usr/bin/env bash
# Installed by the network_hardening Ansible role. Run as root from a
# SECOND, INDEPENDENT SSH session (spec §3.3) — never the session that
# enabled UFW — to prove the box is still reachable before the dead-man's
# switch's grace period elapses.
set -euo pipefail

MARKER=/etc/ddev-fleet/ufw-deadman-confirmed

# Marker FIRST, then stop the timer. If the timer fires in the window
# between these two operations, the fire script (fleet-ufw-deadman-fire.sh)
# only ever checks the marker's presence to decide whether to disable UFW —
# marker-first means a fire in that window sees the marker and keeps UFW
# enabled (safe). Writing the marker AFTER stopping the timer would instead
# leave a window where a fire sees no marker and disables UFW.
mkdir -p "$(dirname "$MARKER")"
touch "$MARKER"
systemctl stop fleet-ufw-deadman.timer 2>/dev/null || true
echo "fleet-firewall-confirm: marker written at $MARKER; dead-man's-switch timer stopped"
