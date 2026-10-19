---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Webhooks (1.x)

Jira and Bitbucket can call Fleet when something happens. The signed endpoints, per-project secrets, the rules that decide
what an event means, the actions behind them (deploy an instance, start a Bitbucket pipeline) and a delivery log
(milestones M0.08a and M0.08b).

A matched rule **acts**: a Jira "Dispatched" queues a deploy job, a Bitbucket push queues a deploy per pushed branch, a
`run-pipeline` rule calls the Bitbucket Pipelines API. What happened is in the delivery row (the **Outcome** column) and in
the answer sent back to Jira or Bitbucket. The action behind a rule is `App\Fleet\Webhooks\WebhookAction`
(`RuleWebhookAction`: `DeployWebhookAction` for a deploy rule, `PipelineWebhookAction` for a run-pipeline rule).

## The addresses

Every project has two receivers, shown with copy buttons on **Webhooks** (`/admin/webhooks`, all projects) and on the
project's **Webhooks** tab:

```
https://<fleet-domain>/hooks/jira/<project>
https://<fleet-domain>/hooks/bitbucket/<project>
```

Caddy lets `/hooks/*` through without the login gate (Jira and Bitbucket cannot log in) and caps the body at 1 MB. The
application authenticates each call itself, by its signature. The Caddy configuration of an installed host already
contains both, so no change is needed.

## Secrets

A receiver answers only once it has a secret: the HMAC key the sender signs the body with
(`X-Hub-Signature: sha256=<hex HMAC-SHA256(secret, raw body)>`). Create it with **Create secret** on the page, or on the
host:

```bash
fleet webhook:secret <project> [--source=jira|bitbucket]
```

