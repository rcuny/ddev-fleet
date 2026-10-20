import { expect, test, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, openSection, submitAndWait, uniqueName } from './support/projects';
import { createPort, deletePort, runConsole } from './support/ports';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// The Ports tab in full: every kind of port assigned and unassigned, each refusal the service and the catalogue
// commands can return, and the long values at 390 px. The catalogue is host-wide and has no web UI, so every test
// creates its own ports with the console (unique names and random numbers, support/ports.ts) and removes them last.

const row = (page: Page, port: string) => page.getByTestId('port-row').filter({ has: page.getByRole('checkbox', { name: `Use port ${port}` }) });
const box = (page: Page, port: string) => page.getByRole('checkbox', { name: `Use port ${port}` });
const save = (page: Page) => page.getByRole('button', { name: 'Save ports' });

test.describe('project ports tab, in full', () => {
  test('a port created on the host is listed with both numbers, unassigned, on a new project', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    const port = createPort(uniqueName('pp'));
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      await expect(page.getByTestId('ports-empty')).toHaveCount(0);
      await expect(page.getByTestId('ports-note')).toContainText('named ports the host offers');
      await expect(row(page, port.name)).toHaveCount(1);
      await expect(row(page, port.name)).toContainText(String(port.publicPort));
      await expect(row(page, port.name)).toContainText(String(port.routerPort));
      await expect(box(page, port.name)).not.toBeChecked();
      await expect(page.getByTestId('ports-errors')).toHaveCount(0);
      await expect(page.getByRole('table', { name: 'Named ports' })).toBeVisible();
      await expectNoBlockingViolations(page, 'Ports tab');
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
    expect(errors).toEqual([]);
  });

  test('several ports are assigned, one is unassigned, and the state survives a reload', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    const [a, b, c] = [createPort(uniqueName('pa')), createPort(uniqueName('pb')), createPort(uniqueName('pc'))];
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      await box(page, a.name).check();
      await box(page, c.name).check();
      await submitAndWait(page, save(page));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      await expect(box(page, a.name)).toBeChecked();
      await expect(box(page, b.name)).not.toBeChecked();
      await expect(box(page, c.name)).toBeChecked();
      await expectNoBlockingViolations(page, 'Ports tab, saved');

      // Unassign one, add another.
      await box(page, a.name).uncheck();
      await box(page, b.name).check();
      await submitAndWait(page, save(page));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      await page.reload();
      await expect(box(page, a.name)).not.toBeChecked();
      await expect(box(page, b.name)).toBeChecked();
      await expect(box(page, c.name)).toBeChecked();

      // Unassign all: the form posts no port at all and every assignment goes.
      await box(page, b.name).uncheck();
      await box(page, c.name).uncheck();
      await submitAndWait(page, save(page));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      for (const port of [a, b, c]) {
        await expect(box(page, port.name)).not.toBeChecked();
      }
    } finally {
      await deleteProjectViaUi(page, project);
      for (const port of [a, b, c]) {
        deletePort(port.name);
      }
    }
    expect(errors).toEqual([]);
  });

  test('the assignment is per project: a second project does not see the first one\'s ports', async ({ page }) => {
    test.setTimeout(120_000);
    const first = uniqueName();
    const second = uniqueName();
    const port = createPort(uniqueName('pp'));
    try {
      await createProjectViaUi(page, first);
      await openSection(page, 'Ports');
      await box(page, port.name).check();
      await submitAndWait(page, save(page));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      await createProjectViaUi(page, second);
      await openSection(page, 'Ports');
      await expect(box(page, port.name)).not.toBeChecked();
      // Two projects may share a port.
      await box(page, port.name).check();
      await submitAndWait(page, save(page));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      await expect(box(page, port.name)).toBeChecked();
    } finally {
      await deleteProjectViaUi(page, first);
      await deleteProjectViaUi(page, second);
      deletePort(port.name);
    }
  });

  test('the Typesense flag on the General tab assigns the typesense port, and the Ports tab shows it', async ({ page }) => {
    test.setTimeout(120_000);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await expect(page.getByLabel('Typesense')).not.toBeChecked();
      await page.getByText('Run a Typesense search server for each instance').click();
      await expect(page.getByLabel('Typesense')).toBeChecked();
      await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      await openSection(page, 'Ports');
      await expect(box(page, 'typesense')).toBeChecked();

      // And back: unticking the port clears the flag.
      await box(page, 'typesense').uncheck();
      await submitAndWait(page, save(page));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      await openSection(page, 'General');
      await expect(page.getByLabel('Typesense')).not.toBeChecked();
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('ticking without saving changes nothing: leaving the tab and coming back shows the stored state', async ({ page }) => {
    const project = uniqueName();
    const port = createPort(uniqueName('pp'));
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      await box(page, port.name).check();
      await openSection(page, 'General');
      await openSection(page, 'Ports');
      await expect(box(page, port.name)).not.toBeChecked();
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
  });

  test('a port removed from the catalogue while the tab is open is refused with a message, nothing is saved', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    const keep = createPort(uniqueName('pk'));
    const gone = createPort(uniqueName('pg'));
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      await box(page, keep.name).check();
      await box(page, gone.name).check();
      expect(runConsole(['fleet:port:delete', gone.name, '--yes']).status).toBe(0);
      await submitAndWait(page, save(page));
      const alert = page.getByTestId('ports-errors');
      await expect(alert).toBeVisible();
      await expect(alert).toContainText(`Unknown port(s): ${gone.name}.`);
      await expect(box(page, gone.name)).toHaveCount(0);
      // The ticks the person made stay on screen.
      await expect(box(page, keep.name)).toBeChecked();
      await expect(box(page, keep.name)).toHaveAttribute('aria-invalid', 'true');
      expect(await hasHorizontalOverflow(page)).toBe(false);
      await expectNoBlockingViolations(page, 'Ports tab, error');

      // Nothing was stored: the same tab, reloaded, has nothing assigned and no error.
      await openSection(page, 'General');
      await openSection(page, 'Ports');
      await expect(page.getByTestId('ports-errors')).toHaveCount(0);
      await expect(box(page, keep.name)).not.toBeChecked();

      // Saving what is there now works.
      await box(page, keep.name).check();
      await submitAndWait(page, save(page));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(keep.name);
      deletePort(gone.name);
    }
    expect(errors.filter((message) => !message.includes('422'))).toEqual([]);
  });

  test('forged posts: unknown and malformed names, too many ports, a bad token, a missing project', async ({ page }) => {
    test.setTimeout(120_000);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      const url = `/admin/projects/${project}/ports`;
      const alertAfter = async (names: string[]): Promise<string> => {
        await page.evaluate((extra) => {
          const form = document.querySelector('form[data-testid="ports-form"]') as HTMLFormElement;
          for (const name of extra) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ports[]';
            input.value = name;
            form.append(input);
          }
        }, names);
        await submitAndWait(page, save(page));
        return page.getByTestId('ports-errors').innerText();
      };

      expect(await alertAfter(['no-such-port'])).toContain('Unknown port(s): no-such-port.');

      // A malformed name is never echoed back.
      const malformed = await alertAfter(['<b>x</b>', 'UPPER']);
      expect(malformed).toContain('Unknown port(s): (invalid name), (invalid name).');
      expect(malformed).not.toContain('<b>');

      // Only the first five unknown names are listed.
      const many = await alertAfter(['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7']);
      expect(many).toContain('Unknown port(s): u1, u2, u3, u4, u5.');
      expect(many).not.toContain('u6');

      expect(await alertAfter(Array.from({ length: 51 }, (_, index) => `p${index}`))).toContain('At most 50 ports can be assigned.');
      expect(await hasHorizontalOverflow(page)).toBe(false);

      const post = (form: Record<string, string>) => page.request.post(url, { form, maxRedirects: 0 });
      expect((await post({ _token: 'not-the-token' })).status()).toBe(403);
      expect((await post({})).status()).toBe(403);

      const missing = await page.request.post('/admin/projects/no-such-project-pw/ports', { form: { _token: 'x' }, maxRedirects: 0 });
      expect(missing.status()).toBe(404);
      expect((await page.request.get('/admin/projects/no-such-project-pw/ports')).status()).toBe(404);

      // After all that, nothing is assigned.
      await openSection(page, 'General');
      await openSection(page, 'Ports');
      await expect(page.getByRole('checkbox', { checked: true })).toHaveCount(0);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  const create = (name: string, pub: string, router: string) => runConsole(['fleet:port:create', name, `--public=${pub}`, `--router=${router}`]);
  const refused = (result: { status: number; stderr: string; stdout: string }, message: string) => {
    expect(result.status, result.stdout + result.stderr).toBe(1);
    expect(result.stderr).toContain(message);
  };

  test('the catalogue command refuses a bad or duplicate name and stores nothing', async () => {
    test.setTimeout(120_000);
    const existing = createPort(uniqueName('pe'));
    try {
      refused(create('', '21001', '41001'), 'Enter a name.');
      refused(create('Upper', '21001', '41001'), 'Use lowercase letters, digits and single dashes only');
      refused(create('a--b', '21001', '41001'), 'must not contain two dashes');
      refused(create('x'.repeat(41), '21001', '41001'), 'The name must be at most 40 characters.');
      refused(create(existing.name, '21001', '41001'), 'A port with this name already exists.');
      expect(runConsole(['fleet:port:list']).stdout).not.toContain('21001');
    } finally {
      deletePort(existing.name);
    }
  });

  test('the catalogue command refuses bad, reserved and colliding port numbers and stores nothing', async () => {
    test.setTimeout(120_000);
    const existing = createPort(uniqueName('pe'));
    const fresh = uniqueName('pf');
    try {
      for (const bad of ['0', '65536', 'abc', '1.5', '']) {
        refused(create(fresh, bad, '41001'), 'Enter a port number between 1 and 65535.');
      }
      refused(create(fresh, '21001', '-5'), 'Enter a port number between 1 and 65535.');
      for (const reserved of ['22', '80', '443', '8765', '2019', '3000', '9091', '8080', '8443']) {
        refused(create(fresh, reserved, '41001'), 'This port is reserved for the host.');
      }
      refused(create(fresh, '21001', '22'), 'This port is reserved for the host.');
      refused(create(fresh, '21001', '8443'), 'This port is reserved for the host.');
      refused(create(fresh, '21001', '21001'), 'The router port must differ from the public port.');
      refused(create(fresh, String(existing.publicPort), '41001'), 'This port number is already used by another entry.');
      refused(create(fresh, String(existing.routerPort), '41001'), 'This port number is already used by another entry.');
      refused(create(fresh, '21001', String(existing.publicPort)), 'This port number is already used by another entry.');
      refused(create(fresh, '21001', String(existing.routerPort)), 'This port number is already used by another entry.');
      expect(runConsole(['fleet:port:list']).stdout).not.toContain(fresh);
    } finally {
      deletePort(existing.name);
    }
  });

  test('a port that a project uses cannot be deleted; once unassigned it can, and it leaves the tab', async ({ page }) => {
    test.setTimeout(120_000);
    const project = uniqueName();
    const existing = createPort(uniqueName('pe'));
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      await box(page, existing.name).check();
      await submitAndWait(page, save(page));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      const blocked = runConsole(['fleet:port:delete', existing.name, '--yes']);
      expect(blocked.status).toBe(2);
      expect(blocked.stderr).toContain(`Used by project(s): ${project}.`);

      await box(page, existing.name).uncheck();
      await submitAndWait(page, save(page));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      expect(runConsole(['fleet:port:delete', existing.name]).status, 'without --yes nothing is deleted').not.toBe(0);
      expect(runConsole(['fleet:port:list']).stdout).toContain(existing.name);
      expect(runConsole(['fleet:port:delete', existing.name, '--yes']).status).toBe(0);
      await openSection(page, 'General');
      await openSection(page, 'Ports');
      await expect(box(page, existing.name)).toHaveCount(0);
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(existing.name);
    }
  });

  test('a 40-character port name and 5-digit numbers wrap inside the table without scrolling the page', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    const longName = `lp-${'a1b2c3d4-'.repeat(5)}`.slice(0, 40).replace(/-$/, 'z');
    expect(longName).toHaveLength(40);
    const port = createPort(longName);
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      await expect(row(page, port.name)).toContainText(port.name);
      await expect(row(page, port.name)).toContainText(String(port.publicPort));
      expect(await hasHorizontalOverflow(page), 'long port name, unchecked').toBe(false);
      await expectNoBlockingViolations(page, 'Ports tab, long name');

      await box(page, port.name).check();
      await submitAndWait(page, save(page));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      expect(await hasHorizontalOverflow(page), 'long port name, after the flash').toBe(false);

      // The Save button is inside the viewport and not cut off.
      const saveBox = await save(page).boundingBox();
      const width = page.viewportSize()!.width;
      expect(saveBox!.x).toBeGreaterThanOrEqual(0);
      expect(saveBox!.x + saveBox!.width).toBeLessThanOrEqual(width + 0.5);
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
    expect(errors).toEqual([]);
  });

  test('the checkbox and the button are touch-sized on the phone', async ({ page }, testInfo) => {
    test.skip('phone' !== testInfo.project.name, 'phone layout only');
    const project = uniqueName();
    const port = createPort(uniqueName('pp'));
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      const target = box(page, port.name);
      const label = row(page, port.name).locator('label');
      await expect(label).toBeVisible();
      const checkboxBox = await target.boundingBox();
      expect(checkboxBox!.width).toBeGreaterThan(10);
      const saveBox = await save(page).boundingBox();
      expect(saveBox!.height).toBeGreaterThanOrEqual(43.5);
      expect(saveBox!.width).toBeGreaterThan(300);
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
  });
});
