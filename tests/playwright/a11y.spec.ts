import { expect, test } from '@playwright/test';
import { blockingViolations } from './support/axe';

const PAGES = ['/', '/admin/design-system', '/admin/projects', '/admin/projects/new'];

for (const scheme of ['light', 'dark'] as const) {
  test.describe(`axe, ${scheme} theme`, () => {
    test.use({ colorScheme: scheme });

    for (const path of PAGES) {
      test(`no serious or critical violations on ${path}`, async ({ page }) => {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        if (scheme === 'dark') {
          await expect(page.locator('html')).toHaveClass(/\bdark\b/);
        }

        expect(await blockingViolations(page)).toEqual([]);
      });
    }
  });
}
