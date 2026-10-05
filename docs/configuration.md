---
Author: Claude Code
Reviewer: none
Last updated: 2026-09-27
Type: documentation
---

# Configuring ddev-fleet: the `fleet.yml` registry

`fleet.yml` is the fleet's single source of truth for *what* it manages:
which projects exist, where their git remotes live, what deploy templates
(`post_deploy` recipes) they offer, and which extra network ports they
expose. It is declarative and read-only at runtime — the `fleet` CLI/daemon
never write to it. You author it by hand (or via provisioning tooling) and
edit it directly to onboard a project, change a default, or expose a port.

This document is the field-by-field reference. For the first-run checklist
that creates this file, see `docs/installation.md`'s `fleet init` section.
For the runbook on exposing a new port end-to-end, see `docs/networking.md`.

## Where it lives

By default: `/srv/fleet/config/fleet.yml` (`$FLEET_HOME/config/fleet.yml`,
`FLEET_HOME` defaulting to `/srv/fleet`). `fleet init` creates it — either
by copying `fleet.yml.dist` verbatim and patching only `fleet.domain`
(the default, "local file" mode), or by cloning a private config repo when
`FLEET_CONFIG_REPO` is set (see "Recommended for teams" below). Assets
(DB dumps, `.env` templates, local-settings templates) live alongside it at
`config/assets/<project>/`.

A syntax error or schema violation makes every `fleet` command fail at
registry load (`Registry.load`) with an actionable message naming the bad
key — nothing partially loads.

## Per-host domain (`host.yml`) — sharing one `fleet.yml` across servers

`fleet.domain` (below) is normally the only place the wildcard domain lives.
But when several fleet servers share **one** config repo (so `fleet.yml` is
literally the same file, checked out on each host), a single hardcoded
`fleet.domain` can't be right for all of them. `<FLEET_HOME>/host.yml`
(`/srv/fleet/host.yml` by default — a SIBLING of `config/`, never inside the
shared repo) solves this: a small, per-host YAML mapping,

```yaml
domain: fleet.this-host.example.com
```

rendered by the `caddy` Ansible role from its own `fleet_domain` variable
(`ansible/group_vars/all.yml`/`/etc/ddev-fleet/local-vars.yml`), owned by the
fleet user, mode `0644`. It is intentionally an open schema — unknown keys
are ignored, so more host-level settings can be added later without a
migration.

`host.yml` also carries `auth_mode: basic | authelia` — see "Auth modes
and `users:`" below — the same per-host rationale applies: one shared
`fleet.yml` can still run basic auth on one server and Authelia on
another.

**Precedence:** `host.yml`'s `domain` wins whenever the file exists and sets
it — even if `fleet.yml`'s own `fleet.domain` also has a (different) value;
that's the whole point of a domain that belongs to the host, not the shared
registry. Falls back to `fleet.yml`'s `fleet.domain` when `host.yml` is
absent or has no `domain` key. `fleet.domain` becomes fully **optional** in
`fleet.yml` once every host that reads it carries its own `host.yml` — if
neither source has a domain, `Registry.load` fails at load time naming both
locations checked. Every CLI/daemon call site loads the registry through
`fleet.core.instances.load_registry(paths)`, the one constructor that always
passes `paths.host_config` through — so this precedence can never be
forgotten at some call site.

## Top-level shape

```yaml
fleet:
  domain: <string>              # required, unless host.yml provides it (see above)
  git_bot_name: <string>        # optional
  git_bot_email: <string>       # optional
  git_bot: false                # optional
  ports:                        # optional
    <port-name>:
      public: <int>
      router: <int>

projects:
  <project-key>:
    git: <ssh-git-url>                     # required
    default_template: <string>             # optional
    default_branch: <string>               # optional
    additional_hostnames: [<string>, ...]  # optional
    typesense: true                        # optional, legacy back-compat
    ports: [<port-name>, ...]              # optional
    git_bot: {name: <string>, email: <string>} | false   # optional
    issue_id_regexp: <string>              # optional
    templates:
      <template-name>:
        drupal_env: <word>                 # optional
        post_deploy: [<string>, ...]       # optional
        tty1: [<string>, ...]              # optional
        tty2: [<string>, ...]              # optional
```

