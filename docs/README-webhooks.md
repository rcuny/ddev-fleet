---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-06
Type: documentation
---

# Jira webhooks

A Jira admin webhook can start a fleet deploy by itself: when an issue moves
into a configured status (for example **Dispatched**), the fleet server
receives the event and deploys an instance for that issue, exactly as if you
had submitted the web UI's deploy form. The instance's template decides what
runs next (a template whose `tty2` types `claude "/jira work FLE-3"` starts an
agent on the ticket).

v1 supports one action, `deploy`. Webhooks are **off by default**: a server
needs both a `jira_hooks` rule in `fleet.yml` and a webhook secret.

## How it works

```
Jira Cloud -- POST https://<fleet-domain>/hooks/jira/<project> --> Caddy --> fleet daemon
```

- Caddy forwards `/hooks/*` to the daemon **without** Authelia / basic auth
  (Jira cannot log in) and caps the body at `max_size 1MB` (1,000,000 bytes;
  Caddy answers 413). The daemon authenticates the
  request itself: HMAC-SHA256 of the raw body with the project's secret,
  compared in constant time against the `X-Hub-Signature: sha256=<hex>` header.
- The match is on the status **transition** in Jira's changelog, not the
  issue's current status, so editing an issue that already sits in
  Dispatched deploys nothing.
- The deploy is a normal deploy job: same `logs/<instance>/deploy.log`, same
  tmux hook, same auth defaults (in Authelia mode the project's `users:`
  apply). The issue key becomes the deploy label, so `FLE-3` yields
  `<project>--fle-3`, and `[[issue-id]]` resolves from the label via the
  project's `issue_id_regexp`.

## Configure the rule (`fleet.yml`)

```yaml
projects:
  example-project:
    git: git@example.com:org/example-project.git
    issue_id_regexp: FLE-[0-9]+
    templates:
      jira-work: {}
    jira_hooks:
      - on_status: Dispatched   # status the issue moves INTO (case-insensitive)
        action: deploy          # the only valid action in v1
        template: jira-work     # must exist in this project's templates
        # branch: develop       # optional; default = the project's default_branch
```

Validation is strict and fails loudly at load time: unknown keys in a rule,
an unknown action, a missing or unknown template, a duplicate `on_status`
(case-insensitive) or a `jira_hooks` that is not a list are all errors.
When `issue_id_regexp` is set, an issue key that does not fully match it is
ignored. `fleet.yml` is shared by every server, so a rule alone enables
nothing: each server also needs its own secret (next section). Servers on an
older release ignore the unknown `jira_hooks` key.

## Create the secret

On the server, as the `fleet` user:

```bash
fleet webhook secret example-project
```

Output:

```
<the secret, on its own line>
URL: https://<fleet-domain>/hooks/jira/example-project
Shown once: paste the secret into the Jira webhook's Secret field now; ...
```

The secret is stored in `/srv/fleet/webhooks/secrets.env` (directory `0700`,
file `0600`, one `<project>=<secret>` line per project) and is **shown only
once**. A second run is refused; `fleet webhook secret <project> --rotate`
replaces it, and then Jira's Secret field must be updated or every delivery
gets 401. The daemon reads the file per request, so rotation needs no restart.
Other projects' secrets are untouched. An unknown project is an error. A
project with no `jira_hooks` still gets a secret, with a warning that the
route answers 404 until a rule exists.

## Jira admin setup

Jira Cloud: **Settings -> System -> WebHooks -> Create a WebHook**.

| Field | Value |
|---|---|
| URL | the `URL:` line printed above, e.g. `https://ddev1.example.com/hooks/jira/example-project` |
| Secret | the printed secret |
| Events | Issue -> **updated** |
| JQL | `project = FLE` (optionally `AND status = Dispatched`; the daemon re-checks the transition regardless) |

One webhook per (fleet project, server), each with its own secret.

## Responses

The checks run in this order, so an unauthenticated caller learns nothing
beyond "this project has hooks". Jira only counts **200** as success and never
retries a 4xx (it does retry 5xx).

