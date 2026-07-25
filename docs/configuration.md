---
Author: Claude Code
Reviewer: none
Last updated: 2026-07-25
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

## Top-level shape

```yaml
fleet:
  domain: <string>              # required
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
    templates:
      <template-name>:
        post_deploy: [<string>, ...]       # optional
```

## The `fleet:` block

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `domain` | string | **yes** | — | The wildcard DNS root, e.g. `fleet.example.com`. Every instance is reachable at `<instance-id>.<domain>`; the dashboard/web UI at `<domain>` itself (behind Caddy's `fleet.<domain>` site — see `docs/networking.md`). |
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
`<project>--<label>` instance-id separator).

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `git` | string | **yes** | — | SSH git URL cloned/updated for every instance of this project. The fleet's read-only deploy key (`fleet ssh-key`) must be added to the forge as a deploy key. |
| `default_template` | string | no | (none — `fleet deploy` errors without one) | Template name used when `fleet deploy <project>` omits its `<template>` positional arg. |
| `default_branch` | string | no | (none — `fleet deploy` errors without one) | Branch used when `fleet deploy` omits `--branch`. |
| `additional_hostnames` | list of strings | no | `[]` | Extra FQDNs routed to the instance alongside `<instance-id>.<domain>` (e.g. Drupal's domain-access-style subdomains). |
| `typesense` | bool | no | `false` | **Legacy** opt-in flag: expose Typesense at the `typesense` named port (`*.<domain>:9108` by default) for every instance of this project. Equivalent to `ports: [typesense]`; both may be present without duplicating the exposure (`Registry.project_ports` de-dupes). The browser-exposed key must be a **search-only** key, never the admin key — see `docs/README-typesense.md`. |
| `ports` | list of strings | no | `[]` | The general mechanism superseding `typesense: true` — names must each exist as a key in `fleet.ports` (validated: `Registry._validate` raises if a project references an undeclared port name). |
| `git_bot` | mapping `{name, email}` or `false` | no | (inherits the fleet-level default) | Per-project override of the injected git commit identity. A mapping overrides `name`/`email` individually (either key may be omitted, falling back to the fleet default for that field). `false` disables identity injection for this project only, letting the project's own `git config` (e.g. a post-start hook) win — note the injected `GIT_AUTHOR_*`/`GIT_COMMITTER_*` env vars otherwise take precedence over `git config user.*`. |
| `templates` | mapping | no | `{}` | See below. |

### `templates.<name>`

A template is a reusable, named deploy recipe — **not** a place to store a
branch (branch and the running instance's `label` are always resolved
per-deploy, from `fleet deploy` arguments, never from the registry; a
`branch:` key inside a template block is rejected at load time).

```yaml
templates:
  default:
    post_deploy: [ddev start, ddev drush deploy]
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `post_deploy` | list of strings | no (default `[]`) | Commands run, in order, after the instance is cloned/configured/started, via `ddev exec`. `[[token]]` placeholders (`[[project]]`, `[[branch]]`, `[[instance-fqdn]]`, per-project secret tokens, …) are substituted first — see `CLAUDE.md`'s "Per-project secrets model" for the token mechanism. |

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
    # additional_hostnames: [sub1, sub2]   # domain-module subdomains (optional)
    templates:
      default:
        post_deploy: [ddev start]
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
mode: `docs/installation.md` §4.

## See also

- `docs/installation.md` — the `fleet init` step in the first-run checklist.
- `docs/operations.md` — `fleet refresh-config`, `fleet refresh-ports`, and
  the rest of the ongoing-operations command set.
- `docs/networking.md` — the full port-exposure topology and runbook for
  adding a new named port.
- `docs/cli.md` — the full CLI reference.
- `CLAUDE.md` — `Registry`'s implementation notes (`core/registry.py`) and
  the git-identity-injection mechanism in more depth.
