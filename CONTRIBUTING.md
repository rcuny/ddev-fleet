# Contributing to ddev-fleet

Contributions are welcome. The full guide lives in [`docs/contributing/`](docs/contributing/README.md):

- [Development setup](docs/contributing/01-development.md): run the app, environment variables, style and pull-request rules.
- [Testing and gates](docs/contributing/02-testing.md): the checks a change must pass.
- [CI](docs/contributing/03-ci.md) and [releasing](docs/contributing/04-releasing.md).

Short version: cut `feature/<KEY>-<slug>` off `develop`, add a `changelog.d/<KEY>.md` fragment, run the gates, open the pull request against `develop`.
