# HTTP API: tokens and authentication

New to the API? Start with the guide: [use the API](../guides/09-use-the-api.md).

The API lives under `/api/v1/` on the same host name as the web interface and answers JSON. This page covers the foundation: how a caller authenticates and what a token may do. The endpoints are added release by release: the read endpoints are below (`GET` only, scope `read`) and so are the write endpoints.

> **Status:** the firewall, the token store and the checks described here are in the app, and Caddy lets `/api/v1/*` through the login gate (see "Network path" below).

## Network path

Caddy skips the Authelia login for `/api/v1/*` and for nothing else under `/api/`, because the callers are machines. The route is the same host name and certificate as the web interface:

- **Identity headers are stripped first.** Every `Remote-*` and `Remote_*` request header is removed on every path before the gate and before the app, so a forged `Remote-User: admin` never reaches PHP. On the straight `/api/v1/` path the `api` firewall also has no proxy-identity authenticator, a second guard; on odd spellings (upper case, doubled slash) the Caddy strip is the only layer, and a test sends them through a real Caddy. A test runs the rendered Caddyfile against a real Caddy (`app/tests/Repo/RenderedCaddyApiRouteTest.php`, needs `FLEET_CADDY_STOCK_BIN`).
- **TLS only.** The site address is HTTPS; Caddy redirects plain HTTP itself and no extra listener or firewall rule is added.
- **Limits.** Request bodies are capped at 1 MB (`413` above), the upstream read and write timeout is 30 seconds, and responses carry `Cache-Control: no-store`. No CORS header is sent: the API is not called from a browser.
- During setup (before the wizard is finished) the setup gate redirects everything but the wizard, so the API is closed until then (a test pins that the setup gate does not list `/api`).

## Authenticating

Send the token in the `Authorization` header, nothing else:

```
Authorization: Bearer flt_svc_...
```

A token in the query string, in the body or in another header is never read. The Authelia identity headers (`Remote-User` and friends) are ignored on `/api/`: the API has its own firewall with no proxy-identity authenticator, so a forged header is not an identity.

Every failed authentication answers the same `401` whatever the reason (missing, malformed, unknown, expired, revoked, or sent from an address outside the allow-list). The reason goes to the audit log as `api.denied`.

## The token

| Part | Value |
|---|---|
| Format | `flt_svc_` + 43 random Base62 characters + 6 Base62 characters of CRC32 checksum |
| Stored | Only the SHA-256 of the whole token. It is shown once, when issued. |
| Expiry | Required. At most 365 days (7 days with `terminal:write`). Defaults: 90 days (`read`, `deploy`), 30 days (`lifecycle`), 1 day (`terminal:*`). |
| Projects | A list of project names. `*` (every project) is allowed for a read-only token only. |
| IP allow-list | Optional CIDR ranges or addresses, checked against the connection's address (a forwarding header is not consulted). Required with `terminal:write`. |
| Instance patterns | `*`/`?` patterns of instance ids a terminal scope may reach. |

The prefix and the checksum let secret scanners find a leaked token and let the app turn typos away without a database lookup.

### Scopes

| Scope | Meaning |
|---|---|
| `read` | List and read. |
| `deploy` | Create and redeploy instances of the listed projects. |
| `lifecycle` | Start, stop and retry on the listed projects. |
| `lifecycle:destroy` | A flag on top of `lifecycle`: destroy instances. |
| `terminal:read`, `terminal:write` | `terminal:read` reads an agent's pane (see "Terminal read"); typing into it needs `terminal:write` (see "Terminal write"). `terminal:write` cannot be combined with `deploy` or `lifecycle`. |

There is no wildcard scope and no scope implies another. Settings, secrets, users and tokens are never reachable with a token.

## Managing tokens

Tokens are service tokens: they belong to no person and outlive anyone's access. Only an admin makes them, from the CLI on the host or from **API tokens** in the web interface. Nothing in the API itself can create, change or list tokens.

### CLI

```
sudo fleet token:create orchestrator --scope=read --scope=lifecycle --project=blog --ip=203.0.113.0/24 [--expires-in=DAYS]
sudo fleet token:list [--all] [--json]
sudo fleet token:rotate <id> [--grace=SECONDS] [--expires-in=DAYS]
sudo fleet token:revoke <id>
```

