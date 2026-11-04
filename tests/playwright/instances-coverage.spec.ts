import { expect, test, type Locator, type Page } from '@playwright/test';
import { ADMIN_PROJECT, createDeployableProject, deployOne, rowOf, runConsole, submitDeploy, uniqueLabel } from './support/instances';
import { follow, navigate, openDialog, submitAndWait, uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// The browser-only part of the instances coverage: the deploy form's project switch and its phone layout, the row and
// bulk dialogs (destroy, redeploy, Cancel), the bulk bar and its count, and the stop/start/redeploy flow of the detail
// page. Everything that is plain HTTP (form defaults and messages, numbering, filters, bulk and action refusals, role
// limits, the detail page facts and busy state) is asserted in app/tests/Functional/InstanceDeployFormTest.php and
// InstanceControllerTest.php. Runs on the desktop (1280 px) and the phone (390 px, @phone tests only); every test owns
// its project and its instances.

const isPhone = (): boolean => test.info().project.name === 'phone';

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

/** A project with a default template and branch of its own, so the filters and the bulk actions see only its rows. */
async function ownProject(): Promise<string> {
  const name = uniqueName('ic');
  await createDeployableProject(name);
  return name;
}

/** Deploys `count` instances (labels `<label>-1` ...) through the form and returns their ids once all are running. */
async function deployMany(page: Page, project: string, count: number): Promise<string[]> {
  const label = uniqueLabel('bk');
  await submitDeploy(page, { project, label, count });
  await expect(page.getByTestId('flashes')).toContainText(`${count} jobs queued.`);
  const ids = Array.from({ length: count }, (_, i) => `${project}--${label}-${i + 1}`);
  await page.goto(`/instances?project=${project}`);
  for (const id of ids) {
    await expect(rowOf(page, id).getByTestId('instance-state')).toHaveAttribute('data-state', 'running');
  }
  return ids;
}

/** Ticks the rows' boxes (the label around the box is the target on a phone, where the box is small). */
async function selectRows(page: Page, ids: string[]): Promise<void> {
  for (const id of ids) {
    await page.getByLabel(`Select ${id}`).check();
  }
  await expect(page.getByTestId('bulk-bar')).toBeVisible();
}

function bulkButton(page: Page, name: string): Locator {
  return page.getByTestId('bulk-bar').getByRole('button', { name, exact: true });
}

test.describe('deploy form: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('shows the deploy form with its fields and a Cancel link @smoke', async ({ page }) => {
    // The defaults, help texts and validation messages are asserted over HTTP (InstanceDeployFormTest).
    await page.goto(`/instances/new?project=${ADMIN_PROJECT}`);
    const form = page.getByTestId('deploy-form');
    await expect(page.getByRole('heading', { level: 1, name: 'Deploy' })).toBeVisible();
    await expect(form.getByLabel('Project', { exact: true })).toHaveValue(ADMIN_PROJECT);
    await expect(form.getByLabel('Template', { exact: true })).toBeVisible();
    await expect(form.getByLabel('Branch', { exact: true })).toBeVisible();
    await expect(form.getByLabel('Label', { exact: true })).toBeVisible();
    await expect(form.getByLabel('How many')).toHaveValue('1');
    await expect(form.getByLabel('Deploy at the same time')).not.toBeChecked();

    await follow(page, page.getByRole('link', { name: 'Cancel' }));
    await expect(page).toHaveURL(/\/instances$/);
  });

  test('choosing another project reloads the form with that project\'s templates and default branch', async ({ page }) => {
    const other = await ownProject();
    await runConsole(['fleet:template:create', other, 'alt']);

    await page.goto(`/instances/new?project=${ADMIN_PROJECT}`);
    const project = page.getByTestId('deploy-form').getByLabel('Project', { exact: true });
    await navigate(page, () => project.selectOption(other));
    await expect(page).toHaveURL(new RegExp(`/instances/new\\?project=${other}$`));
    await expect(page.getByTestId('deploy-form').getByLabel('Project', { exact: true })).toHaveValue(other);
    await expect(page.getByTestId('deploy-form').getByLabel('Template', { exact: true }).locator('option')).toHaveText(['Project default', 'alt', 'default']);
    await expect(page.getByTestId('deploy-form').getByLabel('Branch', { exact: true })).toHaveAttribute('placeholder', 'main');
  });

  test('the form fits at the phone width with its errors @phone', async ({ page }) => {
    test.skip(!isPhone(), 'phone width only');
    await page.goto(`/instances/new?project=${ADMIN_PROJECT}`);
    const form = page.getByTestId('deploy-form');
    await form.getByLabel('Branch', { exact: true }).fill('b'.repeat(150));
    await form.getByLabel('Label', { exact: true }).evaluate((input: HTMLInputElement) => input.removeAttribute('maxlength'));
    await form.getByLabel('Label', { exact: true }).fill('l'.repeat(60));
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(form).toContainText('The instance id would be 66 characters; the limit is 59 (DDEV adds -web to the container host name, and a host name has at most 63 characters). Use a shorter label.');
    await expectNoOverflow(page, 'the deploy form with an error');

    // Several errors at once.
    await form.getByLabel('Branch', { exact: true }).fill('a..b');
    await form.getByLabel('Label', { exact: true }).fill('ok');
    await form.getByLabel('How many').fill('0');
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(form).toContainText('This is not a valid git branch name');
    await expect(form).toContainText('The count must be between 1 and 20.');
    await expectNoOverflow(page, 'the deploy form with several errors');
  });
});

