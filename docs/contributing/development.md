---
Author: Claude Code
Reviewer: none
Last updated: 2026-11-06
Type: documentation
---

# Developing Fleet

This page gets Fleet running on your machine and tells you the rules a pull request must follow. Fleet is a Symfony 8.x app in `app/` (PHP 8.4, SQLite, Twig + Symfony UX, Tailwind v4 via AssetMapper, no JavaScript build). Conventions that apply to every change are in the repository's `CLAUDE.md`.

## Prerequisites and first run

| Tool | Version | Used for |
|---|---|---|
| PHP | 8.4 with `pdo_sqlite`, `sodium` | the app, PHPUnit, PHPStan (`php8.4` if your default is older) |
| Composer | 2.x | dependencies and the `gates` script |
| Node.js | 22 | Playwright and TypeScript only |
| Chromium | via Playwright | `npx playwright install chromium` (`--with-deps` as root) |

```bash
cd app
composer install
php bin/console importmap:install && php bin/console tailwind:build   # first run needs network
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console messenger:setup-transports
php -S 127.0.0.1:8000 -t public                                       # then: curl /health
```

Defaults come from `app/.env` and `app/.env.dev`; override them in `app/.env.local` (git-ignored).

| Variable | Default | Meaning |
|---|---|---|
| `APP_ENV` | `dev` | `prod` in production |
| `FLEET_HOME` | `app/var/fleet-home` | Absolute directory for everything the app persists |
| `FLEET_RUNNER` | `recording` in dev/test | `recording` runs no host command (scripted results); `process` runs them. A `prod` kernel refuses `recording` |
| `FLEET_STAND_IN_ROLE` | `admin` in dev/test, `none` otherwise | Default dev role: `admin`, `member`, `none` |
| `FLEET_TRUSTED_PROXIES` | loopback | IPs allowed to assert `Remote-*` identity headers; the installer sets it empty |
| `FLEET_PROXY_SECRET` | empty | Shared secret between Caddy and the app (32+ characters) |
| `FLEET_E2E_BASE_URL` | unset | Playwright: target a running server |
| `FLEET_PHP` | `php8.4` or `php` | PHP binary of Playwright's self-started app |
| `FLEET_E2E_PORT` | `8000` | Port of that app |

PHPUnit forces `APP_ENV=test`, a temporary `FLEET_HOME` and the recording runner, so no test touches real state or runs a host command.

## Signing in during development

There is no proxy in dev and test, so a stand-in authenticator signs you in; it does not exist in `prod`. Pick the role with the `X-Fleet-Stand-In-Role` header (what browser tests use), the cookie set by visiting `/_dev/stand-in/admin|member|none`, or `FLEET_STAND_IN_ROLE`.

In production the app trusts `Remote-User` and `Remote-Groups` only from a request that proves it came through the local Caddy: Caddy passes `FLEET_PROXY_SECRET` as a FastCGI parameter a client cannot set and strips every inbound `Remote-*` header. See [secure the host](../guides/secure-the-host.md).

## Code style

- PHP-CS-Fixer (`@Symfony`, `declare(strict_types=1)`) and PHPStan level 8 are gates.
- Host commands go through `CommandRunner`, paths through `FleetPaths`. `ddev`, `git` and `docker` never run inside an HTTP request; they run in queued jobs.
- The UI follows the design system at `/admin/design-system`: use its tokens and components (`ux:install <recipe> --kit=shadcn` adds one) and make every page work at 390 px.

## Pull requests

- Branch `feature/<KEY>-<slug>` off `develop` (no `__` or `--` in the name); open the PR against `develop`.
- Add `changelog.d/<KEY>.md` in the same branch, not a line in `CHANGELOG.md` ([releasing.md](releasing.md)).
- Gates green ([testing.md](testing.md)). Never merge a red suite.
- The repository is public: no real hostnames or client names; `RepoHygieneTest` enforces it.
- Docs tests pin guide names, command names and options, so changing a command means changing its doc.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `tailwind:build` or `importmap:install` fails | Both download on first use; run once with network. |
| Page renders without styles | Rerun `composer run assets`. |
| Playwright cannot start a browser | `npx playwright install chromium` (`--with-deps` as root). |
| `Address already in use` | Another server holds `FLEET_E2E_PORT`; stop it or change the port. |

## Learn more

- [testing.md](testing.md): the gates and suites
- [ci.md](ci.md): what CI runs on your push
- `CLAUDE.md` in the repository (the architecture notes for contributors and agents)
