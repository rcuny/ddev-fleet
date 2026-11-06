import { expect, test } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, openDialog, submitAndWait, uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';

// The Keys & secrets tab (FLE-232): Git SSH section with a confirmed, audited toggle, and masked secret values.
test.describe('keys & secrets tab', () => {
  test('Git over SSH changes only through the confirmation dialog and is audited @phone', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await page.goto(`/admin/projects/${name}/keys`);
      await expect(page.getByTestId('container-ssh-risk')).toContainText('any process in the container can use the push key');
      await expect(page.getByTestId('container-ssh-state')).toContainText('is off');
      expect(await hasHorizontalOverflow(page)).toBe(false);
      await expectNoBlockingViolations(page);

      const dialog = await openDialog(page, page.getByTestId('container-ssh-change'));
      await submitAndWait(page, dialog.getByTestId('container-ssh-confirm-button'));
      await expect(page.getByTestId('flashes')).toContainText('Git over SSH in the container is now on.');

      await page.goto('/admin/audit');
      await expect(page.getByTestId('audit-row').filter({ hasText: `${name}/on` })).toHaveCount(1);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the secret value field is masked and a multi-line value has its own textarea', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await page.goto(`/admin/projects/${name}/keys`);
      await expect(page.getByLabel('Value', { exact: true })).toHaveAttribute('type', 'password');
      await page.getByTestId('secret-multiline').locator('summary').click();
      await expect(page.getByLabel('Multi-line value')).toBeVisible();
      await expectNoBlockingViolations(page);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});
