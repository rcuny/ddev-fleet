#!/usr/bin/env bash
# Installed by the network_hardening Ansible role. Shared by BOTH triggers
# of the UFW dead-man's switch (spec §3.3):
#   - fleet-ufw-deadman.service, run once by fleet-ufw-deadman.timer
#     fleet_ufw_deadman_grace_minutes after the switch is armed;
#   - fleet-ufw-deadman-bootcheck.service, run on every boot.
# Same script, same logic, both times: only the marker file's presence
# decides the outcome, never *why* this script is being invoked.
set -euo pipefail

MARKER=/etc/ddev-fleet/ufw-deadman-confirmed

if [ -f "$MARKER" ]; then
    echo "fleet-ufw-deadman-fire: confirmation marker present — UFW stays enabled"
    exit 0
fi

echo "fleet-ufw-deadman-fire: no confirmation marker — disabling UFW (fail-safe)"
ufw disable
