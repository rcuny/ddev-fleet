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
 * No blocking violation on the current page in the light and the dark theme. The pointer is parked first: a hovered
 * default Button blends to bg-primary/80, which fails axe contrast. The theme class is flipped in place, a reload
 * would repeat a POST page and lose what the test typed. Transitions are switched off for the check, so axe reads the
 * final colours and not a mid-transition blend on a slow machine.
 */
export async function expectNoBlockingViolations(page: Page, where = 'axe'): Promise<void> {
  await page.mouse.move(0, 0);
  expect(await blockingViolations(page), `${where}, light theme`).toEqual([]);
  const wasDark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
  const freeze = await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; animation: none !important; }' });
  try {
    await page.evaluate((dark) => document.documentElement.classList.toggle('dark', !dark), wasDark);
    // Two frames: the style recalculation with the flipped tokens has run before axe measures.
    await page.evaluate(() => new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done()))));
    expect(await blockingViolations(page), `${where}, ${wasDark ? 'light' : 'dark'} theme`).toEqual([]);
  } finally {
    await page.evaluate((dark) => document.documentElement.classList.toggle('dark', dark), wasDark);
    await freeze.evaluate((node) => (node as Element).remove());
  }
}
