# shellcheck shell=bash
# Step 10: base packages. PHP is Debian's own 8.4 (no third-party PHP repository).
#   openssh-client: ssh-keygen and ssh-keyscan, used by the app for deploy keys and known_hosts
#   libnss3-tools:  certutil, which mkcert (DDEV) needs to manage its NSS trust store
#   acl, sudo:      the /srv/fleet ACL for Authelia and the root helper's sudoers line
#   tmux:           the agent workspace (fleet-tmux.service, the `fleet` tmux session)

step_main() {
  apt_mark_dirty
  apt_install \
    ca-certificates curl git sudo acl openssl unzip jq openssh-client libnss3-tools iproute2 tmux composer \
    php8.4-fpm php8.4-cli php8.4-opcache php8.4-sqlite3 php8.4-intl php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip
}
