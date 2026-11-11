# Use the HTTP API

Fleet has an HTTP API for scripts, CI jobs and an orchestrator that deploy, start, stop or watch instances without a person at a browser. It lives under `/api/v1/` on the same host name as the web interface. It is **off until you create a token**, and `/api/v1` skips the login portal on purpose: the bearer token is the only credential. Details and every endpoint: [the API reference](../reference/15-api.md).

## Create a token

Only an administrator makes tokens, on the host or in the web interface.

```bash
sudo fleet token:create ci-bot \
  --description "Deploys CI branches" \
  -s read -s deploy \
  -p website \
  --ip 203.0.113.0/24 \
  --expires-in 60
```

- `ci-bot` is the name: lower-case letters, digits, `.`, `_`, `-` (32 at most).
- `-s` / `--scope` and `-p` / `--project` repeat. `--ip` is an optional address or CIDR range, repeatable.
- The **secret is the only line on stdout**, shown once (it starts with `flt_svc_`). Everything else goes to stderr, so `SECRET=$(sudo fleet token:create ...)` captures just the secret.
- `--json` prints the secret too. Keep that output out of logs, CI output and shell history.

In the web interface, open **API tokens** (`/admin/api-tokens`), fill in the create form and confirm with your password. The secret appears once on the page that follows, with a copy button.

## Choose scopes

Scopes are separate: none implies another. To do X, you need Y:

| To do | You need |
|---|---|
| List and read hosts, projects, instances, jobs, logs | `read` |
| Create or redeploy an instance | `deploy` |
| Start, stop or retry an instance | `lifecycle` |
| Destroy an instance | `lifecycle` and `lifecycle:destroy` |
| Retry a failed deploy | `deploy` |

Settings, secrets, users and tokens are never reachable with a token.

**Projects.** Every token lists the projects it may touch (`-p website -p blog`). Another project's instance answers `403`. `-p '*'` (every project) is allowed for a read-only token only.

**Expiry.** A token always expires. Without `--expires-in` you get 90 days for `read` and `deploy`, and 30 days for `lifecycle`. No token lives longer than 365 days.

**IP allow-list.** With `--ip`, the token works only from those addresses. A call from anywhere else gets the same `401` as a wrong token, and the host records why. Use it whenever the caller has a fixed address.

## Use it

Send the token in the `Authorization` header. A token in the query string or the body is never read.

```bash
export FLEET_TOKEN='flt_svc_...'
curl -sS -H "Authorization: Bearer $FLEET_TOKEN" https://fleet.example.com/api/v1/tokens/self
curl -sS -H "Authorization: Bearer $FLEET_TOKEN" https://fleet.example.com/api/v1/instances
```

`tokens/self` shows the token's scopes, projects and `expires_at`, so a script can check its own health. A write answers `202` with a job; poll `GET /api/v1/jobs/<id>` until it is final.

```bash
curl -sS -X POST -H "Authorization: Bearer $FLEET_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"project": "website", "branch": "feature/login"}' \
  https://fleet.example.com/api/v1/instances
```

**Destroy needs `If-Match`.** Read the instance first, then send its `created_at` as the header, so a stale id never removes a newer instance that reused it:

```bash
curl -sS -i -H "Authorization: Bearer $FLEET_TOKEN" https://fleet.example.com/api/v1/instances/website--feature-login
curl -sS -X DELETE -H "Authorization: Bearer $FLEET_TOKEN" \
  -H 'If-Match: "2026-01-01T10:00:00+00:00"' \
  https://fleet.example.com/api/v1/instances/website--feature-login
```

Without the header you get `428`; with an outdated value, `412`. A project's `main` instance cannot be destroyed.

**Limits.** 120 reads and 30 writes per minute per token. Past that you get `429` with a `Retry-After` header: wait that many seconds. Ten failed sign-ins in 15 minutes from one address also answer `429`.

**Errors** are `application/problem+json` with `status`, `code`, `detail` and `request_id`; quote the request id when you look for the cause in the audit log.

The whole API is described by an OpenAPI document at `/api/v1/openapi.json` (any valid `read` token): feed it to a client generator or an agent ([openapi](../reference/16-openapi.md)).

## Look after tokens

```bash
sudo fleet token:list            # add --all to include revoked and expired ones
sudo fleet token:rotate <id> --grace=600
sudo fleet token:revoke <id>
```

- **Rotate** before expiry. The successor has the same scopes and limits; the old token is revoked at once, or after `--grace` seconds (3600 at most) so a client can switch without downtime. Run `token:rotate` again on the successor, not on the old token.
- **Revoke** a token the moment it leaks or its job ends.
- **Needs attention.** `token:list` and the **API tokens** page flag tokens that expire within 14 days (`EXPIRING`) or have not been used for 60 days (`UNUSED`). Revoke the unused ones. `sudo fleet token:remind` (also run by the worker once an hour) writes one `api_token.expiring` audit entry a day for each token in that window and prints a `WARNING` line for it; `token:list` prints the same warning on stderr, so `--json` stays clean.
- **Terminal input alerts.** Every terminal write also publishes a private Mercure update on `/alerts/terminal-write` (instance, pane, token, length, time; never the text) and shows as a count under **Needs attention** on the **API tokens** page, with the text itself in the audit view. If the hub is down the call still goes through; the evidence and the audit entry are the record.
- **Audit.** Creation, rotation, revocation and every refused call (`api.denied`) are in the audit log, and each accepted write is recorded as `api.write` with the token id.
- **Storage.** Keep the secret in a secrets manager or the CI's secret variables, or in an env file only the caller can read. Never commit it to a repository. Fleet stores only a hash, so a lost secret cannot be recovered: rotate it.

## Let a project's orchestrator use it

An agent that runs in one of your instances can watch and manage that project's instances through the API. The product ships a ready-made Claude skill for it, `skills/fleet-api/` (a `SKILL.md` and a client that needs only Python 3). Copy it into the skills folder your agent reads.

Create a token limited to the project, with the scopes the orchestrator needs:

```bash
sudo fleet token:create website-orchestrator \
  -s read -s lifecycle \
  -p website \
  --ip 203.0.113.10 \
  --expires-in 30
```

Add `-s deploy` to let it deploy and redeploy, `-s lifecycle:destroy` to let it destroy, `-s terminal:read` to let it read panes. Give the agent the secret through its environment as `FLEET_API_TOKEN`, with `FLEET_API_URL` set to your Fleet address (https only); never commit either. The skill's commands and rules are in its `SKILL.md`.

## Where to go next

- [The API reference](../reference/15-api.md)
- [Share and access](06-share-and-access.md)
- [Secure the host](08-secure-the-host.md)

## Require a client certificate (optional)

Off by default. To add mutual TLS in front of `/api/v1/*`, put the PEM file of the CA that signs your client certificates at `/etc/caddy/fleet/api-client-ca.pem` (readable by the `caddy` user) and run `sudo fleet host:render --apply`. From then on Caddy asks for a client certificate on the host's main site, verifies one when offered against that CA, and answers `403` to any `/api/v1/*` call without one; a certificate the CA did not sign fails the handshake. The browser UI is unaffected (it offers no certificate and needs none). The bearer token is still required: the certificate is a second factor, not a replacement. To turn it off, remove the file and render again. Test with `curl --cert client.pem --key client.key https://<host>/api/v1/tokens/self -H "Authorization: Bearer flt_..."`.
