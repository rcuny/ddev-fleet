# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **FLE-25: scoped playbooks for live servers.** `ansible/hardening.yml` applies `network_hardening`, `security_hardening` and `security_probe` alone, and `ansible/fleet-units.yml` applies only the systemd units and CLI wrapper of `fleet_service` (no git clone/update, no venv/pip; the unit tasks moved to `roles/fleet_service/tasks/units.yml`, still imported by `main.yml` in the same order). Both load `/etc/ddev-fleet/local-vars.yml` like `caddy-only.yml`. A changed `fleet.service` now also queues a `systemctl try-restart fleet.service` (no-op when the daemon is not running). See `docs/operations.md`.

### Changed
- **Rollout:** (FLE-25) servers hardened before this release may still block the registry's named ports (Typesense 9108, ...): run `fleet refresh-ports` once, or re-run `hardening.yml`.

### Fixed
- **FLE-25: named ports blocked after hardening.** `network_hardening` now deploys `fleet-ufw-sync` and runs it before `ufw enable`, so the registry's named ports are staged with SSH/80/443 (skipped in `--check` and when the fleet venv is missing; a failing sync warns instead of aborting).
- **FLE-25: hardening no longer restarts Docker.** The `daemon.json` change notifies a `Reload docker` handler (`systemctl reload docker`) instead of restarting it, so running DDEV containers (restart policy `no`) survive on a host without live-restore yet.
- **FLE-25: `--check` works.** The UFW "SSH port staged" assert no longer errors in a dry run (`ufw show added` runs read-only in check mode); the `findmnt` and msmtp credential reads in `security_hardening` do too, and its test-send report is skipped with the send.

## [0.12.1] - 2026-10-09

### Added
- **FLE-24: browser end-to-end tests.** `tests/playwright/` is a TypeScript `@playwright/test` suite (its own npm package, Playwright 1.63.0 pinned exactly) that starts the real daemons under uvicorn and drives the Secrets page in headless Chromium with the CSP enforced; console errors, page errors and CSP violations fail a test, and the save test checks that only ciphertext is sent and that `gpg` decrypts what the browser encrypted. It never skips: a missing browser fails the run. The Bitbucket gates step runs `npm ci`, `npx playwright install --with-deps --only-shell chromium` and `npx playwright test` in that folder. See `tests/README-tests.md` and `docs/README-ci.md`.

