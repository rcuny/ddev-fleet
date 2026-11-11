# Git access and SSH keys

This guide answers one question: what can happen to the code you pull into Fleet's DDEV instances? Who can read it, who can push it, from where, and with which key. It is the task-oriented companion to the [SSH keys reference](../reference/14-ssh-keys.md), which stays the place for every option and command.

## The three keys

Fleet uses at most three kinds of SSH key. Each one has a single job.

| Key | Purpose | Where it is used |
|---|---|---|
| **Deploy key** (`deploy_ed25519`) | **Read.** The host clones and pulls your projects' repositories. | On the host only. Never inside an instance. |
| **Push key** (`push_ed25519`) | **Write.** Agents inside an instance push branches. | The instance's own SSH agent, and only when the project has **Git over SSH in the container** on. |
| **Extra keys** (named per template) | **Other git hosts.** A private package registry, a second forge. | The same instance agent, next to the push key or instead of it. |

The private half of a key stays in a file on the host (mode 0600). It is never shown in the web UI, never stored in the database and never copied into a container. What you register on a git host is the public half, which the **SSH keys** tab (`/admin/host/ssh-keys`) and `fleet ssh:key:list` show next to the fingerprint, the use and the creation date.

## What can and cannot happen to your code

- **An instance can push only if container SSH is on and a key it holds is allowed on the git host.** With container SSH off, an instance holds no key at all and cannot push over SSH.
- **With "extras only", the push key never enters the instance.** The instance holds only the extra keys its template names, so a narrow-purpose instance cannot write to your main repositories.
- **An instance never sees the deploy key.** Only the host reads with it.
- **Other instances cannot use your instance's agent.** Each instance gets its own agent, mounted in that instance's container only.
- **Whatever is pushed is limited by what the key may do on the git host.** Fleet cannot grant more than the host allows. Register the push key with write access only on the repositories agents must push to, and turn on branch protection for `main` and `develop`, so an agent can push a branch but not rewrite or merge into the branches you release from.

Inside an instance with container SSH on, any process in the container can use the loaded keys while the agent runs. Turn the setting on only for projects whose agents must push.

## Set up a project so agents can push

1. Open the **SSH keys** tab and copy the **push** key's public key.
2. On your git host, register it as an access key (a deploy key) with **write** access to the repository.
3. In the project, open **Keys & secrets** and turn on **Git over SSH in the container**. You are asked for your password again.
4. In the template, choose **SSH identities**: "push key + extras" (the default) or "extras only". Name extra keys in **Extra SSH keys** if the template needs them.
5. Back on the **SSH keys** tab, pick the git host and press **Check the push key**. It tests the push key alone, not your `~/.ssh/config` and not an agent, and tells you whether the host accepts it.
6. Start or redeploy the instance. The agent loads the keys for 24 hours, and the status watcher adds them again every 10 minutes while the instance runs.

The check also runs by itself as a warning that never blocks: at the start of a deploy of a project with container SSH on, and after you save a template of such a project.

## Rotate and revoke keys

- **Rotate** (the button on the host key, behind your password) replaces the key. The old key stops working wherever it is registered, so register the new public key on the git host first or right after, then run **Check the push key**. Until you do, deploys that rely on it fail and instances cannot push.
- **Revoke** a key by removing its public half from the git host. The host refuses it at once; the copy in an instance's agent is useless from then on and expires within 24 hours.
- **Delete** an extra key on the **SSH keys** tab. Fleet refuses while a template still names it.
- Turning container SSH **off** stops new and restarted instances from getting the push key.

## Troubleshooting

| What you see | Cause and fix |
|---|---|
| `Permission denied (publickey)` in the container | The git host does not know the key. Run **Check the push key**, then register the public key shown on the **SSH keys** tab with write access. |
| The agent holds no identities (`ssh-add -l` says so) | The watcher adds the keys again within 10 minutes. To skip the wait, start the instance again. Also check that container SSH is on and, with "extras only", that the template names an extra key. |
| A warning about `ddev auth ssh` or `ssh-add -D` in a template | Old template lines. Remove them (see the next section). |
| The check says the host key is not trusted | Compare the fingerprints, then run `fleet ssh:trust <host>`. |
| The check says the git host could not be reached | Nothing was tested. Fix the network and run it again. |

## Why not DDEV's shared agent

Older templates ran `ddev exec ssh-add -D` and `ddev auth ssh -f .../push_ed25519`. DDEV's agent is **shared**: every DDEV project on the host can use what it holds, and clearing it breaks the other instances. One template line would hand your write key to all of them. Fleet starts one agent per instance instead, so those lines are unnecessary. Fleet still saves a template that contains them, but warns. Remove the lines and use container SSH and extra keys.

## Go further

- [SSH keys reference](../reference/14-ssh-keys.md): extra keys, the `ssh_identities` option, every command.
- [Secure the host](08-secure-the-host.md): the wider security model.
- [Share and access](06-share-and-access.md): who may sign in to Fleet.
