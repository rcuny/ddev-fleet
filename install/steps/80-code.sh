# shellcheck shell=bash
# shellcheck disable=SC2034  # the change flags and results are read by the other steps (one shell sources them all)
# Step 80: install the application's dependencies and build its assets, as `fleet`, with the application environment
# loaded (so APP_ENV=prod: --no-dev, the prod cache). Also records whether the code changed since the last successful
# install: opcache.validate_timestamps=0 means php-fpm and the workers only see new code after step 98 reloads them.


# FLE-193: GitHub releases answer 504 now and then, so the Tailwind CLI comes from a host cache (downloaded once, with
# retries and a SHA-256 check) instead of a live download inside `tailwind:build`. A failure here is only a warning:
# the bundle then tries its own download.
seed_tailwind_binary() {
  local version
  version=$(sed -n "s/^[[:space:]]*binary_version:[[:space:]]*[\"']\{0,1\}\(v[0-9][0-9.]*\)[\"']\{0,1\}.*/\1/p" \
    "$FLEET_SRC_DIR/app/config/packages/symfonycasts_tailwind.yaml" 2>/dev/null | head -n1 || true)
  if [ -z "$version" ]; then
    log_warn "no symfonycasts_tailwind binary_version found: the bundle downloads Tailwind itself"
    return 0
  fi
  if is_dry; then
    printf '+ as fleet: tailwind-seed.sh %s %s/cache/tailwind %s/app/var/tailwind\n' "$version" "$FLEET_SRV_DIR" "$FLEET_SRC_DIR"
    return 0
  fi
  run_as_fleet bash "$INSTALL_DIR/lib/tailwind-seed.sh" "$version" "$FLEET_SRV_DIR/cache/tailwind" \
    "$FLEET_SRC_DIR/app/var/tailwind" || log_warn "could not cache the Tailwind binary: the bundle downloads it itself"
}

# FLE-213: jobs started as child processes survive the worker restart, but not the swap of the code and the database under
# them. Before the dependencies and migrations change, wait (bounded) for the jobs that are running. FLEET_INSTALL_JOB_WAIT
# is the limit in seconds (default 300, 0 skips the wait); a job still running at the limit is left to finish and the
# install goes on. Reads the database directly: the application of the old release may not match the new code.
wait_for_running_jobs() {
  local limit=${FLEET_INSTALL_JOB_WAIT:-300} db=$FLEET_SRV_DIR/var/fleet.sqlite waited=0 running
  [[ $limit =~ ^[0-9]+$ ]] || limit=300
  if is_dry; then
    printf '+ wait up to %ss for running jobs to finish (FLEET_INSTALL_JOB_WAIT)\n' "$limit"
    return 0
  fi
  [ "$limit" -gt 0 ] && [ -f "$db" ] || return 0
  while :; do
    running=$(run_as_fleet php8.4 -r 'try { $p = new PDO("sqlite:".$argv[1]); echo (int) $p->query("SELECT COUNT(*) FROM job WHERE status = \"running\"")->fetchColumn(); } catch (Throwable) { echo 0; }' "$db" 2>/dev/null || echo 0)
    [[ $running =~ ^[0-9]+$ ]] || running=0
    [ "$running" -gt 0 ] || return 0
    if [ "$waited" -ge "$limit" ]; then
      log_warn "$running job(s) still running after ${limit}s: continuing, they run on against the new code (retry any that fail)"
      return 0
    fi
    log_info "waiting for $running running job(s) to finish (${waited}s of ${limit}s)"
    sleep 5
    waited=$((waited + 5))
  done
}

step_main() {
  wait_for_running_jobs
  if is_dry; then
    CODE_CHANGED=1
    run_as_fleet git -C "$FLEET_SRC_DIR" rev-parse HEAD
  else
    CODE_REVISION=$(run_as_fleet git -C "$FLEET_SRC_DIR" rev-parse HEAD)
    if [ "$CODE_REVISION" != "$(cat "$FLEET_ETC_DIR/installed-revision" 2>/dev/null || true)" ]; then
      CODE_CHANGED=1
    fi
  fi

  fleet_app_run php8.4 /usr/bin/composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
  fleet_console importmap:install --no-interaction
  seed_tailwind_binary
  fleet_console tailwind:build --minify --no-interaction
  fleet_console asset-map:compile --no-interaction
  fleet_console cache:clear --no-interaction
  fleet_console cache:warmup --no-interaction
}
