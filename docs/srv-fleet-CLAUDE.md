# ddev-fleet server context

This file is the server-side Claude Code context document (spec §10.2).
The canonical copy lives in the product repo at `docs/srv-fleet-CLAUDE.md`;
the runbook (`docs/runbook-server-rollout.md`, section 9) copies it to
`/srv/fleet/CLAUDE.md` so a `claude -p "..."` session run as the `fleet`
user on the server has grounded context without reading the whole spec.

## Registry (`/srv/fleet/fleet.yml`)

```yaml
fleet:
  domain: <string>                  # wildcard DNS root, e.g. fleet.example.com
  assets_path: <absolute path>      # e.g. /srv/fleet/assets
  instances_path: <absolute path>   # e.g. /srv/fleet/instances

projects:
  <project-key>:
    git: <ssh-git-url>
    post_deploy: [<string>, ...]    # OPTIONAL — project-level default pipeline
    instances:
      <instance-name>:
        branch: <string>            # git ref to deploy
        post_deploy: [<string>, ...]  # OPTIONAL — replaces the project default entirely
```

Instance id = `<project-key>--<instance-name>` (double-dash). This is the
DDEV project name, the Docker Compose project prefix, and the hostname
label under the fleet wildcard domain. Edit `fleet.yml` by hand only when
necessary — every `fleet` command that mutates it uses `ruamel.yaml`
round-trip mode to preserve comments/formatting; a manual edit that breaks
YAML syntax or the schema (project/instance name pattern
`[a-z0-9]([a-z0-9-]*[a-z0-9])?`, no `--`) will make every `fleet` command
fail at registry load with an actionable message naming the bad key.

## CLI reference

| Command | Arguments | Behavior |
|---|---|---|
| `fleet init` | `[--domain=...] [--skip-claude]` | Interactive: fleet domain, `claude setup-token`, writes `.secrets` |
| `fleet deploy <project> <instance>` | `[--branch=<ref>] [--fresh] [--force]` | Full deploy pipeline; auto-registers unknown instances (`--branch` required then); refuses a dirty/unpushed worktree update without `--force` |
| `fleet destroy <instance-id>` | — | Tears down containers, removes instance dir + lock file |
| `fleet start <instance-id>` | — | `ddev start` on an existing, stopped instance |
| `fleet stop <instance-id>` | — | `ddev stop` — frees RAM, keeps disk |
| `fleet list` | — | Table: id, project, branch, state, URL, RAM |
| `fleet project add <key>` | `--git=<url> [--post-deploy=...]` | Registers a new project in `fleet.yml` |
| `fleet ssh-key` | — | Prints the fleet deploy public key |
| `fleet assets push <project> <src> <dest-rel>` | — | Copies a local file into `assets/<project>/<dest-rel>` |
| `fleet snapshot <instance-id>` | `[--dest-rel=dumps/db.sql.gz]` | `ddev export-db` into the project's asset tree |
| `fleet refresh-claude-token` | — | Rotates `CLAUDE_CODE_OAUTH_TOKEN` fleet-wide, rewrites every instance's `config.fleet.yaml`, restarts running instances |

## Common workflows

- **Deploy a known instance on a new branch push:** `fleet deploy <project> <instance>` (re-uses the registered branch) or `fleet deploy <project> <instance> --branch=<ref>` to switch branches.
- **Register and deploy a brand-new instance:** `fleet deploy <project> <new-instance-name> --branch=<ref>` — auto-registers into `fleet.yml` before deploying.
- **Free RAM without losing disk state:** `fleet stop <instance-id>`; bring it back with `fleet start <instance-id>`.
- **Fully tear down:** `fleet destroy <instance-id>` — irreversible, removes the instance directory.
- **Refresh a stale DB dump for a project:** `fleet snapshot <instance-id>` (exports the running instance's DB into its project's shared asset tree) then `fleet deploy <project> <other-instance>` to propagate it.
- **Rotate the Claude Code token fleet-wide (e.g. before the ~1 year expiry):** `fleet refresh-claude-token` — safe to re-run; only running instances are restarted.
- **Recovery when the daemon/web UI is down:** every command above works from the CLI directly against `fleet.core` — the daemon is not a dependency of the CLI (spec §11).
