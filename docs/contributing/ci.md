---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# CI

Every push is checked by the same gates you run locally, and a release cannot go out on a red build. This page explains what runs when, and the three automated guards around it.

Every push to `feature/*`, `release/*` and `develop` runs `ci/run-gates.sh`, reported as the commit status `fleet-gates`. It merges `origin/develop` first, so it tests the merge result. The gates are listed in [testing.md](testing.md).

## The gates script

`ci/run-gates.sh [--quick|--full] [--report-dir DIR]` runs every gate. A failing gate does not stop the others; the exit status is 0 only if every blocking gate passed (a skipped gate counts as passed). `--quick` is composer install, `composer run gates` and `prod-check`; `--full` (default) adds Playwright, pytest and the systemd security check. Reports land in `var/ci-report/` (`summary.txt`, `summary.json`, one log per gate).

| Variable | Effect |
|---|---|
| `FLEET_PW_TIER` | `quick` or `full` Playwright tier |
| `FLEET_PW_PHONE` | `on`, `off`, `auto`: whether the phone project runs (auto: only on a UI change) |
| `FLEET_CI_WORKERS` | Playwright worker count |
| `FLEET_CI_SERIAL` | `1` runs plain `composer run gates` instead of parallel lanes |
| `FLEET_REQUIRE_SYSTEMD_ANALYZE` | `1` makes a missing `systemd-analyze` a failure instead of a skip |

| Branch | Tier |
|---|---|
| `feature/*`, `renovate/*`, `develop` pushes | `quick` (phone and coverage specs chosen by `ci/pw_select.py`, see [testing.md](testing.md)) |
| `release/*`, `main`, nightly `develop` run | `full` |

A release needs the full suite green.

## Tag guard

`ci/tag-guard.sh <tag> <commit>` decides what a tag may publish to the GitHub mirror, with one non-forced push. It runs inside the maintainers' publish step.

| Tag | Condition | Publishes |
|---|---|---|
| `v1.X.Y`, `v1.X.Y-<prerelease>` | commit is the tip of `origin/main` | `main` (fast-forward) and the tag |
| same | commit is an older ancestor of `origin/main` | the tag only |
| same | commit is only on `develop` | refused: merge the release into `main` first |
| anything else | - | refused |

If it says "not on origin/main", the tag reached the remote before `main` did: push `main`, publish again. Never force-push `main`, `develop` or a tag.

## Systemd security check

`systemd-analyze security` scores a unit's sandbox from 0 (locked down) to 10. The check renders the sandboxed units offline, scores them with `--offline=yes` and compares them with the committed baseline `ci/systemd-security-baseline.json`. The offline mode scores `fleet.service`, `fleet-boot.service`, `fleet-reboot-notify.service` and `caddy.service` (rendered as Fleet's sandbox drop-in over a stand-in vendor unit). `authelia.service` has no template, and the plain install units `fleet-tmux.service` and `fleet-mercure.service` are deliberately unscored offline (their measured scores are recorded under `install_units` in the baseline).

| Status | Meaning | Fails the run |
|---|---|---|
| OK | within 0.1 of the baseline | no |
| REGRESSION | more than 0.1 worse | **yes**; the report lists the weakened directives |
| IMPROVED, NEW, GONE | better, not in the baseline, missing from the report | no |

Fix the unit rather than raise the baseline. If the change is intended, re-record it in the same pull request, on Debian 13 (systemd 257), and say why:

```bash
python ci/systemd_security.py offline --out reports/offline.json
python ci/systemd_security.py update-baseline --env offline --current reports/offline.json
```

A weekly live probe (`ci/systemd-security-live.sh`, targets in `SECURITY_PROBE_TARGETS`) compares real hosts; unset, it skips and succeeds.

## Renovate

Renovate (`renovate-config.json`) opens grouped dependency PRs against `develop` (Composer, npm, pip) and rebases stale ones, so each is tested on the latest `develop`. The PHP platform constraint is never bumped.

`scripts/renovate_merge.py` merges at most one PR per run: the oldest approved `renovate/*` PR whose every commit status is SUCCESSFUL and whose head contains `develop`. Approve with the Bitbucket button or a `/merge` comment; close the PR to decline. A `red` PR gets one comment per head commit and waits for a human.

| Variable | Meaning |
|---|---|
| `RENOVATE_USERNAME` | Atlassian account email (HTTP Basic user) |
| `RENOVATE_PASSWORD` | API token: repository and pull request read/write, `read:user` |
| `RENOVATE_MERGE_APPROVERS` | Optional comma-separated account ids; default: the account behind `RENOVATE_PASSWORD` |

An approval covers the update group, not one commit: a newer version added to an approved PR merges without a new look, though the gates still run on the exact head. Preview decisions with `python3 scripts/renovate_merge.py --dry-run --repo <workspace>/<slug>`. Merged groups get a `### Dependencies` fragment (`changelog.d/renovate-YYYY-MM-DD.md`).

## Learn more

- [testing.md](testing.md): the gates and Playwright selection
- [releasing.md](releasing.md): cutting a release
