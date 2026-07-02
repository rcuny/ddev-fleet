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
# If the repo is PRIVATE: export FLEET_REPO_URL with an https app-password
# URL, or pre-clone /opt/ddev-fleet manually before running this script.
FLEET_REPO_URL="${FLEET_REPO_URL:-https://bitbucket.org/personal_maintainer/ddev-fleet.git}"
FLEET_OPT_DIR="${FLEET_OPT_DIR:-/opt/ddev-fleet}"

echo "==> Installing git and ansible"
apt-get update -y
apt-get install -y git ansible

if [ -d "${FLEET_OPT_DIR}/.git" ]; then
  echo "==> ${FLEET_OPT_DIR} already exists — pulling latest"
  git -C "${FLEET_OPT_DIR}" pull --ff-only
else
  echo "==> Cloning ${FLEET_REPO_URL} into ${FLEET_OPT_DIR}"
  git clone "${FLEET_REPO_URL}" "${FLEET_OPT_DIR}"
fi

echo "==> Installing Ansible Galaxy collections"
ansible-galaxy collection install -r "${FLEET_OPT_DIR}/ansible/requirements.yml"

echo "==> Running the provisioning playbook"
ansible-playbook -c local "${FLEET_OPT_DIR}/ansible/site.yml"

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
echo "  2. Generate the admin password hash: caddy hash-password"
echo "     then set fleet_admin_bcrypt_hash in ${FLEET_OPT_DIR}/ansible/group_vars/all.yml"
echo "     and re-run this script."
echo "  3. Point DNS: fleet.<domain> and *.fleet.<domain> at this server's IP."
echo "  4. Run 'fleet init' as the fleet user to mint the Claude Code OAuth token."
