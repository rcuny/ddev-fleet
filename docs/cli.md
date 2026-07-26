---
Author: Claude Code
Reviewer: none
Last updated: 2026-07-25
Type: documentation
---

# CLI reference

Full reference for the `fleet` command, generated against the real,
landed `argparse` surface in `src/fleet/cli.py` — every command below
matches `_build_parser()` exactly. On the server, `/usr/local/bin/fleet`
(installed by the `fleet_service` Ansible role) execs the venv CLI as the
`fleet` user, so any admin account can run these without `sudo -u fleet`
or the venv path. Locally: `venv/bin/fleet`.

Every command accepts a global `--fleet-home <path>` before the
subcommand (defaults to `$FLEET_HOME`, or `/srv/fleet` if unset):

```bash
fleet --fleet-home /srv/fleet list
```

For narrative walkthroughs (first deploy, rotating credentials, adding a
port) see `docs/installation.md` and `docs/operations.md`; for the
`fleet.yml` schema these commands read/write against, see
`docs/configuration.md`.

## Command table

| Command | Arguments | Behavior |
|---|---|---|
| `fleet init` | `[--domain=<domain>] [--skip-claude]` | Interactive first-run setup: creates `$FLEET_HOME`'s directory tree, prompts for the fleet domain if `--domain` is omitted, creates `fleet.yml` (local-file mode by default — copies `fleet.yml.dist` and patches only `fleet.domain`; or clones a private config repo when `FLEET_CONFIG_REPO` is set — see below), and runs `claude setup-token` to mint `CLAUDE_CODE_OAUTH_TOKEN` into `.secrets` (skip with `--skip-claude`). Never overwrites an existing `fleet.yml` or re-clones an existing `config/` checkout — safe to re-run. |
| `fleet deploy <project> [<template>]` | `--branch <ref> [--label=<name>] [--fresh] [--force] [--no-auth] [--auth-password=<pw>] [--count=<n> \| -n <n>] [--skip-disk-check]` | Full deploy pipeline. `template`/`--branch` fall back to the project's `default_template`/`default_branch` when omitted. Running instance is named `<project>--<label>` (`label` defaults to the slugified branch; an explicit `--label` is normalised the same way — lowercased, non-alphanumeric runs collapsed to `-`, e.g. `--label=ABC-1234` → `abc-1234` — rather than rejected, see `docs/configuration.md`). Refuses a dirty/unpushed worktree update without `--force`. Per-instance basic auth is ON by default (`fleet`/`fleet`); `--no-auth` disables it, `--auth-password` sets a non-default password. `--count`/`-n` (default `1`) deploys that many independently-labelled instances in one call — see "Bulk deploy" below. |
| `fleet destroy [<instance-id> ...] \| --all \| --project=<p> \| --state=<s>` | `[--yes]` | Tears down containers, removes the instance dir + lock file. Accepts one explicit id (legacy single-instance form, no prompt), several explicit ids, or a selector (`--all`, `--project=<name>`, `--state=running\|deployed`) — never mixed with explicit ids. See "Bulk actions" below for confirmation/exit-code behavior. |
| `fleet start [<instance-id> ...] \| --all \| --project=<p> \| --state=<s>` | — | `ddev start` on one or more existing, stopped instances. Same targeting rules as `destroy`. |
| `fleet stop [<instance-id> ...] \| --all \| --project=<p> \| --state=<s>` | — | `ddev stop` — frees RAM, keeps disk. Same targeting rules as `destroy`. |
| `fleet list` | — | Prints a table: instance id, project, branch, state, RAM (MiB), URL. |
| `fleet ssh-key` | — | Prints the fleet deploy (read-only) public key, for adding to each forge. |
| `fleet assets push <project> <src> <dest-rel>` | — | Copies a local file into `assets/<project>/<dest-rel>`. |
| `fleet secret set <project> <key> <value>` | — | Writes `KEY=VALUE` into `secrets/<project>.env` (mode `0600`, upserts). Values become available at deploy time as `[[key-with-dashes]]` tokens. |
| `fleet snapshot <instance-id>` | `[--dest-rel=<path>]` | `ddev export-db --gzip=false` into the project's shared asset tree. Default dest: `dumps/default-<instance-id>.sql`. Refuses to write to `dumps/default.sql` (the project's shared, hard-linked default dump) under any `--dest-rel`. |
| `fleet refresh-claude-token` | `[--restart]` | Interactively mints a new Claude Code OAuth token (`claude setup-token`), writes it to `.secrets`, and rewrites every deployed instance's `config.fleet.yaml`. Does **not** restart running instances by default (prints the `ddev restart` command for each); `--restart` restarts them immediately. |
| `fleet set-claude-token <token>` | `[--restart]` | Same propagation as `refresh-claude-token`, but for a token you already have (validated against the `sk-ant-oat01-…` shape) instead of running `claude setup-token`. |
| `fleet set-admin-password <password>` | — | Sets the dashboard `basic_auth` password to an explicit value: hashes it (`caddy hash-password`), atomically rewrites `/etc/caddy/fleet/admin-auth.conf`, validates, reloads Caddy. No Ansible run. |
| `fleet rotate-admin-password` | — | Generates a strong random dashboard password, applies it the same way, and prints it exactly once. |
| `fleet refresh-config` | — | If `$FLEET_HOME/config` is a git checkout (`FLEET_CONFIG_REPO` mode), runs `git fetch --quiet && git pull --ff-only`. Otherwise prints a no-op message (local-file mode — edit `fleet.yml` in place). |
| `fleet refresh-instance-config <instance-id>` | `[--restart]` | Regenerates just that instance's `.ddev/config.fleet.yaml` (including the Claude onboarding hook) without a full deploy. The rewrite always happens; `--restart` additionally restarts the instance (omit it and the command prints the `ddev restart` command to run yourself). |
| `fleet refresh-ports` | — | Reconciles Caddy's fleet-owned port-exposure snippets (`/etc/caddy/fleet/ports/*.conf`) to `fleet.yml`'s current `fleet.ports`/per-project `ports:` state — no Ansible re-run, no redeploy. Also runs `sudo /usr/local/sbin/fleet-ufw-sync` when that helper exists (installed by the network-hardening role) — silently skipped otherwise, not an error. |
| `fleet shell [<instance-id>]` | `[-l \| --list]` | Drops into an interactive shell in an instance's directory (or the fleet home if no id given). `--list`/`-l` prints the known instance ids instead of prompting. |
| `fleet ddev [<instance-id>] [-- <ddev-args>...]` | — | Runs `ddev <ddev-args>` inside the given instance's directory (prompts for the instance if omitted). |
| `fleet tmux` | — | Attaches the persistent tmux session (general tab + one tab per instance), reconciling tabs to the current instance list on every attach. |
| `fleet tmux-sidebar` | `--window=<name> [--once]` | Internal: renders the tmux sidebar pane for one window; `--once` renders a single frame instead of looping (used by the pane's startup command). |
| `fleet tmux-reset [<window>]` | — | Rebuilds a tab's standard pane layout in place (general = 1 bash + sidebar; instance = 2 bash + sidebar) without killing the window. Defaults to the currently attached window if omitted. |
| `fleet reboot-notify` | `[--test]` | Checks Debian's reboot-required marker and sends an anti-spammed email notification (via `msmtp`, config at `$FLEET_HOME/reboot-notify.env` + `msmtprc`) if a reboot is pending and one hasn't been sent recently. `--test` forces a test email regardless of pending-reboot state, to verify the mail relay works. Exit `0` normally; with `--test`, exit `1` if the test send failed. |

## Exit codes

Single-target commands (`fleet deploy` with `--count 1`, a single-id
`fleet destroy <id>`/`start <id>`/`stop <id>`, and most other commands)
follow the ordinary convention: `0` on success, `1` on a `FleetError`
(message printed to stderr).

**Bulk operations** — multi-instance `start`/`stop`/`destroy` (selector or
several explicit ids) and `deploy --count N` for `N > 1` — use a
three-way exit code instead, based on the per-instance outcome:

| Exit code | Meaning |
|---|---|
| `0` | Every targeted instance succeeded (or the selector matched zero instances — a no-op is a success, not an error). |
| `1` | Every targeted instance failed. |
| `2` | Partial failure — at least one succeeded and at least one failed. |

Each bulk command prints one `<instance-id>: OK` or `<instance-id>: FAILED
— <error>` line per target, followed by a `N succeeded, M failed` summary
line.

## Bulk actions: `start` / `stop` / `destroy`

```bash
fleet start <instance-id> [<instance-id> ...]   # explicit list
fleet start --all
fleet start --project <project>
fleet start --state running|deployed

fleet stop    --all | --project <project> | --state running|deployed
fleet destroy --all | --project <project> | --state running|deployed [--yes]
```

Rules (`_resolve_bulk_targets` in `cli.py`):

- An explicit instance-id list and a selector (`--all`/`--project`/
  `--state`) are mutually exclusive — combining them is a `FleetError`.
- `--all` cannot be combined with `--project`/`--state`.
- At least one instance id or one selector flag is required.
- A selector that matches zero instances is a no-op: prints "no instances
  matched the given selector" and exits `0`.

**Confirmation for `destroy`:** a *single* explicit instance id (the
traditional `fleet destroy <id>` form) is destroyed immediately, no
prompt — preserved for backward compatibility. Anything else (a selector,
or more than one explicit id) always requires confirmation: it lists every
instance about to be destroyed, then either requires `--yes`, or — on an
interactive terminal — asks you to type the exact count
(`Type N to confirm destroying N instances:`). Non-interactive without
`--yes` raises a `FleetError` refusing to proceed.

```bash
fleet destroy --project demo --state deployed --yes
fleet destroy oak--old-one oak--old-two   # 2 explicit ids → still confirms
```

## Bulk deploy: `deploy --count`

```bash
fleet deploy <project> [<template>] --branch <ref> --count <n> [--skip-disk-check]
```

- `--count 1` (the default) behaves exactly like a single deploy: prints
  just the instance URL, ordinary `0`/`1` exit code.
- `--count 0` prints `nothing to deploy (--count=0)` and exits `0` without
  doing anything.
- `--count 2` or higher deploys `n` independently-labelled instances of
  the same project/template/branch, gated by a disk-headroom check before
  starting (skip it with `--skip-disk-check` if you're confident there's
  room). Prints the same per-instance `OK`/`FAILED` lines and
  `N succeeded, M failed` summary, and uses the same three-way exit code,
  as the bulk `start`/`stop`/`destroy` commands above.

## `fleet init` — the two registry modes

```bash
fleet init                                    # local-file mode (default)
FLEET_CONFIG_REPO=git@forge:you/fleet-config.git fleet init   # config-repo mode
```

- **Local-file mode (default):** copies `fleet.yml.dist` verbatim
  (comments included) into `$FLEET_HOME/config/fleet.yml`, then patches
  only the `fleet.domain` key to the value you supplied or were prompted
  for.
- **Config-repo mode (`FLEET_CONFIG_REPO` set):** clones that git URL into
  `$FLEET_HOME/config` instead — the recommended pattern for teams/
  multi-machine setups, since it makes the real registry + assets tree
  version-controlled and shareable. Never re-clones an existing checkout.

Full walkthrough: `docs/installation.md` §4; schema reference:
`docs/configuration.md`.

## Worked examples

```bash
# First-run setup
fleet init --domain fleet.example.com

# Deploy a project on its default template/branch
fleet deploy demo

# Deploy a specific branch under an explicit label
fleet deploy demo default --branch feature/new-thing --label preview

# Deploy 3 fresh instances of the same branch at once
fleet deploy demo default --branch main --count 3

# Free RAM on everything for one project, without losing disk state
fleet stop --project demo

# Tear down every instance in "deployed" (not running) state, no prompt
fleet destroy --state deployed --yes

# Rotate the dashboard password
fleet rotate-admin-password

# Apply a fleet.yml ports edit
fleet refresh-ports

# Regenerate one instance's config after editing fleet.yml, then restart it
fleet refresh-instance-config demo--main --restart

# Send a one-off test reboot-notification email
fleet reboot-notify --test
```

## See also

- `docs/configuration.md` — the `fleet.yml` schema these commands read.
- `docs/installation.md` — first-run checklist (`fleet init` through the
  first `fleet deploy`).
- `docs/operations.md` — ongoing procedures grouped by task (updates,
  rotation, bulk operations, port changes).
- `docs/networking.md` — the port-exposure mechanism behind
  `fleet refresh-ports`.
