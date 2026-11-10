import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { fleetHome, runConsole } from './support/fleetData';
import { hasHorizontalOverflow } from './support/shell';

// A fake-behind host: the dev app reads its installed version from FLEET_HOME/test-installed-version, and the release
// catalog is filled by `fleet:upgrade:refresh` against a throw-away local git repository (FLEET_UPGRADE_REPO_URL) that
// has an annotated v1.99.0 tag. State is shared by the desktop and phone projects, which run at the same time, so a
// directory is the lock (mkdir is atomic; a lock left by a crash is taken over after a minute).
const home = fleetHome();
const versionFile = join(home, 'test-installed-version');
const lock = `${versionFile}.lock`;
let repo = '';
let bare = '';

async function acquire(): Promise<void> {
  mkdirSync(home, { recursive: true });
  for (;;) {
    try {
      mkdirSync(lock);
      return;
    } catch {
      try {
        if (Date.now() - statSync(lock).mtimeMs > 60_000) {
          rmSync(lock, { recursive: true, force: true });
        }
      } catch {
        // released between the two calls: try again
      }
      await new Promise((done) => setTimeout(done, 100));
    }
  }
}

function git(args: string[], cwd: string): void {
  execFileSync('git', args, {
    cwd,
    stdio: 'ignore',
    env: { ...process.env, GIT_AUTHOR_NAME: 'T', GIT_AUTHOR_EMAIL: 't@example.com', GIT_COMMITTER_NAME: 'T', GIT_COMMITTER_EMAIL: 't@example.com' },
  });
}

test.describe('upgrade notice and page', () => {
  test.beforeEach(async () => {
    await acquire();
    repo = mkdtempSync(join(tmpdir(), 'fleet-upgrade-'));
    git(['init', '-q', '-b', 'main'], repo);
    writeFileSync(join(repo, 'CHANGELOG.md'), '# Changelog\n\n## [1.99.0] - 2026-11-03\n\n### Added\n- A new thing.\n');
    git(['add', '.'], repo);
    git(['commit', '-q', '-m', 'release'], repo);
    git(['tag', '-a', 'v1.99.0', '-m', 'v1.99.0'], repo);
    // The changelog is read with `git --git-dir=<repo> show`, which wants a bare repository.
    bare = join(repo, 'bare.git');
    git(['clone', '-q', '--bare', repo, bare], repo);
    writeFileSync(versionFile, 'v1.98.0\n');
    const refreshed = runConsole(['fleet:upgrade:refresh'], { FLEET_RUNNER: 'process', FLEET_UPGRADE_REPO_URL: bare });
    expect(refreshed.status, refreshed.text).toBe(0);
  });

  test.afterEach(() => {
    rmSync(versionFile, { force: true });
    runConsole(['cache:pool:delete', 'cache.app', 'fleet.upgrade.catalog', 'fleet.upgrade.changelog']);
    rmSync(repo, { recursive: true, force: true });
    rmSync(lock, { recursive: true, force: true });
  });

  test('a host one release behind shows the badge, and the page fits the viewport @phone', async ({ page }) => {
    await page.goto('/admin/host/upgrade');
    await page.waitForLoadState('networkidle');

    await expect(page.getByRole('heading', { level: 1, name: 'Upgrade' })).toBeVisible();
    await expect(page.getByTestId('upgrade-installed')).toHaveText('v1.98.0');
    await expect(page.getByTestId('upgrade-latest')).toHaveText('v1.99.0');
    await expect(page.getByTestId('upgrade-engine')).toHaveText('bash');
    await expect(page.getByTestId('upgrade-cmd-to')).toHaveValue('sudo fleet upgrade --to v1.99.0');
    await expect(page.getByTestId('upgrade-section')).toContainText('A new thing');
    expect(await hasHorizontalOverflow(page)).toBe(false);

    // The badge sits in the sidebar (desktop) or the More sheet (phone); the page above is reachable from it.
    const phone = (page.viewportSize()?.width ?? 1280) < 768;
    if (phone) {
      await page.getByRole('button', { name: 'More' }).click();
    }
    const badge = page.getByTestId('upgrade-notice-badge').filter({ visible: true });
    await expect(badge).toHaveText('v1.99.0 available');
    await expect(badge).toHaveAttribute('href', '/admin/host/upgrade');
    const box = await badge.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.height).toBeGreaterThanOrEqual(phone ? 44 : 24);
  });
});