| Case | Code | Body |
|---|---|---|
| Unknown project, no `jira_hooks`, or no secret configured | 404 | `{"error": "not found"}` |
| Missing / malformed / wrong signature | 401 | `{"error": "unauthorized"}` |
| Body not JSON, not an object, or no `issue.key` on an issue event | 400 | `{"error": "<reason>"}` |
| Ignored (other event, no status change, key does not match `issue_id_regexp`, no matching rule) | 200 | `{"result": "ignored", "reason": "..."}` |
| Duplicate delivery | 200 | `{"result": "duplicate"}` |
| Rule matched but the deploy target cannot be resolved | 422 | `{"error": "<reason>"}` |
| Accepted | 200 | `{"result": "accepted", "instance": "...", "job": "..."}` |
| `fleet.yml` fails to load | 500 | `{"error": "internal error"}` |

Body limits: Caddy rejects bodies over `max_size 1MB` (1,000,000 bytes) with a
413 before they reach the daemon. The daemon enforces its own 1 MiB
(1,048,576 bytes) cap as defence in depth, with the same 413
`{"error": "payload too large"}`; in practice Caddy's lower limit always wins.

The 500 is the one deliberate 5xx: the registry error is logged in the daemon
journal only (never sent to the unauthenticated caller), and Jira's retry
succeeds once `fleet.yml` is fixed.

## Deduplication and re-dispatch

- **Dedupe.** Jira retries failed deliveries up to 5 times, reusing the same
  `X-Atlassian-Webhook-Identifier`. The daemon records that id (per project)
  in an atomic marker file under `/srv/fleet/webhooks/seen/` right before it
  submits the job, so a retry answers `duplicate` and never deploys twice,
  even across a daemon restart. Only matched deliveries consume an id, and
  markers older than 7 days are pruned. A delivery without the header is
  processed without dedupe.
- **Re-dispatch.** Moving the same ticket into Dispatched a second time (a
  new, deliberate event with a new id) is **not** a duplicate: deploy's
  auto-suffix applies, so the first instance is `<project>--fle-3` and the
  next `<project>--fle-3-1`. The response's `instance`, the delivery log's
  `instance` and the job's `log_path` all refer to the *requested* id
  (`<project>--fle-3`). When the suffix applies, the actual instance, and its
  `deploy.log`, live under the suffixed id (`logs/<project>--fle-3-1/deploy.log`),
  exactly as with a web-UI deploy. Continuity of work
  comes from git: the agent checks out the existing `feature/FLE-3-...` branch
  if the first run pushed it.

## The tmux requirement

A template's `tty1` / `tty2` commands are typed into the fleet tmux session by
the deploy hook. That only happens when `fleet-tmux.service` is running (see
`docs/operations.md`); without it the instance still deploys, but nothing is
typed and no agent starts. Check with `systemctl status fleet-tmux`.

## Delivery log

Every authenticated request (one that passed the 404 and signature checks)
appends one JSON line to `/srv/fleet/logs/webhooks/jira.jsonl` (no secrets, no
bodies): `ts, project, delivery_id, retry, event, issue, from, to, result,
reason, instance, job`. The `delivery_id` and `retry` header values are
truncated to 64 characters. Requests rejected for a bad or missing signature
(401) are **not** written there, so unauthenticated callers cannot grow the
file; they are logged as a warning (project and truncated delivery id only) in
the daemon journal: `journalctl -u fleet | grep 'jira webhook'`. A failed
write to the delivery log is also only a journal warning; it never changes the
response. Read the log with:

```bash
fleet webhook log [--project <p>] [-n 20]
```

One line per entry: `ts  project  issue  from->to  result  instance-or-reason`.
Empty log: `no webhook deliveries logged`. A failed deploy shows up in the
instance's `deploy.log` and the job state, not in the webhook log: the route
answers as soon as the job is submitted.

## Upgrading: re-run the Caddy role

The `/hooks/*` exemption and the body cap live in the Caddyfile template, so
after upgrading a server to a release that includes webhooks, re-apply the
Caddy role (never the full `site.yml`):

```bash
cd /opt/ddev-fleet/ansible
sudo ansible-playbook caddy-only.yml
```

Until then Caddy still puts Authelia / basic auth in front of the route and
Jira's deliveries are redirected to a login page.
