# Server rollout runbook

Ordered checklist for the first provisioning of a ddev-fleet host. Run
each step in order. Steps in section 6 correspond to spec §16 items that
could not be confirmed inside the companion dev container (no
Docker/systemd/caddy binaries there) and MUST be confirmed live, on the
real host, before it is considered production-ready.

## 1. DNS

Before running bootstrap, create the wildcard DNS record. A wildcard
matches exactly one label (spec §9.4), so both of the following are
required — `fleet.<domain>` is NOT covered by the `*.fleet.<domain>`
wildcard:

```
fleet.<domain>       A     <server-ip>
*.fleet.<domain>     A     <server-ip>
```

## 2. Run bootstrap

```bash
# Repo is PRIVATE — the bare raw-URL one-liner is auth-gated. Either:
#   (a) scp bootstrap.sh to the server, then: sudo bash bootstrap.sh
#       (export FLEET_REPO_URL=https://<user>:<app-password>@bitbucket.org/personal_maintainer/ddev-fleet.git first), or
#   (b) pre-clone the repo to /opt/ddev-fleet manually, then: sudo bash /opt/ddev-fleet/bootstrap.sh
# public-repo variant: curl -fsSL https://bitbucket.org/personal_maintainer/ddev-fleet/raw/main/bootstrap.sh | sudo bash
```

The first run uses the placeholder `fleet_admin_bcrypt_hash` in
`ansible/group_vars/all.yml` — basic auth will not yet accept a real
password. Continue to step 3, then re-run bootstrap (or just re-run the
playbook, step 3 below).

## 3. Generate the admin password hash

```bash
caddy hash-password
```

Paste the output into `fleet_admin_bcrypt_hash` in
`/opt/ddev-fleet/ansible/group_vars/all.yml`, then redeploy the Caddyfile:

```bash
ansible-playbook -c local /opt/ddev-fleet/ansible/site.yml
```

## 4. Add the deploy key to each forge

```bash
cat /srv/fleet/fleet-deploy-key.pub
```

Add it as a **read-only** deploy key on Bitbucket (and GitHub, if used)
for every project registered in `fleet.yml`.

## 5. `fleet init` — mint the Claude Code token

```bash
sudo -u fleet -i
cd /opt/ddev-fleet
venv/bin/fleet init
```

Runs `claude setup-token` as `fleet`, writes `CLAUDE_CODE_OAUTH_TOKEN` into
`/srv/fleet/.secrets` (`600`).

## 6. Verification items (spec §16 — confirm live here, not in-container)

- **(a) `config.fleet.yaml` merge order.** Deploy a test instance, run
  `ddev describe -j` inside its directory, confirm `name`/`project_tld`
  reflect the fleet-injected values ahead of the project's own
  `config.yaml`/`config.local.yaml`. If they do not, fall back to
  `ddev config --project-name=<instance-id>` invoked explicitly at deploy
  time — tracked as a Plan 3 follow-up, not this plan.
- **(b) Cheapest `web_environment` reload path.** On a running test
  instance, change `CLAUDE_CODE_OAUTH_TOKEN` in `.ddev/config.fleet.yaml`,
  try `ddev restart`, then confirm with
  `ddev exec env | grep CLAUDE_CODE_OAUTH_TOKEN` that the new value took
  effect. Record whether `ddev restart` suffices or a full
  `ddev stop && ddev start` is required — this determines the
  implementation of `fleet refresh-claude-token` in Plan 3.
- **(c) Caddy on-demand TLS + Host pass-through end to end.** After
  deploying a test instance, request `https://<instance-id>.<fleet_domain>`
  from an external client; confirm Caddy requests a certificate (check
  `journalctl -u caddy`), the `/api/tls-authorize` call succeeds, and the
  correct project responds — not a different instance and not a TLS or
  routing error.
- **(f) `caddy validate` on the generated Caddyfile:**

  ```bash
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
  ```

  (Already wired as the `validate:` parameter on the caddy role's
  `template` task in `ansible/roles/caddy/tasks/main.yml` — every apply
  re-validates automatically. This command is the manual/interactive
  re-check.)

Additional live checks, not in spec §16 but load-bearing for this plan:

- **`systemd-analyze verify fleet.service`** — confirm the generated unit
  has no structural errors:

  ```bash
  systemd-analyze verify fleet.service
  ```

- **DDEV router binds loopback-only** — confirm it is not reachable from
  outside the host:

  ```bash
  ss -tlnp | grep -E ':(8080|8443)\b'
  ```

  Expected: both listeners show `127.0.0.1:8080` / `127.0.0.1:8443`, never
  `0.0.0.0` or `:::`.

## 7. Start the fleet daemon

Only after section 6 passes:

```bash
sudo systemctl start fleet.service
sudo systemctl status fleet.service
```

## 8. First deploy

Pick a project already registered in `/srv/fleet/fleet.yml` (or register
one with `fleet project add`) and run, as the `fleet` user:

```bash
fleet deploy <project> <instance> --branch=<ref>
```

then browse to the printed instance URL.
