# ddev-fleet server context

This file is the server-side Claude Code context document (spec §10.2).
The canonical copy lives in the product repo at `docs/srv-fleet-CLAUDE.md`;
`docs/operations.md`'s Claude-context-refresh section copies it to
`/srv/fleet/CLAUDE.md` so a `claude -p "..."` session run as the `fleet`
user on the server has grounded context without reading the whole spec.

## Registry (`/srv/fleet/config/fleet.yml`)

```yaml
fleet:
  domain: <string>                  # wildcard DNS root, e.g. fleet.example.com
  git_bot_name: <string>            # OPTIONAL — default commit identity name  (default "ddev-fleet bot")
  git_bot_email: <string>           # OPTIONAL — default commit identity email (default bot@<domain>)
  # git_bot: false                  # OPTIONAL — disable git identity injection fleet-wide

projects:
  <project-key>:
    git: <ssh-git-url>
    default_template: <string>        # OPTIONAL — template used when `fleet deploy` omits one
    default_branch: <string>          # OPTIONAL — branch used when `fleet deploy` omits --branch
    additional_hostnames: [<string>, ...]  # OPTIONAL — extra FQDNs routed to the instance
    git_bot:                          # OPTIONAL — per-project commit identity override:
      name: <string>                  #   {name, email} overrides for this project only
      email: <string>                 #   (omit either to inherit the fleet default)
    # git_bot: false                  #   ...or `false` to inject NO git identity (project's own
    #                                 #   `git config` / config.claude-code.local.yaml hook wins)
    templates:
      <template-name>:
        post_deploy: [<string>, ...]  # OPTIONAL — commands run after deploy for this template
```

**git identity injection.** The fleet injects `GIT_AUTHOR_*`/`GIT_COMMITTER_*`
into each instance's `config.fleet.yaml` `web_environment` so commits made
inside a container are attributed to a known identity. **Those env vars override
`git config user.*`** — so a project's own `git config` (e.g. a
`config.claude-code.local.yaml` post-start hook) can only take effect if the
project sets `git_bot: false` (opt-out). To attribute commits to a real person
instead, set `git_bot: {name, email}` on the project (this is what `oak` does).

The registry is declarative and read-only at runtime — `fleet.yml` lives in
`/srv/fleet/config` (a git checkout kept in sync with `fleet refresh-config`,
see below) and is edited by hand, never mutated by the `fleet` CLI/daemon.
There is no per-instance `branch` field and no `instances:` block: a
template is a reusable named `post_deploy` recipe, and both `branch` and the
running instance's `label` are resolved per-deploy (`fleet deploy` args),
never stored in the registry. Assets live alongside it at
`/srv/fleet/config/assets`.

Running instance id = `<project-key>--<label>` (double-dash), where `label`
defaults to the slugified branch. This is the DDEV project name, the Docker
Compose project prefix, and the hostname label under the fleet wildcard
domain. A manual edit that breaks YAML syntax or the schema (project/
template name pattern `[a-z0-9]([a-z0-9-]*[a-z0-9])?`, no `--`, and no
`branch` key inside a template) will make every `fleet` command fail at
registry load with an actionable message naming the bad key.

## CLI reference

| Command | Arguments | Behavior |
|---|---|---|
| `fleet init` | `[--domain=...] [--skip-claude]` | Interactive: fleet domain, `claude setup-token`, writes `.secrets` |
| `fleet deploy <project> [<template>] --branch <ref>` | `[--label=<name>] [--fresh] [--force] [--no-auth] [--auth-password=<pw>]` | Full deploy pipeline; running instance is named `<project>--<label>` (label defaults to the slugified branch); `template`/`--branch` fall back to the project's `default_template`/`default_branch` when omitted; refuses a dirty/unpushed worktree update without `--force`. Per-instance basic auth is ON by default (`fleet`/`fleet`); `--no-auth` disables it, `--auth-password` sets a non-default password |
| `fleet destroy <instance-id>` | — | Tears down containers, removes instance dir + lock file |
| `fleet start <instance-id>` | — | `ddev start` on an existing, stopped instance |
| `fleet stop <instance-id>` | — | `ddev stop` — frees RAM, keeps disk |
| `fleet list` | — | Table: id, project, branch, state, URL, RAM |
| `fleet ssh-key` | — | Prints the fleet deploy public key |
| `fleet assets push <project> <src> <dest-rel>` | — | Copies a local file into `assets/<project>/<dest-rel>` |
| `fleet snapshot <instance-id>` | `[--dest-rel=dumps/default-<instance-id>.sql]` | `ddev export-db --gzip=false` into the project's asset tree |
| `fleet refresh-claude-token` | — | Rotates `CLAUDE_CODE_OAUTH_TOKEN` fleet-wide, rewrites every instance's `config.fleet.yaml`, restarts running instances |
| `fleet set-admin-password <password>` | — | Sets the dashboard `basic_auth` password to an explicit value: hashes it (`caddy hash-password`), atomically rewrites `/etc/caddy/fleet/admin-auth.conf`, validates, reloads Caddy — no Ansible run |
| `fleet rotate-admin-password` | — | Generates a strong random dashboard password, applies it the same way, and prints it once |
| `fleet refresh-config` | — | Git-aware pull of `/srv/fleet/config` (fetch + `--ff-only` pull) so the registry and assets checkout track their remote; a no-op message if `config/` isn't a git checkout |
| `fleet refresh-ports` | — | Reconciles Caddy port-exposure snippets (`/etc/caddy/fleet/ports/*.conf`) to `fleet.yml`'s `fleet.ports`/project `ports:` state — the "apply my port edits now" command; also runs `sudo /usr/local/sbin/fleet-ufw-sync` when the `network_hardening` role's helper is present (silent no-op otherwise) |
| `fleet tmux` | — | Attach the persistent tmux session (general tab + a tab per instance, two bash panes each, with a vertical instance sidebar); reconciles tabs on attach; applies mouse/clipboard/status-bar settings on every attach |
| `fleet tmux-reset <window>` | — | Rebuild a tab's standard pane layout in place (general = 1 bash + sidebar; instance = 2 bash + sidebar), without killing the window; bound to `^b R` inside the workspace |

