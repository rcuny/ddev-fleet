import { mkdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { blockingViolations } from './support/axe';
import { baseURL, externalBaseURL } from './support/env';
import { hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// The dev and test environments read the "reboot required" flag from FLEET_HOME/test-reboot-required (not /run), so
// this spec can switch it: the app's own FLEET_HOME when Playwright started it (support/start-app.mjs; the environment's
// FLEET_HOME is not that one), otherwise the FLEET_HOME of the environment (the companion's served dev app shares it).
const here = dirname(fileURLToPath(import.meta.url));
const fleetHome = (externalBaseURL && process.env.FLEET_HOME) || resolve(here, '.run/fleet-home');
const flag = join(fleetHome, 'test-reboot-required');
const packages = `${flag}.pkgs`;

function clearFlag(): void {
  rmSync(flag, { force: true });
  rmSync(packages, { force: true });
}

// The flag is one file shared by every worker, and the desktop and phone projects run at the same time: a directory
// is the lock (mkdir is atomic). A lock left by a crashed run is taken over after a minute.
const lock = `${flag}.lock`;

async function acquire(): Promise<void> {
  mkdirSync(fleetHome, { recursive: true });
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

test.describe('reboot banner', () => {
  test.beforeEach(async () => {
    await acquire();
    clearFlag();
  });
  test.afterEach(() => {
    clearFlag();
    rmSync(lock, { recursive: true, force: true });
  });

  test('no banner while no reboot is pending', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('h1')).toBeVisible();
    await expect(page.getByTestId('reboot-banner')).toHaveCount(0);
  });

  test('an administrator sees the banner with the packages, without overflow or axe violations', async ({ page }) => {
    mkdirSync(fleetHome, { recursive: true });
    writeFileSync(flag, '*** System restart required ***\n');
    writeFileSync(packages, 'linux-image-6.12.0-amd64\nlibc6\n');

    await page.goto('/');
    await page.waitForLoadState('networkidle');

    const banner = page.getByTestId('reboot-banner');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('This host needs a reboot');
    await expect(banner).toContainText('never reboots the host by itself');
    await expect(page.getByTestId('reboot-packages').locator('li')).toHaveText(['linux-image-6.12.0-amd64', 'libc6']);

    const box = await banner.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(page.viewportSize()!.width);
    expect(await hasHorizontalOverflow(page)).toBe(false);
    expect(await blockingViolations(page)).toEqual([]);
  });

  test('the banner shows on other shell pages and without a package list', async ({ page }) => {
    mkdirSync(fleetHome, { recursive: true });
    writeFileSync(flag, '');

    await page.goto('/admin/host');
    await page.waitForLoadState('networkidle');

    await expect(page.getByTestId('reboot-banner')).toBeVisible();
    await expect(page.getByTestId('reboot-packages')).toHaveCount(0);
    expect(await hasHorizontalOverflow(page)).toBe(false);
  });

  test('a member does not see the banner', async ({ page, context }) => {
    mkdirSync(fleetHome, { recursive: true });
    writeFileSync(flag, '');
    await setStandInRole(context, 'member', baseURL);

    await page.goto('/');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('h1')).toBeVisible();
    await expect(page.getByTestId('reboot-banner')).toHaveCount(0);
  });
});
