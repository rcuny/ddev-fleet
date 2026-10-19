# shellcheck shell=bash
# shellcheck disable=SC2034  # constants below are consumed by run.sh and the steps that source this file
#
# Helpers shared by install/run.sh and install/steps/*.sh. Sourced, never executed.
#
# Dry-run contract: with FLEET_DRY_RUN=1 nothing is changed. `run` and the file helpers print what they would do as
# "+ ..." lines (some are pseudo-commands such as "+ write" or "+ dir"). Read-only probes still run (DNS, os-release).
# The plan always lists every action of a step, whether or not the host already satisfies it; at run time a step
# skips what is already in place.

: "${FLEET_DRY_RUN:=0}"

# Exit codes (sysexits-style, plus 2 for DNS as documented in docs/installation.md).
EX_DNS=2
EX_USAGE=64
EX_TEMPFAIL=75
EX_NOTROOT=77
EX_UNSUPPORTED=78

# Fixed layout (spec section 8, plan P5/P6).
FLEET_USER=fleet
FLEET_HOME_DIR=/home/fleet
FLEET_OPT_DIR=/opt/ddev-fleet
FLEET_SRV_DIR=/srv/fleet
FLEET_ETC_DIR=/etc/ddev-fleet
CADDY_ETC_DIR=/etc/caddy
CADDY_FLEET_DIR=/etc/caddy/fleet
SYSTEMD_DIR=/etc/systemd/system
KEYRING_DIR=/etc/apt/keyrings
APT_SOURCES_DIR=/etc/apt/sources.list.d
INSTALL_LOG=/var/log/ddev-fleet-install.log

# The custom Caddy (OVH DNS module) must be at least this version (the version the app's configs were validated with).
# The build API ignores a requested version, so the installer cannot pin; it compares and refreshes instead.
CADDY_MIN_VERSION=2.11.7

# Parsed flags (parse_args).
DOMAIN=
ACME_EMAIL=
REF=develop
REPO_URL=https://github.com/rcuny/ddev-fleet.git
SKIP_DNS_CHECK=0

# Name of the step being run, for the error trap.
STEP_NAME=startup
APT_DIRTY=0
FILE_CHANGED=0

is_dry() {
  [ "${FLEET_DRY_RUN:-0}" = 1 ]
}

# Every real step line starts with "==>"; nothing else may, the plan test counts them.
log_step() {
  STEP_NAME=$1
  printf '==> %s\n' "$1"
}

log_info() {
  printf '    %s\n' "$*"
}

log_warn() {
  printf 'WARNING: %s\n' "$*" >&2
}

die() {
  local code=$1
  shift
  printf 'ERROR: %s\n' "$*" >&2
  exit "$code"
}

REPORTED=0

# Prints which step failed and what to do, once per run. $2 is "file:line" when known.
report_failure() {
  [ "$REPORTED" = 0 ] || return 0
  REPORTED=1
  local where=${2:+, at $2}
  printf '\nERROR: step "%s" failed (exit %s%s).\n' "$STEP_NAME" "$1" "$where" >&2
  printf 'Fix the cause and re-run the same command: the installer is idempotent and continues from where it stopped.\n' >&2
  if ! is_dry; then
    printf 'Log: %s\n' "$INSTALL_LOG" >&2
  fi
}

# Writes to the terminal even while stdout is teed into the log (fd 3 is the original stdout, set up by run.sh).
# Used for the one-time setup URL, which must never land in the install log.
term_print() {
  if { true >&3; } 2>/dev/null; then
    printf '%s\n' "$*" >&3
  else
    printf '%s\n' "$*"
  fi
}

# Runs a command with stdin closed (an apt/ddev/mkcert prompt must never eat the installer's own input); in dry-run
# prints it instead.
run() {
  if is_dry; then
    printf '+'
    printf ' %q' "$@"
    printf '\n'
    return 0
  fi
  "$@" </dev/null
}

# Runs a command as the fleet user with a sane HOME. Never needed for root-only work.
run_as_fleet() {
  run runuser -u "$FLEET_USER" -- env "HOME=$FLEET_HOME_DIR" DDEV_NONINTERACTIVE=true "$@"
}

