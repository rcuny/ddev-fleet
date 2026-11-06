---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Ten things to know

Learn these ten words and the rest of the documentation reads easily. Each concept gets a short paragraph and a link to the full story.

## 1. Project

You need a place to say "this is the repository we deploy". A project is a git repository plus its defaults, assets, secrets and users. Example: `shop` with `git@git.example.com:acme/shop.git`. Names are permanent, because they become part of every instance id. [Learn more](guides/first-project.md).

## 2. Template

Different jobs need different setups: a review site, a CI run, an agent desk. A template is a named recipe inside a project: a Drupal environment, post-deploy commands, terminal commands and an optional CPU allotment. Example: `web` runs `composer install`; `work` also opens an agent. [Learn more](guides/first-project.md).

## 3. Instance

An instance is one deployed checkout with its own DDEV project. Its id is `<project>--<label>` and it lives at `https://<id>.<domain>`. Example: `shop--main` at `https://shop--main.fleet.example.com`. Start, stop, redeploy and destroy it freely. [Learn more](guides/first-project.md).

## 4. Job

The web request never runs `ddev` or `git`. Fleet stores a job in a queue and a worker runs it, so you see a live log and can retry. States: `queued`, `running`, `succeeded`, `failed`, `interrupted`. Example: Deploy opens a job page that streams the log. [Learn more](reference/cli.md).

## 5. Assets

A fresh checkout is useless without a database dump and local settings. Assets are per-project files that Fleet copies into every new instance before it starts. Example: `dumps/default.sql.gz` and a `.env`. [Learn more](reference/assets-and-secrets.md).

## 6. Secrets

API keys must reach instances without living in git. You store a secret once, encrypted, and reference it with a `[[token]]` placeholder in asset files or commands. Example: `TOKEN="[[jira-token]]"` in `.env`. [Learn more](reference/assets-and-secrets.md).

## 7. Users and access

Sites should be private, but clients sometimes need one. Administrators manage everything, project members work on their projects, site-only users just open sites. Caddy enforces it. Example: `alice` is a member of `shop`. [Learn more](guides/share-and-access.md).

## 8. Named ports

Some services need their own port, such as a search server. A named port opens an extra public port per instance behind the same sign-in (or public if you choose). Typesense, a search engine, is a built-in option. [Learn more](reference/networking.md).

## 9. Webhooks and schedules

Nobody should click to make a review site. Webhooks react to Jira and Bitbucket events; schedules fire on a cron timer. Both queue normal jobs. Example: Jira status "Dispatched" deploys an instance. [Learn more](guides/automate-deploys.md).

## 10. Capacity and the agent workspace

One heavy run should not starve the host. Capacity gives projects and templates a CPU **allotment** and a count of **slots** for concurrent heavy runs. The **agent workspace** is a tmux session with a window per instance, where a coding agent works. [Capacity](reference/capacity.md), [agents](guides/work-with-agents.md).

## How a deploy works

1. You, a webhook or a schedule asks for a deploy; a job is queued.
2. The worker clones the branch.
3. Assets are seeded into the checkout.
4. `[[token]]` placeholders are replaced.
5. The Caddy site is written and Caddy reloads (the site is never live before the sign-in gate is in place).
6. The DDEV project starts.
7. Post-deploy commands run; a failing line fails the deploy.
8. The agent workspace window opens and types the terminal commands, only after the last post-deploy line succeeded.
9. The instance becomes `running`.
10. You open `https://<id>.<domain>`.

## The host at a glance

```text
Internet
   |
 Caddy  (only public process: TLS, sign-in gate)
   |-------- Authelia (login) 
   |-------- Fleet app (UI, CLI, queues jobs)
                 |
              worker (ddev, git, docker)
                 |
           DDEV router (loopback)
             |       |       |
         shop--main  shop--x  demo--main
```

## Learn more

- [Start here](README.md)
- [Install](guides/install.md)
- [First project](guides/first-project.md)
