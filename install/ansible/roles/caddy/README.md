# caddy

Port of `install/steps/50-caddy.sh`, behaviour for behaviour. Always on, after `docker`.

Does: the Caddy apt repository (pinned key fingerprint), the package (no service start during the install), the
`dpkg-divert` of the packaged binary to `/usr/bin/caddy.default`, the custom build `/usr/bin/caddy.custom` with the OVH DNS
module (downloaded, verified: it runs, lists `dns.providers.ovh`, is at least the packaged version and `fleet_caddy_min_version`;
an installed build that still meets the minimum is kept when the download fails), `update-alternatives` (10 default, 50 custom,
auto), the `list-modules` assertion on `/usr/bin/caddy`, the two `caddy.service.d` drop-ins, and the snippet directories
`/etc/caddy/fleet{,/instances,/ports}` (`2750 fleet:caddy`, setgid) with the directories below them normalised to `2750` and the snippets under `instances/` and `ports/` to group `caddy` `0640` (other files, such as `stored-certs.list`, are left alone).

Sets the fact `fleet_caddy_changed` when the custom build was replaced or a drop-in changed; the `units` role then restarts Caddy once at the end of the run (a no-op run restarts nothing).

Never does: start, restart or reload Caddy (a changed drop-in only triggers `systemctl daemon-reload`, as in bash), so live routes
stay up. **Not written, owned by `apply caddy`:** the Caddyfile, every per-instance and port snippet, `/etc/caddy/fleet.env`
and `/etc/caddy/ovh/`. Existing snippets only get `group caddy` / `0640` (directories `2750`), no content change.

Check mode: `command` tasks are skipped (the divert, the alternatives, the download); a missing or old custom build is reported
by a message instead.

The drop-in files are read from `install/etc/` (one copy, shared with the bash step).
