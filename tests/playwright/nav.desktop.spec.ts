import { expect, test } from '@playwright/test';
import { setStandInRole } from './support/standIn';
import { ADMIN_LABELS } from './support/shell';

test.describe('desktop navigation', () => {
  const sidebarLinks = (page: import('@playwright/test').Page) =>
    page.getByRole('navigation', { name: 'Main' }).getByRole('link');

  test('admin sidebar lists the ten destinations', async ({ page }) => {
    await page.goto('/');

    await expect(sidebarLinks(page)).toHaveText(ADMIN_LABELS);
    await expect(page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Home' })).toHaveAttribute(
      'aria-current',
      'page',
    );
  });

  test('switching to member narrows the menu and blocks admin pages; the cookie restores admin', async ({
    page,
    context,
    baseURL,
  }) => {
    await page.goto('/');

    await page.locator('[data-testid=stand-in-switch]:visible').getByRole('link', { name: 'Member' }).click();
    await expect(page).toHaveURL(/\/$/);
    await expect(sidebarLinks(page)).toHaveText(['Home', 'Instances']);

    const response = await page.goto('/admin/projects');
    expect(response?.status()).toBe(403);
    await expect(page.getByText('You do not have access to this page')).toBeVisible();

    await setStandInRole(context, 'admin', baseURL!);
    await page.goto('/');
    await expect(sidebarLinks(page)).toHaveText(ADMIN_LABELS);
  });
});
