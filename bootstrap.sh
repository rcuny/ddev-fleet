#!/usr/bin/env bash
set -euo pipefail

# ddev-fleet bootstrap — spec §3.1. Does ONLY: install git+ansible, clone or
# update /opt/ddev-fleet, install the Ansible Galaxy collections it needs,
# and run the provisioning playbook. Idempotent: re-running this script
# after the first successful run is a safe upgrade (git pull + re-apply).
#
# Usage (public one-liner):
#   curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/main/bootstrap.sh | sudo bash

# --- 1. Root check -----------------------------------------------------------
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: bootstrap.sh must run as root (it installs system packages and" >&2
  echo "       systemd units). Re-run with sudo, e.g.:" >&2
  echo "       curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/main/bootstrap.sh | sudo bash" >&2
  exit 1
fi

# --- 2. OS detection (warn-and-continue, not hard-block) --------------------
_tty_openable() {
  # `[ -r /dev/tty ]` only checks the device node's permission bits, not
  # whether opening it will actually succeed — under a real headless run
  # (no controlling terminal) it reads TRUE and the subsequent
  # `read < /dev/tty` then fails with ENXIO. Actually try to open it.
  { : < /dev/tty; } 2>/dev/null
}

FLEET_FORCE_OS="${FLEET_FORCE_OS:-}"
if [ -r /etc/os-release ]; then
  # shellcheck disable=SC1091  # dynamic host file, nothing to statically follow
  . /etc/os-release
else
  ID="unknown"
fi
case "${ID:-unknown}" in
  debian|ubuntu) ;;
  *)
    echo "WARNING: this installer targets Debian 13 (Ubuntu 26.04 also" >&2
    echo "         verified working). Detected ID=${ID:-unknown} — untested," >&2
    echo "         may fail partway through apt/Ansible steps." >&2
    if [ -n "${FLEET_FORCE_OS}" ]; then
      echo "==> FLEET_FORCE_OS set — continuing anyway."
    elif _tty_openable; then
      read -r -p "Continue anyway? [y/N] " _os_confirm < /dev/tty || _os_confirm=""
      case "${_os_confirm}" in
        y|Y|yes|YES) ;;
        *) echo "Aborting. Set FLEET_FORCE_OS=1 to skip this check." >&2; exit 1 ;;
      esac
    else
      echo "ERROR: no controlling terminal to confirm, and FLEET_FORCE_OS is not" >&2
      echo "       set. Re-run with FLEET_FORCE_OS=1 to proceed unattended." >&2
      exit 1
    fi
    ;;
esac

# --- 3. Value collection (env var -> persisted local-vars.yml -> /dev/tty
#        prompt -> generate/default/fail-closed) -----------------------------
# The curl|bash stdin trap: when piped, stdin IS the script body, so a bare
# `read` here would consume leftover script text. Every prompt reads from
# /dev/tty explicitly; if that's not openable (no tty — CI, cloud-init, a
# fully piped non-interactive session), required values fail with an
# actionable error instead of hanging or silently accepting an empty string.
#
# FLEET_LOCAL_VARS is set up here (moved ahead of §6 "persist collected
# values") so a RE-RUN with no env var and no tty can still recover a value
# a prior run already persisted, instead of failing closed on a value it
# already knows. Idempotent either way: mkdir -p/touch/chmod are no-ops on
# an already-provisioned file.
FLEET_LOCAL_VARS=/etc/ddev-fleet/local-vars.yml
mkdir -p "$(dirname "${FLEET_LOCAL_VARS}")"
touch "${FLEET_LOCAL_VARS}"
# Secrets (admin password, future SMTP creds) land in this file — lock it
# down to root-only regardless of the umask that created it.
chmod 0600 "${FLEET_LOCAL_VARS}"

_persisted_value() {
  # $1=yaml key -> prints the unquoted value already persisted in
  # FLEET_LOCAL_VARS, or nothing if the key isn't there (fresh install, or
  # a key that predates persistence). Matches _persist_if_absent's format:
  # `key: "value"` for strings, `key: value` for bare bools.
  local line
  line="$(grep "^$1:" "${FLEET_LOCAL_VARS}" 2>/dev/null | head -n1)" || true
  if [ -n "${line}" ]; then
    line="${line#*: }"
    line="${line%\"}"
    line="${line#\"}"
    printf '%s' "${line}"
  fi
}

