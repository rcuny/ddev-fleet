---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Share and access

Fleet owns the people who may sign in, so one place decides who sees what. Administrators add users on the **Users** page (`/admin/users`) or with `fleet user:*`. You get three kinds of user, and a clean way to show a staging site to a client.

## Roles

| | Administrator | Project member | Site-only |
|---|---|---|---|
| Open the instance sites of a project | all | their projects | their projects |
| See instances and jobs, read logs | all | their projects | no (every Fleet page answers 403) |
| Start, stop, redeploy an instance, retry its jobs | yes | their projects | no |
| Deploy, destroy | yes | no | no |
| Projects, templates, ports, assets, secrets, webhooks, schedules, users, host settings, export and import | yes | no (403) | no |

**Site-only** is for clients: no email needed, a short password is allowed, never an administrator. Nobody can delete, disable or demote themselves, and the last enabled administrator can never be removed.

## Give a client access

```bash
fleet user:create carol
fleet user:update carol --site-only
fleet user:grant carol website
```

Carol can open `https://website--main.fleet.example.com` after signing in at the portal, and nothing else.

## How a site is protected

The login portal (Authelia) lets any signed-in user through `*.fleet.example.com`. Each instance's Caddy site then answers 403 unless the user is an administrator or in the group `fleet-project-website`. Changes apply at once: no restart, nobody is signed out. A named port with access `public` skips sign-in for that port only ([networking](../reference/09-networking.md)).

## Commands

| Command | Does |
|---|---|
| `fleet user:list` | List users. |
| `fleet user:create` | Create a user (hidden password prompt). |
| `fleet user:update` | Change name, email, enabled, administrator or site-only. |
| `fleet user:grant` / `user:revoke` | Add or remove project access. |
| `fleet user:delete` | Delete a user. |
| `fleet user:reset-password` | New password; needs a terminal: `ssh -t admin@fleet.example.com sudo fleet user:reset-password alice`. |

Options: [cli](../reference/01-cli.md).

## Where to go next

- [Secure the host](08-secure-the-host.md)
- [First project](02-first-project.md)
- [Troubleshooting](../reference/13-troubleshooting.md)
