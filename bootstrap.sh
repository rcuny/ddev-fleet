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

# TLS certificate mode (docs/installation.md "Choosing a TLS mode" / spec
# `fleet_tls_mode`): how Caddy gets Let's Encrypt certs for *.<domain>.
# `1`/`on_demand` (default) needs no setup; `2`/`ovh_dns` trades an OVH API
# key for a single wildcard cert with no per-hostname rate limit.
FLEET_TLS_MODE="${FLEET_TLS_MODE:-}"
if [ -z "${FLEET_TLS_MODE}" ]; then
  FLEET_TLS_MODE="$(_persisted_value fleet_tls_mode)"
  [ -n "${FLEET_TLS_MODE}" ] && echo "==> fleet_tls_mode already set in ${FLEET_LOCAL_VARS} — using existing value, not re-prompting"
fi
if [ -z "${FLEET_TLS_MODE}" ]; then
  if _tty_openable; then
    echo "TLS certificates (Let's Encrypt):"
    echo "  1) Per-hostname (HTTP challenge) — no setup; max ~50 NEW hostnames per 7 days per registered domain"
    echo "  2) Wildcard via OVH DNS — one certificate for *.<domain>, no hostname limit; needs an OVH API key for your DNS zone"
    read -r -p "Choose [1]: " _fleet_tls_choice < /dev/tty || _fleet_tls_choice=""
  else
    _fleet_tls_choice=""
  fi
  case "${_fleet_tls_choice}" in
    2) FLEET_TLS_MODE="ovh_dns" ;;
    ""|1) FLEET_TLS_MODE="on_demand" ;;
    *)
      echo "ERROR: unrecognised TLS mode choice '${_fleet_tls_choice}' (expected 1 or 2)." >&2
      exit 1
      ;;
  esac
fi
case "${FLEET_TLS_MODE}" in
  on_demand|ovh_dns) ;;
  *)
    echo "ERROR: FLEET_TLS_MODE '${FLEET_TLS_MODE}' is not valid (expected 'on_demand' or 'ovh_dns')." >&2
    exit 1
    ;;
esac

# Auth mode (docs/README-authelia.md / spec `fleet_auth_mode`): how
# visitors authenticate to instances and the dashboard. `1`/`basic`
# (default) is today's per-instance HTTP basic auth; `2`/`authelia` adds a
# cookie-based login portal for networks that block basic auth outright.
FLEET_AUTH_MODE="${FLEET_AUTH_MODE:-}"
if [ -z "${FLEET_AUTH_MODE}" ]; then
  FLEET_AUTH_MODE="$(_persisted_value fleet_auth_mode)"
  [ -n "${FLEET_AUTH_MODE}" ] && echo "==> fleet_auth_mode already set in ${FLEET_LOCAL_VARS} — using existing value, not re-prompting"
fi
if [ -z "${FLEET_AUTH_MODE}" ]; then
  if _tty_openable; then
    echo "Auth mode:"
    echo "  1) Basic auth (default) — today's per-instance HTTP basic auth"
    echo "  2) Authelia — cookie-based login portal; works on networks that block basic auth"
    read -r -p "Choose [1]: " _fleet_auth_choice < /dev/tty || _fleet_auth_choice=""
  else
    _fleet_auth_choice=""
  fi
  case "${_fleet_auth_choice}" in
    2) FLEET_AUTH_MODE="authelia" ;;
    ""|1) FLEET_AUTH_MODE="basic" ;;
    *)
      echo "ERROR: unrecognised auth mode choice '${_fleet_auth_choice}' (expected 1 or 2)." >&2
      exit 1
      ;;
  esac
fi
case "${FLEET_AUTH_MODE}" in
  basic|authelia) ;;
  *)
    echo "ERROR: FLEET_AUTH_MODE '${FLEET_AUTH_MODE}' is not valid (expected 'basic' or 'authelia')." >&2
    exit 1
    ;;
esac

