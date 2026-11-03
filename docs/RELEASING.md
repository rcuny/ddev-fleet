---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-03
Type: documentation
---

# Releasing ddev-fleet

The one place that describes how a release is cut: branching model,
versioning, CHANGELOG conventions, the release and hotfix steps, and the
push order the tag guard depends on. For what a contributor does day to day
see `CONTRIBUTING.md`.

## 1. Model

Gitflow, with two release lines:

| Branch | Holds | Tags |
|---|---|---|
| `main` | The 1.x (Symfony) releases. The default branch on GitHub and what a new install clones. | `v1.*`, annotated, on `main` |
| `develop` | The integration branch for 1.x. Features, Renovate and CI target it. | none |
| `release/1.X.Y` | Cut off `develop`, merged into `main` (tag), then back into `develop`. | |
| `hotfix/<slug>` (1.x) | Cut off `main`, merged into `main` (tag) and back into `develop`. | |
| `legacy` | The frozen Python 0.x line. Hotfixes only; never merged into `develop` or `main`. | `v0.*` (`v0.1.0` to `v0.20.2` and later 0.20.x) |

- **Feature branches** are `feature/<KEY>-<slug>`, cut off `develop` and merged back with `git merge --no-ff`
  (in practice through a pull request). `<KEY>` is the Jira key of the ticket.
- The `v1.0.0` to `v1.0.6` tags were cut on `develop` before the cut-over (release `v1.1.0`). They stay as they
  are and are ancestors of `main`.

Bitbucket (`origin`) is the source of truth. GitHub is a read-only mirror, published from the Fleet CI host
by `publish-github.sh`: `develop` after a green run, a release tag on request. `ci/tag-guard.sh` decides what a
tag may publish (section 5). Nobody pushes to GitHub by hand.

A new install follows `main` (`install/install.sh --ref=main`, the default); an upgrade re-runs the installer, with
`--ref=vX.Y.Z` to pin a release ([`installation.md`](installation.md)).

## 2. Versioning

[Semantic Versioning](https://semver.org/spec/v2.0.0.html). The 1.x line has no version file: the annotated
tag is the version.

- **MAJOR**: a breaking change that needs operator action beyond re-running the installer.
- **MINOR**: new features, and any change in behaviour or configuration that operators need to know about. Call
  breaking changes out under `### Changed` and add a "Rollout" bullet when the order of steps matters.
- **PATCH**: fixes, documentation-only releases and dependency-only releases.

## 3. CHANGELOG conventions

`CHANGELOG.md` follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).

- **Every feature or fix branch adds its own fragment, `changelog.d/<KEY>.md`,
  in the same branch** (not a line in `CHANGELOG.md`: two branches editing the same lines
  conflicted on nearly every merge). A fragment starts with a `### <Section>` heading and holds the
  bullets; see `changelog.d/README.md`. Headline items start with the ticket key in bold, as existing
  entries do (`- **FLE-6: ...**`).
- **The release step assembles them:** `php ci/changelog-assemble.php` previews the unreleased text,
  `php ci/changelog-assemble.php X.Y.Z [YYYY-MM-DD]` cuts the release (sections merged in the order below,
  fragments read in file name order, fragments deleted). Anything still under `## [Unreleased]` in
  `CHANGELOG.md` is kept and comes first, so a hand edit or a hotfix entry is not lost.
- Sections, in this order: `### Added`, `### Changed`, `### Deprecated`,
  `### Removed`, `### Fixed`, `### Security`, then the project-specific
  `### Dependencies` (last).
- `### Dependencies` holds **one condensed bullet per merged Renovate
  group**. Renovate does not edit the changelog: add it as a fragment
  (`changelog.d/renovate-YYYY-MM-DD.md` with a `### Dependencies` heading) when the Renovate PR is merged, or at
  the latest when cutting the release.
- Renovate PRs merged into `develop` while a `release/*` branch is open
  simply ride the **next** release; Renovate is not paused.
- Compare links at the bottom of the file: on release the `[Unreleased]`
  link becomes `.../compare/vX.Y.Z...HEAD`, and a new line
  `[X.Y.Z]: .../compare/vPREV...vX.Y.Z` is added below it.

## 4. Cutting a 1.x release

Replace `1.X.Y` throughout. Run from a checkout of the Bitbucket repo. The `/release` skill of the maintainers'
companion repo drives these steps.

1. **Preflight.**

   ```bash
   git status --short                      # must print nothing
   git fetch origin --tags
   git rev-parse develop origin/develop    # the two shas must match
   git rev-parse main origin/main          # likewise
   ```

   - If there is no local `main`: `git branch main origin/main`.
   - The latest `develop` run of Fleet CI (commit status `fleet-gates`, full suite) must be green.
   - `php ci/changelog-assemble.php` must print a non-empty changelog.

2. **Release branch and CHANGELOG.**

   ```bash
   git switch -c release/1.X.Y develop
   php ci/changelog-assemble.php 1.X.Y
   git commit -am "chore(release): 1.X.Y"
   ```

   The script writes `## [1.X.Y] - YYYY-MM-DD` from `changelog.d/`, adds a fresh empty `## [Unreleased]` above it,
   points the compare links at the new tag and deletes the fragments. Make sure `### Dependencies` covers every
   Renovate group merged since the last release.

