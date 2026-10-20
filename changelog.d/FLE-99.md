### Changed

- **FLE-99: the Typesense search key waits for a cold container.** After `ddev start` the deploy retries registering
  the search-only key with a growing delay for up to 60 seconds instead of 3 x 3 seconds. Giving up is still only a
  warning in the job log. `docs/projects-and-templates.md` now lists the Typesense variables an instance gets, where
  each lands and the operator steps.
