### Fixed

- **FLE-90: the agent workspace works on an installed host.** The installer now installs the `tmux` package and installs, enables and starts `fleet-tmux.service` (idempotently, never restarting it); the uninstaller removes the unit. The pipeline image installs `tmux` so `TmuxRealTest` runs instead of skipping.

### Changed

- **FLE-90: `fleet claude:token:apply --all` asks for confirmation** (or `--yes`/`-y`); non-interactive and `--json` runs refuse without `--yes`, like the other destructive commands.
