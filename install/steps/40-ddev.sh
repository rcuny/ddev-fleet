# shellcheck shell=bash
# Step 40: DDEV from its apt repository, configured for the fleet user (FLE-300: without DDEV's shared ssh-agent container, whose
# socket every web container would mount; the per-instance agent of container_ssh is the only supported way): the router listens on the loopback ports
# Caddy proxies to (bound explicitly to loopback, not to every interface), and mkcert's local CA is installed for the NSS store only (a headless host has no browser store).

# `--omit-containers` REPLACES DDEV's whole list, so merge: the containers already omitted in the fleet user's global config
# (flow list or block list) plus ddev-ssh-agent, comma separated, without duplicates.
omit_containers_with_ssh_agent() {
  local file="$FLEET_HOME_DIR/.ddev/global_config.yaml" existing=""
  if [ -r "$file" ]; then
    existing=$(awk '
      /^omit_containers:/ { f = 1; v = $0; sub(/^omit_containers:[ ]*/, "", v)
        if (v ~ /^\[/) { gsub(/[\[\]" ]/, "", v); print v; exit }
        next }
      f && /^[ ]*-[ ]/ { v = $0; sub(/^[ ]*-[ ]*/, "", v); gsub(/"/, "", v); printf "%s,", v; next }
      f { exit }' "$file")
  fi
  local list="" item
  for item in $(printf '%s' "$existing,ddev-ssh-agent" | tr ',' ' '); do
    case ",$list," in *",$item,"*) ;; *) list="${list:+$list,}$item" ;; esac
  done
  printf '%s' "$list"
}

step_main() {
  apt_repo ddev https://pkg.ddev.com/apt/gpg.key "$KEYRING_DIR/ddev.asc" \
    "deb [signed-by=$KEYRING_DIR/ddev.asc] https://pkg.ddev.com/apt/ * *" \
    DBD730A3A581BDAC98BAF17FC16711FC1179C7CA
  apt_install ddev

  run_as_fleet ddev config global --router-http-port=8080 --router-https-port=8443 --router-bind-all-interfaces=false --instrumentation-opt-in=false "--omit-containers=$(omit_containers_with_ssh_agent)"

  if is_dry || [ ! -r "$FLEET_HOME_DIR/.local/share/mkcert/rootCA.pem" ]; then
    run_as_fleet env TRUST_STORES=nss mkcert -install
  fi
}
