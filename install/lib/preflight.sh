# shellcheck shell=bash
#
# Pre-flight checks: is this host fit for the install or upgrade about to run? Sourced by run.sh after lib/common.sh,
# never executed. Read-only: nothing here writes to the host, and nothing needs root (a file or command that cannot be
# read as the caller is reported, not guessed).
#
# One line per finding, on stdout:   BLOCK|WARN|INFO <id>: <message>
#   BLOCK  the run must not start (exit 3); WARN  worth a look, the run goes on; INFO  for the record.
#
# `run.sh --preflight` prints the findings and exits 0 (no BLOCK) or 3. A normal run executes the same checks first and
# stops before step 10 on a BLOCK. The upgrade (`fleet upgrade`) calls `--preflight` of the target tag's own copy.

# Free space needed, in MiB (the installer clones the code and unpacks packages under /opt and /var; instances, their
# images and the database live under /srv/fleet and /var/lib/docker).
PREFLIGHT_MIN_OPT_MIB=${PREFLIGHT_MIN_OPT_MIB:-500}
PREFLIGHT_MIN_SRV_MIB=${PREFLIGHT_MIN_SRV_MIB:-2048}
PREFLIGHT_MIN_VAR_MIB=${PREFLIGHT_MIN_VAR_MIB:-2048}
# The longest instance id the app creates; keep in step with App\Fleet\Instances\InstanceNaming::MAX. Longer ids that
# already exist (FLE-146) cannot run (DDEV adds -web to the container host name, a host name has 63 characters at most).
PREFLIGHT_MAX_INSTANCE_ID=59
# Keys a current fleet.env carries (step 70 adds a missing one; see there).
PREFLIGHT_ENV_KEYS=(APP_ENV APP_SECRET MERCURE_JWT_SECRET FLEET_PROXY_SECRET FLEET_HOME DEFAULT_URI)
# Commands the install needs; step 10 installs a missing one, so on a host that is already installed it is a WARN.
PREFLIGHT_COMMANDS=(openssl jq tmux composer php8.4)

PREFLIGHT_BLOCKS=0
PREFLIGHT_NETWORK_TIMEOUT=${PREFLIGHT_NETWORK_TIMEOUT:-10}

# pf_emit <BLOCK|WARN|INFO> <id> <message...>
pf_emit() {
  local level=$1 id=$2
  shift 2
  [ "$level" != BLOCK ] || PREFLIGHT_BLOCKS=$((PREFLIGHT_BLOCKS + 1))
  printf '%s %s: %s\n' "$level" "$id" "$*"
}

# True when a previous install left its marker (so a missing tool is a problem, not a first install).
pf_installed() {
  [ -e "$FLEET_ETC_DIR/installed-revision" ] || [ -e "$FLEET_ETC_DIR/fleet.env" ]
}

# The os-release file: /etc/os-release, or the test fixture (in --preflight, dry-run and the test prefix only).
pf_os_file() {
  if [ -n "${FLEET_OS_RELEASE:-}" ] && { is_dry || [ "$PREFLIGHT_ONLY" = 1 ]; }; then
    printf '%s\n' "$FLEET_OS_RELEASE"
  else
    printf '/etc/os-release\n'
  fi
}

pf_check_os() {
  local file id version arch
  file=$(pf_os_file)
  if [ ! -r "$file" ]; then
    pf_emit BLOCK host.os "cannot read $file"
    return 0
  fi
  id=$(sed -n 's/^ID=//p' "$file" | head -n 1 | tr -d "\"'")
  version=$(sed -n 's/^VERSION_ID=//p' "$file" | head -n 1 | tr -d "\"'")
  if [ "$id" = debian ] && [ "$version" = 13 ]; then
    pf_emit INFO host.os "Debian 13"
  elif [ "${FLEET_FORCE_OS:-0}" = 1 ]; then
    pf_emit WARN host.os "unsupported host (${id:-unknown} ${version:-?}); FLEET_FORCE_OS=1 lets the run continue"
  else
    pf_emit BLOCK host.os "this installer supports Debian 13 only (found ${id:-unknown} ${version:-?}); FLEET_FORCE_OS=1 tries anyway"
  fi
  arch=$(uname -m)
  case $arch in
    x86_64 | amd64 | aarch64 | arm64) ;;
    *) pf_emit BLOCK host.arch "unsupported CPU architecture: $arch" ;;
  esac
}