# True in dry-run (so the plan is complete) or when the command is not installed yet.
missing_or_dry() {
  is_dry || ! command -v "$1" >/dev/null 2>&1
}

# --- argument handling -------------------------------------------------------------------------------------------

usage() {
  cat <<'USAGE'
Usage: install.sh --domain=<fqdn> [options]

Installs ddev-fleet on a fresh Debian 13 host. Re-running upgrades an existing install.

Required:
  --domain=<fqdn>       public host name of this fleet, e.g. fleet.example.com. The DNS records for
                        <fqdn> and auth.<fqdn> (or one wildcard record) must already point at this host.

Options:
  --acme-email=<email>  contact address for Let's Encrypt
  --ref=<ref>           branch, tag or commit to install (default: develop)
  --repo-url=<url|path> git remote or bundle to install from (default: the public GitHub mirror)
  --skip-dns-check      do not compare the DNS records with this host's addresses
  --dry-run             print the plan and change nothing (works without root)
  --help                this text

Exit codes: 0 ok, 2 DNS mismatch, 64 usage, 77 not root, 78 unsupported host.

The install takes several minutes. Run it inside screen (or systemd-run): a dropped SSH session would interrupt it.
If it is interrupted anyway, run the same command again.
USAGE
}

# Domain: lowercase, at least two labels of [a-z0-9-] (1-63 chars, no leading or trailing dash), final label starts
# with a letter (so an IP address is refused), no wildcard, no port. Mirrors App\Fleet\System\ConfigValue::domain.
validate_domain() {
  local LC_ALL=C
  local d=$1
  [ "${#d}" -ge 4 ] && [ "${#d}" -le 253 ] || return 1
  [[ $d =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$ ]] || return 1
  [[ $d != *..* ]]
}

# Mirrors App\Fleet\System\ConfigValue::acmeEmail.
validate_email() {
  local LC_ALL=C
  local e=$1
  [ "${#e}" -ge 3 ] && [ "${#e}" -le 254 ] || return 1
  [[ $e =~ ^[A-Za-z0-9._%+-]{1,64}@([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ ]]
}

# A git ref or a remote: never an option, no whitespace or control characters.
validate_ref() {
  local LC_ALL=C
  [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9._/+-]{0,199}$ ]] && [[ $1 != *..* ]]
}

validate_repo_url() {
  local LC_ALL=C
  [[ $1 =~ ^[A-Za-z0-9/][A-Za-z0-9._/:@+~%=-]{0,499}$ ]]
}

parse_args() {
  local arg
  for arg in "$@"; do
    case $arg in
      --help | -h)
        usage
        exit 0
        ;;
      --domain=*) DOMAIN=${arg#--domain=} ;;
      --acme-email=*) ACME_EMAIL=${arg#--acme-email=} ;;
      --ref=*) REF=${arg#--ref=} ;;
      --repo-url=*) REPO_URL=${arg#--repo-url=} ;;
      --skip-dns-check) SKIP_DNS_CHECK=1 ;;
      --dry-run) FLEET_DRY_RUN=1 ;;
      *)
        usage >&2
        die "$EX_USAGE" "unknown argument: ${arg:0:60}"
        ;;
    esac
  done
  [ -n "$DOMAIN" ] || {
    usage >&2
    die "$EX_USAGE" "--domain=<fqdn> is required"
  }
  validate_domain "$DOMAIN" || die "$EX_USAGE" "--domain must be a lowercase fully qualified host name such as fleet.example.com (no wildcard, port or IP address)"
  if [ -n "$ACME_EMAIL" ]; then
    validate_email "$ACME_EMAIL" || die "$EX_USAGE" "--acme-email is not a valid email address"
  fi
  validate_ref "$REF" || die "$EX_USAGE" "--ref is not a valid git ref"
  validate_repo_url "$REPO_URL" || die "$EX_USAGE" "--repo-url is not a valid url or path"
}

# --- host checks -------------------------------------------------------------------------------------------------

check_root() {
  is_dry && return 0
  [ "$(id -u)" -eq 0 ] || die "$EX_NOTROOT" "run as root (sudo bash install.sh ...), or add --dry-run to only print the plan"
}

