---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-20
Type: documentation
---

# Moving a project between hosts (1.x)

A project can be **exported** from one host as a single versioned file and **imported** on another host (or on the
same one) after you have looked at what it would change. This is how independent 1.x hosts share a project. The file is
plain JSON, the same on every host that reads the same version.

Contents: [what is in the file](#what-is-in-the-file) · [export](#export) · [secrets and the passphrase](#secrets-and-the-passphrase) ·
[import](#import) · [copying the assets](#copying-the-assets) · [the command line](#the-command-line) ·
[restoring a backup and checking it](#restoring-a-backup-and-checking-it) · [limits](#limits) · [permissions](#permissions) · [troubleshooting](#troubleshooting)

## What is in the file

| Part | Content |
|---|---|
| `project` | The settings of the project: git URL, default branch and template, additional hostnames, git identity, issue id pattern, submodule branch, Typesense, and the **names** of its ports. |
| `ports` | The catalogue ports the project uses, with their public and router numbers. |
| `templates` | Every template: Drupal environment and the `exec`, `tty1`, `tty2` command lists. |
| `webhooks` | The Jira and Bitbucket endpoints with their rules, in order. |
| `schedules` | The schedules that target the project's instances, by instance **label** (`main`, not `blog--main`), with their cron expression, timezone and whether they are paused. |
| `secrets` | Only when you gave a passphrase: the project's secrets, sealed (see below). |

The file starts with `"format": "fleet-project-export"` and `"version": 1`. Keys are in a fixed order and nothing
else is in it: no ids, no timestamps, no instance state, no users, no SSH keys, no host settings. **Webhook secrets and
URLs are not exported**: the new host issues its own, and you read them with *Rotate* on the project's Webhooks tab.
Pipeline access tokens of Bitbucket webhooks are not exported either; set them again on the new host.
**Assets are not in the file**: copy them with rsync ([below](#copying-the-assets)).

Two exports of the same project are byte-for-byte equal when nothing changed, and exporting, importing on an empty host
and exporting again gives the same bytes.

## Export

On the project's **General** tab, the **Export** card downloads `<project>.fleet-project.json`. The passphrase field
is optional; leave it empty to export without secrets. The download is never cached (`Cache-Control: no-store`) and is
recorded in the audit log as `project.export` with the project name only.

On the command line, `fleet export:project <slug>` prints the file to standard output, or writes it with
`--output=<file>` (mode 0600; an existing file is only replaced with `--force`).

## Secrets and the passphrase

Secrets are **left out by default**; `grep` the file for a secret value and you find nothing. To take them along,
give a passphrase (at least 8 characters). The project's secrets are then sealed in the file's `secrets` block:

- the key comes from the passphrase with libsodium `crypto_pwhash` (Argon2id), the secrets are encrypted with
  `crypto_secretbox` (XSalsa20-Poly1305); the salt, the cost parameters and the nonce are stored in the block, so
  any host can open it with the passphrase. The host's own encryption key (`keys/secret.key`) is not involved;
- the passphrase is read from a hidden prompt, from the environment variable `FLEET_EXPORT_PASSPHRASE`, or from the
  first line of standard input with `--passphrase-stdin`. **Never from an option or an argument**: argv is visible to
  every user of the host. The passphrase is never stored, logged or shown;
- a **wrong passphrase** (or a damaged block) is a clean error with one fixed message, and **nothing is written**;
- an import **without** the passphrase imports everything else and reports the secrets as skipped.

Keep the passphrase apart from the file.

## Import

Import never writes before you confirm, and writes all or nothing.

1. **Upload** the file on **Projects → Import** (`/admin/import`), or run `fleet import:preview <file>`.
2. **Preview.** A table lists every item (project, ports, templates, webhooks, schedules, secrets) as one of:

   | Action | Meaning |
   |---|---|
   | `create` | It does not exist on this host. |
   | `update` | It exists and differs; the note names the changed fields (never values). |
   | `unchanged` | It exists and is equal. |
   | `skipped` | Not imported: the sealed secrets when no passphrase was given, or an item that is in conflict (the note and the errors say why). |

3. **Confirm** (*Import this project*), or run `fleet import:apply <file>` (it shows the preview and asks; `--yes`
   skips the question). The import runs in one database transaction, then the sign-in rules and the Caddy sites of
   the project's instances are refreshed; a failure there is a warning, the data is saved.

How a file is matched and applied:

- **Matching is by project name.** A project with the same name is *updated* to what the file says; the preview shows
  it as an `update` before anything happens. Templates, schedules and secrets that exist on this host but are not
  in the file are left alone; nothing is deleted by an import.
- Every value goes through the same rules as the pages and the commands (`fleet project|template|port|schedule`),
  so a file cannot do what a form could not.
- A **port** that does not exist on this host is created; one with the same name and other numbers, or numbers that
  collide with another catalogue port, is a validation error. Catalogue ports are not changed by an import.
- A webhook rule or the default template that names a **template** that is neither in the file nor already on this
  host is a validation error.
- A **schedule** is created even when its instance does not exist yet (you deploy the instances after the import); a
  schedule whose name belongs to another project's instance is refused. A new schedule keeps its paused state.
- Refused, with nothing written: bad JSON, another `format`, a `version` this host does not know (a newer one says
  to update this host first), unknown or missing keys, wrong types, a file over 2 MiB, any value a rule refuses. The
  errors name the path (`templates[1].post_deploy.exec: ...`) and never repeat a value from the file.

Importing needs an administrator. Each import is recorded as `project.import` with the project name.

## Copying the assets

The assets (database dumps, files archives, env templates) are too large for the file. Copy the project's assets
directory from the old host to the new one with rsync, as the `fleet` user, after the import:

```bash
rsync -av --info=progress2 old-host.example.com:/srv/fleet/assets/blog/ /srv/fleet/assets/blog/
```

Run it on the new host as `fleet` (`sudo -u fleet rsync ...`) so the files belong to the right owner; more on this
in [`assets.md`](assets.md). The project's **Assets** tab shows the result after *Rescan*.

## The command line

All commands accept `--format=table|json` or `--json` (as the other `fleet` commands) and use the exit codes
below.

### `fleet export:project <slug> [--output=<file>] [--force] [--with-secrets] [--passphrase-stdin]`

Prints the export file, or writes it to `--output`. `--with-secrets` adds the sealed secrets; the passphrase comes from
the prompt (asked twice), from `FLEET_EXPORT_PASSPHRASE` or from standard input with `--passphrase-stdin`.
`--force` replaces an existing `--output` file.

### `fleet import:preview <file> [--passphrase-stdin]`

Shows what importing the file would do and writes nothing. With sealed secrets in the file the passphrase is
asked (leave it empty to skip the secrets), read from `FLEET_EXPORT_PASSPHRASE`, or from standard input. With
`--json` the output is `{"project", "valid", "counts", "items", "errors", "warnings"}`.

### `fleet import:apply <file> [--yes] [--passphrase-stdin]`

Shows the preview and, after confirmation (`--yes` or an interactive *yes*), imports the file. A file with errors
writes nothing and exits 1. Nothing to change is a success that writes nothing.

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Done (a preview of a valid file, an export, an import). |
| `1` | Refused: the file or a value is invalid, a passphrase is wrong; the errors are listed and nothing was written. |
| `2` | Not allowed now: not confirmed (`--yes` missing), an output file that exists, a passphrase that cannot be read non-interactively. |
| `3` | The project does not exist (`export:project`). |

## Restoring a backup and checking it

A move of a whole host is a **backup restore**, not an export: see "Backup and restore" in
[`installation.md`](installation.md). Before you restore a file, check it:

```bash
sudo fleet backup:verify /srv/fleet/backups/fleet-20261019-083015.sqlite
```

`fleet backup:verify <file> [--json]` opens the file **read-only** (it never changes the file or the live database)
and prints:

- `integrity: ok` from SQLite's `PRAGMA integrity_check`;
- `schema: current`, or `behind` with the number of migrations to run after the restore
  (`fleet doctrine:migrations:migrate -n`), or `newer` (a backup from a newer release, which fails the check).

Exit 0 means the file is sound, 1 that it failed a check, 2 that the path is not a file to check (missing, a link, or
the live database). A restore that has not passed `backup:verify` is a gamble.

Not part of this release: restoring into the live database with a command, a host-wide export (users, SSH keys,
host settings), and reading a 0.x `fleet.yml`.

## Limits

| Limit | Value |
|---|---|
| File size | 2 MiB (upload and import) |
| Templates per project | 50 |
| Ports per project | 50 |
| Passphrase | 8 to 1024 bytes |
| Sealed block cost | Argon2id, 2 to 10 passes, 64 MiB to 1 GiB (the export uses libsodium's *moderate* setting) |

## Permissions

Export and import (pages and commands) are for administrators. The pages need the stateless CSRF token on every POST.
A project member who is not an administrator gets a 403.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| "The passphrase is wrong, or the sealed secrets are damaged." | The passphrase is not the one used at export, or the file was edited. Nothing was written. |
| "A passphrase is needed" | `--with-secrets` without a terminal: set `FLEET_EXPORT_PASSPHRASE` or use `--passphrase-stdin`. |
| "this host already has a port with this name and other numbers" | Rename or renumber the port on one host so both agree, then export again. |
| "is not a known export version" / "this host reads version 1 at most" | The file comes from another release. Update the host that reads it. |
| The imported project has no webhook secret you know | Webhook secrets are issued per host: use *Rotate* on the Webhooks tab and copy the new one into Jira or Bitbucket. |
