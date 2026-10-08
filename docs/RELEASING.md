---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-06
Type: documentation
---

# Releasing ddev-fleet

The one place that describes how a release is cut: branching model,
versioning, CHANGELOG conventions, the release and hotfix steps, and the
push order the CI guard depends on. For what a contributor does day to day
see `CONTRIBUTING.md`.

## 1. Model

Gitflow:

- **`develop`** is the integration branch.
- **`main`** holds releases only. Each release is an annotated tag `vX.Y.Z`
  on `main`.
- **Feature branches** are `feature/<KEY>-<slug>`, cut off `develop` and
  merged back with `git merge --no-ff`. `<KEY>` is the Jira key of the
  ticket.
- Release branches are `release/X.Y.Z` (off `develop`) and hotfix branches
  `hotfix/<slug>` (off `main`).

Bitbucket (`origin`) is the source of truth. GitHub is a read-only mirror
published by Bitbucket Pipelines (`bitbucket-pipelines.yml`):

- **`develop` pipeline:** gates (pytest, ruff, black), then "Mirror develop
  to GitHub". The mirror step only pushes if `develop` is still the pushed
  commit, so a newer push is mirrored by its own pipeline.
- **`v*` tag pipeline:** gates, then "Publish release to GitHub mirror". That
  step fetches `origin/main` and **fails unless it equals the tagged
  commit**, then pushes `main` and the tag to the mirror. Pushes are never
  forced.
- Both mirror steps skip when `GITHUB_MIRROR_URL` is unset, so forks stay
  inert.

Installs and servers follow `main` (`bootstrap.sh` clones with
`--branch main`), so only a release ever reaches them. Nobody pushes to
GitHub by hand.

## 2. Versioning

[Semantic Versioning](https://semver.org/spec/v2.0.0.html). The version
lives only in `pyproject.toml` (`version`).

While the project is below 1.0:

- **MINOR** (`0.8.0` -> `0.9.0`): new features, and any change in behaviour
  or configuration that operators need to know about, breaking ones
  included. Call breaking changes out under `### Changed` and add a
  "Rollout" bullet saying what order servers must be updated in (see the
  0.8.0 "Rollout order" entry).
- **PATCH** (`0.8.0` -> `0.8.1`): fixes, documentation-only releases and
  dependency-only releases.

## 3. CHANGELOG conventions

`CHANGELOG.md` follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).

- **Every feature or fix branch adds its own bullet under `## [Unreleased]`
  in the same branch**, so the release step never has to reconstruct it.
  Headline items start with the ticket key in bold, as existing entries do
  (`- **FLE-6: ...**`).
- Sections, in this order: `### Added`, `### Changed`, `### Deprecated`,
  `### Removed`, `### Fixed`, `### Security`, then the project-specific
  `### Dependencies` (last).
- `### Dependencies` holds **one condensed bullet per merged Renovate
  group**, for example `Python tooling: ruff 0.x -> 0.y, black ...`. Add it
  when the Renovate PR is merged, or at the latest when cutting the
  release.
- Renovate PRs merged into `develop` while a `release/*` branch is open
  simply ride the **next** release; Renovate is not paused.
- Compare links at the bottom of the file: on release the `[Unreleased]`
  link becomes `.../compare/vX.Y.Z...HEAD`, and a new line
  `[X.Y.Z]: .../compare/vPREV...vX.Y.Z` is added below it.

## 4. Cutting a release

Replace `X.Y.Z` throughout. Run from a checkout of the Bitbucket repo with
the dev venv set up (`CONTRIBUTING.md`).

1. **Preflight.**

   ```bash
   git status --short                      # must print nothing
   git fetch origin --tags
   git rev-parse develop origin/develop    # the two shas must match
   git rev-parse main origin/main          # likewise
   ```

   - If there is no local `main`: `git branch main origin/main`.
   - The latest `develop` pipeline must be green.
   - `## [Unreleased]` in `CHANGELOG.md` must not be empty.