`create` and `rotate` print the secret as the only line on stdout (or the `secret` field with `--json`); everything else goes to stderr. It is shown once. Store it as a secret, never in a repository. **`--json` output contains the secret**: keep it out of CI logs, shell history and job output (capture it into a secret store, not a build log). The audit actor of CLI changes is `cli`.

`rotate` makes a successor with the same scopes and restrictions and a new default lifetime (or `--expires-in`). The old token is revoked at once; with `--grace` (at most 3600 seconds) it keeps working that long, so a client can switch without downtime. A revoked or expired token cannot be rotated: issue a new one. A token that was already rotated (its successor exists, so it is in or past its grace period) cannot be rotated again: rotate the successor, or revoke the old one. `token:list` hides revoked and expired tokens unless you pass `--all`, and flags tokens that **expire within 14 days** (`EXPIRING`) or were **not used for 60 days** (`UNUSED`, counted from creation for a token never used).

### Web interface

**API tokens** (`/admin/api-tokens`, admins only) lists the tokens (prefix hint, scopes, projects, state, expiry, last use time and address; never the secret), the "Needs attention" panel with the expiring and unused tokens, and the create, rotate and revoke forms. The rotate form sets the grace period and, optionally, the successor's lifetime in days. Every change asks for your password again (re-auth) and is audited. The new secret appears once, in the page that the change returns, with a copy button.

## Limits

| Limit | Value |
|---|---|
| Reads | 120 per minute per token |
| Writes | 30 per minute per token |
| Failed authentications | 10 per 15 minutes per client address; beyond that the address gets `429` before its token is looked at |

The failed-authentication limit is **per client address**, not per token. Callers behind one shared address (a NAT, an office or a CI runner pool) share the same 10 attempts: one misconfigured client can lock out the others on that address for up to 15 minutes. Give each calling host its own address, or its own token with an `--ip` allow-list so a wrong token fails fast and is easy to find in the `api.denied` rows.

A `429` carries `Retry-After`.

## Responses

Every response carries `X-Request-Id` and `Cache-Control: no-store`. Errors are `application/problem+json` (RFC 9457): `type`, `title`, `status`, `detail`, `code` and `request_id`. Codes so far: `unauthorized`, `forbidden`, `scope_missing`, `project_not_allowed`, `rate_limited`, `not_found`, `method_not_allowed`, `invalid_limit`, `invalid_cursor`, `invalid_filter`, `invalid_offset`, `internal_error`. An unauthenticated caller gets `401` for every path under `/api/`, existing or not.

## Machine-readable description

The API is described by an OpenAPI 3.1 document: `GET /api/v1/openapi.json` (any valid `read` token) and [openapi.md](16-openapi.md).

## `GET /api/v1/tokens/self`

Any valid token. Returns its own `id`, `name`, `description`, `prefix_hint`, `scopes`, `projects`, `instance_globs`, `ip_allow`, `created_by`, `created_at`, `expires_at`, `expires_in_seconds`, `last_used_at`, `last_used_ip` and `rotated_from`, so a client can check its health before it expires. The secret and its hash are never returned. `last_used_at` is written at most once a minute.

## Read endpoints

All need the `read` scope and answer JSON, except the job log. A token limited to some projects sees those projects only: lists leave the others out, and naming another project (path or `project=` filter) answers `403 project_not_allowed`, whether or not it exists. A job without a project (a host job) is visible to a read-only `*` token only.

