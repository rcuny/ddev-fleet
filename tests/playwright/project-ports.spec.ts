import { expect, test } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, openSection, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// The editable Ports tab in a real browser, desktop and phone. The catalogue is the host's: a migration seeds the
// "typesense" port (public 9108, router 8108), so the spec uses that one and creates its own project.

const PORT = 'typesense';

test.describe('project ports tab', () => {
  test('assign, unassign and the typesense flag follow the port', async ({ page }, testInfo) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();

    try {
      await createProjectViaUi(page, name);
      await openSection(page, 'Ports');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/ports$`));
      await expect(page.getByTestId('ports-note')).not.toContainText('M0.07');

      const row = page.getByTestId('port-row').filter({ hasText: PORT });
      await expect(row).toHaveCount(1);
      await expect(row).toContainText('9108');
      await expect(row).toContainText('8108');
      const checkbox = row.getByRole('checkbox', { name: `Use port ${PORT}` });
      await expect(checkbox).not.toBeChecked();
      expect(await hasHorizontalOverflow(page), 'horizontal overflow on the Ports tab').toBe(false);
      await expectNoBlockingViolations(page, 'Ports tab');

      const save = page.getByRole('button', { name: 'Save ports' });
      if ('phone' === testInfo.project.name) {
        const box = await save.boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(43.5);
      }

      // Assign.
      await checkbox.check();
      await submitAndWait(page, save);
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      await expect(page.getByTestId('port-row').filter({ hasText: PORT }).getByRole('checkbox')).toBeChecked();

      // The General tab's Typesense flag follows the assignment.
      await openSection(page, 'General');
      await expect(page.getByLabel('Typesense')).toBeChecked();
      await openSection(page, 'Ports');

      // Unassign.
      await page.getByTestId('port-row').filter({ hasText: PORT }).getByRole('checkbox').uncheck();
      await submitAndWait(page, page.getByRole('button', { name: 'Save ports' }));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      await expect(page.getByTestId('port-row').filter({ hasText: PORT }).getByRole('checkbox')).not.toBeChecked();
      await openSection(page, 'General');
      await expect(page.getByLabel('Typesense')).not.toBeChecked();
    } finally {
      await deleteProjectViaUi(page, name);
    }
    expect(errors).toEqual([]);
  });

  test('a save without a change says so', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await openSection(page, 'Ports');
      await submitAndWait(page, page.getByRole('button', { name: 'Save ports' }));
      await expect(page.getByTestId('flashes')).toContainText('No change');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('a member gets 403 on the Ports tab and cannot post to it', async ({ page, context, baseURL }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await setStandInRole(context, 'member', baseURL!);
      const get = await page.goto(`/admin/projects/${name}/ports`);
      expect(get?.status()).toBe(403);
      const post = await page.request.post(`/admin/projects/${name}/ports`, { form: { _token: 'x', 'ports[]': PORT } });
      expect(post.status()).toBe(403);
    } finally {
      await setStandInRole(context, 'admin', baseURL!);
      await deleteProjectViaUi(page, name);
    }
  });
});
