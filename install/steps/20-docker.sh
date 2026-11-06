# shellcheck shell=bash
# Step 20: Docker CE from Docker's own repository, and its daemon configuration (FLE-219). The daemon is enabled and
# started every run, also when the binary was already there (an image can ship docker without ever having enabled it).
#
# /etc/docker/daemon.json is MERGED, never replaced: an operator's keys stay, and a key the operator already set
# (live-restore, log-driver, log-opts, default-address-pools) is left alone.
#   - default-address-pools: every DDEV instance makes a Docker bridge network and Docker's built-in pools hold about 30
#     of them ("all predefined address pools have been fully subnetted"). The built-in pools are listed first, so the
#     networks that exist stay valid, then one large pool of /24s (10.64.0.0/10, about 16000 networks).
#   - live-restore: containers survive a dockerd restart.
#   - log rotation: json-file, 10 MB x 3 files per container.
# Docker reads the file only at start. On a fresh install the file is written before the daemon first starts. On a host
# that already runs Docker the installer never restarts it (that would stop every instance): it prints a note.

DOCKER_FLEET_POOL_BASE=10.64.0.0
DOCKER_FLEET_POOL_PREFIX=10

# ip -> integer, for the route overlap check.
docker_ip_to_int() {
  local IFS=. a b c d
  read -r a b c d <<<"$1"
  printf '%s' $(((a << 24) | (b << 16) | (c << 8) | d))
}

# True when a route of this host overlaps the fleet pool (the pool would then hand out subnets that are in use).
# Routes over Docker's own bridges (br-*, docker0) are ignored.
docker_pool_routed() {
  local dest ip len mask pool_start pool_mask r_start
  pool_mask=$(((0xFFFFFFFF << (32 - DOCKER_FLEET_POOL_PREFIX)) & 0xFFFFFFFF))
  pool_start=$(docker_ip_to_int "$DOCKER_FLEET_POOL_BASE")
  while read -r dest rest; do
    # Docker's own bridges (instance networks live in the pool after the restart) are not a conflict.
    case " $rest " in
      *" dev br-"* | *" dev docker0 "*) continue ;;
    esac
    case $dest in
      [0-9]*) ;;
      *) continue ;;
    esac
    ip=${dest%/*}
    len=32
    [ "$ip" = "$dest" ] || len=${dest#*/}
    mask=$(((0xFFFFFFFF << (32 - len)) & 0xFFFFFFFF))
    r_start=$(docker_ip_to_int "$ip")
    # Two CIDR blocks overlap when they agree on the shorter prefix.
    if [ "$len" -lt "$DOCKER_FLEET_POOL_PREFIX" ]; then
      [ $((pool_start & mask)) -eq $((r_start & mask)) ] && return 0
    else
      [ $((r_start & pool_mask)) -eq "$pool_start" ] && return 0
    fi
  done < <(ip -4 route show 2>/dev/null)
  return 1
}

docker_daemon_config() {
  local file="$DOCKER_ETC_DIR/daemon.json" current pools merged with_pool=1 i
  if is_dry; then
    printf '+ merge %s: default-address-pools +%s/%s size 24, live-restore, log rotation\n' "$file" "$DOCKER_FLEET_POOL_BASE" "$DOCKER_FLEET_POOL_PREFIX"
    return 0
  fi
  ensure_dir "$DOCKER_ETC_DIR" 0755 root:root
  current='{}'
  if [ -s "$file" ]; then
    current=$(cat "$file")
    if ! jq -e 'type == "object"' >/dev/null 2>&1 <<<"$current"; then
      log_warn "$file is not a JSON object; left untouched (fix it, then re-run the installer to get the Docker network pools)"
      return 0
    fi
  fi

  if docker_pool_routed; then
    with_pool=0
    log_warn "this host routes ${DOCKER_FLEET_POOL_BASE}/${DOCKER_FLEET_POOL_PREFIX}; the Docker address pool for instance networks was NOT added (Docker allows about 30 instance networks)"
  elif jq -e --arg base "${DOCKER_FLEET_POOL_BASE}/${DOCKER_FLEET_POOL_PREFIX}" '(."default-address-pools" // []) | any(.base == $base)' >/dev/null <<<"$current"; then
    with_pool=0
  elif jq -e 'has("default-address-pools")' >/dev/null <<<"$current"; then
    with_pool=0
    log_warn "$file already sets default-address-pools; left as is (add {\"base\":\"${DOCKER_FLEET_POOL_BASE}/${DOCKER_FLEET_POOL_PREFIX}\",\"size\":24} yourself for more than about 30 instance networks)"
  fi

  # Docker's built-in pools first (172.17.0.0/16 .. 172.31.0.0/16 in /16s, 192.168.0.0/16 in /20s), then ours.
  pools='['
  for i in $(seq 17 31); do
    pools+="{\"base\":\"172.$i.0.0/16\",\"size\":16},"
  done
  pools+="{\"base\":\"192.168.0.0/16\",\"size\":20},{\"base\":\"${DOCKER_FLEET_POOL_BASE}/${DOCKER_FLEET_POOL_PREFIX}\",\"size\":24}]"

  merged=$(jq --argjson pools "$pools" --argjson with_pool "$with_pool" '
      (if has("live-restore") then . else . + {"live-restore": true} end)
    | (if has("log-driver") or has("log-opts") then . else . + {"log-driver": "json-file", "log-opts": {"max-size": "10m", "max-file": "3"}} end)
    | (if $with_pool == 1 and (has("default-address-pools") | not) then . + {"default-address-pools": $pools} else . end)
  ' <<<"$current")
  write_file "$file" 0644 root:root <<<"$merged"
}

# True when the running daemon has not read the pool yet (a restart is pending).
docker_pool_pending() {
  local live
  live=$(docker info --format '{{json .DefaultAddressPools}}' 2>/dev/null) || return 1
  grep -q "${DOCKER_FLEET_POOL_BASE}" "$DOCKER_ETC_DIR/daemon.json" 2>/dev/null || return 1
  ! grep -q "${DOCKER_FLEET_POOL_BASE}" <<<"$live"
}

step_main() {
  local fresh=0
  if missing_or_dry docker; then
    fresh=1
    # The file goes in before the package, so the daemon's very first start already reads it.
    docker_daemon_config
    apt_repo docker https://download.docker.com/linux/debian/gpg "$KEYRING_DIR/docker.asc" \
      "deb [arch=$(detect_arch) signed-by=$KEYRING_DIR/docker.asc] https://download.docker.com/linux/debian trixie stable" \
      9DC858229FC7DD38854AE2D88D81803C0EBFCD88
    apt_install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  else
    docker_daemon_config
  fi
  run systemctl enable --now docker.service

  if [ "$fresh" = 0 ] && ! is_dry && docker_pool_pending; then
    log_warn "Docker has not read the new network pool yet (an installer never restarts Docker by itself)"
    add_final_note "NOTE: Docker must be restarted once, at a quiet moment, to read /etc/docker/daemon.json (more than about 30 instance networks, live-restore). Until then the host holds about 30 instances. Procedure: stop the instances (fleet instance:stop --all, or 'sudo -u fleet ddev poweroff'), 'sudo systemctl restart docker', start them again (fleet instance:start). See docs/reference/upgrade.md, Docker network pool."
  fi
}
