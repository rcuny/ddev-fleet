# privileged

Port of `install/steps/90-privileged.sh`: the `apply` and `upgrade` root helpers, the tmpfiles entry for the upgrade
history, the sudoers file (`/etc/sudoers.d/fleet-apply`, validated with `visudo -cf` before it lands) and the `fleet`
wrapper. Everything is copied from the root-owned export of `install/` (`fleet_install_dir`), never from the checkout.
