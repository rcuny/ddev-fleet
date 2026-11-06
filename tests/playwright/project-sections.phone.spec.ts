import { expect, test } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, openSection, uniqueName } from './support/projects';
import { blockingViolations } from './support/axe';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// Phone only (390 px): the ten project tabs are one labelled select (FLE-226). The desktop strip is project-sections.spec.ts.

test.describe('project sections on a phone @phone @smoke', () => {
  test('the phone shows one labelled select instead of the strip ', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();

    try {
      await createProjectViaUi(page, name, undefined, 'overview');
      const nav = page.getByRole('navigation', { name: 'Project sections' });
      const select = nav.getByRole('combobox', { name: 'Project section' });
      await expect(select).toBeVisible();
      await expect(nav.getByRole('link')).toHaveCount(0);
      await expect(select.locator('option')).toHaveCount(10);
      expect((await select.boundingBox())!.height).toBeGreaterThanOrEqual(44);

      await openSection(page, 'Network');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/network$`));
      await expect(select).toHaveValue(new RegExp(`/admin/projects/${name}/network$`));
      await openSection(page, 'Keys & secrets');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/keys$`));
      expect(await blockingViolations(page)).toEqual([]);
      expect(await hasHorizontalOverflow(page)).toBe(false);
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});
