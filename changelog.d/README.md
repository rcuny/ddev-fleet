# Changelog fragments

One file per change, so two branches never edit the same lines of `CHANGELOG.md`.

- Name: `<KEY>.md` (for example `FLE-123.md`), or `<KEY>-<slug>.md` when a ticket needs several.
- Content: one or more `### <Section>` headings (`Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`,
  `Security`, `Dependencies`) with the Keep a Changelog bullets under them. Start a headline bullet with the
  ticket key in bold (`- **FLE-123: ...**`).
- Do not edit the `## [Unreleased]` section of `CHANGELOG.md`; the release step builds it from these files:
  `php ci/changelog-assemble.php` previews the result, `php ci/changelog-assemble.php X.Y.Z` cuts the release
  (see `docs/RELEASING.md`).
