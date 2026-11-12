#!/bin/bash
# Live check of instance network isolation (FLE-299). NOT part of CI: run it by hand ON a throwaway host (never on a
# host with colleagues) after the installer ran at the commit under test and two projects are deployed.
#
#   sudo ci/isolation-check.sh <source-project> <target-project> <service> <port>
#   e.g. sudo ci/isolation-check.sh demo blog typesense 8108
#
# Both projects need a running instance with the same label. The script walks the rollout of
# docs/reference/17-instance-isolation.md: off -> report (flows recorded) -> enforce (probes) -> a declared link
# (reachable) -> the four restart kinds (ddev restart of each side, docker restart ddev-router, systemctl restart docker)
# -> disable. Every step runs `fleet host:isolation:check` and stops at the first failure. The router measurement
# prints the seconds from `docker restart ddev-router` until the first answer through Caddy.
# It changes the isolation mode of THIS host and leaves it `off` at the end (also on failure, via the trap).
set -euo pipefail

if [ "$#" -ne 4 ]; then
  printf 'usage: %s <source-project> <target-project> <service> <port>\n' "$0" >&2
  exit 64
fi
SRC=$1
DST=$2
SERVICE=$3
PORT=$4
FLEET=${FLEET_BIN:-fleet}
SETTLE=${ISOLATION_SETTLE_SECONDS:-150}

step() { printf '\n==> %s\n' "$*"; }
fleet_as_fleet() { sudo -u fleet "$FLEET" "$@"; }
check() {
  local label=$1
  step "check: $label"
  fleet_as_fleet host:isolation:check
}
cleanup() {
  local status=$?
  step 'cleanup: isolation off'
  fleet_as_fleet host:isolation:disable || true
  fleet_as_fleet project:link:delete "$SRC" "$DST" --service="$SERVICE" --port="$PORT" >/dev/null 2>&1 || true
  exit "$status"
}
trap cleanup EXIT

step 'report mode: the table must load (nft -c -f runs inside the helper first)'
fleet_as_fleet host:isolation:enable --mode=report
nft list table inet fleet_isolation | head -n 40

step 'status in report mode'
fleet_as_fleet host:isolation:status
check 'report mode: everything reachable (expected by the mode)'

step 'generate cross-instance traffic, then the flow must be recorded'
SRC_WEB=$(docker ps --format '{{.Names}}' | grep -E "^ddev-${SRC}--.*-web$" | head -n 1)
DST_WEB=$(docker ps --format '{{.Names}}' | grep -E "^ddev-${DST}--.*-web$" | head -n 1)
[ -n "$SRC_WEB" ] && [ -n "$DST_WEB" ] || { printf 'no running web container for %s or %s\n' "$SRC" "$DST" >&2; exit 1; }
docker exec "$SRC_WEB" timeout 3 bash -c "exec 3<>/dev/tcp/${DST_WEB}/80" || true
sleep 2
fleet_as_fleet host:isolation:status --json | grep -q '"flows": \[' || { echo 'no flow list in the status'; exit 1; }
fleet_as_fleet host:isolation:status | grep -E 'flow |would need' || echo '(no flow recorded: look at the table)'

step 'enforce'
fleet_as_fleet host:isolation:enable --mode=enforce --yes
check 'enforce: the other instance, the router API and ssh are blocked'

step 'declare the link, wait for the pass, then it must be reachable'
fleet_as_fleet project:link:create "$SRC" "$DST" --service="$SERVICE" --port="$PORT" --note='isolation-check'
sleep 130
check 'enforce with the link live'

step 'ddev restart of the source and of the target'
for p in "$SRC_WEB" "$DST_WEB"; do
  name=${p#ddev-}
  name=${name%-web}
  sudo -u fleet ddev restart "$name" >/dev/null 2>&1 || (cd "/srv/fleet/instances/$name" && sudo -u fleet ddev restart)
done
sleep "$SETTLE"
check 'after ddev restart (within the watcher backstop)'

step 'router restart: seconds until the first answer through Caddy'
URL=${ISOLATION_CHECK_URL:-}
start=$(date +%s.%N)
docker restart ddev-router >/dev/null
if [ -n "$URL" ]; then
  until curl -fsS -o /dev/null --max-time 2 "$URL"; do sleep 0.5; done
  printf 'router restart -> first 200: %.1f s\n' "$(echo "$(date +%s.%N) - $start" | bc)"
else
  echo '(set ISOLATION_CHECK_URL to an instance URL to time it)'
  sleep 10
fi
sleep 5
check 'after docker restart ddev-router'

step 'systemctl restart docker'
systemctl restart docker
sleep "$SETTLE"
check 'after the docker daemon restart'

step 'done; the trap switches isolation off'
