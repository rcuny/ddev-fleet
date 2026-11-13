import { expect, test } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, openDialog, submitAndWait, uniqueName } from './support/projects';
import { confirmPassword } from './support/secrets';

// FLE-299: the Links card of the Network tab (create behind the password, refusal under the field, delete with a
// confirmation). The rules and messages are PHPUnit: ProjectLinksTest. The phone check is project-links.phone.spec.ts.

test.describe('project Links card', () => {
  test('create asks for the password, a bad port is refused under its field, delete asks first', async ({ page }) => {
    test.setTimeout(180_000);
    const source = uniqueName('lnk');
    const target = uniqueName('tgt');

    try {
      await createProjectViaUi(page, source);
      await createProjectViaUi(page, target);
      await page.goto(`/admin/projects/${source}/network`);
      const card = page.getByTestId('project-links');
      await expect(card).toBeVisible();
      await expect(page.getByTestId('links-out-empty')).toBeVisible();

      await card.getByLabel('Target project').selectOption(target);
      await card.getByLabel('Service').fill('typesense');
      await card.getByLabel('Port').fill('8108');
      await submitAndWait(page, card.getByRole('button', { name: 'Create link' }));
      await expect(page).toHaveURL(/\/admin\/reauth\?next=/);
      await confirmPassword(page);
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${source}/network$`));

      await card.getByLabel('Target project').selectOption(target);
      await card.getByLabel('Service').fill('typesense');
      await card.getByLabel('Port').fill('99999');
      await submitAndWait(page, card.getByRole('button', { name: 'Create link' }));
      await expect(page.getByText('Enter a TCP port between 1 and 65535.')).toBeVisible();

      await card.getByLabel('Port').fill('8108');
      await submitAndWait(page, card.getByRole('button', { name: 'Create link' }));
      await expect(page.getByTestId('flashes')).toContainText('Link created.');
      const row = page.getByTestId('link-row');
      await expect(row).toHaveCount(1);
      await expect(row).toContainText(`${target}`);
      await expect(row).toContainText('typesense:8108');

      const dialog = await openDialog(page, page.getByTestId('link-delete'));
      await submitAndWait(page, dialog.getByTestId('link-delete-confirm'));
      await expect(page.getByTestId('flashes')).toContainText('Link deleted.');
      await expect(page.getByTestId('links-out-empty')).toBeVisible();
    } finally {
      await deleteProjectViaUi(page, source);
      await deleteProjectViaUi(page, target);
    }
  });
});
