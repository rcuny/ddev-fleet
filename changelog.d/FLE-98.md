### Added

- **FLE-98: Typesense keys and environment for instances.** An instance of a project with the Typesense flag gets what
  0.x gave it: a generated admin key and search-only key (sealed project secrets, created once, never rotated),
  `TYPESENSE_API_KEY` in `.ddev/.env`, the `FLEET_TYPESENSE_*` variables in `web_environment`, and the search-only key
  registered in the instance's Typesense after `ddev start` (a failure is a warning in the job log). See
  `docs/networking.md`.
