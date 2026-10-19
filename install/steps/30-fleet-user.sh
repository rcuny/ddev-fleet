# shellcheck shell=bash
# Step 30: the `fleet` service user and the directories it owns. /opt/ddev-fleet stays fleet:fleet: the app is a
# fleet-owned git checkout, never root-owned.

step_main() {
  run groupadd --system --force docker
  if is_dry || ! id -u "$FLEET_USER" >/dev/null 2>&1; then
    run useradd --system --create-home --home-dir "$FLEET_HOME_DIR" --shell /bin/bash --user-group "$FLEET_USER"
  fi
  run usermod -aG docker "$FLEET_USER"

  ensure_dir "$FLEET_SRV_DIR" 0750 fleet:fleet
  ensure_dir "$FLEET_SRV_DIR/var" 0750 fleet:fleet
  ensure_dir "$FLEET_SRV_DIR/staging" 0700 fleet:fleet
  ensure_dir "$FLEET_ETC_DIR" 0750 root:fleet
  ensure_dir "$FLEET_OPT_DIR" 0755 fleet:fleet
  run chown -R fleet:fleet "$FLEET_OPT_DIR"
}
