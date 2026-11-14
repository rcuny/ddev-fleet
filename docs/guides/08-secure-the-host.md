# Secure the host

Fleet is built so one process faces the Internet and everything else sits behind it. This page explains that model in plain words and shows the optional hardening for the host itself.

## The security model

- **One Internet-facing process.** Caddy terminates TLS for every name and forwards to everything else.
- **A login portal.** Only `/health`, the signed `/hooks/*` receivers and the bearer-token `/api/v1/*` API skip sign-in; so does a request to the live-feed hub `/.well-known/mercure` that carries an `Authorization: Bearer` header (the hub checks that token itself; a browser still signs in).
- **A per-project gate.** Each instance's site checks the user's project group ([share and access](06-share-and-access.md)).
- **A proxy secret.** The app trusts an identity only when Caddy presents it.
- **The app never runs as root.** Writing the rendered configuration goes through a root helper with one sudoers line.
- **Loopback only.** The login portal, the app socket, the Mercure hub and the DDEV router do not listen on the Internet.
- **Secrets encrypted.** Stored with `/srv/fleet/keys/secret.key`. Back it up; without it every secret is lost.

The honest limit: the `fleet` user is in the `docker` group, which is root-equivalent. The helper prevents accidents; it is not a boundary against a compromised `fleet` account.

## What the installer never touches

`sshd`, `ufw`, fail2ban and unattended upgrades. A plain install or upgrade leaves them alone, so open the ports yourself: `fleet port:firewall` prints a `ufw allow` line for each public port in use (plus 80 and 443). Allow 22 yourself.

## Opt-in hardening

1. Keep a **second SSH session open** the whole time.
2. Preview: `sudo bash install/install.sh --dry-run --domain=fleet.example.com --harden --trusted-ip=203.0.113.7`
3. Apply: the same command without `--dry-run`. It sets key-only SSH, fail2ban, a firewall that Docker cannot bypass, and audit rules.
4. A **dead-man's switch** turns the firewall off after a grace period (default 10 minutes) unless you confirm.
5. From a second, independent session confirm: `sudo /usr/local/sbin/fleet-firewall-confirm`.
6. Your choices are kept; later runs and `sudo fleet upgrade` re-apply them.

Success: SSH still works from a new session, `https://fleet.example.com` loads, and the confirm command reports success.

## If something goes wrong

| Symptom | Fix |
|---|---|
| Locked out of SSH | Wait for the grace period: the firewall turns itself off. Other recovery paths are in [hardening](../reference/07-hardening.md). |
| Run refused | Your account has no SSH key, or another account with keys is not in the allow list. |

## Where to go next

- [Hardening reference](../reference/07-hardening.md): each part, undo, lock-out recovery
- [Networking reference](../reference/09-networking.md)
- [Operate](07-operate.md)
