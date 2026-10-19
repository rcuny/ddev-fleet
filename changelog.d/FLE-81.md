### Fixed

- **FLE-81: M0.04 follow-ups.** Authelia: a project's alias prefix no longer swallows another project's own hosts (project `blog` with the hostname `x` no longer matches the instances of project `x-blog`; the colliding alias is left out of the rule). Mercure: instance changes go to the per-project topic `/instances/<project>` and the page's cookie authorises only the projects the user may operate, so a member no longer receives events about other projects' instances. The instance page's job link is underlined (axe `link-in-text-block`).

### Changed

- **FLE-81:** Playwright: axe checks in both themes (shared `expectNoBlockingViolations` helper) on the jobs specs, the phone instance specs and the phone navigation pages.
