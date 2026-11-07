---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Backup and restore reference

Fleet backs up its own databases every day, so a broken disk or a bad upgrade is not the end. This page says what is and is not covered, and how to restore without surprises.

| | |
|---|---|
| Backed up | The fleet database (settings, users, SSH key metadata, encrypted secrets) and the job queue database. |
| **Not** backed up | Instances, assets, SSH keys under `/srv/fleet/ssh`, and `/srv/fleet/keys/secret.key`. |
| When | `fleet-backup.timer`, daily; also `sudo fleet backup:create` or "Back up now" on the Backups tab (`/admin/host?tab=backups`). |
| Where | `/srv/fleet/backups/` (directory 0700, files 0600): `fleet-<UTC stamp>.sqlite` (the one you restore) and `queue-<stamp>.sqlite` (transient jobs). |
| Retention | 14 days (`backupRetentionDays`); the newest of each kind is always kept. `*.pre-restore-*` files are never pruned. |

The backups sit on the same disk. **Copy `/srv/fleet/backups` off the host (rsync, restic) together with `keys/secret.key`**: the encrypted secrets cannot be read without the key. Each fleet backup records a fingerprint of the key that sealed it (never the key). Check the timer with `systemctl list-timers fleet-backup.timer` and the log with `journalctl -u fleet-backup.service`.

## Verify

`sudo fleet backup:verify <file>` opens the file read-only and runs SQLite's integrity check and a schema comparison. Exit 0 sound, 1 not; it refuses the live database. It prints `schema: behind` (the migrations the restore will run) and `secret key: match`, `mismatch` or `not recorded`.

## Restore

Four units write the databases, so stop them first. The command stops nothing itself; it checks, and prints this line when one is active:

```bash
sudo systemctl stop fleet-scheduler fleet-worker@1 fleet-status php8.4-fpm
sudo fleet backup:restore /srv/fleet/backups/fleet-<stamp>.sqlite
sudo systemctl start fleet-worker@1 fleet-scheduler fleet-status php8.4-fpm
```

The web UI is down while php-fpm is stopped. The command asks to confirm (`--yes` skips it and is required without a terminal; `--json` prints the result). It copies the backup next to the live file, keeps the current database as `/srv/fleet/backups/fleet.sqlite.pre-restore-<stamp>` (and the queue likewise), renames the copy into place, runs migrations for an older backup and validates the schema. The queue backup with the same stamp is restored too, if it exists. If a step fails the previous databases are put back (exit 1). On success it prints `previous kept: <path>`. Then check `https://<domain>/health`.

**Undo a restore:** stop the units and run `fleet backup:restore` on the `*.pre-restore-<stamp>` file. Delete those files by hand when you no longer need them.

## When it refuses

Before touching anything, `fleet backup:restore` exits 1 with a reason.

| Message means | What to do |
|---|---|
| The file fails verification, or is from a newer release. | Pick another backup, or upgrade first. |
| Sealed by another secret key (mismatch). | Put the matching `keys/secret.key` back. If it is gone, `--accept-key-mismatch`, then set every secret again (OVH credentials, Claude token, project secrets). |
| No fingerprint recorded (old backup). | Restores with a warning; nothing can be compared. |
| A job is queued or running. | Let it finish (`fleet job:list`). |
| A worker, scheduler, status or php-fpm unit is active. | Run the `systemctl stop` line it prints. |
| Wrong user. | Use `sudo fleet backup:restore`; a root `console` run leaves root-owned databases the services cannot open. |
| The live database cannot be read. | Move it aside with `mv`, then restore into the empty place. |

Moving one project between hosts is a different tool: [12-export-format.md](12-export-format.md).

## Learn more

- [Operate](../guides/07-operate.md): backups in day-2 routine.
- [Upgrade](05-upgrade.md): the backup an upgrade takes, and rollback.
- [Files on disk](03-files-on-disk.md): paths and permissions.
