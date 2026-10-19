#!/usr/bin/env bash
# ddev-fleet uninstaller. NEVER run automatically: nothing in the installer calls it.
#
#   sudo bash /opt/ddev-fleet/install/uninstall.sh --yes [--keep-data] [--purge-packages] [--dry-run]
#
# Removes what install.sh put on this host: the services and units, the configuration under /etc, the root helper,
# the sudoers line and the `fleet` command, the checkout in /opt/ddev-fleet, the state in /srv/fleet and the `fleet`
# user. Without --purge-packages the packages (Docker, DDEV, Caddy, Authelia, PHP) stay installed.
#
#   --yes             required: nothing is removed without it
#   --keep-data       keep /srv/fleet (the database, secrets, SSH keys, instances) and the `fleet` user that owns it
#   --purge-packages  also purge the packages the installer added from third-party repositories, and those repositories
#   --dry-run         print what would be removed and change nothing (works without root)
#
# Every removal goes through remove_path / remove_file, which refuse an empty, relative or too-short path; every call
# passes its path as "${VAR:?}" so an unset variable stops the script instead of widening the removal.
main() {
  set -Eeuo pipefail
  export LC_ALL=C
  PATH="$PATH:/usr/local/sbin:/usr/sbin:/sbin"
  export PATH
  exec </dev/null

  local yes=0 keep_data=0 purge=0 dry=0 arg
  for arg in "$@"; do
    case $arg in
      --yes) yes=1 ;;
      --keep-data) keep_data=1 ;;
      --purge-packages) purge=1 ;;
      --dry-run) dry=1 ;;
      --help | -h)
        sed -n '2,/^main()/p' "${BASH_SOURCE[0]}" | sed -e '$d' -e 's/^# \{0,1\}//'
        return 0
        ;;
      *)
        printf 'ERROR: unknown argument: %s\n' "${arg:0:60}" >&2
        return 64
        ;;
    esac
  done
  if [ "$yes" != 1 ]; then
    printf 'ERROR: this removes ddev-fleet from this host. Re-run with --yes to confirm (add --keep-data to keep /srv/fleet, --dry-run to only list).\n' >&2
    return 64
  fi
  if [ "$dry" != 1 ] && [ "$(id -u)" -ne 0 ]; then
    printf 'ERROR: run as root (sudo), or add --dry-run to only list what would be removed.\n' >&2
    return 77
  fi

  # Fixed layout, the same as install/lib/common.sh and the steps. Every path a removal uses is one of these (or built
  # from one), always written "${VAR:?}".
  local fleet_user=fleet
  local opt_dir=/opt/ddev-fleet
  local stage_dir=/usr/local/lib/ddev-fleet-installer
  local srv_dir=/srv/fleet
  local etc_dir=/etc/ddev-fleet
  local mercure_etc=/etc/fleet-mercure
  local caddy_etc=/etc/caddy
  local authelia_etc=/etc/authelia
  local systemd_dir=/etc/systemd/system
  local fpm_dir=/etc/php/8.4/fpm
  local local_lib=/usr/local/lib/ddev-fleet
  local helper_dir=/usr/local/libexec/fleet
  local wrapper=/usr/local/bin/fleet
  local mercure_bin=/usr/local/bin/mercure
  local sudoers_file=/etc/sudoers.d/fleet-apply
  local tmpfiles_mask=/etc/tmpfiles.d/authelia.conf
  local var_lib=/var/lib
  local apt_sources=/etc/apt/sources.list.d
  local apt_keyrings=/etc/apt/keyrings
  local caddy_default_bin=/usr/bin/caddy.default
  local caddy_custom_bin=/usr/bin/caddy.custom

  say() { printf '%s\n' "$*"; }
  # do_cmd <command...>: runs it, or prints it in dry-run. A failure of a cleanup command is reported, not fatal:
  # a half-installed host must still be cleaned as far as possible.
  do_cmd() {
    if [ "$dry" = 1 ]; then
      printf '+'
      printf ' %q' "$@"
      printf '\n'
      return 0
    fi
    "$@" || printf 'WARNING: failed (continuing): %s\n' "$*" >&2
  }
  # The only functions that delete. $1 must be an absolute path of at least two components.
  safe_path() {
    case ${1:-} in
      / | '' | /bin | /boot | /dev | /etc | /home | /lib | /opt | /proc | /root | /run | /sbin | /srv | /sys | /tmp | /usr | /var) return 1 ;;
      /*/*) ;;
      *) return 1 ;;
    esac
    [[ $1 != *..* ]]
  }
  remove_file() {
    safe_path "${1:-}" || {
      printf 'ERROR: refusing to remove "%s"\n' "${1:-}" >&2
      return 1
    }
    do_cmd rm -f -- "$1"
  }
  remove_path() {
    safe_path "${1:-}" || {
      printf 'ERROR: refusing to remove "%s"\n' "${1:-}" >&2
      return 1
    }
    do_cmd rm -rf --one-file-system -- "$1"
  }
  restore_file() {
    safe_path "${1:-}" || return 1
    do_cmd mv -f -- "$1" "${2:?}"
  }

  say "ddev-fleet uninstall$([ "$dry" = 1 ] && echo ' (dry-run: nothing is changed)')"

  # --- 1. services ----------------------------------------------------------------------------------------------------
  say "==> stopping services"
  if id -u "${fleet_user:?}" >/dev/null 2>&1 && command -v ddev >/dev/null 2>&1; then
    # Containers of the fleet's instances: stopped (their files go with /srv/fleet, or stay with --keep-data).
    do_cmd runuser -u "${fleet_user:?}" -- env HOME=/home/fleet DDEV_NONINTERACTIVE=true ddev poweroff
  fi
  do_cmd systemctl disable --now 'fleet-worker@*.service'
  do_cmd systemctl disable --now fleet-mercure.service
  do_cmd systemctl disable --now caddy.service
  do_cmd systemctl disable --now authelia.service

  # --- 2. units, drop-ins, configuration -----------------------------------------------------------------------------
  say "==> removing units and configuration"
  remove_file "${systemd_dir:?}/fleet-worker@.service"
  remove_file "${systemd_dir:?}/fleet-mercure.service"
  remove_file "${systemd_dir:?}/php8.4-fpm.service.d/fleet-env.conf"
  remove_file "${systemd_dir:?}/caddy.service.d/10-fleet-sandbox.conf"
  remove_file "${systemd_dir:?}/caddy.service.d/20-fleet-env.conf"
  remove_file "${systemd_dir:?}/authelia.service.d/secrets.conf"
  # The drop-in directories go when they are empty (rmdir never removes anything else).
  do_cmd rmdir --ignore-fail-on-non-empty "${systemd_dir:?}/php8.4-fpm.service.d" "${systemd_dir:?}/caddy.service.d" "${systemd_dir:?}/authelia.service.d"
  remove_file "${tmpfiles_mask:?}"
  remove_path "${etc_dir:?}"
  remove_path "${mercure_etc:?}"
  remove_path "${var_lib:?}/fleet-mercure"
  remove_path "${var_lib:?}/private/fleet-mercure"
  remove_path "${caddy_etc:?}/fleet"
  remove_file "${caddy_etc:?}/fleet.env"
  remove_file "${authelia_etc:?}/configuration.yml"
  do_cmd systemctl daemon-reload

  # --- 3. php-fpm: back to the stock pool ----------------------------------------------------------------------------
  remove_file "${fpm_dir:?}/pool.d/fleet.conf"
  remove_file "${fpm_dir:?}/conf.d/90-fleet.ini"
  if [ "$dry" = 1 ] || [ -f "${fpm_dir:?}/pool.d/www.conf.disabled" ]; then
    restore_file "${fpm_dir:?}/pool.d/www.conf.disabled" "${fpm_dir:?}/pool.d/www.conf"
  fi
  do_cmd systemctl try-restart php8.4-fpm.service

  # --- 4. Caddy: undo the divert and the alternatives, restore the package Caddyfile -----------------------------------
  say "==> restoring the packaged Caddy"
  do_cmd update-alternatives --remove caddy "${caddy_custom_bin:?}"
  do_cmd update-alternatives --remove caddy "${caddy_default_bin:?}"
  remove_file "${caddy_custom_bin:?}"
  if [ "$dry" = 1 ] || [ -n "$(dpkg-divert --list /usr/bin/caddy 2>/dev/null || true)" ]; then
    do_cmd dpkg-divert --rename --remove /usr/bin/caddy
  fi
  if [ "$dry" = 1 ] || dpkg -s caddy >/dev/null 2>&1; then
    # Reinstalling with --force-confmiss puts the package's own Caddyfile back (needs the package archive or network).
    remove_file "${caddy_etc:?}/Caddyfile"
    do_cmd env DEBIAN_FRONTEND=noninteractive apt-get -y -q -o Dpkg::Options::=--force-confmiss install --reinstall caddy
  fi

  # --- 5. the root helper, sudoers, command, Mercure ----------------------------------------------------------------
  say "==> removing the helper, the sudoers line and the commands"
  remove_file "${sudoers_file:?}"
  remove_path "${helper_dir:?}"
  remove_file "${wrapper:?}"
  remove_file "${mercure_bin:?}"
  remove_path "${local_lib:?}"
  remove_path "${stage_dir:?}"

  # --- 6. code, data, user -----------------------------------------------------------------------------------------------
  say "==> removing the application"
  remove_path "${opt_dir:?}"
  if [ "$keep_data" = 1 ]; then
    say "kept: ${srv_dir:?}, Authelia's secrets and database, and the ${fleet_user:?} user that owns them (--keep-data)"
  else
    remove_path "${srv_dir:?}"
    remove_path "${authelia_etc:?}/secrets"
    remove_path "${var_lib:?}/authelia"
    if [ "$dry" = 1 ] || id -u "${fleet_user:?}" >/dev/null 2>&1; then
      do_cmd userdel --remove "${fleet_user:?}"
    fi
  fi

  # --- 7. packages (only on request) -------------------------------------------------------------------------------
  if [ "$purge" = 1 ]; then
    say "==> purging packages and repositories"
    do_cmd env DEBIAN_FRONTEND=noninteractive apt-get -y -q purge caddy authelia ddev docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    remove_file "${apt_sources:?}/caddy.list"
    remove_file "${apt_sources:?}/authelia.list"
    remove_file "${apt_sources:?}/ddev.list"
    remove_file "${apt_sources:?}/docker.list"
    remove_file "${apt_keyrings:?}/caddy.asc"
    remove_file "${apt_keyrings:?}/authelia-security.gpg"
    remove_file "${apt_keyrings:?}/ddev.asc"
    remove_file "${apt_keyrings:?}/docker.asc"
    do_cmd apt-get -y -q autoremove
  else
    say "packages left installed (add --purge-packages to remove Docker, DDEV, Caddy and Authelia)"
  fi
  say "done"
}

main "$@"