_prompt_required() {
  # $1=varname (for the error message) $2=prompt text -> prints the answer
  local prompt="$2" answer=""
  if _tty_openable; then
    read -r -p "${prompt}" answer < /dev/tty || answer=""
  fi
  if [ -z "${answer}" ]; then
    echo "ERROR: $1 is required and no value was supplied." >&2
    echo "       Set it as an env var, e.g.: sudo $1=<value> bash bootstrap.sh" >&2
    exit 1
  fi
  printf '%s' "${answer}"
}

_prompt_yes_default() {
  # $1=prompt text -> prints "1" (yes) or "0" (no); defaults to yes on a
  # bare Enter, an unreadable /dev/tty, or any answer other than n/N/no/NO.
  local prompt="$1" answer=""
  if _tty_openable; then
    read -r -p "${prompt}" answer < /dev/tty || answer=""
  fi
  case "${answer}" in
    n|N|no|NO) printf '0' ;;
    *) printf '1' ;;
  esac
}

# _persist_if_absent (below) embeds these two values unescaped into a
# double-quoted YAML scalar in local-vars.yml; a literal `"` (or newline)
# in either would corrupt that file. Minimal sanity check, not a full
# RFC validator — just enough to keep the persisted YAML well-formed and
# catch an obviously-wrong value early.
_validate_domain() {
  # $1=value
  case "$1" in
    *'"'*|*[![:alnum:].-]*)
      echo "ERROR: FLEET_DOMAIN '$1' is not a valid domain (only letters," >&2
      echo "       digits, '.' and '-' allowed; no quotes)." >&2
      exit 1
      ;;
  esac
}

_validate_email() {
  # $1=value
  case "$1" in
    *'"'*|*[[:space:]]*|*\\*)
      echo "ERROR: FLEET_ACME_EMAIL '$1' contains a quote, backslash, or" >&2
      echo "       whitespace — not a valid email." >&2
      exit 1
      ;;
  esac
  case "$1" in
    *@*) ;;
    *)
      echo "ERROR: FLEET_ACME_EMAIL '$1' is missing '@' — not a valid email." >&2
      exit 1
      ;;
  esac
}

FLEET_DOMAIN="${FLEET_DOMAIN:-}"
if [ -z "${FLEET_DOMAIN}" ]; then
  FLEET_DOMAIN="$(_persisted_value fleet_domain)"
  [ -n "${FLEET_DOMAIN}" ] && echo "==> fleet_domain already set in ${FLEET_LOCAL_VARS} — using existing value, not re-prompting"
fi
if [ -z "${FLEET_DOMAIN}" ]; then
  FLEET_DOMAIN="$(_prompt_required FLEET_DOMAIN 'Fleet domain (e.g. fleet.example.com): ')"
fi
_validate_domain "${FLEET_DOMAIN}"

FLEET_ACME_EMAIL="${FLEET_ACME_EMAIL:-}"
if [ -z "${FLEET_ACME_EMAIL}" ]; then
  FLEET_ACME_EMAIL="$(_persisted_value acme_email)"
  [ -n "${FLEET_ACME_EMAIL}" ] && echo "==> acme_email already set in ${FLEET_LOCAL_VARS} — using existing value, not re-prompting"
fi
if [ -z "${FLEET_ACME_EMAIL}" ]; then
  FLEET_ACME_EMAIL="$(_prompt_required FLEET_ACME_EMAIL "Let's Encrypt contact email: ")"
fi
_validate_email "${FLEET_ACME_EMAIL}"

