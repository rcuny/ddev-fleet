---
name: fleet-api
description: Watch and manage this project's own Fleet instances through the Fleet HTTP API with a project token. Use when an orchestrator or agent needs to list its instances, read a job log or an agent's terminal, or deploy, redeploy, start, stop or destroy an instance of its project.
---

# fleet-api

A small client for the Fleet HTTP API (`/api/v1`). It uses a **project token**: the token lists the projects it may touch, and another project's instance answers `403`. Standard library only (Python 3), no install step.

## Setup

Two environment variables, set by whoever runs the agent. **Never write them into a repository, a log or a comment.**

```bash
export FLEET_API_URL='https://fleet.example.com'   # https only; a redirect is refused
export FLEET_API_TOKEN='flt_svc_...'
```

The Fleet administrator creates the token (see "Use the API" in the Fleet guides):

```bash
sudo fleet token:create <project>-orchestrator -s read -s lifecycle -p <project> --ip <address> --expires-in 30
```

Add `-s deploy` to let the agent deploy and redeploy, `-s lifecycle:destroy` to let it destroy, and `-s terminal:read` (with an instance pattern) to let it read panes. Check the token first:

```bash
python3 skills/fleet-api/bin/fleet_api.py token-check
```

## Commands

Run `python3 skills/fleet-api/bin/fleet_api.py <command>` (the path is where this skill is installed). Output is JSON on stdout; job logs and terminal text are plain text.

| Command | What it does | Scope |
|---|---|---|
| `token-check` | Scopes, projects, expiry of this token | any |
| `instances [--project P --branch B --state S --label L]` | All instances (every page) | `read` |
| `instance <id>` | One instance and its last five jobs; `created_at` | `read` |
| `job <id>` | A job's status (`final`, `error`, `exit_code`) | `read` |
| `job-log <id> [--offset N]` | Log from byte N; stderr prints `next-offset=... state=... done=...`. Poll with the printed offset every 2 to 5 seconds to tail | `read` |
| `capacity [--project P --template T]` | Capacity verdict (`verdict.ok`, `reasons`, `instances.remaining`) of the own project, from the project-scoped `GET /host/capacity`. `--project` is optional when the token names exactly one project. Do not guess a verdict | `read` |
| `deploy --project P [--branch B --template T --label L] [--wait]` | Create an instance | `deploy` |
| `redeploy <id> [--branch B --template T --force] [--wait]` | Redeploy | `deploy` |
| `start <id>`, `stop <id>` `[--wait]` | Start or stop | `lifecycle` |
| `destroy <id> --confirm <id> [--wait]` | Destroy. Refused unless `--confirm` repeats the id. Reads the instance and sends `If-Match` from its `created_at`. A project's `main` instance cannot be destroyed | `lifecycle` + `lifecycle:destroy` |
| `terminal <id> [--pane tty1\|tty2] [--lines N]` | The tail of an agent's pane (1 to 200 lines, secrets masked) | `terminal:read` |

A write answers with a job; `--wait` polls it until final (`--timeout` seconds, default 600) and exits 3 when the job did not succeed. Without `--wait`, follow it with `job` and `job-log`.

## Optional live feed (no polling)

`job-log` polling is the baseline and always works. A host with a Mercure hub also offers a push wake-up for one job:

1. `POST /api/v1/jobs/<id>/stream-token` with the same bearer token (scope `read`, same project rules as the log). It answers `{token, topic, hub_url, expires_at}`. `503 streaming_unavailable` means no hub on this host: keep polling.
2. Open an SSE connection: `curl -N -H "Authorization: Bearer <token>" "<hub_url>?topic=<topic>"`. The Mercure token is a subscribe-only JWT for that one job, valid 10 minutes or until the API token expires, whichever is sooner. It is not the API token: never send one where the other is expected.
3. Each event carries `id`, `status` and `size`, never the text. On an event, fetch the new text with `job-log <id> --offset N`. Stop when the status is final.
4. When the stream ends or the token expires, mint a new one (the call is audited), or fall back to polling.

## Behaviour to rely on

- Exit codes: 0 done, 1 API or network error, 2 usage or a local refusal (missing variable, plain `http`, destroy without `--confirm`), 3 a waited-for job failed.
- Errors are `application/problem+json`; the code is printed (`capacity_full`, `instance_busy`, `stale_if_match`, `rate_limited`...).
- `429` waits for `Retry-After` (at most 60 s) and retries up to 3 times. Reads also retry on `5xx`; writes never retry on anything but `429`.
- The token is masked as `flt_***` in all output.
- Destroy is irreversible. Ask for explicit approval from the person who owns the ticket before running it, unless the ticket says to.
