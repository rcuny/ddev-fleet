---
name: fleet-status
description: Read-only fleet health summary — running/deployed instance counts, RAM and disk headroom, and a flagged-anomaly list built from the deploy logs. Invoked via /fleet-status or phrases like "how's the fleet doing"; optionally scope to one project by name.
---

# /fleet-status — fleet health summary

Fully read-only. Never runs `fleet deploy`/`start`/`stop`/`destroy` or any
`ddev` mutating command.

## Inputs

- None required.
- Optional: a project name to scope the summary to. `fleet list` has **no**
  `--project` flag — filter the printed table yourself by matching the
  `PROJECT` column (2nd column, 20 chars wide).

## Steps

1. Run `fleet list`. Columns are fixed-width:
   `INSTANCE ID(30) PROJECT(20) BRANCH(15) STATE(10) RAM(MiB)(10) URL`.
   `STATE` is exactly `running` or `deployed` (`deployed` = present on disk,
   `ddev`-stopped, not currently running) — there is no third state string.
   If a project scope was given, keep only matching rows for the counts
   below (but still scan all logs in step 3 — logs aren't project-labelled
   in their path).
2. Run `df -h /srv/fleet` and `free -h` for disk and RAM headroom.
   From `df`'s `Use%` column, compute `free_pct = 100 - use_pct`.
3. Anomaly scan — cross-reference `/srv/fleet/logs/*/` against the `fleet
   list` output:
   - Run `ls /srv/fleet/logs` to get every instance id that has ever been
     deployed (logs survive `fleet destroy` by design — this is expected).
   - For each id from `ls /srv/fleet/logs` that is **not** a row in `fleet
     list`: this almost always just means the instance was cleanly
     destroyed and its log retained. Do **not** call this an anomaly by
     itself — note it only as context, e.g. "N ids present in logs/ but no
     longer deployed (destroyed)".
   - For each id (whether or not it's still in `fleet list`) whose
     `tail /srv/fleet/logs/<id>/deploy.log` does **not** end with the exact
     line `deploy complete`: this **is** an anomaly — the deploy pipeline
     stopped partway (crash, a failing `post_deploy` command, `ddev start`
     failure, etc.). Quote the last 1-3 non-empty lines of that command's
     `tail` output as the evidence (never re-run `/fleet-triage`'s full-log
     read here — that's a separate, deeper skill).
   - Command shape matters: use exactly `tail /srv/fleet/logs/<id>/deploy.log`
     (no flags before the path) and exactly `cat /srv/fleet/logs/<id>/deploy.log`
     if you need more than the default last 10 lines — inserting flags
     before the path breaks the settings.json allow-rule's literal prefix
     match and forces an avoidable permission prompt.

## Output format

```
Fleet status[ — <project>]: <R> running, <D> deployed (stopped), <T> total
RAM: <used>/<total>  ·  Disk (/srv/fleet): <used>/<total> (<free_pct>% free)
[⚠ Disk headroom is low: <free_pct>% free (warn threshold 15%; a hard
  10%-free gate blocks multi-instance deploys separately) — only printed
  when free_pct < 15]

Anomalies:
  - <id>: deploy incomplete — last log line(s): "<quoted line(s)>"
  - ... (repeat per flagged id)
  (none) — if nothing was flagged

Context: <N> id(s) in logs/ no longer deployed (destroyed) — expected, not
flagged above.
```

Never print the full, unfiltered `fleet list` table as the primary output —
the counts + anomaly list above are the deliverable. If the user explicitly
asks to see the raw table too, it's fine to include it after the summary.
