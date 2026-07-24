---
Author: Claude Code
Reviewer: none
Last updated: 2026-07-16
Type: documentation
---

# CLAUDE.md — working on the ddev-fleet product repo

This file is for Claude (or any agent) editing code in this repo,
`/var/www/html/ddev-fleet` (remote: `ddev-fleet.git`). It is the **product**:
the fleet manager itself, live in production on a Kimsufi KS-7 host
(`ddev.personal.example`, Debian 13; instances under the
`fleet.personal.example` wildcard). For the workspace/tooling this repo is
built *from* (rules, skills, memory), see
`/var/www/html/.claude/rules/30-project.md` and the rest of
`/var/www/html/.claude/rules/`.

## Hard fact: this runs natively, not inside DDEV

`fleet.service` is a `systemd` unit running FastAPI+uvicorn as `User=fleet`,
bound to `127.0.0.1:8765`, on the bare-metal host. It is **not** a DDEV
project and does not run in a container. It manages *other* DDEV projects
(the fleet's instances) directly via the Docker socket and `ddev`/`git`
subprocess calls. Do not add DDEV config to this repo assuming it will run
inside one — see `/var/www/html/.claude/rules/11-ddev.md` for the companion
shell's own (unrelated) DDEV setup.

## Module map (`src/fleet/`)

| File | Responsibility |
|---|---|
| `cli.py` | Thin argparse CLI (`fleet …`); calls straight into `fleet.core`, never depends on the daemon |
| `daemon.py` | FastAPI app: `/api/tls-authorize` (Caddy on-demand TLS callback), `/api/jobs/{id}`, `/ws/instances/{id}/log` (HMAC-token-gated WebSocket), `/` + `/ui/*` HTMX routes for the web UI. The `/` index also renders a footer of host stats via `core/sysinfo` |
| `jobs.py` | In-memory `JobManager` backing the web UI's async deploy jobs (not a system of record — `.fleet/deploy.log` on disk is) |
| `core/registry.py` | Loads/validates `fleet.yml` (`Registry`), resolves `(project, template, branch, label)` → `ResolvedInstance`; registry is declarative and read-only at runtime. `git_bot(project)` resolves the per-instance commit identity — per-project `git_bot: {name,email}` override or `false` opt-out, over fleet-level defaults |
| `core/instances.py` | Orchestrates `deploy`/`destroy`/`start`/`stop`/`list_instances`/`snapshot` — the core engine; `FleetPaths` maps `FLEET_HOME` to all on-disk paths |
| `core/fleetconfig.py` | Writes the one fleet-owned file per instance, `.ddev/config.fleet.yaml` (name, project_tld, `web_environment` incl. Claude token, git bot identity, Typesense vars), plus `.ddev/.env`, `settings.local.php`/`services.fleet.yml`, and `.git/info/exclude` bookkeeping |
| `core/gitops.py` | `clone`/`update` of an instance's git worktree |
| `core/ddev.py` | Subprocess wrappers: `start`/`stop`/`restart`/`delete`/`list_projects`/`ram_usage` |
| `core/assets.py` | rsync-mirrors a project's asset tree into an instance, then runs the `[[token]]` substitution pass over copied files |
| `core/bulk.py` | Bulk orchestration over the existing single-instance primitives in `core/instances.py` — `BulkResult`/`BulkOutcome`, `run_sequential`/`run_concurrent` (continue-on-error, no new locking), and `multi_deploy()` (multi-instance deploy: `core/naming.py:allocate_multi_deploy_labels` + `core/sysinfo.py:check_disk_headroom` disk gate + the `_multideploy` advisory lock via `core/locks.py:instance_lock`). Used by both `cli.py` (bulk `start`/`stop`/`destroy`, `deploy --count`) and `daemon.py` (`/ui/bulk/*`, `/ui/deploy` with `count>1`) |
| `core/tokens.py` | The `[[token]]` substitution engine (`[[project]]`, `[[branch]]`, `[[instance-fqdn]]`, secret tokens, …) and `FLEET_*` env var derivation for `post_deploy` commands |
| `core/secrets.py` | Read/write `KEY=VALUE` files (0600) — both the fleet-wide `.secrets` and per-project `secrets/<project>.env` |
| `core/typesense.py` | Generates/persists per-project Typesense admin+search-only keys, registers the search-only key against a running instance's Typesense admin API |
| `core/caddyauth.py` | Rotates the Caddy dashboard `basic_auth` password WITHOUT Ansible: hashes via `caddy hash-password`, atomically rewrites the fleet-owned snippet `/etc/caddy/fleet/admin-auth.conf` (imported by `Caddyfile.j2`, seeded once by the `caddy` Ansible role), `caddy validate`s, then reloads Caddy via `caddy reload` (talks to the local Caddy admin API on 127.0.0.1:2019 — no sudo, no privilege escalation, works under `fleet.service`'s `NoNewPrivileges=yes` sandbox). Backs `fleet set-admin-password` / `fleet rotate-admin-password` |
| `core/caddyports.py` | Reconciles fleet-owned Caddy named-port exposure snippets (`/etc/caddy/fleet/ports/<name>.conf`) to `Registry.all_port_profiles()` — one snippet per port NAME with ≥1 subscribing project (Typesense, Playwright reports, etc.), imported by `Caddyfile.j2` via a glob. Mirrors `caddyauth.py`'s write/validate/reload pattern (`sync()`: atomic write → `caddy validate` → `caddy reload`, one batch per call) but raises the sibling `CaddyPortsError`, not `CaddyAuthError`. Called from `core/instances.py`'s `deploy()`/`destroy()`, the `fleet refresh-ports` CLI command, and once at daemon startup as a safety net |
| `core/locks.py` | Per-instance `flock`-based locking so concurrent CLI/daemon operations on the same instance can't race |
| `core/naming.py` | Validates project/template/label parts and composes `<project>--<label>` instance ids (DNS-label-safe) |
| `core/sysinfo.py` | Host stats for the web UI footer: `SystemStats.gather` (free/total RAM from `/proc/meminfo`, free/total disk from `shutil.disk_usage` on the instances mount) + `fmt_bytes`; memory → `n/a` if `/proc/meminfo` is unreadable |
| `core/reboot.py` | Single shared reader for Debian's reboot-required marker (`/var/run/reboot-required` + `.pkgs`) — `RebootStatus`/`read_reboot_status()` — plus the anti-spam notification cadence and msmtp email send backing `fleet reboot-notify [--test]`. Consumed by `tmux_sidebar.py` (sidebar banner) and `core/sysinfo.py` (web UI footer badge) — one implementation, not three |
| `core/errors.py` | `FleetError` hierarchy — every user-facing failure carries an actionable `.message` |

## Testing

Dev tooling is set up (unlike the companion shell, which has none yet —
don't assume they're the same). From `/opt/ddev-fleet` on the server, or
this repo's checkout locally:

```bash
.venv/bin/pytest -q          # 257 tests as of 2026-07-16
.venv/bin/ruff check .
.venv/bin/black --check .
```

`pyproject.toml` declares `dev` extras (`pytest`, `ruff`, `black`, `httpx`)
and `infra` extras (`ansible-core`, `ansible-lint`, `yamllint`) as optional
dependency groups. `ruff` selects `E,F,I`, line length 100 (`black` matches).
`pytest` filters a known `httpx`/`starlette.testclient` deprecation warning
(see `[tool.pytest.ini_options]`).

## Deploy model — shipping a code change to the live host

Full details: `docs/operations.md`. Summary:

- **Option B (preferred, enabled 2026-07-16).** `/opt/ddev-fleet` is a
  `fleet`-owned git checkout tracking `origin/main`, with a read-only
  deploy key. Update:

  ```bash
  sudo -u fleet git -C /opt/ddev-fleet pull --ff-only && \
  sudo -u fleet git -C /srv/fleet/config pull --ff-only && \
  sudo systemctl restart fleet
  ```

  The package is installed **editable** (`pip install -e`), so the restart
  alone picks up new source — no reinstall needed for a pure Python change.

- **Option A (fallback).** `git archive HEAD | tar` over ssh into
  `/opt/ddev-fleet`, then the same config-pull + restart. Used when Option
  B isn't available or as a documented rollback path (keep
  `/opt/ddev-fleet.src.bak` around).

- Run the **full** `sudo bash bootstrap.sh` / `ansible-playbook site.yml`
  only when system packages, the venv/dependencies, systemd units, or the
  Caddyfile actually change — not for a plain code change.

- **CAVEAT — do NOT run the full `ansible-playbook site.yml` against a
  live host casually.** The `fleet_service` role's git task rewrites
  `/opt/ddev-fleet`'s git remote to the `https://` `fleet_repo_url` in
  `ansible/group_vars/all.yml`, which breaks the `fleet`-user SSH-key-based
  `git pull` that Option B depends on. When only the Caddy config needs
  reapplying (e.g. after touching `Caddyfile.j2` or
  `fleet_typesense_public_port`), apply the `caddy` role alone via a scoped
  one-off playbook, not the full `site.yml`. **Rotating the dashboard admin
  password is NOT one of these cases** — `fleet rotate-admin-password` /
  `fleet set-admin-password` (`core/caddyauth.py`) never touches Ansible at
  all; see README.md "Default credentials".

## Per-project secrets model

- Fleet-wide secrets (`CLAUDE_CODE_OAUTH_TOKEN`, …) live in `/srv/fleet/.secrets`.
- Per-project secrets live in `/srv/fleet/secrets/<project>.env` — write them
  with `fleet secret set <project> KEY VALUE` (`core/secrets.py:write_secret`,
  0600, upserts).
- At deploy time (`core/instances.py:deploy`), `secret_tokens()` maps each
  `KEY` to a `[[key-with-dashes]]` token (e.g. `SLACK_BOT_TOKEN` →
  `[[slack-bot-token]]`), merged into the token-substitution context
  alongside `[[project]]`, `[[branch]]`, `[[instance-fqdn]]`, etc.
  `core/assets.py:inject` then rsyncs the project's asset tree into the
  instance and rewrites `[[token]]` placeholders in every copied text file
  (`core/tokens.py:substitute_file`, skips binaries/oversized files, raises
  `TokenError` naming the file if a token is left unresolved).
- Never print secret values in logs/output when working on this code —
  `core/secrets.py` and `core/typesense.py` are the two modules that handle
  raw key material; treat both carefully in tests and debugging.

## Typesense port/key coupling

Full human-readable design: `docs/README-typesense.md`; the generic
mechanism it now rides on: `docs/networking.md` + `core/caddyports.py`.
Typesense's public/router ports are no longer Python constants hand-synced
against Ansible vars — that footgun was eliminated 2026-07-24
(`2026-07-24-fleet-port-exposure-design.md`). They are now
`Registry.port_profile("typesense")` (`core/registry.py`): an explicit
`fleet.ports.typesense: { public, router }` entry in `fleet.yml`, or — if
absent — a built-in legacy default of `public=9108`/`router=8108` (so an
existing `fleet.yml` with only `typesense: true` needs zero edits). Any
project opted in (via `typesense: true` or `ports: [typesense, ...]`) gets
its Caddy exposure reconciled by `core/caddyports.py`'s `sync()`, called
from `deploy()`/`destroy()`, `fleet refresh-ports`, and daemon startup —
not by Ansible past the one-time `ports/` directory seed.

Two keys are generated per opted-in project (`core/typesense.py`): a strong
admin key (`TYPESENSE_API_KEY`, written to the instance's `.ddev/.env` for
the container boot and to `web_environment` for the Drupal backend — never
sent to the browser) and a search-only key (`FLEET_TYPESENSE_SEARCH_KEY`,
the only key injected for the browser to use), registered into the
instance's running Typesense via `register_search_key()` after `ddev start`.

This is a **port-based** design (`*.<domain>:9108` → Caddy → `127.0.0.1:8108`
ddev-router, Host-routed) that **superseded** an earlier path-based
(`/_typesense`) design — the `fern_facets` widget's built JS has no `path`
support, so path-prefixing didn't work end-to-end. If you find references to
`/_typesense` or a `FLEET_TYPESENSE_PATH` env var anywhere (old specs,
`fleet.yml.dist` comments), they're stale — the port-based scheme is the one
actually implemented and deployed.

## Cross-references

- `docs/srv-fleet-CLAUDE.md` — the registry schema + CLI reference, copied
  onto the server at `/srv/fleet/CLAUDE.md` so a `claude -p "..."` session
  run there (as the `fleet` user) has grounded context without reading the
  full spec. Keep it in sync with this file's CLI-shape facts and re-copy it
  after any change (`docs/operations.md`'s Claude-context-refresh section).
- `docs/installation.md` — full first-rollout checklist (DNS, delivery,
  admin password, deploy keys, Claude token mint, live-verification items).
- `docs/operations.md` — the ongoing code-update procedure, rollback, and
  admin-password rotation.
- `docs/README-typesense.md` — the Typesense browser-search exposure design
  in full (topology, keys, env vars, reindexing, reachability caveat).
- `/var/www/html/.claude/rules/` — the companion dev-shell's rules governing
  *how* Claude works on this repo (subagent model tiers, git branch/push
  policy, session logging, memory routing). This repo receives only product
  code; session logs/specs/plans for work done here live under the
  companion's `.claude/user/docs/` and `.claude/user/logs/`, not in this repo.
