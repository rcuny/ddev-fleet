---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Work with agents

A dispatched AI agent (Claude Code) needs a terminal, a token and a predictable layout in every instance. Fleet gives each instance a tmux window, injects the token, and lets git push work from the container, so an agent can be started by a Jira ticket and watched by you.

## The workspace

```bash
ssh -t admin@fleet.example.com sudo fleet tmux
```

Each instance has a window with three panes: a sidebar listing every instance, `tty1` (a shell for runners and logs) and `tty2` (where Claude runs). Switch tabs with `^b w`, detach with `^b d`.

The template's `tty1` and `tty2` lines are typed into those panes as the last step of a deploy. A `tty2` line that starts an agent on the ticket:

```bash
fleet template:create shop work \
  --tty2='ddev exec claude --model sonnet "/jira work [[issue-id]]"'
```

Pair it with the Jira rule from [automate deploys](automate-deploys.md): moving `SHOP-3` to `Dispatched` deploys `shop--shop-3` and starts the agent.

## The Claude token

1. Run `fleet claude:token:set` (hidden prompt; or **Host settings, Claude token**).
2. Deploy. The token is exposed in the container as `CLAUDE_CODE_OAUTH_TOKEN`, so no command line or log holds it.
3. To rotate, a running instance keeps its old token and a new one restarts its containers (ending a running agent), so it is manual: `fleet claude:token:apply --all --yes`.

## Git over SSH in the container

Opt-in per project (on the **Keys & secrets** tab, or `--container-ssh`). The instance gets its own ssh-agent holding the push key for 24 hours; no key file enters a container. Anyone with a process in that container can use the key while the agent runs, so enable it only where agents need to push. Trust a new git server with `fleet ssh:trust git.example.com`.

Check it all with `fleet workspace:check` (push key, git identity, token). Success: it reports every check true.

## If something goes wrong

| Symptom | Fix |
|---|---|
| A `tty2` line did not run | An unresolved token or unsafe value skips it with a `WARNING` in the job log. |
| `git push` fails in the container | Git over SSH is not on for the project, or the server is not trusted yet. |
| The agent has no token | None set on the host, or the instance started before it: apply it. |

More: [troubleshooting](../reference/troubleshooting.md).

## Where to go next

- [Automate deploys](automate-deploys.md)
- [Assets and secrets](../reference/assets-and-secrets.md)
- [Capacity](../reference/capacity.md) for CPU per agent
