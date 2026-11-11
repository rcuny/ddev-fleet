# php_app

Port of the former bash step 70-env.sh and the php-fpm part of `95-units.sh`. Writes `/etc/ddev-fleet/fleet.env`
(`root:fleet` 0640) and `mercure.env` (`root:root` 0600), the `fleet` php-fpm pool (the stock pool is renamed), the
`90-fleet.ini` settings and the unit drop-in. Nothing is started.

- Only **missing** keys of `fleet.env` are added; secrets are generated once and never rewritten. `mercure.env` is derived
  from `MERCURE_JWT_SECRET` on every run.
- Needs `fleet_domain` and `fleet_ref`; the files come from the root-owned export of `install/` (`fleet_install_dir`).
- Sets the facts `fleet_env_changed`, `fleet_fpm_changed` and `fleet_mercure_changed` for the `units` role.
