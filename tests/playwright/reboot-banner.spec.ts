import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { baseURL } from './support/env';
import { acquire, clearFlag, flag, fleetHome, lock, packages } from './support/rebootFlag';
import { hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

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

  test('an administrator sees the banner with the packages, without overflow @phone', async ({ page }) => {
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
  });

  test('the banner shows on other shell pages and without a package list @phone', async ({ page }) => {
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
