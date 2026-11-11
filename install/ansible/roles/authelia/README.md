# authelia

Port of the former bash step 60-authelia.sh, behaviour for behaviour. Always on, after `caddy`.

Does: the tmpfiles mask (`/etc/tmpfiles.d/authelia.conf`, before the package), the Authelia apt repository (pinned key
fingerprint) and package (no service start during the install; the step has no version pin, apt installs the repository's current
`authelia`), stops a freshly installed unit, the three secrets (each generated once with `openssl rand -hex 32`, only when its own
file is missing or empty, `0640 root:<group of the unit>`), `/var/lib/authelia`, the traverse-only ACL on `/srv/fleet`,
`/srv/fleet/authelia` (`2750`, setgid) with a disabled placeholder `users.yml` (`force: false`), the no-default-account
assertion, the `secrets.conf` drop-in and the tmpfiles proof that `/etc/authelia/secrets` keeps `0750`.

Never does: start or reload Authelia. **Not written, owned by `apply authelia`:** `/etc/authelia/configuration.yml` and the real
users in `users.yml` (an existing file is never overwritten; only a missing, empty or `"users":{}` one is replaced by the
placeholder, as in bash).

The user and group come from the installed `authelia.service`, never assumed (a check run on a host without the package assumes
`authelia`). Secrets use `no_log`. The tmpfiles and drop-in files are read from `install/etc/` (one copy, shared with the bash step).
