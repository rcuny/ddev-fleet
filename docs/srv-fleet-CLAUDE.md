---
Author: Claude Code
Reviewer: none
Last updated: 2026-09-28
Type: documentation
---

# ddev-fleet server context

This file is the server-side Claude Code context document. The canonical
copy lives in the product repo at `docs/srv-fleet-CLAUDE.md`;
`docs/operations.md`'s Claude-context-refresh section copies it to
`/srv/fleet/CLAUDE.md` so a `claude -p "..."` session run as the `fleet`
user on the server has grounded context without reading the whole doc set.
This is a compact, server-side reference — the full versions are
`docs/configuration.md` (registry schema) and `docs/cli.md` (CLI), both in
the product repo, not copied to the server.

## Per-host domain (`/srv/fleet/host.yml`)

Lets several fleet servers share ONE `fleet.yml` (via the config repo) while
each keeps its own domain. A small, open-schema mapping — `domain:
<string>` — rendered by Ansible's `caddy` role from `fleet_domain`, owned
by the fleet user, mode `0644`. Deliberately a SIBLING of `config/`, never
inside the shared config repo. Precedence: `host.yml`'s `domain` wins when
present (even over a different `fleet.domain` in `fleet.yml`); falls back
to `fleet.yml`'s `fleet.domain` otherwise; if neither is set, `Registry.load`
fails at load time naming both locations. `fleet.domain` in `fleet.yml`
below is therefore optional once every host has its own `host.yml`. Every
call site loads via `fleet.core.instances.load_registry(paths)`, which
always passes `host.yml` through — never call `Registry.load()` directly.
Full detail: `docs/configuration.md`.

`host.yml` also carries `auth_mode: basic | authelia` (absent means
`basic`), letting one shared `fleet.yml` run different auth modes on
different hosts — see "Auth modes" below and `docs/README-authelia.md`.

## Registry (`/srv/fleet/config/fleet.yml`)

```yaml
fleet:
  domain: <string>                  # required, unless /srv/fleet/host.yml sets it (see above)
  git_bot_name: <string>            # OPTIONAL — default commit identity name  (default "ddev-fleet bot")
  git_bot_email: <string>           # OPTIONAL — default commit identity email (default bot@<domain>)
  # git_bot: false                  # OPTIONAL — disable git identity injection fleet-wide
  ports:                            # OPTIONAL — fleet-wide named-port catalogue
    <port-name>:
      public: <int>                 # Caddy's externally-reachable port (1-65535, not 22/80/443/8765)
      router: <int>                 # loopback ddev-router port proxied to (not 8080/8443); shared across subscribers

projects:
  <project-key>:
    git: <ssh-git-url>
    default_template: <string>        # OPTIONAL — template used when `fleet deploy` omits one
    default_branch: <string>          # OPTIONAL — branch used when `fleet deploy` omits --branch
    additional_hostnames: [<string>, ...]  # OPTIONAL — Domain Access alias hostnames (bare DNS labels)
    typesense: true                   # OPTIONAL — legacy opt-in, expose Typesense at *.<domain>:9108 (see ports: below)
    ports: [<port-name>, ...]         # OPTIONAL — general port-exposure mechanism; names must exist in fleet.ports
    git_bot:                          # OPTIONAL — per-project commit identity override:
      name: <string>                  #   {name, email} overrides for this project only
      email: <string>                 #   (omit either to inherit the fleet default)
    # git_bot: false                  #   ...or `false` to inject NO git identity (project's own
    #                                 #   `git config` / config.claude-code.local.yaml hook wins)
    issue_id_regexp: <string>         # OPTIONAL — derives [[issue-id]]/FLEET_ISSUE_ID (see below)
    users:                             # OPTIONAL — only meaningful in `authelia` auth mode
      - name: <string>                 #   ^[a-z0-9._-]+$, "admins" reserved
        password: <string>             #   plaintext here; hashed (argon2id) only when rendering users.yml
    templates:
      <template-name>:
        post_deploy: [<string>, ...]  # OPTIONAL — commands run after deploy (list == shorthand for `exec:`), OR:
        # post_deploy:
        #   exec: [<string>, ...]     #   host-side, strict — a failure aborts the deploy
        #   tty1: [<string>, ...]     #   typed ONCE by the deploy into the window's MIDDLE bash pane
        #   tty2: [<string>, ...]     #   typed ONCE by the deploy into the window's RIGHT bash pane
        # (template-level tty1/tty2 are DEPRECATED: still accepted, with a warning)
```

