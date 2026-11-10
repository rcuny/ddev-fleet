---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# The tmux workspace and agents

Fleet keeps one persistent tmux session on the host with a window for every instance. It is the place where you, a colleague or a coding agent such as Claude Code work inside an instance, and it is one of Fleet's most useful features. You can follow what happens in every instance at a glance, leave a long run or an agent working after you disconnect, and step in whenever you want to. An agent is optional: the workspace is just as useful for runners, logs and your own shell.

## Why it matters

| You get | Because |
|---|---|
| Work that outlives your SSH connection | The session belongs to the host (the `fleet-tmux` unit), not to your terminal. Detach, close the laptop, come back later. |
| One overview of every instance | A sidebar in each window lists all instances with their state and URL. |
| A shell already in the right place | Each window opens in the instance's checkout, ready for a runner, `ddev` or your editor. |
| A human in the loop on your terms | Watch an agent type, read its output, interrupt it or take over the keyboard. |
| A shared view for the team | Everyone with SSH access attaches to the same session. |
| Automation without lock-in | A template can type a command into each pane after a deploy: a test runner, `ddev exec claude ...`, another agent or nothing. |

## Open the workspace

```bash
ssh -t admin@fleet.example.com sudo fleet tmux            # the session, on the general window
ssh -t admin@fleet.example.com sudo fleet tmux website--main   # straight to one instance
```

`fleet tmux` needs a terminal (`ssh -t`) and refuses to nest inside another tmux. Success: you see a status bar at the bottom with one tab per instance.

## What you see

Each instance window has three panes:

| Pane | Holds |
|---|---|
| Sidebar (left) | Every instance with its state, URL and keys; refreshes by itself. |
| `tty1` | A shell in the checkout. Typical use: a CI runner, logs, your own commands. |
| `tty2` | A shell in the checkout. Typical use: a coding agent such as Claude Code. |

The first tab, `general`, is a shell in the Fleet home directory for host-level work. The mouse is on, so you can click tabs and panes and scroll. Keys use the default tmux prefix: `^b w` lists windows, `^b n` and `^b p` move between them, `^b d` detaches (everything keeps running) and `^b R` rebuilds the current tab.

## What Fleet manages for you

- **Windows follow instances.** A deploy creates the window, a destroy removes it. `fleet tmux:ensure` creates or heals the session and prunes windows of instances that are gone.
- **Commands are typed for you, when you ask.** The template's `tty1` and `tty2` lines are typed into those panes as the very last step of a deploy, after the last post-deploy line succeeded. Leave them empty and you get plain shells.
- **Broken windows are rebuilt in place.** `fleet tmux:reset [<window>]` recreates one window (or all) with fresh shells and sidebar.
- **Inspect from scripts.** `fleet tmux:list --json` lists the windows.

All the commands are in [the CLI reference](../reference/01-cli.md#tmux).

## Start a coding agent in a pane

A `tty2` line that starts an agent on the ticket (optional, use any tool you like):

```bash
fleet template:create website work \
  --tty2='ddev exec claude --model sonnet "/jira work [[issue-id]]"'
```

Pair it with the Jira rule from [automate deploys](03-automate-deploys.md): moving `WEBSITE-3` to `Dispatched` deploys `website--website-3` and starts the agent. Because the agent runs in a normal tmux pane, you can open the window at any time, read its progress and answer it, or leave it alone.

## The Claude token

1. Mint a token with `claude setup-token` on any machine that has Claude Code and a browser (your workstation is fine; the installer does not put the Claude CLI on the host). It signs you in and prints a long-lived token (about a year).
2. Store it with `fleet claude:token:set` (hidden prompt; or **Host settings, Claude token**).
3. Deploy. The token is exposed in the container as `CLAUDE_CODE_OAUTH_TOKEN`, so no command line or log holds it.
4. To rotate, mint and store a new token the same way. A running instance keeps its old token and a new one restarts its containers (ending a running agent), so it is manual: `fleet claude:token:apply --all --yes`.

## Git over SSH in the container

Opt-in per project (on the **Keys & secrets** tab, or `--container-ssh`). The instance gets its own ssh-agent holding the push key for 24 hours; no key file enters a container. Anyone with a process in that container can use the key while the agent runs, so enable it only where agents need to push. Trust a new git server with `fleet ssh:trust git.example.com`.

Check it all with `fleet workspace:check` (push key, git identity, token). Success: it reports every check true.

## If something goes wrong

| Symptom | Fix |
|---|---|
| A `tty2` line did not run | An unresolved token or unsafe value skips it with a `WARNING` in the job log. |
| `git push` fails in the container | Git over SSH is not on for the project, or the server is not trusted yet. |
| The agent has no token | None set on the host, or the instance started before it: apply it. |

More: [troubleshooting](../reference/13-troubleshooting.md).

## Where to go next

- [Automate deploys](03-automate-deploys.md)
- [Assets and secrets](../reference/08-assets-and-secrets.md)
- [Capacity](../reference/10-capacity.md) for CPU per agent
