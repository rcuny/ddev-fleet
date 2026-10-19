import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';

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
