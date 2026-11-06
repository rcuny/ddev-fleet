---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Fleet

[![Licence: MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

**Run many DDEV projects on one server, with almost no human work.**

## The problem

You have several projects, each with many branches, tickets and test runs. Every one of them deserves its own live site: a checkout, a database, a TLS certificate, a login, seed data. Doing that by hand means cloning, editing `.env` files, waiting for certificates and, later, remembering to clean up. So most teams share one staging site and queue up for it.

## What Fleet does

Fleet is a self-hosted manager for one Linux host. Give it a git repository and a template, and it clones, configures, starts and removes DDEV instances for you, each at `https://<project>--<branch>.fleet.example.com` behind a login and seeded with your database dump and settings. You create instances from the browser or the `fleet` command, or let a Jira ticket, a pushed branch or a schedule do it. Each instance also gets a terminal window where a coding agent such as Claude Code can work on the ticket.

## Who it is for

Agencies and teams with many Drupal/DDEV projects, teams dispatching coding agents on tickets, and people running demo copies for clients.

## What you can do

| Scenario | How it happens |
|---|---|
| A review environment per Jira ticket | The ticket moves to a status, a webhook fires, Fleet deploys `shop--shop-123`. |
| A CI instance per pushed branch | A Bitbucket push deploys an instance that runs your tests. |
| Demo copies for a client, fresh every night | A cron schedule redeploys `demo--main` at 03:00. |
| An agent per ticket | The instance opens a terminal where Claude Code works the ticket. |

## What you get

| Feature | In one line |
|---|---|
| One-command install | Debian 13 to working HTTPS in about five minutes. |
| Projects and templates | Describe a repository once, deploy many instances. |
| Jobs with live logs | Every action is queued, watchable, retryable. |
| Assets and secrets | Seed dumps and `.env` files; encrypted `[[token]]` secrets. |
| Users and access | Admins, members, site-only clients; enforced at the proxy. |
| Automation | Jira and Bitbucket webhooks, cron schedules. |
| Capacity | CPU allotments and slots protect the host. |
| Operations | Backups, export, `fleet upgrade` with rollback, hardening. |

## By hand or with Fleet

| Task | By hand | With Fleet |
|---|---|---|
| Clone, configure | Many commands | One deploy |
| TLS and login | Certificates and proxy rules | Automatic |
| Seed data | Copy dump, edit `.env` | Seeded from assets |
| Cleanup | Easy to forget | One destroy, or on ticket close |
| Who clicks | You | Nobody |

## Quick start

On a fresh Debian 13 host, as root, with DNS for `fleet.example.com`, `auth.` and `*.` pointing at it and ports 80 and 443 open:

```bash
curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/main/install/install.sh \
  | sudo bash -s -- --domain=fleet.example.com --acme-email=you@example.com
```

The installer prints a setup URL for the browser wizard. Then:

```bash
fleet project:create shop --git=git@git.example.com:acme/shop.git
fleet template:create shop web --post-deploy='composer install'
fleet project:update shop --default-template=web
fleet instance:deploy shop --branch=main
```

The deploy streams its log and prints `https://shop--main.fleet.example.com`; sign in and the site is there. Walk-through with assets, secrets and access: [your first project](docs/guides/first-project.md).

## How it fits together

Caddy is the only Internet-facing process; Authelia handles sign-in. The Fleet app queues jobs, a worker runs `ddev` and `git`, one DDEV project per instance. Fleet runs natively, not in Docker, so it can recover unhealthy instances. See [concepts](docs/concepts.md).

## Documentation

- [Start here: the doc map](docs/README.md)
- [Ten concepts in one page](docs/concepts.md)
- [Install](docs/guides/install.md) and [your first project](docs/guides/first-project.md)
- [Automate deploys](docs/guides/automate-deploys.md)
- [CLI reference](docs/reference/cli.md)
- [Contributing](docs/contributing/README.md)

## Branches and releases

`main` holds the releases (annotated tags `v1.*`, what the installer installs by default) and `develop` is the integration branch. The Python 0.x line of Fleet is frozen on the `legacy` git branch and is not covered here. Release history: `CHANGELOG.md`.

## Repository layout

| Path | Holds |
|---|---|
| `app/` | The Symfony project. |
| `install/` | The installer and uninstaller. |
| `tests/` | Playwright and CI-tool tests. |
| `ci/`, `changelog.d/` | CI scripts, the tag guard, changelog fragments. |
| `docs/` | This documentation. |

Licence: MIT.
