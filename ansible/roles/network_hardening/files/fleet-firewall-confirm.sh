#!/usr/bin/env bash
# Installed by the network_hardening Ansible role. Run as root from a
# SECOND, INDEPENDENT SSH session (spec §3.3) — never the session that
# enabled UFW — to prove the box is still reachable before the dead-man's
# switch's grace period elapses.
set -euo pipefail

MARKER=/etc/ddev-fleet/ufw-deadman-confirmed

systemctl stop fleet-ufw-deadman.timer 2>/dev/null || true
mkdir -p "$(dirname "$MARKER")"
touch "$MARKER"
echo "fleet-firewall-confirm: dead-man's-switch timer stopped; marker written at $MARKER"
