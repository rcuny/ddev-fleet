# hardening (opt-in)

Port of the former bash hardening (`install/harden/`). Off unless `fleet_hardening_enabled: true`.

SSH drop-in safety (FLE-255): the existing `/etc/ssh/sshd_config.d/10-fleet-hardening.conf` is copied to a private
temporary file before the template runs. A drop-in that fails `sshd -t -f` is never written, so the existing good one is
left alone; only a drop-in that was written and then breaks the whole `sshd -t` is restored from the copy (or removed
when there was none). After the reload `ssh-ports.sh` re-reads the listening ports and the run stops if they differ
from the ones the firewall is opened for.

Manual check on a throw-away host: put a bad line in `templates/ssh-dropin.conf.j2`, run the role, and confirm the
old drop-in is still there and the play fails with "does not pass sshd -t".
