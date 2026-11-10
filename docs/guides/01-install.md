---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Install Fleet

One command turns a fresh Debian 13 host into a working Fleet with real HTTPS, and a short browser wizard finishes the job. You will end this page signed in as an administrator, ready to add a project.

## What you need

| You need | Detail |
|---|---|
| Host | Debian 13, amd64 or arm64, reachable from the Internet. Other systems are refused. |
| Root | Run the installer with `sudo`. |
| DNS | A (and AAAA) records for `fleet.example.com` and `auth.fleet.example.com`. One wildcard `*.fleet.example.com` covers both and every instance. |
| Ports | 80 and 443 free and open (Let's Encrypt uses 80). |
| Time | About five minutes. Use `screen` or `tmux`; if interrupted, run the same command again. |

## Steps

1. Run the installer:

   ```bash
   curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/main/install/install.sh \
     | sudo bash -s -- --domain=fleet.example.com --acme-email=you@example.com
   ```

   Add `--preflight` to check the host and change nothing, or `--dry-run` to print the plan. All flags and exit codes: [installer reference](../reference/04-installer.md).

2. The installer prints a **setup URL**. It is a credential for 24 hours (or until Finish): whoever opens it can create the administrator. Open it yourself; never paste it into a chat or ticket.

3. Walk through the wizard. Until you finish it, only `/setup` and `/health` answer.

   | Step | You enter |
   |---|---|
   | Administrator | Username (fixed afterwards), name, email, a password of 12 to 128 characters. |
   | Domain and certificates | `http01` (default), or `ovh_dns` for a wildcard certificate through the OVH API. |
   | SSH keys | Copy the deploy and push public keys to your git server (push key with write access), or import your own. |
   | Claude token | Optional: the output of `claude setup-token`, run on any machine with Claude Code and a browser (not needed on the host). You can set it later, see [work with agents](05-work-with-agents.md#the-claude-token). |
   | Finish | Writes the administrator into the login portal and switches Caddy to the sign-in gate. |

4. Press **Finish**. It takes seconds to minutes; if it fails, fix the cause and press it again.

## First sign-in

Open `https://fleet.example.com/`. It redirects to the login portal at `https://auth.fleet.example.com/`; sign in with the administrator you just created. You land on the Fleet dashboard. Success: `https://fleet.example.com/health` answers and the dashboard loads.

## If something goes wrong

| Symptom | Fix |
|---|---|
| Installer exits 2, "not an address of this host" | Fix the A/AAAA record (an old AAAA breaks Let's Encrypt), or add `--skip-dns-check`. |
| No certificate, the browser warns | Ports 80 and 443 closed or DNS wrong: `journalctl -u caddy -n 100 --no-pager`. |
| Lost the setup URL | `sudo fleet setup:token` prints a new one; the old one stops working. |

More rows: [troubleshooting](../reference/13-troubleshooting.md).

## Where to go next

- [Deploy your first project](02-first-project.md)
- [Secure the host](08-secure-the-host.md) (the installer never touches ssh or the firewall)
- [Installer reference](../reference/04-installer.md) and [files on disk](../reference/03-files-on-disk.md)
