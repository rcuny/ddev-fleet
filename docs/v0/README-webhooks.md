---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-18
Type: documentation
---

# Jira webhooks

> **v0.x (Python) documentation** — the 1.x port re-implements this in milestone M0.08; the files and commands it names (`src/fleet/`, `fleet`, `bootstrap.sh`) live on the `main` branch, not on `develop`.

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
| URL | the `URL:` line printed above, e.g. `https://fleet.example.com/hooks/jira/example-project` |
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
`docs/v0/operations.md`); without it the instance still deploys, but nothing is
typed and no agent starts. Check with `systemctl status fleet-tmux`.

## Delivery log

Every authenticated request (one that passed the 404 and signature checks)
appends one JSON line to `/srv/fleet/logs/webhooks/jira.jsonl` (no secrets, no
bodies): `ts, project, delivery_id, retry, event, issue, from, to, result,
reason, instance, job, action, template, label, rule`. The last four (FLE-30)
say which rule matched and what it ran, from the rule's own configuration:
`action` (`deploy`), `template`, `label` (the label of the instance really
deployed) and `rule` (a short selector string such as `on_status=Dispatched`);
they are empty when no rule matched or the delivery failed earlier, and lines
written before FLE-30 do not have them. The `delivery_id` and `retry` header values are
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
product repo (and, since FLE-27, **deploy a pushed branch**: see
[Deploy on branch push](#deploy-on-branch-push-action-deploy)). The use case is the hands-off Renovate workflow: when the
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
      - {on_event: "pullrequest:approved", repo: example-workspace/ddev-fleet, branch: "renovate/*", action: run-pipeline, pattern: renovate-merge, ref: develop}
      - {on_event: "pullrequest:comment_created", repo: example-workspace/ddev-fleet, branch: "renovate/*", comment: "/merge", action: run-pipeline, pattern: renovate-merge, ref: develop}
      - {on_event: "repo:commit_status_updated", repo: example-workspace/ddev-fleet, branch: "renovate/*", state: SUCCESSFUL, action: run-pipeline, pattern: renovate-merge, ref: develop}
```

| Key | Required | Meaning |
|---|---|---|
| `on_event` | yes | Bitbucket event key: `pullrequest:<x>` or `repo:<x>` (e.g. `pullrequest:approved`, `pullrequest:comment_created`, `repo:commit_status_updated`). |
| `repo` | yes | `workspace/slug`. The payload's `repository.full_name` must equal it (case-insensitive), else the event is ignored. It is also the repo the pipeline is started on. |
| `branch` | no (required for `deploy`) | `fnmatch` glob (case-sensitive) against the PR's source branch, a commit status's `refname`, or, for `repo:push`, the name of the pushed branch. A rule with `branch` never matches an event that has no branch (a commit status whose `refname` is null). Required for `action: deploy`. |
| `state` | no | Commit-status events only (`repo:commit_status_*`): the status `state`, case-insensitive (`SUCCESSFUL`, `FAILED`, ...). |
| `comment` | no | `pullrequest:comment_*` events only: matches when any line of the comment (stripped, case-insensitive) equals it, e.g. `/merge`. |
| `action` | yes | `run-pipeline` or `deploy` (see [Deploy on branch push](#deploy-on-branch-push-action-deploy)). |
| `pattern` | `run-pipeline` only | The custom pipeline's name in `bitbucket-pipelines.yml` (`custom:` key), e.g. `renovate-merge`. |
| `ref` | `run-pipeline` only | The branch to run the pipeline on, e.g. `develop`. |
| `template` | `deploy` only | The project template to deploy (must exist in the project). |
| `label` | `deploy` only | A fixed instance label (slugified), e.g. `pw-regression`. Exclusive with `label_prefix` / `strip_suffix`. |
| `label_prefix` | `deploy` only; required for a derived label | Prepended to the pushed branch (after `strip_suffix` is removed) to form the label. Required whenever the label is derived (without `label`): a bare branch slug could equal the id of a hand-deployed or Jira-hook instance, which the deploy would replace. |
| `strip_suffix` | `deploy` only | Literal text removed from the end of the pushed branch. The `branch` glob must end with it (`*--pw-run` / `--pw-run`). Needs `label_prefix` alongside. |

The first matching rule wins. Validation is strict and fails at load time with
a `projects.<p>.bitbucket_hooks[i]...` message: unknown keys, a malformed
`on_event` or `repo`, a missing `action` / `pattern` / `ref`, an empty
`branch` / `state` / `comment`, and `state` / `comment` on the wrong kind of
event (the `action: deploy` rules have their own checks, below). Servers on a
release before v0.10.0 ignore the unknown `bitbucket_hooks` key, and a rule
alone enables nothing: each server also needs its own secret (and, for
`run-pipeline`, token). A release from v0.10.0 up to the one that added
`action: deploy` refuses a `fleet.yml` that contains a deploy rule (unknown
keys), so upgrade every server that pulls that `fleet.yml` first (details in
the next section).

## Deploy on branch push (`action: deploy`)

FLE-27. A `repo:push` rule deploys the pushed branch instead of starting a
pipeline. It lets a push to a branch name with a marker suffix start a CI run
on a fleet server (the Playwright CI: `git push origin HEAD:feature/x--pw-run`).

**Requires fleet v0.14.0 or later on every server that loads the `fleet.yml`.**
Pushing a `fleet.yml` that contains a deploy rule (`ddev push-config`) to a
server running v0.10.0 to v0.13.1 makes `Registry.load` fail there on the
unknown `bitbucket_hooks` keys, which breaks every fleet command and every
webhook route on that server. Servers older than v0.10.0 (before
`bitbucket_hooks` and the `post_deploy` mapping existed) cannot load such a
config either. Upgrade the product first, then push the config.

```yaml
bitbucket_hooks:
  - {on_event: "repo:push", repo: acme/website, branch: "*--pw-run", action: deploy, template: ci-pw, label_prefix: "pw-", strip_suffix: "--pw-run"}
  - {on_event: "repo:push", repo: acme/website, branch: develop, action: deploy, template: ci-baseline, label: pw-regression}
```

- Bitbucket sends one `repo:push` per push with one entry per ref in
  `push.changes`. Each **created or updated branch** is matched against the
  rules (first match wins, per branch); branch deletions, tags and other refs
  are skipped, so a push that only deletes a branch is `ignored`. The
  `branch` glob is anchored: `*--pw-run` does not match `x--pw-run-ml`.
- The instance label is the rule's `label`, or `label_prefix` + the branch
  minus `strip_suffix`, slugified (`feature/ABC-1898-x--pw-run` ->
  `pw-feature-abc-1898-x`, instance `website--pw-feature-abc-1898-x`). When
  `<project>--<label>` would exceed 63 characters the label is cut and ends in
  `-` plus the first 6 hex of the SHA-1 of the branch name, so the id still
  fits and the same branch always lands on the same id. The fitting is
  alias-aware: a project with `additional_hostnames` also needs
  `<hostname>-<instance id>` to fit in 63 characters, so the id is shortened by
  the longest `<hostname>-` prefix, and a long branch can still deploy.
- Two different branch names can slugify to the same base instance id (for
  example `Feature/A_b--pw-run` and `feature/a/b--pw-run`): within one push
  only the first deploys; across pushes, the second gets a suffixed instance
  (see below).
- **Fixed `label` vs `label_prefix`: what a re-push does.**
  - A rule with a fixed `label` (for example a `develop` baseline) keeps
    exactly **one** instance: every push is a deploy with `replace=True` that
    destroys the existing instance (and its tmux window, which kills a run in
    flight) and rebuilds it under the same id.
  - A rule with `label_prefix` never overwrites. If `<project>--<label>`
    already exists, the push deploys a **new** instance `<label>-1`, then
    `<label>-2`, and so on (the same allocation as a plain `fleet deploy`).
    The id is picked when the webhook is received, so the answer, the delivery
    log, the job and `logs/<instance>/deploy.log` all name the instance that
    will really be created. Old instances are kept for debugging; clean them up
    by hand with `fleet destroy`. Room for a `-999` suffix is reserved when
    the label is fitted to 63 characters, so a long branch still deploys.
  - Either way, if the deploy of the same id is still running, the job waits
    for its lock (retries every 15 s, up to 45 minutes) instead of failing.
- **Label-collision guard.** A derived-label deploy that would land on the
  instance id of a fixed-`label` deploy rule is ignored with reason
  `collides with a fixed-label rule` (a `*--pw-run` branch must not rebuild the
  baseline instance). Each such branch gets its own delivery-log line; if every
  target of the push collides, the answer is 200 `ignored`.
- **Reserved prefix.** The derived-label prefix namespace (e.g. `pw-`) is
  reserved for webhook-owned instances: a hand-deployed instance under that
  prefix is never overwritten, but it makes the push allocate a `-1` suffix.
  A fixed-`label` instance is rebuilt in place, whoever created it.
- **Jobs live in memory.** Deploy jobs are held by the daemon process only: a
  daemon restart drops a queued or lock-waiting job (Bitbucket already got its
  200, so it will not retry). Push the branch again to retry.
- **Waiting occupies a job slot.** A push while the same instance is still
  deploying waits for its lock (`retry_while_locked`, up to 45 minutes) and
  holds one of the daemon's job slots for the whole wait.
- Validation (at load time): `deploy` only with `on_event: repo:push`; `branch`
  and `template` required; exactly one of `label` or `label_prefix` /
  `strip_suffix` (a derived label requires a non-empty `label_prefix`);
  `pattern` / `ref` are not allowed; `run-pipeline` is not
  allowed on `repo:push`.
- A deploy rule needs **no Bitbucket token** (only the webhook secret).
- Targets are resolved before the delivery is claimed: a branch that cannot be
  turned into a valid instance (for example it slugifies to nothing) answers
  422 and nothing is deployed, so Bitbucket's retry can succeed once fixed.
  One delivery can start several deploys (one per distinct instance id).

Webhook trigger to enable in Bitbucket: Repository **Push**.

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
| URL | the `URL:` line printed above, e.g. `https://fleet.example.com/hooks/bitbucket/example-project` |
| Secret | the printed secret |
| Triggers | Choose from a full list: Pull request **Approved**, Pull request **Comment created**, Repository **Build status created** and **Build status updated**, Repository **Push** (deploy rules) |

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
| Body not JSON or not an object, no `X-Event-Key`, or a required field missing (`repository.full_name`, `pullrequest.id`, `comment.content.raw`, `commit_status.state`, `push.changes`, a pushed branch's `new.name` / `new.target.hash`) | 400 | `{"error": "<reason>"}` |
| Ignored (other event, a push without a created/updated branch, or no rule matches the repo / branch / state / comment) | 200 | `{"result": "ignored", "reason": "..."}` |
| Duplicate delivery (same `X-Request-UUID`) | 200 | `{"result": "duplicate"}` |
| `run-pipeline` rule matched but no Bitbucket token is stored, or a `deploy` target cannot be resolved | 422 | `{"error": "<reason>"}` |
| Pipeline started | 200 | `{"result": "triggered", "pipeline": <build number>}` |
| Push deploys started | 200 | `{"result": "accepted", "deploys": [{"instance": "...", "job": "..."}]}`, plus top-level `instance` and `job` when there is exactly one deploy |
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
repo, pr, branch, state, result, reason, pipeline, action, template, label,
rule`. Push deliveries add `sha`, `instance` and `job` and write one line per
deploy. The last four (FLE-30) name the rule that matched and what it ran:
`action` (`deploy` / `run-pipeline`), `template` (deploys only), `label` (the
label of the deployed instance, `-N` suffix included) and `rule` (a short
selector string, e.g. `on_event=repo:push branch=*--pw-run` or
`on_event=pullrequest:approved branch=renovate/* ref=develop pattern=renovate-merge`).
They come from the rule in `fleet.yml`, never from the request; a push deploy
skipped by the label-collision guard still names the rule it matched. Lines
written before FLE-30 do not have them. Header values are truncated
to 64 characters. 401s go to the daemon journal only
(`journalctl -u fleet | grep 'bitbucket webhook'`). Read it with:

```bash
fleet webhook log --source bitbucket [--project <p>] [-n 20]
```

One line per entry: `ts  project  event  repo#pr  branch  result  pipeline-or-reason`.

# Webhooks page (web UI)

The dashboard has a **Webhooks** page (nav: Instances, Secrets, Webhooks; `GET /webhooks`)
that shows the latest deliveries of **both** sources without ssh and `fleet webhook log`.

- **Read-only.** The page has no form that changes anything; it cannot trigger, replay or
  delete a delivery. It sits behind the same Caddy login as the rest of the dashboard
  (Caddy exempts only `/hooks/*` and the token-gated `/ws/*`).
- **Data.** It reads `/srv/fleet/logs/webhooks/jira.jsonl` and `bitbucket.jsonl`, the files
  described under "Delivery log" above, merges them and shows the newest first (by `ts`).
  Nothing is stored besides those files. A missing or empty file shows an empty state; torn
  or non-JSON lines are skipped. Requests rejected for a bad signature (401) are not logged,
  so they do not appear (see the journal).
- **Columns.** Time (UTC), source, project, event, ref (the Jira issue and status
  transition, or the Bitbucket branch with PR number / short commit / state), rule (the
  `rule` log field), action (`action` and `template`, then the deployed `instance` with its
  `label` and `job`, or the triggered `pipeline`), result, reason and the `delivery_id`. The
  instance is a link to the instance's URL while it still exists on this host, plain text
  once it is destroyed. A result of `accepted` means the deploy job was started, not that it
  finished (see the instance's `deploy.log`); `triggered` means a pipeline was started.
- **Filters.** Source, project and result (query parameters `source`, `project`, `result`,
  plus `limit`: the default is the last 100 deliveries, at most 500). The project options come
  from the deliveries read; the result options are `accepted`, `triggered`, `ignored`,
  `duplicate`, `error`, `bad_request` plus any other value found. Filters apply to the latest
  part of each log (10 times the limit, at least 1000 lines per file), not to its whole history.
  An unknown value simply matches nothing. The table partial is
  `GET /ui/webhooks/table` (same parameters); the **Refresh** button re-fetches it with the
  current filters. There is no auto-refresh.
- **Hide ignored hooks.** A checkbox in the filter form (unchecked by default; query
  parameter `hide_ignored=1` on the page and on the partial) drops the deliveries whose
  result is `ignored` before the limit is applied, so you still get up to `limit` other rows.
- **Log fields it relies on.** `instance`, `delivery_id` (Jira
  `X-Atlassian-Webhook-Identifier`, Bitbucket `X-Request-UUID`) and, since FLE-30, `action`,
  `template`, `label` and `rule` (see the two "Delivery log" sections). Entries written before
  a field existed render with `-` in its column. Every logged value is HTML-escaped.
