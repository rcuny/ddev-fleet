# Configuration reference

Every setting you can change, in one place: the host file, the Settings page, the fields and limits of projects, templates and ports, and the variables Fleet writes into each instance. Look things up here; the guides tell you when you need them.

| Where | What |
|---|---|
| [`/etc/ddev-fleet/fleet.env`](#etcddev-fleetfleetenv) | Process settings of the app, the workers and the CLI. |
| [Settings page](#host-settings-tabs) | Host-wide settings, in ten tabs. |
| [Projects, templates, ports](#fields-and-limits) | Fields and limits, same in the browser and the CLI. |
| [Instance variables](#instance-environment-variables) | What each instance receives. |
| [Placeholders and retention](#built-in-placeholders) | `[[token]]` names, how long things are kept. |

## `/etc/ddev-fleet/fleet.env`

The installer generates it once; a re-run only adds missing keys and never changes yours. Change it, then restart `php8.4-fpm` and the workers.

| Key | Value |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `prod`, `0`. The php-fpm pool also pins `APP_ENV=prod`. |
| `APP_SECRET` | Generated once (64 hex characters). |
| `MERCURE_JWT_SECRET` | Generated once; the hub's keys derive from it. |
| `FLEET_PROXY_SECRET` | Generated once. Caddy passes it to PHP (`FLEET_PROXY_AUTH`); only a request carrying it may assert an identity. |
| `FLEET_HOME` | `/srv/fleet`. |
| `FLEET_RUNNER` | `process`: commands really run. |
| `FLEET_TRUSTED_PROXIES` | Empty on purpose: trust comes from the proxy secret, not the peer address. |
| `FLEET_ADMIN_GROUP` | `fleet-admins`: the one group that makes an administrator. Set it **before** the wizard creates the administrator; later changes do not regroup existing users. Letters, digits, `.`, `_`, `-`. |
| `FLEET_CADDY_DIR` | `/etc/caddy/fleet`. |
| `FLEET_JOB_RETENTION_DAYS` | `90`: finished jobs and logs older than this are pruned daily. |
| `DEFAULT_URI` | `https://<domain>`. |
| `FLEET_AUTHELIA_SMTP_ADDRESS` | Optional. `smtp://host`, `submission://host:587` or `submissions://host:465`. With the sender, it switches Authelia's "Forgot password?" on. |
| `FLEET_AUTHELIA_SMTP_SENDER` | Optional. The From address. |
| `FLEET_AUTHELIA_SMTP_USERNAME` | Optional. Only when the relay wants a login. |
| `FLEET_UPGRADE_REPO_URL` | Optional. Where `fleet upgrade` fetches releases from instead of the public GitHub mirror (mirrors, rehearsals). `--repo-url` on the command line wins. |

After editing the SMTP keys run `sudo fleet host:render --apply`. The SMTP password recipe is in [07-hardening.md](07-hardening.md).

## Host settings tabs

Administrators open **Settings** at `/admin/host`.

| Tab | Holds |
|---|---|
| General | Domain and TLS mode (read-only), ACME contact email. |
| Git identity | Author name and email of commits the fleet makes. |
| Claude token | Write-only token for Claude Code in instances (a host secret). |
| Notifications | SMTP relay (host, port, user, password), sender and recipient. Stored encrypted, write-only. |
| Certificates | Read-only certificate health; a banner shows on every page when something is wrong. |
| Backups | List, download, "Back up now", retention in days. |
| Jobs | How many jobs run at once (`fleet host:job-concurrency`). |
| Uploads | Per-file cap for browser uploads (`asset_upload_max_bytes`, default 2 GiB). |
| Capacity | CPU reserve and instance cap ([10-capacity.md](10-capacity.md)). |
| Networking | In-network port mirror ([09-networking.md](09-networking.md)). |

## Fields and limits

The browser and the commands apply the same rules and refuse a bad value with the same message. Nothing refused is stored, and messages never echo what you typed. Names are **immutable**: an instance id is `<project>--<label>`, so to rename, create the new one and delete the old.

### Names (project, template, port)

1 to 40 characters: lowercase letters, digits, single dashes, starting and ending with a letter or digit, no `--`. Unique. `new` is reserved (allowed for a port).

### Project

| Field | Rule |
|---|---|
| Git URL | Required, at most 2048 characters, plain ASCII. Exactly three shapes: `https://host[:port]/path`, `ssh://[user@]host[:port]/path`, `[user@]host:path`. Refused: `http://`, `git://`, `file://`, `ext::` and every other scheme, local paths, credentials in the URL (never put a token there), a leading dash, spaces and control characters, query strings, fragments, `..` segments, IPv6 literals and single-number hosts such as `2130706433`. |
| Default branch | Optional valid git branch name (max 200). Blank means the repository's default. |
| Default template | Optional, one of the project's own templates. Cannot be deleted while it is the default. |
| Additional hostnames | Max 50. One label each (lowercase letters, digits, single dashes, no dots), unique in the project, and short enough that `<label>-<project>--<instance label>` stays within 63 characters. Each adds a name to the instance's DDEV config and Caddy site; in `http01` mode each is its own certificate, so many hostnames can hit Let's Encrypt limits. |
| Ports | Max 50 names from the catalogue. |
| Git identity | `inherit` (host default), `disabled`, or `override` with a name and/or email (a blank one falls back to the host default). Name: at most 100 characters, no control characters, `<`, `>`, `"`, `$` or `\` (DDEV cannot carry them). Email: a plain address. |
| Submodule branch | Optional relative path (such as `web/modules/custom/blog`) whose branch the UI shows. |
| Typesense, git over SSH in the container | Yes/no switches ([09-networking.md](09-networking.md#typesense-keys-and-environment), [agents guide](../guides/05-work-with-agents.md)). |
| CPU threads, slots | Whole numbers of at least 1, or `none` ([10-capacity.md](10-capacity.md)). |
| Issue id pattern | See below. |

### Template

| Field | Rule |
|---|---|
| Drupal environment | Optional, 1 to 64 letters, digits, `.`, `_`, `-`. Written verbatim as `DRUPAL_ENV=<value>` into the instance's `.env`; quotes and spaces are refused. |
| Extra SSH keys | Max 8 names of keys at `ssh/<name>_ed25519` in the fleet home (mode `0600`, owned by the fleet user; `deploy` and `push` are reserved). Needs git over SSH in the container on the project. Only the name is stored. Manage the keys with `fleet ssh:key:*` or the SSH keys tab ([14-ssh-keys.md](14-ssh-keys.md)). |
| SSH identities | `push_and_extras` (default) or `extras_only` (the agent gets no push key; needs at least one extra key). See [14-ssh-keys.md](14-ssh-keys.md). |
| Post-deploy, terminal 1, terminal 2 | Each max 50 lines of 2000 characters (the whole list at most 100 100 characters), valid UTF-8, **one command per line**. Every control character is refused (newline, tab, escape, NUL), as are Unicode line and format separators. Blank lines are dropped, spaces trimmed. |
| CPU threads, slots | As the project; a template may not exceed its project. |

**How post-deploy lines run.** On the host, in the instance's checkout, as an argument list and never through a shell. Quotes and backslashes group words; an unquoted `|`, `&`, `;`, `<`, `>`, `$`, backtick, `(`, `)`, `{` or `}` is refused when the deploy runs, with a hint: write `ddev exec '...'` for a command that needs the container's shell. `[[token]]` placeholders are substituted after splitting. A failing line fails the deploy. Terminal lines are typed into tmux panes on the host instead (`ddev logs -f` is fine there).

### Named port

| Field | Rule |
|---|---|
| Public and router port | Whole numbers 1 to 65535, each unique across the catalogue, router different from public. |
| Reserved | The host reserves 22, 80, 443, 2019, 3000, 8080, 8443, 8765 and 9091. |
| Access | `gated` (login, default) or `public`; applies to every project using the port. |
| Seeded entry | One migration entry, `typesense` (public `9108`, router `8108`, public access), unless those names or numbers are taken. |

### Issue id patterns

This one bites. The pattern finds the issue id in an instance label, then in the branch name, for example `(?:ABC|XYZ)-\d{1,6}`. It is a case-insensitive PCRE **without delimiters** (no `~`), max 200 characters. If it has a first group, that group is the id, otherwise the whole match; the id is upper-cased. Because the server runs it, backreferences, `\Q...\E`, recursion, callouts, conditionals, comments and nested repetition such as `(a+)+` are refused. The id must also be safe to use as a path or command word (letters, digits, `.`, `_`, `-`, no `..`); otherwise no `[[issue-id]]` is set. The Source tab has a tester.

## Instance environment variables

Fleet writes these on each deploy and restart. A changed value reaches a running instance at its next restart or redeploy.

| Variable | Where | Value |
|---|---|---|
| `DRUPAL_ENV` | `.env` | The template's Drupal environment, if set. |
| `DRUSH_OPTIONS_URI` | `web_environment` | `https://<instance host>`. |
| `FLEET_INSTANCE_HOST` | `web_environment` | The primary instance host name; use `https://$FLEET_INSTANCE_HOST` as a base URL. |
| `FLEET_PORT_<NAME>` | `web_environment` | Public port of each assigned named port (name upper-cased, `-` becomes `_`). |
| `FLEET_PROJECT_SLOTS`, `FLEET_TEMPLATE_SLOTS` | `web_environment` | Slot counts; empty means unlimited. |
| `FLEET_TEMPLATE_CPU_THREADS` | `web_environment` | The template's effective allotment. |
| `FLEET_SLOT_DIR`, `FLEET_PROJECT_SLOT_DIR` | `web_environment` | Lock directories `$FLEET_HOME/slots/<project>/<template>/` and `.../_project/`. |
| `TYPESENSE_API_KEY` | `.ddev/.env` and `web_environment` | Admin key (Typesense projects). |
| `FLEET_TYPESENSE_SEARCH_KEY`, `FLEET_TYPESENSE_HOST`, `FLEET_TYPESENSE_PORT` | `web_environment` | Search-only key, instance host, public port (9108). |
| `CLAUDE_CODE_OAUTH_TOKEN` | `web_environment` | The host's Claude token, when one is set. |
| `GIT_AUTHOR_NAME`, `GIT_AUTHOR_EMAIL`, `GIT_COMMITTER_NAME`, `GIT_COMMITTER_EMAIL` | `web_environment` | The git identity, unless disabled. |
| `GIT_SSH_COMMAND` | `web_environment` | Pinned known hosts and the container's ssh-agent. |

`.ddev/config.fleet.yaml` carries `web_environment`. Post-deploy commands also receive these, from the host side:

| Variable | Value |
|---|---|
| `FLEET_INSTANCE_ID`, `FLEET_PROJECT`, `FLEET_INSTANCE`, `FLEET_BRANCH` | Instance id, project, label, branch. |
| `FLEET_INSTANCE_FQDN`, `FLEET_DOMAIN` | Primary host name, fleet domain. |
| `FLEET_ISSUE_ID` | The extracted issue id, when the project has a pattern that matches. |

## Built-in placeholders

`[[` + lowercase letters, digits and dashes + `]]`, replaced in post-deploy commands, seeded asset files and `tty` commands. Built-in names:

`[[project]]`, `[[instance]]`, `[[instance-id]]`, `[[instance-fqdn]]`, `[[branch]]`, `[[fleet-domain]]`, `[[issue-id]]`

These cannot be used as secret names. Any other placeholder resolves to a project secret, else a fleet-wide one: [08-assets-and-secrets.md](08-assets-and-secrets.md).

## Retention

| What | Kept |
|---|---|
| Finished jobs and logs | 90 days (`FLEET_JOB_RETENTION_DAYS`); `fleet job:prune`. Instances are never pruned. |
| Webhook delivery log | 60 days. |
| Database backups | 14 days (editable on the Backups tab); the newest of each kind is always kept. |
| Unfinished browser uploads | 24 hours (`fleet asset:gc`). |

## Learn more

- [Files on disk](03-files-on-disk.md)
- [CLI reference](01-cli.md)
- [Capacity](10-capacity.md)
