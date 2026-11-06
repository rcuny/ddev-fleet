---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Export format

Moving a project to another host should take minutes, not an afternoon. Export writes one JSON file with the project's setup; import shows what would change before it writes anything. When to use it: [operate](../guides/operate.md). Administrators only.

## What the file contains

| Included | Not included |
|---|---|
| `project` settings and the names of its ports; `ports` (public and router numbers); every `templates` entry; `webhooks` rules; `schedules` of its instances (by label, e.g. `main`); `secrets` only with a passphrase. | Assets, users, SSH keys, host settings, instance state, webhook URLs and secrets (the new host issues its own: use **Rotate** on the Integrations tab). |

The file starts with `"format": "fleet-project-export"` and `"version": 1`. A host refuses a version it does not know.

## Commands

| Command | Does |
|---|---|
| `fleet export:project` | Writes the file (mode 0600; an existing file needs `--force`). The same file downloads from the project's **Transfer & danger** tab. |
| `fleet import:preview` | Shows what importing would do; writes nothing. |
| `fleet import:apply` | Previews, asks, then imports. |
| `fleet backup:verify` | Checks a backup file read-only (integrity and schema). A whole host moves by [backup and restore](backup-and-restore.md), not by export. |

Options are in [cli.md](cli.md).

| Exit code | Meaning |
|---|---|
| `0` | Done. |
| `1` | Refused: invalid file or value, wrong passphrase. Nothing written. |
| `2` | Not confirmed, output file exists, or the passphrase cannot be read non-interactively. |
| `3` | The project does not exist (export). |

## Passphrase and sealing

Secrets are left out unless you give a passphrase (8 to 1024 bytes). The key is derived with libsodium `crypto_pwhash` (Argon2id) and the secrets are encrypted with `crypto_secretbox`; the salt, cost parameters and nonce are stored in the file, so any host can open it. The host's own `keys/secret.key` is not involved.

The passphrase comes from a hidden prompt, the environment variable `FLEET_EXPORT_PASSPHRASE`, or `--passphrase-stdin`; never an argument. A wrong passphrase writes nothing. An import without it brings everything but the secrets. Keep the passphrase apart from the file.

## Import

Nothing is written before you confirm, and the write is all-or-nothing (one transaction; the sign-in rules and Caddy sites refresh afterwards, a failure there is a warning).

| Preview state | Meaning |
|---|---|
| `create` | Does not exist on this host. |
| `update` | Exists and differs; the note names the changed fields, never values. |
| `unchanged` | Exists and is equal. |
| `skipped` | Not imported: secrets without a passphrase, or a conflict. |

Matching rules:

- By project name. A same-named project is updated; nothing on the host is ever deleted.
- A port missing here is created. Same name with other numbers, or numbers colliding with another catalogue port, is an error. Existing catalogue ports are not changed.
- A webhook rule or default template naming a template that is neither in the file nor on this host is an error.
- A schedule is created even when its instance does not exist yet. One whose name belongs to another project's instance is refused. A new schedule keeps its paused state.
- Refused with nothing written: bad JSON, another `format`, an unknown `version`, unknown or missing keys, wrong types, a file over the limit, any refused value. Errors name the path and never repeat a value.

## Copying the assets

Assets are too big for the file. After the import, on the new host:

```bash
sudo -u fleet rsync -av old-host.example.com:/srv/fleet/assets/shop/ /srv/fleet/assets/shop/
```

Then press **Rescan** on the Assets tab ([assets and secrets](assets-and-secrets.md)).

## Limits

| Limit | Value |
|---|---|
| File | 2 MiB |
| Templates, ports | 50 each per project |
| Passphrase | 8 to 1024 bytes |

## Learn more

- [Operate](../guides/operate.md)
- [Backup and restore](backup-and-restore.md)
