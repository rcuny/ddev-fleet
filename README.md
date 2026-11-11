# Fleet

[![Licence: MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

**Run many DDEV projects on one server for easy testing/demo, CI workflows or agentic development** 

## What Fleet does

Fleet is a self-hosted manager for Linux hosts. Give it several git repositories, define some templates, and it clones, configures, starts and removes DDEV instances for you, each at `https://<project>--<branch>.fleetserver.yourdomain.com` behind a login and seeded with your database dumps and settings. 

You can **create instances manually** from the browser or the `fleet` command, or configure Fleet to do it **automatically** by **reacting to webhooks** (eg. ticket status change, pushed branch) or by creating **scheduled actions** (eg. nighly CI baseline rebuild).

Each instance also gets its own window in a shared **tmux** workspace (see below), where you, a colleague or a coding agent such as Claude Code can interact with the instance to run CI scripts or do some actual development.

## Philosophy and flavour

Fleet is agnostic by design, it does not assume a stack or a tool. Run Drupal or any other DDEV project, use Claude or no agent at all, keep a human in the loop wherever you want one and automate the rest.

In its current state of development, Fleet still has the flavour of the stack I use (Drupal, Claude, Jira, Bitbucket). I integrated them as a priority simply because they are part of my own workflows, but their integration is done without altering Fleet's neutrality. 

I will work on more integrations in the future, such as Gitlab hooks, GitHub or Redmine hooks, or Codex agents. Contributions in that direction are most welcome! :)

## Who it is for

Solo developers, agencies and teams with many Drupal/DDEV projects, teams dispatching CI jobs or coding agents on tickets, and people running demo copies for clients.

### Example use cases

| Scenario | How it happens |
|---|---|
| A review environment for your branch | You've finished working on a feature branch, push it to your git remote then manually deploy a Fleet instance for your lead-dev or client review on `website--feature-123.fleet.domain.com`. |
| A review environment per Jira ticket, automatically | The ticket moves to a status, a webhook fires, Fleet deploys `website--feature-123.fleet.domain.com` automatically for you. |
| A CI instance per pushed branch | A Bitbucket push deploys an instance that runs your tests. |
| Demo copies for a client or as a CI baseline, fresh every night | A cron schedule redeploys `demo--main` at 03:00. |
| A terminal workspace per ticket | The instance opens a tmux window where you, or an agent such as Claude Code, work the ticket. |

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

## Under the hood

| Feature | In one line |
|---|---|
| One-command install | Debian 13 to working HTTPS in about five minutes. Ubuntu support on the roadmap. |
| Projects and templates | Describe a repository once, deploy many instances. |
| Jobs with live logs | Every action is queued, watchable, retryable. |
| Assets and secrets | Seed dumps and `.env` files; encrypted `[[token]]` secrets. |
| Users and access | Admins, members, site-only clients; enforced at the proxy. |
| tmux workspace | One persistent session, a window per instance, shared by the team and attachable from anywhere. |
| Automation | Jira and Bitbucket webhooks, cron schedules, each one opt-in. |
| Capacity | CPU allotments and slots protect the host. |
| Operations | Backups, export, `fleet upgrade` with rollback, hardening. |

For more info, see: [Ten concepts in one page](docs/concepts.md)

### The tmux workspace

Every instance gets a window in one persistent tmux session on the host. Attach with `ssh -t admin@fleet.example.com sudo fleet tmux`: a sidebar lists every instance with its state and URL, one shell sits at the checkout for runners and logs, and a second shell is where you or a coding agent work. The session survives your SSH connection, so a long run or an agent keeps going while you are away, and you can come back, read what happened and take over. Details: [the tmux workspace](docs/guides/05-work-with-agents.md).

### How it fits together

Caddy is the only Internet-facing process; Authelia handles sign-in. The Fleet app queues jobs, a worker runs `ddev` and `git`, one DDEV project per instance. Fleet runs natively, not in Docker, so it can recover unhealthy instances. See [concepts](docs/concepts.md).

## Documentation

- [Start here: the doc map](docs/README.md)
- [Ten concepts in one page](docs/concepts.md)
- [Install](docs/guides/01-install.md) and [your first project](docs/guides/02-first-project.md)
- [Automate deploys](docs/guides/03-automate-deploys.md) and [the tmux workspace](docs/guides/05-work-with-agents.md)
- [Use the HTTP API](docs/guides/09-use-the-api.md) with a token
- [Git access and SSH keys](docs/guides/10-git-access-and-ssh-keys.md): who can read and push your code
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

## 🤖 AI Disclaimer
This project uses AI-assisted development tools. See the [AI usage policy](AI_POLICY.md) for details.

## Licence

Licence: MIT.
