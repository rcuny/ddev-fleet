### Changed

- **FLE-122: Renovate merges its own green patch and minor PRs.** Patch, minor, pin and digest updates, and the weekly lock-file
  maintenance PR, are merged by Renovate itself once their Fleet CI status (`fleet-gates`) is green, one per run; the
  Renovate runner is scheduled hourly. Symfony minor/major groups, Symfony UX and every major update still need a manual merge.