# Debian 13 only. FLEET_OS_RELEASE (a fixture for the tests) is honoured in dry-run only; FLEET_FORCE_OS=1 overrides.
check_os() {
  local file=/etc/os-release id version
  if is_dry && [ -n "${FLEET_OS_RELEASE:-}" ]; then
    file=$FLEET_OS_RELEASE
  fi
  [ -r "$file" ] || die "$EX_UNSUPPORTED" "cannot read $file"
  id=$(sed -n 's/^ID=//p' "$file" | head -n 1 | tr -d "\"'")
  version=$(sed -n 's/^VERSION_ID=//p' "$file" | head -n 1 | tr -d "\"'")
  if [ "$id" != debian ] || [ "$version" != 13 ]; then
    if [ "${FLEET_FORCE_OS:-0}" = 1 ]; then
      log_warn "unsupported host (${id:-unknown} ${version:-?}); continuing because FLEET_FORCE_OS=1"
    else
      die "$EX_UNSUPPORTED" "this installer supports Debian 13 only (found ${id:-unknown} ${version:-?}). Set FLEET_FORCE_OS=1 to try anyway."
    fi
  fi
  detect_arch >/dev/null
}

# amd64 or arm64 (the Debian names); anything else is unsupported.
detect_arch() {
  case $(uname -m) in
    x86_64 | amd64) printf 'amd64\n' ;;
    aarch64 | arm64) printf 'arm64\n' ;;
    *) die "$EX_UNSUPPORTED" "unsupported CPU architecture: $(uname -m)" ;;
  esac
}

# --- DNS ----------------------------------------------------------------------------------------------------------

# Addresses (one per line) of family 4 or 6 that this host owns: global addresses on its interfaces.
local_addrs() {
  { ip -o "-$1" addr show scope global 2>/dev/null || true; } | awk '{print $4}' | cut -d/ -f1 | tr 'A-F' 'a-f' | sort -u
}

# The address the Internet sees for this host (differs from the local ones behind NAT). Empty when unavailable.
public_addr() {
  local url=https://api.ipify.org flag=-4
  if [ "$1" = 6 ]; then
    url=https://api6.ipify.org
    flag=-6
  fi
  curl "$flag" -fsS --proto '=https' --tlsv1.2 --max-time 10 "$url" 2>/dev/null </dev/null | tr 'A-F' 'a-f' | tr -d '[:space:]' || true
}

# DNS records of family 4 or 6 for a name, one per line. This asks the resolver (dig), not the name service switch:
# `getent` would read /etc/hosts first, where Debian maps the host's own name to 127.0.1.1, and a line there could
# also make a wrong public record look right. CNAME lines in the answer are dropped.
dns_records() {
  local family=$1 name=$2 type=A pattern='^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'
  if [ "$family" = 6 ]; then
    type=AAAA
    pattern='^[0-9a-fA-F:]*:[0-9a-fA-F:.]*$'
  fi
  { dig +short +time=3 +tries=2 "$type" "$name" 2>/dev/null || true; } | { grep -E "$pattern" || true; } | tr 'A-F' 'a-f' | sort -u
}

# True when $1 is an entry of the newline-separated list $2.
in_list() {
  [[ $'\n'$2$'\n' == *$'\n'"$1"$'\n'* ]]
}

