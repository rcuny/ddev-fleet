import { expect, test } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, openSection, submitAndWait, uniqueName } from './support/projects';
import { fillNetwork, saveNetworkConfirmed, submitNetwork } from './support/projectForm';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { createPort, deletePort, runConsole } from './support/ports';

// The editable Network tab in a real browser, desktop and phone: what a kernel test cannot show (layout at 390 px, the
// axe check, the tap targets). The rules and messages are PHPUnit: NamedPortInputTest (normalising),
// NamedPortServiceTest::messages|collisionMessages (every field message), PortCommandsTest (the command line, delete),
// ProjectPagesTest::testPortsTab* (assign, 422 states, forged posts, access). The catalogue is the host's: a migration
// seeds the "typesense" port (public 9108, router 8108); the other tests create their own ports with the console.

const PORT = 'typesense';

test.describe('project ports tab', () => {
  test('assign, unassign and the typesense flag follow the port @phone', async ({ page }, testInfo) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();

    try {
      await createProjectViaUi(page, name);
      await openSection(page, 'Network');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/network$`));

      const row = page.getByTestId('port-row').filter({ hasText: PORT });
      await expect(row).toHaveCount(1);
      await expect(row).toContainText('9108');
      await expect(row).toContainText('8108');
      const checkbox = row.getByRole('checkbox', { name: `Use port ${PORT}` });
      await expect(checkbox).not.toBeChecked();
      expect(await hasHorizontalOverflow(page), 'horizontal overflow on the Ports tab').toBe(false);

      const save = page.getByRole('button', { name: 'Save network settings' });
      if ('phone' === testInfo.project.name) {
        const box = await save.boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(43.5);
      }

      // Assign: the typesense port is public by default, so the save asks first.
      await checkbox.check();
      await submitAndWait(page, save);
      await expect(page.getByTestId('network-confirm')).toContainText('Assign the public port "typesense"');
      await submitAndWait(page, page.getByTestId('network-confirm-save'));
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');
      await expect(page.getByTestId('port-row').filter({ hasText: PORT }).getByRole('checkbox')).toBeChecked();

      // The Typesense toggle follows the assignment.
      await expect(page.getByLabel('Run a Typesense search server for each instance')).toBeChecked();

      // Unassign.
      await page.getByTestId('port-row').filter({ hasText: PORT }).getByRole('checkbox').uncheck();
      await saveNetworkConfirmed(page);
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');
      await expect(page.getByTestId('port-row').filter({ hasText: PORT }).getByRole('checkbox')).not.toBeChecked();
      await expect(page.getByLabel('Run a Typesense search server for each instance')).not.toBeChecked();
    } finally {
      await deleteProjectViaUi(page, name);
    }
    // The confirmation is a 422 page like a refusal (Turbo only renders a form response with an error status).
    expect(errors.filter((message) => !message.includes('422'))).toEqual([]);
  });

  test('a 40-character port name and 5-digit numbers wrap inside the table without widening the page @phone', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    // 40 characters, and unique: the desktop and the phone project run this test at the same time on one catalogue.
    const longName = `lp-a1b2c3d4-a1b2c3d4-a1b2c3d4-${Math.random().toString(36).slice(2).padEnd(9, '0').slice(0, 9)}z`;
    expect(longName).toHaveLength(40);
    const port = createPort(longName);
    const row = page.getByTestId('port-row').filter({ has: page.getByRole('checkbox', { name: `Use port ${port.name}` }) });
    const save = page.getByRole('button', { name: 'Save network settings' });
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Network');
      await expect(row).toContainText(port.name);
      await expect(row).toContainText(String(port.publicPort));
      expect(await hasHorizontalOverflow(page), 'long port name, unchecked').toBe(false);

      await row.getByRole('checkbox').check();
      await submitAndWait(page, save);
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');
      expect(await hasHorizontalOverflow(page), 'long port name, after the flash').toBe(false);

      // The Save button is inside the viewport and not cut off.
      const saveBox = await save.boundingBox();
      const width = page.viewportSize()!.width;
      expect(saveBox!.x).toBeGreaterThanOrEqual(0);
      expect(saveBox!.x + saveBox!.width).toBeLessThanOrEqual(width + 0.5);
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
  });

  test('a port removed from the catalogue while the tab is open gives the 422 message, keeps the ticks and passes axe @phone', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    const keep = createPort(uniqueName('pk'));
    const gone = createPort(uniqueName('pg'));
    const box = (name: string) => page.getByRole('checkbox', { name: `Use port ${name}` });
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Network');
      await box(keep.name).check();
      await box(gone.name).check();
      expect(runConsole(['fleet:port:delete', gone.name, '--yes']).status).toBe(0);
      await submitAndWait(page, page.getByRole('button', { name: 'Save network settings' }));
      await expect(page.getByTestId('ports-errors')).toContainText(`Unknown port(s): ${gone.name}.`);
      await expect(page.getByTestId('flashes')).toHaveCount(0);
      // The ticks the person made stay on screen, and the field is marked invalid.
      await expect(box(keep.name)).toBeChecked();
      await expect(box(keep.name)).toHaveAttribute('aria-invalid', 'true');
      expect(await hasHorizontalOverflow(page)).toBe(false);

      // Nothing was stored: the tab, opened again, has nothing assigned and no error.
      await openSection(page, 'Source');
      await openSection(page, 'Network');
      await expect(page.getByTestId('ports-errors')).toHaveCount(0);
      await expect(box(keep.name)).not.toBeChecked();
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(keep.name);
      deletePort(gone.name);
    }
    expect(errors.filter((message) => !message.includes('422'))).toEqual([]);
  });

  test('the checkbox and the button are touch-sized on the phone @phone', async ({ page }, testInfo) => {
    test.skip('phone' !== testInfo.project.name, 'phone layout only');
    const project = uniqueName();
    const port = createPort(uniqueName('pp'));
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Network');
      const row = page.getByTestId('port-row').filter({ has: page.getByRole('checkbox', { name: `Use port ${port.name}` }) });
      await expect(row.locator('label')).toBeVisible();
      const checkboxBox = await page.getByRole('checkbox', { name: `Use port ${port.name}` }).boundingBox();
      expect(checkboxBox!.width).toBeGreaterThan(10);
      const saveBox = await page.getByRole('button', { name: 'Save network settings' }).boundingBox();
      expect(saveBox!.height).toBeGreaterThanOrEqual(43.5);
      expect(saveBox!.width).toBeGreaterThan(300);
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
  });

  test('port access: flip it with a confirmation, Cancel keeps it, the badge follows @phone', async ({ page }, testInfo) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    const port = createPort(uniqueName('pa'));
    const row = () => page.getByTestId('port-row').filter({ has: page.getByRole('checkbox', { name: `Use port ${port.name}` }) });
    const select = () => row().getByRole('combobox', { name: `Access of port ${port.name}` });
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Network');
      // Not assigned yet: a badge, no select.
      await expect(row().getByTestId('port-access-badge')).toHaveAttribute('data-access', 'gated');
      await expect(select()).toHaveCount(0);
      await row().getByRole('checkbox').check();
      await submitNetwork(page);
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');

      // Assigned: the select is there. Flipping to public asks for a confirmation.
      await expect(select()).toBeVisible();
      if ('phone' === testInfo.project.name) {
        const box = await select().boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(43.5);
      }
      await select().selectOption('public');
      await submitNetwork(page);
      const dialog = page.getByRole('dialog');
      await expect(dialog).toBeVisible();
      await expect(dialog).toContainText(`Change the access of the port "${port.name}" from gated to public`);
      expect(await hasHorizontalOverflow(page), 'overflow with the confirmation open').toBe(false);

      // Escape closes it, the typed choice stays, nothing was saved.
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(select()).toHaveValue('public');
      await page.goto(`/admin/projects/${project}/network`);
      await expect(row().getByTestId('port-access-badge')).toHaveAttribute('data-access', 'gated');

      // Confirm by keyboard: the dialog takes the focus, Tab reaches "Confirm and save".
      await select().selectOption('public');
      await submitNetwork(page);
      await expect(page.getByRole('dialog')).toBeVisible();
      await submitAndWait(page, page.getByTestId('network-confirm-save'));
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');
      await expect(row().getByTestId('port-access-badge')).toHaveAttribute('data-access', 'public');
      await expect(row().getByTestId('port-access-badge')).toContainText('Public');
      await expect(select()).toHaveValue('public');

      // And back: it persists after a reload.
      await select().selectOption('gated');
      await saveNetworkConfirmed(page);
      await page.reload();
      await expect(row().getByTestId('port-access-badge')).toHaveAttribute('data-access', 'gated');
      expect(await hasHorizontalOverflow(page)).toBe(false);
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
    expect(errors.filter((message) => !message.includes('422'))).toEqual([]);
  });

  test('a new hostname asks for a confirmation, removing one does not', async ({ page }) => {
    test.setTimeout(120_000);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Network');
      await fillNetwork(page, { hostnames: 'www' });
      await submitNetwork(page);
      await expect(page.getByRole('dialog')).toContainText('Add the hostname "www"');
      await submitAndWait(page, page.getByTestId('network-confirm-save'));
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');
      await expect(page.getByLabel('Additional hostnames')).toHaveValue('www');

      await fillNetwork(page, { hostnames: '' });
      await submitNetwork(page);
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');
      await expect(page.getByRole('dialog')).toHaveCount(0);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});
