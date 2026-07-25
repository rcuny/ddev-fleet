---
name: fleet-triage
description: Diagnose why a specific instance's deploy failed or never finished, by reading its full deploy.log and cross-checking the project's registry entry. Invoked via /fleet-triage <instance-id> or "why did <id> fail" — prompts for the id if omitted, never guesses.
---

# /fleet-triage — deploy-failure diagnosis

Read-only. Never auto-executes a remediation command — it only prints one
for the operator to run.

## Inputs

- `<instance-id>` — required. If the trigger phrase didn't include one, run
  `ls /srv/fleet/logs` to show what's available and **ask the operator
  directly** which id to triage. Never guess or default to "the most
  recent" — a wrong guess wastes the whole diagnosis.

## Step 1 — read the full log

Run exactly `cat /srv/fleet/logs/<instance-id>/deploy.log` (no flags before
the path — the settings.json allow-rule is a literal-prefix match on `cat
/srv/fleet/logs/`). If the file doesn't exist, say so plainly and stop —
that id has no deploy log (never deployed, or its log directory was
manually removed) and there's nothing to diagnose.

## Step 2 — identify the phase from the log's markers

`deploy()` (`src/fleet/core/instances.py`) writes these markers, always in
this order, and the CLI never appends the final raised error message to
this file (it only goes to the CLI's own stderr, seen live at deploy time,
not stored) — so diagnosis works from **where the log stops and what its
last real output lines say**, not from a stored exception string.

| Marker / content seen in the log | Phase |
|---|---|
| `deploy start: project=... template=... label=... branch=...` | 0. Pipeline started |
| raw `git clone`/`git pull` output lines (no fixed prefix) | 1. Git clone/update |
| `recovered from a partial instance directory left by a prior destroy` | (informational — a stale dir was cleaned up first) |
| `basic auth enabled for <fqdn>` / `basic auth disabled for <fqdn>` | 2. Caddy basic-auth config |
| raw `ddev start` output lines | 3. `ddev start` |
| `WARNING: Typesense search-key registration failed: ...` | 3b. Typesense key registration (non-fatal — deploy continues) |
| raw `ddev auth ssh` output / `WARNING: ddev auth ssh returned ...` | 4. Push-key setup (non-fatal — deploy continues) |
| raw output of each `post_deploy` command | 5. `post_deploy` commands (**fatal** on nonzero exit — this is the single most common failure phase) |
| `deploy complete` | 6. Success |

Diagnosis rule: if the log's last line is `deploy complete`, the deploy
succeeded overall (any WARNING lines earlier are non-fatal and already
explained inline). Otherwise, the failure happened in the phase whose
marker most recently appears before the log stops — most often mid-phase 5,
where the log's very last lines **are** the failing command's own
stdout/stderr (that's the "exact error line(s)" to quote — not a
paraphrase).

## Step 3 — cross-check the registry

Resolve which project/template this instance used from the log's `deploy
start: project=... template=...` line, then run:

```bash
grep -n -A 30 "^  <project>:" /srv/fleet/config/fleet.yml
```

to see that project's `templates.<template>.post_deploy` list, `git_bot`,
and `additional_hostnames`. Use this to (a) show which exact `post_deploy`
command was running when the log stopped (count command outputs from the
`deploy start` line, matching the registry's `post_deploy` list order), and
(b) flag config-shaped causes — e.g. a `post_deploy` command referencing a
secret (`fleet secret set <project> KEY ...`) that was never set, or a
`TokenError`-shaped failure (a `[[token]]` left unresolved in a copied
asset file) that names the offending file in its own output line.

## Output format

```
Triage: <instance-id> (project=<p> template=<t> branch=<b>)

Last log line: "<line>"
Diagnosis: [succeeded | failed in phase <N>: <phase name>]

Quoted evidence:
  <the actual last 1-5 non-empty lines of deploy.log, verbatim>

Suggested fix: <one concrete next action, e.g. "run `fleet secret set
<project> SLACK_BOT_TOKEN <value>` then re-deploy with `fleet deploy
<project> <template> --branch <b> --label <label> --fresh`">

(Print the suggested command; do not run it.)
```

If the failure phase can't be narrowed past "somewhere after phase N", say
so honestly rather than guessing a specific cause — this skill's job is an
honest read of the evidence, not a confident-sounding wrong answer.
