### Added

- **FLE-92: In-network port mirror and `:443` site mirror (M0.07b).** `ddev-router` now also listens on the catalogue's
  public ports inside the Docker network (two generated files in `~/.ddev/traefik/`), and the `:443` site mirror lets
  `https://<host>/` work inside the network. The router restarts only when the live entrypoints differ from the wanted
  ones; a failure removes the files and writes the marker `.fleet-port-mirror-failed`. `fleet port:refresh` syncs and
  prints `router mirror: live|pending`. The first deploy after the upgrade restarts `ddev-router` once.
- A port-mirror switch in the host settings (default on); off removes the mirror files at the next sync.
- A catalogue port `typesense` (9108 / 8108); a project's Typesense flag and the assignment of that port stay in step.
- The project Ports tab is editable: assign and unassign catalogue ports in the browser.
- `fleet port:firewall [--json]` lists the public ports to open (80, 443 and the ports in use) with a `ufw allow` line
  each. The installer still does not change the firewall.
- `docs/networking.md`: every port, named-port sites and certificates (`http01` and `ovh_dns`), the port mirror, the
  firewall list and the checks that need a live host.

### Changed

- Port values and names rendered in the instance Caddy site go through `ConfigValue`, and the no-ports site test
  compares the whole file byte for byte (FLE-79 review minors).