test.describe('instance list: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the rows stop, start, redeploy and destroy from the list, destroy behind a dialog', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto(`/instances?q=${id}`);
    await follow(page, rowOf(page, id).getByRole('button', { name: 'Stop', exact: true }));
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByTestId('job-status')).toHaveAttribute('data-status', 'succeeded');

    await page.goto(`/instances?q=${id}`);
    await expect(rowOf(page, id).getByTestId('instance-state')).toHaveAttribute('data-state', 'stopped');
    await expect(rowOf(page, id).getByTestId('open-site')).toHaveCount(0);
    await follow(page, rowOf(page, id).getByRole('button', { name: 'Start', exact: true }));
    await page.goto(`/instances?q=${id}`);
    await expect(rowOf(page, id).getByTestId('instance-state')).toHaveAttribute('data-state', 'running');
    await expect(rowOf(page, id).getByTestId('open-site')).toHaveAttribute('href', new RegExp(`^https://${id}\\.`));

    await rowOf(page, id).getByLabel(`More actions for ${id}`).click();
    await follow(page, page.getByRole('menuitem', { name: 'Redeploy' }));
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');

    // Destroy: Cancel keeps the instance, the confirmation queues the job.
    await page.goto(`/instances?q=${id}`);
    await rowOf(page, id).getByLabel(`More actions for ${id}`).click();
    const dialog = await openDialog(page, page.getByRole('menuitem', { name: 'Destroy…' }));
    await expect(dialog).toContainText(`Destroy ${id}?`);
    await expect(dialog).toContainText('This deletes the instance, its database and its files. It cannot be undone.');
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(rowOf(page, id)).toBeVisible();

    await rowOf(page, id).getByLabel(`More actions for ${id}`).click();
    const confirm = await openDialog(page, page.getByRole('menuitem', { name: 'Destroy…' }));
    await follow(page, confirm.getByRole('button', { name: 'Destroy instance' }));
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await page.goto(`/instances?q=${id}`);
    await expect(rowOf(page, id)).toHaveCount(0);
  });
});

