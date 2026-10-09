---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-07
Type: documentation
---

# CI: Bitbucket Pipelines and the systemd security check

Bitbucket Pipelines (`bitbucket-pipelines.yml`) is the only CI. GitHub is a
read-only mirror published by a pipeline step and has no workflow of its own.
The default image is Debian 13 (`python:3.11-trixie`), the same release and the
same systemd (257) as the servers.

## The pipelines

| Trigger | What runs |
|---|---|
| Pull request (any branch) | in parallel: **gates** and **ansible lint** |
| Push to `develop` | the same parallel pair, then **mirror develop** to GitHub |
| Tag `v*` (release) | **gates**, then **publish main + the tag** to GitHub |
| Custom `renovate` | Renovate (daily schedule): dependency-update PRs against `develop` |
| Custom `systemd-security-live` | the live systemd security check, the published report and the Jira alert (weekly schedule) |

- **Gates**: `pytest -q`, `ruff check .`, `black --check .`,
  `node --test tests/node/*.test.mjs` (the OpenPGP.js browser-crypto tests), the
  browser E2E suite (below), then the offline systemd security check (below). The
  image has `systemd` installed and `FLEET_REQUIRE_SYSTEMD_ANALYZE=1` is set, so the systemd scoring tests fail
  instead of skipping if `systemd-analyze` is ever missing. The image also gets
  `gnupg` and `nodejs`, and `FLEET_REQUIRE_PGP_TOOLS=1` is set so the gpg/node
  interop tests fail instead of skipping if either tool is missing.
  The step also runs the browser E2E suite (`tests/playwright`, a TypeScript
  `@playwright/test` npm package driving real daemons in headless Chromium with the CSP
  enforced): `npm ci`, `npx playwright install --with-deps --only-shell chromium` and
  `npx playwright test`, run from that folder after the Node tests. The image gets the
  Debian `npm` package next to `nodejs` (Debian 13 ships Node 20, which Playwright 1.63
  supports). The runner has no skip mode: a missing browser fails the step.
- **Ansible lint (non-blocking)**: `ansible-lint ansible/` and `yamllint
  ansible/` (the `infra` extra). Findings are printed but never fail the run;
  drop the `|| echo` in the step to promote it to a hard gate.
- **Mirror / publish**: see `docs/RELEASING.md` and the header of
  `bitbucket-pipelines.yml` (`GITHUB_MIRROR_URL`, the deploy key).
- **Renovate**: `renovate-config.json`; see `CONTRIBUTING.md`.

Alerts are Bitbucket's built-in "pipeline failed" email and, for the live check, a Jira comment
(see "The published report and the Jira alert" below).

## The systemd security check (FLE-8)

`systemd-analyze security` scores a unit's sandbox from 0 (locked down) to 10
(no sandbox). FLE-1 brought fleet, fleet-boot, fleet-reboot-notify and caddy to
1.6-1.8. This check keeps them there: it records the scores in a committed
baseline and fails when one goes up.

### Offline (every PR, `develop` push and release tag)

