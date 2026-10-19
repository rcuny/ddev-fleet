---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Secrets

Credentials and tokens are entered once, in the browser or on the host, and stored encrypted. They are
never shown in a list again. Instances receive them through **placeholders** in the project's asset files
(`[[my-token]]`); a person with the admin role can **reveal** a value, and every reveal is recorded.

This guide covers the milestone M0.05 of the 1.x line: the pages, the commands, the placeholder rules and
the security model. Deploying instances, and with it the substitution into asset files, arrives in M0.04;
uploading asset files in M0.06. Until then a secret can be stored, listed, revealed and checked for a
placeholder, but nothing delivers it yet.

## Concepts

| Kind | Where it lives | Used for |
|---|---|---|
| **Fleet-wide secret** | `Secrets` in the sidebar (`/admin/secrets`) | A value several projects share, for example a token of a service every instance talks to |
| **Project secret** | the **Secrets** tab of a project (`/admin/projects/<name>/secrets`) | A value that belongs to one project. A project secret wins over a fleet-wide one of the same name |
| **Host secret** | set by the setup wizard (DNS credentials, the Claude token) | Used by Fleet itself. Listed read-only under "Host secrets", never offered to an instance, never a placeholder |

Everything here is administrator-only. A member of a project cannot list, set, reveal or delete a secret
(the pages answer 403, and each controller checks the role itself).

## Names and values

- A name is capital letters, digits and underscores, starting with a letter, at most 64 characters:
  `JIRA_TOKEN`, `DB_PASSWORD_2`. Names are the same shape as an environment variable.
- The placeholder for a secret is its name in lowercase with dashes: `JIRA_CLAUDE_TOKEN` is
  `[[jira-claude-token]]`. The page and `fleet secret:list` show it next to each name.
- A name may not be the same as a built-in placeholder (`[[project]]`, `[[instance]]`, `[[instance-id]]`,
  `[[instance-fqdn]]`, `[[branch]]`, `[[fleet-domain]]`, `[[issue-id]]`): `BRANCH`, `PROJECT` and the like are refused.
- A value is text: valid UTF-8, no NUL byte, 1 byte to 64 KiB. Several lines are fine (a private key).
  A browser sends line breaks as CRLF and people paste a trailing newline; both are normalised (CRLF becomes LF,
  trailing line breaks are dropped) before the value is stored.
- **Do not wrap the value in quotes.** Asset files usually quote the placeholder themselves
  (`TOKEN="[[my-token]]"`), and a value that carries its own quotes lands as `""abc""` and fails silently in
  the consumer (a 0.x trap). Fleet refuses a value wrapped in one pair of quotes and says so; a quote inside the
  value is fine.
- The value is **write-only**: the form never pre-fills it, a list never shows it, an error message never repeats it.

## The pages

- **Secrets** (`/admin/secrets`): the fleet-wide secrets, a form to add or replace one, and the host secrets
  (names only). **Reveal**, **Replace** (opens the form with the name filled in) and **Delete** (with a
  confirmation) are on each row.
- **Secrets tab** of a project: the same for that project, plus a check that each secret is used.
- **Audit log** (`/admin/audit`, linked from the Secrets page): the latest 200 entries, newest first, optionally
  of one action. Every put, delete and reveal of a secret is an entry with who, when, the action
  (`secret.put`, `secret.delete`, `secret.reveal`, `secret.reveal-refused`) and the subject (`fleet/NAME` or
  `project:<project>/NAME`). The log never contains a value. A field longer than its column is cut on a character
  boundary and ends in `…`, so a cut shows.
- **Reveal** is a POST with a CSRF token. The response shows the value once in a dialog; the response is never
  cached (`Cache-Control: no-store, private`), the reveal is recorded before the value is handed out (if the audit
  entry cannot be written, nothing is shown) and reloading the page shows nothing. A value that cannot be opened (the
  key file was replaced, the row was tampered with) is reported and nothing is revealed. Each user may reveal 10
  values per minute (sliding window, limiter `secret_reveal` in `config/packages/rate_limiter.yaml`); the 11th answers
  429 with a `Retry-After` header and the page says to wait, nothing is opened, and the refusal is an audit entry
  (`secret.reveal-refused`).
- Deleting a **project** deletes its secrets too, with one audit entry per secret.

All pages work at 390 px: the rows stack, the buttons are 44 px high.

### The "No placeholder" warning

In 0.x a secret that no asset file mentioned was silently inert: it was stored, never delivered, and nobody noticed.
The Secrets tab scans the text files of the project's assets directory (`assets/<project>/` under the fleet home)
for `[[token]]` placeholders and flags every project secret whose placeholder no file mentions:

> No file in this project's assets mentions `[[lonely-key]]`, so instances never receive this value.

The scan reads text files up to 1 MiB, skips binary files, files that are not UTF-8 and symbolic links, and stops
after 5000 files (the page says so). While the project has no asset files at all, nothing is flagged and the tab
says "No asset files yet". Fleet-wide secrets are never flagged: any project may use them.

## Placeholders

`App\Fleet\Secrets\TokenRenderer` renders a text for a project. It is the only deploy-time substitution:

- A placeholder is `[[` followed by lowercase letters, digits and dashes, then `]]`. `[[ token ]]`, `[[TOKEN]]`
  and `[[my_token]]` are not placeholders and are left alone.
