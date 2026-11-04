import { expect, test, type Locator, type Page } from '@playwright/test';
import { consoleOk, instanceRow, runConsole } from './support/fleetData';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// Browser-only coverage of /admin/schedules at 1280 px (desktop project) and 390 px (phone project): form layout and tap targets,
// the delete dialog (Cancel, Escape) and long values on a phone. Forms, messages, cron/timezone, run/skip/history, CSRF and
// the member role are asserted in app/tests/Functional/Admin/SchedulePagesTest.php. schedules.spec.ts keeps the one-walk smoke test.
//
// Every test owns a project, an instance row and uniquely named schedules, set up through the console (fast) and removed in
// `finally`, so the specs are independent and repeatable on the shared database. The pages are what is asserted.

const isPhone = (): boolean => test.info().project.name === 'phone';

const MSG = {
  instanceEmpty: 'Enter the id of the instance to redeploy.',
} as const;

interface Fixture {
  project: string;
  instance: string;
  names: string[];
}

/** A project with one instance row; `names` collects the schedules the test makes, for the cleanup. */
async function fixture(page: Page): Promise<Fixture> {
  const project = uniqueName('sc');
  await createProjectViaUi(page, project);
  instanceRow('insert', project, 'main');
  return { project, instance: `${project}--main`, names: [] };
}

async function cleanup(page: Page, fx: Fixture): Promise<void> {
  for (const name of fx.names) {
    // Best effort: the test may have deleted the schedule itself or failed before it existed.
    runConsole(['fleet:schedule:delete', name, '--yes']);
  }
  instanceRow('delete', fx.project, 'main');
  instanceRow('delete', fx.project, 'other');
  await deleteProjectViaUi(page, fx.project);
}

/** Creates a schedule through the console (the redeploy action) and remembers it for the cleanup. */
function makeSchedule(fx: Fixture, name: string, cron = '0 3 * * *', timezone = 'UTC', instance = fx.instance): void {
  consoleOk(['fleet:schedule:create', name, `--cron=${cron}`, `--timezone=${timezone}`, `--instance=${instance}`]);
  fx.names.push(name);
}

const row = (page: Page, name: string): Locator => page.locator(`[data-testid=schedule-row][data-name=${name}]`);

async function expectTall(target: Locator, min = 43.5, what = 'element'): Promise<void> {
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box, 'element has a box').not.toBeNull();
  expect(box!.height, what).toBeGreaterThanOrEqual(min);
}

