import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { acquire, clearFlag, flag, fleetHome, lock, packages } from './support/rebootFlag';
import { hasHorizontalOverflow } from './support/shell';

// The banner's logic (who sees it, the package list, escaping, the flag without a list) is covered by
// app/tests/Functional/Host/RebootBannerTest.php; this spec keeps the layout check, which needs a real viewport.
test.describe('reboot banner', () => {
  test.beforeEach(async () => {
    await acquire();
    clearFlag();
  });
  test.afterEach(() => {
    clearFlag();
    rmSync(lock, { recursive: true, force: true });
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
});
