---
Author: Claude Code
Reviewer: none
Last updated: 2026-09-27
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
| `fleet deploy <project> [<template>]` | `--branch <ref> [--label=<name>] [--force] [--no-auth] [--auth-password=<pw>] [--count=<n> \| -n <n>] [--skip-disk-check]` | Full deploy pipeline. `template`/`--branch` fall back to the project's `default_template`/`default_branch` when omitted. Running instance is named `<project>--<label>` (`label` defaults to the slugified branch; an explicit `--label` is normalised the same way — lowercased, non-alphanumeric runs collapsed to `-`, e.g. `--label=ABC-1234` → `abc-1234` — rather than rejected, see `docs/configuration.md`). **Never overwrites an existing instance**: if the resolved id is already in use, `-1`, `-2`, … is appended until a free one is found — see "`fleet deploy` never overwrites" below. Refuses a dirty/unpushed worktree update without `--force`. In basic auth mode (default), per-instance basic auth is ON by default (`fleet`/`fleet`); `--no-auth` disables it, `--auth-password` sets a non-default credential, used as BOTH username and password (e.g. `--auth-password=fern` → `fern`/`fern`). In **Authelia mode** (`auth_mode: authelia` in `host.yml`, see `docs/README-authelia.md`), `--auth-password` is **rejected** — per-instance credentials don't exist in this mode, authorization is by project group membership instead (`fleet.yml`'s `users:`). `--count`/`-n` (default `1`) deploys that many independently-labelled instances in one call — see "Bulk deploy" below. |
| `fleet redeploy [<instance-id> ...] \| --all \| --project=<p> \| --state=<s>` | `[--template=<name>] [--auth-password=<pw>] [--force] [--yes]` | Destroys an instance and rebuilds it under the **same id**, from the project/template/branch/label/auth recorded in its `.fleet/instance.yml` at the last deploy. Refuses if no `template` was recorded (an instance deployed before template recording existed) unless `--template` is given. See "`fleet redeploy`" below. |
| `fleet destroy [<instance-id> ...] \| --all \| --project=<p> \| --state=<s>` | `[--yes]` | Tears down containers, removes the instance dir + lock file. Accepts one explicit id (legacy single-instance form, no prompt), several explicit ids, or a selector (`--all`, `--project=<name>`, `--state=running\|deployed`) — never mixed with explicit ids. See "Bulk actions" below for confirmation/exit-code behavior. |
| `fleet start [<instance-id> ...] \| --all \| --project=<p> \| --state=<s>` | `[--sequential] [--timeout=<seconds>] [--retry-port-conflict]` | `ddev start` on one or more existing, stopped instances. Same targeting rules as `destroy`. `--sequential` runs the bulk path one instance at a time (`run_sequential`) instead of the default 2-at-a-time `run_concurrent` — used by `fleet-boot.service` (`fleet start --all --sequential --timeout 1800 --retry-port-conflict`) at boot to avoid CPU spikes / `ddev-ssh-agent` races; see `docs/operations.md`'s "Automatic instance startup after reboot". `--timeout` is a per-instance **hang guard**: a `ddev start` that doesn't finish within that many seconds is killed and recorded as a failed instance (continue-on-error) instead of stalling the batch forever. Default: no timeout. `--retry-port-conflict` self-heals a Docker port-allocation race: if `ddev start` FAST-FAILs with a port-already-allocated / container-networking error, it does one clean `ddev stop` + `ddev start` before giving up (a still-failing retry, or any other kind of failure, still just fails that instance — continue-on-error unchanged). Default: off. Both flags are also honoured by the single-explicit-id fast path. |
| `fleet stop [<instance-id> ...] \| --all \| --project=<p> \| --state=<s>` | `[--sequential] [--timeout=<seconds>]` | `ddev stop` — frees RAM, keeps disk. Same targeting rules as `destroy`; same `--sequential`/`--timeout` flags and semantics. |
| `fleet list` | — | Prints a table: instance id, project, branch, state, RAM (MiB), URL. `state` is `running`/`deployed`/`unknown` — see "Degraded state" below for when `unknown` appears and why. |
| `fleet ssh-key` | — | Prints the fleet deploy (read-only) public key, for adding to each forge. |
| `fleet assets push <project> <src> <dest-rel>` | — | Copies a local file into `assets/<project>/<dest-rel>`. |
| `fleet secret set <project> <key> <value>` | — | Writes `KEY=VALUE` into `secrets/<project>.env` (mode `0600`, upserts). Values become available at deploy time as `[[key-with-dashes]]` tokens. |
| `fleet snapshot <instance-id>` | `[--dest-rel=<path>]` | `ddev export-db --gzip=false` into the project's shared asset tree. Default dest: `dumps/default-<instance-id>.sql`. Refuses to write to `dumps/default.sql` (the project's shared, hard-linked default dump) under any `--dest-rel`. |
| `fleet refresh-claude-token` | `[--restart]` | Interactively mints a new Claude Code OAuth token (`claude setup-token`), writes it to `.secrets`, and rewrites every deployed instance's `config.fleet.yaml`. Does **not** restart running instances by default (prints the `ddev restart` command for each); `--restart` restarts them immediately. |
| `fleet set-claude-token <token>` | `[--restart]` | Same propagation as `refresh-claude-token`, but for a token you already have (validated against the `sk-ant-oat01-…` shape) instead of running `claude setup-token`. |
| `fleet set-admin-password <password>` | — | Mode-aware (reads `host.yml`'s `auth_mode` only, so it works even with a broken/missing `fleet.yml` in basic mode — a break-glass guarantee). Basic mode: hashes it (`caddy hash-password`), atomically rewrites `/etc/caddy/fleet/admin-auth.conf`, validates, reloads Caddy. No Ansible run. Authelia mode: hashes it into `admin.yml` and re-renders `users.yml` — no Caddy/Authelia restart, since Authelia's file backend hot-reloads it. |
| `fleet rotate-admin-password` | — | Generates a strong random dashboard password, applies it the same mode-aware way as `set-admin-password`, and prints it exactly once. |
| `fleet refresh-config` | — | If `$FLEET_HOME/config` is a git checkout (`FLEET_CONFIG_REPO` mode), runs `git fetch --quiet && git pull --ff-only`. Otherwise prints a no-op message (local-file mode — edit `fleet.yml` in place). |
| `fleet refresh-instance-config <instance-id>` | `[--restart]` | Regenerates just that instance's `.ddev/config.fleet.yaml` (including the Claude onboarding hook) without a full deploy. The rewrite always happens; `--restart` additionally restarts the instance (omit it and the command prints the `ddev restart` command to run yourself). |
| `fleet refresh-ports` | — | Reconciles Caddy's fleet-owned port-exposure snippets (`/etc/caddy/fleet/ports/*.conf`) to `fleet.yml`'s current `fleet.ports`/per-project `ports:` state — no Ansible re-run, no redeploy. Also runs `sudo /usr/local/sbin/fleet-ufw-sync` when that helper exists (installed by the network-hardening role) — silently skipped otherwise, not an error. |
| `fleet refresh-auth` | — | Re-applies the current auth configuration to every deployed instance — the "apply my `fleet.yml`/`host.yml` auth edits now" command, and the mechanism a server uses when switching `auth_mode`. Basic mode: re-renders every instance's `basic_auth` snippet from each instance's recorded `auth-enabled`/`auth-password` (the old `fleet.auth_bypass_cidrs` whitelist is deprecated and no longer applied here — see `docs/README-authelia.md`). Authelia mode: re-renders `users.yml` from `fleet.yml`'s per-project `users:` plus the admin account, and every instance's `forward_auth` snippet. Either way: one `caddy validate` + `caddy reload` for the whole fleet. No redeploy, no Ansible run. |
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

**Bulk operations** — multi-instance `start`/`stop`/`destroy`/`redeploy`
(selector or several explicit ids) and `deploy --count N` for `N > 1` — use
a three-way exit code instead, based on the per-instance outcome:

| Exit code | Meaning |
|---|---|
| `0` | Every targeted instance succeeded (or the selector matched zero instances — a no-op is a success, not an error). |
| `1` | Every targeted instance failed. |
| `2` | Partial failure — at least one succeeded and at least one failed. |

Each bulk command prints one `<instance-id>: OK` or `<instance-id>: FAILED
— <error>` line per target, followed by a `N succeeded, M failed` summary
line.

## `fleet list` degraded state

`fleet list` is a read-only status view, so its `ddev list --json-output`,
`docker stats --no-stream`, and per-instance `git rev-parse` calls all run
under bounded timeouts (`core/ddev.py:LIST_TIMEOUT` = 30s,
`core/ddev.py:STATS_TIMEOUT` = 20s, `core/instances.py:GIT_READ_TIMEOUT` =
10s) — a stalled `docker`/`ddev`/`git` process degrades the table instead of
hanging the whole command forever. (Deploy/destroy/start/stop/import
operations are unaffected — those keep waiting indefinitely, by design.)

On a timeout (or any other failure) of `ddev list`, the table still renders
from on-disk instances (`.fleet/instance.yml`), but the `state` column
reports `unknown` rather than `deployed` for every instance not confirmed
running — `deployed` is a claim that `ddev list` actually observed the
instance as not-running, which isn't true if the call never completed. One
warning is printed to stderr naming what was unavailable, e.g.:

```
warning: 'ddev list' timed out after 30s — live state unavailable, showing on-disk instances
warning: 'docker stats' timed out after 20s — RAM column unavailable
```

A `docker stats` timeout/failure only blanks the `RAM(MiB)` column (`-`);
it never affects `state`. A per-instance `git rev-parse` timeout/failure
falls back quietly to the branch recorded at deploy time — no warning, same
as its other failure modes (bad checkout, missing `git`, non-zero exit).

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
- `fleet start`/`fleet stop` accept `--sequential`, which routes the bulk
  path through `run_sequential` (one instance at a time, in the same order
  `fleet list` shows) instead of the default 2-at-a-time `run_concurrent`.
  Manual usage without the flag is unchanged; `fleet-boot.service` (boot-time
  auto-start, see `docs/operations.md`) always passes it.
- `fleet start`/`fleet stop` also accept `--timeout=<seconds>` — a
  per-instance **hang guard**, not a slowness limit. It's bound onto the
  per-instance op via `functools.partial` (so `run_sequential`/
  `run_concurrent`'s generic `op(paths, registry, instance_id, runner=...)`
  calling convention is untouched) and forwarded down through
  `core/instances.py` → `core/ddev.py` → `core/runner.py:run_streamed`,
  which kills the child and raises `subprocess.TimeoutExpired` if it hasn't
  exited within the deadline; `core/ddev.py` converts that into a
  `FleetError` (`"ddev start timed out after <n>s for <instance-id>"`),
  which `run_sequential`'s continue-on-error then records as a normal failed
  `BulkResult` — the batch keeps going. Default: no timeout (today's
  behaviour). `fleet-boot.service` passes `--timeout 1800` (30 minutes).
- `fleet start` (only — it's not meaningful for `stop`) also accepts
  `--retry-port-conflict`, a **separate** opt-in self-heal for a Docker
  port-allocation race distinct from the `--timeout` hang guard above: some
  instances' `ddev start` FAST-FAILs (in well under a second, so `--timeout`
  never sees it) because a host port from a just-stopped/starting sibling
  container hasn't been released by the kernel yet (`core/ddev.py:is_port_
  conflict` matches `"port is already allocated"` / `"failed to set up
  container networking"` case-insensitively in the captured output). With
  the flag, `core/instances.py:start` does exactly the proven manual fix —
  one clean `ddev stop` + `ddev start` — before giving up; any other kind of
  failure, or a second consecutive port conflict, still just fails that
  instance (continue-on-error unchanged). It's bound onto the op the same
  way as `--timeout` (accumulated into the same `functools.partial`, so both
  can be given together). Default: off, no behaviour change.
  `fleet-boot.service` always passes it.

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

`fleet redeploy` accepts the exact same targeting forms and mirrors
`destroy`'s confirmation rule — see "`fleet redeploy`" below for its own
options and examples.

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

## `fleet deploy` never overwrites

A plain `fleet deploy` always produces a **new** instance — it never treats
an existing instance directory as something to update in place. If the
resolved instance id (`<project>--<label>`) is already taken, the label
gets `-1`, `-2`, … appended until a free one is found. This applies equally
to a label derived from `--branch` and to an explicit `--label`:

```bash
fleet deploy demo default --branch main --label preview   # → demo--preview
fleet deploy demo default --branch main --label preview   # → demo--preview-1 (preview is taken)
fleet deploy demo default --branch main --label preview   # → demo--preview-2 (preview and preview-1 are taken)
```

The deploy log for the second run records the substitution:

```
label 'preview' already in use — allocated 'preview-1' instead (deploy never overwrites an existing instance)
```

`fleet deploy --count N` already suffixed every label it allocated (`-1`,
`-2`, …, never the bare base) — that behavior is unchanged. What's new here
is that a **single** deploy (`--count 1`, the default) no longer reuses an
existing id either.

To rebuild an existing instance **in place**, use `fleet redeploy` instead
(below) — that is now the only way to do it; deploy itself will not.

## `fleet redeploy`

```bash
fleet redeploy <instance-id>... [--all | --project P | --state S]
               [--template T] [--auth-password P] [--force] [--yes]
```

Destroys the named instance(s) and rebuilds each one under its **same
id**, recovering the project/template/branch/label/auth it was originally
deployed with from `<instance-dir>/.fleet/instance.yml` — see
`docs/configuration.md` for that file's full field reference. The registry
is re-read at rebuild time, so a redeploy picks up any edits made since the
original deploy to the resolved template's `post_deploy`/`tty1`/`tty2` —
"same parameters" means the same project/template/branch/label *identity*,
not a frozen copy of the recipe.

| Option | Meaning |
|---|---|
| `--template <name>` | Use this template instead of the recorded one. **Required** if the instance has no recorded `template:` (it predates template recording) — the command refuses with an actionable error naming `--template` and listing the project's available templates. Applied to every targeted instance when redeploying more than one. |
| `--auth-password <pw>` | Override the recorded basic-auth password. Omit it to reproduce the recorded password exactly (or today's default, `fleet`, if none was recorded). The recorded `auth-enabled` flag itself is never flipped by this flag. **Rejected in Authelia mode** — same as `deploy --auth-password`, see above. |
| `--force` | Same meaning as `deploy --force` (passed through to the rebuild). |
| `--yes` | Skip the interactive confirmation prompt for a selector or multiple ids (see below). |

**Targeting** uses the same `_resolve_bulk_targets` rules as
`destroy`/`start`/`stop` — one or more explicit ids, or a selector
(`--all`/`--project=<p>`/`--state=running\|deployed`), never mixed.

**Confirmation mirrors `destroy` exactly**, because a redeploy destroys
before it rebuilds: a single *explicit* instance id runs immediately, no
prompt. Anything else — a selector, or more than one explicit id — always
requires confirmation: it lists every instance about to be redeployed,
then either requires `--yes`, or, on an interactive terminal, asks you to
type the exact count (`Type N to confirm redeploying N instances:`).
Non-interactive without `--yes` raises a `FleetError` refusing to proceed.

**Bulk redeploy runs sequentially**, never concurrently — like
`multi_deploy()`, since a redeploy is a full destroy + clone + DB import,
and running several of those at once on one host is how you exhaust disk
mid-batch.

```bash
# Rebuild one instance in place, same project/template/branch/label/auth
fleet redeploy demo--preview

# The instance predates template recording — must name one explicitly
fleet redeploy demo--preview --template default

# Rebuild every deployed instance of a project, unattended
fleet redeploy --project demo --state deployed --yes
```

**Web UI**: a per-row **Redeploy** button (with a confirm prompt) and a
**Redeploy selected** bulk action are available alongside the existing
instance actions; both open the live-log job panel, the same as a deploy.

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

Full walkthrough: `docs/installation.md` §6; schema reference:
`docs/configuration.md`.

## Worked examples

```bash
# First-run setup
fleet init --domain fleet.example.com

# Deploy a project on its default template/branch
fleet deploy demo

# Deploy a specific branch under an explicit label
fleet deploy demo default --branch feature/new-thing --label preview

# Deploy 3 new instances of the same branch at once
fleet deploy demo default --branch main --count 3

# Rebuild an instance in place, same project/template/branch/label/auth
fleet redeploy demo--preview

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


## Per-instance basic auth, and Authelia mode for networks that block it

Every instance is protected by HTTP basic auth by default (basic mode —
the server-wide default when `host.yml` sets no `auth_mode`). The
credential is **symmetric** — the one value you pass is both username and
password:

```bash
fleet deploy oak default --branch develop --auth-password=fern   # login: fern / fern
fleet deploy oak default --branch develop --no-auth              # no auth at all
```

Some corporate networks block HTTP basic auth outright, so visitors there
cannot reach the instance at all. A server can instead run in **Authelia
auth mode** (`auth_mode: authelia` in `host.yml`) — a cookie-based login
portal, with per-project users defined in `fleet.yml`'s `users:` key and
authorized per instance by Caddy. Full design, `users:` schema, and
switch-over instructions: `docs/README-authelia.md`. In this mode
`--auth-password`/`--no-auth` are irrelevant (rejected/ignored — see
above) since there is no per-instance credential, only project group
membership.

**Deprecated**: `fleet.auth_bypass_cidrs` used to let a per-server list of
networks skip the basic-auth prompt entirely. It is now accepted in
`fleet.yml` only for backward compatibility — a deprecation warning is
logged and the key is otherwise ignored in both auth modes; no Caddy
snippet reads it any more. See `docs/configuration.md` and
`docs/README-authelia.md` for the replacement.

Editing auth configuration (either mode) does not require redeploying
anything:

```bash
fleet refresh-auth      # re-render every instance's auth snippet (+ users.yml in Authelia mode), validate, reload Caddy once
```
