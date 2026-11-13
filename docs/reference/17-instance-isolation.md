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
  one port are accepted, anything else is dropped. A link is **one way at the packet level**: the rule accepts only a *new* connection from the source container's address to the target container's address and port (replies are "established"); every other new flow on the link network, including one opened by the target, is dropped. In `report` mode nothing is dropped anywhere, link networks included.
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
Creating or deleting a link runs a pass at once, so a deleted link is closed immediately; a new instance or a restart is picked up at the next start of either side or by the watcher within about two minutes. A pass never fails a deploy or a start: errors are logged and the next pass retries. Two passes never run at the same time, not even in two processes (the CLI and the watcher share a lock file), and each pass reads the stored mode again, so a mode set from the CLI is seen by the running watcher at once. The root helper writes the files under `/etc` (`/etc/ddev-fleet`, `/etc/modules-load.d`, `/etc/sysctl.d`) before it loads the table and only when their content changed; when a write fails the live table is left exactly as it was. The watcher, the workers and php-fpm run in a read-only `/etc` sandbox, so their units list those three paths as writable.

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
instance's web container (80, 8025), the router's Traefik API (10999), the gateway's port 22 and the Internet (port 443 of a public
address), plus every live link, and checks that `br_netfilter` is active. Databases and search servers are never probed: they sit on
the project's own network and are never reachable from another project in any mode. It asserts only what the mode promises:
`enforce` expects the web containers, the router API and ssh blocked, and the Internet and the link targets reachable; `off` and
`report` promise no reachability (a host firewall may block ssh), so those probes are listed as `any` and never fail, while the
Internet and live links must be reachable in every mode. Exit 0 = every probe behaved as expected, 1 = not,
3 = Docker did not answer.

### `br_netfilter` is required

The table filters traffic between two containers of one bridge, and the kernel shows that traffic to nftables only when the module `br_netfilter` is loaded and `net.bridge.bridge-nf-call-iptables` and `-ip6tables` are `1`. A stock Debian host has neither, so without them `enforce` would look active and filter nothing between instances. Turning isolation on makes the root helper load the module, set both sysctls and keep them across reboots (`/etc/modules-load.d/fleet-isolation.conf`, `/etc/sysctl.d/90-fleet-isolation.conf`); it refuses to install the ruleset when they are not active, and then `fleet host:isolation:enable` fails with a clear message and the mode stays as it was. `fleet host:isolation:check` and `status` report it, and `fleet-isolation.service` refuses to start at boot without it. `host:isolation:disable` removes the two files (the module stays loaded until the next reboot).

### UDP, IPv6 and other protocols

Only TCP flows are recorded and listed by `status`. In `enforce` mode a new UDP, IPv6 or other-protocol flow between instances, or from a container to the host, is dropped like any TCP flow, with these exceptions: ICMP and ICMPv6, and DNS (UDP 53) to the bridge gateway. A project that needs UDP between instances (or a UDP port on the host) has no link for it: leave it in `report`/`off`, or use a TCP service.

### `nftables.service` must stay disabled

Debian's `nftables.service`, when enabled, loads a file that begins with `flush ruleset` and flushes the whole ruleset when it stops or reloads, which would wipe Docker's tables, the firewall's and `fleet_isolation`. Fleet never enables it. `fleet host:isolation:enable` and the root helper refuse to load a ruleset while it is enabled or active (`systemctl disable --now nftables.service` first), `status` reports it as a problem, and the installer prints a warning when it finds it enabled.

### After a plain `docker restart ddev-router`

A restart that Fleet does itself refreshes the router's address in the table at once. A restart done by hand (or by DDEV) gives the router a new address that the table learns at the next pass: the status watcher notices the change within about two minutes, and in `enforce` mode the sites answer 502 from Caddy until then (measured on a throwaway host: up to about 3 minutes, mostly the watcher interval). The unit `fleet-isolation-watch` closes that window: it listens to `docker events` and runs a pass about a second after a ddev container or the `ddev_default` / `fleet-link-*` networks change (a plain `docker restart ddev-router` recovers in about a second; a router that comes back with a new address, within a second or two). If the unit is down, the status watcher's two-minute pass is the backstop. Check it with `systemctl status fleet-isolation-watch` and `journalctl -u fleet-isolation-watch`; to force a pass by hand run `fleet host:isolation:enable --mode=enforce --yes`.

### What it does not do

