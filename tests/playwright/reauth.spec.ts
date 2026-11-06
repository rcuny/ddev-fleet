import { expect } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, uniqueName } from './support/projects';
import { confirmPassword, removeSecret, saveSecret, secretRow, test, uniqueSecret, uniqueValue } from './support/secrets';
import { collectBrowserErrors, hasHorizontalOverflow, unexpectedErrors } from './support/shell';

// The re-auth window: Reveal secret and a sealed Export send the admin to a password page first, a wrong password is
// refused, and the page is accessible at desktop and phone width.

test.describe('re-auth window', () => {
  test('a reveal asks for the password, then works; a wrong password is refused @phone', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const name = uniqueSecret('RA');
    const value = uniqueValue();
    await page.goto('/admin/secrets');

    try {
      await saveSecret(page, name, value);
      await secretRow(page, name).getByTestId('secret-reveal').click();
      await expect(page).toHaveURL(/\/admin\/reauth\?next=\/admin\/secrets$/);
      expect(await page.content()).not.toContain(value);
      expect(await hasHorizontalOverflow(page)).toBe(false);
      await expectNoBlockingViolations(page, 're-auth form');

      // A wrong password: 422, the error is wired to the field, nothing opens.
      await page.getByTestId('reauth-password').fill('definitely not the password');
      await page.getByTestId('reauth-submit').click();
      await expect(page.getByTestId('reauth-error')).toContainText('The password is not right.');
      await expect(page.getByTestId('reauth-password')).toHaveAttribute('aria-invalid', 'true');
      await expect(page.getByTestId('reauth-password')).toHaveValue('');
      await expectNoBlockingViolations(page, 're-auth form with an error');

      await confirmPassword(page);
      await expect(page).toHaveURL(/\/admin\/secrets$/);
      await expect(page.getByTestId('flashes')).toContainText('Password confirmed.');

      await secretRow(page, name).getByTestId('secret-reveal').click();
      await expect(page.getByTestId('reveal-dialog').getByTestId('revealed-value')).toHaveText(value);

      await page.goto('/admin/audit?action=reauth.failure');
      await expect(page.getByTestId('audit-row').first()).toContainText('reauth.failure');
      expect(await page.content()).not.toContain('definitely not the password');
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
    expect(unexpectedErrors(errors)).toEqual([]);
  });

  test('an export with a passphrase asks for the password first; one without does not', async ({ page }) => {
    const project = uniqueName('reauth');
    await createProjectViaUi(page, project);
    try {
      await page.goto(`/admin/projects/${project}/source`);
      await expect(page.locator('#export-passphrase-help')).toContainText('At least 8 characters');

      const [plain] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-download').click()]);
      expect(plain.suggestedFilename()).toBe(`${project}.fleet-project.json`);

      await page.getByTestId('export-passphrase').fill('correct horse battery');
      await page.getByTestId('export-download').click();
      await expect(page).toHaveURL(new RegExp(`/admin/reauth\\?next=/admin/projects/${project}/source$`));
      await confirmPassword(page);
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/source$`));

      await page.getByTestId('export-passphrase').fill('correct horse battery');
      const [sealed] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-download').click()]);
      expect(sealed.suggestedFilename()).toBe(`${project}.fleet-project.json`);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});
