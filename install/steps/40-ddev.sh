# shellcheck shell=bash
# Step 40: DDEV from its apt repository, configured for the fleet user: the router listens on the loopback ports
# Caddy proxies to (bound explicitly to loopback, not to every interface), and mkcert's local CA is installed for the NSS store only (a headless host has no browser store).

step_main() {
  apt_repo ddev https://pkg.ddev.com/apt/gpg.key "$KEYRING_DIR/ddev.asc" \
    "deb [signed-by=$KEYRING_DIR/ddev.asc] https://pkg.ddev.com/apt/ * *" \
    DBD730A3A581BDAC98BAF17FC16711FC1179C7CA
  apt_install ddev

  run_as_fleet ddev config global --router-http-port=8080 --router-https-port=8443 --router-bind-all-interfaces=false --instrumentation-opt-in=false

  if is_dry || [ ! -r "$FLEET_HOME_DIR/.local/share/mkcert/rootCA.pem" ]; then
    run_as_fleet env TRUST_STORES=nss mkcert -install
  fi
}
