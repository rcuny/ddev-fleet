### Added
- **FLE-18: `fleet upgrade`, the milestone.** One safe command keeps a 1.x server current and gives it a way back.
  `fleet upgrade --check` (no root) shows the installed and the latest release, the changelog and the rollout notes
  between them (an `ACTION:` note is called out) and the pre-flight results; `sudo fleet upgrade [--to vX.Y.Z]` plans,
  checks, takes a database backup, runs the installer of the resolved commit, verifies the host (health, sign-in, version,
  migrations, workers) and records the run in a root-owned history; `sudo fleet upgrade --to <older release>` rolls back
  and restores the database from the backup taken before the upgrade being undone. The Upgrade page and the
  "vX.Y.Z available" notice show the status to administrators, and a daily timer keeps the release list fresh. The upgrade
  itself is deliberately command-line only: there is no sudoers entry for it. The installer gained `--preflight` and
  writes `/etc/ddev-fleet/installed-version`. See `docs/upgrading.md` for the commands, flags, exit codes and every
  pre-flight check id, `docs/installation.md` section 6, and `docs/RELEASING.md` for how to write rollout notes.
- **FLE-155: `ci/upgrade-rehearsal.sh`.** A rehearsal for a test host: it builds a local release repository with two
  throwaway annotated tags and runs check, upgrade, upgrade, rollback and a rollback to the starting release, asserting the
  exit codes and `installed-version` after each step, then puts the host back. `--build-only` and `--dry-run` change nothing.

## Rollout
- Hosts installed before this release do not have `fleet upgrade`: move them to this release with the
  installer (`install.sh --ref=vX.Y.Z`); every later release can use `sudo fleet upgrade`.
