---
Author: Claude Code
Reviewer: none
Last updated: 2026-09-29
Type: documentation
---

# Runbook: rolling code/config out to a live server, and changing its domain

Two related but distinct procedures for an already-provisioned host:
**§1** is the routine "ship a change" loop referenced from several other
docs (`docs/operations.md` covers the same `git pull` mechanics from the
server side). **§2** is the rarer, higher-risk procedure for moving an
existing server (and its already-deployed instances) to a new domain
and/or a new TLS/auth mode — written up here because it has real gotchas
that a plain code update never hits.

> **SSH-lockout warning.** Neither procedure below runs the full
> `bootstrap.sh` / `ansible-playbook site.yml` (§1 is a plain `git pull` +
> restart; §2 uses scoped playbooks) — but if a rollout ever *does* require
> re-running the full installer on a live host, be aware that a
> detached/root run with no controlling `sudo` session (`$SUDO_USER`
> unset) can lock your SSH login user out via `security_hardening`'s
> `AllowUsers`. Run it via `sudo` from your login shell, or set
> `FLEET_SSH_ALLOW_USERS="debian root"` explicitly — see
> `docs/installation.md` §4.

## 1. Routine rollout (code + config)

`/opt/ddev-fleet` and `/srv/fleet/config` are both `fleet`-owned git
checkouts tracking `origin/main` with read-only deploy keys (see
`docs/operations.md` "Updating the code" for the exact commands: `git
pull --ff-only` on each, then `systemctl restart fleet`). Whatever wraps
that for you — a hand-run `ssh` one-liner, or a convenience script/alias
in your own tooling — the same precondition applies:

**§1a — `/opt/ddev-fleet` must be a clean checkout before pulling.** A
`git pull --ff-only` fails (or a wrapper script that shells out to `git
pull` reports an error and refuses to continue) if there is *any*
untracked or modified file in the way — including a stray file left over
from manual debugging on the server (e.g. a one-off Ansible playbook
copied in by hand and never committed or removed). Before a rollout,
confirm the checkout is clean:

```bash
sudo -u fleet git -C /opt/ddev-fleet status --short
```

Expected: no output. If it isn't clean, move the stray file(s) out of the
way (e.g. `mv ansible/some-stray-file.yml ansible/some-stray-file.yml.local-bak-<date>`)
before retrying the pull — don't `git clean -fd` on a whim, since you
don't know whether the file was intentional server-local state until you
look at it.

## 2. Domain-change procedure (existing instances, same server)

