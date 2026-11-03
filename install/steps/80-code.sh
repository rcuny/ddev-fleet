# shellcheck shell=bash
# shellcheck disable=SC2034  # the change flags and results are read by the other steps (one shell sources them all)
# Step 80: install the application's dependencies and build its assets, as `fleet`, with the application environment
# loaded (so APP_ENV=prod: --no-dev, the prod cache). Also records whether the code changed since the last successful
# install: opcache.validate_timestamps=0 means php-fpm and the workers only see new code after step 98 reloads them.


step_main() {
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
  fleet_console tailwind:build --minify --no-interaction
  fleet_console asset-map:compile --no-interaction
  fleet_console cache:clear --no-interaction
  fleet_console cache:warmup --no-interaction
}
