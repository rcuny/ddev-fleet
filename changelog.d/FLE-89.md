### Fixed

- **FLE-89: a password reset no longer looks broken when Authelia has banned the user.** Authelia's regulation
  (`max_retries`, `find_time`, `ban_time`) is now rendered explicitly (3 failures in 2 minutes, 5 minute ban) and
  documented in `docs/installation.md` (Troubleshooting). `fleet user:reset-password` and the Users page "set a new
  password" now say that Authelia may keep blocking the user for up to 5 minutes and that retrying extends the ban.
