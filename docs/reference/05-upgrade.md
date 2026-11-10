# Upgrade reference

Check what the next release changes, install it with a backup and a verification, and go back if you must. This page lists the exit codes, the pre-flight checks, the JSON and history formats, and how to rehearse. For the short version see [operate](../guides/07-operate.md).

Upgrading is **command line only**: the installer restarts php-fpm, Caddy and Authelia, which would kill a web request that started it. The Upgrade page shows status, changelog, checks and history, plus the command to copy.

`fleet upgrade --check` is read-only and needs no root; `sudo fleet upgrade` upgrades or rolls back; `fleet upgrade history` and `verify` need no root. Every option: [01-cli.md](01-cli.md).

## Check

```
fleet upgrade --check [--to vX.Y.Z] [--json] [--no-cache]
```

It prints versions, the changelog between them, rollout notes and pre-flight findings. `--to` checks another published release (older shows as a downgrade; unknown is exit 2). `--no-cache` asks the source now; otherwise the list cached by `fleet-upgrade-check.timer` is used while under 6 hours old.

| Exit | Meaning |
|---|---|
| 0 | Up to date, or an update is available. |
| 1 | The check itself failed. |
| 2 | Usage: `--to` is not a version or not published. |
| 3 | Blocked by a pre-flight `block`. Nothing changed. |
| 4 | Offline: the source could not be read. Shows the cached latest and its age. |

Statuses: `up_to_date`, `update_available`, `downgrade`, `not_a_release` (the host runs a branch: install a release first), `blocked`, `offline`.

## Pre-flight checks

A `block` stops the upgrade (exit 3); `warn` and `info` only show. `--force=<id>` waives a waivable block.

| Id | Severity | Meaning |
|---|---|---|
| `host.sudo` | block | `sudo` is missing. |
| `network.source` | block | The release source does not answer. |
| `checkout.missing` | block | No git checkout in `/opt/ddev-fleet`. |
| `checkout.dirty` | block | Local changes would be discarded (cannot be forced). |
| `checkout.moved` | block | Checkout is not at the installed commit. |
| `checkout.origin` | block | `origin` is not the release source. |
| `checkout.revision-unknown` | warn | `installed-revision` is unreadable. |
| `target.series` | block | The target is not a `v1.*` release. |
| `target.below-minimum` | block | The target is older than v1.1.0. |
| `target.not-on-main` | block | The tag's commit is not on `main`. |
| `target.main-unverified` | warn, waivable | The tag could not be verified on `main`; the installer re-checks. |
| `disk.space` | block | Not enough free space. |
| `jobs.active` | warn, waivable | Running jobs would be cut off. |
| `instances.id-length` | block, waivable | An instance id exceeds DDEV's 59 characters. |
| `schema.ahead` | warn | Rollback: later data is lost. |
| `schema.unknown` | info | The target's migrations could not be compared. |
| `rollout.action` | warn | A release has an `ACTION:` rollout note. |
| `rollout.unreadable` | info | Notes unreadable. |
| `check.crashed` | block | A check could not run; the message names it. |

The target's installer then runs `install/run.sh --preflight`; findings appear as `installer.<id>`: `host.os`, `host.arch`, `disk.opt`, `disk.srv`, `disk.var`, `instances.id-length`, `docker.answer`, `ddev.answer`, `host.packages`, `fleet.env`, `network.repo`. `installer.unsupported`, `installer.unavailable` and `installer.failed` mean its checks could not be used.

## Upgrade

```
sudo fleet upgrade [--to vX.Y.Z] [--yes] [--force=<check-id>]... [--no-db-restore] [--repo-url=<url or path>]
```

| Option | Meaning |
|---|---|
| `--check` | Read-only report (no root); never combined with `--yes`. |
| `--to vX.Y.Z` | Target release; default the latest. An older tag is a rollback. |
| `--json` | With `--check`: the JSON document below. |
| `--yes` | Confirm without the `[y/N]` prompt (required without a terminal). |
| `--force=<check-id>` | Waive one waivable block; repeatable. |
| `--no-db-restore` | Rollback only: keep the current database (a fresh backup is taken first). |
| `--repo-url=<url or path>` | Fetch the installer from this repository instead of the default mirror (used by the rehearsal). |

1. **Plan and pre-flight**, the same report as `--check`; a block refuses it.
2. **Confirmation**: a terminal asks `[y/N]`; `--yes` confirms; no terminal without `--yes` is refused.
3. **Backup** of the databases. If it fails, the upgrade stops.
4. **Installer** of the target, fetched by the exact planned commit (a moved tag is refused). It is idempotent: after a failure, run the same command again.
5. **Verification**: `/health` and the Authelia portal answer, version and commit match, no migration is pending, `fleet-worker@1` and `fleet-status` run. Failure exits 5 and prints the rollback command.

**Every signed-in user is signed out** (Authelia restarts) and running jobs are cut off. Secrets, users, SSH keys and wizard choices are kept. Docker, DDEV and the OS are upgraded by `apt`, not here. `--domain` cannot change after setup.

| Exit | Meaning |
|---|---|
| 0 | Done and verified, or already up to date. |
| 1 | Failed: plan, backup or installer. |
| 2 | Usage: bad option or unknown `--to`. |
| 3 | Refused, nothing changed: block, no `--yes`, not root, moved tag, another upgrade running. |
| 5 | The installer finished but the verification failed. |

### Install engine

The installer records the engine that provisioned the host in `/etc/ddev-fleet/installed-engine` (`bash` or `ansible`;
a host without the file is `bash`). `fleet upgrade`, and a rollback, run the target release's `install.sh` with the same
engine: on an `ansible` host that is `--engine=ansible`, so the playbook of the target tag runs again and writes
`installed-version`. A target release that does not have the Ansible engine is refused before the backup, before any history line and before any service is stopped. A record that holds anything else than `bash` or `ansible` is reported as a warning and the host is treated as bash.
The Upgrade page shows the engine.

