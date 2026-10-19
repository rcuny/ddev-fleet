### Fixed

- **FLE-83: Playwright schedules spec no longer flakes on a slow machine.** The dark-theme axe check now runs through a shared `expectNoBlockingViolations` helper that switches transitions off before measuring, and the empty-state test no longer depends on test order.