# ovh_dns credentials: /etc/caddy/ovh.env, mode 0600 root:root (the caddy
# Ansible role later re-owns it 0640 root:caddy once the `caddy` group
# exists — apt hasn't installed the package yet at this point in the
# script). Ansible never writes this file's contents; this is the ONLY
# place they're collected. Secrets never go to local-vars.yml or stdout.
FLEET_OVH_ENV_FILE=/etc/caddy/ovh.env
_ovh_env_has_all_keys() {
  # Prints nothing; returns 0 (true) only if every required key is present
  # as a KEY=... line. Never echoes values.
  [ -f "${FLEET_OVH_ENV_FILE}" ] || return 1
  for _k in OVH_ENDPOINT OVH_APPLICATION_KEY OVH_APPLICATION_SECRET OVH_CONSUMER_KEY; do
    grep -q "^${_k}=" "${FLEET_OVH_ENV_FILE}" 2>/dev/null || return 1
  done
  return 0
}

if [ "${FLEET_TLS_MODE}" = "ovh_dns" ]; then
  if _ovh_env_has_all_keys; then
    echo "==> ${FLEET_OVH_ENV_FILE} already has all required OVH keys — leaving it unchanged"
  else
    OVH_ENDPOINT="${OVH_ENDPOINT:-}"
    OVH_APPLICATION_KEY="${OVH_APPLICATION_KEY:-}"
    OVH_APPLICATION_SECRET="${OVH_APPLICATION_SECRET:-}"
    OVH_CONSUMER_KEY="${OVH_CONSUMER_KEY:-}"

    if [ -z "${OVH_ENDPOINT}" ] && _tty_openable; then
      read -r -p "OVH API endpoint [ovh-eu]: " OVH_ENDPOINT < /dev/tty || OVH_ENDPOINT=""
    fi
    OVH_ENDPOINT="${OVH_ENDPOINT:-ovh-eu}"

    if [ -z "${OVH_APPLICATION_KEY}${OVH_APPLICATION_SECRET}${OVH_CONSUMER_KEY}" ] && _tty_openable; then
      _ovh_zone="${OVH_ZONE:-}"
      if [ -z "${_ovh_zone}" ]; then
        read -r -p "OVH DNS zone (the registered domain in your OVH account, e.g. example.com): " _ovh_zone < /dev/tty || _ovh_zone=""
      fi
      echo "==> Create an OVH API token at: https://eu.api.ovh.com/createToken/"
      echo "    Rights needed (GET/POST/DELETE), scoped to your zone:"
      if [ -n "${_ovh_zone}" ]; then
        echo "      GET    /domain/zone/${_ovh_zone}/*"
        echo "      POST   /domain/zone/${_ovh_zone}/*"
        echo "      DELETE /domain/zone/${_ovh_zone}/*"
      else
        echo "      GET/POST/DELETE  /domain/zone/<your-zone>/*"
      fi
      echo "    Optionally restrict the token to this server's IP."
    fi

    if [ -z "${OVH_APPLICATION_KEY}" ]; then
      OVH_APPLICATION_KEY="$(_prompt_required OVH_APPLICATION_KEY 'OVH application key: ')"
    fi
    if [ -z "${OVH_APPLICATION_SECRET}" ]; then
      if _tty_openable; then
        read -r -s -p "OVH application secret: " OVH_APPLICATION_SECRET < /dev/tty || OVH_APPLICATION_SECRET=""
        echo
      fi
      if [ -z "${OVH_APPLICATION_SECRET}" ]; then
        echo "ERROR: OVH_APPLICATION_SECRET is required and no value was supplied." >&2
        exit 1
      fi
    fi
    if [ -z "${OVH_CONSUMER_KEY}" ]; then
      if _tty_openable; then
        read -r -s -p "OVH consumer key: " OVH_CONSUMER_KEY < /dev/tty || OVH_CONSUMER_KEY=""
        echo
      fi
      if [ -z "${OVH_CONSUMER_KEY}" ]; then
        echo "ERROR: OVH_CONSUMER_KEY is required and no value was supplied." >&2
        exit 1
      fi
    fi

    mkdir -p "$(dirname "${FLEET_OVH_ENV_FILE}")"
    umask 077
    {
      echo "OVH_ENDPOINT=${OVH_ENDPOINT}"
      echo "OVH_APPLICATION_KEY=${OVH_APPLICATION_KEY}"
      echo "OVH_APPLICATION_SECRET=${OVH_APPLICATION_SECRET}"
      echo "OVH_CONSUMER_KEY=${OVH_CONSUMER_KEY}"
    } > "${FLEET_OVH_ENV_FILE}"
    umask 022
    chown root:root "${FLEET_OVH_ENV_FILE}"
    chmod 0600 "${FLEET_OVH_ENV_FILE}"
    echo "==> Wrote ${FLEET_OVH_ENV_FILE} (0600 root:root — the caddy role re-owns it 0640 root:caddy once provisioned)"
  fi
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

