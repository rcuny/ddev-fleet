# units

Port of `install/steps/95-units.sh` and `98-render-start.sh`.

1. The unit files of `install/etc/` (worker, status, boot, scheduler, backup, release check, certificate check, tmux,
   Mercure) and the pinned, sha256-checked Mercure hub with its marker file.
2. `fleet:host:render --stage`, then the root helper installs the Caddy and Authelia configuration.
3. Enable and start everything, wait for `/health`, the hub and the units, and record `installed-revision` and
   `installed-version` (the tag when `fleet_ref` is a tag of the checkout, else `<ref>@<sha7>`).
4. The one-time setup token is **not** minted here (it is a credential and must not reach an Ansible log): the bootstrap
   prints it in its final notes, as the bash installer does; the role only says to run `sudo fleet setup:token`.

**Restarts happen only for a reason:** php-fpm when its pool, settings or `fleet.env` changed, a reload when only the code
changed; workers, status watcher and scheduler when the code, `fleet.env` or their unit changed; the hub when its binary,
configuration, unit or keys changed. A run that changes nothing restarts nothing. Differences from bash: Authelia is
restarted by the helper only when its configuration differs from the staged one (bash: every run), Caddy only when
`fleet_caddy_changed` is true (bash: every run; the helper still reloads it).

`command` tasks are skipped in `--check` (render, helper, health): a drift report shows files and units only.