- **The router path is allow-listed per instance (`enforce` mode only).** An instance can still *connect* to `ddev-router`
  (the port mirror and in-container tests need it), but Fleet writes `~/.ddev/traefik/custom-global-config/fleet-router-access.yaml`:
  for every instance a Traefik router (HTTP and HTTPS, priority 100000, bound to the router's live entry points except the mirror's `fleet-mirror-*` ones: an HTTP TLS router on the `:443` mirror would terminate TLS there and answer 404 for an instance's own name) that answers `noop@internal` (not the site) to a request
  for the instance's host names unless the source is the `ddev_default` gateway (Caddy), the loopback, one of the instance's own
  containers, or the web container of a live link's source. When the `:443` site mirror is active the same rule is applied by
  SNI on its TCP entry point: it forwards an instance's names only for that instance's allowed sources (priority 200000) and
  drops every other connection (another SNI, or none). The mirror passes the connection through to the router's https entry point
  with a PROXY protocol v2 header, accepted from the loopback only (09-networking.md), so the HTTP deny routers there still see the
  real client: `ClientIP(A)` with `Host: A` is served, `ClientIP(A)` with `Host: B` answers 418 even when the SNI is A's. Until the
  router holds the new mirror files (next paragraph) the re-entry still looks local and that last case is open. DDEV copies `custom-global-config` into the router's own volume only at a `ddev start`, so Fleet also puts the file into
  `ddev-router` itself (`/mnt/ddev-global-cache/traefik/config/`, copied under a temporary name and renamed) on every isolation pass
  and compares the router's copy with the host file; Traefik's file watcher loads it without a restart (measured live: enable, link
  changes and disable take effect without any instance restart). The check `host:isolation:check` has a row for that copy.
  The router keeps its own copies of the port mirror files too (its static configuration and the dynamic file), refreshed by DDEV only at a
  `ddev start`, and a static change needs a router restart. `fleet port:refresh` (the installer runs it after every upgrade) and
  `host:isolation:enable` push both copies into the router when they differ (the static fragment is merged into the router's own static file,
  found from `/etc/traefik/traefik.yaml`, a symlink to `.static_config.yaml`, or a `--configFile` argument) and restart `ddev-router` once if the static part changed or the PROXY trust is not live yet, and only after the API shows the trust do they switch the `:443` mirror to send the PROXY header (until then it works without it and isolation is not enforced on `:443`) (every instance
  blinks for a few seconds; the command says so). The two-minute isolation pass pushes the files but never restarts, and logs that a restart is
  pending. `host:isolation:check` compares the router's copies with the host files and the live PROXY trust. `report` and `off` always remove it (every pass, even when no ruleset is recorded). A declared link opens **all ports** of the target through the router for the link's source web container (the allow-list is by source address and host name, not by port); the link's own port limit applies only to the direct link network. If the Traefik API does not answer during a pass, the entry points of the last good file are kept (a warning is logged; with no earlier file the deny routers bind to every entry point, closed, and an instance's own name over `:443` may answer 404 until the API is back). Residuals: the TLS-terminating named-port mirrors
  (for example Typesense on 9108) still re-enter from the loopback, so an instance can reach another instance's *exposed service*
  by `Host` there; and for about a second after a container gets a new address, its own request for its own host name through the
  router is denied.
- Network changes (a recreated container, a router restart) are caught by the Docker events listener (`fleet-isolation-watch`,
  about a second), a start, the router converge and, as a backstop, the two-minute status watcher. After a router restart,
  instances answer 502 from Caddy until the router's new address reaches the table (see above).
- No egress filtering to the Internet, and no isolation inside one instance.

### Risks and how to roll back

| Risk | What to do |
|---|---|
| A wrong ruleset keeps the router out of every web container | `nft -c -f` validates before install and both chains accept by default. Roll back: `fleet host:isolation:disable`, or as root `nft delete table inet fleet_isolation`. |
| Something relied on cross-instance traffic | `report` mode lists it first. In `enforce`, add the link, or `disable`. |
| The router's address changed and the table is stale | `fleet host:isolation:status` exits 1 in enforce mode (empty router set while instances run); the next pass fixes it, or `fleet host:isolation:enable --mode=enforce --yes` forces one. |
| `fleet host:isolation:disable` failed half way | The table is removed first; if the helper was down, the mode is still saved as off and the next pass retries the removal. |
| Recorded flows | A re-apply carries at most the last 2000 TCP flows and 500 host flows (one element per line); older ones are forgotten. |
| The host rebooted | `fleet-isolation.service` loads the last ruleset before `fleet-boot` starts the instances (the file `/etc/ddev-fleet/fleet_isolation.nft` exists only while the mode is not `off`). |

The root helper's target is `sudo fleet-apply isolation`: the app stages `staging/isolation/fleet_isolation.nft`, the helper
validates it against an allowlist (the fixed header, then only `set` and `chain` blocks of the one table `inet fleet_isolation` whose every line is one of the rendered patterns; nothing outside the table, no other table, chain or rule), checks it with `nft -c -f` and loads it atomically. With
nothing staged it prints the live table as JSON, which `status` reads (it is how the recorded flows are collected).

