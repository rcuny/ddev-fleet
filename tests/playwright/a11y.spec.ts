import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const PAGES = ['/', '/admin/design-system'];
const BLOCKING = new Set(['serious', 'critical']);

async function blockingViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  return violations
    .filter((violation) => violation.impact && BLOCKING.has(violation.impact))
    .map((violation) => {
      const targets = violation.nodes.map((node) => `${node.target.join(' ')} -> ${node.failureSummary?.split('\n').slice(1).join(' ')}`);
      return `${violation.impact} ${violation.id}: ${violation.help}\n    ${targets.join('\n    ')}`;
    });
}

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