Projects and templates are declared by hand in `fleet.yml` — there is no
`fleet project add` and no auto-registration of unknown projects on deploy.
To onboard a new project, add a `projects.<key>` block (and its `templates`)
to the registry, then deploy.

`fleet snapshot` writes plain, uncompressed SQL (`--gzip=false`) — each
project's own `.ddev/commands/web/install-site-from-db` import script reads
`dumps/<SITE>.sql` straight into `drush sql:connect`, with no gunzip step.
The default dest, `dumps/default-<instance-id>.sql`, deliberately avoids
`dumps/default.sql`: that name is the project's **shared** dump, hard-linked
into every instance of the project (`core/assets.py:_link_shared_dir`), and
`fleet snapshot` refuses to write there (`FleetError`, even with an explicit
`--dest-rel`) so a snapshot can never corrupt it for every other instance.

## Common workflows

- **Deploy an already-declared project on a new branch push:** `fleet deploy <project> <template> --branch <ref>` (add `--label <name>` to control the instance name; otherwise it's the slugified branch).
- **Re-deploy using the project's defaults:** `fleet deploy <project>` — uses `default_template`/`default_branch` from `fleet.yml` when the project declares them.
- **Onboard a brand-new project:** edit `fleet.yml` to add `projects.<key>` (git URL, templates), run `fleet refresh-config` if `config/` is a shared git checkout, then `fleet deploy <key> <template> --branch <ref>`.
- **Free RAM without losing disk state:** `fleet stop <instance-id>`; bring it back with `fleet start <instance-id>`.
- **Fully tear down:** `fleet destroy <instance-id>` — irreversible, removes the instance directory.
- **Refresh a stale DB dump for a project:** `fleet snapshot <instance-id>` (exports the running instance's DB into its project's shared asset tree) then `fleet deploy <project> <template> --branch <ref> --label <other-label>` to propagate it to another instance.
- **Rotate the Claude Code token fleet-wide (e.g. before the ~1 year expiry):** `fleet refresh-claude-token` — safe to re-run; only running instances are restarted.
- **Rotate the dashboard admin password:** `fleet rotate-admin-password` (generated) or `fleet set-admin-password <password>` (explicit) — never requires an Ansible run.
- **Pull the latest registry/assets after someone else edits `fleet.yml`:** `fleet refresh-config`.
- **Expose a new port for a project (e.g. Typesense, a Playwright report port):** add it to `fleet.ports` and the project's `ports:` list in `fleet.yml`, then `fleet refresh-ports` — no Ansible run, no redeploy needed.
- **Recovery when the daemon/web UI is down:** every command above works from the CLI directly against `fleet.core` — the daemon is not a dependency of the CLI (spec §11).

## `fleet tmux` operator notes

- **Mouse is on.** Click a pane to focus it; scroll stays inside that pane
  (no more `^b [` copy-mode dance); drag with the mouse to select text —
  the selection is confined to a single pane and copies straight to the Mac
  clipboard.
- **One-time iTerm 2 setting** for that clipboard copy to work:
  *Preferences → General → Selection → "Applications in terminal may access
  clipboard"*.
- **Full-width selection across panes:** hold **⌥ Option while dragging**
  for iTerm 2's native selection instead of tmux's per-pane one — useful
  when you want to grab text that spans a pane boundary.
- **Bottom status bar:** click a tab to switch to it directly, no need to
  cycle with `^b n`/`^b p`.
- **`^b R`** resets the current tab's layout (`fleet tmux-reset <window>`
  under the hood) — rebuilds the standard bash+sidebar panes in place if one
  got closed or mangled, without losing the tab's position in the window
  list.
- Every tab uses the same layout: a fixed **30-column** left sidebar, and (on
  instance tabs) two **equal** bash panes. If a pane border drifts — or a tab
  looks lopsided — press `^b R` to re-even it (the reset re-asserts the sidebar
  width and makes the two bash panes equal again).
- The sidebar branch line and the web UI now show each instance's **actual
  currently checked-out git branch** (via `git rev-parse`), not the branch
  recorded at deploy time. The sidebar re-reads it about every 5 minutes; the
  web UI reads it fresh on every page load. If you `git checkout` a different
  branch inside an instance, the overview catches up within ~5 min (or press
  `^b R` to refresh that tab's sidebar immediately). `.fleet/instance.yml` still
  records the deploy-time branch and is not modified.