# fleet_ssh_allow_users (security_hardening's sshd `AllowUsers`) — THE
# LOCKOUT FOOTGUN: that role's own default is
# `[ansible_env.SUDO_USER | default(ansible_user_id, true)] | select | list`
# (ansible/roles/security_hardening/defaults/main.yml). Run this installer
# DETACHED as root with no controlling sudo session (e.g. wrapped in
# `systemd-run`, or any invocation where `$SUDO_USER` is unset) and that
# default resolves to `root`, so sshd gets `AllowUsers root` and the real
# login user (e.g. `debian`) is locked out of SSH — this actually happened
# and bricked SSH access to a server. Computed here (not left to the
# Ansible default) regardless of whether FLEET_SECURITY_HARDENING is on
# THIS run: persisting a safe value now, while we can still see who is
# really logged in, means a LATER run that enables hardening (possibly
# itself detached, possibly with no SUDO_USER) reuses this value instead of
# recomputing under exactly the conditions that caused the original bug.
_validate_ssh_username() {
  # $1=value — mirrors _validate_domain's minimal-but-real-enough check;
  # values land in a double-quoted YAML list item in local-vars.yml.
  case "$1" in
    *'"'*|*[![:alnum:]._-]*)
      echo "ERROR: FLEET_SSH_ALLOW_USERS entry '$1' is not a valid username" >&2
      echo "       (only letters, digits, '.', '_', '-' allowed; no quotes" >&2
      echo "       or whitespace)." >&2
      exit 1
      ;;
  esac
}

_compute_safe_ssh_allow_users() {
  # Prints a de-duplicated, order-preserving list (one per line): the
  # invoking user (if determinable) + every UID>=1000 account with a
  # NON-EMPTY ~/.ssh/authorized_keys + root — always, unconditionally
  # appended last. Never empty. This guarantees whoever can already SSH in
  # today (they must have an authorized_keys to do so) stays in
  # AllowUsers even when SUDO_USER is unset.
  local invoking_user="" seen=" " candidate uid home_dir keys_file
  invoking_user="${SUDO_USER:-}"
  if [ -z "${invoking_user}" ]; then
    invoking_user="$(logname 2>/dev/null)" || invoking_user=""
  fi
  if [ -n "${invoking_user}" ]; then
    printf '%s\n' "${invoking_user}"
    seen="${seen}${invoking_user} "
  fi

  while IFS=: read -r candidate _pw uid _gid _gecos home_dir _shell; do
    [ "${uid}" -ge 1000 ] 2>/dev/null || continue
    case "${seen}" in *" ${candidate} "*) continue ;; esac
    keys_file="${home_dir}/.ssh/authorized_keys"
    if [ -s "${keys_file}" ]; then
      printf '%s\n' "${candidate}"
      seen="${seen}${candidate} "
    fi
  done < /etc/passwd

  case "${seen}" in
    *" root "*) ;;
    *) printf '%s\n' "root" ;;
  esac
}

_yaml_list_persisted() {
  # $1=yaml key -> prints each already-persisted list item (one per line,
  # unquoted) for a `key:` / `  - "item"` block written by
  # _persist_list_if_absent (below), or nothing if the key isn't present.
  awk -v key="$1" '
    $0 == key ":" { found=1; next }
    found && /^[[:space:]]*-[[:space:]]/ {
      line=$0
      sub(/^[[:space:]]*-[[:space:]]*/, "", line)
      gsub(/^"|"$/, "", line)
      print line
      next
    }
    found { exit }
  ' "${FLEET_LOCAL_VARS}" 2>/dev/null
}

