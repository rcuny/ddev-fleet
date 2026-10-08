# Vendored front-end assets

The web UI has no JS build pipeline, so third-party front-end assets are
committed as-is under `src/fleet/static/`. Renovate watches the version
column below (see `renovate-config.json`) and opens a PR when a newer
release exists; the file itself must then be re-downloaded by hand.

| Asset | npm package | Version | Path | Download URL | Licence |
|-------|-------------|---------|------|--------------|---------|
| htmx.min.js | htmx.org | 2.0.11 | `src/fleet/static/htmx.min.js` | https://unpkg.com/htmx.org@2.0.11/dist/htmx.min.js | 0BSD |
| openpgp.min.mjs | openpgp | 6.3.2 | `src/fleet/static/openpgp.min.mjs` | https://unpkg.com/openpgp@6.3.2/dist/openpgp.min.mjs | LGPL-3.0-or-later (`src/fleet/static/openpgp.LICENSE`) |

## Integrity pins (OpenPGP.js)

`tests/test_vendored_openpgp.py` fails if the bundle changes by a single byte.

- `openpgp.min.mjs` sha256: `7d3285efa6dfedbb34a136d8b5ad21c28fb973269df0b2818dcb74dfb40b59d9` (395,333 bytes)
- npm tarball `openpgp-6.3.2.tgz` integrity (the registry's `dist.integrity`): `sha512-wcZTzHz41LV8Y48zH/JlD1JT8YdNmpWOuMAjQ/podvvCwsjBEU37K3znJh4UQcj3hksE5z9TzvhbUbwzsGvlLQ==`

OpenPGP.js is LGPL-3.0-or-later. The file is shipped **unmodified** together
with its licence text; `secrets-crypto.mjs` imports it as a separate module,
which keeps that boundary clean. Never edit, re-minify or concatenate it.

## Re-vendoring when Renovate bumps a version

### htmx

1. Download the new file from the URL in the table, with the new version
   substituted, over the path in the table, e.g.
   `curl -fsSL https://unpkg.com/htmx.org@<new>/dist/htmx.min.js -o src/fleet/static/htmx.min.js`.
2. Check the file reports the new version (`grep -o 'version:"[^"]*"' src/fleet/static/htmx.min.js`).
3. A new **major** (htmx 2.x) is breaking (extensions moved out of core,
   changed defaults and event names): read the upgrade guide and exercise
   the web UI before accepting it; close the PR if it is not worth it yet.
4. Update the Download URL cell to the new version, run the gates
   (`pytest -q`, `ruff check .`, `black --check .`, `node --test tests/js/*.test.mjs`), and commit the file
   and this table together.

### OpenPGP.js

1. In an empty scratch directory outside the repo:
   `curl -fsSL -o openpgp.tgz https://registry.npmjs.org/openpgp/-/openpgp-<new>.tgz`.
2. Verify it against the registry:
   `curl -fsSL https://registry.npmjs.org/openpgp/<new> | python3 -I -c 'import json,sys; print(json.load(sys.stdin)["dist"]["integrity"])'`
   must equal `sha512-$(openssl dgst -sha512 -binary openpgp.tgz | base64 -w0)`.
3. `tar -xzf openpgp.tgz package/dist/openpgp.min.mjs package/LICENSE`, copy them to
   `src/fleet/static/openpgp.min.mjs` and `src/fleet/static/openpgp.LICENSE`.
4. Update the version, Download URL, sha256 and sha512 above, and the pins at the top of
   `tests/test_vendored_openpgp.py`.
5. A new **major** needs a read of the release notes first: key-version defaults
   (`v6Keys`) and message formats (SEIPDv2 / AEAD) decide whether GnuPG can still read
   what the browser produces. `node --test tests/js/*.test.mjs` and
   `tests/test_pgp_interop.py` (real gpg) must pass unchanged.
