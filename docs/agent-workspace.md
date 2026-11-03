# The agent workspace

Every deployed instance has a window in the persistent `fleet` tmux session, so a person (or an agent) can watch and
drive it from one terminal: `sudo fleet tmux` (attaches to the session, creating it first if it is missing;
`sudo fleet tmux <instance-id>` opens that instance's window; also `fleet tmux:attach`). The command needs a terminal:
over ssh use `ssh -t host sudo fleet tmux`, without `-t` it stops with "needs a terminal: use ssh -t" (exit 2). The raw form is
`sudo -u fleet tmux -L fleet attach -t fleet`. A window has three panes: the sidebar on the left, `tty1`
above `tty2` on the right, all in the instance's checkout.

## What is typed, by whom, when

At the end of a deploy, once `ddev start` has succeeded, the deploy step `TmuxWindowStep`:

1. creates the window (a redeploy closes the old one first, so the panes start fresh);
2. types each non-empty command of the template's **tty1** list into pane 1, then each command of **tty2** into pane 2,
   in the order they are written, each followed by Enter.

The window is closed when the instance is destroyed or redeployed.

Commands are the template's `tty1` and `tty2` lists. `[[token]]` placeholders are replaced from the instance context
(`[[issue-id]]`, `[[branch]]`, `[[instance-id]]`, ...). Substitution here is lenient:

- a command with an unresolved token (`[[issue-id]]` on a branch without an issue id) is skipped with a
  `WARNING: skipped ...` line in the job log, and that pane stays a plain shell;
- a value that is not safe to type into a shell (anything but letters, digits and `._/@:+-`) skips the command too;
- a tmux problem is a `WARNING` too. **A deploy never fails because of the workspace.**

Example, a v0 style template where tty2 starts the agent on the ticket of the branch (issue id `FLE-9`):

```yaml
tty2:
  - ddev exec claude --model sonnet "/jira work [[issue-id]]"
```

types exactly `ddev exec claude --model sonnet "/jira work FLE-9"` into tty2, once.

## The Claude token

The token never appears on a command line, in tmux keys, in the job log or in the audit log.

- `fleet claude:token:set` stores it (hidden prompt or standard input, never an argument).
- `ddev start` reads it from `.ddev/config.fleet.yaml` (mode 0640, excluded from the project's git status) and puts it in
  the container environment as `CLAUDE_CODE_OAUTH_TOKEN`. That is why the tty2 line above does not mention it.
- A project or host without a token gets no such line, and the deploy still succeeds.

### Rotating it into running instances

A running instance keeps the token it started with. Handing a new one over restarts its containers, which ends a
running agent, so it is **never automatic**:

```
fleet claude:token:apply --all --yes                 # every running instance (without --yes it asks; non-interactive runs refuse)
fleet claude:token:apply --instance=acme--main       # one (repeatable)
fleet claude:token:apply --all --yes --json         # the queued jobs as JSON
printf '%s' "$TOKEN" | fleet claude:token:set --apply  # store and apply in one go
```

Each running instance gets one `restart` job: it rewrites `config.fleet.yaml` from the current settings and runs
`ddev restart`. The jobs form one sequential batch, so the host restarts one instance at a time. An instance that is not
running, or has a job queued or running, is refused and nothing is queued. The audit log records `claude.token.apply`.

## Checks

`fleet workspace:check` reports the push key, the git identity, the Claude token and whether the tmux server is alive
(exit 1 when one is missing). `fleet-tmux.service` (installed and enabled by the installer, together with the `tmux` package; the installer never restarts it, so the panes survive a re-run) is a oneshot and cannot restart a dead server by itself; run
`systemctl restart fleet-tmux` to recreate the session.
