#!/usr/bin/env bash
# FLE-193: keep the Tailwind CLI binary in a host cache so a build never depends on a live GitHub release download.
#
# symfonycasts/tailwind-bundle downloads app/var/tailwind/<version>/tailwindcss-linux-<arch> from GitHub releases when
# that file is missing or empty (and GitHub answers 504 now and then). This script, run as `fleet` by install step 80
# before `tailwind:build`, makes sure the file exists:
#
#   1. cache hit: <cache>/<version>/<name> whose SHA-256 matches the one stored beside it is copied into place;
#   2. cache miss: the binary and the release's sha256sums.txt are downloaded with retries, the hash is verified, the
#      binary is cached (atomic rename) and copied into place; other versions are removed from the cache.
#
# Usage: tailwind-seed.sh <version, e.g. v4.3.3> <cache dir> <app var/tailwind dir>
# Environment (tests): FLEET_TAILWIND_BASE_URL (default: the GitHub releases download URL), FLEET_TAILWIND_ATTEMPTS
# (default 5), FLEET_TAILWIND_RETRY_DELAY seconds (default 10), FLEET_TAILWIND_ARCH (x64|arm64, default: uname -m).
# Exit 0 = the binary is in place. Non-zero = it is not (the caller carries on: the bundle then tries its own download).

set -euo pipefail

BASE_URL=${FLEET_TAILWIND_BASE_URL:-https://github.com/tailwindlabs/tailwindcss/releases/download}
ATTEMPTS=${FLEET_TAILWIND_ATTEMPTS:-5}
DELAY=${FLEET_TAILWIND_RETRY_DELAY:-10}

log() { printf 'tailwind-seed: %s\n' "$*"; }

[ "$#" -eq 3 ] || { echo "usage: $0 <version> <cache dir> <var/tailwind dir>" >&2; exit 64; }
VERSION=$1
CACHE_DIR=$2
DEST_DIR=$3
[[ $VERSION =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { log "invalid version '$VERSION'"; exit 64; }

ARCH=${FLEET_TAILWIND_ARCH:-}
if [ -z "$ARCH" ]; then
  case $(uname -m) in
    x86_64 | amd64) ARCH=x64 ;;
    aarch64 | arm64) ARCH=arm64 ;;
    *) log "unsupported architecture $(uname -m)"; exit 1 ;;
  esac
fi
NAME=tailwindcss-linux-$ARCH   # the bundle's 'auto' name on Debian (glibc)

CACHED=$CACHE_DIR/$VERSION/$NAME
CACHED_SUM=$CACHED.sha256
DEST=$DEST_DIR/$VERSION/$NAME

sha_of() { sha256sum "$1" | cut -d' ' -f1; }

# Downloads $1 to $2 with retries (curl's own, plus a delay; 504s are retried).
fetch() {
  curl -fsSL --retry "$ATTEMPTS" --retry-all-errors --retry-delay "$DELAY" --connect-timeout 20 --max-time 180 -o "$2" "$1"
}

cache_valid() {
  [ -s "$CACHED" ] && [ -s "$CACHED_SUM" ] && [ "$(sha_of "$CACHED")" = "$(tr -d '[:space:]' <"$CACHED_SUM")" ]
}

populate_cache() {
  local tmp sums expected actual
  mkdir -p "$CACHE_DIR/$VERSION"
  tmp=$(mktemp -d "$CACHE_DIR/.dl.XXXXXX")
  # shellcheck disable=SC2064  # $tmp is expanded now on purpose
  trap "rm -rf '$tmp'" RETURN
  log "downloading $NAME $VERSION"
  fetch "$BASE_URL/$VERSION/sha256sums.txt" "$tmp/sums" || { log "could not download sha256sums.txt"; return 1; }
  expected=$(awk -v n="$NAME" '{f=$2; sub(/^[*.\/]+/, "", f); if (f == n) {print tolower($1); exit}}' "$tmp/sums")
  [ -n "$expected" ] || { log "no checksum for $NAME in sha256sums.txt"; return 1; }
  fetch "$BASE_URL/$VERSION/$NAME" "$tmp/bin" || { log "could not download $NAME"; return 1; }
  [ -s "$tmp/bin" ] || { log "downloaded $NAME is empty"; return 1; }
  actual=$(sha_of "$tmp/bin")
  [ "$actual" = "$expected" ] || { log "checksum mismatch for $NAME (expected $expected, got $actual)"; return 1; }
  chmod 755 "$tmp/bin"
  printf '%s\n' "$expected" >"$tmp/bin.sha256"
  mv -f "$tmp/bin.sha256" "$CACHED_SUM"
  mv -f "$tmp/bin" "$CACHED"
  log "cached $CACHED"
}

if cache_valid; then
  log "cache hit ($VERSION)"
else
  rm -f "$CACHED" "$CACHED_SUM"
  populate_cache || exit 1
fi

# Keep only the current version in the cache.
for d in "$CACHE_DIR"/v*/; do
  [ -d "$d" ] || continue
  [ "$(basename "$d")" = "$VERSION" ] || rm -rf "$d"
done

# Seed the app: leave a good binary alone, replace a missing, empty or different one.
if [ -s "$DEST" ] && [ "$(sha_of "$DEST")" = "$(tr -d '[:space:]' <"$CACHED_SUM")" ]; then
  log "app binary already in place"
else
  mkdir -p "$DEST_DIR/$VERSION"
  cp -f "$CACHED" "$DEST.tmp" && chmod 755 "$DEST.tmp" && mv -f "$DEST.tmp" "$DEST"
  log "seeded $DEST"
fi