2. **Release branch, version and CHANGELOG.**

   ```bash
   git switch -c release/X.Y.Z develop
   ```

   - Set `version = "X.Y.Z"` in `pyproject.toml`.
   - In `CHANGELOG.md`: rename `## [Unreleased]` to
     `## [X.Y.Z] - YYYY-MM-DD`, add a fresh empty `## [Unreleased]` above
     it, point the `[Unreleased]` compare link at `vX.Y.Z...HEAD` and add
     `[X.Y.Z]: .../compare/vPREV...vX.Y.Z`. Make sure `### Dependencies`
     covers every Renovate group merged since the last release.

   ```bash
   git commit -am "chore(release): X.Y.Z"
   ```

3. **Gates.**

   ```bash
   .venv/bin/pytest -q
   .venv/bin/ruff check .
   .venv/bin/black --check .
   node --test tests/js/*.test.mjs
   ```

4. **Merge to `main` and tag.**

   ```bash
   git switch main
   git merge --no-ff release/X.Y.Z -m "Merge branch 'release/X.Y.Z'"
   git tag -a vX.Y.Z -m "vX.Y.Z — <one-line headline>"
   ```

   The tag must be **annotated**; deploy tooling refuses lightweight tags.

5. **Back-merge** the release branch so `develop` gets the version bump and
   the CHANGELOG heading:

   ```bash
   git switch develop
   git merge --no-ff release/X.Y.Z -m "Merge branch 'release/X.Y.Z' into develop"
   git branch -d release/X.Y.Z
   ```

   `main`'s own merge commits never reach `develop`, so
   `git log develop..main` always lists them; that is expected. What must
   hold is that the release branch tip is in `develop`:
   `git merge-base --is-ancestor 'vX.Y.Z^{}^2' develop`.

6. **Push, in one command.**

   ```bash
   git push origin main develop vX.Y.Z
   ```

7. **Watch the pipelines.** The tag pipeline must be green (gates and
   "Publish release to GitHub mirror"), and so must the `develop` one. Then
   check the mirror; both lines must show the `main` sha:

   ```bash
   git ls-remote https://github.com/rcuny/ddev-fleet.git refs/heads/main 'refs/tags/vX.Y.Z^{}'
   git rev-parse main
   ```

## 5. Push order and recovery

The tag pipeline fetches `origin/main` and refuses to publish unless it is
the tagged commit. If the tag reaches Bitbucket **before** `main` does (for
example `git push --tags` first, or a rejected `main` push), the guard fails
and nothing is published. Sending `main`, `develop` and the tag in a single
`git push origin main develop vX.Y.Z` puts all refs on the remote together,
so `main` is already there when the pipeline starts.

Recovery if it happened anyway:

```bash
git push origin main
```

then re-run the failed tag pipeline in Bitbucket (Pipelines -> the tag run
-> Rerun).

Never delete and re-push a published tag, and never force-push `main`,
`develop` or a tag: servers and the mirror follow them.

## 6. Hotfixes

For a fix that cannot wait for the next release from `develop`:

```bash
git switch -c hotfix/<slug> main
```

1. Fix it, with a test.
2. Add the CHANGELOG entry under a new `## [X.Y.Z+1] - YYYY-MM-DD` heading
   (a PATCH bump) and update the compare links; bump `pyproject.toml`.
3. Run the gates (section 4, step 3).
4. `git switch main`, `git merge --no-ff hotfix/<slug>`, annotated tag
   `vX.Y.Z+1` (as in section 4, step 4).
5. Back-merge: `git switch develop && git merge --no-ff hotfix/<slug>`. Resolve the
   CHANGELOG conflict so `develop` keeps its `## [Unreleased]` on top, with
   the hotfix section below it.
6. Push with the same single command:
   `git push origin main develop vX.Y.Z+1`, and watch the pipelines.

## 7. Deploying

Releasing is not deploying. A release makes the tag available on `main`;
each server updates when its operator pulls it. Read the CHANGELOG for any
"Rollout" notes first (they state the order in which servers must be
updated), then follow
[Updating the code](operations.md#updating-the-code) and the precondition in
[runbook-server-rollout.md](runbook-server-rollout.md#1-routine-rollout-code--config).