| Endpoint | Returns |
|---|---|
| `GET /api/v1/host` | Host name, installed version, generation (major version), instance counts (host-wide tokens), visible project count. |
| `GET /api/v1/host/capacity` | The schema-1 document of `fleet host:capacity --json` ([capacity](10-capacity.md)). Host-wide: a token limited to some projects gets `403 host_wide_not_allowed` (as for `host` and `upgrade`). `?project=P[&template=T]` adds `verdict`: `{project, template, ok, reasons[], slots, cores, instances}`. `reasons` holds `template_slots_full`, `project_slots_full`, `cpu_full`, `instance_cap`; the template defaults to the project's default template. The gate is the one the `ddev-fleet` skill applies: dispatch only while `ok`. |
| `GET /api/v1/projects`, `/projects/{p}` | The project as `fleet project:show --json` shows it. |
| `GET /api/v1/projects/{p}/templates`, `/templates/{p}/{t}` | Templates as `fleet template:show --json` shows them. No secret value: environment entries are the placeholders the template holds. |
| `GET /api/v1/instances` | `id`, `project`, `label`, `state`, `branch`, `template`, `head`, `ram_mib`, `requested_by`, `url`, `created_at`, `deployed_at`, `last_observed_at`, `busy` (`{job_id}` of the queued or running job, else `null`). Filters: `project`, `branch`, `state`, `label`. |
| `GET /api/v1/instances/{id}` | The same row plus `jobs`, the instance's last five. |
| `GET /api/v1/jobs`, `/jobs/{id}` | `id`, `type`, `status`, `final`, `trigger`, `requested_by`, `project`, `instance`, `batch_id`, `retry_of`, `exit_code`, `error`, `created_at`, `started_at`, `finished_at`. Never the job parameters. Filters on the list: `status`, `trigger`, `type`, `project`, `instance`. |
| `GET /api/v1/jobs/{id}/log?offset=N` | `text/plain`: the masked log from byte `offset` (default 0), whole lines only. Headers `X-Next-Offset` (continue here), `X-Job-State` (the status) and `X-Log-Done` (`1` when the job is final and the log is read to its end). Poll every 2 to 5 seconds to tail. |
| `GET /api/v1/upgrade` | `installed`, `latest`, `behind`, `engine`, `catalog_failed`, `pending_migrations`. Offline: it reports what the host last saw and never asks the release source. |
| `GET /api/v1/webhooks/log` | Recent deliveries (subject, rule, decision, outcome, job id), newest first. Filters: `project`, `result`, `hide_ignored=1`. No request body and no secret. |

Not in this release: the `agent` object on instances (running, foreground command, idle time).

## Write endpoints

Every write answers `202 Accepted` with `{"job": {"id", "state", "url"}}` and a `Location` header; poll `GET /api/v1/jobs/{id}` until the job is final (see "Read endpoints"). Bodies are JSON objects; a refused value is `422 validation_failed` with an `errors` map (field to message). A token is limited to its listed projects: another project's instance answers `403 project_not_allowed`, an unknown one `404`. There is no dispatch endpoint: dispatching a ticket stays the Jira webhook.

| Request | Scope | Notes |
|---|---|---|
| `POST /api/v1/instances` | `deploy` | Body `{project, template?, branch?, label?}`; defaults come from the project. Refused with `429 capacity_full` (and the verdict in `verdict`) while the server-side capacity gate says the host or project is full; nothing is gated when the host reports no CPU allotment. |
| `POST /api/v1/instances/{id}/redeploy` | `deploy` | Optional body `{template?, branch?, force?}`; a `branch` is checked with the same rule as a deploy (`422` on `-x`, spaces, `..`). `409 instance_busy` while a job is queued or running on it. |
| `POST /api/v1/instances/{id}/start`, `/stop` | `lifecycle` | `409 instance_busy` as above. |
| `DELETE /api/v1/instances/{id}` | `lifecycle` and `lifecycle:destroy` | `409 protected_instance` for a project's `main` instance. `If-Match: "<created_at>"` (the instance's `created_at`) is required: without it `428 if_match_required`, with a different value `412 stale_if_match`, and nothing is destroyed, so a stale id never removes a newer instance that reused it. |
| `POST /api/v1/instances:destroy-batch` | `lifecycle` and `lifecycle:destroy` | Body `{ids: [...], if_match: {"<id>": "<created_at>"}}`, at most 20 ids; an id without its `if_match` entry is refused with `428` in its result. One job per id; the answer is `207` with a `results` entry per id: `{id, accepted: true, job}` or `{id, accepted: false, status, code, detail}`. A refused id does not stop the others. |
| `POST /api/v1/jobs/{id}/retry` | the scope the repeated work needs | `deploy` for a deploy or redeploy (a failed deploy is retried as a redeploy), `lifecycle` for start and stop, `lifecycle` and `lifecycle:destroy` for a destroy. Only a failed or interrupted job. Retrying a destroy applies the destroy rules again: the `main` protection and the required `If-Match` header. |

### Terminal read

