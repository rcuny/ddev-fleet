### Changed

- **FLE-85: webhooks cleanup.** `RuleMatcher::label` now delegates to the new `Labels::slugOrNull`, so there is one slug
  implementation. `docs/webhooks.md` states that two Jira deliveries with different bodies give `fle-3` then `fle-3-1`, and
  that a failed or refused action consumes the delivery id and body hash (the sender's retry is a `duplicate`).

### Fixed

- **FLE-85: a fixed-label webhook redeploy follows the rule's branch.** When the rule's branch differs from the
  instance's, the redeploy job carries it (optional `branch` param, so queued jobs still run) and the instance switches.
