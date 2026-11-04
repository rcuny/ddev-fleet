### Added
- **FLE-19: `fleet upgrade --check`.** The read-only command that shows what the next release changes: the installed
  version, the latest (or `--to vX.Y.Z`), the changelog sections in between, the rollout notes (`ACTION:` lines
  flagged) and the pre-flight results. It changes nothing and needs no root. Exit codes: 0 checked, 1 error, 2 usage
  (an unknown `--to` lists the available tags), 3 blocked by a pre-flight check, 4 offline (the installed version and
  the cached latest with its age are still printed). `--json` prints a documented, versioned document;
  `--no-cache` asks the release source instead of the 6-hour list. See `docs/upgrading.md`.
