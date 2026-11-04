---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-04
Type: documentation
---

# Upgrading

How to see what the next release of Fleet changes (`fleet upgrade --check`) and how to install it (`sudo fleet upgrade`).
The rollback (`sudo fleet upgrade --to <older tag>`) is described under "Roll back"; the upgrade
engine is the installer re-run (`installation.md`, section "Upgrading"), which `sudo fleet upgrade` wraps with a plan,
a backup, a verification and a history.

## Check for an update

```
fleet upgrade --check [--to vX.Y.Z] [--json] [--no-cache]
```

Read-only. It changes nothing on the host and **needs no root**: run it as any user who can use the `fleet` command.

It prints, in this order:

1. the installed version and the latest release (with the age of the list it came from);
2. the changelog sections between the two versions (capped at 60 lines in the text form; `--json` has all of it),
   read from the `CHANGELOG.md` at the target tag, so it is the text that would be installed;
3. the **rollout notes** of those sections (`#### Rollout`). A note that starts with `ACTION:` needs the operator to do
   something; the upgrade will ask for `--yes` (see `changelog.d/README.md` for how release authors write them);
4. the **pre-flight checks** of the upgrade: one line per finding with its severity (`BLOCK`, `WARN`, `INFO`), id,
   message and remedy. A `BLOCK` stops an upgrade; a waivable one says so (`--force=<id>` on the upgrade command).

When the host already runs the latest release it prints `Already up to date.` and runs no checks.

| Option | Meaning |
|---|---|
| `--to vX.Y.Z` | Check this published release instead of the latest. An older one is shown as a downgrade. A version that is not published, or not a version, is a usage error (exit 2) that lists the available tags. |
| `--json` | One JSON document on standard output (schema below) instead of text. |
| `--no-cache` | Ask the release source now. Without it the list cached by the daily check (`fleet-upgrade-check.timer`) is used while it is younger than 6 hours; an older or missing list is refreshed first. |

### Exit codes

| Code | Meaning |
|---|---|
| 0 | Checked: up to date, or an update is available (read the output or `status` in the JSON). |
| 1 | The check itself failed (an unexpected error). |
| 2 | Usage error: `--to` is not a version or not a published release. |
| 3 | Blocked: a pre-flight check found a `BLOCK`. Nothing was changed. |
| 4 | Offline or undeterminable: the release source could not be read, or knows no release. The text shows the installed version and the cached latest with its age. |

These are the codes of the `upgrade` group; the project, template and port commands use their own (0 done, 1 refused
value, 2 not allowed now, 3 not found).

### JSON schema (`schema: 1`)

Printed by `fleet upgrade --check --json`. Keys are never removed or renamed within a schema number; a change that
would do that raises `schema`. A usage or unexpected error is `{"schema": 1, "error": "<message>", "exitCode": 2}`
(or 1).

| Key | Type | Meaning |
|---|---|---|
| `schema` | int | Always `1` for this shape. |
| `status` | string | `up_to_date`, `update_available`, `downgrade`, `not_a_release` (the host runs a branch or a development tree), `blocked`, `offline`. |
| `exitCode` | int | The process exit code (0, 3 or 4). |
| `installed` | object | `raw` (the installed version text, for example `v1.1.0` or `main@abc1234`) and `version` (`vX.Y.Z`, or `null` when it is not a release). |
| `target` | object or null | `tag` and `sha` of the release the plan goes to (the latest stable one, or `--to`). |
| `latest` | object or null | `tag` and `sha` of the latest stable release known. |
| `direction` | string | `up`, `down`, `same`, `unknown`. |
| `releases` | list of string | The release tags the plan passes through, newest first. |
| `catalog` | object | `checkedAt` (last attempt), `listedAt` (last successful read), `ageSeconds` (age of the list), `error` (why the last attempt failed, or `null`), `refreshed` (whether this run asked the source). |
| `changelog` | list | One object per section passed through, newest first: `version`, `date`, `body` (Markdown, untrusted text), `rollout` (its bullets). |
| `changelogError` | string or null | Why the changelog could not be read. |
| `rollout` | object | `notes` (every rollout bullet) and `actions` (the `ACTION:` ones, without the prefix). |
| `checks` | list | `id`, `severity` (`block`, `warn`, `info`), `message`, `remedy`, `overridable` per finding. |
| `checksSkipped` | list of string | Why the pre-flight did not run (up to date, offline); empty when it ran. |

### The internal plan document

`fleet:upgrade:plan` prints the same document (always JSON) and accepts `--force=<id>` (repeatable) to waive overridable
blocks. It is internal: the upgrade command reads it, people use `--check`.

## Upgrade the host

```
sudo fleet upgrade [--to vX.Y.Z] [--yes] [--force=<check-id>]... [--no-db-restore]
```

Moves the host up to the latest release (or `--to`). A `--to` that is older than the installed version is a rollback, see "Roll back" below. It needs root: run
it with `sudo`. Without root it changes nothing and prints the exact `sudo` line. The steps, in order:

1. **Plan and pre-flight**, as the `fleet` user (the same report as `--check`). A `BLOCK`, a modified checkout, a host that runs a branch instead of a release, or an unreachable release source
   refuses the upgrade (exit 3) before anything changes.