### Changed
- **FLE-24: test tree reorganised by framework.** `tests/pytest/<area>/` (unit and TestClient tests, grouped by area), `tests/playwright/` (browser E2E, TypeScript) and `tests/node/` (Node's built-in test runner, was `tests/js/`); shared `conftest.py` and `fixtures/` stay at the root of `tests/`.

### Fixed
- **FLE-24: Secrets "Encrypt and save" sent nothing.** htmx validates every form field before sending; the plaintext value field was cleared after encryption, so its `required` attribute silently halted the request. `required` is removed from that unnamed field; an empty value is still refused in the browser with a visible message.

## [0.12.0] - 2026-10-08

### Added
- **FLE-21: encrypted per-project secret store.** `fleet keys init|show` creates and shows a GnuPG host key (ed25519 + cv25519, no passphrase) in `$FLEET_HOME/gnupg`. With a key, `fleet secret set` stores `secrets/<project>/<KEY>.asc` (OpenPGP, 0600) and deploy, tty commands and Typesense key handling decrypt through the new `SecretStore`; `.asc` wins over a legacy `secrets/<project>.env`, which is still read. New `fleet secret list|unset|migrate (<project>|--all)`; `migrate` encrypts, verifies by decrypting, then deletes the plaintext file. The Ansible `fleet_user` role creates `/srv/fleet/secrets` and `/srv/fleet/gnupg` (0700). See `docs/operations.md` "Encrypted project secrets".
- **FLE-22: Secrets screen.** New dashboard page `/secrets`: choose a project, type a name and a value, and the value is encrypted in the browser with OpenPGP.js (vendored 6.3.2, LGPL-3.0-or-later, sha256-pinned) to the host's GnuPG public key before anything is sent. The server only validates the ciphertext (packet inspection, never a decrypt) and stores it as `secrets/<project>/<KEY>.asc`; values are never shown again. Without a host key the page explains `fleet keys init`. Routes: `GET /secrets`, `GET|POST /ui/secrets/{project}`, `POST /ui/secrets/{project}/{key}/delete`. Existing legacy plaintext names are listed as such.
- **FLE-22: browser-to-GnuPG interop tests.** `node --test tests/js/*.test.mjs` (new gate) covers the browser crypto; `tests/test_pgp_interop.py` encrypts with Node and the vendored file and decrypts with the real `gpg` (plus a committed OpenPGP.js ciphertext fixture). They skip when `gpg`/`node` are missing unless `FLEET_REQUIRE_PGP_TOOLS=1`.
- `docs/client-side-encryption.md`: data flow, key management and the threat model of client-side secret encryption.

### Changed
- **FLE-21: `fleet secret set <project> <key>`** reads the value from a hidden prompt or stdin; the positional `<value>` still works but is deprecated and prints a warning to stderr. The project must exist in `fleet.yml`, and the key must match `^[A-Z][A-Z0-9_]{0,63}$`. `fleet init` now also creates an empty `gnupg/` directory.
- **FLE-22:** the Bitbucket gates image installs `gnupg` and `nodejs`, sets `FLEET_REQUIRE_PGP_TOOLS=1` and runs `node --test tests/js/*.test.mjs`. `.mjs` files are served as `text/javascript`. Renovate now also tracks the vendored OpenPGP.js version in `docs/vendored-assets.md`.
- **Rollout:** encryption is opt-in per server. Nothing changes until `sudo -u fleet fleet keys init` creates the host key; then optionally run `sudo -u fleet fleet secret migrate --all` to encrypt the existing plaintext secret files, and check that gpg-agent works under the systemd sandbox (`docs/operations.md`). The new `/srv/fleet/secrets` and `/srv/fleet/gnupg` directories (0700) are created by `fleet init` / `fleet keys init`, or by the `fleet_user` role through a scoped playbook (never the full `site.yml`).

### Security
- **FLE-21:** project secrets can rest as ciphertext on disk once a host key exists; no plaintext value in argv when the prompt or stdin is used (the deprecated positional value still lands in argv), in logs, error messages or command output, and gpg is run with separate stdout/stderr rather than the merged streaming runner. Run `fleet secret migrate --all` after `fleet keys init`, then rotate secrets that were ever stored in plaintext. The host key has no passphrase; it is protected by file permissions only (see the threat notes in `docs/operations.md`).
- **FLE-23: strict Content-Security-Policy on the dashboard.** Every HTML response (pages and htmx fragments) now carries `default-src 'self'; script-src 'self'; style-src 'self'; ...; frame-ancestors 'none'`, plus `X-Content-Type-Options: nosniff` and `Referrer-Policy: no-referrer`. The inline `<style>` and `style=` moved to `static/fleet.css`, and htmx runs with `includeIndicatorStyles` and `allowEval` off. A test fails if a template reintroduces inline script, style or event handlers.
- **FLE-23: cross-origin writes to `/ui/*` are refused (HTTP 403).** A state-changing UI request must be `Sec-Fetch-Site: same-origin` or carry an `Origin` equal to the request `Host`; `cross-site`, `same-site` (a DDEV instance on a sibling subdomain) and `Origin: null` are rejected. Requests sending neither header (curl, scripts) still work. `/hooks/*`, `/api/*` and the WebSocket are unaffected.
- **FLE-22:** a secret entered in the web UI never crosses the network or touches the host's disk as plaintext (from the browser to the stored `.asc`; decrypted deploy-time use is unchanged): the value input is unnamed (it cannot be submitted), is cleared once encrypted, and the server rejects anything that is not an OpenPGP message addressed to the host key. The limits (root on the host, the unpassphrased host key readable by the fleet user, a compromised dashboard page) are documented in `docs/client-side-encryption.md`.

### Dependencies
- FLE-10: `fastapi>=0.142.4` (was `>=0.142.2`), Renovate #24.

## [0.11.0] - 2026-10-07

### Added
- **FLE-11:** `docs/README-renovate.md` explains how to test the hands-off Renovate merge end to end with a `renovate/*` test PR (it came in through such a PR, #22, merged by `renovate-merge`).
- **FLE-16: published systemd security report.** The weekly `systemd-security-live` run now builds `SYSTEMD-SECURITY-REPORT.md` (`ci/systemd_security.py publish-report`): what the exposure score means, a status line per host, the product's units first (fleet, fleet-boot, fleet-reboot-notify, caddy, authelia) with score, baseline and delta, the full host overview, and the offline scores of the shipped unit files. On `develop` (or any branch run with `PUBLISH_REPORT=1`) the pipeline commits it, even when a unit regressed, and pushes it back (never forced, one rebase retry); the README links to it on GitHub. The step image is now `python:3.11-trixie` with `systemd` and `openssh-client`.
- **FLE-16: Jira alert on a regression.** When a host regressed or could not be fetched, `ci/systemd_security.py jira-alert` posts one comment on the issue in `JIRA_ALERT_ISSUE` with a real @mention (the account id in `JIRA_ALERT_MENTION`), naming the host, the regressed units, the changed directives, the branch and links to the pipeline run and the report. Stdlib only; a scoped token goes through the `api.atlassian.com` gateway when `JIRA_ALERT_CLOUD_ID` is set. Unset variables skip it, and a failed post only warns. See `docs/README-ci.md`.

### Changed
- **FLE-8: ddev3 baseline recorded** in `ci/systemd-security-baseline.json` (systemd 257, 37 units) for the live systemd security check, after the `security_probe` role was applied there. The live-check test now uses a host that never gets a baseline, so recording a real one cannot break it (#20).

### Fixed
- **FLE-11: `renovate-merge` only considers open PRs.** Bitbucket ignored `state=OPEN` next to the `q` filter, so merged and declined `renovate/*` PRs were listed as candidates (shown as "behind", requesting needless Renovate runs). The state is now part of `q` and checked again client-side. Docs: `RENOVATE_MERGE_APPROVERS` must hold the maintainer's account id when the Renovate token belongs to a bot account.

## [0.10.0] - 2026-10-07

### Added
- **FLE-8: systemd security regression checks in Bitbucket Pipelines.** `ci/systemd_security.py` renders the sandboxed units (fleet, fleet-boot, fleet-reboot-notify, caddy with its drop-in), scores them with `systemd-analyze security --offline=yes`, compares them with the committed baseline `ci/systemd-security-baseline.json` (tolerance +0.1) and fails the pipeline on a regression, listing the directives whose exposure changed. Runs in the gates on every pull request, `develop` push and release tag; Markdown and JSON reports are published as pipeline artifacts (`reports/**`). Improvements and new/removed units are reported without failing. Baseline update procedure: `docs/README-ci.md`.
- **FLE-8: live check.** A `custom: systemd-security-live` pipeline (weekly Bitbucket schedule) fetches `systemd-analyze security` scores from each server in the repository variable `SECURITY_PROBE_TARGETS` and compares them with that server's baseline. It skips when the variable is unset.
- **FLE-8: `security_probe` Ansible role** and scoped playbook `ansible/security-probe.yml`: a no-sudo `fleet-probe` user whose SSH keys (`fleet_security_probe_authorized_keys`, set in `/etc/ddev-fleet/local-vars.yml`) are forced commands that can only print the JSON report (`/usr/local/bin/fleet-security-report`, stdlib-only Python). Inert until a key is configured; it never introduces the first `AllowUsers` line, only extends an existing one. Also listed in `site.yml` after `security_hardening`.
- `docs/README-ci.md`: the pipelines, the baseline, regression handling and live-check setup.

- **FLE-14: branch indicator can follow a submodule.** New optional per-project `fleet.yml` key `display_submodule_branch: <relative submodule path>` (e.g. `ddev-fleet`). When set, `fleet list`, the web UI instance list and the tmux sidebar show the branch and short HEAD of the git checkout at `<instance dir>/<path>` instead of the instance's own, prefixed with the path (`ddev-fleet: feature/FLE-12-x`). A missing or uninitialised submodule falls back to the instance's own branch. Display only: deploy, redeploy and the recorded `branch` are unchanged. Validated at registry load (non-empty relative path, no `..`).
- **FLE-15:** the web UI shows the server hostname (`ddev-fleet (<hostname>)` as page title and heading) and the Fleet version it is running (from `git describe --tags`, falling back to the package version) in the footer next to the server stats.
- **FLE-11: Bitbucket webhooks start a custom pipeline.** New route `POST /hooks/bitbucket/{project}` verifies Bitbucket Cloud's `X-Hub-Signature` HMAC-SHA256 with a per-project secret (separate from the Jira one). When an event matches one of the project's new `bitbucket_hooks` rules in `fleet.yml` (`on_event`, `repo`, optional `branch` glob, `state`, `comment`, `action: run-pipeline`, `pattern`, `ref`), it starts that custom pipeline through the Bitbucket API, using a per-project access token that only needs the `pipeline:write` scope. Deliveries are deduplicated on `X-Request-UUID`, and a failed trigger releases the claim so Bitbucket's retry can still succeed. New CLI: `fleet webhook secret <p> --source bitbucket`, `fleet webhook bitbucket-token <p>` (reads stdin or a hidden prompt), and `fleet webhook log --source bitbucket`. See `docs/README-webhooks.md`.
- **FLE-11: hands-off Renovate merges.** New custom pipeline `renovate-merge`, backed by `scripts/renovate_merge.py`. It merges (merge commit) at most one open `renovate/*` PR per run, and only one that the maintainer has accepted (an Approve or a `/merge` comment), that has green gates on its current head, and that is up to date with `develop`. It then runs Renovate to recreate the other PRs on the new `develop`. An approved PR with red gates gets one "needs a human" comment. Renovate now uses `rebaseWhen: behind-base-branch`. See `docs/README-renovate.md`.

### Changed
- **FLE-8: the GitHub Actions workflow is removed** (`.github/workflows/ci.yml`); Bitbucket Pipelines is the only CI. The ansible-lint/yamllint job moved to a non-blocking Bitbucket step that runs next to the gates on pull requests and `develop`. The Renovate rule for GitHub Actions is dropped with it.
- The Bitbucket gates image is Debian 13 (`python:3.11-trixie`) with `systemd` installed, so `tests/test_systemd_sandbox.py` scores the units instead of skipping. With `FLEET_REQUIRE_SYSTEMD_ANALYZE=1` (set in the pipeline) the tests fail when `systemd-analyze` is missing.

### Dependencies
- FLE-10: vendored htmx 2.0.11 (was 1.9.12; supersedes Renovate PR #11). No breaking change applies to the UI; behaviour verified identical in a headless-browser run (#15).

## [0.9.2] - 2026-10-06

### Dependencies
- `argon2-cffi` >= 25.1.0 (was >= 23.1) (#10).
- `rich` >= 15.0.0 (was >= 13.9.4) (#12).
- Dev: `ansible-lint` >= 26.9.0 (was >= 24.12.2) (#9).
- CI (GitHub workflow): `actions/checkout` v4 → v7, `actions/setup-python` v5 → v7 (#13).

## [0.9.1] - 2026-10-06

### Added
- **FLE-12:** `docs/RELEASING.md` — the release procedure (versioning, CHANGELOG conventions, release and hotfix steps, push order and recovery for the tag pipeline guard).

## [0.9.0] - 2026-10-06

### Added
- **FLE-3: Jira webhooks trigger deploys.** New route `POST /hooks/jira/{project}` receives Jira Cloud admin webhooks, verifies the `X-Hub-Signature` HMAC-SHA256 with a per-project secret, and, when an issue moves into a status listed in the project's new `jira_hooks` rules in `fleet.yml` (`on_status`, `action: deploy`, `template`, optional `branch`), deploys an instance labelled with the issue key, exactly like a web-UI deploy (tty commands, auto-suffix). Retries are deduplicated on `X-Atlassian-Webhook-Identifier`. Every outcome is answered with JSON and a 4xx/200 code (see `docs/README-webhooks.md`). Hooks stay off on a server until a secret exists there.
- `fleet webhook secret <project> [--rotate]` (generates and stores the secret in `/srv/fleet/webhooks/secrets.env`, mode 0600, and prints it with the URL to configure in Jira) and `fleet webhook log [--project P] [-n N]` (tails `/srv/fleet/logs/webhooks/jira.jsonl`).
- **FLE-1: systemd sandboxing.** `fleet.service`, `fleet-boot.service` and `fleet-reboot-notify.service` get a `systemd-analyze security` sandbox (`ProtectSystem=strict`, syscall filter, empty capability set, `PrivateDevices`, `MemoryDenyWriteExecute`, ...; exposure 8.7 / 9.2 / 9.0 down to 1.8 / 1.8 / 1.6). The two daemon units share one include (`fleet-sandbox.inc.j2`) so they cannot drift. The `caddy` role installs a drop-in `caddy.service.d/50-fleet-sandbox.conf` (8.8 down to 1.6). `fleet-tmux.service` stays unsandboxed on purpose.
- `security_hardening` runs `systemd-analyze security` against fleet, fleet-boot, fleet-reboot-notify, caddy and authelia at the end of the role (skips units that are not installed) and prints one summary; a unit over its threshold logs a warning, or fails the play with `fleet_systemd_security_enforce: true`. Settings: `fleet_systemd_security_check_enabled`, `fleet_systemd_security_thresholds`, `fleet_systemd_security_enforce`.
- `tests/test_systemd_sandbox.py`: renders the units and scores them offline with `systemd-analyze` (skipped when it is absent).
- **FLE-2: Bitbucket Pipelines CI and GitHub auto-mirror.** `bitbucket-pipelines.yml` runs pytest, ruff and black on every pull request, on `develop` and on `v*` tags. Bitbucket is the source of truth: a green `develop` push is mirrored to the GitHub mirror, and a `v*` tag publishes `main` plus the tag (refused unless the tag is the tip of `main`). Pushes are never forced; the steps skip when `GITHUB_MIRROR_URL` is unset, so forks stay inert. See `CONTRIBUTING.md`.
- **FLE-2: Renovate.** `renovate-config.json` plus a scheduled `custom: renovate` pipeline open daily grouped dependency PRs against `develop` (Python, GitHub Actions, Ansible collections, vendored htmx via `docs/vendored-assets.md`). The Python interpreter version is never auto-bumped.
- `docs/vendored-assets.md`: records the vendored htmx version and how to re-vendor it.
- `tests/test_ci_config.py`: structure checks for the pipelines and the Renovate config.

### Changed
- Caddy (`caddy` role): `/hooks/*` on the fleet host is exempt from Authelia/basic auth (the daemon authenticates it by HMAC, as `/ws/*` by token) and its request body is capped at 1MB. **Re-run `ansible/caddy-only.yml` after upgrading** to get the route through Caddy.
- **FLE-10 (Renovate): dependency floors raised.** Runtime: `fastapi>=0.142.2`, `uvicorn[standard]>=0.54.0`, `ruamel.yaml>=0.19.1`, `jinja2>=3.1.6`, `python-multipart>=0.0.32`, `rich>=13.9.4`. Dev/tooling: pytest 9.1, ruff 0.16, black 26.10, httpx 0.28, ansible-core 2.21, ansible-lint 24.12, yamllint 1.38. Ansible collections (`ansible/requirements.yml`) move to new major versions. **Upgrading a server needs `pip install -e` in the fleet venv** (`push-product` does it when `pyproject.toml` changed).
- `bootstrap.sh` fresh installs clone with `--branch main` instead of the remote's default branch (now `develop` on Bitbucket).
- `fleet.service` now runs with `ProtectSystem=strict`; the daemon can only write `fleet_srv_dir`, the Caddy snippet dir, the `fleet` user's home, `/tmp` and `/var/tmp`. Anything else fails with EROFS. Set `fleet_systemd_sandbox_enabled: false` to get the previous units back (and the Caddy drop-in removed). See `docs/operations.md`, "systemd sandboxing".

## [0.8.0] - 2026-10-06

### Added
- **FLE-6: `fleet-tmux.service`** (new systemd unit in the `fleet_service` Ansible role, tag `fleet_tmux`). It owns the `fleet` tmux server in its own cgroup, so `systemctl restart fleet` never kills operators' panes, and recreates the session after a reboot with a window per instance (sidebar + tty1 + tty2) as plain shells. `Type=oneshot` + `RemainAfterExit=yes`, `After=fleet.service` only (it does not wait for `fleet-boot.service`).
- `fleet tmux --ensure`: non-interactive create-if-missing + reconcile, no attach — what the unit runs.
- `post_deploy` may now be a mapping with optional `exec`, `tty1` and `tty2` lists; the list form stays valid as shorthand for `exec:`. Any other key is a validation error naming the template.

### Changed
- **FLE-6: tty1/tty2 commands are now a deploy action.** They are typed only by `fleet deploy` and `fleet redeploy` (CLI and web UI alike), from the template as resolved at deploy time. `fleet tmux`, reconcile, a recreated or closed window, `^b R` and the reboot reconcile always create plain shells, so a reboot no longer relaunches `/jira work` (with permissions skipped) on every templated instance.
- Deploy with no `fleet` tmux session: the CLI creates the session as before; the daemon/web UI never does and logs `fleet-tmux.service not running: tty commands not typed; start it with sudo systemctl start fleet-tmux and redeploy`. If the instance's window already exists the commands are not typed into it (warning in the deploy log).
- **Rollout order:** every server must run 0.8.0+ before `fleet.yml` uses the `post_deploy` mapping — older products cannot parse it and all servers share the config repo.

### Deprecated
- Template-level `tty1:`/`tty2:` (use `post_deploy.tty1`/`tty2`). Still accepted, with a deprecation warning once per template; setting the same pane in both places is a validation error.

### Removed
- `ttycmds.plan_for_instance` and the `tty_for` argument of `tmux.reconcile` (they re-derived tty commands from the live `fleet.yml` on every window creation).

## [0.7.2] - 2026-10-05

### Fixed
- **FLE-5: in-container `git push` failed on every instance after a reboot.** DDEV's ssh-agent is shared by all projects and starts empty after a reboot, and `start()` (`fleet start`, `fleet start --all`, `fleet-boot.service`) never loaded the read-write push key — only `deploy()` did — so a project pre-start hook could leave just the read-only deploy key in the agent. `start()` now clears the shared agent and loads only the push key after a successful `ddev start` (including after the port-conflict stop+start retry), via a helper shared with `deploy()`. A push-key problem only warns and never fails a start; a server with no push-key directory skips the step.

## [0.7.1] - 2026-10-05

### Fixed
- **FLE-4: every public URL returned 502 after a reboot until all instances had started.** `fleet-boot.service` is now `Type=exec` instead of `Type=oneshot`, so it no longer holds up `multi-user.target` (which the Authelia unit is ordered after); the dashboard and Authelia come up immediately while instances keep starting sequentially in the background.

## [0.7.0] - 2026-10-05

### Added
- **Authelia auth mode** as an alternative to per-instance basic auth
  (`auth_mode: authelia` in `host.yml`, default remains `basic`): a
  cookie-based login portal for networks that block basic auth outright,
  with per-project users defined in `fleet.yml`'s new `users:` key and
  authorized per-instance by Caddy's `forward_auth` against Authelia
  (`fleet.core.authelia`, `fleet.core.caddyauth`). Editing `users:` never
  restarts Authelia — only its hot-reloaded `users.yml` changes. The old
  `fleet.auth_bypass_cidrs` IP whitelist is removed: it is now accepted
  with a deprecation warning and otherwise ignored. See
  `docs/README-authelia.md`.
  - **Fixed (first live rollout, ddev2, 2026-09-27):** the role's apt
    signing key URL 404'd (`ff461cc`) — corrected to
    `https://www.authelia.com/keys/authelia-security.gpg`, gpgv-verified
    against the repo's `InRelease` like every other apt source in this
    project. Then three more bugs the `authelia` role only hit on a real
    server (`5b75ce9`). `storage.local.path` and
    `notifier.filesystem.filename` moved from `/srv/fleet/authelia`
    (Authelia has group-read only there, by design — "unable to open
    database file: permission denied") to a new, Authelia-writable
    `/var/lib/authelia`. The role now also grants the Authelia service
    user a traverse-only (`x`) ACL entry on `/srv/fleet` itself (via
    `setfacl`, guarded by a `getfacl` read-back), since it previously
    couldn't even `stat` into `/srv/fleet/authelia`. And the role's own
    `authelia config validate` task now gets the same
    `AUTHELIA_*_FILE` secret env vars the systemd drop-in supplies
    (both derive from one `authelia_secret_env` mapping in
    `ansible/roles/authelia/vars/main.yml`) — it previously failed with
    `storage: option 'encryption_key' is required`. See
    `docs/README-authelia.md`.

## [0.6.0] - 2026-09-25

### Added
- **Bounded timeouts on `fleet list`'s read-only status calls** so a
  stalled `docker`/`ddev`/`git` process degrades the table instead of
  hanging the whole command forever (observed once on ddev2, 2026-09-22:
  a `fleet list` hung >2 minutes with no output before being killed by
  hand — not reproduced since, so this is defensive hardening rather than
  a confirmed root-cause fix). `ddev list --json-output`
  (`core/ddev.py:LIST_TIMEOUT` = 30s), `docker stats --no-stream`
  (`core/ddev.py:STATS_TIMEOUT` = 20s), and the per-instance `git
  rev-parse` branch/HEAD reads (`core/instances.py:GIT_READ_TIMEOUT` =
  10s) now all time out rather than block indefinitely. On a `ddev list`
  timeout/failure, instances are still listed from on-disk state but
  `InstanceStatus.state` reports a new `"unknown"` value instead of the
  previous, inaccurate `"deployed"` (which asserted a live fact — nothing
  running — that was never actually observed); a `docker stats`
  timeout/failure only blanks the RAM column; a git-read timeout/failure
  falls back quietly to the deploy-time recorded branch, same as its other
  failure modes. Each of the first two prints one `warning: …` line to
  stderr naming what's unavailable. Deploy/destroy/start/stop/import paths
  are untouched — they still wait indefinitely by design. See
  `docs/cli.md`'s "`fleet list` degraded state".
- **Pre-destroy preflight** for redeploy/replace: `redeploy()` and
  `deploy(replace=True)` (and, more lightly, `destroy()`) now run cheap,
  side-effect-free checks — registry still resolves the deploy target,
  alias FQDNs still compose, the Caddy snippet directory guard, and the
  CURRENT Caddy config still validates — BEFORE anything is torn down.
  Raises `DeployError` naming the reason and stating nothing was
  destroyed. Closes the gap that let `fleet redeploy --all` destroy 5
  instances on 2026-09-22 and then fail to rebuild them because `caddy
  validate` was already broken for an unrelated reason.
- **Per-host `fleet.domain`** (`<FLEET_HOME>/host.yml`, e.g.
  `/srv/fleet/host.yml`): lets several fleet servers share ONE `fleet.yml`
  (via the config repo) while each keeps its own domain. Rendered by the
  `caddy` Ansible role from `fleet_domain`; `host.yml`'s `domain` wins over
  `fleet.yml`'s own `fleet.domain` when both are set, and `fleet.domain`
  becomes optional in `fleet.yml` once every host has its own `host.yml`.
  See `docs/configuration.md`'s "Per-host domain" section.
- **TLS certificate mode**, chosen at install time (`fleet_tls_mode`,
  `on_demand` default or `ovh_dns`): `on_demand` keeps today's
  per-hostname Let's Encrypt behaviour (HTTP-01 via `/api/tls-authorize`,
  ~50 new certs/registered-domain/7 days); `ovh_dns` issues a single
  wildcard certificate for `*.<domain>` via the OVH DNS-01 challenge
  (`caddy-dns/ovh` plugin, custom Caddy build installed via
  `update-alternatives`), removing the per-hostname rate limit entirely.
  Both modes render from one shared Caddy snippet, `tls.conf`, imported by
  a literal path from the `*.<domain>` site and every named-port site
  (`fleet.core.caddyports` needs no knowledge of the mode). New scoped
  playbook `ansible/caddy-only.yml` lets an existing server switch mode
  (or domain) without running `site.yml`. `bootstrap.sh` prompts for the
  mode and, for `ovh_dns`, the OVH API credentials (written straight to
  `/etc/caddy/ovh.env`, never to `local-vars.yml`). See
  `docs/installation.md` "Choosing a TLS mode" and `docs/networking.md` §4.
- The `*.<domain>` site now sends `X-Robots-Tag: noindex, nofollow` on
  every response, in both TLS modes — instance URLs are ephemeral,
  often-unfinished work and should never be indexed.
- `FLEET_INSTANCE_HOST=<instance-id>.<domain>` injected into every
  instance's `web_environment`, so a project's own Drupal Domain Access
  config can build its alias-matching patterns without hardcoding the
  fleet domain: `"<h>-" . getenv('FLEET_INSTANCE_HOST')`.

### Changed
- `additional_hostnames` alias FQDNs are now **flattened**:
  `<h>-<instance-id>.<domain>` (e.g.
  `news-oak--translations-test.fleet.example.com`) instead of the previous
  nested `<h>.<instance-id>.<domain>` form, which Caddy's single-label
  `*.<domain>` site block could never match. Each entry is now validated
  at registry load as a bare DNS label (lowercase, no dots —
  `RegistryError` otherwise), and deploy raises `DeployError` if a
  composed alias label exceeds the 63-character DNS limit. See
  `docs/networking.md` §7.

### Fixed
- **Security:** per-instance Caddy basic auth now covers alias hosts too —
  the `@auth-<instance-id>` matcher lists the instance FQDN and every
  registered alias FQDN in the same `host` clause (`fleet refresh-auth`
  re-applies this to already-deployed instances). Previously an alias host
  bypassed basic auth entirely, since the matcher only ever named the bare
  instance FQDN.
- **Security:** `/api/tls-authorize` no longer authorizes any
  `<prefix>-<instance-id>` label — only the bare instance id, or a label
  matching one of that instance's project's registered
  `additional_hostnames`. The previous generic
  `label.endswith(f"-{instance_id}")` check let anyone mint an on-demand
  TLS certificate for an arbitrary, unregistered alias pointed at a real
  instance.

### Added
- Template-level `drupal_env` in `fleet.yml`: fleet writes `DRUPAL_ENV=<value>`
  into the deployed instance's own root `.env` (after asset injection, in
  place — comments, key order and every other value preserved), overriding the
  default the project's `assets/<project>/.env` ships. A template without
  `drupal_env` leaves the file untouched. Lets one project run a `staging`
  template beside its `dev` one.
- `fleet.auth_bypass_cidrs` in `fleet.yml` (per fleet server): IP addresses /
  CIDR ranges whose visitors skip per-instance HTTP basic auth, rendered into
  each instance's Caddy snippet as `not remote_ip …`. Anything unlisted still
  gets the prompt — the list never denies. For networks where corporate policy
  blocks basic auth outright.
- `fleet refresh-auth`: re-applies that whitelist (plus each instance's
  recorded auth settings) to every deployed instance — rewrite all snippets,
  then one `caddy validate` + `caddy reload`. No redeploy, no Ansible run.

### Fixed
- Fleet-owned Caddy snippet directories under `/etc/caddy` are no longer
  created on the fly: `caddyauth.ensure_snippet_dir()` now refuses to write
  when the directory is missing OR has lost its **setgid** bit, naming the
  exact fix. A `mkdir` there drops setgid, so every snippet written after it
  is group-owned by `fleet` instead of `caddy` and Caddy cannot read it —
  which took ddev2's Caddy down from 2026-09-14 to 2026-09-21, invisibly,
  because a reload keeps serving the old config until the next restart. The
  unprivileged `fleet` user cannot repair setgid itself (Linux drops S_ISGID
  for a non-member group), so provisioning owns these directories.

### Changed
- Per-instance basic auth is now symmetric: the `--auth-password` / web-UI
  "Auth user & password" value is used as BOTH username and password
  (previously the username was always `fleet`). Because it doubles as a
  Caddyfile username token, it must be a single word (no whitespace, quotes,
  braces, backslashes, or leading `#`) — rejected up front otherwise.
  Existing instances keep their old `fleet`/<password> credentials until
  redeployed.

## [0.5.0] - 2026-07-27

Backfilled summary — this release's larger features predate the detailed entries below: named-port exposure (`fleet.ports`, per-port Caddy snippets, `fleet refresh-ports`); bulk start/stop/destroy and multi-deploy (`deploy --count`) in CLI and web UI; reboot-required notifications (msmtp email, UI badge, tmux banner, `fleet reboot-notify`); the `network_hardening` (UFW + dead-man's switch) and `security_hardening` (SSH, fail2ban, sysctl, auditd, needrestart, Docker `daemon.json`) Ansible roles; the four fleet-management Claude skills; the bundled `demo` project.

### Added
- `fleet redeploy <instance-id>... [--all|--project=P|--state=S] [--template T] [--auth-password P] [--force] [--yes]`,
  plus a per-row Redeploy button and a bulk "Redeploy selected" action in the
  web UI. Destroys an instance and rebuilds it under the same id, recovering
  project/template/branch/label/auth from `.fleet/instance.yml`. Refuses if
  no `template` was recorded (an instance deployed before template recording
  existed) unless `--template` is given. Confirmation and bulk targeting
  mirror `destroy` exactly; bulk redeploy runs sequentially.
- Per-project `issue_id_regexp` and the `[[issue-id]]` token: derived from
  the deploying instance's label (checked first) or branch (fallback),
  matched case-insensitively with the result uppercased; also exported as
  `FLEET_ISSUE_ID`.
- `templates.<name>.tty1`/`.tty2`: lists of commands typed (via `tmux
  send-keys`) into the middle/right bash panes of an instance's `fleet
  tmux` window the first time that window is created — for interactive
  workflows (e.g. `ddev exec claude "/jira pull [[issue-id]] ..."`) the
  operator can attach to and keep talking to. An unresolved `[[token]]` in
  a `tty1`/`tty2` command is skipped with a warning rather than failing the
  deploy.
- MIT `LICENSE`.
- `.github/` issue templates and a CI workflow (pytest, ruff, black).
- `tests/test_no_personal_leakage.py`, a denylist test guarding against
  deployment-specific data leaking into the public repo.
- Public installer flow: OS detection, env-var/tty/generated value
  collection, `/etc/ddev-fleet/local-vars.yml` persistence,
  `FLEET_REPO_VERSION` pinning.
- `fleet init`'s local-file registry mode (`fleet.yml.dist` →
  `$FLEET_HOME/config/fleet.yml`) and the `FLEET_CONFIG_REPO` advanced
  config-repo mode.
- `docs/installation.md`, `docs/operations.md`, `docs/architecture.md`,
  `CONTRIBUTING.md`.

### Changed
- `fleet deploy` never overwrites an existing instance. If the resolved
  instance id is already taken — whether the label came from `--branch` or
  an explicit `--label` — `-1`, `-2`, … is appended until a free one is
  found; the deploy log records the substitution. This applies to a single
  deploy as well as `deploy --count` (which already always suffixed).
- `.fleet/instance.yml` now also records `auth-enabled`/`auth-password` (so
  a redeploy can reproduce an instance's basic-auth settings), and is
  written mode `0600` on every write instead of the default umask, since it
  now holds a plaintext credential.
- `post_deploy` commands are now actually `[[token]]`-substituted before
  running (previously only documented, not implemented); an unresolved
  token now aborts the deploy with a named `DeployError` instead of running
  the literal, unsubstituted command.
- De-personalised all deployment-specific references (hostnames, hosting
  provider, private-forge URLs) throughout the codebase and documentation.
- The old server-rollout runbook split into `docs/installation.md`
  (first-run) and `docs/operations.md` (ongoing).
- The dashboard admin password is no longer a fixed default — the
  installer always generates or prompts for one.
- `fleet deploy --label` / the web UI's Label field is now normalised
  (slugified) instead of rejected: lowercased, non-alphanumeric runs
  collapsed to a single `-`, leading/trailing `-` stripped (e.g.
  `--label=ABC-1234` → `abc-1234`) — matching the existing behaviour for a
  branch-derived label. The deploy log records when an explicit label was
  changed by this normalisation.

### Removed
- `fleet deploy --fresh` and the web UI's "Fresh" checkbox. `fleet redeploy`
  (see Added) replaces both — one operation, one name.
- `ansible/group_vars/all.yml`'s `fleet_admin_default_password: ddev-admin`
  fixed default.
- The old server-rollout runbook (content split, see Changed).

## [0.4.0] - 2026-07-23

Operator workspace release (backfilled): per-project secret injection via `[[token]]` substitution; Typesense exposure reworked to port-based browser access with search-only keys; `fleet set-claude-token` with non-destructive propagation; `fleet shell` / `fleet ddev`; per-instance HTTP basic auth and a rotatable dashboard password; deploy logs kept after destroy; shared per-project DB dumps (hard links); server shell profile and Claude first-run onboarding; `fleet refresh-instance-config`; the `fleet tmux` workspace (instance tabs, live sidebar showing the checked-out branch, pane layouts, `tmux-reset`); per-project `git_bot` override; web UI footer with free memory/disk; mkcert local CA for the fleet user.

## [0.3.0] - 2026-07-15

Typesense edge exposure: port-based browser access to a project's
Typesense (`*.<domain>:9108` via Caddy), replacing an earlier,
incompatible path-based (`/_typesense`) design. Per-project admin +
search-only key generation.

## [0.2.0] - 2026-07-14

Fleet-core model redesign: projects→templates registry, `deploy` =
project + template + branch, instance naming as `<project>--<label>`,
public-engine/private-config repo split, hybrid config-override
ownership.

## [0.1.0] - 2026-07-02

Initial deploy engine: registry (`fleet.yml`), CLI (`deploy`/`destroy`/
`start`/`stop`/`list`), Ansible provisioning (Docker, DDEV, Caddy,
`fleet.service`), asset/secret management, web UI (FastAPI + HTMX).

[Unreleased]: https://github.com/rcuny/ddev-fleet/compare/v0.12.1...HEAD
[0.12.1]: https://github.com/rcuny/ddev-fleet/compare/v0.12.0...v0.12.1
[0.12.0]: https://github.com/rcuny/ddev-fleet/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/rcuny/ddev-fleet/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/rcuny/ddev-fleet/compare/v0.9.2...v0.10.0
[0.9.2]: https://github.com/rcuny/ddev-fleet/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/rcuny/ddev-fleet/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/rcuny/ddev-fleet/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/rcuny/ddev-fleet/compare/v0.7.2...v0.8.0
[0.7.2]: https://github.com/rcuny/ddev-fleet/compare/v0.7.1...v0.7.2
[0.7.1]: https://github.com/rcuny/ddev-fleet/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/rcuny/ddev-fleet/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/rcuny/ddev-fleet/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/rcuny/ddev-fleet/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/rcuny/ddev-fleet/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/rcuny/ddev-fleet/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/rcuny/ddev-fleet/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/rcuny/ddev-fleet/releases/tag/v0.1.0
