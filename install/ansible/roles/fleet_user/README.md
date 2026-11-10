# fleet_user

Ansible port of `install/steps/30-fleet-user.sh`.

- the `docker` system group and the `fleet` system user (home `/home/fleet`, shell `/bin/bash`, own group), created
  only when missing, then added to `docker`;
- `/srv/fleet` (0750), `var` (0750), `staging` (0700), `/etc/ddev-fleet` (0750 `root:fleet`), `/opt/ddev-fleet` (0755);
- `/opt/ddev-fleet` recursively `fleet:fleet` (the app is a fleet-owned checkout, never root-owned).

Variables (defaults in `group_vars/all.yml`): `fleet_user`, `fleet_home_dir`, `fleet_srv_dir`, `fleet_opt_dir`,
`fleet_etc_dir`. No small bash script is kept: every behaviour maps onto a module.