FLEET_ADMIN_PASSWORD="${FLEET_ADMIN_PASSWORD:-}"
_admin_password_generated=0
if [ -z "${FLEET_ADMIN_PASSWORD}" ]; then
  # Never ship a fixed default: generate from /dev/urandom (always
  # present; `openssl` isn't guaranteed until phase 4 installs
  # dependencies). 24+ alphanumeric chars is strong given it's
  # machine-generated and immediately bcrypt-hashed.
  # `head -c 24` closes its end of the pipe as soon as it has enough bytes;
  # `tr` then gets SIGPIPE writing into the closed pipe and exits 141. Under
  # `set -euo pipefail` that 141 would abort the WHOLE script right here, on
  # what is otherwise the default (most common) path. The trailing `; true`
  # makes the command substitution's own exit status 0 without touching the
  # captured output (command substitution strips the trailing newline
  # regardless, and `; true` only affects $?, not stdout).
  FLEET_ADMIN_PASSWORD="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 24; true)"
  _admin_password_generated=1
fi

FLEET_REPO_VERSION="${FLEET_REPO_VERSION:-main}"

FLEET_NETWORK_HARDENING="${FLEET_NETWORK_HARDENING:-}"
case "${FLEET_NETWORK_HARDENING}" in
  0|1) ;;
  *) FLEET_NETWORK_HARDENING="$(_prompt_yes_default 'Enable network hardening (UFW + Docker DOCKER-USER guard)? [Y/n] ')" ;;
esac

FLEET_SECURITY_HARDENING="${FLEET_SECURITY_HARDENING:-}"
case "${FLEET_SECURITY_HARDENING}" in
  0|1) ;;
  *) FLEET_SECURITY_HARDENING="$(_prompt_yes_default 'Enable security hardening (auto-updates, SSH lockdown, fail2ban, Docker robustness)? [Y/n] ')" ;;
esac
# msmtp relay (reboot-required email channel) — asked only when security
# hardening is enabled; entirely optional even then (blank host disables
# just this channel, never blocks the install). Interfaces: env overrides
# FLEET_MSMTP_HOST/_PORT/_USER/_PASSWORD/_FROM/_TO. Host/from/to are
# persisted (unencrypted) into local-vars.yml below via _persist_if_absent;
# user/password are SECRETS and never go there — they land in
# /srv/fleet/.secrets as MSMTP_USER/MSMTP_PASSWORD, the keys the
# security_hardening role's harden.yml reads back out.
_validate_no_quote() {
  # $1=varname (for the error message) $2=value — a literal `"` would
  # corrupt the double-quoted YAML scalar _persist_if_absent writes.
  case "$2" in
    *'"'*)
      echo "ERROR: $1 '$2' contains a double-quote character — not allowed." >&2
      exit 1
      ;;
  esac
}

FLEET_MSMTP_HOST="${FLEET_MSMTP_HOST:-}"
FLEET_MSMTP_PORT="${FLEET_MSMTP_PORT:-}"
FLEET_MSMTP_USER="${FLEET_MSMTP_USER:-}"
FLEET_MSMTP_PASSWORD="${FLEET_MSMTP_PASSWORD:-}"
FLEET_MSMTP_FROM="${FLEET_MSMTP_FROM:-}"
FLEET_MSMTP_TO="${FLEET_MSMTP_TO:-}"

