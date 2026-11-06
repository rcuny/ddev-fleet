# Changelog fragments

One file per change, so two branches never edit the same lines of `CHANGELOG.md`.

- Name: `<KEY>.md` (for example `FLE-123.md`), or `<KEY>-<slug>.md` when a ticket needs several.
- Content: one or more `### <Section>` headings (`Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`,
  `Security`, `Dependencies`) with the Keep a Changelog bullets under them. Start a headline bullet with the
  ticket key in bold (`- **FLE-123: ...**`).
- Do not edit the `## [Unreleased]` section of `CHANGELOG.md`; the release step builds it from these files:
  `php ci/changelog-assemble.php` previews the result, `php ci/changelog-assemble.php X.Y.Z` cuts the release
  (see `docs/contributing/releasing.md`).

## Rollout notes (operator actions)

A change that needs the operator to do something, or to know something, when the server is upgraded adds a
`## Rollout` heading at the end of its fragment, followed by bullets:

```markdown
### Changed
- **FLE-123: the worker now reads FLEET_FOO**

## Rollout
- ACTION: add FLEET_FOO=... to /etc/ddev-fleet/fleet.env before upgrading.
- The first request after the upgrade rebuilds the cache and is slower.
```

- The release step merges every fragment's Rollout bullets into one `#### Rollout` subsection at the end of the
  version section of `CHANGELOG.md`.
- A bullet that starts with `ACTION:` needs operator action. `fleet upgrade --check` and the Upgrade page show it as
  a warning, and the upgrade repeats it before asking you to confirm. Other bullets are information.
- Every line under the heading must be a bullet (`- text`); anything else is refused by `ci/changelog-assemble.php`.