**`[[issue-id]]` and `tty1`/`tty2` (interactive tmux commands).** A
project's `issue_id_regexp` is matched against the deploying instance's
label first, then its branch (case-insensitive; result always uppercased —
labels are lowercase DNS labels, issue keys are conventionally uppercase);
group 1 if the pattern has a capture group, else the whole match; a match
outside `[A-Za-z0-9._/-]` is rejected. No match (or no `issue_id_regexp`) ⇒
`[[issue-id]]` is simply absent. The resolved value is also exported as
`FLEET_ISSUE_ID`. A template's `tty1`/`tty2` commands are `[[token]]`-
substituted with the same context as `post_deploy.exec`/assets, then typed
via `tmux send-keys` into the instance's window — middle pane for `tty1`,
right for `tty2` (sidebar is the fixed-width left pane) — **by the deploy
only** (`fleet deploy` and `fleet redeploy`, from the CLI and the web UI
alike, from the template as resolved at deploy time). Nothing else types
them: after a reboot `fleet-tmux.service` recreates the session with **plain
shells**, and `fleet tmux`, a recreated/closed window and `^b R` also give
plain shells. Substitution is **strict** for `post_deploy.exec` (an
unresolved token raises `DeployError`, aborting the deploy) but **lenient**
for `tty1`/`tty2` (that one command is dropped with a `WARNING: skipped ...`
log line, pane left as plain bash — never fails a deploy). The resolved
`template:` is recorded in the instance's `.fleet/instance.yml` (mode `0600` —
the file also records `auth-enabled`/`auth-password`) so `fleet redeploy` can
rebuild it; instances deployed before this field existed need an explicit
`--template`. If no `fleet` session exists at deploy time the CLI creates it,
while a web-UI deploy only logs `fleet-tmux.service not running: tty commands
not typed` (start it with `sudo systemctl start fleet-tmux` and redeploy).
Full reference:
`docs/configuration.md`.

**Named ports (`fleet.ports` / project `ports:`).** Each `fleet.ports`
entry is one externally-exposable named port: `public` is what Caddy
terminates TLS on and listens for at `*.<domain>:<public>`; `router` is
the loopback `ddev-router` port Caddy proxies to (shared across every
subscribing instance — Host-header routing on the router's side picks the
right one). Validated: both ints 1-65535, neither one of the reserved
`22`/`80`/`443`/`8765`, `router` not `8080`/`8443`, `public != router`
within an entry, and no `public`/`router` value reused across entries.
`Registry.port_profile("typesense")` falls back to a built-in
`public=9108, router=8108` default when `fleet.ports` has no explicit
`typesense` entry, so a `typesense: true`-only registry needs zero edits.
Apply a `ports:` edit with `fleet refresh-ports` (see below) — no Ansible
run, no redeploy. Full reference: `docs/configuration.md`; runbook for
adding a new port: `docs/networking.md`.

**Domain Access alias hostnames (`additional_hostnames`).** Each entry
must be a bare DNS label (lowercase, no dots — rejected at registry load
otherwise) and resolves per instance to the FLATTENED FQDN
`<h>-<instance-id>.<domain>` (never nested under the instance id —
`core/instances.py:alias_fqdns()`), e.g.
`news-oak--translations-test.fleet.example.com`. Raises `DeployError` if
that composed label exceeds 63 characters. Alias hosts are covered by the
SAME per-instance basic-auth matcher as the instance's own FQDN
(`core/caddyauth.py`) and each gets its own on-demand Let's Encrypt
certificate (mind Let's Encrypt's ~50 new-certs/registered-domain/week rate
limit with many aliases — a wildcard DNS-01 cert is the future fix, not yet
implemented). Every instance also gets `FLEET_INSTANCE_HOST=<instance-id>.
<domain>` injected into `web_environment`, so a project's own Domain
Access config can build these same alias patterns in PHP:
`"<h>-" . getenv('FLEET_INSTANCE_HOST')`. Full reference:
`docs/networking.md` §7, `docs/configuration.md`.

