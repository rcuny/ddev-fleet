---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Webhooks (1.x)

Jira and Bitbucket can call Fleet when something happens. This is the receiving side (milestone M0.08a): the signed
endpoints, per-project secrets, the rules that decide what an event means, and a delivery log.

**What does not work yet:** a matched rule is *decided and recorded* ("would deploy fle-66 (template jira-work)"), but
nothing is deployed or started. The action behind a rule is `App\Fleet\Webhooks\WebhookAction`; its only implementation,
`UnavailableWebhookAction`, records "deploy action not available until M0.04". The deploy, redeploy and pipeline start
arrive with the instance jobs.

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

fleet webhook:list                        # receivers, addresses and rules (never secrets)
fleet webhook:rule:remove blog <rule-id>
```

- Jira: the first rule whose `on-status` equals the new status (case-insensitive) wins. The issue key is the instance
  label (`FLE-66` becomes `fle-66`). When the project has an issue id pattern, a key that does not fully match it is ignored.
- Bitbucket: the event comes from `X-Event-Key`. A rule matches on the event, the repository (`workspace/slug`), an
  optional branch glob, an optional commit status `state` and an optional comment line.
- Every field is validated when the rule is added (a deploy rule must name one of the project's templates).

## What the endpoint answers

The checks run in this order, so a caller without the secret learns nothing beyond "a receiver exists". Jira counts only
200 as success and never retries a 4xx.

| Case | Code | Body |
|---|---|---|
| No such project, or no receiver (no secret yet) | 404 | `{"error": "not found"}` |
| Body over 1 MiB (Caddy's 1 MB limit normally answers first) | 413 | `{"error": "payload too large"}` |
| Missing, malformed or wrong signature | 401 | `{"error": "unauthorized"}` |
| Signed, but not a JSON object, or an issue update without a key | 400 | `{"error": "<reason>"}` |
| Valid, but no rule matches (other event, no status change, key outside the pattern, no rule) | 200 | `{"result": "ignored", "reason": ...}` |
| Retry of a delivery id that was already handled | 200 | `{"result": "duplicate"}` |
| A rule matched | 200 | `{"result": "accepted", "decision": ..., "performed": false, "note": ...}` |

A 401 is **not** stored in the delivery log (an unauthenticated caller must not be able to grow it). It is a warning in
the application log with the source, the project and the (cut) delivery id, and nothing else; `journalctl -u php8.4-fpm`
on a standard install.

## Deduplication

Jira repeats `X-Atlassian-Webhook-Identifier` when it retries, Bitbucket repeats `X-Request-UUID`. A delivery that
matched a rule consumes its id, so a retry answers `duplicate` and decides nothing twice, even across a restart. Only
matched deliveries consume an id, a delivery without the header is never deduplicated, and ids are freed after 7 days.
Delivery rows are kept for 60 days.

## The delivery log

Every authenticated request is one row: when, source, event, delivery id, subject (issue key or branch), the change
("To Do -> Dispatched"), the rule that matched, the decision and the result (`accepted`, `ignored`, `duplicate`,
`failed`). See it per project (the Webhooks tab, paged, with "Hide ignored") or across projects (`/admin/webhooks`, the
latest 25), or on the host:

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
- The Bitbucket pipeline token (`pipeline:write`) is not stored yet: a `run-pipeline` rule is only decided and recorded.
