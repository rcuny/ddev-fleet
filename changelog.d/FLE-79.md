### Added

- **FLE-79: Named ports are served by Caddy (M0.07a).** Every instance's Caddy site now also carries one block per
  named port of its project (`<instance host>:<public port>`, aliases included), behind the same Authelia gate and
  proxied to the port on ddev's router. Assigning or unassigning a port rewrites the sites of that project's
  instances; a site Caddy refuses is rolled back and reported as a warning. New command `fleet port:refresh
  [--project=<name>] [--json]` rewrites them on demand.

### Changed

- The DDEV router's own ports (8080, 8443) are now refused as a public port too.
