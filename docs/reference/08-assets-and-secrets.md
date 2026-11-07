---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Assets and secrets

Every new instance needs a database dump, a `.env`, local settings and API keys, and none of that belongs in git. This page is the lookup for the rules Fleet applies when it stores and delivers them. For the walk-through, see [first project](../guides/02-first-project.md).

- **Assets** are plain files in `/srv/fleet/assets/<project>/`. Fleet copies them into every new checkout.
- **Secrets** are encrypted values. Instances receive them through `[[placeholder]]` tokens inside asset files.
- Both are administrator-only ([share and access](../guides/06-share-and-access.md)).

## Assets

There is no index: the **Assets** tab (`/admin/projects/<project>/assets`) shows what is on disk. **Upload files** and **Create folder** (up to 8 levels) act on the current folder. **Rescan** reloads the list after you copy files in over SSH. A folder is deleted only when empty. Commands: `fleet asset:list`, `fleet asset:gc`, `fleet asset:snapshot` ([01-cli.md](01-cli.md)). Files are created with mode 0640 (folders 0750); a symbolic link in the way is refused with exit code 2.

### How a deploy seeds

Before `ddev start`, a deploy copies the whole tree into the checkout with the same relative paths. The job log says `assets: seeded N file(s)`.

| What | Rule |
|---|---|
| Text files (UTF-8, up to 1 MiB) | `[[token]]` placeholders are replaced. Unresolved ones stay as written and are logged by name. |
| Other files | Copied byte for byte. |
| `dumps/` | Hard-linked (no extra disk), never substituted. |
| Git | Everything copied goes into `.git/info/exclude`, so `git status` stays clean. |
| Database import | Not done by Fleet. Put your import command in the template's `post_deploy`. A `files.tar.gz` is not extracted either. |
| Failure | An unreadable file, or a checkout folder that is a symbolic link where the assets have a folder, fails the deploy and the log names the path. A project with no assets directory is a no-op. |

### Browser upload internals

| Topic | Behaviour |
|---|---|
| Chunks | The browser sends 8 MiB chunks, one request at a time, so Caddy and php-fpm never see a request bigger than a chunk. A chunk may be 64 KiB to 8 MiB; at most 65 536 per file. A failed chunk is retried three times. |
| Staging | `/srv/fleet/uploads/<id>/` (mode 0700). Sending a chunk twice is harmless. The disk needs room for the file plus one chunk. |
| Assembly | Chunks are joined into a hidden temporary file, then moved in with one atomic rename. A half-finished file never appears. The screen shows the file's sha256. |
| Resume | Choose the same file again (same name, size, modified time); only missing chunks are sent. |
| Limit | 2 GiB per file by default: `asset_upload_max_bytes`, changed on the **Uploads** tab (`/admin/host?tab=uploads`) in MiB. A bigger file is refused before anything is sent. |
| Existing name | The browser asks before replacing a file. A folder or link of that name is never replaced. |
| Abandoned uploads | An upload untouched for 24 hours is deleted by the next upload that starts and by `fleet asset:gc` (which also removes leftover temporary files). Run it from cron if uploads are rare. |
| Gaps | A download has no `Range` support (it restarts) and is cut after php-fpm's 240 s request timeout. An empty file cannot be uploaded. For large files use SSH. |

### Copying over SSH

The directory belongs to `fleet`, so copy as an admin with `sudo`, then press **Rescan**:

```bash
rsync -av --rsync-path="sudo rsync" --chown=fleet:fleet --chmod=D750,F640 \
  ./dumps/ admin@fleet.example.com:/srv/fleet/assets/blog/dumps/
```

### Names, paths and symbolic links

- Paths are relative to the project's directory: no `..`, no absolute path.
- Allowed name characters: letters, digits, space and `. _ - ( ) + @ , = ~`. Other names show as "name not supported here"; rename them on the host.
- Symbolic links are never followed by the screen or the upload.
- A deploy honours a link only when it ends at a regular file inside the same project's assets (for example `dumps/default.sql` pointing at `dump-prod.sql`). Others are skipped and logged. A deploy never reads outside the project's assets.

### Snapshot

The **Snapshot a running instance** card (or `fleet asset:snapshot <instance-id>`) queues a job that runs `ddev export-db` and saves `dumps/default-<instance-id>-<UTC time>.sql.gz`. It never replaces an earlier snapshot. A stopped or busy instance is refused.

### Limits

Limits: 2 GiB per browser upload by default; folder depth 8, name 255 bytes, whole path 1024 bytes; 2000 items shown per folder; unfinished uploads kept 24 hours.

Dumps can hold personal data: Caddy never serves these files.

