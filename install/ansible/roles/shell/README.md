# shell

Ansible port of `install/steps/35-shell.sh`.

- `/etc/profile.d/10-fleet-shell.sh` (0644 `root:root`), copied from `install/etc/profile.d/10-fleet-shell.sh`, the same
  file the bash installer writes; a warning when the file on the host is foreign content;
- one guarded hook block (`# BEGIN ddev-fleet shell profile` ... `# END ddev-fleet shell profile`, byte for byte the
  bash one) appended to `/etc/bash.bashrc` and the `~/.bashrc` of root, the fleet user and the user that ran
  `sudo ansible-playbook` (`SUDO_USER`, or `fleet_shell_sudo_user`). A file is hooked only when it exists and does
  not already mention `10-fleet-shell.sh` in any form, so the block is never added twice. The uninstaller removes
  exactly this block.

Switch: `fleet_shell_profile: false` (in `/etc/ddev-fleet/host-vars.yml`) is the old `--no-shell-profile`.
No small bash script is kept: the guard is a `grep -sqF` task and `blockinfile` writes the block.
