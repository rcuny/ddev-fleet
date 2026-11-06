---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Contributing to Fleet

You want to fix a bug or add a feature without breaking the build. These four pages cover setup, tests, CI and releases, and none of them is needed to use Fleet.

| Page | Read it to |
|---|---|
| [development.md](development.md) | Set up locally, sign in, follow the code style, open a pull request |
| [testing.md](testing.md) | Run the gates and know which suite to extend |
| [ci.md](ci.md) | Understand `fleet-gates`, the tag guard, the systemd check and Renovate |
| [releasing.md](releasing.md) | Write changelog fragments, cut a release or a hotfix |

The rule that matters most: **run the gates and get them green before you open a pull request**, and add a `changelog.d/<KEY>.md` fragment in the same branch. The repository is public, so use no real hostnames or client names (`fleet.example.com`, `demo`, `blog` instead).

Start with [development.md](development.md).