## Secrets

| Kind | Where | Used for |
|---|---|---|
| Fleet-wide | **Secrets** (`/admin/secrets`) | A value several projects share. |
| Project | **Keys & secrets** tab (`/admin/projects/<name>/keys`) | One project's value. Wins over a fleet-wide one of the same name. |
| Host | **Host settings** tabs (Claude token, Notifications) and the wizard or `fleet host:ovh-credentials` (OVH credentials) | Used by Fleet itself. Never given to an instance, never substituted. |

Commands: `fleet secret:list`, `fleet secret:set`, `fleet secret:unset` (see [01-cli.md](01-cli.md)). Changes from the command line are audited as actor `cli`.

### Names and values

| Rule | Detail |
|---|---|
| Name | Capitals, digits, underscores, starting with a letter, at most 64 characters (`JIRA_TOKEN`). |
| Placeholder | The name in lowercase with dashes: `[[jira-token]]`. Only `[[` + lowercase letters, digits, dashes + `]]` counts. |
| Reserved | A secret may not take a built-in placeholder: every built-in placeholder `[[project]]`, `[[instance]]`, `[[instance-id]]`, `[[instance-fqdn]]`, `[[branch]]`, `[[fleet-domain]]`, `[[issue-id]]`. |
| Value | UTF-8, 1 byte to 64 KiB, multi-line allowed. Trailing line breaks are dropped. |
| Write-only | Never pre-filled, listed or echoed. |

The value is never an argument or an option (it would land in shell history). Use the hidden prompt or standard input: `printf '%s' "$TOKEN" | fleet secret:set JIRA_TOKEN --project=website`.

**Do not wrap the value in quotes.** Asset files usually quote the placeholder (`TOKEN="[[my-token]]"`), so a quoted value lands as `""abc""` and fails silently. Fleet refuses a value wrapped in one pair of quotes.

### Where placeholders resolve

A placeholder resolves to the project secret, else the fleet-wide one. Built-in values win over any secret. Values are inserted verbatim and never re-expanded.

| Where | Secrets | Unresolved |
|---|---|---|
| Post-deploy commands | Resolved | The deploy fails before changing anything. |
| Asset files seeded into the checkout | Resolved | Left as written, logged by name. |
| `tty1`/`tty2` commands | Never (built-in tokens only) | Command skipped with a warning. |

### Reveal, audit and warnings

- **Reveal** needs a password re-check (valid 5 minutes), shows the value once, is never cached, and is limited to 10 per minute per user (the 11th answers 429).
- **Audit log** (`/admin/audit`, newest 200): `secret.put`, `secret.delete`, `secret.reveal`, `secret.reveal-refused`. Other actions in the same log: `reauth.success`, `reauth.failure`, `reauth.required`, `claude.token.rotate`, `project.container-ssh`. Subjects are `fleet/NAME` or `project:<project>/NAME`. The log never contains a value.
- **Unused secret:** the Keys & secrets tab warns "No file in this project's assets mentions `[[name]]`". The scan reads text files up to 1 MiB, at most 5000 files, and skips binary files and symbolic links. Fleet-wide secrets are never flagged.

### JSON and exit codes

`fleet secret:list --json` prints names only:

```json
{"scope": "project", "project": "blog", "secrets": [
  {"name": "JIRA_TOKEN", "placeholder": "[[jira-token]]", "updated_by": "cli",
   "updated_at": "2026-10-19T10:00:00+00:00", "referenced": true}
]}
```

`"referenced"` is `true`, `false`, or `null` (fleet scope, or no asset files yet). `set` answers `{"saved": "<name>", ...}`, `unset` answers `{"deleted": "<name>", ...}`. A refusal is `{"error": "Invalid values.", "fields": {...}}`; the `"fields"` object names the field and never echoes the value.

| Exit code | Meaning |
|---|---|
| `0` | Done. |
| `1` | A name or value was refused. |
| `2` | Not allowed now (delete without `--yes`, concurrent change). |
| `3` | The project or secret does not exist. |

### Security model

- Values are sealed with libsodium XChaCha20-Poly1305. The key is `keys/secret.key` under the fleet home (mode 0600, created once, never in the database).
- Each value is bound to its scope and name, so a copied row fails to open.
- Deploy-time values are shown as `[masked]` in job logs, the live tail and error text.
- Back up the database together with `keys/secret.key`, or every secret is lost. There is no key rotation ([backup and restore](06-backup-and-restore.md)).

## Learn more

- [First project](../guides/02-first-project.md): assets and secrets in practice.
- [Troubleshooting](13-troubleshooting.md): upload and symlink problems.
