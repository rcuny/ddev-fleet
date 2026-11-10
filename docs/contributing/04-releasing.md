# Releasing

A release is a tag on `main` with a changelog operators can trust. This page covers the branch model, how to write changelog fragments, and how to cut a release or a hotfix.

| Branch | Holds |
|---|---|
| `main` | Releases only: each an annotated tag `v1.X.Y`; what a new install clones |
| `develop` | Integration: features, Renovate and CI target it |
| `feature/<KEY>-<slug>` | Off `develop`, merged back with `--no-ff` (in practice through a PR) |
| `release/1.X.Y` | Off `develop`; merged into `main` (tagged), then back into `develop` |
| `hotfix/<slug>` | Off `main`; merged into `main` (tagged) and back into `develop` |

Bitbucket is the source of truth; GitHub (`rcuny/ddev-fleet`) is a read-only mirror. Nobody pushes to it by hand.

## Versioning

[Semantic Versioning](https://semver.org/spec/v2.0.0.html); the annotated tag is the version.

- MAJOR: breaking, needs operator action beyond `fleet upgrade`.
- MINOR: features and any behaviour or configuration change operators must know about.
- PATCH: fixes, docs-only, dependency-only.

## Changelog fragments

Every feature or fix branch adds `changelog.d/<KEY>.md` (format: `changelog.d/README.md`), never a line in `CHANGELOG.md`. It starts with a `### <Section>` heading. Preview with `php ci/changelog-assemble.php`; cut with `php ci/changelog-assemble.php X.Y.Z`, which writes the version section and deletes the fragments.

### Rollout notes and ACTION lines

A change that needs operator attention ends its fragment with a `## Rollout` section of bullets only. A bullet starting `ACTION:` becomes a warning in `fleet upgrade --check` and in the upgrade prompt, so say what to do, where and when (before or after). Schema changes need no note.

## Cutting a release

Replace `1.X.Y`. Run from a checkout of the Bitbucket repo.

1. **Preflight:** clean tree, `git fetch origin --tags`, local `develop` and `main` equal their `origin/` twins, the latest full `develop` run green, `php ci/changelog-assemble.php` non-empty.
2. **Branch:** `git switch -c release/1.X.Y develop`.
3. **Assemble:** `php ci/changelog-assemble.php 1.X.Y`, then `git commit -am "chore(release): 1.X.Y"`.
4. **Gates:** [02-testing.md](02-testing.md), plus `FLEET_REQUIRE_SYSTEMD_ANALYZE=1 pytest -q tests/pytest`.
5. **Merge:** `git switch main && git merge --no-ff release/1.X.Y`.
6. **Tag:** `git tag -a v1.X.Y -m "v1.X.Y - <headline>"` on that merge commit (annotated).
7. **Back-merge:** `git switch develop && git merge --no-ff release/1.X.Y`, then delete the release branch.
8. **Push in one command**, so `origin/main` is at the tagged commit before the tag is published: `git push origin main develop v1.X.Y`.
9. **Publish the GitHub mirror** with the maintainers' publish step (it runs the tag guard, [03-ci.md](03-ci.md)), then check that `main` and the tag on GitHub carry the tagged sha.
10. Optionally comment "Released in v1.X.Y" on the shipped tickets.

## Hotfixes

`git switch -c hotfix/<slug> main`; fix with a test and a `changelog.d/<KEY>.md` fragment; run the gates. Merge `--no-ff` into `main`, assemble the PATCH version and tag `v1.X.Y+1`. Back-merge into `develop` (in a changelog conflict, keep its empty `## [Unreleased]` on top), push `git push origin main develop v1.X.Y+1`, then publish.

## Releasing is not deploying

Each host updates when its operator runs `sudo fleet upgrade`; `fleet upgrade --check` shows the changelog and rollout notes first. See [upgrade reference](../reference/05-upgrade.md).

## Learn more

- [03-ci.md](03-ci.md): the tag guard and gates
- `changelog.d/README.md` and `CHANGELOG.md` in the repository
