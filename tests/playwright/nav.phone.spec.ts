import { expect, test, type Page } from '@playwright/test';
import { setStandInRole } from './support/standIn';
import { ADMIN_NAV, hasHorizontalOverflow } from './support/shell';

const bottomNav = (page: Page) => page.locator('[data-bottom-nav]');
const bottomItems = (page: Page) => bottomNav(page).locator('a, button');
const moreSheet = (page: Page) => page.locator('#more-sheet');

test.describe('phone navigation (390 px)', () => {
  for (const { label, path } of ADMIN_NAV) {
    test(`no horizontal overflow on ${path} (${label})`, async ({ page }) => {
      await page.goto(path);
      await page.waitForLoadState('networkidle');

      expect(await hasHorizontalOverflow(page)).toBe(false);
    });
  }

  test('no horizontal overflow on the 403 page', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'member', baseURL!);
    await page.goto('/admin/projects');

    await expect(page.getByText('You do not have access to this page')).toBeVisible();
    expect(await hasHorizontalOverflow(page)).toBe(false);
  });

  test('bottom bar sits in the lower third, inside the viewport, with 44 px tap targets', async ({ page }) => {
    await page.goto('/');

    const viewport = page.viewportSize()!;
    const bar = await bottomNav(page).boundingBox();
    expect(bar).not.toBeNull();
    expect(bar!.y).toBeGreaterThanOrEqual(0.66 * viewport.height);
    expect(bar!.y + bar!.height).toBeLessThanOrEqual(viewport.height + 0.5);
    expect(bar!.x).toBeGreaterThanOrEqual(0);
    expect(bar!.x + bar!.width).toBeLessThanOrEqual(viewport.width + 0.5);

    const items = bottomItems(page);
    await expect(items).toHaveCount(4);
    for (const item of await items.all()) {
      const box = await item.boundingBox();
      expect(box, await item.innerText()).not.toBeNull();
      expect(box!.width).toBeGreaterThanOrEqual(44);
      expect(box!.height).toBeGreaterThanOrEqual(44);
    }
  });

  test('More opens the sheet and reaches the design system in two taps', async ({ page }) => {
    await page.goto('/');

    await bottomNav(page).getByRole('button', { name: 'More' }).click();
    await expect(moreSheet(page)).toBeVisible();
    await expect(page.locator('#sheet-more')).toBeVisible();

    await moreSheet(page).getByRole('link', { name: 'Design system' }).click();
    await expect(page).toHaveURL(/\/admin\/design-system$/);
  });

  test('a member sees three bottom items', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'member', baseURL!);
    await page.goto('/');

    await expect(bottomItems(page)).toHaveText([/Home/, /Instances/, /More/]);
  });

  test('first Tab focuses the skip link', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');

    const focused = page.locator(':focus');
    await expect(focused).toHaveText('Skip to content');
    await expect(focused).toHaveAttribute('href', '#main');
  });

  test('rotating to landscape with the More sheet open leaves the page usable', async ({ page }) => {
    await page.goto('/');
    await bottomNav(page).getByRole('button', { name: 'More' }).click();
    await expect(moreSheet(page)).toBeVisible();
    // Open before the rotation: otherwise the hidden checks below would be vacuous.
    await expect(page.locator('#sheet-more')).toBeVisible();

    await page.setViewportSize({ width: 844, height: 390 });
    await expect(page.locator('#sheet-more')).toBeHidden();

    // At md and up the sidebar replaces the bottom bar; the modal sheet must not keep the page inert.
    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Instances' }).click();
    await expect(page).toHaveURL(/\/instances$/);
    await expect(page.locator('#sheet-more')).toBeHidden();
  });
});