`GET /api/v1/instances/{id}/terminal?pane=tty2&lines=40` needs `terminal:read`, the instance's project in the token's project list **and** an instance pattern of the token that matches the id (`403 instance_not_allowed` otherwise; a token without patterns reaches none). `pane` is `tty1` or `tty2` (default `tty2`, the agent's pane); anything else, the sidebar and the `general` window included, is `400 invalid_pane`, and `general` is not an instance id. `lines` is 1 to 200 (default 40). The answer is JSON `{instance, pane, lines, truncated, captured_at, text}`: terminal escapes and control characters are removed, registered secret values are masked, and the text is capped at 64 KiB (the end is kept, `truncated: true`). Reads have their own limit of 30 per minute per token on top of the read pool (`429` with `Retry-After`). Each read is audited as `api.terminal.read` with the instance, pane, line and byte counts and the client address, never the text. `404` when the instance or its window has no such pane.

### Terminal write

`POST /api/v1/instances/{id}/terminal` with `{"pane":"tty2","text":"...","enter":true}` types one line of text into the agent of an instance. This is the most sensitive call of the API: the text reaches an agent that acts with the instance's privileges (push key, tokens). It is a message to an agent, never a command for the host, and every gate below must pass or nothing is typed.

| Gate | Refusal |
|---|---|
| Token holds `terminal:write` (never combined with `deploy`/`lifecycle`, needs an IP allow-list, 7 days at most, 1 day by default), lists the project, and an instance pattern matches the id | `403` |
| The project's **Terminal input over the API** switch is on (Project, Keys & secrets; off by default, admin only, asks for the password again, audited as `project.api-terminal`). It is not part of the CLI, the export or the import, so no script turns it on | `409 terminal_write_disabled` |
| The instance is running | `409 instance_not_running` |
| `pane` is `tty1` or `tty2` (the sidebar and the `general` window cannot be named); the pane is found by its `tty1`/`tty2` tag, or by position only in a window with exactly two untagged shells; a split window without tags has no addressable pane | `400 invalid_pane`, `404` |
| `text` is one line of plain text: valid UTF-8, at most 4096 bytes, no control, format (bidi, zero-width), separator or unassigned character (so no newline and no escape), and it does not start with `!`, `/` or `#` (the agent reads those as a shell command, a slash command or a memory note). Key names such as `C-c` are plain text, never keys | `422` |
| Rate limit: one call per 2 seconds and 6 per minute per token | `429` with `Retry-After` |
| The pane's foreground command is the agent (`claude`), read twice: when the call is accepted and again immediately before the keys are sent. A shell, an editor, `ssh`, `sudo` or anything else is refused; so is a pane that is no longer in the instance's own window | `409 pane_not_agent`, `409 pane_changed` |
| The call is recorded first: a row in `api_call` (token, client address, pane, foreground command, length, sha256 and the text up to 4 KiB) and an `api.terminal.write` audit entry. If either cannot be written, nothing is sent | `503 audit_unavailable` |

The answer is `{instance, pane, foreground, length, sha256, enter, call}`. `enter` presses Enter after the text; without it the text only lands in the agent's input line. Denials are audited as `api.denied` with their reason.

**What this does not prevent.** The foreground check reduces host takeover to agent takeover; it does not remove it: an agent told to run commands can run them with its own privileges, and the check and the keystroke are two tmux calls, so a pane that changes in those few milliseconds is a residual race (the re-check narrows it, the evidence row shows what was sent). Turn the switch on only for projects whose agents an orchestrator must nudge, keep the token's IP list and instance patterns tight, and revoke it when the work is done.


Each accepted write is audited as `api.write` (actor `token:<id>:<name>`, subject: the operation, the target, the job and the client address). The jobs record the trigger `api`. The token id is the stable identifier; the name is informational and can be reused. Names cannot contain `:`, so the actor splits unambiguously into `token`, id and name.

**Replays.** There is no idempotency key. Repeating a write while the first job is queued or running answers `409 instance_busy`; after it finished, the write runs again (a repeated destroy of a gone instance answers `404`).

### Pagination

Lists take `?limit=` (1 to 200, default 50) and an opaque `?cursor=`; the response carries `next_cursor`, `null` on the last page. A cursor not issued by the API answers `400 invalid_cursor`.

## Audit

`api_token.create` and `api_token.revoke` record a new and a revoked token. `api_token.rotate` records the successor, and the old token's end is audited too: `api_token.revoke` when it dies at once, `api_token.expire` (with the grace in seconds) when it gets a grace period; `api.denied` records refused calls (authentication, scope, project, address). The actor of a token's calls is `token:<id>:<name>`. A secret is never written to the log. `api.terminal.write` records each terminal input that was carried out (instance, pane, foreground command, byte count, the `api_call` evidence row id; the text itself is in `api_call`); `project.api-terminal` records an admin turning the project switch on or off.