# Compares the A and AAAA records of <domain> and auth.<domain> with this host's addresses. Let's Encrypt validates
# over IPv6 when an AAAA record exists, so a stale AAAA is as fatal as a wrong A. Exits 2 listing every problem.
check_dns() {
  local domain=$1 name family rec problems=0 local_set public_set known
  if [ "$SKIP_DNS_CHECK" = 1 ]; then
    log_info "DNS check skipped (--skip-dns-check)"
    return 0
  fi
  command -v dig >/dev/null 2>&1 || die "$EX_USAGE" "dig is needed for the DNS check (apt-get install bind9-dnsutils), or pass --skip-dns-check"
  for name in "$domain" "auth.$domain"; do
    for family in 4 6; do
      local_set=$(local_addrs "$family")
      public_set=
      local recs
      recs=$(dns_records "$family" "$name")
      if [ -z "$recs" ]; then
        if [ "$family" = 4 ]; then
          printf 'ERROR: %s has no A record (or the DNS lookup failed).\n' "$name" >&2
          problems=$((problems + 1))
        fi
        continue
      fi
      while IFS= read -r rec; do
        if in_list "$rec" "$local_set"; then
          continue
        fi
        if [ -z "$public_set" ]; then
          public_set=$(public_addr "$family")
          [ -n "$public_set" ] || public_set=-
        fi
        if in_list "$rec" "$public_set"; then
          continue
        fi
        known=$(printf '%s\n%s\n' "$local_set" "$public_set" | { grep -v -e '^-$' -e '^$' || true; } | sort -u | paste -sd, -)
        if [ "$family" = 4 ]; then
          printf 'ERROR: %s has the A record %s, which is not an address of this host (%s).\n' "$name" "$rec" "${known:-none found}" >&2
        else
          printf 'ERROR: %s has the AAAA record %s, which is not an IPv6 address of this host (%s). Fix it or remove the AAAA record: Let'"'"'s Encrypt prefers IPv6.\n' "$name" "$rec" "${known:-none found}" >&2
        fi
        problems=$((problems + 1))
      done <<<"$recs"
    done
  done
  if [ "$problems" -gt 0 ]; then
    printf 'Point the DNS records of %s and auth.%s (or one wildcard record) at this host, wait for them to propagate, then re-run. --skip-dns-check bypasses this check.\n' "$domain" "$domain" >&2
    exit "$EX_DNS"
  fi
  log_info "DNS ok: $domain and auth.$domain resolve to this host"
}

# --- files --------------------------------------------------------------------------------------------------------

# ensure_dir <path> <mode> <owner:group>: creates the directory and sets owner and mode every time (repairs drift,
# e.g. a lost setgid bit).
ensure_dir() {
  local dir=$1 mode=$2 owner=$3
  if is_dry; then
    printf '+ dir %s %s %s\n' "$dir" "$mode" "$owner"
    return 0
  fi
  mkdir -p "$dir"
  chown "$owner" "$dir"
  chmod "$mode" "$dir"
}

# write_file <path> <mode> <owner:group>  (content on stdin). Atomic (temp file in the same directory, then rename).
# Sets FILE_CHANGED=1 when the content differs from what was there.
write_file() {
  local path=$1 mode=$2 owner=$3 tmp
  FILE_CHANGED=0
  if is_dry; then
    printf '+ write %s %s %s\n' "$path" "$mode" "$owner"
    cat >/dev/null
    FILE_CHANGED=1
    return 0
  fi
  tmp=$(mktemp "$path.XXXXXX")
  cat >"$tmp"
  chown "$owner" "$tmp"
  chmod "$mode" "$tmp"
  if [ -f "$path" ] && cmp -s "$tmp" "$path"; then
    rm -f "$tmp"
    chown "$owner" "$path"
    chmod "$mode" "$path"
    return 0
  fi
  mv -f "$tmp" "$path"
  FILE_CHANGED=1
}

# install_etc <file under install/etc> <destination> <mode> <owner:group>
install_etc() {
  write_file "$2" "$3" "$4" <"$INSTALL_DIR/etc/$1"
}

# write_file_once: like write_file but leaves an existing non-empty file untouched.
write_file_once() {
  local path=$1
  if ! is_dry && [ -s "$path" ]; then
    FILE_CHANGED=0
    cat >/dev/null
    return 0
  fi
  write_file "$@"
}

# --- apt ----------------------------------------------------------------------------------------------------------

fetch() {
  local url=$1 out=$2
  case $url in
    https://*) ;;
    *) die 1 "refusing a non-https download: $url" ;;
  esac
  if is_dry; then
    printf '+ fetch %s -> %s\n' "$url" "$out"
    return 0
  fi
  curl -fsSL --proto '=https' --tlsv1.2 --retry 3 --connect-timeout 15 -o "$out" "$url" </dev/null
}

apt_mark_dirty() {
  APT_DIRTY=1
}

