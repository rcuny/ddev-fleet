---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-07
Type: documentation
---

# Jira webhooks

A Jira admin webhook can start a fleet deploy by itself: when an issue moves
into a configured status (for example **Dispatched**), the fleet server
receives the event and deploys an instance for that issue, exactly as if you
had submitted the web UI's deploy form. The instance's template decides what
runs next (a template whose `tty2` types `claude "/jira work FLE-3"` starts an
agent on the ticket).

(Bitbucket webhooks, which start a pipeline instead, are covered in
[Bitbucket webhooks](#bitbucket-webhooks) at the end.)

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

# Bitbucket webhooks

FLE-11. A Bitbucket Cloud webhook can start a **custom pipeline** of the
product repo. The use case is the hands-off Renovate workflow: when the
maintainer approves a Renovate PR, or comments `/merge` on it, or when a
Renovate PR's build turns green, the fleet server starts the
`renovate-merge` custom pipeline, which merges the next eligible PR and
re-runs Renovate (see `docs/README-renovate.md`). Bitbucket pipelines cannot
be triggered by a PR approval or comment on their own, so the daemon relays
the event.

The relay is generic (any custom pipeline, any of the events below) and holds
no merge power: its Bitbucket token has the `pipeline:write` scope only, so it
can start pipelines but cannot push or merge. Like Jira webhooks, it is **off
by default**: a server needs a `bitbucket_hooks` rule in `fleet.yml`, a webhook
secret and a pipeline token, all per project.

## How it works

```
Bitbucket Cloud -- POST https://<fleet-domain>/hooks/bitbucket/<project> --> Caddy --> fleet daemon
                                                                              |
Bitbucket Pipelines  <-- POST /2.0/repositories/<repo>/pipelines/  <----------+
 (custom: renovate-merge)       Authorization: Bearer <pipeline:write token>
```

- Caddy forwards `/hooks/*` (so `/hooks/bitbucket/*` as well) without Authelia /
  basic auth and caps the body at 1MB: the existing Jira exemption covers it,
  no Caddy change is needed.
- Bitbucket signs the raw body exactly like Jira: `X-Hub-Signature:
  sha256=<hex HMAC-SHA256(secret, body)>`. The daemon checks it with the
  project's **Bitbucket** secret, which is separate from the Jira one (a Jira
  secret does not open this route).
- The event comes from the `X-Event-Key` header. The delivery id is
  `X-Request-UUID` (unique per delivery, reused by Bitbucket's retries);
  `X-Attempt-Number` is logged.

## Configure the rules (`fleet.yml`)

```yaml
projects:
  example-project:
    git: git@bitbucket.org:org/example-project.git
    bitbucket_hooks:
      - {on_event: "pullrequest:approved", repo: renaud_cuny/ddev-fleet, branch: "renovate/*", action: run-pipeline, pattern: renovate-merge, ref: develop}
      - {on_event: "pullrequest:comment_created", repo: renaud_cuny/ddev-fleet, branch: "renovate/*", comment: "/merge", action: run-pipeline, pattern: renovate-merge, ref: develop}
      - {on_event: "repo:commit_status_updated", repo: renaud_cuny/ddev-fleet, branch: "renovate/*", state: SUCCESSFUL, action: run-pipeline, pattern: renovate-merge, ref: develop}
```

| Key | Required | Meaning |
|---|---|---|
| `on_event` | yes | Bitbucket event key: `pullrequest:<x>` or `repo:<x>` (e.g. `pullrequest:approved`, `pullrequest:comment_created`, `repo:commit_status_updated`). |
| `repo` | yes | `workspace/slug`. The payload's `repository.full_name` must equal it (case-insensitive), else the event is ignored. It is also the repo the pipeline is started on. |
| `branch` | no | `fnmatch` glob (case-sensitive) against the PR's source branch, or a commit status's `refname`. A rule with `branch` never matches an event that has no branch (a commit status whose `refname` is null). |
| `state` | no | Commit-status events only (`repo:commit_status_*`): the status `state`, case-insensitive (`SUCCESSFUL`, `FAILED`, ...). |
| `comment` | no | `pullrequest:comment_*` events only: matches when any line of the comment (stripped, case-insensitive) equals it, e.g. `/merge`. |
| `action` | yes | `run-pipeline` (the only action). |
| `pattern` | yes | The custom pipeline's name in `bitbucket-pipelines.yml` (`custom:` key), e.g. `renovate-merge`. |
| `ref` | yes | The branch to run the pipeline on, e.g. `develop`. |

The first matching rule wins. Validation is strict and fails at load time with
a `projects.<p>.bitbucket_hooks[i]...` message: unknown keys, a malformed
`on_event` or `repo`, a missing `action` / `pattern` / `ref`, an empty
`branch` / `state` / `comment`, and `state` / `comment` on the wrong kind of
event. As with `jira_hooks`, servers on an older release ignore the unknown
`bitbucket_hooks` key, and a rule alone enables nothing: each server also needs
its own secret and token.

## Create the token and the secret

1. **Pipeline token.** In the Bitbucket repo: **Repository settings -> Access
   tokens -> Create Repository Access Token**. Give it the scope **Pipelines:
   Write** and nothing else (no repository write, no pull-request write), so it
   cannot push or merge.
2. On the server, as the `fleet` user, store it. It is read from a piped stdin
   or a hidden prompt, never from the command line, and never printed:

   ```bash
   fleet webhook bitbucket-token example-project          # hidden prompt
   printf '%s' "$TOKEN" | fleet webhook bitbucket-token example-project
   ```

   Running it again replaces the token (use this when the token is rotated).
3. Create the webhook secret:

   ```bash
   fleet webhook secret example-project --source bitbucket
   ```

   The output has the same shape as the Jira one: the secret on its own line,
   then `URL: https://<fleet-domain>/hooks/bitbucket/example-project`, shown
   once. A second run is refused; `--rotate` replaces it (and then the
   Bitbucket webhook's Secret must be updated). Warnings say when the project has
   no `bitbucket_hooks` yet, or no token is stored yet.

Both go into `/srv/fleet/webhooks/secrets.env` (`0600`) next to the Jira
secrets, under distinct keys (`bitbucket:<project>` for the secret,
`bitbucket-token:<project>` for the token; the Jira secret keeps its bare
`<project>` key, so existing files work unchanged). The daemon reads the file
per request, so no restart is needed.

## Bitbucket webhook setup

In the Bitbucket repo: **Repository settings -> Webhooks -> Add webhook**.

| Field | Value |
|---|---|
| URL | the `URL:` line printed above, e.g. `https://ddev1.example.com/hooks/bitbucket/example-project` |
| Secret | the printed secret |
| Triggers | Choose from a full list: Pull request **Approved**, Pull request **Comment created**, Repository **Build status created** and **Build status updated** |

Trigger only what your rules use. One webhook per (repo, server), each with
its own secret.

## Responses

Same order and contract as the Jira route: an unauthenticated caller learns
nothing beyond "this project has hooks", and every response is JSON.

| Case | Code | Body |
|---|---|---|
| Unknown project, no `bitbucket_hooks`, or no Bitbucket secret configured | 404 | `{"error": "not found"}` |
| Body over the limit (Caddy's 1MB first; the daemon's own 1 MiB cap) | 413 | `{"error": "payload too large"}` |
| Missing / malformed / wrong signature | 401 | `{"error": "unauthorized"}` |
| Body not JSON or not an object, no `X-Event-Key`, or a required field missing (`repository.full_name`, `pullrequest.id`, `comment.content.raw`, `commit_status.state`) | 400 | `{"error": "<reason>"}` |
| Ignored (other event, or no rule matches the repo / branch / state / comment) | 200 | `{"result": "ignored", "reason": "..."}` |
| Duplicate delivery (same `X-Request-UUID`) | 200 | `{"result": "duplicate"}` |
| Rule matched but no Bitbucket token is stored | 422 | `{"error": "<reason>"}` |
| Pipeline started | 200 | `{"result": "triggered", "pipeline": <build number>}` |
| Bitbucket refused the call, timed out or is unreachable (8 s timeout), or the call failed in any other way | 502 | `{"error": "pipeline trigger failed"}` |
| `fleet.yml` fails to load | 500 | `{"error": "internal error"}` |

The 502 and 500 are 5xx on purpose: Bitbucket retries them. The 502 log entry
and journal line carry the upstream HTTP status code only, never the token or
any response content. `pipeline` is the new pipeline's build number (its uuid
if the response has none).

## Deduplication

The daemon claims the delivery id (per project, in its own namespace under
`/srv/fleet/webhooks/seen/`, so it cannot collide with a Jira id) before it
starts the pipeline, and releases the claim again if the start fails for any reason. A
Bitbucket retry after a 502 therefore starts the pipeline, while a retry of a
delivery that did start one answers `duplicate`. A 422 never consumes the id.
A delivery without `X-Request-UUID` is processed without dedupe. Different
events for the same PR (approve, then the green build) are different
deliveries and each starts a pipeline: the `renovate-merge` pipeline is
written to be idempotent.

## Delivery log

Every authenticated request (one that passed the 404 and signature checks)
appends one JSON line to `/srv/fleet/logs/webhooks/bitbucket.jsonl`, next to the
Jira log (no secrets, no bodies): `ts, project, delivery_id, attempt, event,
repo, pr, branch, state, result, reason, pipeline`. Header values are truncated
to 64 characters. 401s go to the daemon journal only
(`journalctl -u fleet | grep 'bitbucket webhook'`). Read it with:

```bash
fleet webhook log --source bitbucket [--project <p>] [-n 20]
```

One line per entry: `ts  project  event  repo#pr  branch  result  pipeline-or-reason`.
