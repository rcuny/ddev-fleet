---
name: fleet-cleanup
description: Find stale fleet instances (stopped for a long time, or a deploy that never finished) and, only after an explicit yes/no confirmation, destroy them. Invoked via /fleet-cleanup or "what can we destroy" / "clean up the stale ones" — a bare invocation only ever builds the candidate list, it never destroys anything on its own.
---

# /fleet-cleanup — candidate finder + gated bulk destroy

**Hard rule: nothing is ever destroyed without an explicit, separate "yes"
from the operator to this skill's own question.** Imperative phrasing like
"clean up the stale ones" still only selects candidates — it is not itself
a confirmation.

## Inputs

- Optional age threshold — default: `state == deployed` (i.e. not
  currently `running`) for **more than 7 days**, OR a `deploy.log` whose
  last line isn't `deploy complete` (regardless of age).
- Optional project-name filter — `fleet list` has no `--project` flag;
  filter the printed table's `PROJECT` column yourself.

## Step 1 — build the candidate table (nothing destroyed yet)

1. `fleet list` — keep rows where `STATE` is `deployed` (never touch a
   `running` row here; a running instance being "old" isn't itself a
   cleanup signal).
2. Estimate "stopped since" per candidate id without `stat` (not
   allow-listed) — use `find`'s own date math instead:

   ```bash
   find /srv/fleet/instances -maxdepth 1 -mindepth 1 -type d -not -newermt '-7 days'
   ```

   Any id this prints has been untouched on disk for more than 7 days —
   add it as a candidate with reason `stopped >7d`. (Adjust `-7 days` if a
   non-default age threshold was requested.)
3. Separately, for every id (regardless of the 7-day check), run `tail
   /srv/fleet/logs/<id>/deploy.log` and check the last line. If it isn't
   `deploy complete`, add/keep that id as a candidate with reason `deploy
   incomplete` — this overrides age, an incomplete deploy is a candidate
   immediately, even if it's only minutes old.
4. Build one numbered table, deduplicated by id (an id matching both
   reasons lists both).

## Output format (Step 1 — always shown, never skipped)

```
/fleet-cleanup candidates:

 #  INSTANCE ID              PROJECT    BRANCH        STATE      REASON
 1  <id>                     <project>  <branch>      deployed   stopped >7d
 2  <id>                     <project>  <branch>      deployed   deploy incomplete
...

Destroy these <N> instance(s)? (yes/no, or list the numbers to destroy a subset)
```

If the table is empty, say so and stop — there is nothing to confirm and
nothing further happens.

## Step 2 — wait for an explicit answer

- A bare `/fleet-cleanup` invocation, or prose like "what can we destroy" /
  "clean up the stale ones", **stops after Step 1's table and question**.
  Do not proceed on your own judgement, and do not treat a timeout/no-
  response as "yes" (see `35-wait-for-user-question-answers.md`).
- Only on an explicit "yes" (destroy the full table) or an explicit subset
  of the numbered rows, continue to Step 3 with exactly that id list.
- Any other answer ("no", or anything else) stops here — nothing is
  destroyed.

## Step 3 — destroy the confirmed subset

Detect native bulk destroy before deciding how to issue the calls (it does
not exist in the CLI as of this writing — belongs to a sibling
plan/spec — so this must be runtime-detected, not assumed):

```bash
fleet destroy --help
```

- If the output shows it accepts multiple positional ids and a `--yes`
  flag: issue **one** call, `fleet destroy <id1> <id2> ... --yes`.
  `--yes` is safe here **only** because Step 2 already got a real,
  explicit confirmation from the operator — it substitutes for the native
  command's own interactive typed-confirmation prompt, which would
  otherwise hit its non-TTY refusal path (Claude's Bash tool has no TTY)
  and abort with nothing destroyed. Never pass `--yes` without having just
  completed Step 2 for exactly this id set.
- Otherwise, loop `fleet destroy <id>` once per confirmed id. Each call is
  `ask`-tier in settings.json regardless of native support, so the
  harness will prompt for each one — that's expected and correct: it's a
  second, independent gate under this skill's own Step 2 confirmation, not
  a redundant annoyance to route around.

Report which ids were destroyed and which (if any) failed, with the exact
error for any failure.

## Safety notes

- `fleet destroy --all *` is categorically denied at the settings.json
  level (Task 1) — this skill must never construct that exact invocation,
  even accidentally; it always destroys an explicit, bounded id list built
  from Step 1/2, never a blanket `--all`.
- This skill is the **only** place in this fleet-host-Claude work that ever
  issues a bulk destroy — `/fleet-deploy-batch` never destroys anything.