# A previous run killed in the middle of dpkg (a dropped SSH session, Ctrl-C) leaves it unconfigured, and every apt
# call then refuses until `dpkg --configure -a` has run. Checked once per run, before the first apt action.
DPKG_UPDATES_DIR=/var/lib/dpkg/updates
DPKG_CHECKED=0

dpkg_interrupted() {
  [ -n "$(dpkg --audit 2>/dev/null || true)" ] || [ -n "$(find "$DPKG_UPDATES_DIR" -maxdepth 1 -type f 2>/dev/null || true)" ]
}

dpkg_recover() {
  [ "$DPKG_CHECKED" = 0 ] || return 0
  DPKG_CHECKED=1
  if is_dry || dpkg_interrupted; then
    log_info "dpkg was interrupted (or may have been): repairing before any apt action"
    run dpkg --configure -a
    apt_get -f install
  fi
}

# Every apt-get call of the installer goes through here: non-interactive, existing configuration files kept (a file
# we rendered must survive a package upgrade, and a prompt on closed stdin would abort the run), and a wait for the
# dpkg lock (a fresh image is often running unattended upgrades).
apt_get() {
  run env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get -y -q \
    -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold -o DPkg::Lock::Timeout=120 "$@"
}

apt_refresh() {
  dpkg_recover
  if [ "$APT_DIRTY" = 1 ]; then
    apt_get update
    APT_DIRTY=0
  fi
}

apt_install() {
  apt_refresh
  apt_get install "$@"
}

# --- no service start during a package install ------------------------------------------------------------------

POLICY_RC=/usr/sbin/policy-rc.d
POLICY_MARKER='# ddev-fleet installer: temporary, removed after the install'

# invoke-rc.d and deb-systemd-invoke ask policy-rc.d before starting a service; 101 means "not allowed".
policy_deny_start() {
  if is_dry; then
    printf '+ write %s 0755 root:root (exit 101: no service starts during the install)\n' "$POLICY_RC"
    return 0
  fi
  if [ -e "$POLICY_RC" ] && ! grep -qxF "$POLICY_MARKER" "$POLICY_RC"; then
    log_warn "$POLICY_RC exists and is not ours; leaving it in place"
    return 0
  fi
  printf '#!/bin/sh\n%s\nexit 101\n' "$POLICY_MARKER" | write_file "$POLICY_RC" 0755 root:root
}

# Removes only our own file; safe to call at any time (also from the exit trap and after a killed run).
policy_allow_start() {
  if is_dry; then
    printf '+ rm -f %s (only if the installer wrote it)\n' "$POLICY_RC"
    return 0
  fi
  if [ -f "$POLICY_RC" ] && grep -qxF "$POLICY_MARKER" "$POLICY_RC"; then
    rm -f "$POLICY_RC"
  fi
  return 0
}

# apt_repo <name> <key-url> <key-file> <sources line>: key and one-line source, updated only when they changed.
apt_repo() {
  local name=$1 key_url=$2 key_file=$3 line=$4 tmp
  if is_dry; then
    fetch "$key_url" "$key_file"
    printf '+ write %s/%s.list: %s\n' "$APT_SOURCES_DIR" "$name" "$line"
    APT_DIRTY=1
    return 0
  fi
  install -d -m 0755 -o root -g root "$KEYRING_DIR"
  tmp=$(mktemp)
  fetch "$key_url" "$tmp"
  [ -s "$tmp" ] || die 1 "empty signing key from $key_url"
  if ! cmp -s "$tmp" "$key_file" 2>/dev/null; then
    install -m 0644 -o root -g root "$tmp" "$key_file"
    APT_DIRTY=1
  fi
  rm -f "$tmp"
  write_file "$APT_SOURCES_DIR/$name.list" 0644 root:root <<<"$line"
  if [ "$FILE_CHANGED" = 1 ]; then
    APT_DIRTY=1
  fi
}

# True when $1 is a strictly older dotted version than $2 (leading "v" ignored).
version_lt() {
  local a=${1#v} b=${2#v}
  [ "$a" != "$b" ] && [ "$(printf '%s\n%s\n' "$a" "$b" | sort -V | head -n 1)" = "$a" ]
}