**Auth modes (`host.yml`'s `auth_mode`, project `users:`).** Basic mode
(default): per-instance HTTP basic auth, credential symmetric
(`--auth-password`), on by default. Authelia mode: a cookie-based login
portal (systemd `authelia.service`, `127.0.0.1:9091`, STATIC config —
never restarted for a routine edit); Caddy authorizes each instance via
`forward_auth` + `Remote-Groups` against the project name or `admins`.
Each project's `users:` becomes its Authelia group; `--auth-password` is
rejected in this mode. Apply a `users:`/`auth_mode` edit with
`fleet refresh-auth` (re-renders `users.yml` — hot-reloaded, no restart —
and every instance's snippet). The old `fleet.auth_bypass_cidrs` IP
whitelist is deprecated: accepted with a warning, otherwise ignored in
both modes. Full reference: `docs/README-authelia.md`,
`docs/configuration.md`.

**git identity injection.** The fleet injects `GIT_AUTHOR_*`/`GIT_COMMITTER_*`
into each instance's `config.fleet.yaml` `web_environment` so commits made
inside a container are attributed to a known identity. **Those env vars override
`git config user.*`** — so a project's own `git config` (e.g. a
`config.claude-code.local.yaml` post-start hook) can only take effect if the
project sets `git_bot: false` (opt-out). To attribute commits to a real person
instead, set `git_bot: {name, email}` on the project.

The registry is declarative and read-only at runtime — `fleet.yml` lives in
`/srv/fleet/config` (a git checkout kept in sync with `fleet refresh-config`,
see below) and is edited by hand, never mutated by the `fleet` CLI/daemon.
There is no per-instance `branch` field and no `instances:` block: a
template is a reusable named `post_deploy` (`exec`/`tty1`/`tty2`) recipe, and both
`branch` and the running instance's `label` are resolved per-deploy (`fleet
deploy` args), never stored in the registry. Assets live alongside it at
`/srv/fleet/config/assets`.

Running instance id = `<project-key>--<label>` (double-dash), where `label`
defaults to the slugified branch. This is the DDEV project name, the Docker
Compose project prefix, and the hostname label under the fleet wildcard
domain. A manual edit that breaks YAML syntax or the schema (project/
template/port name pattern `[a-z0-9]([a-z0-9-]*[a-z0-9])?`, no `--`, and no
`branch` key inside a template) will make every `fleet` command fail at
registry load with an actionable message naming the bad key.

## CLI reference

| Command | Arguments | Behavior |
|---|---|---|
| `fleet init` | `[--domain=...] [--skip-claude]` | Interactive: fleet domain, registry creation (local-file mode by default, or clones `FLEET_CONFIG_REPO` if set), `claude setup-token`, writes `.secrets` |
| `fleet deploy <project> [<template>] --branch <ref>` | `[--label=<name>] [--force] [--no-auth] [--auth-password=<pw>] [--count=<n>] [--skip-disk-check]` | Full deploy pipeline; running instance is named `<project>--<label>` (label defaults to the slugified branch); `template`/`--branch` fall back to the project's `default_template`/`default_branch` when omitted; refuses a dirty/unpushed worktree update without `--force`. **Never reuses an existing instance id** — if the resolved id is taken, `-1`/`-2`/… is appended until one is free. Basic mode (default): per-instance basic auth is ON by default (`fleet`/`fleet`); `--no-auth` disables it, `--auth-password` sets a non-default credential, used as BOTH username and password (e.g. `--auth-password=fern` → `fern`/`fern`). Authelia mode: `--auth-password` is **rejected** (users are managed in `fleet.yml`'s `users:` instead — see "Auth modes" above). `--count`/`-n` (default 1) bulk-deploys N labelled instances at once, gated by a disk-headroom check (`--skip-disk-check` to bypass); prints per-instance OK/FAILED + summary and a 0/1/2 exit code for N>1, same as the bulk commands below |
| `fleet redeploy [<id>...] \| --all \| --project=<p> \| --state=<s>` | `[--template=<name>] [--auth-password=<pw>] [--force] [--yes]` | Destroys and rebuilds an instance **in place, same id**, from the project/template/branch/label/auth recorded in `.fleet/instance.yml`. Refuses if no `template` was recorded, unless `--template` is given. `--auth-password` rejected in Authelia mode, same as `deploy`. Confirmation and bulk targeting match `destroy` exactly; bulk redeploy runs sequentially. This is now the only way to rebuild an instance in place — `deploy` never does |
| `fleet destroy [<id>...] \| --all \| --project=<p> \| --state=<s>` | `[--yes]` | Tears down containers, removes instance dir + lock file. A single explicit id destroys immediately (no prompt, backward-compat); a selector or multiple ids always confirms (type the count, or pass `--yes`) |
| `fleet start [<id>...] \| --all \| --project=<p> \| --state=<s>` | — | `ddev start` on one or more existing, stopped instances |
| `fleet stop [<id>...] \| --all \| --project=<p> \| --state=<s>` | — | `ddev stop` — frees RAM, keeps disk |
| `fleet list` | — | Table: id, project, branch, state, RAM, URL |
| `fleet ssh-key` | — | Prints the fleet deploy public key |
| `fleet assets push <project> <src> <dest-rel>` | — | Copies a local file into `assets/<project>/<dest-rel>` |
| `fleet secret set <project> <key> <value>` | — | Writes `KEY=VALUE` into `secrets/<project>.env` (0600), available at deploy as `[[key-with-dashes]]` |
| `fleet webhook secret <project>` | `[--rotate] [--source=jira\|bitbucket]` | Creates/rotates the project's Jira webhook secret (`webhooks/secrets.env`, 0600), printed once with the hook URL; see `docs/README-webhooks.md` |
| `fleet webhook bitbucket-token <project>` | (token via stdin / hidden prompt) | Stores the Bitbucket `pipeline:write` token for `bitbucket_hooks` rules |
| `fleet webhook log` | `[--project=<p>] [-n=20] [--source=jira\|bitbucket]` | Last N webhook deliveries from `logs/webhooks/jira.jsonl` (or `bitbucket.jsonl`) |
| `fleet snapshot <instance-id>` | `[--dest-rel=dumps/default-<instance-id>.sql]` | `ddev export-db --gzip=false` into the project's asset tree; refuses to write `dumps/default.sql` |
| `fleet refresh-claude-token` | `[--restart]` | Rotates `CLAUDE_CODE_OAUTH_TOKEN` fleet-wide, rewrites every instance's `config.fleet.yaml`; restarts running instances only if `--restart` |
| `fleet set-claude-token <token>` | `[--restart]` | Same propagation as `refresh-claude-token` for a token you already have, instead of running `claude setup-token` |
| `fleet set-admin-password <password>` | — | Mode-aware (reads only `host.yml`'s `auth_mode` — a break-glass guarantee that still works with a broken/missing `fleet.yml` in basic mode). Basic mode: hashes it, atomically rewrites `/etc/caddy/fleet/admin-auth.conf`, validates, reloads Caddy — no Ansible run. Authelia mode: writes `admin.yml` and re-renders `users.yml` — no restart needed (hot-reloaded) |
| `fleet rotate-admin-password` | — | Generates a strong random dashboard password, applies it the same mode-aware way, and prints it once |
| `fleet refresh-config` | — | Git-aware pull of `/srv/fleet/config` (fetch + `--ff-only` pull); no-op message if `config/` isn't a git checkout |
| `fleet refresh-instance-config <instance-id>` | `[--restart]` | Regenerates just that instance's `.ddev/config.fleet.yaml` (incl. the Claude onboarding hook) without a full deploy; `--restart` also restarts it. Does NOT rewrite `settings.local.php` — see `docs/runbook-server-rollout.md` for the domain-change workaround |
| `fleet refresh-ports` | — | Reconciles Caddy port-exposure snippets (`/etc/caddy/fleet/ports/*.conf`) to `fleet.yml`'s `fleet.ports`/project `ports:` state — the "apply my port edits now" command; also runs `sudo /usr/local/sbin/fleet-ufw-sync` when the `network_hardening` role's helper is present (silent no-op otherwise) |
| `fleet refresh-auth` | — | Re-applies the current auth config to every deployed instance — the "apply my auth edits now" command, and how a server switches `auth_mode`. Basic mode: re-renders every instance's `basic_auth` snippet from its recorded `auth-enabled`/`auth-password` (`fleet.auth_bypass_cidrs` is deprecated and no longer applied). Authelia mode: re-renders `users.yml` from `fleet.yml`'s `users:` + the admin account, plus every instance's `forward_auth` snippet. Either way: ONE `caddy validate` + `caddy reload` |
| `fleet shell [<instance-id>]` | `[-l \| --list]` | Interactive shell in an instance's dir (or fleet home); `--list` prints known instance ids instead |
| `fleet ddev [<instance-id>] [-- args]` | — | Runs `ddev <args>` inside an instance's directory |
| `fleet tmux` | `[--ensure]` | Attach the persistent tmux session (general tab + a tab per instance, two bash panes each, with a vertical instance sidebar); reconciles tabs on attach; every window it creates is a **plain shell** (tty commands are typed by deploy only). `--ensure`: non-interactive — create the session if missing and reconcile, no attach (what `fleet-tmux.service` runs) |
| `fleet tmux-sidebar` | `--window=<name> [--once]` | Internal: renders one tmux window's sidebar pane |
| `fleet tmux-reset [<window>]` | — | Rebuild a tab's standard pane layout in place, without killing the window; bound to `^b R` inside the workspace |
| `fleet reboot-notify` | `[--test]` | Checks Debian's reboot-required marker, sends an anti-spammed email if pending; `--test` forces a test send regardless |

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

## Bulk operations & exit codes

`fleet start`/`fleet stop`/`fleet destroy` accept either one-or-more
explicit instance ids, or a selector (`--all` / `--project=<p>` /
`--state=running|deployed`) — never both. `--all` cannot combine with
`--project`/`--state`. A selector matching zero instances is a no-op
(exit `0`). `fleet deploy --count N` (N > 1) uses the same machinery for a
bulk deploy. Every bulk operation (including `deploy --count`) prints a
per-instance `OK`/`FAILED` line plus a `N succeeded, M failed` summary and
exits `0` (all succeeded), `1` (all failed), or `2` (partial failure).
Full detail + examples: `docs/cli.md`.

## Common workflows

- **Deploy an already-declared project on a new branch push:** `fleet deploy <project> <template> --branch <ref>` (add `--label <name>` to control the instance name; otherwise it's the slugified branch).
- **Re-deploy using the project's defaults:** `fleet deploy <project>` — uses `default_template`/`default_branch` from `fleet.yml` when the project declares them.
- **Deploy several instances at once:** `fleet deploy <project> <template> --branch <ref> --count <n>`.
- **Onboard a brand-new project:** edit `fleet.yml` to add `projects.<key>` (git URL, templates), run `fleet refresh-config` if `config/` is a shared git checkout, then `fleet deploy <key> <template> --branch <ref>`.
- **Free RAM without losing disk state:** `fleet stop <instance-id>` (or `--project`/`--all`/`--state` for many at once); bring back with `fleet start`.
- **Rebuild an instance from scratch, same parameters:** `fleet redeploy <instance-id>` — destroys and redeploys it under the same id, from what's recorded in its `.fleet/instance.yml`. Needs `--template <name>` if the instance predates template recording.
- **Fully tear down:** `fleet destroy <instance-id>` (or a selector + `--yes`) — irreversible, removes the instance directory.
- **Refresh a stale DB dump for a project:** `fleet snapshot <instance-id>` (exports the running instance's DB into its project's shared asset tree) then `fleet deploy <project> <template> --branch <ref> --label <other-label>` to propagate it to another instance.
- **Rotate the Claude Code token fleet-wide (e.g. before the ~1 year expiry):** `fleet refresh-claude-token` — safe to re-run; only running instances are restarted (with `--restart`).
- **Rotate the dashboard admin password:** `fleet rotate-admin-password` (generated) or `fleet set-admin-password <password>` (explicit) — never requires an Ansible run.
- **Pull the latest registry/assets after someone else edits `fleet.yml`:** `fleet refresh-config`.
- **Let a network that blocks basic auth outright reach an instance:**
  switch the server to Authelia mode (`fleet_auth_mode: authelia`, see
  `docs/README-authelia.md`) rather than the deprecated
  `fleet.auth_bypass_cidrs` whitelist, which is now ignored.
- **Add/edit a project user (Authelia mode):** edit that project's
  `users:` in `fleet.yml`, then `fleet refresh-auth` — no redeploy, no
  Ansible run, nobody is logged out.
- **Expose a new port for a project (e.g. Typesense, a Playwright report port):** add it to `fleet.ports` and the project's `ports:` list in `fleet.yml`, then `fleet refresh-ports` — no Ansible run, no redeploy needed.
- **Check for a pending host reboot and notify:** `fleet reboot-notify` (normally run on a timer); `--test` to verify the mail relay works.
- **Recovery when the daemon/web UI is down:** every command above works from the CLI directly against `fleet.core` — the daemon is not a dependency of the CLI.

## `fleet tmux` operator notes

- **Mouse is on.** Click a pane to focus it; scroll stays inside that pane
  (no more `^b [` copy-mode dance); drag with the mouse to select text —
  the selection is confined to a single pane and copies straight to the local
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
- **`post_deploy.tty1`/`tty2` template commands are typed by deploy only
  (FLE-6).** Not by `^b R` (`fleet tmux-reset` rebuilds a tab's pane layout in
  place), not by `fleet tmux`, not after a reboot: `fleet-tmux.service`
  recreates the `fleet` session at boot with a plain-shell window per
  instance, and `fleet tmux` just attaches and repairs layout. The only ways to
  get the commands typed are a `fleet deploy`/`fleet redeploy` (CLI or web UI).
- **`fleet-tmux.service` owns the tmux server.** It runs `fleet tmux --ensure`
  (create the session if missing, reconcile a plain window per instance, exit
  — no attach) and keeps the server in its own cgroup, so `systemctl restart
  fleet` never kills your panes. `systemctl status fleet-tmux` should say
  `active (exited)`; `sudo systemctl stop fleet-tmux` ends the session and all
  its panes.

## Reboot notifications

`fleet reboot-notify` (intended to run on a timer, e.g. cron/systemd
timer) checks Debian's `/var/run/reboot-required` marker and sends an
email via `msmtp` if a reboot is pending and no notification has gone out
recently (anti-spam cadence). Reads `MSMTP_TO`/`MSMTP_FROM` from
`$FLEET_HOME/reboot-notify.env` and the relay config from
`$FLEET_HOME/msmtprc`. `fleet reboot-notify --test` sends a one-off test
email immediately, ignoring the pending-reboot check, to verify the relay
is configured correctly — exits `1` if the test send fails.

## Claude Code skills (`/srv/fleet/.claude/skills/`)

Added by `2026-07-24-fleet-host-claude-design.md` (companion repo) — this
spec owns this section; a later doc regeneration pass should pull from here,
not re-derive it. An interactive `claude` session started at cwd
`/srv/fleet` (`fleet shell` with no instance id, or `cd /srv/fleet && claude`)
discovers four project-scoped skills:

| Skill | Trigger | What it does |
|---|---|---|
| `/fleet-status` | `/fleet-status`, "how's the fleet doing" | Read-only health summary: running/deployed counts, RAM/disk headroom, and a deploy-log-derived anomaly list. Warns (never blocks) below 15% disk-free. |
| `/fleet-triage <instance-id>` | `/fleet-triage <id>`, "why did `<id>` fail" | Reads that instance's full `deploy.log`, identifies the failing phase from its markers, cross-checks the registry, and suggests (never runs) a fix. |
| `/fleet-deploy-batch` | `/fleet-deploy-batch`, "deploy `<project>` on branches x, y, z" | Deploys one instance per distinct branch (looped `fleet deploy` calls — the permanent mechanism, no native equivalent exists for distinct branches) or N replicas of one branch (native `fleet deploy --count`, if present). |
| `/fleet-cleanup` | `/fleet-cleanup`, "what can we destroy" | Builds a candidate table (stopped >7 days, or an incomplete deploy) and destroys **only** after an explicit yes/no confirmation — a bare invocation never destroys anything. |

**Delivery**: `ansible/roles/claude_cli` copies the skills to
`/srv/fleet/.claude/skills/<name>/SKILL.md` and a permission allowlist to
`/srv/fleet/.claude/settings.json` — both project-scoped at `FLEET_HOME`,
**not** under `/home/fleet/.claude/` (which stays `$HOME`-scoped, holding
only `.claude.json`). Applied via the scoped `ansible/claude-onboarding.yml`
playbook (roles: `shell_profile`, `claude_cli`) — never the full `site.yml`.

**Permission tiers (`/srv/fleet/.claude/settings.json`)**: read-only/
reversible `fleet`/`ddev` commands are allow-tier (`fleet list`, `fleet
deploy *`, `fleet start/stop/snapshot`, read-only `ddev`/`git`/`grep`/`find`/
`df`/`free` commands). Credential and fleet-wide-restart commands
(`fleet secret set`, `fleet {set,refresh,rotate}-*-token`,
`fleet {set,rotate}-admin-password`, bulk selector forms `fleet start/stop
--*`) ask. `fleet destroy` (any single- or multi-id form) is deliberately
absent from allow and instead sits in ask, so the harness always prompts
under a skill's own confirmation; `fleet destroy --all *` specifically is a
categorical **deny**, which wins over any ask/allow match regardless of
specificity. `.secrets`/`secrets/**` are denied for both `Read`/`Edit` and
`cat`.

**Token-reuse verification** (run once after any provisioning change):

```bash
sudo -u fleet bash -ic 'claude -p "What is 17 plus 25?"'
```

Expect `42`. This is the only reliable auth check — it skips onboarding
entirely, unlike the interactive TUI, whose apparent "login screen" is very
likely onboarding state, not an auth failure.
