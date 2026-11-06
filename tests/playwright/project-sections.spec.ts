import { expect, test } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, openSection, uniqueName } from './support/projects';
import { blockingViolations } from './support/axe';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// The ten project tabs (FLE-226): the strip on desktop, the select on a phone, the old addresses, the Overview.
// The page rules are PHPUnit: ProjectSectionsTest and ProjectPagesTest::testPagesHaveOneH1AndTheTabNavigation.

const SECTIONS: [string, string, string][] = [
  ['Overview', '', 'project-overview'],
  ['Source', '/source', 'project-form'],
  ['Templates', '/templates', ''],
  ['Assets', '/assets', ''],
  ['Network', '/network', 'ports-form'],
  ['Access', '/access', 'project-section-access'],
  ['Capacity', '/capacity', 'project-section-capacity'],
  ['Keys & secrets', '/keys', 'secrets-list'],
  ['Integrations', '/integrations', 'webhook-endpoints'],
  ['Transfer & danger', '/transfer', 'project-section-transfer'],
];

test.describe('project sections', () => {
  test('ten tabs, aria-current, the lock, the old addresses and axe on every tab', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();

    try {
      await createProjectViaUi(page, name, undefined, 'overview');
      // A new project lands on the Overview.
      await expect(page.getByTestId('project-overview')).toBeVisible();
      await expect(page.getByTestId('overview-git')).toBeVisible();
      for (const count of ['instances', 'templates', 'ports', 'secrets']) {
        await expect(page.getByTestId(`count-${count}`)).toHaveText('0');
      }

      const nav = page.getByRole('navigation', { name: 'Project sections' });
      await expect(nav.getByRole('link')).toHaveCount(10);
      const lock = nav.getByRole('link', { name: /Keys & secrets/ });
      await expect(lock).toContainText('(restricted)');

      for (const [label, suffix, testId] of SECTIONS) {
        await openSection(page, label as Parameters<typeof openSection>[1]);
        await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}${suffix}$`));
        await expect(nav.getByRole('link', { name: label })).toHaveAttribute('aria-current', 'page');
        await expect(nav.locator('a[aria-current="page"]')).toHaveCount(1);
        if (testId) {
          await expect(page.getByTestId(testId).first()).toBeVisible();
        }
        await page.waitForLoadState('networkidle');
        expect(await blockingViolations(page), label).toEqual([]);
        expect(await hasHorizontalOverflow(page), `${label} overflows`).toBe(false);
      }

      // The old addresses still resolve.
      for (const [old, now] of [['ports', 'network'], ['secrets', 'keys'], ['webhooks', 'integrations']]) {
        await page.goto(`/admin/projects/${name}/${old}`);
        await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/${now}$`));
      }
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});
