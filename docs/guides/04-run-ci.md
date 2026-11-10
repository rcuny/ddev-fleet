---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Run CI per branch

Fleet can give every branch its own CI instance with a CPU ceiling, so test runs stay predictable and do not starve the host. Be clear on the split: Fleet supplies the instance and the limits; your own scripts run the suite.

## The pieces

| Piece | What it does |
|---|---|
| CI template | A template whose `tty1` line starts your runner once the deploy is done. |
| Allotment and slots | A CPU ceiling for the project or template, and a cap on concurrent heavy runs. |
| Named port | An extra public port on the instance, for a Playwright report or Typesense. |
| Pipeline trigger | A webhook rule that starts a Bitbucket pipeline. |

## Steps

1. **Create a CI template.** The `tty1` line is typed into the instance's terminal after the deploy:

   ```bash
   fleet template:create website ci --post-deploy='composer install' \
     --tty1='bash .ci/run-suite.sh' --cpu-threads=4 --slots=2
   ```

2. **Keep runs predictable.** `--cpu-threads` is a ceiling shared by all instances of the template; `--slots` is how many heavy runs may go at once. Fleet gives your script `FLEET_SLOT_DIR`; the script takes a slot by holding an `flock` on a file in it for the length of its run. Model and numbers: [capacity](../reference/10-capacity.md).

3. **Expose reports.** Add a named port and assign it, then open it in the firewall:

   ```bash
   fleet port:create playwright --public=9325 --router=8323
   fleet project:update website --port=playwright
   fleet port:firewall
   ```

4. **Trigger it from Bitbucket.** Create a repository token with scope **Pipelines: Write** only, store it with `fleet webhook:token website`, and add a `run-pipeline` rule (`fleet webhook:rule:add`; options in [cli](../reference/01-cli.md)). Alternatively reuse the push rule from [automate deploys](03-automate-deploys.md) so each pushed branch gets a CI instance.

Success: pushing `feature/login` creates the instance, the runner starts in `tty1` (watch it with `sudo fleet tmux`), and the report opens at `https://website--feature-login.fleet.example.com:9325`.

## If something goes wrong

| Symptom | Fix |
|---|---|
| The runner never starts | The `tty1` line had an unresolved `[[token]]`; the job log has a `WARNING`. |
| Port not reachable | Open it in the firewall (`fleet port:firewall`). |
| Runs slow each other down | Lower `--slots`, or the allotment, on the template. |

More: [troubleshooting](../reference/13-troubleshooting.md).

## Where to go next

- [Capacity reference](../reference/10-capacity.md)
- [Networking reference](../reference/09-networking.md): named-port sites and access
- [Operate](07-operate.md)
