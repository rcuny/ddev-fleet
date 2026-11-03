import { expect, test } from '@playwright/test';
import { hasHorizontalOverflow } from './support/shell';

const ANCHORS = [
  'colors', 'typography',
  'shells', 'main-navigation', 'sub-navigation', 'page-headers', 'body-content', 'footers',
  'iconography', 'buttons', 'forms', 'badges', 'listings', 'modal', 'dropdown-menu', 'callout',
  'heading-scale', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'anchor', 'paragraph', 'strong', 'lists', 'list-item',
  'blockquote', 'label', 'hr',
];

test.describe('design system page @smoke', () => {
  test('loads with every anchor and the fifteen colour tokens', async ({ page }) => {
    await page.goto('/admin/design-system');

    await expect(page.getByRole('heading', { level: 1, name: 'Design system' })).toBeVisible();
    expect(ANCHORS).toHaveLength(31);
    for (const id of ANCHORS) {
      await expect(page.locator(`#${id}`), `#${id}`).toHaveCount(1);
    }

    const tokens = page.locator('[data-token]');
    await expect(tokens).toHaveCount(15);
    for (const token of await tokens.all()) {
      await token.scrollIntoViewIfNeeded();
      await expect(token).toBeVisible();
    }
  });

  test('section navigation is reachable and updates the URL hash', async ({ page }, testInfo) => {
    await page.goto('/admin/design-system');

    if (testInfo.project.name === 'phone') {
      await page.getByRole('button', { name: 'Sections' }).click();
      await page.getByRole('navigation', { name: 'Design system sections (menu)' }).getByRole('link', { name: 'Buttons', exact: true }).click();
    } else {
      await page.getByRole('navigation', { name: 'Design system sections', exact: true }).getByRole('link', { name: 'Buttons', exact: true }).click();
    }

    await expect(page).toHaveURL(/#buttons$/);
    await expect(page.locator('#buttons')).toBeInViewport();
    expect(await hasHorizontalOverflow(page)).toBe(false);
  });

  test('the shared select is 44 px tall on the phone and stays compact on desktop', async ({ page }, testInfo) => {
    await page.goto('/admin/design-system');

    const select = page.locator('#ds-form-country');
    await select.scrollIntoViewIfNeeded();
    const box = (await select.boundingBox())!;
    if (testInfo.project.name === 'phone') {
      expect(box.height).toBeGreaterThanOrEqual(43.5);
    } else {
      expect(box.height).toBeLessThan(40);
    }
  });
});