## Roll back

```
sudo fleet upgrade --to vX.Y.Z --yes [--no-db-restore]
```

Migrations only go forward, so a rollback **restores the database from the backup taken when the upgrade being undone started** (the newest `start` line whose `from` is the target and whose file still exists). Everything written since (instances, jobs, users, secrets, audit rows) is **lost**. Steps: plan, fetch the older installer, stop the services, restore (the current database is kept as `*.pre-restore-*`), run the older installer, verify. If the restore fails, the previous database stays and the installer never runs (exit 1).

Refused with exit 3, nothing changed:

- a target across a major version, below v1.1.0, or a pre-release;
- no usable backup in the history: restore one by hand (`fleet backup:restore <file>`), then roll back with `--no-db-restore`;
- `--no-db-restore` while the database has migrations the target lacks (see `schema.ahead`).

`--no-db-restore` keeps the current database (a fresh backup is taken first): for a code-only rollback.

## Rollout notes

A release's `CHANGELOG.md` can end a version with `#### Rollout` bullets. One starting `ACTION:` needs you to act: `--check`, the Upgrade page and the upgrade (before it confirms) show it as a `rollout.action` warning; it never blocks. Notes of every release up to the target are shown.

## JSON document

`fleet upgrade --check --json` prints one document. Keys are never removed or renamed within a `schema` number. An error is `{"schema":1,"error":"<message>","exitCode":2}` (or 1).

| Key | Type | Meaning |
|---|---|---|
| `schema` | int | `1`. |
| `status` | string | One of the statuses above. |
| `exitCode` | int | The exit code (0, 3 or 4). |
| `installed` | object | `raw` (version text) and `version` (`vX.Y.Z` or `null`). |
| `target`, `latest` | object or null | `tag` and `sha`. |
| `direction` | string | `up`, `down`, `same`, `unknown`. |
| `releases` | list | Tags the plan passes through, newest first. |
| `catalog` | object | `checkedAt`, `listedAt`, `ageSeconds`, `error`, `refreshed`. |
| `changelog`, `changelogError` | list, string | Per section `version`, `date`, `body` (untrusted), `rollout`; why it could not be read. |
| `rollout` | object | `notes` and `actions` (the `ACTION:` ones, without the prefix). |
| `checks` | list | Per finding: `id`, `severity`, `message`, `remedy`, `overridable`. |
| `checksSkipped` | list | Why pre-flight did not run (up to date, offline). |

## History

`fleet upgrade history [--limit=N] [--json]` lists upgrades, newest first, from `/var/log/ddev-fleet/upgrade-history.jsonl`: root-owned, one JSON object per line, outside the database so a restore cannot erase it, no secrets. A rollback is a line whose `to` is older than its `from`.

| Event | Result | When |
|---|---|---|
| `start` | `started` | After the backup, before the installer; carries `backup`. |
| `installer` | `ok`, `failed` | When the installer returned. |
| `restore` | `ok`, `failed` | Rollback only: the database was restored from `backup`. |
| `verify` | `ok`, `unverified` | When the verification returned. |

Every line has `ts` (UTC), `event`, `result`, `from`, `to`, `sha`, `actor`, `engine` (`bash` or `ansible`, see "Install engine"); `start` and `restore` add `backup`. `fleet upgrade verify --to vX.Y.Z` runs the verification alone.

## Docker network pool and mirrors

Docker holds about 30 instance networks by default (its built-in address pools). The installer adds a pool (`10.64.0.0/10` in /24s, about 16000 networks) to `/etc/docker/daemon.json`; Docker reads it only at start and Fleet never restarts it on an existing host. At a quiet moment: `fleet instance:stop --all`, `sudo systemctl restart docker`, `fleet instance:start --all`. `fleet host:capacity --json` shows `docker_networks` (`max` stays at the counted limit of 31 until then). The pool is skipped if the host routes that range; your own `default-address-pools` is never changed.

Offline, the check exits 4 and an upgrade is refused (exit 3). A mirror works: `sudo fleet upgrade --repo-url=<url or path>`; the unprivileged check follows `FLEET_UPGRADE_REPO_URL` in `fleet.env` (root ignores it on purpose). The mirror needs annotated `v1.*` tags on `main`.

## Rehearse on a test host

`ci/upgrade-rehearsal.sh` proves the whole path with no public release. It builds a local repository with two throwaway tags (default `v1.9.1`, `v1.9.2`, the second with an `ACTION:` note), points the source at it, runs check, refusal without `--yes`, two upgrades and two rollbacks, asserting exit codes and versions, then cleans up.

```
sudo ci/upgrade-rehearsal.sh --yes-this-is-a-test-host [--source=DIR] [--first-tag=vX.Y.Z] [--second-tag=vX.Y.Z] [--keep]
ci/upgrade-rehearsal.sh --build-only [--base-tag=vX.Y.Z] [--workdir=DIR]    # no root, no change to the host
ci/upgrade-rehearsal.sh --dry-run                                           # print the plan
```

Never run it on a host people use (restarts, sign-outs, one database restore). Exit 0 all held, 1 an assertion failed, 3 refused to start.

## Hosts installed before v1.2.0

They have no `fleet upgrade`: re-run the installer once with `--ref=vX.Y.Z` ([04-installer.md](04-installer.md)), then use `fleet upgrade`.

## Learn more

- [Operate](../guides/07-operate.md): the short day-2 view.
- [Backup and restore](06-backup-and-restore.md): the backup an upgrade takes.
- [Troubleshooting](13-troubleshooting.md): upgrade problems.
