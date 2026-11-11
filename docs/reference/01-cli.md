# CLI reference

Every public `fleet` command (100), with its arguments and options. Run `fleet <group>:<verb>` on the host as an administrator; `fleet x:y` is short for `fleet:x:y`, and `fleet <group> <verb>` works too. Commands that need root are marked **sudo**. Add `--help` to any command for the live text. Symfony adds global options (`-h`, `-q`, `-v`, `-V`, `--ansi`, `--no-ansi`, `-n`, `--env`, `--no-debug`, `--profile`, `--silent`), which the tables below leave out.

Most commands accept `--format=table|json` and `--json`. In JSON mode an error is printed as `{"error": "...", "fields": {...}}`.

This page is **generated** from the console definition by `fleet:docs:cli`; a repo test fails when it is stale. Regenerate it with `composer run docs:cli`. Do not edit it by hand.

## Contents

- [Exit codes](#exit-codes)
- [project](#project): Git repositories Fleet can deploy.
- [template](#template): Deploy recipes of a project.
- [port](#port): The named-port catalogue.
- [ssh](#ssh): Trusted git servers.
- [instance](#instance): Deploy, start, stop and watch instances.
- [job](#job): The job queue.
- [asset](#asset): Per-project seed files.
- [secret](#secret): Encrypted secrets.
- [user](#user): Users and roles.
- [webhook](#webhook): Jira and Bitbucket receivers.
- [schedule](#schedule): Cron schedules that redeploy.
- [claude](#claude): The Claude Code token.
- [tmux](#tmux): The tmux workspace.
- [workspace](#workspace): Workspace checks.
- [host](#host): Host settings, certificates, capacity.
- [backup](#backup): Database backups.
- [setup](#setup): The setup wizard.
- [export](#export): Export a project.
- [import](#import): Import a project.
- [upgrade](#upgrade): Release check, history and verification.
- [token](#token): Token.
- [Wrapper commands](#wrapper-commands-installbinfleet)

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Done. |
| `1` | A value was refused, or wrong usage. |
| `2` | Not allowed now (for example a delete without `--yes`, or a state that forbids it). |
| `3` | Not found. |

Exceptions:

- **instance** batch commands: `0` every job succeeded, `1` every job failed, `2` some jobs of the batch failed. `Ctrl-C` on an attached command marks the job `interrupted` and exits `130`.
- **upgrade check** (`fleet upgrade --check`): `0` up to date or update available, `1` failed, `2` usage, `3` blocked (nothing changed), `4` offline.
- **upgrade** (`sudo fleet upgrade`): `0` done, `1` failed, `2` usage, `3` refused, `5` verification failed.
- **export/import**: `2` means not confirmed or the passphrase cannot be read; `3` project not found.
- **host:tls-status**: `0` ok, `1` warning, `2` error.

## project

Git repositories Fleet can deploy.

### fleet project:create

Create a project.

```
fleet project:create [--git GIT] [--default-branch DEFAULT-BRANCH] [--hostname HOSTNAME] [--port PORT] [--git-bot GIT-BOT] [--git-bot-name GIT-BOT-NAME] [--git-bot-email GIT-BOT-EMAIL] [--issue-id-regexp ISSUE-ID-REGEXP] [--display-submodule-branch DISPLAY-SUBMODULE-BRANCH] [--cpu-threads CPU-THREADS] [--slots SLOTS] [--typesense] [--container-ssh] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Project name (lower case letters, digits and single dashes; cannot be changed later) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--git=VALUE` |  | Git URL (required): https://host/path.git, ssh://... or user@host:path |  |
| `--default-branch=VALUE` |  | Branch deployed when none is given |  |
| `--hostname=VALUE (repeatable)` |  | Additional hostname label (repeat for several; the given list replaces the old one) |  |
| `--port=VALUE (repeatable)` |  | Named port to assign (repeat for several; see fleet:port:list; the given list replaces the old one) |  |
| `--git-bot=VALUE` |  | Git identity of the instances: inherit (host default), off (none) or custom (see --git-bot-name and --git-bot-email) |  |
| `--git-bot-name=VALUE` |  | Git identity name (with --git-bot=custom) |  |
| `--git-bot-email=VALUE` |  | Git identity email (with --git-bot=custom) |  |
| `--issue-id-regexp=VALUE` |  | Pattern that extracts an issue id from a branch name |  |
| `--display-submodule-branch=VALUE` |  | Submodule path whose branch is shown next to the instance |  |
| `--cpu-threads=VALUE` |  | CPU threads all instances of the project may use together: a number, or none for unlimited |  |
| `--slots=VALUE` |  | Concurrent heavy runs across the project's templates: a number, or none for unlimited |  |
| `--typesense` |  | Give the project a Typesense search service |  |
| `--container-ssh` |  | Give each instance its own ssh-agent with the push key for git over SSH in the web container |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet project:delete

Delete a project with its templates (refused while it has instances).

```
fleet project:delete [-y|--yes] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Project name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--yes` | `-y` | Do not ask for confirmation |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet project:list

List the projects.

```
fleet project:list [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet project:show

Show one project.

```
fleet project:show [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Project name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet project:update

Change a project (only the options you give).

```
fleet project:update [--git GIT] [--default-branch DEFAULT-BRANCH] [--hostname HOSTNAME] [--port PORT] [--git-bot GIT-BOT] [--git-bot-name GIT-BOT-NAME] [--git-bot-email GIT-BOT-EMAIL] [--issue-id-regexp ISSUE-ID-REGEXP] [--display-submodule-branch DISPLAY-SUBMODULE-BRANCH] [--cpu-threads CPU-THREADS] [--slots SLOTS] [--default-template DEFAULT-TEMPLATE] [--typesense|--no-typesense] [--container-ssh|--no-container-ssh] [--unset UNSET] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Project name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--git=VALUE` |  | Git URL (https://host/path.git, ssh://... or user@host:path) |  |
| `--default-branch=VALUE` |  | Branch deployed when none is given |  |
| `--hostname=VALUE (repeatable)` |  | Additional hostname label (repeat for several; the given list replaces the old one) |  |
| `--port=VALUE (repeatable)` |  | Named port to assign (repeat for several; see fleet:port:list; the given list replaces the old one) |  |
| `--git-bot=VALUE` |  | Git identity of the instances: inherit (host default), off (none) or custom (see --git-bot-name and --git-bot-email) |  |
| `--git-bot-name=VALUE` |  | Git identity name (with --git-bot=custom) |  |
| `--git-bot-email=VALUE` |  | Git identity email (with --git-bot=custom) |  |
| `--issue-id-regexp=VALUE` |  | Pattern that extracts an issue id from a branch name |  |
| `--display-submodule-branch=VALUE` |  | Submodule path whose branch is shown next to the instance |  |
| `--cpu-threads=VALUE` |  | CPU threads all instances of the project may use together: a number, or none for unlimited |  |
| `--slots=VALUE` |  | Concurrent heavy runs across the project's templates: a number, or none for unlimited |  |
| `--default-template=VALUE` |  | Template deployed when none is given (one of the project's templates) |  |
| `--typesense / --no-typesense` |  | Give the project a Typesense search service (--no-typesense removes it) |  |
| `--container-ssh / --no-container-ssh` |  | Give each instance its own ssh-agent with the push key for git over SSH in the web container (--no-container-ssh removes it) |  |
| `--unset=VALUE (repeatable)` |  | Clear a value: default-branch, default-template, hostnames, ports, issue-id-regexp, display-submodule-branch (repeat for several) |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## template

Deploy recipes of a project.

### fleet template:create

Create a template in a project.

```
fleet template:create [--drupal-env DRUPAL-ENV] [--post-deploy POST-DEPLOY] [--tty1 TTY1] [--tty2 TTY2] [--ssh-key SSH-KEY] [--ssh-identities SSH-IDENTITIES] [--cpu-threads CPU-THREADS] [--slots SLOTS] [--format FORMAT] [--json] [--] <project> <name>
```

| Argument | Required | Description |
|---|---|---|
| `project` | yes | Project name |
| `name` | yes | Template name (lower case letters, digits and single dashes; cannot be changed later) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--drupal-env=VALUE` |  | Value of DRUPAL_ENV written to the instance .env |  |
| `--post-deploy=VALUE (repeatable)` |  | Command run inside the web container with `ddev exec` after a deploy (one line; repeat for several; the given list replaces the old one) |  |
| `--tty1=VALUE (repeatable)` |  | Command line typed into the first instance terminal (repeat for several; replaces the old list) |  |
| `--tty2=VALUE (repeatable)` |  | Command line typed into the second instance terminal (repeat for several; replaces the old list) |  |
| `--ssh-key=VALUE (repeatable)` |  | Name of an extra SSH key (the file ssh/_ed25519 in the fleet home) loaded into the instance's ssh-agent; the project needs container_ssh (repeat for several; replaces the old list) |  |
| `--ssh-identities=VALUE` |  | Which keys the instance's ssh-agent holds: push-and-extras (the host push key and the extra keys, the default) or extras-only (no push key) |  |
| `--cpu-threads=VALUE` |  | CPU threads all instances of the template may use together, at most the project's: a number, or none to inherit the project's |  |
| `--slots=VALUE` |  | Concurrent heavy runs of the template, at most the project's slots: a number, or none for unlimited |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet template:delete

Delete a template (refused for the default template of the project).

```
fleet template:delete [-y|--yes] [--format FORMAT] [--json] [--] <project> <name>
```

| Argument | Required | Description |
|---|---|---|
| `project` | yes | Project name |
| `name` | yes | Template name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--yes` | `-y` | Do not ask for confirmation |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet template:list

List the templates of one project, or of all projects.

```
fleet template:list [--format FORMAT] [--json] [--] [<project>]
```

| Argument | Required | Description |
|---|---|---|
| `project` | no | Project name (all projects when left out) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet template:show

Show one template.

```
fleet template:show [--format FORMAT] [--json] [--] <project> <name>
```

| Argument | Required | Description |
|---|---|---|
| `project` | yes | Project name |
| `name` | yes | Template name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet template:update

Change a template (only the options you give).

```
fleet template:update [--drupal-env DRUPAL-ENV] [--post-deploy POST-DEPLOY] [--tty1 TTY1] [--tty2 TTY2] [--ssh-key SSH-KEY] [--ssh-identities SSH-IDENTITIES] [--cpu-threads CPU-THREADS] [--slots SLOTS] [--unset UNSET] [--format FORMAT] [--json] [--] <project> <name>
```

| Argument | Required | Description |
|---|---|---|
| `project` | yes | Project name |
| `name` | yes | Template name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--drupal-env=VALUE` |  | Value of DRUPAL_ENV written to the instance .env |  |
| `--post-deploy=VALUE (repeatable)` |  | Command run inside the web container with `ddev exec` after a deploy (one line; repeat for several; the given list replaces the old one) |  |
| `--tty1=VALUE (repeatable)` |  | Command line typed into the first instance terminal (repeat for several; replaces the old list) |  |
| `--tty2=VALUE (repeatable)` |  | Command line typed into the second instance terminal (repeat for several; replaces the old list) |  |
| `--ssh-key=VALUE (repeatable)` |  | Name of an extra SSH key (the file ssh/_ed25519 in the fleet home) loaded into the instance's ssh-agent; the project needs container_ssh (repeat for several; replaces the old list) |  |
| `--ssh-identities=VALUE` |  | Which keys the instance's ssh-agent holds: push-and-extras (the host push key and the extra keys, the default) or extras-only (no push key) |  |
| `--cpu-threads=VALUE` |  | CPU threads all instances of the template may use together, at most the project's: a number, or none to inherit the project's |  |
| `--slots=VALUE` |  | Concurrent heavy runs of the template, at most the project's slots: a number, or none for unlimited |  |
| `--unset=VALUE (repeatable)` |  | Clear a value: drupal-env, post-deploy, tty1, tty2, ssh-keys (repeat for several) |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## port

The named-port catalogue.

### fleet port:create

Add a named port to the catalogue.

```
fleet port:create [--public PUBLIC] [--router ROUTER] [--access ACCESS] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Port name, for example typesense |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--public=VALUE` |  | Port Caddy listens on (required) |  |
| `--router=VALUE` |  | Port of the DDEV router the traffic is forwarded to (required) |  |
| `--access=VALUE` |  | gated (behind the login, default) or public (no login; typesense defaults to public) |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet port:delete

Remove a named port from the catalogue (refused while a project uses it).

```
fleet port:delete [-y|--yes] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Port name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--yes` | `-y` | Do not ask for confirmation |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet port:firewall

List the public ports to open in the host firewall.

```
fleet port:firewall [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet port:list

List the named-port catalogue.

```
fleet port:list [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet port:mirror

Show, or switch on or off, the in-network mirror of the named ports.

```
fleet port:mirror [--format FORMAT] [--json] [--] [<state>]
```

| Argument | Required | Description |
|---|---|---|
| `state` | no | on or off; without it the current state is shown |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet port:refresh

Rewrite the Caddy sites of the instances and the router port mirror files, and retry a failed mirror.

```
fleet port:refresh [--project PROJECT] [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--project=VALUE` |  | Only the instances of this project |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet port:update

Change who may reach a named port (gated or public) and rewrite the Caddy sites.

```
fleet port:update [--access ACCESS] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Port name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--access=VALUE` |  | gated (behind the login) or public (no login) (required) |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## ssh

Trusted git servers.

### fleet ssh:check-push-key

Check that the host push key is registered on a git host (tests that key only).

```
fleet ssh:check-push-key [--host HOST] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--host=VALUE` |  | The git host to test | `bitbucket.org` |
| `--json` |  | Print the result as JSON |  |

### fleet ssh:key:create

Generate an extra SSH key for templates and print its public key.

```
fleet ssh:key:create [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Key name: lower case letters, digits and single dashes, for example deploy-docs (not deploy or push) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet ssh:key:delete

Delete an extra SSH key Fleet manages (refused while a template uses it).

```
fleet ssh:key:delete [-y|--yes] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Key name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--yes` | `-y` | Do not ask for confirmation |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet ssh:key:import

Import an existing private key as an extra SSH key (read from standard input).

```
fleet ssh:key:import [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Key name: lower case letters, digits and single dashes |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet ssh:key:list

List the host's SSH keys (deploy, push, extras) with fingerprint, public key and where each is used.

```
fleet ssh:key:list [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet ssh:trust

Add the host keys of a server to the fleet known hosts after you confirmed their fingerprints.

```
fleet ssh:trust [--fingerprint FINGERPRINT] [--yes] [--format FORMAT] [--json] [--] <host>
```

| Argument | Required | Description |
|---|---|---|
| `host` | yes | Host name or address, or [host]:port for a non-standard port |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--fingerprint=VALUE (repeatable)` |  | Trust only the offered key with this SHA256 fingerprint, without a prompt (repeat for several) |  |
| `--yes` |  | Trust every offered key without a prompt (only when you verified the fingerprints some other way; --fingerprint is safer) |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## instance

Deploy, start, stop and watch instances.

### fleet instance:deploy

Deploy one or more instances of a project.

```
fleet instance:deploy [--branch BRANCH] [--label LABEL] [--count COUNT] [--parallel] [--force] [--skip-disk-check] [--detach] [--format FORMAT] [--json] [--] <project> [<template>]
```

| Argument | Required | Description |
|---|---|---|
| `project` | yes | Project name |
| `template` | no | Template name (default: the project's default template) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--branch=VALUE` |  | Branch to deploy (default: the project's default branch) |  |
| `--label=VALUE` |  | Label of the instance (default: the branch) |  |
| `--count=VALUE` |  | How many instances | `1` |
| `--parallel` |  | Deploy a batch concurrently instead of one after another |  |
| `--force` |  | Replace a leftover checkout of the same name |  |
| `--skip-disk-check` |  | Do not check the free disk space of a batch |  |
| `--detach` |  | Only queue the job(s) and return |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet instance:destroy

Destroy instances: their containers, database and checkout.

```
fleet instance:destroy [--all] [--project PROJECT] [--state STATE] [-y|--yes] [--detach] [--format FORMAT] [--json] [--] [<id>...]
```

| Argument | Required | Description |
|---|---|---|
| `id...` | no | Instance id(s), as -- |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--all` |  | Every instance |  |
| `--project=VALUE` |  | Every instance of this project |  |
| `--state=VALUE` |  | Every instance in this state |  |
| `--yes` | `-y` | Confirm a selector or several ids |  |
| `--detach` |  | Only queue the job(s) and return |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet instance:list

List the instances.

```
fleet instance:list [--project PROJECT] [--state STATE] [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--project=VALUE` |  | Only the instances of this project |  |
| `--state=VALUE` |  | Only the instances in this state |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet instance:log

Print the log of an instance's latest job (or of --job=).

```
fleet instance:log [--job JOB] [-f|--follow] [--] <id>
```

| Argument | Required | Description |
|---|---|---|
| `id` | yes | Instance id |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--job=VALUE` |  | Job number (default: the latest job of the instance) |  |
| `--follow` | `-f` | Keep printing until the job ends |  |

### fleet instance:redeploy

Rebuild instances from a fresh checkout of their branch.

```
fleet instance:redeploy [--all] [--project PROJECT] [--state STATE] [--template TEMPLATE] [--force] [-y|--yes] [--detach] [--format FORMAT] [--json] [--] [<id>...]
```

| Argument | Required | Description |
|---|---|---|
| `id...` | no | Instance id(s), as -- |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--all` |  | Every instance |  |
| `--project=VALUE` |  | Every instance of this project |  |
| `--state=VALUE` |  | Every instance in this state |  |
| `--template=VALUE` |  | Redeploy with this template |  |
| `--force` |  | Replace a leftover checkout |  |
| `--yes` | `-y` | Confirm a selector or several ids |  |
| `--detach` |  | Only queue the job(s) and return |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet instance:start

Start stopped instances.

```
fleet instance:start [--all] [--project PROJECT] [--state STATE] [--sequential] [--timeout TIMEOUT] [--retry-port-conflict] [--detach] [--format FORMAT] [--json] [--] [<id>...]
```

| Argument | Required | Description |
|---|---|---|
| `id...` | no | Instance id(s), as -- |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--all` |  | Every instance |  |
| `--project=VALUE` |  | Every instance of this project |  |
| `--state=VALUE` |  | Every instance in this state |  |
| `--sequential` |  | Run a bulk request one instance at a time |  |
| `--timeout=VALUE` |  | Seconds ddev may take |  |
| `--retry-port-conflict` |  | Stop and start again once when ddev reports a port conflict |  |
| `--detach` |  | Only queue the job(s) and return |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet instance:stop

Stop running instances.

```
fleet instance:stop [--all] [--project PROJECT] [--state STATE] [--sequential] [--timeout TIMEOUT] [--detach] [--format FORMAT] [--json] [--] [<id>...]
```

| Argument | Required | Description |
|---|---|---|
| `id...` | no | Instance id(s), as -- |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--all` |  | Every instance |  |
| `--project=VALUE` |  | Every instance of this project |  |
| `--state=VALUE` |  | Every instance in this state |  |
| `--sequential` |  | Run a bulk request one instance at a time |  |
| `--timeout=VALUE` |  | Seconds ddev may take |  |
| `--detach` |  | Only queue the job(s) and return |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet instance:watch

Keep the instance states and memory true to ddev and docker (the fleet-status unit).

```
fleet instance:watch [--interval INTERVAL] [--once]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--interval=VALUE` |  | Seconds between two ticks | `10` |
| `--once` |  | Run one tick and exit |  |

## job

The job queue.

### fleet job:list

List the jobs, newest first.

```
fleet job:list [--status STATUS] [--trigger TRIGGER] [--instance INSTANCE] [--limit LIMIT] [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--status=VALUE` |  | queued, running, succeeded, failed or interrupted |  |
| `--trigger=VALUE` |  | ui, cli, webhook or schedule |  |
| `--instance=VALUE` |  | Only the jobs of this instance |  |
| `--limit=VALUE` |  | How many jobs at most | `50` |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet job:prune

Delete finished jobs and their logs past the retention.

```
fleet job:prune [--days DAYS]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--days=VALUE` |  | Keep jobs finished within this many days (default FLEET_JOB_RETENTION_DAYS, 90) |  |

### fleet job:retry

Repeat a failed or interrupted job as a new job.

```
fleet job:retry [--detach] [--format FORMAT] [--json] [--] <id>
```

| Argument | Required | Description |
|---|---|---|
| `id` | yes | Job number |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--detach` |  | Only queue the job(s) and return |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet job:show

Show one job.

```
fleet job:show [--format FORMAT] [--json] [--] <id>
```

| Argument | Required | Description |
|---|---|---|
| `id` | yes | Job number |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet job:work

*(internal, run by the worker/upgrade helper)*

Run one queued job in this process (used by the worker).

```
fleet job:work [--eligible-since ELIGIBLE-SINCE] [--] <id>
```

| Argument | Required | Description |
|---|---|---|
| `id` | yes | Job number |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--eligible-since=VALUE` |  | Give-up clock a previous delivery computed (Unix timestamp) |  |

## asset

Per-project seed files.

### fleet asset:gc

Remove abandoned browser uploads and leftover temporary files.

```
fleet asset:gc [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet asset:list

List the files in a project's assets directory.

```
fleet asset:list [--format FORMAT] [--json] [--] <project> [<path>]
```

| Argument | Required | Description |
|---|---|---|
| `project` | yes | Project name |
| `path` | no | Folder inside the assets directory (default: the top) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet asset:snapshot

Dump a running instance's database into its project's assets (dumps/).

```
fleet asset:snapshot [--format FORMAT] [--json] [--] <instance-id>
```

| Argument | Required | Description |
|---|---|---|
| `instance-id` | yes | Instance id, for example blog--main |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## secret

Encrypted secrets.

### fleet secret:list

List the names of the fleet-wide or a project's secrets (never a value).

```
fleet secret:list [--project PROJECT] [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--project=VALUE` |  | List this project's secrets instead of the fleet-wide ones |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet secret:set

Add a secret or replace its value (the value is read from a hidden prompt or standard input).

```
fleet secret:set [--project PROJECT] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Secret name: capital letters, digits and underscores, for example JIRA_TOKEN |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--project=VALUE` |  | Set a secret of this project instead of a fleet-wide one |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet secret:unset

Delete a secret.

```
fleet secret:unset [--project PROJECT] [-y|--yes] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Secret name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--project=VALUE` |  | Delete a secret of this project instead of a fleet-wide one |  |
| `--yes` | `-y` | Do not ask for confirmation |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## user

Users and roles.

### fleet user:create

Create a user.

```
fleet user:create [--display-name DISPLAY-NAME] [--email EMAIL] [--admin] [--site-only] [--project PROJECT] [--password-stdin] [--format FORMAT] [--json] [--] <username>
```

| Argument | Required | Description |
|---|---|---|
| `username` | yes | Username (lower case letters, digits, dot, underscore or dash; cannot be changed later) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--display-name=VALUE` |  | Display name (default: the username) |  |
| `--email=VALUE` |  | Email address |  |
| `--admin` |  | Make the user an administrator |  |
| `--site-only` |  | A site-only user: opens the instance sites of its projects, nothing else (no email needed, short password allowed) |  |
| `--project=VALUE (repeatable)` |  | A project the user is a member of (repeatable) |  |
| `--password-stdin` |  | Read the password from the first line of standard input (otherwise it is asked on the terminal, without echo) |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet user:delete

Delete a user with their project memberships (refused for the last enabled administrator).

```
fleet user:delete [-y|--yes] [--format FORMAT] [--json] [--] <username>
```

| Argument | Required | Description |
|---|---|---|
| `username` | yes | Username |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--yes` | `-y` | Do not ask for confirmation |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet user:grant

Make a user a member of a project.

```
fleet user:grant [--format FORMAT] [--json] [--] <username> <project>
```

| Argument | Required | Description |
|---|---|---|
| `username` | yes | Username |
| `project` | yes | Project name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet user:list

List the users.

```
fleet user:list [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet user:reset-password

Set a user's password and refresh the Authelia users file.

```
fleet user:reset-password [--no-apply] [--] <username>
```

| Argument | Required | Description |
|---|---|---|
| `username` | yes | The user whose password is reset |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--no-apply` |  | Write the Authelia users file but do not install it (no sudo, no Authelia restart) |  |

### fleet user:revoke

Remove the membership of a user in a project.

```
fleet user:revoke [--format FORMAT] [--json] [--] <username> <project>
```

| Argument | Required | Description |
|---|---|---|
| `username` | yes | Username |
| `project` | yes | Project name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet user:update

Change a user (only the options you give).

```
fleet user:update [--display-name DISPLAY-NAME] [--email EMAIL] [--enable] [--disable] [--admin|--no-admin] [--site-only|--no-site-only] [--format FORMAT] [--json] [--] <username>
```

| Argument | Required | Description |
|---|---|---|
| `username` | yes | Username |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--display-name=VALUE` |  | New display name |  |
| `--email=VALUE` |  | New email address |  |
| `--enable` |  | Enable the user (the user can sign in) |  |
| `--disable` |  | Disable the user (the user cannot sign in; nothing is deleted) |  |
| `--admin / --no-admin` |  | Make the user an administrator (--no-admin: a member) |  |
| `--site-only / --no-site-only` |  | Make the user a site-only user (--no-site-only: a regular user) |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## webhook

Jira and Bitbucket receivers.

### fleet webhook:list

List the webhooks that have a secret, with their addresses and rules (never the secrets).

```
fleet webhook:list [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet webhook:log

Show the latest webhook deliveries (newest first).

```
fleet webhook:log [--project PROJECT] [--limit LIMIT] [--hide-ignored] [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--project=VALUE` |  | Only this project |  |
| `--limit=VALUE` |  | How many deliveries | `20` |
| `--hide-ignored` |  | Leave out ignored deliveries |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet webhook:rule:add

Add a rule to a project webhook (the webhook needs a secret first).

```
fleet webhook:rule:add [--source SOURCE] [--action ACTION] [--on-status ON-STATUS] [--on-event ON-EVENT] [--repo REPO] [--match-branch MATCH-BRANCH] [--state STATE] [--comment COMMENT] [--template TEMPLATE] [--branch BRANCH] [--label LABEL] [--label-prefix LABEL-PREFIX] [--strip-suffix STRIP-SUFFIX] [--reuse] [--pattern PATTERN] [--ref REF] [--format FORMAT] [--json] [--] <project>
```

| Argument | Required | Description |
|---|---|---|
| `project` | yes | Project name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--source=VALUE` |  | Who sends the events: jira or bitbucket | `jira` |
| `--action=VALUE` |  | deploy, or run-pipeline (Bitbucket) | `deploy` |
| `--on-status=VALUE` |  | Jira: the status an issue moves into, e.g. Dispatched |  |
| `--on-event=VALUE` |  | Bitbucket: the event key, e.g. pullrequest:approved |  |
| `--repo=VALUE` |  | Bitbucket: workspace/slug the event must come from |  |
| `--match-branch=VALUE` |  | Bitbucket: branch glob the event must concern, e.g. renovate/* |  |
| `--state=VALUE` |  | Bitbucket: commit status state, e.g. SUCCESSFUL |  |
| `--comment=VALUE` |  | Bitbucket: a comment line to look for, e.g. /merge |  |
| `--template=VALUE` |  | deploy: the project template to deploy |  |
| `--branch=VALUE` |  | deploy (Jira): the branch to deploy, default the project's |  |
| `--label=VALUE` |  | deploy (Bitbucket): a fixed instance label, default derived from the branch |  |
| `--label-prefix=VALUE` |  | deploy (Bitbucket): put this in front of the branch to form the label; never overwrites an instance |  |
| `--strip-suffix=VALUE` |  | deploy (Bitbucket): text removed from the end of the branch first; needs --label-prefix, and the branch glob must end with it |  |
| `--reuse` |  | deploy (Bitbucket): a push to a branch whose instance already exists redeploys it in place instead of allocating -1, -2... (use with --label-prefix or the default label) |  |
| `--pattern=VALUE` |  | run-pipeline: the custom pipeline name |  |
| `--ref=VALUE` |  | run-pipeline: the branch to run it on |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet webhook:rule:remove

Remove a rule from a project webhook (see fleet:webhook:list for the ids).

```
fleet webhook:rule:remove [--format FORMAT] [--json] [--] <project> <rule>
```

| Argument | Required | Description |
|---|---|---|
| `project` | yes | Project name |
| `rule` | yes | Rule id |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet webhook:secret

Create (or with --rotate, replace) the secret of a project webhook; the secret is shown once.

```
fleet webhook:secret [--source SOURCE] [--rotate] [--format FORMAT] [--json] [--] <project>
```

| Argument | Required | Description |
|---|---|---|
| `project` | yes | Project name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--source=VALUE` |  | Who sends the events: jira or bitbucket | `jira` |
| `--rotate` |  | Replace an existing secret (the old one stops working at once) |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet webhook:token

Set, replace or (with --clear) remove the Bitbucket pipeline token of a project (read from a hidden prompt or standard input).

```
fleet webhook:token [--clear] [--format FORMAT] [--json] [--] <project>
```

| Argument | Required | Description |
|---|---|---|
| `project` | yes | Project name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--clear` |  | Remove the stored token instead of setting one |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## schedule

Cron schedules that redeploy.

### fleet schedule:create

Create a schedule.

```
fleet schedule:create [--cron CRON] [--timezone TIMEZONE] [--action ACTION] [--instance INSTANCE] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Schedule name, for example nightly-redeploy |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--cron=VALUE` |  | Five-field cron expression, for example "0 3 * * *" (required) |  |
| `--timezone=VALUE` |  | IANA timezone | `UTC` |
| `--action=VALUE` |  | What to run, one of: redeploy | `redeploy` |
| `--instance=VALUE` |  | Id of the instance the action applies to, for example demo--main |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet schedule:delete

Delete a schedule and its history.

```
fleet schedule:delete [-y|--yes] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Schedule name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--yes` | `-y` | Do not ask for confirmation |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet schedule:history

Show the runs of a schedule, newest first.

```
fleet schedule:history [--limit LIMIT] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Schedule name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--limit=VALUE` |  | How many runs to show | `50` |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet schedule:list

List the schedules.

```
fleet schedule:list [--format FORMAT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet schedule:pause

Stop a schedule from firing (run now still works).

```
fleet schedule:pause [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Schedule name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet schedule:resume

Let a paused schedule fire again, without catching up what it missed.

```
fleet schedule:resume [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Schedule name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet schedule:run

Run a schedule now (also when it is paused).

```
fleet schedule:run [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Schedule name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet schedule:update

Change the timing, action or instance of a schedule (not its name).

```
fleet schedule:update [--cron CRON] [--timezone TIMEZONE] [--action ACTION] [--instance INSTANCE] [--format FORMAT] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Schedule name |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--cron=VALUE` |  | Five-field cron expression, for example "0 3 * * *" (required) |  |
| `--timezone=VALUE` |  | IANA timezone | `UTC` |
| `--action=VALUE` |  | What to run, one of: redeploy | `redeploy` |
| `--instance=VALUE` |  | Id of the instance the action applies to, for example demo--main |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## claude

The Claude Code token.

### fleet claude:token:apply

Restart running instances so they pick up the stored Claude token.

```
fleet claude:token:apply [--instance INSTANCE] [--all] [-y|--yes] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--instance=VALUE (repeatable)` |  | Restart this instance (repeatable) |  |
| `--all` |  | Restart every running instance (asks for confirmation) |  |
| `--yes` | `-y` | Do not ask for confirmation (--all) |  |
| `--json` |  | Print the queued jobs as JSON |  |

### fleet claude:token:set

Replace the fleet-wide Claude token (read from a hidden prompt or standard input).

```
fleet claude:token:set [--apply]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--apply` |  | Also restart every running instance, one at a time, so it picks the token up (ends a running agent) |  |

## tmux

The tmux workspace.

### fleet tmux:ensure

Create or heal the fleet tmux session: general + one window per instance, prune the gone ones (idempotent).

```
fleet tmux:ensure
```

### fleet tmux:list

List the windows of the fleet tmux session.

```
fleet tmux:list [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--json` |  | Print {"session": ..., "running": ..., "windows": [...]} as JSON |  |

### fleet tmux:reset

Rebuild one window (or all of them) in place with fresh shells and sidebar.

```
fleet tmux:reset [--pane PANE] [--] [<window>]
```

| Argument | Required | Description |
|---|---|---|
| `window` | no | Instance id of the window, or general; omit it to reset every instance window |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--pane=VALUE` |  | Reset the window this tmux pane id (%12) is in |  |

### fleet tmux:sidebar

Print the sidebar of a window (all instances, state, URL, keys); repeats unless --once.

```
fleet tmux:sidebar [--window WINDOW] [--once]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--window=VALUE` |  | Instance id of the window, or general (default: the window of this pane) |  |
| `--once` |  | Print once and exit |  |

## workspace

Workspace checks.

### fleet workspace:check

Check that the push key, the git identity and the Claude token are set (read-only).

```
fleet workspace:check [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--json` |  | Print the checks as JSON booleans |  |

## host

Host settings, certificates, capacity.

### fleet host:capacity

Show the host's CPU threads, allotments, live use and slot occupancy.

```
fleet host:capacity [--json] [--reserve RESERVE] [--max-instances MAX-INSTANCES]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--json` |  | Print the report as JSON |  |
| `--reserve=VALUE` |  | Compute with this many reserved threads instead of the saved value |  |
| `--max-instances=VALUE` |  | Report this instance cap instead of the saved value |  |

### fleet host:drift

Check the host against the installed release without changing anything.

```
fleet host:drift [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--json` |  | Print the report as JSON |  |

### fleet host:init

Create the state directories, the secret key and the host settings (idempotent).

```
fleet host:init [--domain DOMAIN] [--acme-email ACME-EMAIL] [--tls-mode TLS-MODE] [--force]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--domain=VALUE` |  | The fully qualified domain name the fleet is served on, e.g. fleet.example.com |  |
| `--acme-email=VALUE` |  | Contact address for Let's Encrypt (optional) |  |
| `--tls-mode=VALUE` |  | How certificates are obtained: http01 (default for a new host) or ovh_dns |  |
| `--force` |  | Allow changing the domain or the TLS mode after setup is complete |  |

### fleet host:job-concurrency

Show or set how many jobs of different instances run at the same time.

```
fleet host:job-concurrency [<jobs>]
```

| Argument | Required | Description |
|---|---|---|
| `jobs` | no | New limit, 1 to 8 (1 = one job after another) |

### fleet host:ovh-credentials

Replace the OVH application key, secret and consumer key of a DNS-mode host, then apply and reload Caddy.

```
fleet host:ovh-credentials [--endpoint ENDPOINT]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--endpoint=VALUE` |  | OVH API endpoint: ovh-eu, ovh-ca, ovh-us (default: the stored one) |  |

### fleet host:render

**sudo**: needs root.

Render the Caddy/Authelia config for the root helper and the Caddy gate.

```
fleet host:render [--gate GATE] [--no-reload] [--force] [--apply] [--stage]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--gate=VALUE` |  | auto, setup or authelia | `auto` |
| `--no-reload` |  | Write the gate file but do not validate or reload Caddy |  |
| `--force` |  | Allow --gate=setup after setup is complete (reopens the wizard's gate; prefer fleet:setup:reset) |  |
| `--apply` |  | Install the config through the root helper (authelia, then caddy) and remove what was staged |  |
| `--stage` |  | Leave the rendered config staged for a root `apply` (the installer only); otherwise nothing stays staged |  |

### fleet host:slices

Install the CPU slices for the projects and templates on this host (--print: show them only).

```
fleet host:slices [--print]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--print` |  | Print the slice list (JSON) and install nothing |  |

### fleet host:tls-check

Check Caddy's certificate health (rate limits, failed solves, expiry) and write the status file.

```
fleet host:tls-check
```

### fleet host:tls-status

Show the certificate health of this host (exit 0 ok, 1 warning, 2 error).

```
fleet host:tls-status [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--json` |  | Print the status as JSON |  |

## backup

Database backups.

### fleet backup:create

Back up the databases to FLEET_HOME/backups and prune backups older than the retention.

```
fleet backup:create
```

### fleet backup:restore

**sudo**: needs root.

Restore a database backup into the live databases (verifies first, keeps the previous database).

```
fleet backup:restore [-y|--yes] [--accept-key-mismatch] [--json] [--] <file>
```

| Argument | Required | Description |
|---|---|---|
| `file` | yes | The fleet backup, for example /srv/fleet/backups/fleet-20261019-083015.sqlite (its queue-<same stamp>.sqlite is restored too when present) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--yes` | `-y` | Do not ask for confirmation (required without a terminal) |  |
| `--accept-key-mismatch` |  | Restore a backup sealed by another secret key; every secret in it must then be set again |  |
| `--json` |  | Print the result as JSON |  |

### fleet backup:verify

Check a database backup file (integrity and schema version) without changing anything.

```
fleet backup:verify [--json] [--] <file>
```

| Argument | Required | Description |
|---|---|---|
| `file` | yes | The backup file, for example /srv/fleet/backups/fleet-20261019-083015.sqlite |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--json` |  | Print the result as JSON |  |

## setup

The setup wizard.

### fleet setup:reset

Open the setup wizard again: mint a new token and put the setup gate back.

```
fleet setup:reset [-y|--yes]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--yes` | `-y` | Do not ask for confirmation |  |

### fleet setup:token

Mint a one-time setup token and print the setup URL (valid 24 hours).

```
fleet setup:token
```

## export

Export a project.

### fleet export:project

Export one project as a versioned file (stdout, or --output).

```
fleet export:project [-o|--output OUTPUT] [--force] [--with-secrets] [--passphrase-stdin] [--format FORMAT] [--json] [--] <slug>
```

| Argument | Required | Description |
|---|---|---|
| `slug` | yes | Project name (its slug) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--output=VALUE` | `-o` | Write the file here (mode 0600, refused when it exists) instead of stdout |  |
| `--force` |  | Replace the --output file when it exists |  |
| `--with-secrets` |  | Add the project's secrets, sealed with a passphrase (prompt, FLEET_EXPORT_PASSPHRASE or --passphrase-stdin) |  |
| `--passphrase-stdin` |  | Read the passphrase from the first line of standard input |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## import

Import a project.

### fleet import:apply

Import a project export file after showing the preview (all or nothing).

```
fleet import:apply [-y|--yes] [--passphrase-stdin] [--format FORMAT] [--json] [--] <file>
```

| Argument | Required | Description |
|---|---|---|
| `file` | yes | The export file |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--yes` | `-y` | Do not ask for confirmation |  |
| `--passphrase-stdin` |  | Read the passphrase of the sealed secrets from the first line of standard input |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

### fleet import:preview

Show what importing a project export file would create, update or skip (writes nothing).

```
fleet import:preview [--passphrase-stdin] [--format FORMAT] [--json] [--] <file>
```

| Argument | Required | Description |
|---|---|---|
| `file` | yes | The export file |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--passphrase-stdin` |  | Read the passphrase of the sealed secrets from the first line of standard input |  |
| `--format=VALUE` |  | Output format: table (for people) or json (for scripts) | `table` |
| `--json` |  | Same as --format=json |  |

## upgrade

Release check, history and verification.

### fleet upgrade:backup

*(internal, run by the worker/upgrade helper)*

Internal: take the pre-upgrade database backup.

```
fleet upgrade:backup
```

### fleet upgrade:check

Show the installed version, the next release, its changelog and the pre-flight results (changes nothing).

```
fleet upgrade:check [--to TO] [--json] [--no-cache]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--to=VALUE` |  | Check this release (vX.Y.Z) instead of the latest |  |
| `--json` |  | Print one JSON document (schema in docs/reference/05-upgrade.md) |  |
| `--no-cache` |  | Ask the release source now instead of using a list younger than 6 hours |  |

### fleet upgrade:history

Show the upgrades run on this host, newest first.

```
fleet upgrade:history [--limit LIMIT] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--limit=VALUE` |  | How many lines to show | `20` |
| `--json` |  | Print the entries as a JSON array |  |

### fleet upgrade:plan

*(internal, run by the worker/upgrade helper)*

Internal: the upgrade plan and pre-flight results as JSON.

```
fleet upgrade:plan [--to TO] [--no-cache] [--force FORCE]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--to=VALUE` |  | Plan this release (vX.Y.Z) instead of the latest |  |
| `--no-cache` |  | Ask the release source now |  |
| `--force=VALUE (repeatable)` |  | Check id to waive (overridable Blocks only) |  |

### fleet upgrade:prepare

*(internal, run by the worker/upgrade helper)*

Internal: plan and pre-flight for `sudo fleet upgrade`.

```
fleet upgrade:prepare [--to TO] [--no-db-restore] [--force FORCE]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--to=VALUE` |  | Upgrade to this release (vX.Y.Z) instead of the latest |  |
| `--no-db-restore` |  | Rollback only: keep the current database (allowed only when it has no migration the target lacks) |  |
| `--force=VALUE (repeatable)` |  | Check id to waive (overridable Blocks only) |  |

### fleet upgrade:refresh

*(internal, run by the worker/upgrade helper)*

Look up the published releases and refresh the cached catalog.

```
fleet upgrade:refresh [--if-stale]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--if-stale` |  | Do nothing when the cached catalog is younger than 6 hours |  |

### fleet upgrade:restore

*(internal, run by the worker/upgrade helper)*

Internal: restore the pre-upgrade backup for a rollback.

```
fleet upgrade:restore [--backup BACKUP]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--backup=VALUE` |  | The backup file name (fleet-YYYYmmdd-HHMMSS.sqlite) |  |

### fleet upgrade:verify

Verify the host after an upgrade: health, Authelia, installed version, migrations, workers, audit row.

```
fleet upgrade:verify [--to TO] [--from FROM] [--sha SHA] [--actor ACTOR]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--to=VALUE` |  | The version the host should run now (vX.Y.Z) |  |
| `--from=VALUE` |  | The version it ran before (for the audit row and the rollback hint) | `unknown` |
| `--sha=VALUE` |  | The commit the plan resolved for --to (the checkout must be at it) |  |
| `--actor=VALUE` |  | Who ran the upgrade (unix user, for the audit row) | `cli` |

## token

Token.

### fleet token:create

Create an API token (the secret is printed once).

```
fleet token:create [--description DESCRIPTION] [-s|--scope SCOPE] [-p|--project PROJECT] [--instance-glob INSTANCE-GLOB] [--ip IP] [--expires-in EXPIRES-IN] [--json] [--] <name>
```

| Argument | Required | Description |
|---|---|---|
| `name` | yes | Token name: lower-case letters, digits, ".", "_", "-" (max 32) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--description=VALUE` |  | What the token is for | `` |
| `--scope=VALUE (repeatable)` | `-s` | A scope (repeat for several) |  |
| `--project=VALUE (repeatable)` | `-p` | A project name, or * (read-only tokens) |  |
| `--instance-glob=VALUE (repeatable)` |  | Instance pattern for terminal scopes |  |
| `--ip=VALUE (repeatable)` |  | Allowed address or CIDR range (repeat) |  |
| `--expires-in=VALUE` |  | Lifetime in days (default depends on the scopes) |  |
| `--json` |  | Print the result as JSON, secret included |  |

### fleet token:list

List API tokens (active ones; --all adds revoked and expired).

```
fleet token:list [-a|--all] [--json]
```

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--all` | `-a` | Include revoked and expired tokens |  |
| `--json` |  | Print as JSON |  |

### fleet token:remind

Warn about API tokens that expire soon (and write the daily audit reminder).

```
fleet token:remind
```

### fleet token:revoke

Revoke an API token (immediate, irreversible).

```
fleet token:revoke <id>
```

| Argument | Required | Description |
|---|---|---|
| `id` | yes | Token id (see token:list) |

### fleet token:rotate

Replace an API token with a successor (new secret printed once).

```
fleet token:rotate [--grace GRACE] [--expires-in EXPIRES-IN] [--json] [--] <id>
```

| Argument | Required | Description |
|---|---|---|
| `id` | yes | Token id (see token:list) |

| Option | Shortcut | Description | Default |
|---|---|---|---|
| `--grace=VALUE` |  | Seconds the old token keeps working (0 to 3600) | `0` |
| `--expires-in=VALUE` |  | Lifetime of the successor in days (default depends on the scopes) |  |
| `--json` |  | Print the result as JSON, secret included |  |

## Wrapper commands (`install/bin/fleet`)

The `/usr/local/bin/fleet` wrapper switches to the `fleet` user, loads `/etc/ddev-fleet/fleet.env` and adds the following on top of the console commands above.

| Command | What the wrapper does |
|---|---|
| `fleet` | Lists the `fleet:*` commands. |
| `fleet <group> <verb>` | Same as `fleet <group>:<verb>`; a full `fleet:<group>:<verb>` name also works. |
| `sudo fleet upgrade [--to vX.Y.Z] [--yes] [--force=<check-id>]` | **sudo.** Hands over to the root helper that runs the installer, with a backup and a verification. Refused (exit `3`) without root. Options: [upgrade reference](05-upgrade.md#upgrade). |
| `fleet upgrade --check [--to vX.Y.Z] [--json]` | Runs `fleet:upgrade:check` (read-only, no root). |
| `fleet upgrade history`, `fleet upgrade verify` | Run `fleet:upgrade:history` and `fleet:upgrade:verify`. |
| `fleet tmux [<instance-id>]` | Attaches your terminal to the tmux workspace, optionally on one instance's window (also `fleet tmux attach [<instance-id>]` and `fleet tmux:attach`). Needs a terminal (`ssh -t`) and refuses to nest inside tmux (exit `2`). |

`fleet backup:*` and the other groups are ordinary console commands, documented above.

## Learn more

- [Configuration](02-configuration.md)
- [Installer](04-installer.md)
- [Files on disk](03-files-on-disk.md)
