---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-03
Type: documentation
---

# Upgrading

How to see what the next release of Fleet changes before you install it. The upgrade itself (`sudo fleet upgrade`) and
the rollback are added by later tickets of the same milestone; until then a release is installed by re-running the
installer (`installation.md`, section "Upgrading").

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
