### Added

- **FLE-45: the agent workspace deploy step** (milestone M0.11b). A deploy now creates the instance's tmux window and types the template's `tty1` and `tty2` commands into it, with `[[tokens]]` substituted (`TmuxWindowStep`). A command with an unresolved token is skipped with a `WARNING` in the job log, and a tmux problem never fails the deploy. Destroy and redeploy close the window. Tests cover the v0 line `ddev exec claude --model sonnet "/jira work FLE-9"` and prove the Claude token is only written to `config.fleet.yaml`, never to tmux keys, process arguments, the job log or the audit log.
- `fleet claude:token:apply [--instance=<id>|--all] [--json]` and `fleet claude:token:set --apply` restart running instances (a new `restart` job per instance, one sequential batch) so they pick up a rotated token. Never automatic. Audit action `claude.token.apply`. See `docs/agent-workspace.md`.
- `fleet workspace:check` also reports whether the tmux server is alive.

### Changed

- `fleet-tmux.service` documents why it has no `Restart=` (a oneshot cannot supervise the tmux server); the systemd security baseline records the measured scores of `fleet-tmux.service` and `fleet-mercure.service` (`install_units`).
- Integration test with a real tmux (skipped when it is not installed) checks that the sidebar command receives its arguments unchanged.