test.describe('bulk actions: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the selection drives the bar and its count; select all is the box in the header @phone', async ({ page }) => {
    const project = await ownProject();
    const ids = await deployMany(page, project, 3);
    await page.goto(`/instances?project=${project}`);
    const bar = page.getByTestId('bulk-bar');
    await expect(bar).toBeHidden();

    await page.getByLabel(`Select ${ids[0]}`).check();
    await expect(bar).toContainText('1 selected');
    await page.getByLabel(`Select ${ids[1]}`).check();
    await expect(bar).toContainText('2 selected');
    await page.getByLabel(`Select ${ids[1]}`).uncheck();
    await expect(bar).toContainText('1 selected');
    await page.getByLabel(`Select ${ids[0]}`).uncheck();
    await expect(bar).toBeHidden();

    if (!isPhone()) {
      // The header box is only there from md up.
      const all = page.getByLabel('Select all');
      await all.check();
      await expect(bar).toContainText('3 selected');
      for (const id of ids) {
        await expect(page.getByLabel(`Select ${id}`)).toBeChecked();
      }
      await page.getByLabel(`Select ${ids[2]}`).uncheck();
      await expect(all).not.toBeChecked();
      await page.getByLabel(`Select ${ids[2]}`).check();
      await expect(all).toBeChecked();
      await all.uncheck();
      await expect(bar).toBeHidden();
    }
  });

  test('redeploy lists the selection in a dialog; Cancel queues nothing, the button does', async ({ page }) => {
    const project = await ownProject();
    const ids = await deployMany(page, project, 2);
    await page.goto(`/instances?project=${project}`);
    await selectRows(page, ids);

    const dialog = await openDialog(page, bulkButton(page, 'Redeploy…'));
    await expect(dialog).toContainText('Redeploy the selected instances?');
    await expect(dialog).toContainText('Each one is rebuilt from its template, one after the other.');
    await expect(dialog.getByRole('listitem')).toHaveText(ids);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByTestId('flashes')).toHaveCount(0);
    // The selection is still there after Cancel.
    await expect(page.getByTestId('bulk-bar')).toContainText('2 selected');

    const confirm = await openDialog(page, bulkButton(page, 'Redeploy…'));
    await submitAndWait(page, confirm.getByRole('button', { name: 'Redeploy', exact: true }));
    await expect(page.getByTestId('flashes')).toContainText('2 jobs queued.');
    await page.goto(`/instances/${ids[0]}`);
    await expect(page.getByTestId('timeline-job').filter({ hasText: 'redeploy' })).toHaveCount(1);
  });

  test('destroy lists the selection in a dialog and removes the rows after the confirmation', async ({ page }) => {
    const project = await ownProject();
    const ids = await deployMany(page, project, 3);
    await page.goto(`/instances?project=${project}`);
    await selectRows(page, [ids[0], ids[2]]);

    const dialog = await openDialog(page, bulkButton(page, 'Destroy…'));
    await expect(dialog).toContainText('Destroy the selected instances?');
    await expect(dialog).toContainText('Their databases and files are deleted. It cannot be undone.');
    await expect(dialog.getByRole('listitem')).toHaveText([ids[0], ids[2]]);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.goto(`/instances?project=${project}`);
    await expect(page.getByTestId('instance-row')).toHaveCount(3);

    await selectRows(page, [ids[0], ids[2]]);
    const confirm = await openDialog(page, bulkButton(page, 'Destroy…'));
    await submitAndWait(page, confirm.getByRole('button', { name: 'Destroy instances' }));
    await expect(page.getByTestId('flashes')).toContainText('2 jobs queued.');
    await page.goto(`/instances?project=${project}`);
    await expect(page.getByTestId('instance-row')).toHaveCount(1);
    await expect(rowOf(page, ids[1])).toBeVisible();
  });

  test('a long id keeps the list, the bar and the dialog inside the phone width @phone', async ({ page }) => {
    test.skip(!isPhone(), 'phone width only');
    const project = uniqueName('lg').padEnd(40, 'x').slice(0, 40);
    await createDeployableProject(project);
    const id = await deployOne(page, project, 'l'.repeat(17));
    expect(id).toHaveLength(59);
    await page.goto(`/instances?project=${project}`);
    await selectRows(page, [id]);
    await expectNoOverflow(page, 'the list with the bulk bar');
    const dialog = await openDialog(page, bulkButton(page, 'Redeploy…'));
    await expect(dialog).toContainText(id);
    await expectNoOverflow(page, 'the bulk dialog');
  });
});

test.describe('instance page: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('stop, then start (the buttons follow the state), redeploy', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto(`/instances/${id}`);
    await follow(page, page.getByTestId('instance-actions').getByRole('button', { name: 'Stop', exact: true }));
    await expect(page.getByTestId('job-status')).toHaveAttribute('data-status', 'succeeded');

    await page.goto(`/instances/${id}`);
    await expect(page.getByTestId('instance-detail')).toContainText('Stopped');
    await expect(page.getByTestId('open-site')).toHaveCount(0);
    await expect(page.getByTestId('instance-actions').getByRole('button', { name: 'Stop', exact: true })).toHaveCount(0);
    await follow(page, page.getByTestId('instance-actions').getByRole('button', { name: 'Start', exact: true }));
    await page.goto(`/instances/${id}`);
    await expect(page.getByTestId('instance-detail')).toContainText('Running');

    await follow(page, page.getByTestId('instance-actions').getByRole('button', { name: 'Redeploy' }));
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');
    await page.goto(`/instances/${id}`);
    await expect(page.getByTestId('timeline-job')).toHaveCount(4);
  });

  test('destroy: the dialog explains, Cancel and Escape keep the instance, the button removes it', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto(`/instances/${id}`);
    const dialog = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
    await expect(dialog).toContainText(`Destroy ${id}?`);
    await expect(dialog).toContainText('This deletes the instance, its database and its files. It cannot be undone.');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByTestId('timeline-job')).toHaveCount(1);

    const again = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
    await follow(page, again.getByRole('button', { name: 'Destroy instance' }));
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByTestId('job-status')).toHaveAttribute('data-status', 'succeeded');
    expect((await page.goto(`/instances/${id}`))?.status()).toBe(404);
  });

  test('fits the phone width with a long id, a long branch and a long log line @phone', async ({ page }) => {
    test.skip(!isPhone(), 'phone width only');
    const project = await ownProject();
    const branch = `feature/${'very-long-branch-name-'.repeat(6)}end`;
    await page.goto(`/instances/new?project=${project}`);
    const form = page.getByTestId('deploy-form');
    await form.getByLabel('Branch', { exact: true }).fill(branch);
    await form.getByLabel('Label', { exact: true }).fill('l'.repeat(30));
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');
    await expectNoOverflow(page, 'the job page');
    await page.goto(`/instances?project=${project}`);
    await expectNoOverflow(page, 'the list');
    await page.goto(`/instances/${project}--${'l'.repeat(30)}`);
    await expect(page.getByTestId('instance-detail')).toContainText(branch);
    await expectNoOverflow(page, 'the detail page');
  });
});
