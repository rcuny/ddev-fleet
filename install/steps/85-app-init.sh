# shellcheck shell=bash
# shellcheck disable=SC2034  # the change flags and results are read by the other steps (one shell sources them all)
# Step 85: database, queue and host settings, as `fleet`. All three are safe to repeat: migrations only apply what is
# new, the queue setup only creates what is missing, and fleet:host:init never changes the setup token, the users or
# the SSH keys (it refuses to change the domain once setup is complete).

# 1 once the wizard has been completed (from the output of fleet:host:init); decides whether step 98 mints a token.
SETUP_COMPLETE=0

step_main() {
  local args=("--domain=$DOMAIN") init_out
  if [ -n "$ACME_EMAIL" ]; then
    args+=("--acme-email=$ACME_EMAIL")
  fi

  fleet_console doctrine:migrations:migrate --no-interaction
  fleet_console messenger:setup-transports --no-interaction
  init_out=$(fleet_console fleet:host:init "${args[@]}")
  printf '%s\n' "$init_out"
  if [[ $init_out == *'Setup is complete.'* ]]; then
    SETUP_COMPLETE=1
  fi
}
