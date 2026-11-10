#!/usr/bin/env bash
# FLE-274: a pinned, checksummed stock Caddy for the real-Caddy tests (RenderedCaddyApiRouteTest), so they run in
# Fleet CI instead of skipping. Downloads the GitHub release tarball, verifies its SHA-512 against the pins below,
# caches the binary and prints its path (the only stdout line). The caller exports it as FLEET_CADDY_BIN.
#
# Usage: ci/fetch-caddy.sh [cache dir]   (default: ${FLEET_CI_TOOLS:-$HOME/.cache/fleet-ci}/caddy)
# Exit 0 = binary path on stdout. Non-zero = unavailable (the caller carries on; the tests then skip, or fail when
# FLEET_REQUIRE_CADDY=1).
# To bump: take the new version's checksums from the release's caddy_<v>_checksums.txt and update all three variables.
# Environment (tests): FLEET_CADDY_BASE_URL, FLEET_CADDY_ARCH (amd64|arm64).

set -euo pipefail

VERSION=2.11.9
SHA_AMD64=0e85544340290a16ce4bfb76fe7d65e9de1bf3a6e67f5ccfff30b30d27ea4c73e078077b7bf3e58ae60b6b12e44327705287059c775bca5782e8ee85ea7edd75
SHA_ARM64=9e5e76d7888542924de2c25353ae87c5f77e2a22c5b1198633cae13688922282e05383d142599205c88d8d80eee5e967d291214d7abce4835329a191b20dd6e0
BASE_URL=${FLEET_CADDY_BASE_URL:-https://github.com/caddyserver/caddy/releases/download}

log() { printf 'fetch-caddy: %s\n' "$*" >&2; }

case "${FLEET_CADDY_ARCH:-$(uname -m)}" in
    x86_64|amd64) arch=amd64; want=$SHA_AMD64 ;;
    aarch64|arm64) arch=arm64; want=$SHA_ARM64 ;;
    *) log "unsupported architecture"; exit 1 ;;
esac

cache=${1:-${FLEET_CI_TOOLS:-$HOME/.cache/fleet-ci}/caddy}/$VERSION-$arch
bin=$cache/caddy
tarball=caddy_${VERSION}_linux_${arch}.tar.gz

# Cache hit: trust nothing recorded in the cache. Re-hash the kept tarball against the pin in this script, then
# check the cached binary is byte for byte the one inside it.
if [ -x "$bin" ] && [ -f "$cache/$tarball" ] \
    && [ "$(sha512sum "$cache/$tarball" | cut -d' ' -f1)" = "$want" ] \
    && [ "$(tar -xzOf "$cache/$tarball" caddy | sha512sum | cut -d' ' -f1)" = "$(sha512sum "$bin" | cut -d' ' -f1)" ]; then
    echo "$bin"
    exit 0
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
curl -fsSL --retry 5 --retry-delay 5 --retry-all-errors -o "$tmp/$tarball" "$BASE_URL/v$VERSION/$tarball" || { log "download failed"; exit 1; }
got=$(sha512sum "$tmp/$tarball" | cut -d' ' -f1)
if [ "$got" != "$want" ]; then
    log "SHA-512 mismatch for $tarball (got $got)"
    exit 1
fi
tar -xzf "$tmp/$tarball" -C "$tmp" caddy
mkdir -p "$cache"
mv "$tmp/caddy" "$bin.new" && chmod 0755 "$bin.new" && mv "$bin.new" "$bin"
cp "$tmp/$tarball" "$cache/$tarball.new" && mv "$cache/$tarball.new" "$cache/$tarball"
echo "$bin"