Moving a live server's fleet domain — e.g. after acquiring a new one, or
consolidating two servers onto matching domains — while **keeping every
existing instance's database** (no destroy/recreate). This assumes the
TLS/auth mode groundwork from `docs/installation.md` ("Choosing a TLS
mode", "Choosing an auth mode", "Switching TLS mode on an existing
server") and `docs/README-authelia.md` ("Switching an existing server's
mode") is already done — this section is specifically about the
*instance*-level fallout of a domain change, which those docs don't cover.

1. **Confirm the new domain's Caddy/TLS config actually applies first**
   (`ansible/caddy-only.yml`, per `docs/installation.md`) — instances are
   unreachable under the OLD domain from the moment this lands until step
   3 below finishes for each of them, since Caddy now only has site
   blocks for the new domain.
2. **Per instance**, regenerate its fleet-managed config for the new
   domain:
   ```bash
   fleet refresh-instance-config <instance-id> --restart
   ```
   This is safe to run **two instances in parallel** (observed on a
   20-instance fleet, ~6 minutes per instance, no contention) — there is
   no shared lock between different instances' config rewrites. It is
   NOT safe to skip: the instance's `.ddev/config.fleet.yaml` (FQDN,
   `web_environment`'s `FLEET_INSTANCE_HOST`, etc.) still names the old
   domain until this runs.
3. **Known gap — `settings.local.php` is not rewritten by step 2.**
   `fleet refresh-instance-config` only rewrites `.ddev/config.fleet.yaml`
   (see `docs/cli.md`'s entry for it). It does **not** call
   `core/fleetconfig.py:write_settings_local()` — the function that
   injects Drupal's `trusted_host_patterns` — which only runs at
   `deploy`/`redeploy` time. After a domain change, every untouched
   instance's `sites/default/settings.local.php` still hardcodes the
   **old** domain in its `trusted_host_patterns` regex, so Drupal itself
   rejects the new hostname with a 400 even though Caddy/DDEV are both
   happy:
   ```
   The provided host name is not valid for this server.
   ```
   **Workaround until this is fixed in `fleetconfig.py`** (tracked as a
   product gap — `refresh-instance-config`, or `write_fleet_config`,
   should also regenerate `settings.local.php`): hand-edit the generated
   line in place on every instance, keeping a backup:
   ```bash
   for id in $(fleet list --fleet-home /srv/fleet | awk 'NR>1{print $1}'); do
     f="/srv/fleet/instances/$id/<docroot>/sites/default/settings.local.php"
     sudo sed -i.bak-$(date +%Y-%m-%d) \
       "s/trusted_host_patterns'\]\[\] = '.*';/trusted_host_patterns'][] = '^.+\\\\.<new-domain-with-dots-escaped>$';/" \
       "$f"
   done
   ```
   Substitute the real `<docroot>` (often empty, or `web`/`docroot`
   depending on the project) and escape the new domain's dots the same
   way `write_settings_local()` does (`domain.replace(".", "\\.")`).
   Confirm with a real request per instance, not just `sed`'s exit code:
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' https://<instance>.<new-domain>/
   ```
4. **Batch the restarts, don't fire them all at once.** Restarting many
   instances' containers back-to-back can trigger the DDEV mass-start
   port-allocation race (a `ddev start` FAST-FAILing on a port the kernel
   hasn't released yet) — restart in small batches (e.g. 4 at a time) and
   watch for it; `docs/operations.md`'s `--retry-port-conflict` self-heal
   only applies to `fleet start`, not to the `--restart` flag on
   `refresh-instance-config`, so a failed one needs a manual `fleet stop`
   + `fleet start` retry.
5. **`fleet refresh-ports`**, then a manual `caddy validate` as a final
   sanity check (see `docs/operations.md`'s verification checklist) — a
   domain change can leave a stale named-port site block referencing the
   old domain if a project's `ports:` catalogue embeds it anywhere
   non-templated.
6. **Smoke test before declaring done**: log in as a real per-project
   user (or basic-auth credential) against the new domain, confirm the
   dashboard, and confirm at least one `additional_hostnames` alias host
   if the project uses them (`docs/networking.md` §7) — aliases compose
   their own hostname string and are easy to miss in a spot check of only
   the primary instance URLs.

## 3. Secrets on an additional server (replicate from an existing one)

A freshly provisioned server can pass `bootstrap.sh`, start `fleet.service`,
and serve its dashboard — and still **fail every instance deploy** if it
lacks the secrets an already-running fleet server has. This bit a real
rollout: `ddev4`/`ddev3` provisioned cleanly, but the first
`fleet deploy fern ...` on the new host failed with
`unresolved token(s): [[jira-claude-token]]` purely because secrets had
never been copied over — nothing else was wrong.

There are two independent kinds of secret, and they fail differently:

- **Per-project secrets** — `/srv/fleet/secrets/<project>.env`
  (`KEY=VALUE`, `0600`, owned by `fleet`; legacy plaintext, with a host key they are
  `/srv/fleet/secrets/<project>/<KEY>.asc` ciphertexts, see `docs/operations.md`
  "Encrypted project secrets"; see this repo's
  `CLAUDE.md` "Per-project secrets model"). A project's asset `.env` (or
  any other asset file) references these as `[[token]]`, where the token
  name is the `KEY` lower-cased with underscores turned to dashes (e.g.
  `JIRA_CLAUDE_TOKEN` → `[[jira-claude-token]]`, per
  `core/secrets.py:secret_tokens`). **A missing required project secret is
  a FATAL deploy error** — `core/tokens.py` raises
  `unresolved token(s): [[…]]` and the deploy aborts. This is by design,
  not a bug: a token substitution pass that silently left a placeholder in
  a live config file would be worse.
- **Fleet-wide secret** — `CLAUDE_CODE_OAUTH_TOKEN` in `/srv/fleet/.secrets`.
  Its absence is only a **non-fatal WARNING**: the deploy proceeds, it just
  doesn't inject a Claude token into the instance's `.ddev/config.fleet.yaml`.

Don't confuse the two when triage-reading a failed deploy's log: an
`unresolved token(s)` failure almost always means a missing **per-project**
secret, not the fleet-wide Claude token (see also `docs/installation.md`
§6's clarification on when you actually need to mint a new Claude token).

**Replicate everything from an existing server in one shot.** Secret
values never touch the terminal — the tar stream is piped server to
server:

```bash
ssh <existing-host> 'sudo tar czf - -C /srv/fleet secrets .secrets' \
  | ssh <new-host> 'sudo tar xzf - -C /srv/fleet \
      && sudo chown -R fleet:fleet /srv/fleet/secrets /srv/fleet/.secrets \
      && sudo chmod 600 /srv/fleet/.secrets'
```

**Warning (encrypted secrets, FLE-21):** if the source host has a host key
(`fleet keys show` works), its `secrets/<project>/*.asc` files are encrypted to
that host's key, so the new host cannot decrypt them (and `gnupg/` must never
be copied). In that case do not copy `secrets/`: run `fleet keys init` on the
new host and re-enter each secret with `fleet secret set`. Alternatively copy
only the legacy plaintext `secrets/*.env` files and run
`fleet secret migrate --all` on the new host.

Or set secrets individually on the new host:

```bash
printf '%s' "$VALUE" | fleet secret set <project> KEY   # per-project; encrypted once `fleet keys init` has run
fleet set-claude-token <token>         # fleet-wide, writes .secrets
```

For a **first deploy** on the new server, nothing else is needed once
secrets are in place — `deploy()` reads them fresh. If you're instead
fixing secrets for instances that are **already deployed and running**,
re-inject the new values with:

```bash
fleet refresh-instance-config <instance-id> [--restart]
```

(see `docs/cli.md` for its full flag reference — the same command used in
§2 above for a domain change).

## See also

- `docs/operations.md` — the plain code/config update loop, rollback, and
  the full verification checklist referenced in step 5 above.
- `docs/installation.md` — first-run TLS/auth mode choices and how to
  switch either on an existing server.
- `docs/README-authelia.md` — switching auth mode, including the
  same-maintenance-window warning for a server currently relying on the
  deprecated `auth_bypass_cidrs` whitelist.
- `docs/cli.md` — `fleet refresh-instance-config`'s full flag reference
  and the `settings.local.php` limitation noted in step 3 above.
- `docs/networking.md` — alias hostnames, TLS certificate modes.
- `docs/installation.md` — §6, minting vs. copying the fleet-wide Claude
  token, and the deploy-blocking-token clarification referenced in §3 above.
- This repo's `CLAUDE.md` — "Per-project secrets model" section, the
  canonical description of the `[[token]]` substitution mechanism used in
  §3 above.