FLEET_SSH_ALLOW_USERS="${FLEET_SSH_ALLOW_USERS:-}"
if [ -n "${FLEET_SSH_ALLOW_USERS}" ]; then
  # Accept space- or comma-separated.
  _fleet_ssh_allow_users_list="$(printf '%s' "${FLEET_SSH_ALLOW_USERS}" | tr ',' ' ')"
  for _u in ${_fleet_ssh_allow_users_list}; do
    _validate_ssh_username "${_u}"
  done
elif grep -q '^fleet_ssh_allow_users:' "${FLEET_LOCAL_VARS}" 2>/dev/null; then
  _fleet_ssh_allow_users_list="$(_yaml_list_persisted fleet_ssh_allow_users | tr '\n' ' ')"
  echo "==> fleet_ssh_allow_users already set in ${FLEET_LOCAL_VARS} — using existing value, not re-prompting"
else
  if [ -z "${SUDO_USER:-}" ]; then
    echo "WARNING: SUDO_USER is empty (a detached/root run — e.g. systemd-run" >&2
    echo "         or a cron job with no controlling sudo session). Falling" >&2
    echo "         back to a scan of every account with a non-empty" >&2
    echo "         ~/.ssh/authorized_keys to keep your SSH login user in" >&2
    echo "         fleet_ssh_allow_users. Set FLEET_SSH_ALLOW_USERS explicitly" >&2
    echo "         to override, e.g. FLEET_SSH_ALLOW_USERS=\"debian root\"." >&2
  fi
  _fleet_ssh_allow_users_list="$(_compute_safe_ssh_allow_users | tr '\n' ' ')"
fi
echo "==> fleet_ssh_allow_users will be: ${_fleet_ssh_allow_users_list}"

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

_persist_list_if_absent() {
  # $1=yaml key $2...=list items (already yaml-safe, unquoted) -> writes
  #   key:
  #     - "item1"
  #     - "item2"
  # into FLEET_LOCAL_VARS unless the key is already present. List-valued
  # sibling of _persist_if_absent, same "never overwrite" contract.
  local _key="$1"
  shift
  if grep -q "^${_key}:" "${FLEET_LOCAL_VARS}" 2>/dev/null; then
    echo "==> ${_key} already set in ${FLEET_LOCAL_VARS} — using existing value, not re-prompting"
    return
  fi
  {
    echo "${_key}:"
    for _item in "$@"; do
      echo "  - \"${_item}\""
    done
  } >> "${FLEET_LOCAL_VARS}"
}

_persist_if_absent fleet_domain "\"${FLEET_DOMAIN}\""
_persist_if_absent acme_email "\"${FLEET_ACME_EMAIL}\""
_persist_if_absent fleet_tls_mode "\"${FLEET_TLS_MODE}\""
_persist_if_absent fleet_auth_mode "\"${FLEET_AUTH_MODE}\""
_persist_if_absent fleet_network_hardening_enabled "$([ "${FLEET_NETWORK_HARDENING}" = "1" ] && echo true || echo false)"
_persist_if_absent fleet_security_hardening_enabled "$([ "${FLEET_SECURITY_HARDENING}" = "1" ] && echo true || echo false)"
# shellcheck disable=SC2086  # _fleet_ssh_allow_users_list is an intentionally word-split, space-separated list
_persist_list_if_absent fleet_ssh_allow_users ${_fleet_ssh_allow_users_list}

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
  ansible-playbook -c local -e fleet_skip_fetch=true -e fleet_auth_mode="${FLEET_AUTH_MODE}" "${FLEET_OPT_DIR}/ansible/site.yml"
else
  ansible-playbook -c local -e fleet_auth_mode="${FLEET_AUTH_MODE}" "${FLEET_OPT_DIR}/ansible/site.yml"
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
if [ "${FLEET_TLS_MODE}" = "ovh_dns" ]; then
  echo "==> TLS mode is 'ovh_dns': Caddy issues one wildcard cert for *.${FLEET_DOMAIN}"
  echo "    via the OVH DNS-01 challenge. Only '${FLEET_DOMAIN}' A/AAAA needs to point"
  echo "    at this server — '*.${FLEET_DOMAIN}' does NOT need its own DNS record."
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
