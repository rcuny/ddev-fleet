### Added

- **FLE-91: the installer installs the fleet shell profile.** A new step 35 writes `/etc/profile.d/10-fleet-shell.sh` (coloured prompt that names the host, `ls` colours, `l` alias, `EDITOR=nano`; interactive shells only) and appends one guarded hook block to `/etc/bash.bashrc` and to the `~/.bashrc` of root, `fleet` and the invoking `sudo` user, only when missing. A file copied by hand from the 0.x host is replaced. `--no-shell-profile` skips the step; the uninstaller removes the file and exactly that block.
