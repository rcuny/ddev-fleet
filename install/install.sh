#!/usr/bin/env bash
# ddev-fleet installer, stage 1 (bootstrap). Safe to pipe into a shell:
#
#   curl -fsSL <url of this file> | sudo bash -s -- --domain=fleet.example.com
#
# Stage 1 validates the flags, installs the three packages it needs (ca-certificates, curl, git), creates the `fleet`
# user, clones the requested ref into /opt/ddev-fleet (as `fleet`, never as root), copies that checkout's install/
# directory to a root-owned location and hands over to its run.sh, which does the real work. Re-running upgrades.
#
# Everything lives in main(), called on the last line: a truncated download is a syntax error instead of a partial
# run, and no child process reads the script from stdin. Run with --help for the flags; --dry-run changes nothing.
#
# This file is self-contained on purpose (it runs before any checkout exists); the flag checks mirror
# install/lib/common.sh and a test keeps the two in step.

main() {
  set -Eeuo pipefail
  export LC_ALL=C
  # sudo and minimal environments often lack the sbin directories.
  PATH="$PATH:/usr/local/sbin:/usr/sbin:/sbin"
  export PATH
  # Nothing below may read the rest of the script (or the terminal) from stdin.
  exec </dev/null
  # Where this script lives, when it is a file (not when piped into a shell).
  local self_dir=''
  if [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then
    self_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
  fi

  local domain='' acme_email='' ref=develop dry_run=0 arg
  local repo_url='https://github.com/rcuny/ddev-fleet.git'
  local opt_dir=/opt/ddev-fleet stage_dir=/usr/local/lib/ddev-fleet-installer fleet_home=/home/fleet

  say() { printf '%s\n' "$*"; }
  fail() {
    local code=$1
    shift
    printf 'ERROR: %s\n' "$*" >&2
    exit "$code"
  }
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
USAGE
  }
  valid_domain() {
    local d=$1
    [ "${#d}" -ge 4 ] && [ "${#d}" -le 253 ] || return 1
    [[ $d =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$ ]] || return 1
    [[ $d != *..* ]]
  }
  valid_email() {
    local e=$1
    [ "${#e}" -ge 3 ] && [ "${#e}" -le 254 ] || return 1
    [[ $e =~ ^[A-Za-z0-9._%+-]{1,64}@([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ ]]
  }
  # Runs a command with stdin closed; in dry-run prints it instead.
  step() {
    if [ "$dry_run" = 1 ]; then
      printf '+'
      printf ' %q' "$@"
      printf '\n'
      return 0
    fi
    "$@" </dev/null
  }
  as_fleet() {
    step runuser -u fleet -- env "HOME=$fleet_home" GIT_TERMINAL_PROMPT=0 "$@"
  }

  trap 'printf "\nERROR: stage 1 failed (exit %s, install.sh line %s). Fix the cause and re-run the same command: the installer is idempotent.\n" "$?" "$LINENO" >&2' ERR

  # --- 1. flags: nothing is touched before they are valid -------------------------------------------------------
  for arg in "$@"; do
    case $arg in
      --help | -h)
        usage
        return 0
        ;;
      --domain=*) domain=${arg#--domain=} ;;
      --acme-email=*) acme_email=${arg#--acme-email=} ;;
      --ref=*) ref=${arg#--ref=} ;;
      --repo-url=*) repo_url=${arg#--repo-url=} ;;
      --skip-dns-check) ;;
      --dry-run) dry_run=1 ;;
      *)
        usage >&2
        fail 64 "unknown argument: ${arg:0:60}"
        ;;
    esac
  done
  if [ -z "$domain" ]; then
    usage >&2
    fail 64 "--domain=<fqdn> is required"
  fi
  valid_domain "$domain" || fail 64 "--domain must be a lowercase fully qualified host name such as fleet.example.com (no wildcard, port or IP address)"
  if [ -n "$acme_email" ]; then
    valid_email "$acme_email" || fail 64 "--acme-email is not a valid email address"
  fi
  if ! [[ $ref =~ ^[A-Za-z0-9][A-Za-z0-9._/+-]{0,199}$ ]] || [[ $ref == *..* ]]; then
    fail 64 "--ref is not a valid git ref"
  fi
  [[ $repo_url =~ ^[A-Za-z0-9/][A-Za-z0-9._/:@+~%=-]{0,499}$ ]] || fail 64 "--repo-url is not a valid url or path"

  # A bundle or checkout given as a relative path must survive the cd below.
  if [ -e "$repo_url" ] && [[ $repo_url != /* ]]; then
    repo_url="$PWD/$repo_url"
  fi
  # The `fleet` user runs git and DDEV from here: the caller's directory (a home directory, say) may be closed to it.
  cd /

  # --- 2. host checks ---------------------------------------------------------------------------------------------
  if [ "$dry_run" != 1 ] && [ "$(id -u)" -ne 0 ]; then
    fail 77 "run as root (sudo bash install.sh ...), or add --dry-run to only print the plan"
  fi
  local os_file=/etc/os-release os_id os_version
  if [ "$dry_run" = 1 ] && [ -n "${FLEET_OS_RELEASE:-}" ]; then
    os_file=$FLEET_OS_RELEASE
  fi
  [ -r "$os_file" ] || fail 78 "cannot read $os_file"
  os_id=$(sed -n 's/^ID=//p' "$os_file" | head -n 1 | tr -d "\"'")
  os_version=$(sed -n 's/^VERSION_ID=//p' "$os_file" | head -n 1 | tr -d "\"'")
  if [ "$os_id" != debian ] || [ "$os_version" != 13 ]; then
    if [ "${FLEET_FORCE_OS:-0}" = 1 ]; then
      say "WARNING: unsupported host (${os_id:-unknown} ${os_version:-?}); continuing because FLEET_FORCE_OS=1" >&2
    else
      fail 78 "this installer supports Debian 13 only (found ${os_id:-unknown} ${os_version:-?}). Set FLEET_FORCE_OS=1 to try anyway."
    fi
  fi
  case $(uname -m) in
    x86_64 | amd64 | aarch64 | arm64) ;;
    *) fail 78 "unsupported CPU architecture: $(uname -m)" ;;
  esac

  # --- 3. stage 1 work --------------------------------------------------------------------------------------------
  say "[stage 1] bootstrap for $domain (ref $ref)"
  step env DEBIAN_FRONTEND=noninteractive apt-get update -q
  step env DEBIAN_FRONTEND=noninteractive apt-get install -y -q ca-certificates curl git iproute2

  # The checkout belongs to `fleet`; git never runs as root in it (no "dubious ownership" on a re-run).
  if [ "$dry_run" = 1 ] || ! id -u fleet >/dev/null 2>&1; then
    step useradd --system --create-home --home-dir "$fleet_home" --shell /bin/bash --user-group fleet
  fi
  step install -d -m 0755 -o fleet -g fleet "$opt_dir"
  if [ "$dry_run" = 1 ] || [ ! -d "$opt_dir/.git" ]; then
    if [ "$dry_run" != 1 ] && [ -n "$(ls -A "$opt_dir")" ]; then
      fail 1 "$opt_dir exists, is not empty and is not a git checkout; move it away and re-run"
    fi
    as_fleet git clone --quiet -- "$repo_url" "$opt_dir"
  fi
  as_fleet git -C "$opt_dir" remote set-url origin "$repo_url"
  as_fleet git -C "$opt_dir" fetch --quiet --tags --prune origin
  # A branch follows origin (fast-forward only); a tag or commit is checked out detached.
  if [ "$dry_run" = 1 ]; then
    as_fleet git -C "$opt_dir" checkout --quiet "$ref"
    as_fleet git -C "$opt_dir" merge --quiet --ff-only "origin/$ref"
  elif runuser -u fleet -- env "HOME=$fleet_home" git -C "$opt_dir" rev-parse -q --verify "refs/remotes/origin/$ref^{commit}" >/dev/null 2>&1; then
    as_fleet git -C "$opt_dir" checkout --quiet "$ref" 2>/dev/null || as_fleet git -C "$opt_dir" checkout --quiet -b "$ref" "origin/$ref"
    as_fleet git -C "$opt_dir" merge --quiet --ff-only "origin/$ref"
  else
    as_fleet git -C "$opt_dir" checkout --quiet --detach "$ref"
  fi

  # Stage 2 runs from a root-owned export of the committed install/ tree, never from the fleet-writable checkout.
  if [ "$dry_run" = 1 ]; then
    say "+ export install/ of the checkout to $stage_dir (root-owned)"
  else
    rm -rf "$stage_dir.new"
    install -d -m 0755 -o root -g root "$stage_dir.new"
    runuser -u fleet -- env "HOME=$fleet_home" git -C "$opt_dir" archive --format=tar HEAD install | tar -x --no-same-owner -C "$stage_dir.new"
    [ -f "$stage_dir.new/install/run.sh" ] || fail 1 "ref $ref has no install/run.sh"
    rm -rf "$stage_dir"
    mv "$stage_dir.new" "$stage_dir"
  fi

  # --- 4. hand over -----------------------------------------------------------------------------------------------
  if [ "$dry_run" = 1 ]; then
    if [ -n "$self_dir" ] && [ -f "$self_dir/run.sh" ]; then
      exec bash "$self_dir/run.sh" "$@"
    fi
    say "+ exec bash $stage_dir/install/run.sh $*"
    say "dry-run: the step plan is printed when this script runs from a checkout (bash install/install.sh --dry-run ...)"
    return 0
  fi
  exec env "FLEET_SRC_DIR=$opt_dir" bash "$stage_dir/install/run.sh" "$@"
}

main "$@"
