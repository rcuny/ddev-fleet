# HTTP API: tokens and authentication

The API lives under `/api/v1/` on the same host name as the web interface and answers JSON. This page covers the foundation: how a caller authenticates and what a token may do. The endpoints themselves (hosts, instances, jobs) are added release by release; today `GET /api/v1/tokens/self` is the only one.

> **Status:** the firewall, the token store and the checks described here are in the app. Reaching `/api/v1/` from outside additionally needs the Caddy route that bypasses the login gate for that path only (a separate change). Until then the login portal answers first.

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
| `terminal:read`, `terminal:write` | Read or type into an agent's pane (later release). `terminal:write` cannot be combined with `deploy` or `lifecycle`. |

There is no wildcard scope and no scope implies another. Settings, secrets, users and tokens are never reachable with a token.

## Limits

| Limit | Value |
|---|---|
| Reads | 120 per minute per token |
| Writes | 30 per minute per token |
| Failed authentications | 10 per 15 minutes per client address; beyond that the address gets `429` before its token is looked at |

A `429` carries `Retry-After`.

## Responses

Every response carries `X-Request-Id` and `Cache-Control: no-store`. Errors are `application/problem+json` (RFC 9457): `type`, `title`, `status`, `detail`, `code` and `request_id`. Codes so far: `unauthorized`, `forbidden`, `scope_missing`, `project_not_allowed`, `rate_limited`, `not_found`, `method_not_allowed`, `internal_error`. An unauthenticated caller gets `401` for every path under `/api/`, existing or not.

## `GET /api/v1/tokens/self`

Any valid token. Returns its own `id`, `name`, `description`, `prefix_hint`, `scopes`, `projects`, `instance_globs`, `ip_allow`, `created_by`, `created_at`, `expires_at`, `expires_in_seconds`, `last_used_at`, `last_used_ip` and `rotated_from`, so a client can check its health before it expires. The secret and its hash are never returned. `last_used_at` is written at most once a minute.

## Audit

`api_token.create`, `api_token.rotate`, `api_token.revoke` and `api_token.expire` record token changes; `api.denied` records refused calls (authentication, scope, project, address). The actor of a token's calls is `token:<id>:<name>`. A secret is never written to the log.