## The `fleet:` block

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `domain` | string | yes, unless `host.yml` sets it (see "Per-host domain" above) | — | The wildcard DNS root, e.g. `fleet.example.com`. Every instance is reachable at `<instance-id>.<domain>`; the dashboard/web UI at `<domain>` itself (behind Caddy's `fleet.<domain>` site — see `docs/networking.md`). |
| `git_bot_name` | string | no | `ddev-fleet bot` | Default commit-author/committer name injected as `GIT_AUTHOR_NAME`/`GIT_COMMITTER_NAME` into every instance's `web_environment`, unless a project overrides it. |
| `git_bot_email` | string | no | `bot@<domain>` | Default commit-author/committer email, same injection. |
| `git_bot` | `false` | no | (unset = enabled) | Set to `false` to disable git identity injection **fleet-wide** — every instance's own `git config` then decides commit identity. Per-project `git_bot: false` (below) overrides this for one project only. |
| `ports` | mapping | no | `{}` | The fleet-wide **named-port catalogue** (added by the port-exposure work). Each key is a port name (validated like a project/template key: `[a-z0-9]([a-z0-9-]*[a-z0-9])?`, no `--`); each value is a mapping with **exactly** the keys `public` and `router` (both required ints, 1–65535). See "The `ports:` catalogue" below for the full validation rules. |

## The `ports:` catalogue (`fleet.ports`)

Each entry declares one externally-exposable named port:

```yaml
fleet:
  ports:
    typesense:  { public: 9108, router: 8108 }
    playwright: { public: 9324, router: 8323 }
```

- `public` — the port Caddy listens on for `*.<domain>:<public>` and
  TLS-terminates before proxying to loopback.
- `router` — the loopback port on the shared `ddev-router` (Traefik) that
  Caddy proxies to; **shared across every instance** subscribing to that
  port name (Host-header routing on `ddev-router`'s side picks the right
  instance) — only the `public` side is unique per named port, not per
  instance.

Validated by `Registry._validate_fleet_ports` (`src/fleet/core/registry.py`):

- Both `public` and `router` must be plain ints (not bools) in `[1, 65535]`.
- Neither may be one of the fleet's own reserved ports: `22`, `80`, `443`,
  `8765`.
- `router` may not collide with the reserved `ddev-router` ports `8080`
  (HTTP) / `8443` (HTTPS).
- `public` must differ from `router` within the same entry.
- No two `fleet.ports` entries may share a `public` value, nor a `router`
  value — each is checked for uniqueness across the whole catalogue.

**Legacy back-compat:** `Registry.port_profile("typesense")` falls back to
a built-in default (`public=9108, router=8108`) when `fleet.ports` has no
explicit `typesense` entry — so an existing registry that only sets
`typesense: true` on a project (see below) needs **zero edits** to keep
working. Every other port name must be declared explicitly in the
catalogue before a project can reference it.

Once declared, run `fleet refresh-ports` to reconcile the Caddy
port-exposure snippets (and UFW rules, if the network-hardening role is
installed) to the current registry state — no Ansible re-run, no redeploy.
Full runbook: `docs/networking.md` §6.

## The `projects:` block

Each key under `projects:` is a project id (same naming rule as port names:
`[a-z0-9]([a-z0-9-]*[a-z0-9])?`, no `--` — reserved as the
`<project>--<label>` instance-id separator). Project and template keys are
registry-authored and are **not** normalised — an invalid key here is a
loud `RegistryError`, since it means a typo in the file you hand-author.

The instance **label** (`fleet deploy --label=<name>` / the web UI's Label
field, or the slugified branch when no label is given) is different: it is
user-supplied per-deploy, so `Registry.resolve()` normalises it instead of
rejecting it — lowercased, every run of non-`[a-z0-9]` characters collapsed
to a single `-`, leading/trailing `-` stripped. `--label=ABC-1234` resolves
to the instance label `abc-1234`; a label that normalises to empty (e.g.
`"!!!"`) still raises. When a deploy's explicit label was changed by this
normalisation, `fleet deploy`'s log records the substitution (e.g. `label
'ABC-1234' normalised to 'abc-1234' (instance ids must be lowercase DNS
labels)`).

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `git` | string | **yes** | — | SSH git URL cloned/updated for every instance of this project. The fleet's read-only deploy key (`fleet ssh-key`) must be added to the forge as a deploy key. |
| `default_template` | string | no | (none — `fleet deploy` errors without one) | Template name used when `fleet deploy <project>` omits its `<template>` positional arg. |
| `default_branch` | string | no | (none — `fleet deploy` errors without one) | Branch used when `fleet deploy` omits `--branch`. |
| `additional_hostnames` | list of strings | no | `[]` | Extra alias hostnames routed to the instance alongside `<instance-id>.<domain>` (e.g. Drupal Domain Access-style subdomains). Each entry must be a bare DNS label (lowercase, `^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`, no dots — validated at registry load, `RegistryError` otherwise). Resolved per instance as the FLATTENED FQDN `<h>-<instance-id>.<domain>` (`core/instances.py:alias_fqdns()`), e.g. `news-oak--translations-test.fleet.example.com` — never nested under the instance id, since Caddy's site block is a single-label wildcard. Deploy raises `DeployError` if `<h>-<instance-id>` exceeds the 63-character DNS label limit. Alias hosts are covered by the SAME per-instance basic-auth matcher as the instance's own FQDN (`core/caddyauth.py`) and each gets its own on-demand Let's Encrypt certificate (mind the ~50 new-certs/domain/week rate limit with many aliases). Every instance also gets `FLEET_INSTANCE_HOST=<instance-id>.<domain>` injected into `web_environment`, so Drupal can build the same alias pattern: `"<h>-" . getenv('FLEET_INSTANCE_HOST')`. Full design: `docs/networking.md` §7. |
| `typesense` | bool | no | `false` | **Legacy** opt-in flag: expose Typesense at the `typesense` named port (`*.<domain>:9108` by default) for every instance of this project. Equivalent to `ports: [typesense]`; both may be present without duplicating the exposure (`Registry.project_ports` de-dupes). The browser-exposed key must be a **search-only** key, never the admin key — see `docs/README-typesense.md`. |
| `ports` | list of strings | no | `[]` | The general mechanism superseding `typesense: true` — names must each exist as a key in `fleet.ports` (validated: `Registry._validate` raises if a project references an undeclared port name). |
| `git_bot` | mapping `{name, email}` or `false` | no | (inherits the fleet-level default) | Per-project override of the injected git commit identity. A mapping overrides `name`/`email` individually (either key may be omitted, falling back to the fleet default for that field). `false` disables identity injection for this project only, letting the project's own `git config` (e.g. a post-start hook) win — note the injected `GIT_AUTHOR_*`/`GIT_COMMITTER_*` env vars otherwise take precedence over `git config user.*`. |
| `issue_id_regexp` | string | no | (none — `[[issue-id]]` and `FLEET_ISSUE_ID` are simply absent) | A Python `re` pattern used to derive the `[[issue-id]]` token for this project's deploys — see "`[[issue-id]]` resolution" below. Validated at registry load: must be a string that `re.compile()`s (`RegistryError` otherwise). |
| `templates` | mapping | no | `{}` | See below. |

### `templates.<name>`

A template is a reusable, named deploy recipe — **not** a place to store a
branch (branch and the running instance's `label` are always resolved
per-deploy, from `fleet deploy` arguments, never from the registry; a
`branch:` key inside a template block is rejected at load time).

```yaml
templates:
  default:
    drupal_env: dev
    post_deploy: [ddev start, ddev drush deploy]
  staging:
    drupal_env: staging
    post_deploy: [ddev init --db=staging.sql --no-interactive]
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `post_deploy` | list of strings | no (default `[]`) | Commands run, in order, after the instance is cloned/configured/started, each as `bash -c <command>` with the instance directory as cwd (so a command that itself needs the DDEV containers typically calls `ddev exec ...` or another `ddev` subcommand). `[[token]]` placeholders (`[[project]]`, `[[branch]]`, `[[instance-fqdn]]`, `[[issue-id]]` when it resolves, per-project secret tokens, …) are substituted first — see `CLAUDE.md`'s "Per-project secrets model" for the token mechanism. This substitution is **strict**: a command left with an unresolved token raises `DeployError` naming the command and the token, and the deploy aborts — a `post_deploy` command is deploy-critical, so failing loudly beats silently skipping it. |
| `drupal_env` | string (single word) | no | Written as `DRUPAL_ENV=<value>` into the instance's own root `.env` after asset injection, overriding whatever the project's `assets/<project>/.env` ships. Lets one codebase run a `staging` template (different modules/cache) alongside a `dev` one. Omit it and the `.env` is left exactly as the project shipped it — an absent key means "don't touch", never "write dev". Rejected at load time if it is empty, non-string, or contains whitespace: it is written verbatim, with no quoting. |
| `tty1` | list of strings | no (default `[]`) | Commands typed into the **middle** bash pane of the instance's `fleet tmux` window, in order, the first time that window is created. See "`tty1`/`tty2`: interactive tmux commands" below. |
| `tty2` | list of strings | no (default `[]`) | Same as `tty1`, for the **right** bash pane (the sidebar occupies the fixed-width left column). |

### `[[issue-id]]` resolution

A project that sets `issue_id_regexp` gets a per-deploy `[[issue-id]]`
token, useful for handing an issue key to an interactive command (e.g.
`ddev exec claude "/jira pull [[issue-id]] --create-branch"`) without
hardcoding it into the template.

Resolution (`core/tokens.py:extract_issue_id`), tried in order:

1. `issue_id_regexp` is matched against the instance's **label** first.
2. If that doesn't match, it's matched against the **branch**.
3. If neither matches (or the project has no `issue_id_regexp`), `[[issue-id]]`
   is simply absent — commands that reference it are handled per the
   strict/lenient rule above.

Both candidates are matched **case-insensitively**, and the extracted value
is always **uppercased** before use. This matters because an instance label
is a DNS label (lowercase only — `core/naming.py`'s `[a-z0-9]([a-z0-9-]*[a-z0-9])?`),
so a Jira-style label is `abc-1234`, never `ABC-1234`; matching
case-insensitively means an operator's naturally-uppercase pattern
(`ABC-[0-9]+`) still fires on it, and uppercasing the result gives one
canonical `[[issue-id]]` regardless of which candidate matched. If the
pattern defines a capture group, group 1 is used; otherwise the whole match
is used. A matched value containing anything outside `[A-Za-z0-9._/-]` is
rejected (treated as no match) — the value is typed into a shell, so a
permissive pattern must not become an injection vector.

Worked examples, with `issue_id_regexp: 'ABC-[0-9]+'`:

| Instance label | Branch | `[[issue-id]]` |
|---|---|---|
| `abc-1234` | `dev` | `ABC-1234` (label wins) |
| `test2` | `feature/ABC-1234-some-improvement` | `ABC-1234` (branch fallback) |
| `test2` | `develop` | *(absent — a command referencing it is skipped/aborted per the strict/lenient rule)* |

The resolved value is also exported as `FLEET_ISSUE_ID` in the `post_deploy`
environment, and merged into the same deploy-time token context asset files
get (alongside `[[project]]`, `[[branch]]`, secret tokens, …) — so an asset
file can use `[[issue-id]]` too.

### `tty1` / `tty2`: interactive tmux commands

A template's `tty1`/`tty2` commands are typed into a live bash shell inside
the instance's `fleet tmux` window — via `tmux send-keys`, not spawned as
the pane's argv — so the process owns a real TTY (an in-pane `claude`
session renders and accepts input) and, when it exits, the operator is left
with a shell rather than a dead pane. Pane mapping is fixed: sidebar
(left) · `tty1` (middle) · `tty2` (right).

```yaml
projects:
  example:
    git: git@example.com:you/example.git
    issue_id_regexp: 'ABC-[0-9]+'
    templates:
      jira-pull:
        post_deploy:
          - ddev init --no-interactive
        tty1:
          - ddev exec claude "/jira pull [[issue-id]] --create-branch"
        tty2:
          - ddev drush watchdog:tail
```

**They fire once, on window creation, never on re-attach.** Concretely:

- If a `fleet` tmux session is already running when `fleet deploy` finishes,
  the commands are typed in immediately as part of that deploy.
- Otherwise, they fire the next time `fleet tmux` creates that instance's
  window (`reconcile()`). Re-running `fleet tmux` against an *existing*
  window never re-sends them — the window-creation check is itself the
  idempotency guard.
- One consequence worth planning around: after a host reboot (or any time
  the `fleet` tmux session doesn't survive), `fleet tmux` recreates every
  instance window from scratch, so **every** instance's `tty1`/`tty2`
  commands fire again, all at once.
- The fleet daemon never creates a tmux session by itself — only `fleet tmux`
  (run interactively by an operator) does. A tmux server spawned by
  `fleet.service` would live in that unit's cgroup and be killed by the next
  `systemctl restart fleet`, taking the operator's whole workspace with it.

**Unresolved tokens are lenient here**, unlike `post_deploy`: a `tty1`/`tty2`
command left with an unresolved `[[token]]` (most commonly `[[issue-id]]`
when the project has no `issue_id_regexp`, or neither the label nor the
branch matched it) is dropped — not typed in at all — and a
`WARNING: skipped tty1 (unresolved [[issue-id]]): <original command>` line
is appended to the deploy log (or, when triggered by `fleet tmux`, only
shown to the operator running it). The pane is left as a plain bash shell;
the deploy itself is never affected.

For `fleet tmux`'s `reconcile()` to know which commands to type into a
window it's about to create for an already-deployed instance, each new
deploy's resolved `template` name is recorded in `.fleet/instance.yml`. This
reaches **new deploys only** — an instance deployed before this field
existed simply resolves to no `tty1`/`tty2` commands (today's behaviour), and
a template or project later removed from `fleet.yml` is treated the same way.
Editing `fleet.yml` changes what a *future* window gets without a redeploy,
since the template is looked up in the live registry each time.

## `.fleet/instance.yml`: recorded deploy parameters

Every deploy writes `<instance-dir>/.fleet/instance.yml`, a small YAML file
recording the parameters that deploy resolved:

```yaml
project: demo
instance: preview
template: default
branch: main
auth-enabled: true
auth-password: fleet
created-at: 2026-07-20T10:00:00+00:00
last-deployed-at: 2026-07-27T09:30:00+00:00
```

| Key | Meaning |
|---|---|
| `project` | The project key this instance was deployed from. |
| `instance` | The instance's label (the part after `--` in `<project>--<label>`). |
| `template` | The resolved template name — what lets `fleet tmux`'s `reconcile()` (and `fleet redeploy`, below) recover which `tty1`/`tty2`/deploy recipe to use, without needing it re-supplied. |
| `branch` | The branch this instance tracks. |
| `auth-enabled` | Whether per-instance basic auth was on for this deploy. |
| `auth-password` | The basic-auth credential configured for this deploy, in plaintext. Used as BOTH username and password. |
| `created-at` | Timestamp of the instance's first deploy — preserved across later redeploys/updates. |
| `last-deployed-at` | Timestamp of the most recent deploy/redeploy. |

Every key **reaches new deploys only**: an instance deployed before a given
key existed simply has no recorded value for it (same "reaches new deploys
only" pattern as `template` above) — see `fleet redeploy`'s refusal rule
below for the consequence of a missing `template`.

Because `auth-password` is a plaintext credential, the file is written
(and rewritten) at mode **`0600`** on every write, not just on creation —
matching how `core/secrets.py` treats other secret-bearing files. It is not
part of the instance's git history: `.fleet/` is added to the instance's
`.git/info/exclude` at deploy time, alongside `.ddev/config.fleet.yaml` and
the other fleet-injected files, so it can never be accidentally committed.

**`fleet redeploy`** (`docs/cli.md`) reads this file to recover an
instance's project/template/branch/label/auth and rebuild it in place under
the same id, without the operator re-supplying any of them. If `template`
was never recorded (the instance predates this field), `redeploy` refuses
with an actionable error naming `--template` rather than guessing a recipe
to rebuild with. `--template`/`--auth-password` passed to `redeploy`
override the recorded value for that one call; the registry itself is
re-read at rebuild time, so a redeploy also picks up any edits made since
the original deploy to the resolved template's `post_deploy`/`tty1`/`tty2`.

## Walkthrough: `fleet.yml.dist` field by field

`fleet.yml.dist`, shipped at the repo root, is the template `fleet init`
copies verbatim (comments included) into a fresh `fleet.yml`, patching only
`fleet.domain`:

```yaml
# Example fleet registry. Copy to your private config repo as fleet.yml and edit.
fleet:
  domain: fleet.example.com
  # ports:                                  # fleet-wide named-port catalogue (optional, see docs/networking.md)
  #   typesense:  { public: 9108, router: 8108 }  # explicit form of the built-in legacy default below
  #   playwright: { public: 9324, router: 8323 }
projects:
  example:
    git: git@example.com:you/example.git
    default_branch: main
    default_template: default
    # typesense: true    # expose Typesense to the browser at *.<domain>:9108 (optional, legacy form — see fleet.ports/ports: for the general mechanism)
    # ports: [typesense]                    # generic equivalent, once fleet.ports.typesense is defined above
    # NOTE: browser-exposed TYPESENSE_API_KEY must be a SEARCH-ONLY key, never the admin key.
    # additional_hostnames: [sub1, sub2]   # Domain Access aliases (optional) -> sub1-<instance-id>.<domain>
    # issue_id_regexp: 'ABC-[0-9]+'         # optional — derives [[issue-id]]/FLEET_ISSUE_ID from the
    #                                       # instance label (checked first) or branch (fallback);
    #                                       # matched case-insensitively, result uppercased.
    templates:
      default:
        post_deploy: [ddev start]
      # jira-pull:                          # example template with interactive tmux commands (optional)
      #   post_deploy: [ddev init --no-interactive]
      #   tty1: ['ddev exec claude "/jira pull [[issue-id]] --create-branch"']  # typed into the middle pane
      #   tty2: [ddev drush watchdog:tail]                                     # typed into the right pane
```

- `fleet.domain` — the only value `fleet init` patches automatically (to
  whatever you answer at the `fleet init` prompt or pass as `--domain`);
  every other value is edited by hand afterwards.
- `fleet.ports` — commented out; uncomment and add entries to expose named
  ports. The `typesense`/`playwright` lines shown are examples, not
  defaults — nothing is exposed until you both declare it here **and**
  reference it from a project's `ports:` list (or, for Typesense only, set
  `typesense: true`).
- `projects.example` — a template project block: swap `example`/`git` for
  your real project id and SSH URL, uncomment/adjust `typesense`/`ports`/
  `additional_hostnames` as needed, and add one `templates.<name>` entry
  per deploy recipe you want (most projects need only `default`).
- `issue_id_regexp` and the commented `jira-pull` template — both fully
  commented out, so a fresh `fleet init` registry stays minimal and valid;
  uncomment and adapt them to hand an issue-tracker key to an interactive
  `tty1`/`tty2` command. See "`[[issue-id]]` resolution" and "`tty1`/`tty2`:
  interactive tmux commands" above.

## Recommended for teams: `FLEET_CONFIG_REPO`

The local-file mode above (`fleet.yml.dist` copied in place) is the
zero-dependency default — good for a single operator or a quick
evaluation. For a team, or any setup spanning more than one machine,
`fleet init` supports an advanced mode: point it at a **private git repo**
holding your real `fleet.yml` plus the `assets/` tree, and it clones that
repo into `config/` instead of copying the template:

```bash
FLEET_CONFIG_REPO=git@forge:you/fleet-config.git venv/bin/fleet init
```

This makes the registry itself version-controlled and shareable — edit
`fleet.yml` in the config repo, push, then `fleet refresh-config` on the
server to pull the update (a plain `git fetch --quiet && git pull
--ff-only` on `config/`; a no-op message if `config/` isn't a git checkout,
i.e. you're still on local-file mode). `fleet init` never re-clones an
existing `config/` checkout and never overwrites an existing `fleet.yml` —
both modes are safe to re-run. Full first-run walkthrough, including this
mode: `docs/installation.md` §6.

## See also

- `docs/installation.md` — the `fleet init` step in the first-run checklist.
- `docs/operations.md` — `fleet refresh-config`, `fleet refresh-ports`, and
  the rest of the ongoing-operations command set.
- `docs/networking.md` — the full port-exposure topology and runbook for
  adding a new named port.
- `docs/cli.md` — the full CLI reference.
- `CLAUDE.md` — `Registry`'s implementation notes (`core/registry.py`) and
  the git-identity-injection mechanism in more depth.


## Auth modes and `users:` — Authelia as an alternative to basic auth

Full design, install/switch instructions, and troubleshooting:
`docs/README-authelia.md`. Summary of the registry-facing pieces:

- **`host.yml`'s `auth_mode: basic | authelia`** (server-level, see above)
  picks which per-instance auth mechanism Caddy enforces. Absent means
  `basic` — today's default behaviour, unchanged.
- **`users:`** — a per-project list, only meaningful in `authelia` mode:

  ```yaml
  projects:
    myproject:
      users:
        - name: alice
          password: some-plaintext-password   # hashed (argon2id) only when rendering users.yml
        - name: bob
          password: another-password
  ```

  Names match `^[a-z0-9._-]+$`; `admins` is reserved (it's the dashboard's
  own required group); the same name must carry the same password in
  every project that lists it — `fleet.yml` fails to load with a
  `RegistryError` naming the conflict otherwise. Each project's `users:`
  becomes that project's Authelia group; a user reaches an instance when
  their groups contain that instance's project name or `admins`.
- Apply a `users:` edit with **`fleet refresh-auth`** — re-renders
  `users.yml` (hot-reloaded by Authelia's file backend, `watch: true` — no
  restart, nobody is logged out) and every instance's Caddy snippet, then
  one `caddy validate` + `caddy reload`.

## `fleet.auth_bypass_cidrs` — deprecated, see `docs/README-authelia.md`

**Deprecated.** This per-server CIDR whitelist used to let known networks
skip the per-instance basic-auth prompt entirely. It is now accepted in
`fleet.yml` only for backward compatibility — `Registry.load` logs a
deprecation warning naming the key and otherwise **ignores it entirely**:
no code path enforces it any more, in either auth mode. Networks that need
to bypass HTTP basic auth outright should switch the server to **Authelia
auth mode** instead (`docs/README-authelia.md`), which replaces the
whole-fleet IP whitelist with per-project login.
