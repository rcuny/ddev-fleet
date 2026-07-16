# ddev-fleet server context

This file is the server-side Claude Code context document (spec §10.2).
The canonical copy lives in the product repo at `docs/srv-fleet-CLAUDE.md`;
the runbook (`docs/runbook-server-rollout.md`, section 9) copies it to
`/srv/fleet/CLAUDE.md` so a `claude -p "..."` session run as the `fleet`
user on the server has grounded context without reading the whole spec.

## Registry (`/srv/fleet/config/fleet.yml`)

```yaml
fleet:
  domain: <string>                  # wildcard DNS root, e.g. fleet.example.com

projects:
  <project-key>:
    git: <ssh-git-url>
    default_template: <string>        # OPTIONAL — template used when `fleet deploy` omits one
    default_branch: <string>          # OPTIONAL — branch used when `fleet deploy` omits --branch
    additional_hostnames: [<string>, ...]  # OPTIONAL — extra FQDNs routed to the instance
    templates:
      <template-name>:
        post_deploy: [<string>, ...]  # OPTIONAL — commands run after deploy for this template
```

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
| `fleet deploy <project> [<template>] --branch <ref>` | `[--label=<name>] [--fresh] [--force]` | Full deploy pipeline; running instance is named `<project>--<label>` (label defaults to the slugified branch); `template`/`--branch` fall back to the project's `default_template`/`default_branch` when omitted; refuses a dirty/unpushed worktree update without `--force` |
| `fleet destroy <instance-id>` | — | Tears down containers, removes instance dir + lock file |
| `fleet start <instance-id>` | — | `ddev start` on an existing, stopped instance |
| `fleet stop <instance-id>` | — | `ddev stop` — frees RAM, keeps disk |
| `fleet list` | — | Table: id, project, branch, state, URL, RAM |
| `fleet ssh-key` | — | Prints the fleet deploy public key |
| `fleet assets push <project> <src> <dest-rel>` | — | Copies a local file into `assets/<project>/<dest-rel>` |
| `fleet snapshot <instance-id>` | `[--dest-rel=dumps/db.sql.gz]` | `ddev export-db` into the project's asset tree |
| `fleet refresh-claude-token` | — | Rotates `CLAUDE_CODE_OAUTH_TOKEN` fleet-wide, rewrites every instance's `config.fleet.yaml`, restarts running instances |
| `fleet set-admin-password <password>` | — | Sets the dashboard `basic_auth` password to an explicit value: hashes it (`caddy hash-password`), atomically rewrites `/etc/caddy/fleet/admin-auth.conf`, validates, reloads Caddy — no Ansible run |
| `fleet rotate-admin-password` | — | Generates a strong random dashboard password, applies it the same way, and prints it once |
| `fleet refresh-config` | — | Git-aware pull of `/srv/fleet/config` (fetch + `--ff-only` pull) so the registry and assets checkout track their remote; a no-op message if `config/` isn't a git checkout |

Projects and templates are declared by hand in `fleet.yml` — there is no
`fleet project add` and no auto-registration of unknown projects on deploy.
To onboard a new project, add a `projects.<key>` block (and its `templates`)
to the registry, then deploy.

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
- **Recovery when the daemon/web UI is down:** every command above works from the CLI directly against `fleet.core` — the daemon is not a dependency of the CLI (spec §11).
