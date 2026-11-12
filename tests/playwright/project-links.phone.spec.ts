import { expect, test } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';

// FLE-299 on a phone (390 px): the Links card of the Network tab and the host Isolation tab fit and have 44 px controls.

test.describe('instance isolation pages on a phone @phone', () => {
  test('the Links card and the Isolation tab @smoke', async ({ page }) => {
    test.setTimeout(120_000);
    const project = uniqueName('lnk');
    const other = uniqueName('oth');

    try {
      await createProjectViaUi(page, project);
      await createProjectViaUi(page, other);
      await page.goto(`/admin/projects/${project}/network`);
      const card = page.getByTestId('project-links');
      await expect(card).toBeVisible();
      expect(await hasHorizontalOverflow(page), 'overflow on the Links card').toBe(false);
      const create = card.getByRole('button', { name: 'Create link' });
      expect((await create.boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
      expect((await card.getByLabel('Port').boundingBox())!.height).toBeGreaterThanOrEqual(43.5);

      await page.goto('/admin/host?tab=isolation');
      await expect(page.getByTestId('host-tab-isolation')).toBeVisible();
      expect(await hasHorizontalOverflow(page), 'overflow on the Isolation tab').toBe(false);
      const save = page.getByRole('button', { name: 'Save ports' });
      expect((await save.boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
      expect((await page.getByLabel('Extra host ports').boundingBox())!.height).toBeGreaterThanOrEqual(43.5);
    } finally {
      await deleteProjectViaUi(page, project);
      await deleteProjectViaUi(page, other);
    }
  });
});
