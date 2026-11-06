---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Operate Fleet

Day 2 is four jobs: keep backups, upgrade safely, move a project, and keep the host from overload. Each is a few commands.

## Backups

A daily timer backs up the fleet and queue databases to `/srv/fleet/backups/` and keeps 14 days. They sit on the same disk, so **copy them off the host together with `/srv/fleet/keys/secret.key`**; without the key, secrets are unreadable. Instances, assets and SSH keys are **not** included.

```bash
sudo fleet backup:create
sudo fleet backup:verify /srv/fleet/backups/<file>
```

Restore steps and refusals: [backup and restore](../reference/backup-and-restore.md).

## Upgrading

```bash
fleet upgrade --check
sudo fleet upgrade
```

The check is read-only: it shows the changelog, rollout notes marked `ACTION:` and any blockers. The upgrade backs up the database, runs the new installer, then verifies. **Everyone is signed out** and running jobs are cut off, so pick a quiet moment. Rollback restores the database from the backup taken at the start, so later data is lost. Check ids, exit codes and rollback rules: [upgrade](../reference/upgrade.md).

## Moving a project

1. Export it on the old host: `fleet export:project shop --output=shop.json` (add a passphrase to include secrets).
2. On the new host: `fleet import:preview shop.json`, read the preview, then `fleet import:apply shop.json`.
3. Copy assets: `sudo -u fleet rsync -av old-host.example.com:/srv/fleet/assets/shop/ /srv/fleet/assets/shop/`.

Assets, users and SSH keys are not in the file. Format and limits: [export format](../reference/export-format.md).

## Capacity

Fleet reserves some CPU threads for the system, caps the number of instances, and limits each project and template with an allotment. See the numbers with `fleet host:capacity`; change the reserve and instance cap on **Host settings, Capacity**. Model: [capacity](../reference/capacity.md).

## Routine checks

| Check | How |
|---|---|
| Certificates | **Host settings, Certificates** tab (a banner appears when something is wrong) |
| Certificate status from a shell | `fleet host:tls-status` (exit 0 ok, 1 warning, 2 error) |
| Worker logs | `journalctl -u fleet-worker@1 -n 50` |
| Disk | Free space where `/srv/fleet` lives (assets, instances, backups) |
| Failed jobs | `fleet job:list --status=failed` |
| Updates | `fleet upgrade --check` |

## Everyday commands

The commands you reach for most, by task. Every option is in the [CLI reference](../reference/cli.md).

| Task | Commands |
|---|---|
| Look at projects | `fleet project:list`, `fleet project:show <name>`, `fleet project:delete <name>` |
| Manage templates | `fleet template:list`, `fleet template:show`, `fleet template:update`, `fleet template:delete` |
| Named ports | `fleet port:list`, `fleet port:mirror`, `fleet port:delete` |
| Instances | `fleet instance:list`, `fleet instance:watch`, `fleet instance:redeploy`, `fleet instance:destroy` |
| Jobs | `fleet job:show <id>`, `fleet job:retry <id>` |
| Take access away | `fleet user:revoke <username> <project>` |
| Webhook rules | `fleet webhook:list` |
| Schedules | `fleet schedule:update`, `fleet schedule:pause`, `fleet schedule:delete` |
| Agent workspace | `fleet tmux:list`, `fleet tmux:ensure`, `fleet tmux:reset`, `fleet tmux:sidebar` |
| Upgrades | `fleet upgrade:check` (same as `fleet upgrade --check`), `fleet upgrade:history`, `fleet upgrade:verify` |

## Where to go next

- [Secure the host](secure-the-host.md)
- [Troubleshooting](../reference/troubleshooting.md)
- [Files on disk](../reference/files-on-disk.md)