# Free MiB on the filesystem holding $1 (or its nearest existing parent: /srv/fleet does not exist on a fresh host).
pf_free_mib() {
  local path=$1 free
  while [ ! -e "$path" ] && [ "$path" != / ]; do
    path=$(dirname -- "$path")
  done
  free=$(df -Pm -- "$path" 2>/dev/null | awk 'NR==2 {print $4}') || free=
  [[ $free =~ ^[0-9]+$ ]] && printf '%s\n' "$free"
}

pf_check_disk_one() {
  local id=$1 path=$2 min=$3 free
  free=$(pf_free_mib "$path" || true)
  if [ -z "$free" ]; then
    pf_emit WARN "$id" "cannot read the free space of $path"
  elif [ "$free" -lt "$min" ]; then
    pf_emit BLOCK "$id" "only $free MiB free on the filesystem of $path; at least $min MiB are needed. Free some space and run again"
  else
    pf_emit INFO "$id" "$free MiB free on the filesystem of $path (need $min)"
  fi
}

pf_check_disk() {
  pf_check_disk_one disk.opt "$FLEET_OPT_DIR" "$PREFLIGHT_MIN_OPT_MIB"
  pf_check_disk_one disk.srv "$FLEET_SRV_DIR" "$PREFLIGHT_MIN_SRV_MIB"
  pf_check_disk_one disk.var /var "$PREFLIGHT_MIN_VAR_MIB"
}

# Runs ddev the way the fleet runs it. DDEV refuses root ("DDEV is not designed to be run with root privileges"), and the
# install and upgrade run as root, so as root every ddev call goes through the fleet user (FLE-168). Returns 99 when
# that user does not exist yet (a fresh install): the caller reports it as not checked, never as a BLOCK.
# PREFLIGHT_EUID overrides the effective uid (tests).
pf_ddev() {
  if [ "${PREFLIGHT_EUID:-$EUID}" -ne 0 ]; then
    timeout "$PREFLIGHT_NETWORK_TIMEOUT" ddev "$@" </dev/null
    return
  fi
  getent passwd "$FLEET_USER" >/dev/null 2>&1 || return 99
  timeout "$PREFLIGHT_NETWORK_TIMEOUT" runuser -u "$FLEET_USER" -- env "HOME=$FLEET_HOME_DIR" DDEV_NONINTERACTIVE=true ddev "$@" </dev/null
}

# The ids of the existing instances, one per line: the directories DDEV knows under $FLEET_SRV_DIR/instances. Prints
# nothing, and returns 1, when neither `ddev` nor `fleet` can say.
pf_instance_ids() {
  local listing=''
  if command -v ddev >/dev/null 2>&1 && listing=$(pf_ddev list --json-output 2>/dev/null); then
    printf '%s\n' "$listing" | { grep -o '"approot": *"[^"]*"' || true; } | sed 's/^"approot": *"//; s/"$//' \
      | sed -n "s#^$FLEET_SRV_DIR/instances/\\([^/]*\\)/\\{0,1\\}\$#\\1#p"
    return 0
  fi
  if command -v fleet >/dev/null 2>&1 && listing=$(timeout "$PREFLIGHT_NETWORK_TIMEOUT" fleet instance:list --json 2>/dev/null </dev/null); then
    printf '%s\n' "$listing" | { grep -o '"id": *"[^"]*"' || true; } | sed 's/^"id": *"//; s/"$//'
    return 0
  fi
  return 1
}

pf_check_instances() {
  local ids id long=() count=0
  if ! ids=$(pf_instance_ids); then
    pf_emit INFO instances.id-length "no way to list the instances (ddev and fleet do not answer); not checked"
    return 0
  fi
  while IFS= read -r id; do
    [ -n "$id" ] || continue
    count=$((count + 1))
    [ "${#id}" -le "$PREFLIGHT_MAX_INSTANCE_ID" ] || long+=("$id")
  done <<<"$ids"
  if [ "${#long[@]}" -gt 0 ]; then
    pf_emit BLOCK instances.id-length "${#long[@]} instance id(s) longer than $PREFLIGHT_MAX_INSTANCE_ID characters (they cannot run: ${long[*]}). Destroy them or recreate them with a shorter label"
  else
    pf_emit INFO instances.id-length "$count instance id(s), none longer than $PREFLIGHT_MAX_INSTANCE_ID characters"
  fi
}