`python ci/systemd_security.py offline --out reports/offline.json` renders the
sandboxed units from the Ansible templates exactly like
`tests/pytest/ansible/test_systemd_sandbox.py` does (`fleet.service`, `fleet-boot.service`,
`fleet-reboot-notify.service`, and `caddy.service` = a stand-in for the Debian
vendor unit plus the `caddy` role's drop-in), scores them with
`systemd-analyze security --offline=yes`, prints a table and writes the report.
`authelia.service` is not rendered: it ships with the Debian package and the
repo has no unit or drop-in for it, so only the live check covers it.

Then `compare --env offline` checks the report against the baseline and writes
`reports/systemd-security-offline.md` and `.json`. The `reports/**` files are
pipeline artifacts.

### Live (weekly, ddev3)

The `systemd-security-live` custom pipeline logs in to each server listed in the
repository variable `SECURITY_PROBE_TARGETS` as the `fleet-probe` user. That
user has no shell access, no sudo and no group memberships: every key in its
`authorized_keys` is `restrict,command="/usr/local/bin/fleet-security-report
..."`, so whatever the client asks for, sshd runs that one read-only script and
returns a JSON report (the score of each watched unit with per-directive
exposure, plus the `systemd-analyze security` overview of every service on the
host). The pipeline compares the report with the `ddev3` baseline. Every target
is checked before the pipeline fails. Logic: `ci/systemd-security-live.sh`.

### The baseline

`ci/systemd-security-baseline.json`:

```json
{ "tolerance": 0.1,
  "environments": { "offline": { "systemd": "257", "recorded": "2026-10-06",
                                 "overview": {"fleet.service": 1.8},
                                 "units": {"fleet.service": {"score": 1.8, "directives": {"...": 0.1}}} },
                    "ddev3": { "...": "recorded from the first live run" } } }
```

Per environment, `compare` looks at the score of every unit in the report (the
watched units, and for the live host every service in the overview):

| Status | Meaning | Fails the run |
|---|---|---|
| OK | within the tolerance (+/- 0.1) of the baseline | no |
| REGRESSION | current is more than 0.1 above the baseline | **yes** |
| IMPROVED | current is more than 0.1 below the baseline | no (the report suggests updating the baseline) |
| NEW | not in the baseline (new unit, or no baseline recorded for the environment yet) | no |
| GONE | in the baseline, not in the report | no |

Every REGRESSION lists, next to the table, the per-directive exposure changes
(baseline vs current, a directive that is not listed counts 0), and stderr gets
one line per unit: `REGRESSION fleet.service (offline): 1.8 -> 2.6 (+0.8)`. The
report also warns when the systemd major version differs from the one the
baseline was recorded with, because scores can move with a systemd upgrade
without any change here.

### When it fails

1. Open the failed step's `reports/systemd-security-<env>.md` artifact (or the
   log): it names the unit and the directives whose exposure went up.
2. Offline failure: your change removed or weakened a sandbox directive (or
   changed the unit in a way that exposes more). Fix the template; do not raise
   the baseline to hide it. If the weakening is intended (e.g. a directive that
   breaks the daemon), record the new baseline in the same pull request and say
   why in the description.
3. Live failure: the unit on the server is weaker than recorded. Look at
   `systemctl cat <unit>` on the host: a drop-in that stopped applying, the
   sandbox toggle (`fleet_systemd_sandbox_enabled: false`) or a package upgrade
   that changed a vendor unit. Fix the host, or, for an accepted change,
   re-record the baseline.

### Updating the baseline

Always on a feature branch and in a pull request, so the change is reviewed.

- **offline**: run in a Debian 13 / systemd 257 environment (the pipeline image,
  or a container with `apt-get install systemd`):

  ```bash
  python ci/systemd_security.py offline --out reports/offline.json
  python ci/systemd_security.py update-baseline --env offline --current reports/offline.json
  ```

  or download `reports/offline.json` from the pipeline's artifacts and run the
  second command on it. Commit `ci/systemd-security-baseline.json`.
- **live (ddev3)**: download `reports/ddev3.json` from the artifacts of the
  scheduled run (or of a manual run of the custom pipeline), then
  `python ci/systemd_security.py update-baseline --env ddev3 --current ddev3.json`
  and commit. The first run, before any `ddev3` baseline exists, reports every
  unit as NEW and passes; record the baseline from that run.

`update-baseline` replaces only the named environment and keeps the others and
the tolerance. `compare --tolerance X` overrides the tolerance for a single run.

## The published report and the Jira alert (FLE-16)

After checking the hosts, `ci/systemd-security-live.sh` also scores the shipped
unit files offline (informational only: the gates enforce that baseline) and
builds the page [`SYSTEMD-SECURITY-REPORT.md`](https://github.com/rcuny/ddev-fleet/blob/develop/SYSTEMD-SECURITY-REPORT.md)
with `python ci/systemd_security.py publish-report --hosts "ddev3 ..."`. The page
has the explanation of the score, one status line per host (`OK`, `N
regression(s)` or `could not be fetched`), the product's units first (fleet,
fleet-boot, fleet-reboot-notify, caddy, authelia), the full host overview in a
`<details>` block and the offline scores. The output is sorted, so the weekly
diff is the date plus whatever moved. It also runs locally, without any
Bitbucket variable. The file is called `SYSTEMD-SECURITY-REPORT.md`, not
`SECURITY.md`, which GitHub reserves for the vulnerability-disclosure policy.
The README links to the absolute `develop` URL: GitHub opens on `main`, which
only moves on releases.

