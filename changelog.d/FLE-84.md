### Added

- **FLE-84: a deploy seeds the new instance from the project's assets.** `AssetsSeedStep` copies the assets into the
  checkout before `ddev start`, substitutes `[[token]]` in text files up to 1 MiB, hard-links `dumps/` and keeps
  everything out of `git status`, as 0.x did. The database import stays the project's `post_deploy` command.
