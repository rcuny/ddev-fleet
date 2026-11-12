# Instance isolation and what projects may not do

Fleet clones a project repository and runs its DDEV project. The repository is not trusted: a `.ddev/docker-compose.*.yaml` or `.ddev/config.*.yaml` it ships can ask Docker for almost anything. The most dangerous request is the **Docker socket**. A web container that mounts `/var/run/docker.sock` is root on the host: it can run any container, read every other instance, `/srv/fleet` and the Fleet database. Code that runs in such a container (a vulnerable dependency, a compromised package, an agent) inherits that.

## The deploy guard

Before every `ddev start` or `ddev restart` Fleet makes (deploy, redeploy, start, restart, and the repairing start of the router converge), one shared check reads the instance's `.ddev/docker-compose*.y(a)ml` and `.ddev/config*.y(a)ml` (the project's own, the seeded assets and the files Fleet wrote). The job is **refused** (state `failed`, nothing started, the log names the file and the key) when it finds any of:

| What | Example |
|---|---|
| The Docker socket anywhere in a value (a mount, a hook that runs `chmod` on it) | `/var/run/docker.sock:/var/run/docker.sock` |
| `privileged: true` | `services.web.privileged` |
| A host namespace | `network_mode: host`, `pid: host`, `ipc: host`, `userns_mode: host`, `uts: host` |
| Capabilities that escape the container | `cap_add: [SYS_ADMIN]`, `NET_ADMIN`, `ALL` |
| Host devices | `devices:` |
| The sandbox switched off | `security_opt: [seccomp=unconfined]`, `apparmor=unconfined` |
| A bind mount of a host path outside the instance directory | `/:/host`, `~/.ssh:/ssh`, `../../other:/o`, a volume with `driver_opts` `o: bind` and an outside `device` |
| Another container's namespaces, cgroups and kernel settings | `network_mode`/`pid`/`ipc` of `container:` or `service:`, `cgroup: host`, `cgroup_parent`, `sysctls`, `volumes_from: container:...`, `security_opt` `label=disable` or `no-new-privileges:false` |
| Files that are never scanned | `include:`, `extends: {file: ...}` |
| Commands on the host | `exec-host` / `exec_host` hooks in any `config*.yaml` (they run as the fleet user at start) |
| A file that cannot be verified | a symlink, a directory, an unreadable or unparsable file, a `.ddev` that is a symlink, a `services` or `volumes` value that is not a mapping, a host path starting with a variable other than a bare `${DDEV_APPROOT}`, `${DDEV_DOCROOT}` or `${DDEV_COMPOSER_ROOT}` |
| Web image build | `.ddev/web-build/*` (top-level files, except Fleet's own `Dockerfile.fleet-*`): a `docker.sock` mention, installing a Docker CLI or engine package (`docker.io`, `docker-ce`, `docker-ce-cli`, `docker-cli`, `docker-compose-plugin`, `containerd.io`, Alpine's bare `apk add docker`, or Docker's `get.docker.com` install script), or a setuid `chmod` (`u+s`, `a+s`, `+s`, `4755`; setgid alone, `g+s` or `2775`, is allowed). Whole-line `#` comments are ignored; subdirectories of `web-build` (build contexts) are not scanned. `docker.io/...` as an image registry prefix is fine. Other `curl \| sh` and `ADD <url>` are not refused |

Bind sources are resolved (the DDEV variables to the instance directory, `..` after a variable is refused) and followed through symlinks, so a symlinked subdirectory cannot lead out of the checkout. Fleet's own CPU slice override (`docker-compose.fleet-cgroup.yaml`, a `*.slice` `cgroup_parent`) is allowed.

Not refused: `.ddev/commands/host/*`. They run only when an administrator invokes them on the host as `fleet`, never at start. A project that ships `exec-host` hooks (the Fleet development project does) needs the project switch below.

Allowed: named volumes, paths inside the instance directory (relative paths count from the `.ddev/` directory), DDEV's own `${DDEV_*}` variables, and the instance's own ssh-agent socket directory that Fleet mounts for projects that use Git over SSH.

This is a best effort on the files DDEV reads at start. It does not stop a project that commits an image or a post-start script doing the same thing in another way, so keep treating project code as untrusted.

### The project switch

An administrator can allow a project to keep such overrides: **Project → Keys → Privileged compose overrides**. It is off by default, it asks for your password again, from the root CLI, `sudo fleet project:update <project> --allow-privileged-overrides` (or `--no-allow-privileged-overrides`), it is not part of project creation, the export or the import (no import can turn it on), and every change is written to the audit log (`project.privileged-overrides`). While it is on, each deploy that relies on it logs a warning and writes `deploy.overrides-allowed` with the violations it let through. Turn it on only for a project whose code you fully trust: its containers can then take over the host and every other instance on it.

## Checking existing instances

`sudo fleet instance:audit-overrides [--json]` is a read-only report of the instances whose `.ddev/` files the guard would now refuse, with the file, the key and the reason, and whether the project allows it. It changes nothing; exit code 1 means at least one would be refused. Fix the project (or redeploy after the repository dropped the override), then redeploy the instance.

## The socket on the host

The host's `/var/run/docker.sock` must be `root:docker` mode `0660`. A project hook that runs `chmod 666 /var/run/docker.sock` makes it writable by everyone. The status watcher checks it every minute; when it is wrong it logs a warning, writes `host.docker-socket` to the audit log and shows a warning on **Settings**. The watcher runs as the `fleet` user and cannot change the permissions of a root-owned socket, so the fix is `sudo fleet host:docker-socket --restore` (without `--restore` it only checks; exit code 1 = not correct). Restoring does not remove the cause: find and redeploy the instances that mount the socket (`audit-overrides`).

## Network isolation between instances

All DDEV containers of a host share the Docker network `ddev_default`, so by default a container of one instance can
reach the web, database, Typesense and Mailpit containers of every other instance, `ddev-router` past Caddy and Authelia,
and the host's own ports (including `ssh`) through the bridge gateway. Network isolation closes that. It is **off** on every
host until an administrator turns it on, it is never switched on by the installer, an upgrade or a deploy, and it can be
rolled back in one command.

### How it works

- **One nftables table, `inet fleet_isolation`**, owned by Fleet and independent of Docker's firewall backend and of ufw.
  Both chains use `policy accept` and run before Docker's chains: a missing table changes nothing, and only the mode decides
  whether the last rule drops. It filters traffic that enters from container bridges only (`ddev_default`, project networks,
  `docker0` and the link networks); traffic from outside the host is never touched.
- **Between instances (the forward hook):** the router may reach every routed service; an instance may reach the router's
  HTTP ports (80, 443, 8080, 8443, 8025, 8026 and the public catalogue ports, so the in-network port mirror and in-container
  browser tests keep working) but not its Traefik API (10999); ICMP stays. Every other new flow on `ddev_default` is recorded
  and, in enforce mode, dropped.
- **From containers to the host (the input hook):** 80, 443, the public catalogue ports, the extra ports an admin allows,
  DNS to the gateway and ICMP. Everything else, including `ssh` (22), is recorded and, in enforce mode, dropped.
- **Declared links** are the only way through. A link is a rule of a *project* (see below). For every instance pair it
  resolves to, Fleet creates one Docker network, `fleet-link-<source>--<target>--<service>--<port>`, with `--internal` (no
  NAT, no route to the Internet) and a stable bridge name `flk-<8 hex>`, attaches the source's `web` container and the
  target service with the alias `<service>.<target project>`, and adds one rule pair for that bridge: new connections to the
  one port are accepted, anything else is dropped. A link is **one way**: the target cannot connect back.
- **Modes:** `off` (no table), `report` (the table exists, records every flow, drops nothing) and `enforce` (drops what no
  link allows). The mode is a host setting, separate from `--harden`.

### Declaring a link

```bash
# The web containers of every "app" instance may reach port 8108 of the "typesense" service of the "cms" instance
# with the same label (app--staging reaches cms--staging):
fleet project:link:create app cms --service=typesense --port=8108 --note="search"
fleet project:link:list app
fleet project:link:delete app cms --service=typesense --port=8108
```

`--label=same` (default) reaches the target project's instance with the source instance's own label; `--label=<label>`
reaches that one instance from every source instance. Inside `app--staging`'s web container the service is
`http://typesense.cms:8108`. Links are project-level, admin-only, audited (`project.link-create`, `project.link-delete`),
shown on both projects (Network tab) and exported with the project (`links`, [export format](12-export-format.md)). A link
whose target instance or service does not exist yet is `pending` and becomes live at the next pass; it is not an error.
Links go live within about two minutes (the status watcher) or at the next start of either instance.

### Rolling it out

```bash
fleet host:isolation:status              # mode, table, links and the recorded flows
fleet host:isolation:enable --mode=report
# ... a few days of normal work ...
fleet host:isolation:status              # every flow, with the project:link:create line that would allow it
fleet host:isolation:enable --mode=enforce --yes
fleet host:isolation:check               # live probe: exit 1 on any unexpected reachability
fleet host:isolation:disable             # roll back: the table is deleted at once, nothing restarts
```

`enforce` always needs `--yes` and first prints every recorded flow no link covers. A new host starts with isolation **off**;
the advice is `report` for a few days, then `enforce`. On a host that colleagues use, stay in `report` until the owners of
the projects have seen the list. Containers that must reach the host on another port (an IDE listening for Xdebug on 9003)
need that port in **Settings → Isolation → extra host ports** (`HostSettings.isolation_host_ports`).

`fleet host:isolation:check` runs from the `web` container of one running instance per project and tries every other
instance's web (80, 8025), database (3306) and Typesense (8108) where those containers exist, the router's Traefik API
(10999), the gateway's port 22 and the Internet (port 443 of a public address), plus every live link. `off` and `report` expect everything
reachable; `enforce` expects only the Internet and the link targets. Exit 0 = every probe behaved as expected, 1 = not,
3 = Docker did not answer.

### What it does not do

- **The router stays reachable from instances.** An instance can still ask `ddev-router` for another instance's host name
  and get it without the Authelia check (closing that needs a Traefik allow-list per instance; planned).
- Network changes (a recreated container, a router restart) are caught by the two-minute watcher, a start, and the router
  converge; a Docker events listener is planned. After a router restart, instances answer 502 from Caddy until
  the router's new address reaches the table (the same pass does it right after `docker restart ddev-router`).
- No egress filtering to the Internet, and no isolation inside one instance.

### Risks and how to roll back

| Risk | What to do |
|---|---|
| A wrong ruleset keeps the router out of every web container | `nft -c -f` validates before install and both chains accept by default. Roll back: `fleet host:isolation:disable`, or as root `nft delete table inet fleet_isolation`. |
| Something relied on cross-instance traffic | `report` mode lists it first. In `enforce`, add the link, or `disable`. |
| The router's address changed and the table is stale | `fleet host:isolation:status` exits 1 in enforce mode (empty router set while instances run); the next pass fixes it, or `fleet host:isolation:enable --mode=enforce --yes` forces one. |
| The host rebooted | `fleet-isolation.service` loads the last ruleset before `fleet-boot` starts the instances (the file `/etc/ddev-fleet/fleet_isolation.nft` exists only while the mode is not `off`). |

The root helper's target is `sudo fleet-apply isolation`: the app stages `staging/isolation/fleet_isolation.nft`, the helper
validates it line by line (only the table `inet fleet_isolation`), checks it with `nft -c -f` and loads it atomically. With
nothing staged it prints the live table as JSON, which `status` reads (it is how the recorded flows are collected).