### When it is committed

`ci/systemd_security.py should-publish` says yes when `BITBUCKET_BRANCH` is
`develop`, or when the pipeline variable `PUBLISH_REPORT=1` is set. Any other run
(for example an acceptance check on a throwaway branch) builds the page as an
artifact (`reports/SYSTEMD-SECURITY-REPORT.md`) and commits nothing.

- The commit is `chore(security): systemd security report <YYYY-MM-DD>` by
  `ddev-fleet security check <security-check@noreply.fleet.pm>`, and only when
  the file changed. There is no `[skip ci]`: the `develop` pipeline is what
  mirrors the commit to GitHub. That cannot loop, because the `develop` push
  pipeline does not run the live check (pinned by `tests/pytest/repo/test_ci_config.py`).
- It is committed **even when a host regressed**; the step then still fails. A
  host that cannot be fetched is listed as such and the other hosts are
  published.
- The push goes back to `origin` (Bitbucket's default clone origin) as
  `HEAD:refs/heads/<branch>`. If it is rejected (the branch moved during the
  run) the script fetches, rebases the one report commit onto the branch and
  pushes once more. It never forces. A publish failure fails the step, but the
  Jira alert is still sent.
- **Branch restrictions on `develop` must allow the pipeline's push** (for
  example "Write access" for Bitbucket Pipelines, or no "merge via pull request
  only"). If they do not, the push fails and the step reports it; decide the
  exception rather than loosening the rule silently.

To test on a feature branch, push it and run the custom pipeline with the
switch, either from the CLI:

```bash
bitbucket run systemd-security-live --branch=<branch> --var PUBLISH_REPORT=1
```

or in Bitbucket's UI: Pipelines > Run pipeline > pick the branch > Custom:
`systemd-security-live`, add the variable `PUBLISH_REPORT` with the value `1`.
The report is committed to that branch.

### The Jira alert

When a host regressed or could not be fetched (on any branch, so a throwaway
branch can prove it), `ci/systemd_security.py jira-alert` posts one comment on
`JIRA_ALERT_ISSUE`. It starts with a real @mention (an Atlassian Document Format
`mention` node: plain `@email` text notifies nobody), then names each affected
host with its regressed units (baseline -> current, delta) and the directives
that changed, the branch, and links to the pipeline run and the committed
report. Repository variables (Repository settings > Pipelines > Repository
variables); if any of the first five is unset or empty the script prints `Jira
alert not configured, skipping` and carries on, so forks stay inert:

| Variable | Value |
|---|---|
| `JIRA_ALERT_SITE` | the Jira Cloud site name, `renaudcuny` for `renaudcuny.atlassian.net` |
| `JIRA_ALERT_EMAIL` | the e-mail of the Jira account that **posts** the comment |
| `JIRA_ALERT_TOKEN` | **secured**; that account's API token |
| `JIRA_ALERT_ISSUE` | the issue to comment on, e.g. `FLE-17` |
| `JIRA_ALERT_MENTION` | the Atlassian account id of the person to mention |
| `JIRA_ALERT_CLOUD_ID` | optional; the site's cloud id (see below) |

- **Use a different account to post.** Jira does not notify anyone of their own
  actions: if the token belonged to the mentioned person, the mention would send
  no e-mail. Post with a bot account and mention the maintainer.
- **Scoped token.** A scoped API token (only the classic scope
  `write:jira-work` is needed) is not accepted by `<site>.atlassian.net`: it goes
  through the gateway `https://api.atlassian.com/ex/jira/<cloudId>/rest/api/3/...`.
  Set `JIRA_ALERT_CLOUD_ID` and the script uses that URL; unset, it uses
  `https://<site>.atlassian.net/rest/api/3/...` (an unscoped token). Basic
  authentication (e-mail and token) is the same either way. Symptom of a scoped
  token without `JIRA_ALERT_CLOUD_ID`: `WARNING: Jira alert failed: HTTPError:
  HTTP Error 404: Not Found` (seen in pipeline #61; the same token posted through
  the gateway in #62).
- **Another ticket.** Change `JIRA_ALERT_ISSUE`; nothing else refers to FLE-17.
- A failed post (HTTP error, network error, timeout) prints `WARNING: Jira alert
  failed: ...` on stderr and never changes the step's result: that stays the
  regression result. The token is never printed.

## Setting up the live check

No credential is stored in the repository.

1. **Key.** Use the repository SSH key (Repository settings > Pipelines > SSH
   keys) that already exists for the GitHub mirror, or generate one there. Put
   its public half in the server's `/etc/ddev-fleet/local-vars.yml`:

   ```yaml
   fleet_security_probe_authorized_keys:
     - "ssh-ed25519 AAAA... bitbucket-pipelines"
   ```

   The role wraps it in `restrict,command=...`: the key can only print the
   report, even though the same private key is also the mirror's deploy key.
2. **Provision the probe** on the server. Never the full `site.yml` (see
   `CLAUDE.md`); use the scoped playbook, which sits beside `site.yml` and loads
   `local-vars.yml` itself:

   ```bash
   cd /opt/ddev-fleet/ansible
   sudo ansible-playbook security-probe.yml --check --diff   # preview
   sudo ansible-playbook security-probe.yml
   ```

   It creates `fleet-probe`, installs `/usr/local/bin/fleet-security-report`
   and the forced-command `authorized_keys`, and finishes with a smoke test that
   runs the report as `fleet-probe` (this proves a non-root user can read the
   scores). When an `AllowUsers` list is already in effect (the
   `security_hardening` role writes one), it adds `fleet-probe` to it with
   `/etc/ssh/sshd_config.d/53fleet-security-probe.conf`: sshd accumulates
   `AllowUsers` lines. The role never creates the first `AllowUsers` line, which
   would lock everyone else out, and checks the effective `sshd -T` result
   afterwards. Emptying `fleet_security_probe_authorized_keys` and re-running
   the playbook removes the user, the key, the script and the snippet. The role
   is also in `site.yml` (after `security_hardening`) and does nothing there
   without keys.
3. **Known hosts.** Repository settings > Pipelines > SSH keys > Known hosts:
   add the server (e.g. `ddev3.fleet.pm`) and fetch its fingerprint. The check
   uses `StrictHostKeyChecking=yes`, so an unknown host fails.
4. **Repository variable** (Repository settings > Pipelines > Repository
   variables): `SECURITY_PROBE_TARGETS`, space-separated `name=user@host`
   entries, e.g. `ddev3=fleet-probe@ddev3.fleet.pm`. The name selects the
   baseline environment. Unset or empty: the pipeline skips and succeeds.
5. **Schedule** (Pipelines > Schedules): branch `develop`, custom pipeline
   `systemd-security-live`, weekly.
6. **Jira alert** (optional): the `JIRA_ALERT_*` variables in "The published
   report and the Jira alert" above.

Try it first with a manual run of the custom pipeline (Run pipeline > Custom:
`systemd-security-live`). Test by hand, from a machine holding the key:
`ssh -i key fleet-probe@ddev3.fleet.pm anything | python3 -m json.tool | head`.

Other servers: add the key and run the playbook there too, then add
`name=fleet-probe@host` to the variable. ddev2 has colleagues: get an explicit
OK before touching it.

## Local runs

```bash
.venv/bin/pytest -q tests/pytest/ansible/test_systemd_sandbox.py tests/pytest/repo/test_systemd_security_ci.py \
  tests/pytest/ansible/test_security_probe_role.py
python ci/systemd_security.py offline --out /tmp/offline.json   # needs systemd-analyze
```

`FLEET_REQUIRE_SYSTEMD_ANALYZE=1` turns "systemd-analyze missing" from a skip
into a failure, as in the pipeline. Likewise `FLEET_REQUIRE_PGP_TOOLS=1` turns a
missing `gpg` or `node` into a failure instead of a skip for the OpenPGP interop
tests (`tests/pytest/secrets/test_pgp_interop.py`, `tests/pytest/web/test_secrets_ui.py`,
`tests/pytest/secrets/test_vendored_openpgp.py`); run `node --test tests/node/*.test.mjs` for the
browser-crypto tests. The browser E2E suite (`tests/playwright`) has no such flag: it never
skips. Locally, install it once with `cd tests/playwright && npm ci && npx playwright install
--only-shell chromium` (add `--with-deps` or run `npx playwright install-deps chromium` with
root for the system libraries), then run `npx playwright test` from that folder.
