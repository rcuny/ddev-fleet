#!/usr/bin/env bash
set -euo pipefail

# ddev-fleet bootstrap — spec §3.1. Does ONLY: install git+ansible, clone or
# update /opt/ddev-fleet, install the Ansible Galaxy collections it needs,
# and run the provisioning playbook. Idempotent: re-running this script
# after the first successful run is a safe upgrade (git pull + re-apply).
#
# Usage:
#   curl -fsSL https://bitbucket.org/personal_maintainer/ddev-fleet/raw/main/bootstrap.sh | sudo bash

# NOTE: HTTPS default — at bootstrap time no SSH deploy key exists yet.
# Ways to get the code onto the box:
#   - Public/authenticated git: leave FLEET_REPO_URL default or export an
#     https app-password URL — the script clones/pulls it.
#   - PRIVATE repo, no server-side git auth (recommended here): deliver a
#     clean checkout out-of-band (e.g. rsync a `git archive` export to
#     ${FLEET_OPT_DIR}) and run with FLEET_SKIP_FETCH=1 — the git step is
#     then skipped entirely and the code already on disk is used as-is.
FLEET_REPO_URL="${FLEET_REPO_URL:-https://bitbucket.org/personal_maintainer/ddev-fleet.git}"
FLEET_OPT_DIR="${FLEET_OPT_DIR:-/opt/ddev-fleet}"
FLEET_SKIP_FETCH="${FLEET_SKIP_FETCH:-}"

echo "==> Installing git and ansible"
apt-get update -y
apt-get install -y git ansible

if [ -n "${FLEET_SKIP_FETCH}" ]; then
  echo "==> FLEET_SKIP_FETCH set — using code already present at ${FLEET_OPT_DIR} (no git clone/pull)"
  if [ ! -f "${FLEET_OPT_DIR}/ansible/site.yml" ]; then
    echo "ERROR: FLEET_SKIP_FETCH set but ${FLEET_OPT_DIR}/ansible/site.yml is missing." >&2
    echo "       Deliver a clean checkout first, e.g. rsync a 'git archive' export to ${FLEET_OPT_DIR}." >&2
    exit 1
  fi
elif [ -d "${FLEET_OPT_DIR}/.git" ]; then
  echo "==> ${FLEET_OPT_DIR} already exists — pulling latest"
  git -C "${FLEET_OPT_DIR}" pull --ff-only
else
  echo "==> Cloning ${FLEET_REPO_URL} into ${FLEET_OPT_DIR}"
  git clone "${FLEET_REPO_URL}" "${FLEET_OPT_DIR}"
fi

echo "==> Installing Ansible Galaxy collections"
ansible-galaxy collection install -r "${FLEET_OPT_DIR}/ansible/requirements.yml"

echo "==> Running the provisioning playbook"
if [ -n "${FLEET_SKIP_FETCH}" ]; then
  # Also tell the playbook not to git-fetch the product repo (fleet_service
  # role) — the code is already on disk, delivered out-of-band.
  ansible-playbook -c local -e fleet_skip_fetch=true "${FLEET_OPT_DIR}/ansible/site.yml"
else
  ansible-playbook -c local "${FLEET_OPT_DIR}/ansible/site.yml"
fi

echo
echo "==> Provisioning complete."
if [ -f /srv/fleet/fleet-deploy-key.pub ]; then
  echo "Fleet deploy public key:"
  cat /srv/fleet/fleet-deploy-key.pub
else
  echo "Fleet deploy public key not found yet at /srv/fleet/fleet-deploy-key.pub"
fi
echo
echo "Next steps (see docs/runbook-server-rollout.md for the full checklist):"
echo "  1. Add the deploy key above as a READ-ONLY deploy key on each git forge."
echo "  2. Rotate the dashboard admin password off the shipped default (admin/ddev-admin):"
echo "     sudo -u fleet fleet rotate-admin-password   # no re-run of this script needed"
echo "  3. Point DNS: fleet.<domain> and *.fleet.<domain> at this server's IP."
echo "  4. Run 'fleet init' as the fleet user to mint the Claude Code OAuth token."
