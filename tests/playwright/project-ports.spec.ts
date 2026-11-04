import { expect, test } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, openSection, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { createPort, deletePort, runConsole } from './support/ports';

// The editable Ports tab in a real browser, desktop and phone: what a kernel test cannot show (layout at 390 px, the
// axe check, the tap targets). The rules and messages are PHPUnit: NamedPortInputTest (normalising),
// NamedPortServiceTest::messages|collisionMessages (every field message), PortCommandsTest (the command line, delete),
// ProjectPagesTest::testPortsTab* (assign, 422 states, forged posts, access). The catalogue is the host's: a migration
// seeds the "typesense" port (public 9108, router 8108); the other tests create their own ports with the console.

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

  test('a 40-character port name and 5-digit numbers wrap inside the table without widening the page', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    // 40 characters, and unique: the desktop and the phone project run this test at the same time on one catalogue.
    const longName = `lp-a1b2c3d4-a1b2c3d4-a1b2c3d4-${Math.random().toString(36).slice(2).padEnd(9, '0').slice(0, 9)}z`;
    expect(longName).toHaveLength(40);
    const port = createPort(longName);
    const row = page.getByTestId('port-row').filter({ has: page.getByRole('checkbox', { name: `Use port ${port.name}` }) });
    const save = page.getByRole('button', { name: 'Save ports' });
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      await expect(row).toContainText(port.name);
      await expect(row).toContainText(String(port.publicPort));
      expect(await hasHorizontalOverflow(page), 'long port name, unchecked').toBe(false);

      await row.getByRole('checkbox').check();
      await submitAndWait(page, save);
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
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

  test('a port removed from the catalogue while the tab is open gives the 422 message, keeps the ticks and passes axe', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    const keep = createPort(uniqueName('pk'));
    const gone = createPort(uniqueName('pg'));
    const box = (name: string) => page.getByRole('checkbox', { name: `Use port ${name}` });
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      await box(keep.name).check();
      await box(gone.name).check();
      expect(runConsole(['fleet:port:delete', gone.name, '--yes']).status).toBe(0);
      await submitAndWait(page, page.getByRole('button', { name: 'Save ports' }));
      await expect(page.getByTestId('ports-errors')).toContainText(`Unknown port(s): ${gone.name}.`);
      await expect(page.getByTestId('flashes')).toHaveCount(0);
      // The ticks the person made stay on screen, and the field is marked invalid.
      await expect(box(keep.name)).toBeChecked();
      await expect(box(keep.name)).toHaveAttribute('aria-invalid', 'true');
      expect(await hasHorizontalOverflow(page)).toBe(false);

      // Nothing was stored: the tab, opened again, has nothing assigned and no error.
      await openSection(page, 'General');
      await openSection(page, 'Ports');
      await expect(page.getByTestId('ports-errors')).toHaveCount(0);
      await expect(box(keep.name)).not.toBeChecked();
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(keep.name);
      deletePort(gone.name);
    }
    expect(errors.filter((message) => !message.includes('422'))).toEqual([]);
  });

  test('the checkbox and the button are touch-sized on the phone', async ({ page }, testInfo) => {
    test.skip('phone' !== testInfo.project.name, 'phone layout only');
    const project = uniqueName();
    const port = createPort(uniqueName('pp'));
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      const row = page.getByTestId('port-row').filter({ has: page.getByRole('checkbox', { name: `Use port ${port.name}` }) });
      await expect(row.locator('label')).toBeVisible();
      const checkboxBox = await page.getByRole('checkbox', { name: `Use port ${port.name}` }).boundingBox();
      expect(checkboxBox!.width).toBeGreaterThan(10);
      const saveBox = await page.getByRole('button', { name: 'Save ports' }).boundingBox();
      expect(saveBox!.height).toBeGreaterThanOrEqual(43.5);
      expect(saveBox!.width).toBeGreaterThan(300);
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
  });
});
