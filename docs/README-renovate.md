---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-07
Type: documentation
---

# Renovate: hands-off merge of approved PRs

Renovate opens grouped dependency PRs against `develop` (see `CONTRIBUTING.md`).
The maintainer only **decides**; the pipeline does the git work.

## What the maintainer does

On a Renovate PR, do **one** of:

- click **Approve**, or
- comment `/merge` (a line of its own, any case).

Only the approvers count: by default the account behind `RENOVATE_PASSWORD`
(Renovate runs with the maintainer's token, so that is the maintainer); set
`RENOVATE_MERGE_APPROVERS` to override. To decline, close the PR.

## What the pipeline does

`custom: renovate-merge` (`bitbucket-pipelines.yml`, script
`scripts/renovate_merge.py`) has two steps:

1. **Merge an approved Renovate PR.** It picks the oldest open PR into
   `develop` from a `renovate/*` branch that is accepted (above), has **every
   commit status on its current head SUCCESSFUL**, and whose head contains the
   current `develop` head. It merges that one PR with a merge commit
   (`--no-ff`) and closes its branch. **At most one PR per run.** It re-reads
   `develop` right before merging and aborts if it moved, and the step runs in a
   `concurrency-group`, so two runs cannot race.
2. **Renovate (when requested).** Runs Renovate only if step 1 asks for it
   (it writes the artifact `renovate-requested.flag`, whose content is the
   reason). Two cases ask:
   - **`merged #n`**: a PR was merged, so the other PRs are now behind
     `develop`; Renovate recreates them on the new head.
   - **`behind #n, #m`**: nothing was merged, but an **accepted** PR is waiting
     because it is behind `develop` (it moved for another reason, e.g. a
     feature merge). Renovate recreates it (`rebaseWhen: behind-base-branch`),
     its green build fires the webhook, and the next run merges it.

   Accepted PRs that are merely still running their gates (or have no status
   yet), red PRs, and PRs nobody accepted never request a Renovate run, so no
   4-minute run is wasted. Otherwise the step logs "Renovate not requested" and
   exits.

Other outcomes, per PR (one line each in the step log):

| Verdict | Meaning |
|---------|---------|
| `skip` | Not approved by an allowed approver. Untouched. |
| `wait` | Approved, but gates still running, no status yet, or **behind `develop`**. Behind is checked first, whatever the statuses say (they belong to a head Renovate is about to replace), and requests a Renovate run (see above); the PR pipeline then runs again on the recreated branch. |
| `red` | Approved and **up to date with `develop`**, but a gate FAILED or STOPPED. The script posts **one** PR comment per head commit (`[renovate-merge] Gates are red on <head>`) and never merges it; it needs a human. |

`renovate-config.json` sets `"rebaseWhen": "behind-base-branch"`. Renovate's
"rebase" recreates the branch as one fresh commit on the head of `develop`
(conflicts such as version-floor bumps disappear), so each PR is tested on the
latest `develop` before it can merge. Automerge stays **off**: Renovate's own
automerge ignores approvals and Bitbucket Cloud cannot enforce them on a PR
authored by the same account.

## Running it

- **Automatically:** a Bitbucket webhook (events on approval, comment and build
  status of the PR) is relayed by the fleet daemon's
  `/hooks/bitbucket/{project}` route, which starts this pipeline. See
  [`README-webhooks.md`](README-webhooks.md).
- **By hand:** Pipelines -> Run pipeline -> branch `develop`, pipeline
  `custom: renovate-merge`; or, from the companion repo,
  `ddev bitbucket run renovate-merge --branch develop`.
- **Dry run:** `python3 scripts/renovate_merge.py --dry-run --repo <ws>/<slug>`
  prints each decision and never merges, comments or writes the flag (needs the
  two variables below in your environment). `--renovate-flag <file>` is the
  option the pipeline uses to request the Renovate step.

## Testing the hands-off merge end to end

`renovate_merge.py` checks the source branch prefix (`renovate/`), not the PR
author. So the whole chain (webhook -> daemon -> `renovate-merge` -> merge ->
Renovate) can be exercised without waiting for a real update:

1. Cut a branch `renovate/<something>-test` off the latest `develop`, with a
   change that is fine to land (it **will** be merged), and push it.
2. Open a PR into `develop`. Its PR pipeline runs the gates.
3. Approve it, or comment `/merge`. If the gates are still running, the
   approval starts a `renovate-merge` run that waits ("not green yet"). The
   green build status then fires the webhook again, and that run merges it.
4. Check:
   - the fleet daemon's delivery log (`fleet webhook log --source bitbucket`)
     shows `triggered` with a build number;
   - that `renovate-merge` run merged the PR (merge commit, branch closed);
   - its Renovate step ran.

This is how FLE-11 was first verified live (this section came in through such a
test PR).

## Repository variables

`RENOVATE_USERNAME` (Atlassian email) and `RENOVATE_PASSWORD` (Atlassian API
token) are the existing secured variables. The token needs repository
read/write, pull request read/write and `read:user`.
`RENOVATE_MERGE_APPROVERS` (comma-separated Bitbucket account ids) defaults to
the token's own account. **Set it to the maintainer's account id when Renovate
runs on another account** (a bot), otherwise the maintainer's Approve or
`/merge` is logged as "not approved ... from an allowed approver" and nothing
merges. An account id is visible on a PR's participants in the API, or in the
Atlassian profile URL.
`BITBUCKET_REPO_FULL_NAME` is set by Pipelines. No token is written in the YAML.

## Accepted trade-off

An approval is not reset when Renovate recreates the branch, so it applies to
the **update group**, not to one exact commit. If Renovate adds a newer version
to an already approved PR, that version merges without a new look. Gates still
run on the exact head, and a red head is never merged.
