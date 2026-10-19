# shellcheck shell=bash
# Step 98: render the Caddy and Authelia configuration, install it through the root helper, start everything and
# wait until it answers. Last, if the wizard has not been completed, mint the one-time setup token.
#
# A re-run is an upgrade: php-fpm and the workers keep running the code and the environment they started with
# (opcache.validate_timestamps=0; an EnvironmentFile is read at start), so they are reloaded or restarted here when
# step 70, 80 or 95 changed something. Caddy is always restarted: a refreshed custom build (step 50) only takes effect
# in a new process, and its environment file is read only at start.
#
# The setup URL is a credential: it is added to the final notes, which run.sh prints on the terminal after the log is
# closed. It is never written to the install log.

HEALTH_TIMEOUT=${FLEET_INSTALL_HEALTH_TIMEOUT:-120}
HEALTH_UNITS=(docker caddy authelia php8.4-fpm fleet-mercure fleet-worker@1)

# restart_workers: every running fleet-worker@N (fleet-worker@1 is always among them).
restart_workers() {
  local unit
  run systemctl restart fleet-worker@1.service
  if is_dry; then
    return 0
  fi
  while read -r unit _; do
    [ -n "$unit" ] || continue
    [ "$unit" = fleet-worker@1.service ] || run systemctl restart "$unit"
  done < <(systemctl list-units --plain --no-legend --state=active 'fleet-worker@*.service')
}

# The reasons the install is not healthy yet, one per line; empty when everything answers.
health_problems() {
  local unit
  curl -fsS --max-time 10 -o /dev/null "https://$DOMAIN/health" 2>/dev/null || printf 'https://%s/health does not answer\n' "$DOMAIN"
  curl -fsS --max-time 5 -o /dev/null http://127.0.0.1:3000/healthz 2>/dev/null || printf 'the Mercure hub does not answer on 127.0.0.1:3000\n'
  for unit in "${HEALTH_UNITS[@]}"; do
    systemctl is-active --quiet "$unit" || printf '%s is not active\n' "$unit"
  done
}

wait_until_healthy() {
  local problems start=$SECONDS
  if is_dry; then
    printf '+ wait up to %s s: curl -fsS https://%s/health, curl -fsS http://127.0.0.1:3000/healthz, systemctl is-active %s\n' "$HEALTH_TIMEOUT" "$DOMAIN" "${HEALTH_UNITS[*]}"
    return 0
  fi
  while :; do
    problems=$(health_problems)
    [ -z "$problems" ] && return 0
    if [ $((SECONDS - start)) -ge "$HEALTH_TIMEOUT" ]; then
      printf '%s\n' "$problems" >&2
      die 1 "the install did not become healthy within ${HEALTH_TIMEOUT}s. Look at: journalctl -u caddy -u authelia -u php8.4-fpm -u fleet-mercure -u fleet-worker@1 -n 50 --no-pager"
    fi
    sleep 3
  done
}

# Adds the setup URL (a credential) and the commands worth knowing to the notes printed after the log is closed.
announce_setup() {
  local url
  add_final_note ''
  if [ "${SETUP_COMPLETE:-0}" = 1 ]; then
    add_final_note "ddev-fleet is up at https://$DOMAIN/. Setup was already completed; nothing was reset."
  elif is_dry; then
    fleet_console fleet:setup:token
    add_final_note "(a real run prints the setup URL for https://$DOMAIN/ here)"
  else
    url=$(fleet_console fleet:setup:token)
    add_final_note "ddev-fleet is up. Open this setup URL to create the administrator. It is a credential for 24 hours: keep it private."
    add_final_note ''
    add_final_note "    $url"
    add_final_note ''
    add_final_note "Lost it? Run  sudo fleet setup:token  for a new one (the old one stops working)."
  fi
  add_final_note "Forgot a password?  sudo fleet user:reset-password <name>"
  add_final_note "Start setup over?   sudo fleet setup:reset"
  add_final_note "Upgrade:            run the same install command again."
}

step_main() {
  if is_dry; then
    # The plan lists every action, whether or not this host needs it.
    CODE_CHANGED=1 ENV_CHANGED=1 FPM_CHANGED=1 WORKER_UNIT_CHANGED=1 MERCURE_CHANGED=1
  fi

  # Render as fleet, install as root. --stage: leave the files for the helper (a render without it stages nothing, the
  # OVH credentials would otherwise sit in clear text in /srv/fleet); the exit trap in run.sh discards whatever the helper
  # did not install if a step fails. --no-reload: Caddy does not serve the new Caddyfile yet (the helper validates the
  # staged copy, and Caddy is restarted below).
  fleet_console fleet:host:render --gate=auto --no-reload --stage
  run "$HELPER_DIR/apply" authelia
  run "$HELPER_DIR/apply" caddy

  run systemctl enable authelia php8.4-fpm fleet-mercure fleet-worker@1 fleet-scheduler caddy

  if [ "$FPM_CHANGED" = 1 ] || [ "$ENV_CHANGED" = 1 ]; then
    run systemctl restart php8.4-fpm
  elif [ "$CODE_CHANGED" = 1 ]; then
    run systemctl reload-or-restart php8.4-fpm
  else
    run systemctl start php8.4-fpm
  fi
  if [ "$MERCURE_CHANGED" = 1 ]; then
    run systemctl restart fleet-mercure
  else
    run systemctl start fleet-mercure
  fi
  run systemctl start authelia
  if [ "$CODE_CHANGED" = 1 ] || [ "$ENV_CHANGED" = 1 ] || [ "$WORKER_UNIT_CHANGED" = 1 ]; then
    restart_workers
  else
    run systemctl start fleet-worker@1
  fi
  # The schedule ticker restarts with the workers' triggers: it runs the same code and environment.
  if [ "$CODE_CHANGED" = 1 ] || [ "$ENV_CHANGED" = 1 ] || [ "${SCHEDULER_UNIT_CHANGED:-0}" = 1 ]; then
    run systemctl restart fleet-scheduler
  else
    run systemctl start fleet-scheduler
  fi
  run systemctl restart caddy
  # The daily backup timer (the units are written by step 95). enable --now is a no-op when it already runs.
  run systemctl enable --now fleet-backup.timer

  wait_until_healthy

  if ! is_dry && [ -n "$CODE_REVISION" ]; then
    write_file "$FLEET_ETC_DIR/installed-revision" 0644 root:root <<<"$CODE_REVISION"
  fi
  announce_setup
}
