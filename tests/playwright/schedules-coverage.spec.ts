import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { consoleOk, instanceRow, runConsole } from './support/fleetData';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// Full coverage of /admin/schedules at 1280 px (desktop project) and 390 px (phone project): every field of the form and
// each message the services can return, create / edit / pause / resume / run now / history / delete, the outcomes of a run
// that cannot start, stale tabs, CSRF, the member role, long values on a phone and axe in both themes. schedules.spec.ts
// keeps the one-walk smoke test; this file is the exhaustive one.
//
// Every test owns a project, an instance row and uniquely named schedules, set up through the console (fast) and removed in
// `finally`, so the specs are independent and repeatable on the shared database. The pages are what is asserted.

const isPhone = (): boolean => test.info().project.name === 'phone';

const MSG = {
  name: 'Enter a name.',
  nameShape: 'Use lowercase letters, digits and single dashes only, starting and ending with a letter or digit.',
  nameDashes: 'The name must not contain two dashes in a row ("--" separates the project from the instance label).',
  nameReserved: 'This name is reserved.',
  nameLong: 'The name must be at most 40 characters.',
  nameTaken: 'A schedule with this name already exists.',
  cronEmpty: 'Enter a cron expression with five fields, for example "0 3 * * *".',
  cronBad: 'Not a valid cron expression: use five fields (minute hour day-of-month month day-of-week), for example "0 3 * * *".',
  zone: 'Enter an IANA timezone such as "Europe/Brussels" or "UTC".',
  instanceEmpty: 'Enter the id of the instance to redeploy.',
  instanceMissing: 'This instance does not exist.',
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
  await expectNoBlockingViolations(page, where);
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
  test('the new-schedule form refuses every bad value with its own message and keeps what was typed', async ({ page }) => {
    test.setTimeout(240_000);
    const errors = collectBrowserErrors(page);
    const fx = await fixture(page);
    const taken = uniqueName('taken');
    try {
      makeSchedule(fx, taken);
      await page.goto('/admin/schedules');
      await follow(page, page.getByTestId('schedule-new'));
      await expect(page.getByRole('heading', { level: 1, name: 'New schedule' })).toBeVisible();
      await expect(page).toHaveTitle(/New schedule/);

      // The fresh form: UTC and redeploy preselected, the one action offered, nothing marked invalid.
      await expect(field(page, 'Timezone')).toHaveValue('UTC');
      await expect(page.getByLabel('Action')).toHaveValue('redeploy');
      await expect(page.getByLabel('Action').locator('option')).toHaveText(['redeploy']);
      await expect(page.locator('[aria-invalid=true]')).toHaveCount(0);
      expect(await page.locator('#schedule-timezones option').count(), 'timezone suggestions').toBeGreaterThan(300);
      await expect(page.locator('#schedule-timezones option[value="Europe/Paris"]')).toHaveCount(1);
      await expect(page.getByLabel('Name', { exact: true })).toHaveAttribute('maxlength', '64');
      await expectClean(page, 'the empty form');
      if (isPhone()) {
        for (const label of ['Name', 'Cron expression', 'Timezone', 'Instance', 'Action']) {
          await expectTall(field(page, label), 43.5, label);
        }
        await expectTall(page.getByTestId('schedule-save'));
        await expectTall(page.getByRole('link', { name: 'Cancel' }));
      }

      // Nothing at all: the three required fields speak, the preselected ones do not.
      await field(page, 'Timezone').fill('');
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('error-name')).toHaveText(MSG.name);
      await expect(page.getByTestId('error-cron')).toHaveText(MSG.cronEmpty);
      await expect(page.getByTestId('error-timezone')).toHaveText(MSG.zone);
      await expect(page.getByTestId('error-instance')).toHaveText(MSG.instanceEmpty);
      await expect(page.locator('[aria-invalid=true]')).toHaveCount(4);
      await expect(page.getByLabel('Name', { exact: true })).toHaveAttribute('aria-describedby', 'schedule-name-error');
      await expectClean(page, 'the form with four errors');

      // Name rules, one at a time (the other fields valid, so only the name can speak).
      const names: Array<[string, string]> = [
        ['Nightly', MSG.nameShape],
        ['night_ly', MSG.nameShape],
        ['-night', MSG.nameShape],
        ['night-', MSG.nameShape],
        ['night--ly', MSG.nameDashes],
        ['new', MSG.nameReserved],
        ['n'.repeat(41), MSG.nameLong],
        [taken, MSG.nameTaken],
      ];
      for (const [name, message] of names) {
        await fillForm(page, { name, cron: '0 3 * * *', timezone: 'UTC', instance: fx.instance });
        await submitAndWait(page, page.getByTestId('schedule-save'));
        await expect(page.getByTestId('error-name'), `name "${name}"`).toHaveText(message);
        await expect(page.getByTestId('error-cron')).toHaveCount(0);
        await expect(page.getByTestId('error-timezone')).toHaveCount(0);
        await expect(page.getByTestId('error-instance')).toHaveCount(0);
        // What was typed is still in the field.
        await expect(page.getByLabel('Name', { exact: true })).toHaveValue(name);
      }

      // Cron rules: wrong field count, aliases, out-of-range values, an expression over 100 characters.
      const longCron = `${Array.from({ length: 40 }, (_, i) => i).join(',')} * * * *`;
      expect(longCron.length).toBeGreaterThan(100);
      const crons: Array<[string, string]> = [
        ['   ', MSG.cronEmpty],
        ['0 3 * *', MSG.cronBad],
        ['0 3 * * * *', MSG.cronBad],
        ['@daily', MSG.cronBad],
        ['61 * * * *', MSG.cronBad],
        ['* 25 * * *', MSG.cronBad],
        ['* * 32 * *', MSG.cronBad],
        ['* * * 13 *', MSG.cronBad],
        ['* * * * 8', MSG.cronBad],
        ['not a cron', MSG.cronBad],
        [longCron, MSG.cronBad],
      ];
      for (const [cron, message] of crons) {
        await fillForm(page, { name: 'cron-check', cron, timezone: 'UTC', instance: fx.instance });
        await submitAndWait(page, page.getByTestId('schedule-save'));
        await expect(page.getByTestId('error-cron'), `cron "${cron.slice(0, 30)}"`).toHaveText(message);
        await expect(page.getByTestId('error-name')).toHaveCount(0);
        await expect(page.getByLabel('Cron expression')).toHaveValue(cron);
      }

      // Timezone rules: unknown, wrong case, blank.
      for (const zone of ['Mars/Base', 'europe/paris', 'Europe/Atlantis', '   ']) {
        await fillForm(page, { name: 'zone-check', cron: '0 3 * * *', timezone: zone, instance: fx.instance });
        await submitAndWait(page, page.getByTestId('schedule-save'));
        await expect(page.getByTestId('error-timezone'), `timezone "${zone}"`).toHaveText(MSG.zone);
        await expect(page.getByTestId('error-cron')).toHaveCount(0);
      }

      // Instance rules: empty, unknown, an instance of nobody.
      for (const [instance, message] of [
        ['', MSG.instanceEmpty],
        ['   ', MSG.instanceEmpty],
        [`${fx.project}--nope`, MSG.instanceMissing],
        ['nope', MSG.instanceMissing],
      ] as const) {
        await fillForm(page, { name: 'instance-check', cron: '0 3 * * *', timezone: 'UTC', instance });
        await submitAndWait(page, page.getByTestId('schedule-save'));
        await expect(page.getByTestId('error-instance'), `instance "${instance}"`).toHaveText(message);
        await expect(page.getByTestId('error-timezone')).toHaveCount(0);
      }
      await expectClean(page, 'the form with an instance error');

      // Nothing was created by any of the refusals.
      await page.goto('/admin/schedules');
      for (const name of ['cron-check', 'zone-check', 'instance-check', 'night--ly']) {
        await expect(row(page, name)).toHaveCount(0);
      }

      // Cancel leaves the form without saving.
      await follow(page, page.getByTestId('schedule-new'));
      await fillForm(page, { name: 'cancelled', cron: '0 3 * * *', instance: fx.instance });
      await follow(page, page.getByRole('link', { name: 'Cancel' }));
      await expect(page).toHaveURL(/\/admin\/schedules$/);
      await expect(row(page, 'cancelled')).toHaveCount(0);

      // One field wrong among valid ones: the error is the only one, and the page then accepts the fixed value, padded
      // with blanks (trimmed on save).
      await follow(page, page.getByTestId('schedule-new'));
      const name = uniqueName('padded');
      fx.names.push(name);
      await fillForm(page, { name: `  ${name}  `, cron: '  0 3 * * *  ', timezone: ' UTC ', instance: ` ${fx.instance} ` });
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" created.`);
      await expect(row(page, name).getByTestId('schedule-cron')).toHaveText('0 3 * * *');
      // The 422 answers of the refusals are the only expected console errors.
      expect(errors.filter((e) => !e.includes('status of 422'))).toEqual([]);
    } finally {
      await cleanup(page, fx);
    }
  });

  test('a schedule is created with every field and its next run follows the cron expression and the timezone', async ({ page }) => {
    test.setTimeout(180_000);
    const fx = await fixture(page);
    const kolkata = uniqueName('kolkata');
    const quarter = uniqueName('quarter');
    const leap = uniqueName('leap');
    try {
      await page.goto('/admin/schedules');
      await follow(page, page.getByTestId('schedule-new'));
      await expect(page.getByTestId('schedule-actions')).toHaveText('Available actions: redeploy.');

      // 02:30 in India (UTC+5:30, no daylight saving) is 21:00 UTC of the previous day.
      fx.names.push(kolkata);
      await fillForm(page, { name: kolkata, cron: '30 2 * * *', timezone: 'Asia/Kolkata', instance: fx.instance });
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${kolkata}" created.`);
      const created = row(page, kolkata);
      await expect(created).toHaveCount(1);
      await expect(created.getByTestId('schedule-name')).toHaveText(kolkata);
      await expect(created.getByTestId('schedule-state')).toHaveText('Active');
      await expect(created.getByTestId('schedule-cron')).toHaveText('30 2 * * *');
      await expect(created).toContainText(`Asia/Kolkata · redeploy · ${fx.instance}`);
      await expect(created.getByTestId('schedule-next')).toHaveText(/^\d{4}-\d{2}-\d{2} 21:00 UTC$/);
      await expect(created.getByTestId('schedule-last')).toHaveText('never run');
      await expect(created.getByTestId('schedule-last-job')).toHaveCount(0);
      // All six controls of an active schedule are there; Resume is not.
      for (const id of ['schedule-pause', 'schedule-run', 'schedule-edit', 'schedule-history', 'schedule-delete']) {
        await expect(created.getByTestId(id)).toBeVisible();
      }
      await expect(created.getByTestId('schedule-resume')).toHaveCount(0);

      // A step, a range and weekday names; and a date that only exists every fourth year.
      fx.names.push(quarter);
      await follow(page, page.getByTestId('schedule-new'));
      await fillForm(page, { name: quarter, cron: '*/15 8-18 * * MON-FRI', timezone: 'UTC', instance: fx.instance });
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${quarter}" created.`);
      await expect(row(page, quarter).getByTestId('schedule-next')).toHaveText(/^\d{4}-\d{2}-\d{2} (0[89]|1[0-8]):(00|15|30|45) UTC$/);

      fx.names.push(leap);
      await follow(page, page.getByTestId('schedule-new'));
      await fillForm(page, { name: leap, cron: '0 0 29 2 *', timezone: 'Pacific/Auckland', instance: fx.instance });
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${leap}" created.`);
      await expect(row(page, leap).getByTestId('schedule-next')).toHaveText(/^\d{4}-(02-28|02-29|03-01) 1[0-2]:00 UTC$/);

      // The list is ordered by name and the three are all there once.
      await expect(page.locator('[data-testid=schedule-name]').filter({ hasText: new RegExp(`^(${kolkata}|${quarter}|${leap})$`) })).toHaveCount(3);
      await expectClean(page, 'the list with three schedules');
    } finally {
      await cleanup(page, fx);
    }
  });

  test('editing changes every field but the name, refuses bad values and keeps a paused schedule paused', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const fx = await fixture(page);
    const name = uniqueName('edit');
    try {
      makeSchedule(fx, name, '0 3 * * *', 'UTC');
      instanceRow('insert', fx.project, 'other');
      await page.goto('/admin/schedules');
      await follow(page, row(page, name).getByTestId('schedule-edit'));
      await expect(page.getByRole('heading', { level: 1, name: `Edit ${name}` })).toBeVisible();
      await expect(page).toHaveTitle(new RegExp(`Edit ${name}`));
      // The name is fixed: no field for it, the rest is filled in.
      await expect(page.getByLabel('Name', { exact: true })).toHaveCount(0);
      await expect(page.getByLabel('Cron expression')).toHaveValue('0 3 * * *');
      await expect(page.getByLabel('Timezone')).toHaveValue('UTC');
      await expect(page.getByLabel('Action')).toHaveValue('redeploy');
      await expect(page.getByLabel('Instance')).toHaveValue(fx.instance);
      await expect(page.getByTestId('schedule-save')).toHaveText('Save schedule');
      await expectClean(page, 'the edit form');

      // Refusals keep the edit page and what was typed.
      await fillForm(page, { cron: 'nope', timezone: 'Mars/Base', instance: '' });
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByRole('heading', { level: 1, name: `Edit ${name}` })).toBeVisible();
      await expect(page.getByTestId('error-cron')).toHaveText(MSG.cronBad);
      await expect(page.getByTestId('error-timezone')).toHaveText(MSG.zone);
      await expect(page.getByTestId('error-instance')).toHaveText(MSG.instanceEmpty);
      await expect(page.getByTestId('error-name')).toHaveCount(0);
      await expect(page.getByLabel('Cron expression')).toHaveValue('nope');
      await expectClean(page, 'the edit form with errors');
      await fillForm(page, { cron: '0 3 * * *', timezone: 'UTC', instance: `${fx.project}--gone` });
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('error-instance')).toHaveText(MSG.instanceMissing);

      // Cancel: back to the list, nothing changed.
      await follow(page, page.getByRole('link', { name: 'Cancel' }));
      await expect(page).toHaveURL(/\/admin\/schedules$/);
      await expect(row(page, name).getByTestId('schedule-cron')).toHaveText('0 3 * * *');
      await expect(row(page, name)).toContainText(`UTC · redeploy · ${fx.instance}`);

      // Saving without a change is accepted.
      await follow(page, row(page, name).getByTestId('schedule-edit'));
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" saved.`);

      // Every field changes, on a paused schedule: it stays paused and shows no next run.
      await submitAndWait(page, row(page, name).getByTestId('schedule-pause'));
      await follow(page, row(page, name).getByTestId('schedule-edit'));
      await fillForm(page, { cron: '30 2 * * *', timezone: 'Asia/Kolkata', instance: `${fx.project}--other` });
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" saved.`);
      await expect(row(page, name).getByTestId('schedule-cron')).toHaveText('30 2 * * *');
      await expect(row(page, name)).toContainText(`Asia/Kolkata · redeploy · ${fx.project}--other`);
      await expect(row(page, name).getByTestId('schedule-state')).toHaveText('Paused');
      await expect(row(page, name).getByTestId('schedule-next')).toContainText('none');

      // Resumed, the new timing is what the next run follows.
      await submitAndWait(page, row(page, name).getByTestId('schedule-resume'));
      await expect(row(page, name).getByTestId('schedule-next')).toHaveText(/^\d{4}-\d{2}-\d{2} 21:00 UTC$/);

      // A schedule that does not exist answers 404 on its edit page.
      expect((await page.goto('/admin/schedules/no-such-schedule/edit'))?.status()).toBe(404);
      expect(errors.filter((e) => !e.includes('status of 422') && !e.includes('status of 404'))).toEqual([]);
    } finally {
      await cleanup(page, fx);
    }
  });

  test('pause, resume, run now and the history of a schedule, including a run that cannot start', async ({ page }) => {
    test.setTimeout(240_000);
    const fx = await fixture(page);
    const name = uniqueName('life');
    const orphan = uniqueName('orphan');
    try {
      makeSchedule(fx, name);
      instanceRow('insert', fx.project, 'gone');
      makeSchedule(fx, orphan, '0 4 * * *', 'UTC', `${fx.project}--gone`);
      await page.goto('/admin/schedules');

      // A schedule that never ran: no job link, an empty history.
      await expect(row(page, name).getByTestId('schedule-last')).toHaveText('never run');
      await follow(page, row(page, name).getByTestId('schedule-history'));
      await expect(page.getByRole('heading', { level: 1, name: `History of ${name}` })).toBeVisible();
      await expect(page.getByTestId('schedule-history-empty')).toHaveText('This schedule has not run yet.');
      await expect(page.getByTestId('schedule-run-row')).toHaveCount(0);
      await expectClean(page, 'the empty history');
      await follow(page, page.getByRole('link', { name: 'Schedules' }).last());
      await expect(page).toHaveURL(/\/admin\/schedules$/);

      // Pause, then Run now works while paused, and the schedule stays paused.
      await submitAndWait(page, row(page, name).getByTestId('schedule-pause'));
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" paused.`);
      await expect(row(page, name).getByTestId('schedule-state')).toHaveText('Paused');
      await expect(row(page, name).getByTestId('schedule-next')).toHaveText('none (paused)');
      await expect(row(page, name).getByTestId('schedule-pause')).toHaveCount(0);
      await submitAndWait(page, row(page, name).getByTestId('schedule-run'));
      await expect(page.getByTestId('flashes')).toContainText(new RegExp(`Schedule "${name}" started job #\\d+\\.`));
      await expect(row(page, name).getByTestId('schedule-state')).toHaveText('Paused');
      await submitAndWait(page, row(page, name).getByTestId('schedule-run'));
      await expect(page.getByTestId('flashes')).toContainText('started job #');

      // The job link of the list goes to that job's page.
      const href = await row(page, name).getByTestId('schedule-last-job').getAttribute('href');
      expect(href).toMatch(/^\/jobs\/\d+$/);
      const lastResult = (await row(page, name).getByTestId('schedule-last').innerText()).trim();
      expect(lastResult).toMatch(/^(claimed|started|succeeded|failed)$/);

      // History: both runs, newest first, manual, each with its job; the list's result is the newest row's.
      await follow(page, row(page, name).getByTestId('schedule-history'));
      const runs = page.getByTestId('schedule-run-row');
      await expect(runs).toHaveCount(2);
      await expect(page.getByTestId('run-trigger')).toHaveText(['manual', 'manual']);
      await expect(runs.first().getByTestId('run-result')).toHaveText(lastResult);
      const jobs = (await page.getByTestId('run-job').allInnerTexts()).map((text) => Number(text.replace('#', '')));
      expect(jobs[0]).toBeGreaterThan(jobs[1]);
      await expect(runs.first().getByTestId('run-job-link')).toHaveAttribute('href', href!);
      await expect(runs.first().locator('td').first()).toHaveText(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
      await expectClean(page, 'the history with two runs');
      await follow(page, runs.first().getByTestId('run-job-link'));
      await expect(page).toHaveURL(new RegExp(`${href}$`));
      await page.goBack();

      // Resume. It never catches up, and a second Resume from a stale tab is harmless.
      await page.goto('/admin/schedules');
      const stale = await page.context().newPage();
      await stale.goto('/admin/schedules');
      await submitAndWait(page, row(page, name).getByTestId('schedule-resume'));
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" resumed.`);
      await expect(row(page, name).getByTestId('schedule-state')).toHaveText('Active');
      await expect(row(page, name).getByTestId('schedule-next')).toContainText('UTC');
      await stale.locator(`[data-testid=schedule-row][data-name=${name}] [data-testid=schedule-resume]`).click();
      await expect(stale.getByTestId('flashes')).toContainText(`Schedule "${name}" resumed.`);
      await expect(stale.locator(`[data-testid=schedule-row][data-name=${name}]`).getByTestId('schedule-state')).toHaveText('Active');
      // Pausing twice, the second time from the page that still offers Pause, is harmless as well.
      await stale.goto('/admin/schedules');
      await submitAndWait(page, row(page, name).getByTestId('schedule-pause'));
      await stale.locator(`[data-testid=schedule-row][data-name=${name}] [data-testid=schedule-pause]`).click();
      await expect(stale.getByTestId('flashes')).toContainText(`Schedule "${name}" paused.`);
      await stale.close();

      // The instance of a schedule is gone: Run now says so and the history keeps the reason.
      instanceRow('delete', fx.project, 'gone');
      await page.goto('/admin/schedules');
      await submitAndWait(page, row(page, orphan).getByTestId('schedule-run'));
      await expect(page.getByTestId('flashes')).toContainText(new RegExp(`Schedule "${orphan}" did not run: .+`));
      await expect(row(page, orphan).getByTestId('schedule-last-job')).toHaveCount(0);
      await expect(row(page, orphan).getByTestId('schedule-last')).toHaveText(/^(failed|skipped)$/);
      await follow(page, row(page, orphan).getByTestId('schedule-history'));
      await expect(page.getByTestId('schedule-run-row')).toHaveCount(1);
      await expect(page.getByTestId('run-result')).toHaveText(/^(failed|skipped)$/);
      await expect(page.getByTestId('run-job')).toHaveText('-');
      await expect(page.getByTestId('run-job-link')).toHaveCount(0);
      await expect(page.getByTestId('schedule-run-row').locator('td').last()).not.toBeEmpty();
      await expectClean(page, 'the history of a run that did not start');

      // A history that does not exist answers 404.
      expect((await page.goto('/admin/schedules/no-such-schedule/history'))?.status()).toBe(404);
    } finally {
      await cleanup(page, fx);
    }
  });

  test('deleting asks first, can be cancelled, removes the history and a second delete finds nothing', async ({ page }) => {
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

  test('every change needs the CSRF token and the pages are for administrators only', async ({ page, context, baseURL }) => {
    test.setTimeout(120_000);
    const fx = await fixture(page);
    const name = uniqueName('guard');
    try {
      makeSchedule(fx, name);
      // A POST without the token is refused and changes nothing.
      for (const path of ['pause', 'resume', 'run', 'delete']) {
        const response = await page.request.post(`/admin/schedules/${name}/${path}`, { form: {} });
        expect(response.status(), path).toBe(403);
      }
      expect((await page.request.post('/admin/schedules/new', { form: { name: 'sneaky', cron: '0 3 * * *', timezone: 'UTC', action: 'redeploy', instance: fx.instance } })).status()).toBe(403);
      expect((await page.request.post(`/admin/schedules/${name}/edit`, { form: { cron: '1 1 * * *', timezone: 'UTC', action: 'redeploy', instance: fx.instance } })).status()).toBe(403);
      await page.goto('/admin/schedules');
      await expect(row(page, name).getByTestId('schedule-state')).toHaveText('Active');
      await expect(row(page, name).getByTestId('schedule-cron')).toHaveText('0 3 * * *');
      await expect(row(page, 'sneaky')).toHaveCount(0);

      // A member (not an administrator) may not see any of it.
      await setStandInRole(context, 'member', baseURL!);
      for (const path of ['/admin/schedules', '/admin/schedules/new', `/admin/schedules/${name}/edit`, `/admin/schedules/${name}/history`]) {
        expect((await page.goto(path))?.status(), path).toBe(403);
      }
      const token = await page.request.post(`/admin/schedules/${name}/pause`, { form: {} });
      expect(token.status()).toBe(403);
      await setStandInRole(context, 'admin', baseURL!);
      await page.goto('/admin/schedules');
      await expect(row(page, name).getByTestId('schedule-state')).toHaveText('Active');
    } finally {
      await setStandInRole(context, 'admin', baseURL!);
      await cleanup(page, fx);
    }
  });

  test('long names, expressions and instance ids wrap on every page and every control is a full-size target', async ({ page }) => {
    test.setTimeout(180_000);
    const fx = await fixture(page);
    const longName = uniqueName('l').padEnd(40, 'x');
    const longLabel = 'y'.repeat(40);
    try {
      expect(longName.length).toBe(40);
      instanceRow('insert', fx.project, longLabel);
      const longInstance = `${fx.project}--${longLabel}`;
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
      await fillForm(page, { instance: `${longInstance}-nope` });
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('error-instance')).toHaveText(MSG.instanceMissing);
      await expectClean(page, 'the edit form with a long refused value');
      await page.goto('/admin/schedules');
      const dialog = await openDialog(page, row(page, longName).getByTestId('schedule-delete'));
      await expect(dialog.getByRole('heading', { name: `Delete ${longName}?` })).toBeVisible();
      expect(await hasHorizontalOverflow(page), 'overflow with the dialog open').toBe(false);
      await page.keyboard.press('Escape');
    } finally {
      instanceRow('delete', fx.project, 'y'.repeat(40));
      await cleanup(page, fx);
    }
  });
});
