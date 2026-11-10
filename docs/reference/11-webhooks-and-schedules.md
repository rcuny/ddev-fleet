# Webhooks and schedules

Instances can be created by events you choose: a Jira issue enters a status, a branch is pushed, or a clock strikes 03:00. This page is the lookup for how Fleet authenticates, matches and deduplicates those events and how schedules fire. To set one up, follow [automate deploys](../guides/03-automate-deploys.md); for CI pipelines see [run CI](../guides/04-run-ci.md). Both features are for administrators and queue the same jobs a person would.

## Webhooks

### Endpoints and authentication

```
POST https://<fleet-domain>/hooks/jira/<project>
POST https://<fleet-domain>/hooks/bitbucket/<project>
```

| Topic | Rule |
|---|---|
| Gate | Caddy lets `/hooks/*` through without sign-in and caps the body at 1 MB. |
| Signature | HMAC-SHA256 of the body in `X-Hub-Signature: sha256=...`. A receiver answers only once it has a secret. |
| Secrets | Jira and Bitbucket have separate ones. `fleet webhook:secret` creates one; it is shown once. `--rotate` replaces it and the old one stops at once. |
| Pipeline token | `fleet webhook:token` stores a Bitbucket repository token (scope **Pipelines: Write** only) for `run-pipeline` rules. |

Commands: `fleet webhook:secret`, `webhook:rule:add`, `webhook:rule:remove`, `webhook:token`, `webhook:list`, `webhook:log` ([01-cli.md](01-cli.md)).

### Rules and actions

Every field is validated when the rule is added. The first matching rule wins.

| Source | A rule matches on |
|---|---|
| Jira | An issue moving into a status (`--on-status`). A key outside the project's issue id pattern is ignored. |
| Bitbucket | The event (`X-Event-Key`, e.g. `repo:push`), the repository `workspace/slug`, optionally a branch glob, a commit status state or a comment line. Each created or updated branch in a push is matched on its own; deletions and tags are skipped. |

| Action | What it does |
|---|---|
| `deploy` | Queues a deploy of the rule's template, trigger `webhook`. Jira: the lowercased issue key is the label (`WEBSITE-3` gives `website--website-3`), the branch is `--branch` or the project default. Moving the ticket in again allocates `website-3-1`. Bitbucket: the label comes from the pushed branch. |
| `run-pipeline` | Bitbucket only. Starts the custom pipeline `--pattern` on `--ref` (default: project default branch). |

### Label options (Bitbucket deploy rules)

