# ddev

Ansible port of `install/steps/40-ddev.sh`.

- DDEV's apt repository: key `/etc/apt/keyrings/ddev.asc` checked against the pinned fingerprint, source
  `/etc/apt/sources.list.d/ddev.list`, package `ddev` (no version pin, as in the bash step);
- the fleet user's global config: router on ports 8080/8443, bound to loopback, instrumentation off;
- mkcert's local CA, NSS store only, once.

The global config is still written by `ddev config global` (DDEV owns that file's format and validation). It is not
a bash script, but a command task that runs only when `~/.ddev/global_config.yaml` does not already hold the four
values, so a converged host reports changed=0. The bash step runs it on every install.

Variables (defaults in `group_vars/all.yml`): `fleet_ddev_key_url`, `fleet_ddev_key_fingerprint`,
`fleet_ddev_router_http_port`, `fleet_ddev_router_https_port`.
