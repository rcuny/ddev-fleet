import { expect, test } from '@playwright/test';
import { blockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, openDialog, submitAndWait, uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';
import { createUserViaUi, deleteUserViaUi } from './support/users';

// Phone only (390 px): the Access tab fits, its controls are 44 px tall and the remove dialog is usable (FLE-230).

test.describe('project Access tab on a phone @phone', () => {
  test('no overflow, 44 px controls, axe, the remove dialog', async ({ page }) => {
    test.setTimeout(180_000);
    const project = uniqueName('prj');
    const username = uniqueName('usr');

    try {
      await createProjectViaUi(page, project, undefined, 'overview');
      await createUserViaUi(page, { username });
      await page.goto(`/admin/projects/${project}/access`);

      const select = page.getByTestId('member-select');
      expect((await select.boundingBox())!.height).toBeGreaterThanOrEqual(44);
      expect((await page.getByTestId('member-add').boundingBox())!.height).toBeGreaterThanOrEqual(44);
      await select.selectOption(username);
      await submitAndWait(page, page.getByTestId('member-add'));

      const remove = page.getByTestId('member-remove');
      expect((await remove.boundingBox())!.height).toBeGreaterThanOrEqual(44);
      expect(await hasHorizontalOverflow(page)).toBe(false);
      expect(await blockingViolations(page)).toEqual([]);

      const dialog = await openDialog(page, remove);
      expect(await blockingViolations(page)).toEqual([]);
      await submitAndWait(page, dialog.getByRole('button', { name: 'Remove member' }));
      await expect(page.getByTestId('members-none')).toBeVisible();
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });
});
