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
   something; the upgrade repeats it before it asks you to confirm (see `changelog.d/README.md` for how release authors write them);
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

## Pre-flight checks

Every plan runs the checks below before anything changes, in this order. Each finding has a severity: `block` stops the
upgrade (exit 3), `warn` is shown and does not stop it, `info` is for the record. "Waivable" means a `block` that
`--force=<id>` can set aside; the others cannot be waived.

| Id | Severity | Waivable | What it means and what to do |
|---|---|---|---|
| `host.sudo` | block | no | `sudo` is missing. Install it, or log in as root and run `fleet upgrade` there. |
| `network.source` | block | no | The release source did not answer to `git ls-remote`. Restore the connection and check again. |
| `checkout.missing` | block | no | `/opt/ddev-fleet` is not a git checkout. Re-run the installer to restore it. |
| `checkout.dirty` | block | no | The checkout has local changes, and the installer's `git reset --hard` would discard them. Commit, stash or discard them. |
| `checkout.moved` | block | no | The checkout is not at the commit named by `installed-revision`. Check out that commit again. |
| `checkout.origin` | block | no | `origin` is not the release source. Set it back with `git remote set-url origin <source>`. |
| `checkout.revision-unknown` | warn | no | `installed-revision` is unreadable, so the checkout cannot be compared with it. Check that the file holds a commit sha. |
| `target.series` | block | no | The target is not a `v1.*` release. The 0.x line is a separate product. |
| `target.below-minimum` | block | no | The target is older than v1.1.0, the first release that can be upgraded to or from. |
| `target.not-on-main` | block | no | The tag's commit is not on `main`: only tags cut from `main` are releases. |
| `target.main-unverified` | warn | yes | `main` could not be read, or the checkout lacks the history to prove the tag is on it. The installer re-verifies. Check again when the source answers. |
| `disk.space` | block | no | Free space is below twice the databases plus room for the new code. Prune old backups, destroy instances you do not need. |
| `jobs.active` | warn | yes | Jobs are queued or running and restarting the workers would cut them off. Wait for them (Jobs page), or pass `--force=jobs.active`. |
| `instances.id-length` | block | yes | An instance id is longer than DDEV allows (59 characters). Destroy it or recreate it with a shorter label, or pass `--force=instances.id-length` to leave it as it is. |
| `schema.ahead` | warn | no | The database has migrations the target lacks (a rollback). The database is restored from the backup taken before the upgrade being undone, and later data is lost. |
| `schema.unknown` | info | no | The target's migrations could not be read here, so the schema was not compared. |
| `rollout.action` | warn | no | A release in between has an `ACTION:` rollout note (one finding per note). Do what it says, then confirm. |
| `rollout.unreadable` | info | no | The rollout notes could not be read; the message says why. |
| `check.crashed` | block | no | A check could not run. The message names it; fix the cause and check again. |

The target's own installer then checks the host with `install/run.sh --preflight` of the target release (a release
older than that flag is skipped with `installer.unsupported`; an installer that cannot be fetched is `installer.unavailable`
and one that cannot run is the block `installer.failed`). Its findings appear as `installer.<id>`:

| Installer id | Severity | What it checks |
|---|---|---|
| `host.os` | block, warn, info | The host is Debian 13. Another release only warns when the installer's unsupported-host override is set. |
| `host.arch` | block | The CPU architecture is supported. |
| `disk.opt`, `disk.srv`, `disk.var` | block, info | Free space under `/opt`, `/srv/fleet` and `/var` (500, 2048 and 2048 MiB). |
| `instances.id-length` | block | No existing instance id is longer than the target allows. |
| `docker.answer`, `ddev.answer` | block, info | Docker and DDEV are installed and answer. |
| `host.packages` | block, warn, info | The commands the installer needs are present (a missing one is installed by the installer). |
| `fleet.env` | block, info | `fleet.env` holds the keys the target requires (a missing key is added by the installer; an unusable `MERCURE_JWT_SECRET` stops it). |
| `network.repo` | block, info | The release source answers within the time limit. |

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

Steps: plan (as for an upgrade, including the schema comparison), the installer of the older release is fetched and
unpacked first (an unreachable source therefore costs nothing), the fleet services (scheduler, every active worker
instance, status watcher and php-fpm) are stopped, the backup is restored (`fleet-user`, the current database is kept next to the backups as
`*.pre-restore-*`), the installer of the older release runs (it applies that release's migrations with its own code and
starts the services again), then the verification (a release older than the upgrade tooling has no `fleet upgrade verify`; the helper then checks
the installed version and `/health` itself). If the restore fails, the previous database stays in place, the
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

## Rollout notes

A release can tell the operator what to do or know when it is installed. The notes live in the release's `CHANGELOG.md`, in
a `#### Rollout` subsection at the end of the version section, one bullet per note:

```markdown
## [1.4.0] - 2026-12-01

### Changed
- **FLE-123: the worker now reads FLEET_FOO**

#### Rollout
- ACTION: add FLEET_FOO=... to /etc/ddev-fleet/fleet.env before upgrading.
- The first request after the upgrade rebuilds the cache and is slower.
```

- A bullet that starts with `ACTION:` needs the operator to do something. `fleet upgrade --check` and the Upgrade page
  show it as a `rollout.action` warning, and `sudo fleet upgrade` repeats it before it asks for confirmation (`--yes`
  or a terminal `y`). It never blocks.
- Any other bullet is information.
- The check reads the notes of **every release between the installed one and the target**, so skipping a release does not
  skip its notes.
- Release authors write the notes in the change's fragment (`changelog.d/README.md`) and the release step assembles them
  (`RELEASING.md`).

## Offline hosts and mirrors

The check and the upgrade need the release source (the public mirror by default): the check lists tags with
`git ls-remote` and reads the `CHANGELOG.md` of the tag, and the installer clones the release.

- **The check never fails with a stack trace.** When the source cannot be read it prints the installed version, the latest
  release from the last good list with its age and the reason, and exits 4. `--json` has `status: offline` and
  `catalog.error`. The Upgrade page shows the same ("Last checked ... failed: ...") from the cached list; no notice is
  invented.
- **An upgrade needs the source** and refuses without it (exit 3, nothing changed): the installer has to clone.
- **A mirror or a local repository** works as the source. `sudo fleet upgrade --repo-url=<url or path>` installs from it,
  and the unprivileged check follows `FLEET_UPGRADE_REPO_URL` in `fleet.env` (the privileged run ignores that variable on
  purpose: root takes a source only from its own command line). The mirror must carry annotated `v1.*` tags on a `main`
  branch, as the public one does.

## Rehearse on a test host

`ci/upgrade-rehearsal.sh` proves the whole path on a host built for testing, with no public release. It builds a local
bare repository from the host's own checkout (the installed release, then two throwaway annotated tags, by default
`v1.9.1` and `v1.9.2`, the second with a rollout note and an `ACTION:` line), points the release source and the checkout's `origin` at it, and runs
as root: the check, an upgrade refused for lack of `--yes`, an upgrade to the first tag, an upgrade to the latest, a
rollback with the database restored, and a rollback to the release the host started on. After each step it asserts the
exit code, `/etc/ddev-fleet/installed-version` and the check's verdict, then removes the override, points `origin` at the
public mirror again, deletes the throwaway tags and refreshes the release cache.

```
sudo ci/upgrade-rehearsal.sh --yes-this-is-a-test-host     # the rehearsal
ci/upgrade-rehearsal.sh --build-only [--base-tag=vX.Y.Z]   # only build the repository, no root, no change to the host
ci/upgrade-rehearsal.sh --dry-run                          # print the plan
```

It is not part of CI and must not run on a host people use: the installer runs several times, the workers, php-fpm,
Caddy and Authelia restart (**everyone is signed out**) and the database is restored once. Run it when no job is
running. It exits 0 when every assertion held, 1 when one failed, 3 when it refused to start. It leaves the backups and the
rehearsal lines in the history file behind. The pieces are covered in CI with fakes (`UpgradeHelperTest`,
`UpgradeRunnerTest`).

## FAQ

**Does it need root?** `fleet upgrade --check`, `fleet upgrade history` and `fleet upgrade verify` do not. `fleet upgrade`
(the actual upgrade and the rollback) does, and without it prints the exact `sudo` line.

**Can I upgrade from the web page?** No. The page shows the status and the command to copy ("Why only the command line").

**Will it sign people out?** Yes: the installer restarts Authelia, which ends every session. Running jobs are cut off when
the workers restart (`jobs.active` warns). Pick a quiet moment.

**What if the installer fails halfway?** It is idempotent: run the same command again. The history shows `installer failed`;
nothing is rolled back for you, because code and database may be mid-migration. `sudo fleet upgrade --to <previous>` is the way back.

**What if the verification fails?** Exit 5, and the rollback command is printed. The history shows `unverified`.

**How do I go back?** `sudo fleet upgrade --to <previous version> --yes`. The database comes back from the backup taken
before the upgrade, so later data is lost ("Roll back").

**The check says `not_a_release`.** The host runs a branch or a development tree (`installed-version` is `main@abc1234`),
not a tagged release. Install a release with `install.sh --ref=vX.Y.Z` first.

**`checkout.dirty` blocks me.** Something changed files in `/opt/ddev-fleet`. The installer would discard those changes, so
the upgrade refuses and cannot be forced. Move your changes out, or commit them elsewhere.

**Can I skip a release?** Yes. `--to` any published release; the notes of the releases in between are still shown.

**Does it upgrade Docker, DDEV, Caddy or the OS?** No. `apt` does that; the installer only refreshes the custom Caddy and
the Mercure hub.

**Is there an unattended mode?** No. The daily timer (`fleet-upgrade-check.timer`) only checks and fills the cache behind
the "available" badge; it never upgrades.