# docker and ddev: absent is fine on a host that is not installed yet, a problem on one that is.
pf_check_tool() {
  local id=$1 cmd=$2 rc=0
  shift 2
  if ! command -v "$cmd" >/dev/null 2>&1; then
    if pf_installed; then
      pf_emit BLOCK "$id" "$cmd is not installed, but this host has a ddev-fleet install"
    else
      pf_emit INFO "$id" "$cmd is not installed yet (step 20/40 installs it)"
    fi
  elif [ "$cmd" = ddev ]; then
    pf_ddev "$@" >/dev/null 2>&1 || rc=$?
    if [ "$rc" -eq 0 ]; then
      pf_emit INFO "$id" "$cmd answers"
    elif [ "$rc" -eq 99 ]; then
      pf_emit INFO "$id" "$cmd is installed, but the $FLEET_USER user does not exist yet (DDEV refuses root); not checked"
    else
      pf_emit BLOCK "$id" "$cmd is installed but does not answer ($cmd $*). Fix it before installing or upgrading"
    fi
  elif timeout "$PREFLIGHT_NETWORK_TIMEOUT" "$cmd" "$@" >/dev/null 2>&1 </dev/null; then
    pf_emit INFO "$id" "$cmd answers"
  else
    pf_emit BLOCK "$id" "$cmd is installed but does not answer ($cmd $*). Fix it before installing or upgrading"
  fi
}

pf_check_packages() {
  local missing=() cmd
  if ! command -v apt-get >/dev/null 2>&1 || ! command -v dpkg >/dev/null 2>&1; then
    pf_emit BLOCK host.packages "apt-get and dpkg are required (a Debian host)"
    return 0
  fi
  for cmd in "${PREFLIGHT_COMMANDS[@]}"; do
    command -v "$cmd" >/dev/null 2>&1 || missing+=("$cmd")
  done
  if [ "${#missing[@]}" -eq 0 ]; then
    pf_emit INFO host.packages "required commands present"
  elif pf_installed; then
    pf_emit WARN host.packages "missing: ${missing[*]} (step 10 installs them)"
  else
    pf_emit INFO host.packages "not installed yet: ${missing[*]} (step 10 installs them)"
  fi
}

pf_env_value() {
  sed -n "s/^$2=//p" "$1" | tail -n 1
}

pf_check_env() {
  local file=$FLEET_ETC_DIR/fleet.env key missing=() secret
  if [ ! -e "$file" ]; then
    pf_emit INFO fleet.env "no $file yet (a first install creates it)"
    return 0
  fi
  if [ ! -r "$file" ]; then
    pf_emit INFO fleet.env "$file is not readable by this user (run with sudo to check it)"
    return 0
  fi
  for key in "${PREFLIGHT_ENV_KEYS[@]}"; do
    grep -q "^$key=." "$file" || missing+=("$key")
  done
  secret=$(pf_env_value "$file" MERCURE_JWT_SECRET)
  if [ -n "$secret" ] && ! [[ $secret =~ ^[A-Za-z0-9._~+/=-]{32,}$ ]]; then
    pf_emit BLOCK fleet.env "MERCURE_JWT_SECRET in $file is unusable (32 or more of A-Za-z0-9._~+/=-); the install stops at step 70"
  elif [ "${#missing[@]}" -gt 0 ]; then
    pf_emit INFO fleet.env "$file lacks ${missing[*]} (step 70 adds them)"
  else
    pf_emit INFO fleet.env "$file has the required keys"
  fi
}

# GitHub (or whatever --repo-url names) must answer: the install clones from it. A path or bundle is not a network source.
pf_check_github() {
  local url=$REPO_URL
  if [[ $url != http*://* ]]; then
    pf_emit INFO network.repo "--repo-url is not a network address; not checked"
    return 0
  fi
  if curl -fsSI --max-time "$PREFLIGHT_NETWORK_TIMEOUT" -o /dev/null -- "$url" 2>/dev/null; then
    pf_emit INFO network.repo "$url answers"
  else
    pf_emit BLOCK network.repo "$url does not answer within ${PREFLIGHT_NETWORK_TIMEOUT}s; the install clones from it"
  fi
}

# Runs every check; returns 0, or $EX_PREFLIGHT when anything BLOCKs.
preflight_run() {
  PREFLIGHT_BLOCKS=0
  pf_check_os
  pf_check_disk
  pf_check_packages
  pf_check_tool docker.answer docker info
  pf_check_tool ddev.answer ddev version
  pf_check_instances
  pf_check_env
  pf_check_github
  [ "$PREFLIGHT_BLOCKS" -eq 0 ] || return "$EX_PREFLIGHT"
}
