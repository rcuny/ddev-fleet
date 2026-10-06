#!/usr/bin/env bash
# FLE-8: live systemd security check, run by the `systemd-security-live` custom
# pipeline (bitbucket-pipelines.yml) on a weekly Bitbucket schedule.
#
# SECURITY_PROBE_TARGETS: space-separated `name=user@host` entries, e.g.
#   ddev3=fleet-probe@ddev3.fleet.pm
# Each host's `fleet-probe` user has a forced command (security_probe Ansible
# role): whatever we ask over SSH, it prints the JSON report and nothing else.
# Every target is checked before the script fails, so one broken host cannot
# hide a regression on another.
#
# Writes reports/<name>.json (raw report), reports/systemd-security-<name>.md
# and .json (comparison with ci/systemd-security-baseline.json). The pipeline
# publishes reports/** as artifacts.
set -euo pipefail

targets="${SECURITY_PROBE_TARGETS:-}"
if [ -z "${targets// /}" ]; then
  echo "SECURITY_PROBE_TARGETS not set, skipping the live systemd security check"
  exit 0
fi

key=/opt/atlassian/pipelines/agent/ssh/id_rsa
known_hosts=/opt/atlassian/pipelines/agent/ssh/known_hosts

ssh_opts=(-T -o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=yes)
if [ -f "$key" ]; then
  ssh_opts+=(-i "$key")
fi
if [ -f "$known_hosts" ]; then
  ssh_opts+=(-o "UserKnownHostsFile=$known_hosts")
fi

mkdir -p reports
failed=0
for entry in $targets; do
  name="${entry%%=*}"
  dest="${entry#*=}"
  if [ -z "$name" ] || [ -z "$dest" ] || [ "$name" = "$entry" ]; then
    echo "ERROR: bad SECURITY_PROBE_TARGETS entry '$entry' (expected name=user@host)" >&2
    failed=1
    continue
  fi
  echo "== $name ($dest)"
  # The command word is ignored by the forced command; it only has to be non-empty.
  if ! ssh "${ssh_opts[@]}" "$dest" fleet-security-report > "reports/$name.json"; then
    echo "ERROR: could not fetch the security report from $name ($dest)" >&2
    failed=1
    continue
  fi
  if ! python ci/systemd_security.py compare --env "$name" --current "reports/$name.json" \
    --report-md "reports/systemd-security-$name.md" \
    --report-json "reports/systemd-security-$name.json"; then
    failed=1
  fi
done
exit "$failed"
