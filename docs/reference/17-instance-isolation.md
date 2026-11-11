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
