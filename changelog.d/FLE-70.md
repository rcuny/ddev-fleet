### Security

- **FLE-70: Secrets used by a deploy are masked in the job log.** The Claude token written to the instance configuration and every secret `TokenRenderer` inserts are registered with the job log masker, so they never reach the job log, the live tail or a job's error text. Only `[masked]` is written.
- **FLE-70: Secret reveals are throttled.** A user may reveal 10 values per minute. A refused reveal answers 429 with `Retry-After` and is audited as `secret.reveal-refused`.

### Changed

- **FLE-70: A truncated audit field ends in an ellipsis.** Actor, action and subject are still cut on a character boundary, now with `…` so the cut shows.
