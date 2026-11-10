---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Deploy your first project

A project is a git repository plus what Fleet needs to run it. Here you register one called `website`, give it a recipe (a template), its files and a secret, deploy it, and let a colleague in. You end with a working site at `https://website--main.fleet.example.com`.

Names (project, template, port) are **immutable**: an instance id is `<project>--<label>`. To rename, create a new one and delete the old.

## Steps

1. **Create the project.** In the browser: **Projects, New project** (`/admin/projects`). On the host:

   ```bash
   fleet project:create website --git=git@git.example.com:acme/website.git
   ```

   Never put a token in the git URL. Field rules: [configuration](../reference/02-configuration.md).

2. **Add a template.** A template is the recipe an instance is deployed from. Its post-deploy lines run on the host after `ddev start`, one command per line, **without a shell**: no pipes or `&&`. For a shell, write `ddev exec '...'`.

   ```bash
   fleet template:create website web --post-deploy='composer install' \
     --post-deploy='ddev drush deploy'
   fleet project:update website --default-template=web
   ```

   The default template is what `fleet instance:deploy website` uses when you do not name one. A failing line fails the deploy. Terminal lines and CPU limits are covered in [work with agents](05-work-with-agents.md) and [run CI](04-run-ci.md). If instances must pull or push over SSH, see [SSH keys](../reference/14-ssh-keys.md).

3. **Drop assets.** Assets are the per-project files Fleet copies into every new instance: a `.env`, `web/sites/default/settings.local.php`, a database dump. Use the project's **Assets** tab (`/admin/projects/website/assets`) or `rsync` to `/srv/fleet/assets/website/`. Fleet does not import the dump for you: add the import command to the template's post-deploy lines, for example `--post-deploy='ddev import-db --file=dumps/default.sql.gz'`. Details: [assets and secrets](../reference/08-assets-and-secrets.md).

4. **Add a secret.** On the project's **Keys & secrets** tab (`/admin/projects/website/keys`), add `JIRA_TOKEN`. Reference it as `[[jira-token]]` in an asset file or a post-deploy line, for example `JIRA_TOKEN=[[jira-token]]`. Do **not** put quotes around the stored value. Secrets are encrypted and never listed.

5. **Deploy.** **Instances, Deploy** in the UI, or:

   ```bash
   fleet instance:deploy website web --branch=main
   ```

6. **Watch and open.** The job page streams the log as it runs. When the instance is `running`, open `https://website--main.fleet.example.com`. Success: you sign in at the portal and see the site.

7. **Let alice in.** Grant her the project (**Access** tab, or `fleet user:grant alice website`). It applies at once. Roles: [share and access](06-share-and-access.md).

## If something goes wrong

| Symptom | Fix |
|---|---|
| The deploy fails at a post-deploy line | Read the job log; a `|`, `&` or `;` in a line is refused (use `ddev exec '...'`). |
| An asset still shows `[[jira-token]]` | The secret name does not match (`JIRA_TOKEN` becomes `[[jira-token]]`) or the file is over 1 MiB. |
| The site does not open | Wildcard DNS record missing, or you are not a member of the project. |

More: [troubleshooting](../reference/13-troubleshooting.md).

## Where to go next

- [Automate deploys](03-automate-deploys.md) from Jira, Bitbucket or a timer
- [Share and access](06-share-and-access.md)
- [Configuration reference](../reference/02-configuration.md)
