# Run agents on Fleet

If your project runs coding agents on Fleet, this page tells you what is true inside an instance, what is not, and what the options cost. It states facts and options and prescribes nothing: Fleet stays neutral about stacks, agents and models. How to open the workspace and start an agent in a pane is in [the tmux workspace and agents](05-work-with-agents.md).

## Why it matters

| You get | Because |
|---|---|
| Scripts and briefs that behave right on Fleet | One reliable marker tells them they run in an instance. |
| No surprise with headless mode | You know up front that `claude -p` is not an option inside an instance. |
| A bill you can predict | The cost model names the three factors you control. |
| A clear choice of where to run what | Three layouts, with what each keeps where. |

## Detect Fleet

**`FLEET_INSTANCE_HOST` is the marker.** A project that needs to know whether it runs on Fleet tests that variable:

```bash
[ -n "$FLEET_INSTANCE_HOST" ] && echo "on Fleet"
```

It is a stable contract:

- It is set in **every** instance, whatever the template. Its value is the instance's primary host name (for example `website--main.fleet.example.com`).
- Fleet writes it to `.ddev/config.fleet.yaml` under `web_environment`, so DDEV puts it in the environment of the web container. Every process started with `ddev exec`, including a coding agent and the commands that agent runs, sees it. A shell on the host outside the container does not.
- Treat the value as a host name, not as a statement. Test that it is non-empty and read nothing else into it.

`FLEET_AGENT=1` is a second, optional variable. It is set **only** when the template has the agent flag (**An AI agent works in these instances**, or `--agent=yes`). It tells you the template was marked for agents, not that you run on Fleet: a template without the flag still sets `FLEET_INSTANCE_HOST` and not `FLEET_AGENT`. See [agent templates](05-work-with-agents.md#agent-templates-and-instances-without-a-database).

## `claude -p` is not available in an instance

Do not use `claude -p` (headless, print mode) inside a Fleet instance. Claude is authenticated there with the long-lived token from `claude setup-token` (see [the Claude token](05-work-with-agents.md#the-claude-token)). That token does not reach the commands Claude itself runs, so a nested `claude -p` has no credentials. Headless mode is also moving to a mode that ignores the token anyway. It is not an option on Fleet.

This page does not prescribe an alternative. Off Fleet (your own machine, where `FLEET_INSTANCE_HOST` is unset) it is up to you.

## Session types and template names

A useful way to sort agent sessions is by **where the heavy context lives**, because a session pays to re-read its context on every turn. Four types:

| Type | Where the heavy context lives | Typical template name |
|---|---|---|
| **Interactive** | In the main session, deliberately: a person drives it and wants a long shared memory. | not a template: a session you open yourself |
| **Dev** | In the main session: one unattended agent implements one ticket, so the code it holds is the work. | `jira-dev` |
| **Mechanical** | In the workers: a driver script and a ledger on disk decide the next item, and each item runs in a fresh subagent with a small brief. The main session stays light. | `<task>-workers` (for example `translate-workers`) |
| **Orchestrator** | In files: a ledger, a status file and ticket mirrors. It dispatches and watches other sessions and does not implement. | `orchestrate` |

The names are a convention, not something Fleet enforces: a template is whatever you configure. Using one name per type makes it clear, from the template list alone, what an instance is for. Say in your project's own docs which type each of your templates is.

## The cost model

**cost of a session = turns x context re-read per turn x the model's rate** (plus output).

- **Context re-read per turn** is usually the largest factor. A long session re-reads everything it has accumulated on every turn. A smaller context helps more than a cheaper model.
- **Every subagent call names its model.** A call that names none inherits the parent's model, which may be the most expensive one. A subagent starts with a fresh context and does not see the parent's conversation, but it does load the project's rules and `CLAUDE.md`, so a subagent has a floor cost per turn. It pays off when it keeps bulky text out of a large parent, not for a two-command check.
- **Loops live in scripts, not in chat.** One script call that prints a count and a file path replaces one tool call per item, and each tool call re-reads the whole context.
- **Results go to files.** A worker replies with one line and writes the detail to a file.
- **All agents on a host share one account limit** (one Claude token per host). Several agents running at once draw on the same quota, so **dispatch in waves** and watch [capacity](../reference/10-capacity.md) instead of starting everything together.

## Where to run what

Three layouts. Each is a choice, and projects mix them.

| Layout | What runs where | Fits when |
|---|---|---|
| **Fully local** | You and the agents work on your own machine. Fleet hosts only the sites. | One person, small tasks, no need for unattended runs. |
| **Local orchestration, Fleet implementation** | Your local session dispatches tickets, watches them, reviews and merges. Dev agents implement on Fleet, one instance per ticket. | Many tickets in parallel, a lean local context, and implementation that needs an instance (database, services, browser tests). |
| **Fully on Fleet** | Orchestrator and dev agents all run in instances. | Work that must go on while your machine is off. The orchestrator then lives on Fleet too and its context is part of the shared account limit. |

## Where to go next

- [The tmux workspace and agents](05-work-with-agents.md)
- [Capacity](../reference/10-capacity.md)
- [Automate deploys](03-automate-deploys.md)
