# shellcheck shell=bash
# Step 20: Docker CE from Docker's own repository. The daemon is enabled and started every run, also when the
# binary was already there (an image can ship docker without ever having enabled it).

step_main() {
  if missing_or_dry docker; then
    apt_repo docker https://download.docker.com/linux/debian/gpg "$KEYRING_DIR/docker.asc" \
      "deb [arch=$(detect_arch) signed-by=$KEYRING_DIR/docker.asc] https://download.docker.com/linux/debian trixie stable"
    apt_install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  fi
  run systemctl enable --now docker.service
}
