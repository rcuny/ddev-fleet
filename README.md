---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Fleet

[![Licence: MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

**Run many DDEV projects on one server, and choose the level of automation that suits your workflow.**

## The problem

You have several projects, each with many branches, tickets and test runs. Every one of them deserves its own live site: a checkout, a database, a TLS certificate, a login, seed data. Doing that by hand means cloning, editing `.env` files, waiting for certificates and, later, remembering to clean up. So most teams share one staging site and queue up for it.

## What Fleet does

Fleet is a self-hosted manager for one Linux host. Give it a git repository and a template, and it clones, configures, starts and removes DDEV instances for you, each at `https://<project>--<branch>.fleet.example.com` behind a login and seeded with your database dump and settings. You create instances from the browser or the `fleet` command, or let a Jira ticket, a pushed branch or a schedule do it, and you decide which steps stay manual. Each instance also gets its own window in a shared tmux workspace (see below), where you, a colleague or a coding agent such as Claude Code can work on the ticket.

Fleet does not assume a stack or a tool. Run Drupal or any other DDEV project, use Claude or no agent at all, keep a human in the loop wherever you want one and automate the rest.

## Who it is for

Agencies and teams with many Drupal/DDEV projects, teams dispatching coding agents on tickets, and people running demo copies for clients.

## What you can do

| Scenario | How it happens |
|---|---|
| A review environment per Jira ticket | The ticket moves to a status, a webhook fires, Fleet deploys `website--website-123`. |
| A CI instance per pushed branch | A Bitbucket push deploys an instance that runs your tests. |
| Demo copies for a client, fresh every night | A cron schedule redeploys `demo--main` at 03:00. |
| A terminal workspace per ticket | The instance opens a tmux window where you, or an agent such as Claude Code, work the ticket. |

## What you get

| Feature | In one line |
|---|---|
| One-command install | Debian 13 to working HTTPS in about five minutes. |
| Projects and templates | Describe a repository once, deploy many instances. |
| Jobs with live logs | Every action is queued, watchable, retryable. |
| Assets and secrets | Seed dumps and `.env` files; encrypted `[[token]]` secrets. |
| Users and access | Admins, members, site-only clients; enforced at the proxy. |
| tmux workspace | One persistent session, a window per instance, shared by the team and attachable from anywhere. |
| Automation | Jira and Bitbucket webhooks, cron schedules, each one opt-in. |
| Capacity | CPU allotments and slots protect the host. |
| Operations | Backups, export, `fleet upgrade` with rollback, hardening. |

## By hand or with Fleet

| Task | By hand | With Fleet |
|---|---|---|
| Clone, configure | Many commands | One deploy |
| TLS and login | Certificates and proxy rules | Automatic |
| Seed data | Copy dump, edit `.env` | Seeded from assets |
| Cleanup | Easy to forget | One destroy, or on ticket close |
| Who decides what to automate | You, step by step | You, once, per project |

## Quick start

On a fresh Debian 13 host, as root, with DNS for `fleet.example.com`, `auth.` and `*.` pointing at it and ports 80 and 443 open:

```bash
curl -fsSL https://raw.githubusercontent.com/rcuny/ddev-fleet/main/install/install.sh \
  | sudo bash -s -- --domain=fleet.example.com --acme-email=you@example.com
```

The installer prints a setup URL for the browser wizard. Then:

```bash
fleet project:create website --git=git@git.example.com:acme/website.git
fleet template:create website web --post-deploy='composer install'
fleet project:update website --default-template=web
fleet instance:deploy website --branch=main
```

The deploy streams its log and prints `https://website--main.fleet.example.com`; sign in and the site is there. Walk-through with assets, secrets and access: [your first project](docs/guides/02-first-project.md).

## The tmux workspace

Every instance gets a window in one persistent tmux session on the host. Attach with `ssh -t admin@fleet.example.com sudo fleet tmux`: a sidebar lists every instance with its state and URL, one shell sits at the checkout for runners and logs, and a second shell is where you or a coding agent work. The session survives your SSH connection, so a long run or an agent keeps going while you are away, and you can come back, read what happened and take over. Details: [the tmux workspace](docs/guides/05-work-with-agents.md).

## How it fits together

Caddy is the only Internet-facing process; Authelia handles sign-in. The Fleet app queues jobs, a worker runs `ddev` and `git`, one DDEV project per instance. Fleet runs natively, not in Docker, so it can recover unhealthy instances. See [concepts](docs/concepts.md).

## Documentation

- [Start here: the doc map](docs/README.md)
- [Ten concepts in one page](docs/concepts.md)
- [Install](docs/guides/01-install.md) and [your first project](docs/guides/02-first-project.md)
- [Automate deploys](docs/guides/03-automate-deploys.md) and [the tmux workspace](docs/guides/05-work-with-agents.md)
- [All guides](docs/guides/README.md) and [all reference pages](docs/reference/README.md)
- [CLI reference](docs/reference/01-cli.md)
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