3. **Gates.** `cd app && composer run gates`, then in `tests/playwright/`
   `npm run typecheck && env -u FLEET_E2E_BASE_URL npx playwright test`, and
   `FLEET_REQUIRE_SYSTEMD_ANALYZE=1 pytest -q tests/pytest` for the CI tooling.

4. **Merge into `main` and tag.**

   ```bash
   git switch main
   git merge --no-ff release/1.X.Y -m "Merge branch 'release/1.X.Y'"
   git tag -a v1.X.Y -m "v1.X.Y - <one-line headline>"
   ```

   The tag must be **annotated** and on the merge commit that is the tip of `main`.

5. **Back-merge** the release branch so `develop` gets the CHANGELOG heading:

   ```bash
   git switch develop
   git merge --no-ff release/1.X.Y -m "Merge branch 'release/1.X.Y' into develop"
   git branch -d release/1.X.Y
   ```

   `main`'s own merge commits never reach `develop`, so `git log develop..main` always lists them; that is
   expected. What must hold is that the release branch tip is in `develop`.

6. **Push, in one command**, so `origin/main` is at the tagged commit before the tag is published:

   ```bash
   git push origin main develop v1.X.Y
   ```

7. **Publish to GitHub.** From the Fleet CI host, as the maintainer (the maintainers' companion repo holds the exact command):

   ```bash
   ssh <ci-host> 'sudo -u fleet bash <ci-checkout>/.ddev/ci/publish-github.sh tag v1.X.Y'
   ```

   Then check the mirror: `main` and the tag must carry the tagged sha.

   ```bash
   git ls-remote https://github.com/rcuny/ddev-fleet.git refs/heads/main 'refs/tags/v1.X.Y^{}'
   git rev-parse main
   ```

## 5. The tag guard

`ci/tag-guard.sh <tag> <commit>` runs before every publish to the mirror, in the publish script on the CI host and in
the (inactive) tag pipeline. The local tag must point at the commit, and then:

| Tag | Accepted when | Pushed to GitHub |
|---|---|---|
| `v1.X.Y`, `v1.X.Y-<prerelease>` (later majors alike) | the commit is the tip of `origin/main` | `main` (fast-forward) and the tag |
| same | the commit is an older ancestor of `origin/main` (the `v1.0.x` tags) | the tag only |
| same | the commit is only on `develop` or elsewhere | refused |
| `v0.X.Y` | the commit is the tip of `origin/legacy` | `legacy` (fast-forward) and the tag |
| anything else | never | refused |

If the guard fails with "not on origin/main", the tag reached the remote before `main` did: `git push origin main`
and publish again. Never delete and re-push a published tag, and never force-push `main`, `develop`, `legacy` or a
tag: servers and the mirror follow them.

## 6. Hotfixes

**1.x**, for a fix that cannot wait for the next release from `develop`:

```bash
git switch -c hotfix/<slug> main
```

1. Fix it, with a test, and add a `changelog.d/<KEY>.md` fragment.
2. Run the gates (section 4, step 3).
3. `git switch main`, `git merge --no-ff hotfix/<slug>`, assemble the changelog for the PATCH version
   (`php ci/changelog-assemble.php 1.X.Y+1`), commit, and tag `v1.X.Y+1` as in section 4, step 4.
4. Back-merge: `git switch develop && git merge --no-ff hotfix/<slug>`; resolve a CHANGELOG conflict so `develop`
   keeps its empty `## [Unreleased]` on top.
5. Push with `git push origin main develop v1.X.Y+1`, then publish (section 4, step 7).

**0.x (Python)**, only for the frozen line, branch `legacy`:

```bash
git switch -c hotfix/<slug> legacy
```

1. Fix it, with a test, and run the Python gates: `pytest -q`, `ruff check .`, `black --check .`,
   `node --test tests/node/*.test.mjs`.
2. Add the CHANGELOG entry under a new `## [0.20.N] - YYYY-MM-DD` heading (a PATCH bump, written by hand: `legacy`
   has no `changelog.d/`) and bump `pyproject.toml`.
3. `git switch legacy`, `git merge --no-ff hotfix/<slug>`, annotated tag `v0.20.N`.
4. **Never merge it into `develop` or `main`.** The Python tree does not exist there.
5. Push: `git push origin legacy v0.20.N`, then publish the tag from the CI host as in section 4, step 7.

## 7. Deploying

Releasing is not deploying. A 1.x release makes the tag available on `main`; each host updates when its operator
re-runs the installer (`install.sh --ref=v1.X.Y`, see [`installation.md`](installation.md)). Read the CHANGELOG for
"Rollout" notes first. The 0.x host (if you still run one) follows `legacy`; its procedure is
[Updating the code](v0/operations.md#updating-the-code) and the precondition in
[runbook-server-rollout.md](v0/runbook-server-rollout.md#1-routine-rollout-code--config).
