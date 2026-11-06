---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Automate deploys

Instances can appear on their own: when a Jira issue changes status, when a branch is pushed, or every night. Nobody clicks; Fleet queues the same jobs a person would. This page gives three recipes for project `shop`. Setting them up is for administrators.

| Recipe | Trigger | Result |
|---|---|---|
| Jira | Issue moves to `Dispatched` | Instance `shop--shop-3` |
| Bitbucket | Push to `feature/*` | One instance per branch, redeployed on each push |
| Schedule | Cron, 03:00 | Redeploy of `demo--main` |

## Recipe 1: an instance per Jira ticket

The issue key becomes the label: `SHOP-3` gives `shop--shop-3`.

1. Set the project's **issue id pattern** (Source tab), for example `SHOP-\d{1,6}`.
2. On the project's **Integrations** tab, **Create secret** for Jira. It is **shown once**; copy it now.
3. Add a rule:

   ```bash
   fleet webhook:rule:add shop --on-status=Dispatched --template=work
   ```

4. In Jira Cloud: Settings, System, WebHooks, Create a WebHook. URL `https://fleet.example.com/hooks/jira/shop`, Secret the one you copied, Events Issue updated, JQL `project = SHOP`.

Move an issue to `Dispatched`: a deploy job appears within seconds.

## Recipe 2: an instance per pushed branch

The label comes from the pushed branch. `--reuse` redeploys the same instance on each push instead of creating a new one.

```bash
fleet webhook:secret shop --source=bitbucket
fleet webhook:rule:add shop --source=bitbucket --action=deploy --on-event=repo:push \
  --repo=acme/shop --match-branch='feature/*' --template=work --reuse
```

In Bitbucket: Repository settings, Webhooks, URL `https://fleet.example.com/hooks/bitbucket/shop`, the secret, and the event `repo:push`.

## Recipe 3: redeploy every night

```bash
fleet schedule:create nightly-demo --cron "0 3 * * *" --timezone Europe/Brussels \
  --action redeploy --instance demo--main
fleet schedule:run nightly-demo
```

The second command tries it now. `fleet schedule:history nightly-demo` shows one row per night. The `fleet-scheduler` service must be running, next to the worker.

## Checking and recovering

Every authenticated delivery is one row in the **delivery log** (Integrations tab, or `fleet webhook:log`). Failed deliveries are **not retried**: fix the cause, then cause a fresh event (move the issue out and back in, or push again).

| Symptom | Fix |
|---|---|
| Jira moves, nothing happens | Look in the delivery log: `ignored` means no rule matched or the key does not fit the issue id pattern. |
| The sender shows 401 | Wrong or rotated secret; rotating stops the old one at once. |
| Schedule runs are `skipped` | The target instance is missing or busy. |

More: [troubleshooting](../reference/troubleshooting.md).

## Where to go next

- [Webhooks and schedules reference](../reference/webhooks-and-schedules.md): HTTP answers, deduplication, label options
- [Run CI](run-ci.md) with a pipeline trigger
- [Work with agents](work-with-agents.md)
