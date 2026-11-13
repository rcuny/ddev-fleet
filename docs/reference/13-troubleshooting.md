# Troubleshooting

Find your symptom, read the likely cause, apply the fix. Tables are grouped by where you are in the journey. Anything else: the last section.

## Installing

| Symptom | Cause | Fix |
|---|---|---|
| Installer exits 2, "not an address of this host" | An A or AAAA record for the domain or `auth.<domain>` points elsewhere (an old AAAA breaks Let's Encrypt). | Fix DNS, or `--skip-dns-check`. "Inconclusive" means the lookup itself failed. |
| A step failed | The message names the step, line and log. | Fix the cause, run the same command again. |
| No certificate, the browser warns | Port 80/443 closed, wrong DNS, or (in `ovh_dns` mode) wrong token rights. | `journalctl -u caddy -n 100 --no-pager`; check the token rights. |
| Caddy will not start in `ovh_dns` mode | The OVH credentials are wrong. | `sudo fleet host:init --tls-mode=http01 --force && sudo fleet host:render --apply && sudo systemctl restart caddy`. |
| Finish fails: "The Caddy snippet directory ... lost its setgid bit" | A directory under `/etc/caddy/fleet` lost its group or setgid bit. | As root: `chgrp -R caddy /etc/caddy/fleet; find /etc/caddy/fleet -type d -exec chmod 2750 {} +; find /etc/caddy/fleet -type f -exec chmod 0640 {} +; systemctl restart caddy`. Or re-run the installer. |
| `/health` does not answer | A service is down. | `journalctl -u caddy -u authelia -u php8.4-fpm -u fleet-mercure -u fleet-worker@1 -u fleet-status -n 50 --no-pager`. |

## Signing in

| Symptom | Cause | Fix |
|---|---|---|
| Wizard answers 403 | No valid session cookie. | Open the setup URL again (valid 24 hours), or `sudo fleet setup:token`. 429: wait 15 minutes. |
| Wizard answers 404 "Setup is complete" | Setup is finished. | Sign in at `https://<domain>/`; to start over, `sudo fleet setup:reset`. |
| Lost the setup URL | Setup unfinished. | `sudo fleet setup:token` (the old URL stops working). |
| Forgotten password, nobody can sign in | No admin session. | `ssh -t admin@fleet.example.com sudo fleet user:reset-password alice`. |
| `user:reset-password` says it needs a terminal | Run without a TTY. | Use `ssh -t`. |
| Sign-in refused after a reset ("... they are banned") | Authelia bans a user for 5 minutes after 3 failed attempts in 2 minutes; each attempt while banned extends it. | Wait 5 minutes without trying, then sign in. |
| After a reboot the portal answers 502 | Authelia's tmpfiles rule stripped the execute bit of `/etc/authelia/secrets`. | Check `/etc/tmpfiles.d/authelia.conf` exists and `stat -c %a /etc/authelia/secrets` prints `750`; re-run the installer. |
| "Forgot password?" is missing | No SMTP server is configured. | [07-hardening.md](07-hardening.md#authelia-forgot-password-by-e-mail). |

## Deploying and jobs

| Symptom | Cause | Fix |
|---|---|---|
| Job `interrupted` | A worker or the host stopped. | Read the log, Retry. |
| Job stays `queued` | Instance busy, job slots full, or no worker. | `systemctl status fleet-worker@1`. |
| "port is already allocated" | Another project uses the port. | Stop that one, or `--retry-port-conflict`. |
| `ddev mysql`, `export-db`, `import-db` or a snapshot fail with `ERROR 1044 ... ''@'localhost'` | The `fleet` user is uid 999, which is `mysql` inside DDEV's db image, so the client connects as `mysql@localhost`, an account that did not exist (the anonymous `''@localhost` answers, with no rights). | Fleet creates `mysql@localhost` (unix_socket only) after each start. Restart or redeploy the instance. |
| Deploy fails after start | A post-deploy line failed, or a token is unresolved. | The job log shows the line; fix the line or the secret. |
| A terminal command did not run | Unresolved token or unsafe value in a `tty1`/`tty2` line. | Skipped with a `WARNING` in the job log; the deploy still succeeds. |
| Webhook returns 401 / 404 | Wrong signature, or no such project or secret yet. | `fleet webhook:secret`; re-enter it in the sender. |
| Webhook delivery is `failed` | Template gone, instance busy, or no pipeline token. | Cause a fresh event (move the issue out and back in, push again): a refusal is never retried. |
| Webhook delivery is `duplicate` | A retry, or the same body within an hour. | Expected; a failed action also consumes its id. |

## Opening a site

| Symptom | Cause | Fix |
|---|---|---|
| Instance runs, site does not open | The wildcard record `*.<domain>` is missing, you are not a member of the project, or Caddy refused the site (Let's Encrypt weekly limit). | Check DNS and membership; `journalctl -u caddy -n 100`. |
| A named port does not open | Port not open in the firewall, or Caddy refused the site. | `fleet port:firewall`; fix the cause, then `fleet port:refresh`. |

## Assets and secrets

| Symptom | Cause | Fix |
|---|---|---|
| "more than the upload limit" | File above `asset_upload_max_bytes`. | Copy it in over SSH. |
| "not enough free disk space" | The disk holding `/srv/fleet` is full. | Free space. |
| Upload stops at "The connection was lost" | Network drop. | Choose the same file again; it resumes. |
| A file copied over SSH does not show | Not rescanned, wrong owner or path. | **Rescan**; owner `fleet`, path `/srv/fleet/assets/<project>/`. |
| "A symbolic link is in the way" | A link where a folder should be. | Replace it with a real folder, or copy the data. |
| Secret value fails silently | The value was wrapped in quotes in the asset file as well (`""abc""`). | Keep the quotes out of the stored value; Fleet refuses one pair. |
| "The passphrase is wrong, or the sealed secrets are damaged." (import) | Wrong export passphrase. | Use the export passphrase; nothing was written. |
| "A passphrase is needed" | No terminal. | Set `FLEET_EXPORT_PASSPHRASE` or use `--passphrase-stdin`. |
| "already has a port with this name and other numbers" | Port definitions differ between hosts. | Align the port numbers, export again. |
| "is not a known export version" | The reading host is older. | Update it. |

## Webhooks and schedules

| Symptom | Cause | Fix |
|---|---|---|
| A schedule never fires | Paused, or `fleet-scheduler` / the worker is not running. | `systemctl status fleet-scheduler fleet-worker@1`; `fleet schedule:resume`. |
| Runs show `skipped` | Target missing or busy. | Create the target; it is not retried. |
| Runs show `missed` | Downtime longer than 60 minutes. | Only the latest missed occurrence under 60 minutes old catches up. |

## Upgrading

| Symptom | Cause | Fix |
|---|---|---|
| Check says `not_a_release` | The host runs a branch or development tree. | Install a release with `--ref=vX.Y.Z` first. |
| `checkout.dirty` blocks | Files in `/opt/ddev-fleet` changed. | Move your changes out; it cannot be forced. |
| Installer failed halfway | Nothing is rolled back for you. | Run the same command again; `sudo fleet upgrade --to <previous>` is the way back. |
| Verification failed (exit 5) | The new release does not start cleanly. | Run the rollback command it prints. |
| Check exits 4 | The release source is unreachable. | Restore the connection or use `--repo-url`. |

Page-by-page detail: [05-upgrade.md](05-upgrade.md), [06-backup-and-restore.md](06-backup-and-restore.md).

## After a reboot

| Symptom | Cause | Fix |
|---|---|---|
| Nothing runs | Instances did not restart. | `journalctl -u fleet-boot`. |
| Portal answers 502 | Authelia secrets permission, see "Signing in". | Re-run the installer. |

## Network isolation

- **A link stays `pending`** (`fleet host:isolation:status`, instance page): the reason names what is missing, for example the target instance (`<target>--<label>` does not exist), or the target service (the compose service has another name, or the container is not created). Fix that, start the instance, wait for the next pass (two minutes).
- **Every site answers 502 after `enforce`:** the router is not in the table. `fleet host:isolation:status` exits 1 and says "the router set is empty". Run `fleet host:isolation:enable --mode=enforce --yes` to force a pass; if it persists, `fleet host:isolation:disable` and look at `docker logs ddev-router`.
- **A container cannot reach the host any more:** the port is not on the allow-list. `status` lists `container -> host` flows; add the port under **Settings → Isolation**.
- **Roll back at once:** `fleet host:isolation:disable`, or as root `nft delete table inet fleet_isolation`.

## Still stuck

`fleet <command> --help`, the job log, and:

```bash
journalctl -u caddy -u authelia -u php8.4-fpm -u fleet-worker@1 -u fleet-status -n 50 --no-pager
```