2. **Confirmation.** `--yes` confirms; a terminal asks `[y/N]`; a non-interactive run without `--yes` is refused (exit 3).
3. **Backup.** The pre-upgrade database backup (`fleet backup`); if it cannot be taken the upgrade stops (exit 1).
4. **Installer.** The target's `install/` tree is fetched by the **commit** the plan resolved (the tag must still point at
   it, or the run is refused), and its `install.sh` runs with `--ref=<tag>`. The installer log path is printed. The
   installer is idempotent: after a failure (exit 1) run the same command again.
5. **Verification**, by the new code: `/health` answers, the Authelia portal answers, `installed-version` is the target,
   the checkout is at the planned commit, no migration is pending, `fleet-worker@1` and `fleet-status` are active, and a
   `host.upgrade` audit row is written (best effort: a missing row only warns). A failure exits 5 and prints the way back:
   `sudo fleet upgrade --to <previous version>` (a rollback, below).

| Option | Meaning |
|---|---|
| `--to vX.Y.Z` | Upgrade to this published release instead of the latest. |
| `--yes` | Confirm without asking. Required when there is no terminal. |
| `--force=<check-id>` | Waive one overridable pre-flight Block (repeatable). A modified checkout and a full disk cannot be waived. |
| `--no-db-restore` | Rollback only: keep the current database. Refused when the database has a migration the target release lacks. |
| `--repo-url=<url or path>` | Install from this git remote instead of the public mirror (mirrors, rehearsals). The root run ignores `FLEET_UPGRADE_REPO_URL`. |

## Roll back

```
sudo fleet upgrade --to vX.Y.Z --yes [--no-db-restore]
```

`--to` an older release returns the host to it. Doctrine migrations only go forward, so older code over a newer schema
would not boot: the **database is restored from the backup taken at the start of the upgrade being undone** (the newest
`start` line of the history whose `from` is the target and whose backup file still exists). Everything written to the
database since that upgrade is **lost**: instances, jobs, users, projects, secrets and audit entries created or changed
after it. The command prints that, naming the backup and the time, and always needs `--yes`. The history file is not in
the database, so the record of both the upgrade and the rollback survives.

Steps: plan (as for an upgrade, including the schema comparison), the fleet services (scheduler, worker, status watcher
and php-fpm) are stopped, the backup is restored (`fleet-user`, the current database is kept next to the backups as
`*.pre-restore-*`), the installer of the older release runs (it applies that release's migrations with its own code and
starts the services again), then the verification. If the restore fails, the previous database stays in place, the
services are started again and the installer never runs (exit 1).

Refused, nothing changed (exit 3):

- a target **across a major version**, **below v1.1.0**, a pre-release, and the `v0.*` series (a separate product line);
- no usable backup in the history (it was pruned, or the upgrade ran before the history existed): the message lists the
  options, restoring one by hand with `fleet backup:restore <file>` and then running the rollback with
  `--no-db-restore`, or staying on the installed version;
- `--no-db-restore` while the database has migrations the target does not ship (check `schema.ahead` in
  `fleet upgrade --check --to <older>`).

`--no-db-restore` keeps the current database and takes a fresh backup first; use it for a code-only rollback between
releases without migrations, or after restoring a backup yourself.

### Exit codes of `sudo fleet upgrade`
| Code | Meaning |
|---|---|
| 0 | Done and verified (or already up to date). |
| 1 | Failed: the plan, the backup or the installer. The message says whether anything changed. |
| 2 | Usage: a bad option or an unknown `--to`. |
| 3 | Refused, nothing changed: a pre-flight Block, no `--yes`, not root, a moved tag, another upgrade running. |
| 5 | The installer finished but the verification failed. The rollback command is printed. |

### History

`fleet upgrade history [--limit=N] [--json]` lists the upgrades run on this host, newest first. The record is
`/var/log/ddev-fleet/upgrade-history.jsonl` (root-owned, mode 0644, one JSON object per line, appended by the root
helper only, so a restored database cannot erase it). Three lines per upgrade (four for a rollback that restores the database):

| Event | Result values | When |
|---|---|---|
| `start` | `started` | After the backup, before the installer; carries the `backup` file name. |
| `installer` | `ok`, `failed` | When the installer returned. |
| `restore` | `ok`, `failed` | Rollback only: when the database was restored from the named backup. |
| `verify` | `ok`, `unverified` | When the verification returned. |

Every line has `ts` (UTC), `event`, `result`, `from`, `to`, `sha` (the commit the plan resolved), `actor` (the `sudo`
user) and, on `start` and `restore`, `backup` (the file taken before the upgrade, or the one a rollback restored). A rollback is a line whose `to` is older than its `from`. No secret is ever written. `fleet upgrade verify --to vX.Y.Z` runs the verification on
its own.

### Why only the command line

The installer restarts php-fpm, the workers, Caddy and Authelia, so an upgrade started from the web UI would end its own
request and sign everyone out, and letting the application user run "any installer" as root would break the rule that
the application never runs as root. The Upgrade page therefore shows the status, the changelog, the check results and the
history, and the command to copy. There is no sudoers entry for the upgrade helper (`/usr/local/libexec/fleet/upgrade`).

### Internal commands

`fleet:upgrade:prepare` (the plan, `KEY=value` lines for the helper), `fleet:upgrade:backup`, `fleet:upgrade:restore` (the database step of a rollback) and `fleet:upgrade:verify`
are the fleet-user steps the root helper calls; they are not meant to be run by hand, apart from `verify`.
