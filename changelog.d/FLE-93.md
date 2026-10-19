### Fixed

- **FLE-93: the job log no longer fails the axe `aria-prohibited-attr` check.** The log `<pre>` carried an `aria-label` without a role that allows a name. It now has `role="log"` and `aria-live="off"` (the live tail appends often, so screen readers are not flooded; the status line above it still announces). No other `aria-label` in `app/templates` sits on an element without a permitting role.