async function expectClean(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

async function fillForm(page: Page, values: { name?: string; cron?: string; timezone?: string; instance?: string }): Promise<void> {
  if (values.name !== undefined) {
    await page.getByLabel('Name', { exact: true }).fill(values.name);
  }
  if (values.cron !== undefined) {
    await page.getByLabel('Cron expression').fill(values.cron);
  }
  if (values.timezone !== undefined) {
    await page.getByLabel('Timezone').fill(values.timezone);
  }
  if (values.instance !== undefined) {
    await page.getByLabel('Instance').fill(values.instance);
  }
}

const field = (page: Page, label: string): Locator => page.getByLabel(label, { exact: label === 'Name' });

test.describe('schedules, full coverage', () => {
  test('the new-schedule form and its errors fit the screen, the controls are full-size targets and Cancel leaves without saving @phone', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const fx = await fixture(page);
    try {
      await page.goto('/admin/schedules');
      await follow(page, page.getByTestId('schedule-new'));
      await expect(page.getByRole('heading', { level: 1, name: 'New schedule' })).toBeVisible();
      await expectClean(page, 'the empty form');
      if (isPhone()) {
        for (const label of ['Name', 'Cron expression', 'Timezone', 'Instance', 'Action']) {
          await expectTall(field(page, label), 43.5, label);
        }
        await expectTall(page.getByTestId('schedule-save'));
        await expectTall(page.getByRole('link', { name: 'Cancel' }));
      }

      // Every required field refused at once (the messages are asserted in SchedulePagesTest).
      await field(page, 'Timezone').fill('');
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.locator('[aria-invalid=true]')).toHaveCount(4);
      await expectClean(page, 'the form with four errors');

      // Cancel leaves the form without saving (the refusal re-rendered the form, so go back to the list first).
      await page.goto('/admin/schedules');
      await follow(page, page.getByTestId('schedule-new'));
      await fillForm(page, { name: 'cancelled', cron: '0 3 * * *', instance: fx.instance });
      await follow(page, page.getByRole('link', { name: 'Cancel' }));
      await expect(page).toHaveURL(/\/admin\/schedules$/);
      await expect(row(page, 'cancelled')).toHaveCount(0);
      // The 422 answer of the refusal is the only expected console error.
      expect(errors.filter((e) => !e.includes('status of 422'))).toEqual([]);
    } finally {
      await cleanup(page, fx);
    }
  });

  test('deleting asks first, can be cancelled, removes the history and a second delete finds nothing @phone', async ({ page }) => {
    test.setTimeout(180_000);
    const fx = await fixture(page);
    const name = uniqueName('doomed');
    const keep = uniqueName('keep');
    try {
      makeSchedule(fx, name);
      makeSchedule(fx, keep, '0 5 * * *');
      await page.goto('/admin/schedules');
      await submitAndWait(page, row(page, name).getByTestId('schedule-run'));
      await expect(page.getByTestId('flashes')).toContainText('started job #');

      // The dialog names the schedule and says what goes; Cancel and Escape both leave it alone.
      let dialog = await openDialog(page, row(page, name).getByTestId('schedule-delete'));
      await expect(dialog.getByRole('heading', { name: `Delete ${name}?` })).toBeVisible();
      await expect(dialog).toContainText('The schedule and its run history are removed. Jobs already started are not affected.');
      await expectClean(page, 'the delete dialog');
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(page.getByRole('dialog')).toHaveCount(0);
      await expect(row(page, name)).toHaveCount(1);
      dialog = await openDialog(page, row(page, name).getByTestId('schedule-delete'));
      await page.keyboard.press('Escape');
      await expect(page.getByRole('dialog')).toHaveCount(0);
      await expect(row(page, name)).toHaveCount(1);
      if (isPhone()) {
        dialog = await openDialog(page, row(page, name).getByTestId('schedule-delete'));
        await expectTall(dialog.getByTestId('schedule-delete-confirm'));
        await expectTall(dialog.getByRole('button', { name: 'Cancel' }));
        expect(await hasHorizontalOverflow(page), 'overflow with the dialog open').toBe(false);
        await page.keyboard.press('Escape');
      }

      // Three more tabs still show the schedule: after the delete each of its actions answers 404.
      const tabs: Record<string, Page> = {};
      for (const testId of ['schedule-pause', 'schedule-run', 'schedule-delete']) {
        tabs[testId] = await page.context().newPage();
        await tabs[testId].goto('/admin/schedules');
      }

      dialog = await openDialog(page, row(page, name).getByTestId('schedule-delete'));
      await dialog.getByTestId('schedule-delete-confirm').click();
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" deleted.`);
      await expect(row(page, name)).toHaveCount(0);
      await expect(row(page, keep)).toHaveCount(1);
      expect((await page.goto(`/admin/schedules/${name}/history`))?.status()).toBe(404);
      expect((await page.goto(`/admin/schedules/${name}/edit`))?.status()).toBe(404);

      const sel = (tab: Page, testId: string): Locator => tab.locator(`[data-testid=schedule-row][data-name=${name}] [data-testid=${testId}]`);
      const posted = (tab: Page): Promise<number> => tab.waitForResponse((r) => r.request().method() === 'POST').then((r) => r.status());
      for (const testId of ['schedule-pause', 'schedule-run']) {
        const [status] = await Promise.all([posted(tabs[testId]), sel(tabs[testId], testId).click()]);
        expect(status, testId).toBe(404);
      }
      // The stale delete form sits in a dialog; it posts the same way.
      const staleDialog = await openDialog(tabs['schedule-delete'], sel(tabs['schedule-delete'], 'schedule-delete'));
      const [status] = await Promise.all([posted(tabs['schedule-delete']), staleDialog.getByTestId('schedule-delete-confirm').click()]);
      expect(status).toBe(404);
      for (const tab of Object.values(tabs)) {
        await tab.close();
      }
      fx.names.splice(fx.names.indexOf(name), 1);
    } finally {
      await cleanup(page, fx);
    }
  });

  test('long names, expressions and instance ids wrap on every page and every control is a full-size target @phone', async ({ page }) => {
    test.setTimeout(180_000);
    const fx = await fixture(page);
    const longName = uniqueName('l').padEnd(40, 'x');
    // As long as the instance id allows: 59 characters (FLE-144), so the label takes what the project name leaves.
    const longLabel = 'y'.repeat(59 - fx.project.length - 2);
    try {
      expect(longName.length).toBe(40);
      instanceRow('insert', fx.project, longLabel);
      const longInstance = `${fx.project}--${longLabel}`;
      expect(longInstance.length).toBe(59);
      const longCron = `${Array.from({ length: 28 }, (_, i) => i).join(',')} * * * *`;
      expect(longCron.length).toBeLessThanOrEqual(100);
      makeSchedule(fx, longName, longCron, 'America/Argentina/Buenos_Aires', longInstance);
      const other = uniqueName('wide');
      makeSchedule(fx, other, '0 3 * * *');

      await page.goto('/admin/schedules');
      await expect(row(page, longName)).toContainText(longInstance);
      await expectClean(page, 'the list with long values');
      if (isPhone()) {
        for (const id of ['schedule-pause', 'schedule-run', 'schedule-edit', 'schedule-history', 'schedule-delete']) {
          await expectTall(row(page, longName).getByTestId(id));
        }
        await expectTall(page.getByTestId('schedule-new'));
      }
      await submitAndWait(page, row(page, longName).getByTestId('schedule-pause'));
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${longName}" paused.`);
      await expectClean(page, 'the list with a paused long schedule');
      if (isPhone()) {
        await expectTall(row(page, longName).getByTestId('schedule-resume'));
      }
      await submitAndWait(page, row(page, longName).getByTestId('schedule-run'));
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${longName}" started job #`);
      await expectClean(page, 'the flash of a long name');

      await follow(page, row(page, longName).getByTestId('schedule-history'));
      await expectClean(page, 'the history of a long name');
      await follow(page, page.getByRole('link', { name: 'Schedules' }).last());
      await follow(page, row(page, longName).getByTestId('schedule-edit'));
      await expect(page.getByLabel('Instance')).toHaveValue(longInstance);
      await expectClean(page, 'the edit form with long values');

      // A refusal with the long values typed back, and the delete dialog titled with the long name.
      await fillForm(page, { instance: '' });
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('error-instance')).toHaveText(MSG.instanceEmpty);
      await expectClean(page, 'the edit form with a long refused value');
      await page.goto('/admin/schedules');
      const dialog = await openDialog(page, row(page, longName).getByTestId('schedule-delete'));
      await expect(dialog.getByRole('heading', { name: `Delete ${longName}?` })).toBeVisible();
      expect(await hasHorizontalOverflow(page), 'overflow with the dialog open').toBe(false);
      await page.keyboard.press('Escape');
    } finally {
      instanceRow('delete', fx.project, longLabel);
      await cleanup(page, fx);
    }
  });
});