| Option | Effect |
|---|---|
| `--label` | One fixed instance, redeployed in place on every push (switched to the rule's branch if it differs). |
| `--label-prefix`, `--strip-suffix` | Label = prefix + branch minus suffix, slugified. Never overwrites: a taken label gets `-1`, `-2`. |
| `--reuse` | A push to a branch whose instance exists redeploys it in place. For CI. Jira rules do not take it. |
| Shortening | If `<project>--<label>` would pass 59 characters (aliases and a `-999` suffix included), the label is cut and ends in `-` plus 6 hex of the SHA-1 of the branch name, so one branch always gets one id. |
| Collision guard | A derived label equal to a fixed-label rule's label is skipped as `collides with a fixed-label rule` (a `*--pw-run` branch must not rebuild a baseline). Each skipped branch gets an `ignored` row. |

A busy instance (job queued or running) does not drop a push to a fixed-label or `--reuse` rule. The redeploy is remembered for that instance (one pending request, the latest push wins: branch, template and requester are overwritten) and the delivery is `accepted` with the note `redeploy pending: ...`. When the active job ends, whatever its outcome, one follow-up `redeploy` job is queued, trigger `webhook`, and appears in the job list. Several pushes during one job therefore give exactly one follow-up. A redeploy always pulls the branch tip, so it builds the newest commit. If another job takes the instance first, the request keeps waiting; if the instance or its template is gone, it is dropped and logged. A deploy that allocates a free label is never busy. The pending request lives in the `pending_redeploy` table, not in `job`: the one active job per instance rule is unchanged.

### HTTP answers

| Status | Meaning |
|---|---|
| 404 | No such project, or no secret yet. |
| 401 | Missing or wrong signature. A warning in the application log, never a delivery row. |
| 400 / 413 / 422 | Not a JSON object or no issue key / body over 1 MiB / the instance cannot be named (nothing consumed, a retry can succeed). |
| 200 `ignored` | No rule matched. |
| 200 `duplicate` | Delivery already handled. |
| 200 `accepted` / `failed` | A rule matched; the action ran, or could not (template gone, instance busy, no pipeline token). |

After a delivery is claimed the sender always gets 200, so a refusal is never retried. To recover from `failed`, cause a fresh event (move the issue out and back in, push again). Nothing returns a 500 or a stack trace.

### Deduplication and the log

| Mechanism | Detail |
|---|---|
| Delivery id | `X-Atlassian-Webhook-Identifier` (Jira) or `X-Request-UUID` (Bitbucket). A matched delivery consumes its id, so retries answer `duplicate`. Ids are freed after 7 days. No header, no dedup. |
| Replay hash | The id header is not signed, so a matched delivery also stores the SHA-256 of its body. The same body within one hour is `duplicate`. A body replayed after that hour is accepted. |
| Failed actions | The id and hash are claimed before the action runs, so a `failed` delivery is not retried either. |
| Delivery log | One row per authenticated request: time, source, event, delivery id, subject, change, rule, decision, result, outcome (job and note). Kept 60 days. On the Integrations tab, `/admin/webhooks`, or `fleet webhook:log`. A project's delivery rows outlive the project until pruned. |

### Sender setup

- **Jira Cloud:** Settings, System, WebHooks, Create a WebHook. URL = the Jira address, Secret = the secret, Events = Issue updated, JQL e.g. `project = WEBSITE`.
- **Bitbucket:** Repository settings, Webhooks. The Bitbucket address, the secret, and the events your rules name.

## Schedules

A schedule is a cron expression, an action (`redeploy` is the only one) and a target instance. Manage them on **Schedules** (`/admin/schedules`) or with `fleet schedule:list`, `create`, `update`, `delete`, `pause`, `resume`, `run`, `history` ([01-cli.md](01-cli.md)).

| Rule | Detail |
|---|---|
| Cron | Exactly five fields; macros such as `@daily` are refused. Read in the schedule's IANA timezone. |
| Target | May not exist yet: runs are `skipped` (badge **Target missing**) until it does. |
| Busy target | A queued or running job makes the run `skipped`; it is not retried. |
| Results | `claimed` (job queued), `started`, `succeeded`, `failed`, `skipped`, `missed`. |
| Once only | Each occurrence is claimed once, so two racing ticks fire one job. |

| Exit code | Meaning |
|---|---|
| `0` | Done. |
| `1` | A value was refused (`--cron`, `--timezone`, `--instance`). |
| `2` | Not allowed now (no `--yes`; `run` on a busy or missing instance). |
| `3` | Not found. |

**Ticker.** The `fleet-scheduler` service ticks every 30 seconds (`systemctl status fleet-scheduler`). Jobs run on the normal worker, so both must be running.

**Catch-up.** An occurrence at most 90 seconds old is a normal run. After downtime only the most recent missed occurrence fires, as a `catch-up` run, and only when it is at most 60 minutes old; older ones are `missed`. Resuming a paused schedule or changing its timing never catches up.

**Daylight saving.** A time the spring-forward skips fires once, shifted past the gap. A time the fall-back repeats fires at its first pass when the expression names its hours (`30 2 * * *`); an every-hour expression keeps firing through the repeated hour.

**Orphan runs.** The ticker claims an occurrence, then queues the job. If it dies in between, the run stays `claimed` with no job. After 5 minutes the next tick marks it `failed` ("The ticker stopped before the job was queued.") and never fires it again.

## Learn more

- [Automate deploys](../guides/03-automate-deploys.md)
- [Run CI](../guides/04-run-ci.md)
- [Troubleshooting](13-troubleshooting.md)
