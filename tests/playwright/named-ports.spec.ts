import { expect, test, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, openSection, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { canRunConsole, consoleRun, randomPorts } from './support/templates';

// The named-port catalogue. It has no pages of its own in 1.x: the host manages it with `fleet:port:create|list|delete`
// and a project uses it on its Ports tab. So the catalogue is exercised through the commands (every message of
// NamedPortService and the PortNumbers/ResourceName rules) and its web side, the Ports tab, is re-read in the browser
// after each change. The catalogue is shared by the whole suite: every test creates ports with unique names and random
// numbers and removes them in `finally`. (The "catalogue is empty" state cannot be reached without deleting the seeded
// typesense port the other specs rely on.)

test.skip(!canRunConsole(), 'The catalogue is managed with bin/console: it needs write access to the FLEET_HOME of the app under test.');

const TAKEN = 'This port number is already used by another entry.';
const RESERVED = 'This port is reserved for the host.';
const RANGE = 'Enter a port number between 1 and 65535.';

function portName(): string {
  return uniqueName('np');
}

function create(name: string, pub: number | string, router: number | string): { status: number | null; output: string } {
  return consoleRun(['fleet:port:create', name, `--public=${pub}`, `--router=${router}`]);
}

function remove(name: string): { status: number | null; output: string } {
  return consoleRun(['fleet:port:delete', name, '--yes']);
}

function expectCreated(name: string, pub: number, router: number): void {
  const result = create(name, pub, router);
  expect(result.output).toContain(`Named port "${name}" created (public ${pub}, router ${router}).`);
  expect(result.status).toBe(0);
}

async function openPorts(page: Page, project: string): Promise<void> {
  await page.goto(`/admin/projects/${project}/ports`);
  await expect(page.getByTestId('ports-form')).toBeVisible();
}

test.describe('named-port catalogue', () => {
  test('a port created on the host shows on the Ports tab with its numbers and can be assigned and unassigned', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    const name = portName();
    const { pub, router } = randomPorts();
    try {
      expectCreated(name, pub, router);
      await createProjectViaUi(page, project);
      await openSection(page, 'Ports');
      const row = page.getByTestId('port-row').filter({ has: page.getByText(name, { exact: true }) });
      await expect(row).toHaveCount(1);
      await expect(row).toContainText(String(pub));
      await expect(row).toContainText(String(router));
      await expect(row.getByRole('checkbox', { name: `Use port ${name}` })).not.toBeChecked();
      expect(await hasHorizontalOverflow(page), 'overflow on the Ports tab').toBe(false);
      await expectNoBlockingViolations(page, 'Ports tab with a catalogue entry');

      await row.getByRole('checkbox').check();
      await submitAndWait(page, page.getByRole('button', { name: 'Save ports' }));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      await expect(page.getByTestId('port-row').filter({ has: page.getByText(name, { exact: true }) }).getByRole('checkbox')).toBeChecked();
      // The typesense flag is untouched by another port.
      await openSection(page, 'General');
      await expect(page.getByLabel('Typesense')).not.toBeChecked();

      await openPorts(page, project);
      await page.getByTestId('port-row').filter({ has: page.getByText(name, { exact: true }) }).getByRole('checkbox').uncheck();
      await submitAndWait(page, page.getByRole('button', { name: 'Save ports' }));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      await expect(page.getByTestId('port-row').filter({ has: page.getByText(name, { exact: true }) }).getByRole('checkbox')).not.toBeChecked();
    } finally {
      await deleteProjectViaUi(page, project);
      remove(name);
    }
    expect(errors).toEqual([]);
  });

  test('the list command shows the entry and the catalogue is ordered by name on the Ports tab', async ({ page }) => {
    const project = uniqueName();
    const first = `np-a-${uniqueName('x').slice(-8)}`;
    const second = `np-b-${uniqueName('x').slice(-8)}`;
    const a = randomPorts();
    const b = randomPorts();
    try {
      // Created in reverse order on purpose.
      expectCreated(second, b.pub, b.router);
      expectCreated(first, a.pub, a.router);
      const listing = consoleRun(['fleet:port:list']);
      expect(listing.status).toBe(0);
      expect(listing.output).toContain(first);
      expect(listing.output).toContain(String(a.pub));
      expect(listing.output).toContain(String(b.router));

      await createProjectViaUi(page, project);
      await openPorts(page, project);
      const names = await page.getByTestId('port-row').locator('td:nth-child(2)').allTextContents();
      expect(names.indexOf(first)).toBeGreaterThanOrEqual(0);
      expect(names.indexOf(first)).toBeLessThan(names.indexOf(second));
    } finally {
      await deleteProjectViaUi(page, project);
      remove(first);
      remove(second);
    }
  });

  test('a long port name wraps on the Ports tab without widening the page', async ({ page }) => {
    const project = uniqueName();
    const name = `n${'o'.repeat(38)}p`;
    const { pub, router } = randomPorts();
    try {
      expectCreated(name, pub, router);
      await createProjectViaUi(page, project);
      await openPorts(page, project);
      await expect(page.getByTestId('port-row').filter({ has: page.getByText(name, { exact: true }) })).toHaveCount(1);
      expect(await hasHorizontalOverflow(page), 'overflow with a 40 character port name').toBe(false);
      await expectNoBlockingViolations(page, 'Ports tab with a long port name');
    } finally {
      await deleteProjectViaUi(page, project);
      remove(name);
    }
  });

  test('delete is refused while a project uses the port and works once it is unassigned; the tab follows', async ({ page }) => {
    const project = uniqueName();
    const name = portName();
    const { pub, router } = randomPorts();
    try {
      expectCreated(name, pub, router);
      await createProjectViaUi(page, project);
      await openPorts(page, project);
      await page.getByTestId('port-row').filter({ has: page.getByText(name, { exact: true }) }).getByRole('checkbox').check();
      await submitAndWait(page, page.getByRole('button', { name: 'Save ports' }));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');

      const refused = remove(name);
      expect(refused.status).not.toBe(0);
      expect(refused.output).toContain(`Used by project(s): ${project}.`);
      await openPorts(page, project);
      await expect(page.getByTestId('port-row').filter({ has: page.getByText(name, { exact: true }) })).toHaveCount(1);

      await page.getByTestId('port-row').filter({ has: page.getByText(name, { exact: true }) }).getByRole('checkbox').uncheck();
      await submitAndWait(page, page.getByRole('button', { name: 'Save ports' }));
      await expect(page.getByTestId('flashes')).toContainText('Ports saved.');
      const done = remove(name);
      expect(done.output).toContain(`Named port "${name}" removed.`);
      expect(done.status).toBe(0);
      await openPorts(page, project);
      await expect(page.getByText(name, { exact: true })).toHaveCount(0);
    } finally {
      await deleteProjectViaUi(page, project);
      remove(name);
    }
  });

  test('deleting a project releases its port assignment so the port can be deleted', async ({ page }) => {
    const project = uniqueName();
    const name = portName();
    const { pub, router } = randomPorts();
    try {
      expectCreated(name, pub, router);
      await createProjectViaUi(page, project);
      await openPorts(page, project);
      await page.getByTestId('port-row').filter({ has: page.getByText(name, { exact: true }) }).getByRole('checkbox').check();
      await submitAndWait(page, page.getByRole('button', { name: 'Save ports' }));
      expect(remove(name).status).not.toBe(0);
    } finally {
      await deleteProjectViaUi(page, project);
    }
    expect(remove(name).status).toBe(0);
  });

  test('a port the catalogue does not know is refused on the Ports tab with the 422 message and nothing changes', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await openPorts(page, project);
      // A stale form: the port was removed from the catalogue after the page was opened.
      await page.getByTestId('ports-form').evaluate((form) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ports[]';
        input.value = 'ghost-port';
        form.append(input);
      });
      await submitAndWait(page, page.getByRole('button', { name: 'Save ports' }));
      await expect(page.getByTestId('ports-errors')).toContainText('Unknown port(s): ghost-port.');
      await expect(page.getByTestId('flashes')).toHaveCount(0);
      await expect(page.getByTestId('port-checkbox').first()).toHaveAttribute('aria-invalid', 'true');
      expect(await hasHorizontalOverflow(page)).toBe(false);
      await expectNoBlockingViolations(page, 'Ports tab with an error');
      // The stored assignment is unchanged.
      await openPorts(page, project);
      await expect(page.getByTestId('ports-errors')).toHaveCount(0);
      await expect(page.getByRole('checkbox', { checked: true })).toHaveCount(0);
    } finally {
      await deleteProjectViaUi(page, project);
    }
    expect(errors.filter((error) => !error.includes('status of 422'))).toEqual([]);
  });
});

