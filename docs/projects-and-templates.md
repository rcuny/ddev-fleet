---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Projects and templates (1.x)

A **project** tells the fleet what it can deploy: a git repository plus a few defaults. A **template** says how an
instance of that project is set up: the Drupal environment and the commands run after a deploy and in the instance's
terminals. You manage both in the browser (`/admin/projects`) or with the `fleet` command on the host. There is no
YAML file and no config repository: the fleet's database is the source of truth.

Instances (the running copies of a project) are described in [`instances-and-jobs.md`](instances-and-jobs.md): a project
is the description, an instance is one deployed branch of it.

Contents: [concepts](#concepts) · [fields and limits](#fields-and-limits) · [the screens](#the-screens) ·
[the command line](#the-command-line) · [permissions](#permissions) · [deleting](#deleting) ·
[named ports](#named-ports) · [upgrading](#upgrading) · [differences from 0.x](#differences-from-0x)

## Concepts

| Thing | What it is |
|---|---|
| **Project** | A name, a git URL, and optional defaults: branch, template, extra hostnames, git identity, issue id pattern, a submodule to show, a Typesense search server, assigned named ports. |
| **Template** | Belongs to one project. A name, an optional Drupal environment, and three command lists: *post-deploy* (run on the host after a deploy, in the instance's checkout and without a shell, so `ddev composer install` or `ddev drush deploy`; to run something inside the web container write `ddev exec '...'`), *terminal 1* and *terminal 2* (typed into the two terminal panes of the instance's window). A project has up to 50. One of them can be the project's **default template**. |
| **Named port** | An entry of the host-wide catalogue: a name, a *public* port Caddy listens on and a *router* port of the DDEV router the traffic is forwarded to (for example `typesense`, 9108 to 8108). A project lists the named ports it uses. The catalogue is managed with `fleet port:*` only. |

Names of projects, templates and ports are **immutable**: an instance id is `<project>--<label>`, so a rename would
orphan instances. To rename, create the new one and delete the old one.

## Fields and limits

Every rule below lives in one place (`App\Fleet\Projects\Rule`) and is applied by the browser pages and the
commands alike, so both refuse the same input with the same message. A refused value is never stored. The
validation messages never echo what you typed (a "does not exist" message repeats the name you asked for, shortened).

### Project

| Field | Rule |
|---|---|
| Name | 1 to 40 characters: lowercase letters, digits and single dashes, starting and ending with a letter or digit; no `--` (the double dash separates project and instance label). `new` is reserved. Unique. Fixed after creation. |
| Git URL | Required, at most 2048 characters, plain ASCII. Exactly three shapes: `https://host[:port]/path`, `ssh://[user@]host[:port]/path` and scp-like `[user@]host:path`. Refused: `http://`, `git://`, `file://`, `ext::` and every other scheme, local paths, credentials in the URL (use the fleet's deploy key, never a token in the URL), a leading dash, spaces and control characters, query strings and fragments, `..` segments, IPv6 literals and single-number hosts such as `2130706433`. |
| Default branch | Optional. Letters, digits, `.`, `_`, `-` and `/`, starting with a letter or digit, at most 200 characters, and a valid git branch name (no `..`, `//`, `/.`, trailing `/` or `.`, `.lock` ending; not `HEAD` and nothing starting with `refs/`). Blank means the repository's own default. |
| Default template | Optional. The name of one of the project's own templates. It can be set only once the template exists (not when creating the project), and the default template cannot be deleted. |
| Additional hostnames | Optional, at most 10. One label each (lowercase letters, digits, single dashes; no dots), unique within the project, and short enough that the whole host name label `<label>-<project>--<instance label>` stays within 63 characters. |
| Ports | Optional, at most 50 names, all of them in the catalogue. |
| Git identity | `inherit` (the host default, the default), `disabled` (no git identity) or `override` (a custom name and/or email; a blank one falls back to the host default at deploy time). Name: at most 100 characters, no control characters, `<` or `>`. Email: a plain address. A name and email are kept only in `override` mode. |
| Issue id pattern | Optional, at most 200 characters. A regular expression (PCRE, case-insensitive, no delimiters, so `~` is not allowed) that finds the issue id in an instance label or a branch name, for example `(?:ABC\|XYZ)-\d{1,6}`. See [the pattern rules](#issue-id-patterns). |
| Submodule branch | Optional. A relative path inside the repository, such as `web/modules/custom/blog`: segments of letters, digits, `.`, `_`, `-`, each starting with a letter or digit, at most 200 characters. |
| Typesense | A yes/no switch for a Typesense search server per instance. |

### Template

| Field | Rule |
|---|---|
| Name | Same as a project name (1 to 40 characters, `new` reserved), unique within the project, fixed after creation. At most 50 templates per project. |
| Drupal environment | Optional. 1 to 64 letters, digits, `.`, `_` or `-`. It is written verbatim as `DRUPAL_ENV=<value>` into the instance's `.env`, so quotes, spaces and shell characters are refused. Blank leaves the project's own value. |
| Post-deploy, terminal 1, terminal 2 | Each a list of at most 50 command lines of at most 2000 characters. **One line per command:** every control character is refused (newline, tab, escape, NUL), as are Unicode line separators and format characters, and the text must be valid UTF-8. Blank lines are dropped and surrounding spaces trimmed. A line is never run through a shell: **post-deploy lines run on the host as an argument list** in the instance's checkout (`ddev composer install`, `ddev drush deploy`), quotes and backslashes group words, and an unquoted `|`, `&`, `;`, `<`, `>`, `$`, backtick, `(`, `)`, `{` or `}` is refused when the deploy runs, with a hint to write `ddev exec '...'` for a command that needs the container's shell. `[[token]]` placeholders are substituted after splitting. **Terminal lines are typed on the host** into a terminal pane (`ddev logs -f` is fine there). A failing post-deploy command fails the deploy (see [`instances-and-jobs.md`](instances-and-jobs.md)).  A whole command list is also bounded as one text, to 100 100 characters (50 lines of 2000 characters with a line break between them): the browser text box and `--post-deploy`/`--tty1`/`--tty2` refuse a longer text with the same message, so every list one of them accepts the other accepts too. |

### Named port

| Field | Rule |
|---|---|
| Name | Same shape as a project name (`new` is allowed here), unique. |
| Public and router port | Whole numbers from 1 to 65535. Both must be unique across the catalogue, and no public port may equal another entry's router port. The host reserves 22, 80, 443, 2019, 3000, 8765 and 9091 (public and router); 8080 and 8443 (the DDEV router) cannot be a router port. The router port must differ from the public port. |

### Text fields

Any other multi-line text box (the hostnames) accepts at most 10 000 characters of valid UTF-8 without NUL; the command lists have the bound given in the table above.

### Issue id patterns

The pattern is operator input that the server runs, so it is restricted. Backreferences, `\Q...\E` quoting, recursion, callouts,
conditionals, comments, control verbs and extended mode are refused, and so is a repeated group whose body already
repeats or alternates (`(a+)+`, `(a|aa)*`). A pattern that is merely expensive on long input is refused after a trial
run. These checks are filters, not proofs: the real protection is that every match runs under a small backtrack limit on
subjects of bounded length (an instance label of at most 63 characters, a branch of at most 200), and a failure means
"no issue id", never an error or a hang.

To extract an id the label is tried first, then the branch. If the pattern has a first group, its text is the id;
otherwise the whole match. The id is upper-cased and must consist of letters, digits, `.`, `_`, `/` and `-` (not
starting with a dash); anything else counts as no match.

## The screens

All pages are under **Projects** in the sidebar (`/admin/projects`), need an administrator, work at phone width and
follow the design system. A refused value shows an error under its field and nothing is saved.

| Page | URL | What it does |
|---|---|---|
| Projects | `/admin/projects` | The list: name, git URL, default branch and the number of templates. **New project** opens the create form. |
| New project | `/admin/projects/new` | Name, git URL, default branch, hostnames (one per line), git identity, issue id pattern, submodule, Typesense. |
| Project, General tab | `/admin/projects/<project>` | The same fields (the name is fixed) plus the default template. Below the form: the **issue id tester** (type a pattern, a sample instance label and a sample branch, and see the id a deploy would extract; nothing typed there is saved) and the **Danger zone** with the delete button and its confirmation dialog. |
| Project, Templates tab | `/admin/projects/<project>/templates` | The project's templates with their counts of commands; the default one carries a badge. **New template**, edit and delete. |
| Template form | `.../templates/new`, `.../templates/<template>` | Name, Drupal environment, and three text boxes with one command per line. |
| Project, Secrets tab | `/admin/projects/<project>/secrets` | The project's secrets (names only), add or replace, reveal, delete, and a warning for a secret no asset file uses. See [`secrets.md`](secrets.md). |
| Project, Ports tab | `/admin/projects/<project>/ports` | Read-only: the catalogue, with the ports this project uses marked. Assigning ports in the browser arrives with the networking milestone (M0.07); until then use `fleet project:update --port`. |

## The command line

Run on the host as `fleet <group>:<verb>` or `fleet <group> <verb>` (`fleet project:list` and `fleet project list` are
the same). The `fleet` wrapper switches to the `fleet` user and loads `/etc/ddev-fleet/fleet.env`, like the other
commands in [`installation.md`](installation.md).

> **`fleet.env` is read by the shell.** The wrapper sources the file as the `fleet` user, so a value you edit by
> hand that contains a space, `$`, a backtick, `;` or similar must be quoted, and an unquoted value with shell
> syntax is *executed* as `fleet`. The installer writes plain `KEY=value` lines; keep to that.

### Output and exit codes

Every command takes `--format=table|json` (the default `table` is for people) and `--json`, which is a shorter
spelling of `--format=json`. With JSON, stdout holds exactly one JSON document and nothing is written to stderr.

| Exit code | Meaning |
|---|---|
| `0` | Done. |
| `1` | A value was refused (validation), or the usage is wrong. |
| `2` | Not allowed now: the project still has instances, it is the default template, a port is in use, or a delete was not confirmed. |
| `3` | The project, template or port does not exist. |

In table mode a refusal prints one line per field on stderr (`--git: message`, or `<name>: message` for a field that
is an argument). In JSON mode the document is `{"error": "...", "fields": {...}}`: `fields` maps the command-line
spelling of each refused field to its message (`{}` when the failure is not about a field).

```console
$ fleet project:create Blog --git='ext::sh x' --json
{
    "error": "Invalid values.",
    "fields": {
        "<name>": "Use lowercase letters, digits and single dashes only, starting and ending with a letter or digit.",
        "--git": "The URL must be plain ASCII without spaces or control characters."
    }
}
$ echo $?
1
$ fleet project:show nope --json
{
    "error": "Project \"nope\" does not exist.",
    "fields": {}
}
$ echo $?
3
```

### Commands

| Command | Does |
|---|---|
| `fleet project:list` | The projects. |
| `fleet project:show <name>` | One project with its templates and ports. |
| `fleet project:create <name> --git=<url> [options]` | Creates a project. |
| `fleet project:update <name> [options]` | Changes the options you give and nothing else. |
| `fleet project:delete <name> [--yes]` | Deletes the project with its templates (see [deleting](#deleting)). |
| `fleet template:list [<project>]` | The templates of one project, or of all. |
| `fleet template:show <project> <name>` | One template. |
| `fleet template:create <project> <name> [options]` | Creates a template. |
| `fleet template:update <project> <name> [options]` | Changes the options you give and nothing else. |
| `fleet template:delete <project> <name> [--yes]` | Deletes a template. |
| `fleet port:list` | The named-port catalogue, with the projects using each port. |
| `fleet port:create <name> --public=<port> --router=<port>` | Adds a port to the catalogue. |
| `fleet port:delete <name> [--yes]` | Removes a port that no project uses. |

**Project options** (`project:create` and `project:update`):

| Option | Meaning |
|---|---|
| `--git=<url>` | The git URL. Required on create. |
| `--default-branch=<branch>` | The default branch. |
| `--default-template=<name>` | The default template (`project:update` only; the template must exist). |
| `--hostname=<label>` | An additional hostname label. Repeat for several; the list you give **replaces** the old one. |
| `--port=<name>` | A named port to assign (see `fleet port:list`). Repeat for several; the list **replaces** the old one. |
| `--git-bot=inherit\|off\|custom` | The git identity: the host default, none (`disabled` in JSON), or a custom one (`override` in JSON). |
| `--git-bot-name=<text>`, `--git-bot-email=<address>` | The custom identity. Only with `--git-bot=custom` (on update: or when the project already uses it); otherwise they are refused with exit code `1`, not silently dropped. |
| `--issue-id-regexp=<pattern>` | The issue id pattern. |
| `--display-submodule-branch=<path>` | The submodule whose branch is shown. |
| `--typesense` | Give the project a Typesense search server. On update `--no-typesense` removes it. |
| `--unset=<name>` | `project:update` only. Clears `default-branch`, `default-template`, `hostnames`, `ports`, `issue-id-regexp` or `display-submodule-branch`. Repeat for several. Combining `--unset=x` with a new value for `x` is refused. |

**Template options** (`template:create` and `template:update`):

| Option | Meaning |
|---|---|
| `--drupal-env=<value>` | The `DRUPAL_ENV` value. |
| `--post-deploy=<command>` | A post-deploy command. Repeat for several; the list **replaces** the old one. A multi-line text works too (`--post-deploy="$(cat commands.txt)"`): it is split into lines like the text box in the browser. |
| `--tty1=<command>`, `--tty2=<command>` | Commands typed into the first and second terminal pane. Same rules. |
| `--unset=<name>` | `template:update` only. Clears `drupal-env`, `post-deploy`, `tty1` or `tty2`. |

`project:delete`, `template:delete` and `port:delete` ask for confirmation on a terminal; `--yes` (`-y`) skips the
question. Without a terminal, and in JSON mode, they never prompt: without `--yes` they stop with exit code `2`.

### Selecting instances

The instance commands (deploy, start, stop, redeploy, destroy) take one or more instance ids (`<id>`), or select with `--all`,
`--project <name>` or `--state <state>`. They wait for their job by default and print its log; `--detach` only queues
the job. The instances guide has the full reference.

### Examples

```console
$ fleet project list
$ fleet project create blog --git=git@git.example.com:acme/blog.git --hostname=www
Project "blog" created.
$ fleet template create blog web --post-deploy='composer install' --tty1='ddev logs -f'
Template "web" created in project "blog".
$ fleet project update blog --default-template=web --issue-id-regexp='(?:ABC|XYZ)-\d{1,6}'
Project "blog" updated.
$ fleet port create typesense --public=9108 --router=8108
Named port "typesense" created (public 9108, router 8108).
$ fleet project update blog --port=typesense
```

A script reads a project with `--json`. Keys are the 0.x names; times are UTC; an unset value is `null`; the sample is shown compact, the command prints it indented:

```json
{
    "name": "blog",
    "git": "git@git.example.com:acme/blog.git",
    "default_branch": null,
    "default_template": "web",
    "additional_hostnames": ["www"],
    "ports": ["typesense"],
    "git_bot": {"mode": "inherit", "name": null, "email": null},
    "issue_id_regexp": "(?:ABC|XYZ)-\\d{1,6}",
    "display_submodule_branch": null,
    "typesense": false,
    "templates": [
        {
            "name": "web",
            "drupal_env": null,
            "post_deploy": {"exec": ["composer install"], "tty1": ["ddev logs -f"], "tty2": []}
        }
    ],
    "created_at": "2026-10-19T07:16:05Z",
    "updated_at": "2026-10-19T07:16:07Z"
}
```

`git_bot.mode` is `inherit`, `disabled` or `override`. A template (from `template:show`, `template:list` and
`template:create|update`) has `project`, `name`, `drupal_env`, `post_deploy` (`exec`, `tty1`, `tty2`),
`created_at` and `updated_at`; the post-deploy commands are under the key `exec`, as in 0.x. A port has `name`,
`public`, `router` and `projects` (the names of the projects using it). `*:delete` answers `{"deleted": "<name>"}`.

## Permissions

Everything on this page is **administrator-only**: the pages need `ROLE_ADMIN` (the group `FLEET_ADMIN_GROUP`, see
[`installation.md`](installation.md)), the issue id tester's endpoint is admin-only too, and every state change is a
POST with a CSRF token. A member gets 403, a signed-out visitor is sent to sign in. The commands are for whoever can
use `sudo fleet` on the host. Operating the instances of a project (start, stop, redeploy, logs) is open to the project's members; deploying and destroying are administrators only, and changing a project's settings never can be given away.

## Deleting

Deleting a project deletes its templates, its port assignments (the catalogue entries stay) and its secrets (one audit entry each, see [`secrets.md`](secrets.md)). It is **refused
while the project has instances**, with a message naming them; destroy the instances first. Destroy them from the Instances page or with `fleet instance:destroy`. A template can be deleted unless it is the project's default template: choose
another default (or clear it with `--unset=default-template`) first. A named port cannot be removed while a project
uses it. A refused delete leaves everything as it was, in the browser (a warning) and on the command line (exit
code `2`).

## Named ports

The catalogue is shared by the whole host and has no web page: add entries with `fleet port:create` and list them with
`fleet port:list`. A project's *Ports* tab shows which of them it uses; assign them with `fleet project:update
--port=<name>` (the list replaces the old one). Opening the ports in Caddy, and assigning them from the browser,
arrive with the networking milestone (M0.07): today the catalogue and the assignments are stored and validated, nothing
listens yet.

## Upgrading

The tables are created by a database migration. Re-running the installer (the upgrade, see
[`installation.md`](installation.md)) applies it; nothing needs to be done by hand, and no existing data is touched.
There is no import of a 0.x `fleet.yml`: re-create the projects (a project export and import comes with the host
operations milestone).

## Differences from 0.x

| 0.x (`fleet.yml`) | 1.x |
|---|---|
| A YAML file, edited by hand and kept in a config repository. | The database, edited in the browser or with `fleet project\|template\|port`. No config repository, no `fleet.yml`, no `host.yml`. |
| Names could be renamed by editing the file. | Names are immutable; rename means create and delete. |
| Names of any length that passed the shape check. | At most 40 characters (so `<project>--<label>` fits a 63-character DNS label); `new` is reserved. |
| A git URL was any string. | Only `https://`, `ssh://` and scp-like URLs, with no credentials in them (see the field table). Use the deploy key. |
| `drupal_env` was a single word. | Stricter: 1 to 64 letters, digits, `.`, `_`, `-`, written verbatim into `.env`. |
| A command could hold anything the YAML did. | One line per command, no control characters (tabs included), at most 2000 characters and 50 lines per list. |
| `tty1`/`tty2` could also sit directly in a template (deprecated). | Only the three lists of a template (`post_deploy`: `exec`, `tty1`, `tty2`). |
| `branch` in a template was forbidden. | Still no branch in a template. |
| Fleet-wide `git_bot_name`, `git_bot_email` and `git_bot: false` keys, and per project `git_bot`. | Per project: `inherit` (the host default), `disabled`, `override`. The host default is a host setting, not part of a project. |
| `typesense: true` and the `ports:` block. | A yes/no switch per project, and a named-port catalogue managed with `fleet port:*`. |
| `users:` and webhook rules in the same file. | Not part of a project here: users and access arrive in M0.10, webhooks in M0.08. |
