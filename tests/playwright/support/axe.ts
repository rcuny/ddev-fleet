import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

const BLOCKING = new Set(['serious', 'critical']);

/** WCAG 2 A/AA violations of serious or critical impact on the current page, one readable string each. */
export async function blockingViolations(page: Page): Promise<string[]> {
  const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  return violations
    .filter((violation) => violation.impact && BLOCKING.has(violation.impact))
    .map((violation) => {
      const targets = violation.nodes.map((node) => `${node.target.join(' ')} -> ${node.failureSummary?.split('\n').slice(1).join(' ')}`);
      return `${violation.impact} ${violation.id}: ${violation.help}\n    ${targets.join('\n    ')}`;
    });
}

/**
 * axe in light, then in dark. The pointer is parked away from any button (a hovered default Button blends to bg-primary/80,
 * which fails contrast). The dark class is flipped in place (a reload would repeat a POST page and lose what the test typed)
 * with transitions switched off, so axe reads the final colours and not a mid-transition blend on a slow machine.
 */
export async function expectNoBlockingViolations(page: Page): Promise<void> {
  await page.mouse.move(0, 0);
  expect(await blockingViolations(page)).toEqual([]);
  const freeze = await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; animation: none !important; }' });
  try {
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    // Two frames: the style recalculation with the dark tokens has run before axe measures.
    await page.evaluate(() => new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done()))));
    expect(await blockingViolations(page)).toEqual([]);
  } finally {
    await page.evaluate(() => document.documentElement.classList.remove('dark'));
    await freeze.evaluate((node) => (node as Element).remove());
  }
}