if [ "${FLEET_SECURITY_HARDENING}" = "1" ]; then
  if [ -z "${FLEET_MSMTP_HOST}" ]; then
    echo "==> Reboot-required email notifications (blank host = disable this channel)"
    if _tty_openable; then
      read -r -p "SMTP relay host (blank = disable reboot-required email) []: " FLEET_MSMTP_HOST < /dev/tty || FLEET_MSMTP_HOST=""
    fi
  fi

  if [ -n "${FLEET_MSMTP_HOST}" ]; then
    _validate_no_quote FLEET_MSMTP_HOST "${FLEET_MSMTP_HOST}"

    if [ -z "${FLEET_MSMTP_PORT}" ] && _tty_openable; then
      read -r -p "SMTP relay port [587]: " FLEET_MSMTP_PORT < /dev/tty || FLEET_MSMTP_PORT=""
    fi
    FLEET_MSMTP_PORT="${FLEET_MSMTP_PORT:-587}"
    case "${FLEET_MSMTP_PORT}" in
      *[![:digit:]]*|'')
        echo "ERROR: FLEET_MSMTP_PORT '${FLEET_MSMTP_PORT}' is not a valid port number." >&2
        exit 1
        ;;
    esac

    if [ -z "${FLEET_MSMTP_USER}" ] && _tty_openable; then
      read -r -p "SMTP username []: " FLEET_MSMTP_USER < /dev/tty || FLEET_MSMTP_USER=""
    fi

    if [ -z "${FLEET_MSMTP_PASSWORD}" ] && _tty_openable; then
      read -r -s -p "SMTP password []: " FLEET_MSMTP_PASSWORD < /dev/tty || FLEET_MSMTP_PASSWORD=""
      echo
    fi

    if [ -z "${FLEET_MSMTP_FROM}" ] && _tty_openable; then
      read -r -p "From address []: " FLEET_MSMTP_FROM < /dev/tty || FLEET_MSMTP_FROM=""
    fi
    _validate_no_quote FLEET_MSMTP_FROM "${FLEET_MSMTP_FROM}"

    if [ -z "${FLEET_MSMTP_TO}" ] && _tty_openable; then
      read -r -p "Notify address (To:) []: " FLEET_MSMTP_TO < /dev/tty || FLEET_MSMTP_TO=""
    fi
    _validate_no_quote FLEET_MSMTP_TO "${FLEET_MSMTP_TO}"
  else
    echo "==> Reboot-required email disabled (blank relay host) — sidebar and web-UI channels are unaffected"
  fi
fi

# NOTE: HTTPS default — at bootstrap time no SSH deploy key exists yet.
# Ways to get the code onto the box:
#   - Public/authenticated git: leave FLEET_REPO_URL default or export an
#     https app-password URL — the script clones/pulls it.
#   - PRIVATE repo, no server-side git auth (recommended here): deliver a
#     clean checkout out-of-band (e.g. rsync a `git archive` export to
#     ${FLEET_OPT_DIR}) and run with FLEET_SKIP_FETCH=1 — the git step is
#     then skipped entirely and the code already on disk is used as-is.
FLEET_REPO_URL="${FLEET_REPO_URL:-https://github.com/rcuny/ddev-fleet.git}"
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
  git -C "${FLEET_OPT_DIR}" fetch --tags
  if [ "${FLEET_REPO_VERSION}" = "main" ]; then
    # A previous run may have pinned FLEET_REPO_VERSION to a tag/sha,
    # leaving this checkout on a detached HEAD. `git pull` has no upstream
    # to pull from in that state and errors under `set -e`. Reattach to
    # main first (a no-op if already on it) so the pull always has a
    # branch to fast-forward.
    git -C "${FLEET_OPT_DIR}" checkout main
    git -C "${FLEET_OPT_DIR}" pull --ff-only
  else
    git -C "${FLEET_OPT_DIR}" checkout "${FLEET_REPO_VERSION}"
  fi
else
  echo "==> Cloning ${FLEET_REPO_URL} into ${FLEET_OPT_DIR}"
  git clone "${FLEET_REPO_URL}" "${FLEET_OPT_DIR}"
  if [ "${FLEET_REPO_VERSION}" != "main" ]; then
    git -C "${FLEET_OPT_DIR}" fetch --tags
    git -C "${FLEET_OPT_DIR}" checkout "${FLEET_REPO_VERSION}"
  fi
fi

# --- 6. Persist collected values (never overwrite an existing key) ---------
# FLEET_LOCAL_VARS itself is set up in §3 above, ahead of the value
# collection that needs to read it back on a re-run.
_persist_if_absent() {
  # $1=yaml key $2=value (already yaml-safe: quoted string or bare bool)
  if grep -q "^$1:" "${FLEET_LOCAL_VARS}" 2>/dev/null; then
    echo "==> $1 already set in ${FLEET_LOCAL_VARS} — using existing value, not re-prompting"
  else
    echo "$1: $2" >> "${FLEET_LOCAL_VARS}"
  fi
}

