# Vendored front-end assets

The web UI has no JS build pipeline, so third-party front-end assets are
committed as-is under `src/fleet/static/`. Renovate watches the version
column below (see `renovate-config.json`) and opens a PR when a newer
release exists; the file itself must then be re-downloaded by hand.

| Asset | npm package | Version | Path | Download URL |
|-------|-------------|---------|------|--------------|
| htmx.min.js | htmx.org | 1.9.12 | `src/fleet/static/htmx.min.js` | https://unpkg.com/htmx.org@1.9.12/dist/htmx.min.js |

## Re-vendoring when Renovate bumps a version

1. Download the new file from the URL in the table, with the new version
   substituted, over the path in the table, e.g.
   `curl -fsSL https://unpkg.com/htmx.org@<new>/dist/htmx.min.js -o src/fleet/static/htmx.min.js`.
2. Check the file reports the new version (`grep -o 'version:"[^"]*"' src/fleet/static/htmx.min.js`).
3. A new **major** (htmx 2.x) is breaking (extensions moved out of core,
   changed defaults and event names): read the upgrade guide and exercise
   the web UI before accepting it; close the PR if it is not worth it yet.
4. Update the Download URL cell to the new version, run the gates
   (`pytest -q`, `ruff check .`, `black --check .`), and commit the file
   and this table together.
