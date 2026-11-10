# SSH keys

Fleet uses SSH keys for two different jobs: the host pulls your code, and an instance may push it. This page is the one place that says which key does what, where it lives and who decides when it is used. The list of every key, with fingerprints and public halves, is the **SSH keys** tab of the host settings (`/admin/host/ssh-keys`) and `fleet ssh:key:list`.

## The keys

| Key | Purpose | Where it is used | Who sees the private half |
|---|---|---|---|
| `deploy` | **Pull.** Clones and fetches your projects' repositories on the host. Read access is enough: register it as a read-only access key. | The host's own git commands, with `IdentitiesOnly`. Never given to an instance. | Nobody: it stays in `ssh/deploy_ed25519` (mode 0600). |
| `push` | **Push.** Lets an instance push to your remotes. Register it with write access only where agents must push. | Loaded into that instance's own ssh-agent, only for projects with **Git over SSH in the container** turned on. | Nobody: a file on the host, loaded by `ssh-add`; no key file enters a container. |
| extra keys | One more server or repository a template needs to reach (a private package registry, a second forge). | Loaded into the agent of instances whose template names them, next to the push key or instead of it. | Nobody, as above (`ssh/<name>_<type>`). |

The deploy and push keys are created by the setup wizard (or `fleet host:init`). Rotating one (the **Rotate** button, behind your password) makes the old key stop working wherever it is registered, so register the new public key first or right after.

## The per-instance agent

With **Git over SSH in the container** on, each instance gets its own `ssh-agent` (socket in the instance's run directory, mounted into that instance's web container only). Keys are loaded for 24 hours and refreshed while the instance runs. Fleet never loads a key into DDEV's shared `ddev-ssh-agent`, which every DDEV project on the host can use. Any process in the instance container can use the loaded keys while the agent runs, so turn the setting on only where agents need to push.

## Extra keys

An extra key is a file `ssh/<name>_<type>`, where `<type>` is the key's real type: `ed25519` (generated keys), or `ed25519`, `ecdsa` or `rsa` for an imported one. Names are lower case letters, digits and single dashes, at most 32 characters; `deploy` and `push` are reserved; a template names at most 8.

| Origin | What it is | Manage it |
|---|---|---|
| `generated` | Created by Fleet (ed25519). | `fleet ssh:key:create <name>` or **Generate key**. |
| `imported` | An unencrypted private key you supplied. | `fleet ssh:key:import <name>` (key on standard input) or **Import key**. |
| `external` | A file you placed by hand. It keeps working. | You, by hand. Fleet lists it and shows its public half, and never deletes it. |

- Creating, importing, deleting and rotating from the web UI ask for your password again; each is recorded in the audit log (`ssh.key.create`, `ssh.key.import`, `ssh.key.delete`, `ssh.key.rotate`). The private key is never stored in the database, shown, logged or passed as a command argument.
- `fleet ssh:key:delete <name>` and **Delete** are refused while a template names the key, and for `external` keys.
- Trust the server a key talks to once with `fleet ssh:trust <host>`.
- Name the key in the template's **Extra SSH keys** (`--ssh-key=<name>`). The project needs Git over SSH in the container.

## Which identities a template gets: `ssh_identities`

| Value | Option | The agent holds |
|---|---|---|
| `push_and_extras` (default) | `--ssh-identities=push-and-extras` | the push key and the template's extra keys |
| `extras_only` | `--ssh-identities=extras-only` | only the template's extra keys: a narrow-purpose instance never gets the write key |

`extras_only` needs at least one extra key (otherwise the instance would have no key). Existing templates are `push_and_extras`. The value is part of the [project export](12-export-format.md) (`ssh_identities`, optional in older files). A change reaches an instance at its next start, redeploy or the status refresh (within about ten minutes); a running agent that held the push key drops it.

## Why never `ddev auth ssh`

`ddev auth ssh` and `ssh-add -D` in a template's post-deploy or terminal lines load or clear keys in DDEV's **shared** agent, which serves every DDEV project on the host: one template line would hand a key to all instances, and clearing it breaks the others. Fleet makes them unnecessary: turn on Git over SSH in the container and use extra keys. Saving a template with such a line still works, but warns (page flash, `warning` in `fleet template:create|update --json`).

## Commands

| Command | What it does |
|---|---|
| `fleet ssh:key:list [--json]` | Every key: purpose, fingerprint, public key, used by, created. |
| `fleet ssh:key:create <name>` | Generates an extra key and prints its public half. |
| `fleet ssh:key:import <name>` | Imports a private key from a hidden prompt or standard input. |
| `fleet ssh:key:delete <name> [--yes]` | Deletes a managed extra key that no template uses. |
| `fleet ssh:trust <host>` | Adds a server's host keys after you confirm their fingerprints. |

Exact options: [01-cli.md](01-cli.md).
