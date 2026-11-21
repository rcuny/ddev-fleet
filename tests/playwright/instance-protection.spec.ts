import { expect, test } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { deployOne, rowOf } from './support/instances';
import { confirmPassword } from './support/secrets';
import { collectBrowserErrors, unexpectedErrors } from './support/shell';
import { setStandInRole } from './support/standIn';

// FLE-363: an admin protects an instance through the password re-auth page, Destroy disappears everywhere, and removing
// the protection (again through re-auth) brings it back. The refusals on every other path (API, CLI, bulk, retry) are
// asserted over HTTP and in the integration tests.

test.describe('instance protection', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('protect and unprotect go through re-auth and hide Destroy', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const id = await deployOne(page);
    await page.goto(`/instances/${id}`);

    await expect(page.getByTestId('protected-badge')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Destroy…' })).toBeVisible();
    await expectNoBlockingViolations(page, 'instance page, not protected');

    // Protect: the password page first, then back on the instance page with the lock.
    await page.getByTestId('protection-toggle').click();
    await expect(page).toHaveURL(new RegExp(`/admin/reauth\\?next=/instances/${id}$`));
    await confirmPassword(page);
    await expect(page).toHaveURL(new RegExp(`/instances/${id}$`));
    await page.getByTestId('protection-toggle').click();
    await expect(page.getByTestId('flashes')).toContainText('now protected');
    await expect(page.getByTestId('protected-badge')).toBeVisible();
    await expect(page.getByTestId('protection-state')).toContainText('Protected against destroy since');
    await expect(page.getByRole('button', { name: 'Destroy…' })).toHaveCount(0);
    await expect(page.getByTestId('protection-toggle')).toHaveText('Remove protection');
    await expectNoBlockingViolations(page, 'instance page, protected');

    await page.goto('/instances');
    await expect(rowOf(page, id).getByTestId('protected-badge')).toBeVisible();
    await page.getByLabel(`More actions for ${id}`).click();
    await expect(page.getByRole('menuitem', { name: /Destroy/ })).toHaveCount(0);
    await page.keyboard.press('Escape');

    // Remove: asks for the password again.
    await page.goto(`/instances/${id}`);
    await page.getByTestId('protection-toggle').click();
    await expect(page).toHaveURL(/\/admin\/reauth\?next=/);
    await confirmPassword(page);
    await page.getByTestId('protection-toggle').click();
    await expect(page.getByTestId('flashes')).toContainText('protection is removed');
    await expect(page.getByTestId('protected-badge')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Destroy…' })).toBeVisible();

    expect(unexpectedErrors(errors)).toEqual([]);
  });
});