test.describe('named-port catalogue, validation messages', () => {
  // Commands only, no browser: the phone project would repeat the same calls.
  test.beforeEach(() => {
    test.skip(test.info().project.name === 'phone', 'No page involved: desktop covers it.');
  });

  const NAME_RULE = 'Use lowercase letters, digits and single dashes only, starting and ending with a letter or digit.';

  const FIELD_CASES: Array<{ title: string; name?: string; pub?: string; router?: string; message: string }> = [
    { title: 'an uppercase name', name: 'Bad', message: `<name>: ${NAME_RULE}` },
    { title: 'a name with an underscore', name: 'a_b', message: `<name>: ${NAME_RULE}` },
    { title: 'a name with two dashes', name: 'a--b', message: '<name>: The name must not contain two dashes in a row' },
    { title: 'a name of 41 characters', name: 'a'.repeat(41), message: '<name>: The name must be at most 40 characters.' },
    { title: 'public port 0', pub: '0', message: `--public: ${RANGE}` },
    { title: 'public port 65536', pub: '65536', message: `--public: ${RANGE}` },
    { title: 'a public port that is not a number', pub: 'abc', message: `--public: ${RANGE}` },
    { title: 'a negative public port', pub: '-5', message: `--public: ${RANGE}` },
    { title: 'a decimal public port', pub: '12.5', message: `--public: ${RANGE}` },
    { title: 'router port 0', router: '0', message: `--router: ${RANGE}` },
    { title: 'router port 70000', router: '70000', message: `--router: ${RANGE}` },
    { title: 'a router port that is not a number', router: 'x1', message: `--router: ${RANGE}` },
    ...[22, 80, 443, 8765, 2019, 3000, 9091, 8080, 8443].map((port) => ({ title: `reserved public port ${port}`, pub: String(port), message: `--public: ${RESERVED}` })),
    ...[22, 80, 443, 8765, 2019, 3000, 9091, 8080, 8443].map((port) => ({ title: `reserved router port ${port}`, router: String(port), message: `--router: ${RESERVED}` })),
  ];

  for (const { title, name, pub, router, message } of FIELD_CASES) {
    test(`refuses ${title}`, async () => {
      const ports = randomPorts();
      const given = name ?? portName();
      const result = create(given, pub ?? ports.pub, router ?? ports.router);
      expect(result.status).not.toBe(0);
      expect(result.output).toContain(message);
    });
  }

  test('router equal to public, a taken name and every number collision are refused', async () => {
    const name = portName();
    const other = portName();
    const a = randomPorts();
    const b = randomPorts();
    try {
      const same = create(other, a.pub, a.pub);
      expect(same.status).not.toBe(0);
      expect(same.output).toContain('--router: The router port must differ from the public port.');

      expectCreated(name, a.pub, a.router);
      const duplicateName = create(name, b.pub, b.router);
      expect(duplicateName.status).not.toBe(0);
      expect(duplicateName.output).toContain('<name>: A port with this name already exists.');

      const duplicatePublic = create(other, a.pub, b.router);
      expect(duplicatePublic.output).toContain(`--public: ${TAKEN}`);
      const duplicateRouter = create(other, b.pub, a.router);
      expect(duplicateRouter.output).toContain(`--router: ${TAKEN}`);
      // A public port may not be another entry's router port, and the other way round.
      const publicIsRouter = create(other, a.router, b.router);
      expect(publicIsRouter.output).toContain(`--public: ${TAKEN}`);
      const routerIsPublic = create(other, b.pub, a.pub);
      expect(routerIsPublic.output).toContain(`--router: ${TAKEN}`);

      // All three at once: a bad name does not hide the number messages.
      const all = create('Bad Name', 0, 0);
      expect(all.output).toContain(`<name>: ${NAME_RULE}`);
      expect(all.output).toContain(`--public: ${RANGE}`);
      expect(all.output).toContain(`--router: ${RANGE}`);

      expect(consoleRun(['fleet:port:list']).output).not.toContain(other);
    } finally {
      remove(name);
      remove(other);
    }
  });

  test('missing options and an unknown port on delete give a message, not a crash', async () => {
    const missing = consoleRun(['fleet:port:create', portName()]);
    expect(missing.status).not.toBe(0);
    expect(missing.output).not.toContain('Exception');
    const absent = remove('no-such-port-here');
    expect(absent.status).not.toBe(0);
    expect(absent.output).toContain('Named port "no-such-port-here" does not exist.');
  });

  test('the boundary ports 1 and 65535 are accepted', async () => {
    const low = portName();
    const high = portName();
    try {
      // 1 and 65535 are valid numbers unless another run holds them; free them first on a clean catalogue only.
      const first = create(low, 1, 65535);
      if (first.status === 0) {
        expect(first.output).toContain(`Named port "${low}" created (public 1, router 65535).`);
      } else {
        expect(first.output).toContain(TAKEN);
      }
      const second = create(high, 65534, 2);
      if (second.status !== 0) {
        expect(second.output).toContain(TAKEN);
      }
    } finally {
      remove(low);
      remove(high);
    }
  });
});
