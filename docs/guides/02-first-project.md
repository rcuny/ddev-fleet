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

   **Read the mail your site sends.** Every instance runs DDEV's Mailpit, which catches outgoing mail instead of delivering it. To open it from the browser, tick **Mailpit** on the template (`fleet template:update website web --mailpit=yes`); the Caddy sites of the template's existing instances are refreshed at once, with no redeploy. The instance page gets an **Open Mailpit** link to `https://mailpit--website--main.fleet.example.com`. You sign in once, with your Fleet account: nobody who cannot see the instance can open its Mailpit.

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

## Protect an instance from destroy (optional)

If an instance must not disappear by accident, an administrator can lock it. Open the instance page, find the **Protection** section and choose **Protect**; Fleet asks for your password again. A protected instance shows a lock badge in the list and on its page, and its **Destroy** buttons are gone. While it is locked nothing can destroy it: the web page, a bulk destroy (which skips it and names it), the HTTP API (`409 instance_protected`, also for a batch or a retried destroy) and `fleet instance:destroy` (it reports the instance as refused and exits with 2) all refuse. There is no command-line or API way to unlock: the only way back is **Remove protection** on the instance page, which asks for the password again. Redeploy, start and stop still work. The API shows the state as the read-only `protected` field. Who protects which instance is the administrators' decision; nothing is protected by default.

## Isolation between instances (optional)

By default the instances of a host can reach each other's containers. To close that, record what actually talks to what first:
`fleet host:isolation:enable --mode=report`, work normally for a few days, then read `fleet host:isolation:status`. It lists
each flow with the `fleet project:link:create` line that would allow it. When the list holds only what you want,
`fleet host:isolation:enable --mode=enforce --yes` cuts the rest, and `fleet host:isolation:disable` undoes it. The choice of
hosts, the timing and the links are yours; nothing turns it on by itself. See [network isolation](../reference/17-instance-isolation.md#network-isolation-between-instances).