- It resolves to the project secret of that name, else the fleet-wide one. Built-in values the caller supplies win
  over any secret. Host secrets are never substituted.
- Values are inserted **verbatim**: no quoting, no escaping. The text is scanned once, so a value that itself
  contains `[[x]]` is not expanded again.
- Where a deploy substitutes (built-in tokens first, then the project's and fleet-wide secrets, every inserted value
  registered with the job log masker before the first log line that could hold it):

  | Site | Secrets | Unresolved token |
  |---|---|---|
  | Post-deploy commands (after splitting, inside each word, so a value never adds shell syntax) | resolved, in the argument list | The deploy fails in the preflight, before anything is changed; the log names the token |
  | Asset files seeded into the checkout (text files up to 1 MiB) | resolved | Left as written; a log line names the token |
  | `tty1` / `tty2` commands typed into the tmux window | **never**: the pane keeps what is typed in its scrollback. Only the built-in tokens are substituted | The command is skipped with a `WARNING` naming the token; the deploy goes on |

  The instance configuration (`config.fleet.yaml`, the Drupal settings) has no placeholders: the Claude token is
  written there by its own code path, also masked.
- A placeholder that nothing resolves is **reported** (`render`) or **refused** (`renderStrict` throws
  `UnresolvedTokens` naming the placeholders, never a value); the text keeps it as written.

## Commands

On the host, as `fleet secret:<verb>` (the wrapper also accepts `fleet secret <verb>`):

```bash
fleet secret:list [--project=blog] [--format=table|json] [--json]
fleet secret:set <name> [--project=blog] [--format=table|json] [--json]
fleet secret:unset <name> [--project=blog] [--yes] [--format=table|json] [--json]
```

| Command | What it does |
|---|---|
| `fleet secret:list` | Names, placeholders, who set each and when, never a value. With `--project`, a **Used** column says whether an asset file mentions the placeholder (`yes`, `NO`, `-` when not known) and each unused secret is named below the table |
| `fleet secret:set <name>` | Adds a secret or replaces its value. The value is **never an argument or an option** (it would end up in the shell history and the process list): it is read from a hidden prompt on a terminal, or from standard input when piped, for example `printf '%s' "$TOKEN" \| fleet secret:set JIRA_TOKEN --project=blog` |
| `fleet secret:unset <name>` | Deletes a secret. Asks for confirmation on a terminal; scripts and `--json` need `--yes` |

`--project` picks a project's secrets; without it the fleet-wide ones. Changes made on the command line are
recorded in the audit log under the actor `cli`.

`fleet secret:list --json` prints one document, names only:

```json
{"scope": "project", "project": "blog", "secrets": [
  {"name": "JIRA_TOKEN", "placeholder": "[[jira-token]]", "updated_by": "cli",
   "updated_at": "2026-10-19T10:00:00+00:00", "referenced": true}
]}
```

`referenced` is `true`, `false`, or `null` (fleet scope, or the project has no asset files yet). For `set` and
`unset` the document is `{"saved": "<name>", "project": ...}` or `{"deleted": "<name>", "project": ...}`.

### Exit codes and errors

| Code | Meaning |
|---|---|
| `0` | Done |
| `1` | A value was refused (name or value; JSON: `{"error": "Invalid values.", "fields": {"<name>": "...", "<value>": "..."}}`) |
| `2` | Not allowed now (a delete without `--yes`, a concurrent change: run it again) |
| `3` | The project or the secret does not exist |

Refused values are described by field (`<name>`, `<value>`) and never echoed.

## Security model

- Values are sealed with libsodium XChaCha20-Poly1305. The 32-byte key is `keys/secret.key` under the fleet home
  (created once by `fleet:host:init`, mode 0600, never in the database, never overwritten).
- Each ciphertext is bound to its scope, scope id and name as authenticated data: a row copied into another
  secret or another project fails to open. A fresh random nonce is drawn for every write.
- The database holds only ciphertext, a nonce, a key version and who/when. A list reads names and stamps, never a
  value; a value is opened only to reveal it, to render a placeholder, or by the code that owns a host secret.
- A secret a deploy uses (the Claude token in the instance configuration, any value `TokenRenderer` inserts) is
  registered with the job log masker, which replaces it with `[masked]` in the job log, the live tail and the job's
  error text. The registry is cleared when the job ends.
- A value is never written to a log, an exception message, argv or the process environment: parameters that carry
  one are marked `#[\SensitiveParameter]`, and a test checks the log records of every request that touches a value.
- Backing up the database without `keys/secret.key` loses every secret; backing up both together keeps them
  readable. A key rotation command arrives later.

## Differences from 0.x

| 0.x | 1.x |
|---|---|
| `$FLEET_HOME/.secrets` and `secrets/<project>.env` files edited by hand | Encrypted rows, set in the browser or with `fleet secret:set` |
| A quoted value had one pair of quotes stripped when read | A quoted value is refused with an explanation; values are inserted verbatim |
| A secret without a placeholder was silently inert | Flagged on the project's Secrets tab and in `fleet secret:list --project` |
| Names such as `jira_claude_token` | `JIRA_CLAUDE_TOKEN`; the placeholder is unchanged (`[[jira-claude-token]]`) |
| No record of who read a secret | Every reveal is in the audit log |
