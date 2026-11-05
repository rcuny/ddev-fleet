import { expect, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { openHostTab, skipBrowserValidation, test } from './support/host';
import { createProjectViaUi, deleteProjectViaUi, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { createTemplateViaUi } from './support/templates';

// Capacity in the web UI (FLE-203): the host page's Capacity tab, and the CPU threads / Slots fields of the project and
// template forms. Exact figures (48 threads, 47 of 46 committed, the saved values) are pinned in
// app/tests/Functional/Host/HostCapacityPageTest.php with a fake cpuinfo; here the host is the real machine, so the specs read
// its numbers from the page. Desktop and phone, axe on the states a user reaches.

const isPhone = (): boolean => test.info().project.name === 'phone';

async function number(page: Page, testId: string): Promise<number> {
  return Number.parseInt(((await page.getByTestId(testId).textContent()) ?? '').trim(), 10);
}

test.describe('capacity', () => {
  test('the Capacity tab shows the host, allotments and instances; no overflow, 44 px targets, axe @phone', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    await openHostTab(page, 'capacity');

    await expect(page.locator('h1')).toHaveCount(1);
    for (const id of ['capacity-threads', 'capacity-cores', 'capacity-ram', 'capacity-allocatable', 'capacity-reserve', 'capacity-committed', 'capacity-instances', 'capacity-jobs']) {
      await expect(page.getByTestId(id)).toBeVisible();
    }
    const threads = await number(page, 'capacity-threads');
    const reserve = await number(page, 'capacity-reserve');
    expect(await number(page, 'capacity-allocatable')).toBe(Math.max(0, threads - reserve));
    await expect(page.getByTestId('capacity-unsliced')).toBeVisible();
    await expect(page.getByText('Allotments', { exact: true })).toBeVisible();

    expect(await hasHorizontalOverflow(page)).toBe(false);
    if (isPhone()) {
      const controls = page.getByTestId('host-tab-capacity').locator('button[type=submit], input:not([type=hidden]):not([type=checkbox]), a[href]');
      for (const control of await controls.all()) {
        const box = await control.boundingBox();
        expect(box, await control.evaluate((el) => el.outerHTML.slice(0, 80))).not.toBeNull();
        expect(box!.height).toBeGreaterThanOrEqual(43.5);
      }
    }
    await expectNoBlockingViolations(page, 'Capacity tab');
    expect(errors).toEqual([]);
  });

  test('two projects each allotted the whole host trigger the overcommit warning, listed with their templates @phone', async ({ page }) => {
    const first = uniqueName('cap');
    const second = uniqueName('cap');
    try {
      await openHostTab(page, 'capacity');
      const allocatable = await number(page, 'capacity-allocatable');
      test.skip(allocatable < 1, 'a host without allocatable threads cannot be allotted');

      for (const name of [first, second]) {
        await createProjectViaUi(page, name);
        await page.getByLabel('CPU threads').fill(String(allocatable));
        await page.getByLabel('Slots').fill('2');
        await submitAndWait(page, page.getByRole('button', { name: 'Save' }));
        await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      }
      await createTemplateViaUi(page, first, { name: 'gates' });

      await openHostTab(page, 'capacity');
      await expect(page.getByTestId('capacity-overcommit')).toContainText('Overcommitted');
      const row = page.locator(`[data-testid=capacity-project-row][data-project=${first}]`);
      await expect(row).toContainText(String(allocatable));
      await expect(page.locator(`[data-testid=capacity-template-row][data-project=${first}][data-template=gates]`)).toContainText('(inherited)');
      expect(await hasHorizontalOverflow(page)).toBe(false);
      await expectNoBlockingViolations(page, 'Capacity tab with an overcommit');
    } finally {
      for (const name of [first, second]) {
        await deleteProjectViaUi(page, name).catch(() => undefined);
      }
    }
  });

  test('the reserve and the instance cap are saved, and refused out of range @phone', async ({ page, hostLock }) => {
    void hostLock;
    const tab = await openHostTab(page, 'capacity');
    const reserve = tab.getByLabel('Reserved threads');
    const max = tab.getByLabel('Maximum instances');
    const before = { reserve: await reserve.inputValue(), max: await max.inputValue() };

    await skipBrowserValidation(max);
    await max.fill('0');
    await submitAndWait(page, tab.getByRole('button', { name: 'Save', exact: true }));
    await expect(page.getByTestId('host-tab-capacity')).toContainText('Enter a number from 1 to');
    await expectNoBlockingViolations(page, 'Capacity tab with an error');

    try {
      const again = await openHostTab(page, 'capacity');
      await again.getByLabel('Maximum instances').fill('31');
      await submitAndWait(page, again.getByRole('button', { name: 'Save', exact: true }));
      await expect(page.getByTestId('flashes')).toContainText('Capacity settings saved.');
      await expect(page.getByTestId('capacity-instances')).toContainText('/ 31');
    } finally {
      const restore = await openHostTab(page, 'capacity');
      await restore.getByLabel('Reserved threads').fill(before.reserve);
      await restore.getByLabel('Maximum instances').fill(before.max);
      await submitAndWait(page, restore.getByRole('button', { name: 'Save', exact: true }));
    }
  });

  test('the project and template forms take CPU threads and slots and render the refusals @phone', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const name = uniqueName('cap');
    try {
      await createProjectViaUi(page, name);
      const fields = page.getByTestId('project-capacity-fields');
      await expect(fields).toContainText('This host can allocate');
      await page.getByLabel('CPU threads').fill('0');
      await submitAndWait(page, page.getByRole('button', { name: 'Save' }));
      await expect(fields).toContainText('Enter a whole number of at least 1, or leave it empty.');
      await expectNoBlockingViolations(page, 'project form with a capacity error');

      await page.getByLabel('CPU threads').fill('4');
      await page.getByLabel('Slots').fill('2');
      await submitAndWait(page, page.getByRole('button', { name: 'Save' }));
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      await expect(page.getByLabel('CPU threads')).toHaveValue('4');

      // Template: inherits the project's 4; above it is refused; an explicit value within it is kept.
      await page.goto(`/admin/projects/${name}/templates/new`);
      const templateFields = page.getByTestId('template-capacity-fields');
      await expect(templateFields).toContainText('inherit the project: 4');
      await page.getByLabel('Name', { exact: true }).fill('big');
      await page.getByLabel('CPU threads').fill('5');
      await page.getByLabel('Slots').fill('3');
      await submitAndWait(page, page.getByRole('button', { name: 'Create template' }));
      await expect(templateFields).toContainText('CPU threads');
      await expect(templateFields.locator('[class*=destructive], [role=alert], ul li').first()).toBeVisible();
      await expectNoBlockingViolations(page, 'template form with capacity errors');
      expect(await hasHorizontalOverflow(page)).toBe(false);

      await page.getByLabel('CPU threads').fill('3');
      await page.getByLabel('Slots').fill('1');
      await submitAndWait(page, page.getByRole('button', { name: 'Create template' }));
      await expect(page.getByTestId('flashes')).toContainText('Template created.');
      await page.goto(`/admin/projects/${name}/templates/big`);
      await expect(page.getByLabel('CPU threads')).toHaveValue('3');
      await expect(page.getByLabel('Slots')).toHaveValue('1');

      // The project may not drop below the template's explicit value.
      await page.goto(`/admin/projects/${name}`);
      await page.getByLabel('CPU threads').fill('2');
      await submitAndWait(page, page.getByRole('button', { name: 'Save' }));
      await expect(page.getByTestId('project-capacity-fields')).toContainText('big');
    } finally {
      await deleteProjectViaUi(page, name).catch(() => undefined);
    }
    // The refusals are 422 responses on purpose.
    expect(errors.filter((message) => !message.includes('422'))).toEqual([]);
  });
});
