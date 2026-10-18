import { expect, test } from '@playwright/test';
import { collectBrowserErrors } from './support/shell';

test.describe('app shell @smoke', () => {
  test('home renders the shell without browser errors', async ({ page }, testInfo) => {
    const errors = collectBrowserErrors(page);

    await page.goto('/');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveTitle(/Fleet/);
    await expect(page.locator('main#main')).toBeVisible();
    // The role summary card is on every viewport; the top bar identity menu is desktop-only (K17).
    await expect(page.getByTestId('role-summary')).toContainText('Dev Admin');
    if (testInfo.project.name === 'desktop') {
      await expect(page.getByTestId('identity')).toContainText('Dev Admin');
    } else {
      await expect(page.getByTestId('identity')).toBeHidden();
    }

    expect(errors).toEqual([]);
  });
});