The secret is **shown once**, in the answer to that request, and can never be read again: not in the pages, the commands,
the logs or the database (it is stored sealed with the host's secret key). `--rotate` (or **Rotate secret** on the page)
replaces it, and the old one stops working at once, so update the sender straight after. The command prints `url:` and
`secret:` on labelled lines (`--json` for scripts): read the field by name, never by line number.

Jira and Bitbucket have separate secrets, so a Jira secret does not open the Bitbucket receiver.

## Rules

A rule says what an event means. They are added on the host for now (editing them in the browser follows); the page lists them.

```bash
# Jira: an issue moving INTO a status (the changelog transition, not the issue's current status) deploys a template
fleet webhook:rule:add blog --on-status=Dispatched --template=jira-work [--branch=develop]

# Bitbucket: start a custom pipeline, or deploy a pushed branch
fleet webhook:rule:add blog --source=bitbucket --action=run-pipeline --on-event=pullrequest:approved \
  --repo=acme/blog --match-branch='renovate/*' --pattern=renovate-merge --ref=develop
fleet webhook:rule:add blog --source=bitbucket --action=deploy --on-event=repo:push \
  --repo=acme/blog --match-branch='feature/*' --template=work
fleet webhook:rule:add blog --source=bitbucket --action=deploy --on-event=repo:push \
  --repo=acme/blog --match-branch='*--pw-run' --template=ci --label-prefix=pw- --strip-suffix=--pw-run
fleet webhook:rule:add blog --source=bitbucket --action=deploy --on-event=repo:push \
  --repo=acme/blog --match-branch=develop --template=ci --label=pw-regression

fleet webhook:token <project>             # the Bitbucket pipeline token; --clear removes it

fleet webhook:list                        # receivers, addresses and rules (never secrets)
fleet webhook:rule:remove blog <rule-id>
```

- Jira: the first rule whose `on-status` equals the new status (case-insensitive) wins. The issue key is the instance
  label (`FLE-66` becomes `fle-66`). When the project has an issue id pattern, a key that does not fully match it is ignored.
- Bitbucket: the event comes from `X-Event-Key`. A rule matches on the event, the repository (`workspace/slug`), an
  optional branch glob, an optional commit status `state` and an optional comment line.
- Every field is validated when the rule is added (a deploy rule must name one of the project's templates).

## Actions

### Deploy (Jira and Bitbucket)

A deploy rule queues the same job a person's deploy does (`InstanceService::requestDeploy`: the same guards and label
allocation), with the trigger `webhook` and the requester `webhook:jira` or `webhook:bitbucket`. The answer and the delivery
row name the job and the instance: `deploy queued: blog--fle-66 (job 12)`.

- **Jira.** The label is the lowercased issue key, so `FLE-3` gives `<project>--fle-3`, and `[[issue-id]]` resolves from
  the label as in a manual deploy. The branch is the rule's `--branch`, else the project's default branch. Moving the
  same ticket into the status again is a new event: the deploy allocation gives `fle-3-1`, then `fle-3-2`. Two Jira
  deliveries for the same issue entering the status twice with **different bodies** therefore create `fle-3`, then
  `fle-3-1` (0.x parity); only identical retries are deduplicated (see "Deduplication").
- **Bitbucket `repo:push`.** Bitbucket sends one entry per ref in `push.changes`. Each **created or updated branch** is
  matched against the rules on its own (first match wins); branch deletions and tags are skipped, so a push that only
  deletes is `ignored` (`no branch was created or updated`). One push can start several deploys; the answer then lists them
  under `actions`.
  - A rule with a fixed `--label` keeps **one** instance: when `<project>--<label>` exists the job is a **redeploy** of it
    (it is rebuilt in place), otherwise a first deploy. When the rule's branch differs from the instance's branch, the
    redeploy switches the instance to the rule's branch. A busy instance (a job queued or running) refuses the replace; the
    delivery records `Busy: ...`.
  - A rule with `--label-prefix` (and optionally `--strip-suffix`, which the branch glob must end with) forms the label as
    prefix + branch minus suffix, slugified: `feature/ABC-1898-x--pw-run` gives `pw-feature-abc-1898-x`. It **never
    overwrites**: a taken label allocates `-1`, `-2`, ... A rule with neither derives the label from the branch alone.
  - **Fitting.** When `<project>--<label>` would be longer than 63 characters the label is cut and ends in `-` plus the first
    6 hex of the SHA-1 of the branch name, so the same branch always lands on the same id. The fitting counts the project's
    additional hostnames (`<hostname>-<id>` must fit too) and, for a derived label, keeps room for a `-999` suffix.
  - **Collision guard.** A derived label that equals the label of a fixed-label deploy rule is skipped with the reason
    `collides with a fixed-label rule` (a `*--pw-run` branch must not rebuild the baseline). Each skipped branch has its own
    `ignored` row in the log.
  - A branch that cannot be turned into a label (it slugifies to nothing) is **422** and consumes nothing: the sender's retry
    can succeed once the rule is fixed.

### Run a pipeline (Bitbucket)

A `run-pipeline` rule posts to the Bitbucket Pipelines API for the rule's repository, with the custom pipeline `--pattern` on
the branch `--ref` (default: the project's default branch). It needs a repository access token with the scope
**Pipelines: Write** and nothing else:

```bash
fleet webhook:token blog            # hidden prompt
printf '%s' "$TOKEN" | fleet webhook:token blog
fleet webhook:token blog --clear
```

or on the project's **Webhooks** tab (Bitbucket card: *Set the pipeline token*, *Remove token*). The Bitbucket secret must
exist first. The token is sealed like the HMAC secret and **shown nowhere afterwards**: not in the page, the commands, a
delivery row, a log line or an exception message. Without a token nothing is called and the delivery says
`no pipeline started: this project has no pipeline token yet`.

### When an action cannot run

After a delivery is claimed (its id is consumed) the sender always gets **200**: a refusal is not retried by Jira or
Bitbucket and would only be a duplicate. The delivery row has the result `failed` and the reason (`not deployed: This project
has no template with that name.`, `Busy: ...`, `no pipeline started: Bitbucket answered HTTP 401`), and the answer is
`{"result": "failed", "performed": false, "note": "..."}`. An unexpected error is `the action failed unexpectedly; see the
application log`, and the log line carries the exception class only. Nothing returns a 500 or a stack trace to the sender.

## What the endpoint answers

The checks run in this order, so a caller without the secret learns nothing beyond "a receiver exists". Jira counts only
200 as success and never retries a 4xx.

| Case | Code | Body |
|---|---|---|
| No such project, or no receiver (no secret yet) | 404 | `{"error": "not found"}` |
| Body over 1 MiB, by `Content-Length` before it is read or by its size (Caddy's 1 MB limit normally answers first) | 413 | `{"error": "payload too large"}` |
| Missing, malformed or wrong signature | 401 | `{"error": "unauthorized"}` |
| Signed, but not a JSON object, or an issue update without a key | 400 | `{"error": "<reason>"}` |
| Valid, but no rule matches (other event, no status change, key outside the pattern, no rules at all) | 200 | `{"result": "ignored", "reason": ...}` |
| Retry of a delivery id, or a replay of a body seen in the last hour | 200 | `{"result": "duplicate"}` |
| A rule matched and the action ran | 200 | `{"result": "accepted", "decision": ..., "performed": true, "note": ..., "job": 12, "instance": "blog--fle-66"}` |
| A rule matched but the action could not run (see above) | 200 | `{"result": "failed", "decision": ..., "performed": false, "note": ...}` |
| A rule matched but the instance cannot be named (a branch that slugifies to nothing) | 422 | `{"error": "<reason>"}` |

A 401 is **not** stored in the delivery log (an unauthenticated caller must not be able to grow it). It is a warning in
the application log with the source, the project and the (cut) delivery id, and nothing else; `journalctl -u php8.4-fpm`
on a standard install. A secret that **cannot be opened** (the host's secret key was lost or replaced) is a different line, at
error level: `the stored secret cannot be opened ... rotate the webhook secret`. That one is the host's problem, not the
sender's: rotate the secret (and update the sender) to recover.

## Deduplication

Jira repeats `X-Atlassian-Webhook-Identifier` when it retries, Bitbucket repeats `X-Request-UUID`. A delivery that
matched a rule consumes its id, so a retry answers `duplicate` and decides nothing twice, even across a restart. Only
matched deliveries consume an id, a delivery without the header is never deduplicated, and ids are freed after 7 days.
Delivery rows are kept for 60 days.

**A failed or refused action still consumes the delivery.** The id and the one-hour body hash are claimed before the action
runs, so a delivery that ends `failed` (template gone, instance busy, Bitbucket refusing the pipeline) is not given
another chance: the sender's retry of it is answered `duplicate`. That is intended, not a bug. To recover, cause a fresh
event (move the issue out of the status and back in, push again), or start the work by hand.

**Replays.** The delivery id header is not covered by the signature, so someone holding a signed body could resend it with a
fresh id. A matched delivery therefore also remembers the SHA-256 of its body: the same body arriving again within **one
hour** is a `duplicate` (`this body was already handled`) whatever its id. Real events never repeat byte for byte (Jira
carries a timestamp, Bitbucket a request UUID and commit hashes). The limit: a body replayed after that hour is accepted
again; the signature proves who signed, not when.

## The delivery log

Every authenticated request is one row: when, source, event, delivery id, subject (issue key or branch), the change
("To Do -> Dispatched"), the rule that matched, the decision and the result (`accepted`, `ignored`, `duplicate`,
`failed`) and, for an accepted one, the **outcome**: the job (plain text `job #12` until the job pages land) and the note of
what the action did or why it did not. See it per project (the Webhooks tab, paged) or across projects (`/admin/webhooks`,
the latest 25); both have **Hide ignored** and filters for the source and the outcome (`?source=jira&result=failed`), or on
the host:

```bash
fleet webhook:log [--project=<p>] [--limit=20] [--hide-ignored] [--json]
```

Administrators only. A project's receivers, rules and secrets disappear with the project; its delivery rows stay until pruned.

## Configure the sender

Jira Cloud: **Settings → System → WebHooks → Create a WebHook**: URL = the Jira address above, Secret = the secret, Events =
Issue → updated, JQL = e.g. `project = FLE`. Bitbucket: the repository's **Repository settings → Webhooks**, same URL and
secret, with the events your rules name.

## Differences from 0.x

- A receiver exists as soon as it has a secret; a project with no rules answers 200 `ignored` (and logs it) instead of 404,
  so a freshly configured sender can be tested.
- Secrets live in the database, sealed, not in `secrets.env`; the delivery log is a table, not `jira.jsonl`.
- The Bitbucket pipeline token (`pipeline:write`) is stored per project, sealed, in the database, set on the Webhooks tab or
  with `fleet webhook:token` (0.x: `secrets.env`, `fleet webhook bitbucket-token`).
- A derived Bitbucket label does not need `label_prefix` (0.x required one so a deploy could not replace a hand-deployed
  instance). Here only a rule with a fixed `label` ever replaces; every other deploy allocates a free label.
- Jobs are queued, not held in memory: a restart does not drop a queued deploy, and a deploy of an instance that is busy is
  refused (`Busy: ...`) instead of waiting up to 45 minutes for its lock.
- The answer carries `job` (a number) and `instance`; the delivery log is a table, not `webhooks/*.jsonl`.
