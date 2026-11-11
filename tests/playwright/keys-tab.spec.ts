import { expect, test } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { confirmPassword } from './support/secrets';
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

      // Turning it on needs a recent re-auth: without one the confirmation sends the person to the password page.
      let dialog = await openDialog(page, page.getByTestId('container-ssh-change'));
      await submitAndWait(page, dialog.getByTestId('container-ssh-confirm-button'));
      await expect(page).toHaveURL(/\/admin\/reauth/);
      await expect(page.getByTestId('container-ssh-state')).toHaveCount(0);
      await confirmPassword(page);
      await expect(page.getByTestId('container-ssh-state')).toContainText('is off');

      dialog = await openDialog(page, page.getByTestId('container-ssh-change'));
      await submitAndWait(page, dialog.getByTestId('container-ssh-confirm-button'));
      await expect(page.getByTestId('flashes')).toContainText('Git over SSH in the container is now on.');

      await page.goto('/admin/audit');
      await expect(page.getByTestId('audit-row').filter({ hasText: `${name}/on` })).toHaveCount(1);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('terminal input over the API is off, changes only through the dialog behind re-auth and is audited @phone', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await page.goto(`/admin/projects/${name}/keys`);
      await expect(page.getByTestId('api-terminal-risk')).toContainText('never reaches a shell');
      await expect(page.getByTestId('api-terminal-state')).toContainText('is off');
      expect(await hasHorizontalOverflow(page)).toBe(false);
      await expectNoBlockingViolations(page);

      let dialog = await openDialog(page, page.getByTestId('api-terminal-change'));
      await submitAndWait(page, dialog.getByTestId('api-terminal-confirm-button'));
      await expect(page).toHaveURL(/\/admin\/reauth/);
      await confirmPassword(page);
      await expect(page.getByTestId('api-terminal-state')).toContainText('is off');

      dialog = await openDialog(page, page.getByTestId('api-terminal-change'));
      await submitAndWait(page, dialog.getByTestId('api-terminal-confirm-button'));
      await expect(page.getByTestId('flashes')).toContainText('Terminal input over the API is now on.');
      await expect(page.getByTestId('api-terminal-state')).toContainText('is on');

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