_persist_if_absent fleet_domain "\"${FLEET_DOMAIN}\""
_persist_if_absent acme_email "\"${FLEET_ACME_EMAIL}\""
_persist_if_absent fleet_network_hardening_enabled "$([ "${FLEET_NETWORK_HARDENING}" = "1" ] && echo true || echo false)"
_persist_if_absent fleet_security_hardening_enabled "$([ "${FLEET_SECURITY_HARDENING}" = "1" ] && echo true || echo false)"

if [ -n "${FLEET_MSMTP_HOST}" ]; then
  _persist_if_absent fleet_msmtp_host "\"${FLEET_MSMTP_HOST}\""
  _persist_if_absent fleet_msmtp_port "${FLEET_MSMTP_PORT}"
  _persist_if_absent fleet_msmtp_tls "true"
  _persist_if_absent fleet_msmtp_from "\"${FLEET_MSMTP_FROM}\""
  _persist_if_absent fleet_msmtp_to "\"${FLEET_MSMTP_TO}\""

  # user/password are SECRETS — never written to local-vars.yml. They go
  # into /srv/fleet/.secrets as MSMTP_USER/MSMTP_PASSWORD, the exact keys
  # security_hardening/tasks/harden.yml greps back out. /srv/fleet may not
  # exist yet at this point in a fresh install; the fleet_user role's
  # later "touch, never overwrite content" task re-owns this file as
  # fleet:fleet without touching what we write here.
  FLEET_SECRETS_FILE=/srv/fleet/.secrets
  mkdir -p "$(dirname "${FLEET_SECRETS_FILE}")"
  touch "${FLEET_SECRETS_FILE}"
  chmod 0600 "${FLEET_SECRETS_FILE}"

  _persist_secret_if_absent() {
    # $1=KEY $2=value -> writes KEY=value into FLEET_SECRETS_FILE unless
    # already present. Mirrors _persist_if_absent but for the KEY=VALUE
    # secrets file, never echoing the value to stdout.
    if grep -q "^$1=" "${FLEET_SECRETS_FILE}" 2>/dev/null; then
      echo "==> $1 already set in ${FLEET_SECRETS_FILE} — leaving it unchanged"
    else
      echo "$1=$2" >> "${FLEET_SECRETS_FILE}"
    fi
  }

  [ -n "${FLEET_MSMTP_USER}" ] && _persist_secret_if_absent MSMTP_USER "${FLEET_MSMTP_USER}"
  [ -n "${FLEET_MSMTP_PASSWORD}" ] && _persist_secret_if_absent MSMTP_PASSWORD "${FLEET_MSMTP_PASSWORD}"
fi

if grep -q '^fleet_admin_default_password:' "${FLEET_LOCAL_VARS}" 2>/dev/null; then
  echo "==> fleet_admin_default_password already set in ${FLEET_LOCAL_VARS} — leaving it unchanged"
  _admin_password_generated=0
else
  echo "fleet_admin_default_password: \"${FLEET_ADMIN_PASSWORD}\"" >> "${FLEET_LOCAL_VARS}"
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
if [ "${_admin_password_generated}" -eq 1 ]; then
  echo "Dashboard admin password (generated, printed ONCE — save it now):"
  echo "  ${FLEET_ADMIN_PASSWORD}"
  echo "Rotate later with: sudo -u fleet fleet rotate-admin-password"
  echo
fi
if [ "${FLEET_NETWORK_HARDENING}" = "1" ]; then
  echo "==> Network hardening is ENABLED. A UFW dead-man's switch has been armed:"
  echo "    if you lose SSH access, the firewall reverts automatically before the"
  echo "    deadline printed in the security-hardening role's own output above."
  echo "    See docs/operations.md for the confirm command."
  echo
fi
echo "Next steps (see docs/installation.md for the full checklist):"
echo "  1. Add the deploy key above as a READ-ONLY deploy key on each git forge."
echo "  2. Point DNS: <domain> and *.<domain> at this server's IP (if not already)."
echo "  3. Run 'fleet init' as the fleet user to mint the Claude Code OAuth token"
echo "     and create the fleet.yml registry."
