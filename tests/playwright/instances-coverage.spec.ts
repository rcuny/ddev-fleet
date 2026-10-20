import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { ADMIN_PROJECT, MEMBER_PROJECT, createDeployableProject, deployOne, postForm, rowOf, runConsole, runFakeJob, submitDeploy, submitTampered, uniqueLabel } from './support/instances';
import { follow, navigate, openDialog, submitAndWait, uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// The coverage companion of instances.spec.ts and instances-phone.spec.ts: every field and every message of the deploy
// form, the list filters in every combination, the bulk actions with their dialogs and refusals, the actions of the
// detail page and what the pages answer when the state or the role forbids an action. The recording runner finishes a
// job inside the request, so a "busy" instance is made with a fake job that holds it (and is killed at the end of the
// test). Runs on the desktop (1280 px) and the phone (390 px) project; every test owns its project and its instances.

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

async function stateOf(page: Page, id: string): Promise<string | null> {
  return rowOf(page, id).getByTestId('instance-state').getAttribute('data-state');
}

/**
 * Starts a long fake job on the instance and waits until the instance page shows it as running. Returns the function
 * that kills it; call it in `finally`, the job then stays `running` and the instance busy, which the test owns alone.
 */
async function holdBusy(page: Page, id: string): Promise<() => void> {
  const controller = new AbortController();
  runFakeJob(id, { lines: 300, delay: 100, signal: controller.signal }).catch(() => undefined);
  await expect
    .poll(async () => (await (await page.request.get(`/instances/${id}`)).text()).includes('data-testid="active-job"'), { message: `${id} is busy` })
    .toBe(true);
  return () => controller.abort();
}

test.describe('deploy form: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('shows every field with its default, help and a Cancel link @smoke', async ({ page }) => {
    await page.goto(`/instances/new?project=${ADMIN_PROJECT}`);
    const form = page.getByTestId('deploy-form');
    await expect(page.getByRole('heading', { level: 1, name: 'Deploy' })).toBeVisible();

    await expect(form.getByLabel('Project', { exact: true })).toHaveValue(ADMIN_PROJECT);
    await expect(form.getByLabel('Project', { exact: true }).locator('option')).toContainText([ADMIN_PROJECT, MEMBER_PROJECT]);
    // The template choices belong to the project; the first one is "Project default".
    await expect(form.getByLabel('Template', { exact: true })).toHaveValue('');
    await expect(form.getByLabel('Template', { exact: true }).locator('option')).toHaveText(['Project default', 'default']);
    await expect(form.getByLabel('Branch', { exact: true })).toHaveValue('');
    await expect(form.getByLabel('Branch', { exact: true })).toHaveAttribute('placeholder', 'main');
    await expect(form).toContainText('Leave blank to use the project default branch.');
    await expect(form.getByLabel('Label', { exact: true })).toHaveValue('');
    await expect(form.getByLabel('Label', { exact: true })).toHaveAttribute('maxlength', '40');
    await expect(form).toContainText('Part of the instance id. Leave blank to derive it from the branch.');
    await expect(form.getByLabel('How many')).toHaveValue('1');
    await expect(form.getByLabel('How many')).toHaveAttribute('max', '20');
    await expect(form).toContainText('1 to 20 instances; more than one get numbered suffixes.');
    await expect(form.getByLabel('Deploy at the same time')).not.toBeChecked();
    await expect(form).toContainText('By default several instances are deployed one after the other.');
    await expectNoBlockingViolations(page, 'the deploy form');

    await follow(page, page.getByRole('link', { name: 'Cancel' }));
    await expect(page).toHaveURL(/\/instances$/);
  });

  test('an unknown ?project falls back to the first project, the project list reloads the form', async ({ page }) => {
    const other = await ownProject();
    await runConsole(['fleet:template:create', other, 'alt']);

    await page.goto('/instances/new?project=no-such-project');
    const project = page.getByTestId('deploy-form').getByLabel('Project', { exact: true });
    await expect(project).not.toHaveValue('no-such-project');
    const first = await project.inputValue();
    expect([ADMIN_PROJECT, MEMBER_PROJECT, other]).toContain(first);

    // Choosing another project reloads the form with that project's templates and default branch.
    await navigate(page, () => project.selectOption(other));
    await expect(page).toHaveURL(new RegExp(`/instances/new\\?project=${other}$`));
    await expect(page.getByTestId('deploy-form').getByLabel('Project', { exact: true })).toHaveValue(other);
    await expect(page.getByTestId('deploy-form').getByLabel('Template', { exact: true }).locator('option')).toHaveText(['Project default', 'alt', 'default']);
    await expect(page.getByTestId('deploy-form').getByLabel('Branch', { exact: true })).toHaveAttribute('placeholder', 'main');
  });

  test('every field of a valid form reaches the instance: branch, template, a label that is slugged', async ({ page }) => {
    const project = await ownProject();
    await runConsole(['fleet:template:create', project, 'alt']);
    const suffix = uniqueLabel('');
    await page.goto(`/instances/new?project=${project}`);
    const form = page.getByTestId('deploy-form');
    await form.getByLabel('Template', { exact: true }).selectOption('alt');
    await form.getByLabel('Branch', { exact: true }).fill(`release/1.0-${suffix}`);
    await form.getByLabel('Label', { exact: true }).fill(`  My Label_${suffix}!  `);
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));

    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');
    const id = `${project}--my-label-${suffix}`;
    await expect(page.getByTestId('job-meta')).toContainText(id);
    await page.goto(`/instances/${id}`);
    const detail = page.getByTestId('instance-detail');
    await expect(detail).toContainText('alt');
    await expect(detail).toContainText(`release/1.0-${suffix}`);
    await expect(page.getByRole('heading', { level: 1, name: id })).toBeVisible();
    await expectNoBlockingViolations(page, 'the detail page of a deployed instance');
  });

  test('a blank label is derived from the branch, a blank branch from the project default', async ({ page }) => {
    const project = await ownProject();
    const suffix = uniqueLabel('');
    await page.goto(`/instances/new?project=${project}`);
    const form = page.getByTestId('deploy-form');
    await form.getByLabel('Branch', { exact: true }).fill(`Feature/Zz-${suffix}`);
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');
    await expect(page.getByTestId('job-meta')).toContainText(`${project}--feature-zz-${suffix}`);

    // No label and no branch: the id comes from the project's default branch, "main".
    await submitDeploy(page, { project });
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');
    await expect(page.getByTestId('job-meta')).toContainText(`${project}--main`);
  });

  test('a count above one numbers the instances; "deploy at the same time" is accepted', async ({ page }) => {
    const project = await ownProject();
    const label = uniqueLabel('par');
    await page.goto(`/instances/new?project=${project}`);
    const form = page.getByTestId('deploy-form');
    await form.getByLabel('Label', { exact: true }).fill(label);
    await form.getByLabel('How many').fill('3');
    // The label covers the box (a bigger tap target), so the label is what a user clicks.
    await form.locator('label', { hasText: 'Deploy at the same time' }).click();
    await expect(form.getByLabel('Deploy at the same time')).toBeChecked();
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(page).toHaveURL(/\/instances$/);
    await expect(page.getByTestId('flashes')).toContainText('3 jobs queued.');
    for (const n of [1, 2, 3]) {
      await expect(rowOf(page, `${project}--${label}-${n}`)).toBeVisible();
    }
    await expect(rowOf(page, `${project}--${label}`)).toHaveCount(0);
  });

  test('each rule of the service shows its message under the right field and keeps what was typed', async ({ page }) => {
    await page.goto(`/instances/new?project=${ADMIN_PROJECT}`);
    const form = page.getByTestId('deploy-form');
    const branch = form.getByLabel('Branch', { exact: true });
    const label = form.getByLabel('Label', { exact: true });
    const count = form.getByLabel('How many');
    const deploy = form.getByRole('button', { name: 'Deploy' });

    async function submitWith(values: { branch?: string; label?: string; count?: string }): Promise<void> {
      // Every response is a new page, with the limits of the fields back on: lift them for the over-long values.
      await branch.evaluate((input: HTMLInputElement) => input.removeAttribute('maxlength'));
      await label.evaluate((input: HTMLInputElement) => input.removeAttribute('maxlength'));
      await branch.fill(values.branch ?? '');
      await label.fill(values.label ?? '');
      await count.fill(values.count ?? '1');
      await submitAndWait(page, deploy);
    }

    // A label with no letter or digit left.
    await submitWith({ label: '!!!', branch: 'dev' });
    await expect(form).toContainText('The label needs at least one letter or digit.');
    await expect(label).toHaveValue('!!!');
    await expect(branch).toHaveValue('dev');
    await expect(form.getByLabel('Project', { exact: true })).toHaveValue(ADMIN_PROJECT);
    await expectNoBlockingViolations(page, 'the deploy form with an error');

    // The field stops typing at 40 characters; an id longer than 63 ("acme--" and 60 more) needs the limit lifted.
    await label.fill('l'.repeat(60));
    await expect(label).toHaveValue('l'.repeat(40));
    await submitWith({ label: 'l'.repeat(60) });
    await expect(form).toContainText('The instance id would be 66 characters; the limit is 63. Use a shorter label.');

    // The count is a whole number from 1 to 20.
    for (const bad of ['0', '21', '-3']) {
      await submitWith({ label: 'ok', count: bad });
      await expect(form).toContainText('The count must be between 1 and 20.');
      await expect(count).toHaveValue(bad);
    }
    await count.evaluate((input: HTMLInputElement) => (input.type = 'text'));
    await submitWith({ label: 'ok', count: 'abc' });
    await expect(form).toContainText('Enter a whole number.');

    // Branch rules.
    const branchRules: Array<[string, string]> = [
      ['bad branch', 'A branch name uses letters, digits, dot, underscore, dash and slash, and starts with a letter or digit.'],
      ['-rf', 'A branch name uses letters, digits, dot, underscore, dash and slash, and starts with a letter or digit.'],
      ['a..b', 'This is not a valid git branch name (no "..", "//", "/.", trailing "/" or ".", or ".lock" ending).'],
      ['a//b', 'This is not a valid git branch name'],
      ['topic/', 'This is not a valid git branch name'],
      ['topic.lock', 'This is not a valid git branch name'],
      ['HEAD', 'This is not a branch name: it is the name git gives the current checkout, or a full reference path.'],
      ['refs/heads/main', 'This is not a branch name: it is the name git gives the current checkout, or a full reference path.'],
      ['b'.repeat(201), 'The branch name must be at most 200 characters.'],
    ];
    for (const [value, message] of branchRules) {
      await count.evaluate((input: HTMLInputElement) => (input.type = 'number'));
      await submitWith({ branch: value, label: 'ok' });
      await expect(form).toContainText(message);
      await expect(branch).toHaveValue(value);
    }

    // The errors of several fields come together.
    await count.evaluate((input: HTMLInputElement) => (input.type = 'number'));
    await submitWith({ branch: 'a..b', label: 'ok', count: '0' });
    await expect(form).toContainText('This is not a valid git branch name');
    await expect(form).toContainText('The count must be between 1 and 20.');
    await expectNoOverflowIfPhone(page);
  });

  test('a project or template that is not in the lists is refused', async ({ page }) => {
    await page.goto(`/instances/new?project=${ADMIN_PROJECT}`);
    const form = page.getByTestId('deploy-form');
    await form.getByLabel('Template', { exact: true }).evaluate((select: HTMLSelectElement) => {
      select.add(new Option('ghost', 'ghost'));
      select.value = 'ghost';
    });
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(form).toContainText('This project has no template with that name.');

    await form.getByLabel('Project', { exact: true }).evaluate((select: HTMLSelectElement) => {
      select.add(new Option('ghost', 'ghost'));
      select.value = 'ghost';
    });
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(form).toContainText('Choose one of the projects.');
  });

  test('a project with no default branch or template asks for both, and deploys once they are given', async ({ page }) => {
    const project = uniqueName('nd');
    await runConsole(['fleet:project:create', project, '--git=https://git.example.com/acme/blog.git']);
    await runConsole(['fleet:template:create', project, 'only']);

    await page.goto(`/instances/new?project=${project}`);
    const form = page.getByTestId('deploy-form');
    await expect(form.getByLabel('Branch', { exact: true })).toHaveAttribute('placeholder', '');
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(form).toContainText('Choose a branch: the project has no default branch.');
    await expect(form).toContainText('Choose a template: the project has no default template.');

    await form.getByLabel('Branch', { exact: true }).fill('develop');
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(form).toContainText('Choose a template: the project has no default template.');
    await expect(form).not.toContainText('Choose a branch');

    await form.getByLabel('Template', { exact: true }).selectOption('only');
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');
    await expect(page.getByTestId('job-meta')).toContainText(`${project}--develop`);
  });

  test('a label that pushes an alias host name over 63 characters is refused', async ({ page }) => {
    const project = uniqueName('al');
    const hostname = 'h'.repeat(30);
    await runConsole(['fleet:project:create', project, '--git=https://git.example.com/acme/blog.git', '--default-branch=main', `--hostname=${hostname}`]);
    await runConsole(['fleet:template:create', project, 'default']);
    await runConsole(['fleet:project:update', project, '--default-template=default']);

    await page.goto(`/instances/new?project=${project}`);
    const form = page.getByTestId('deploy-form');
    await form.getByLabel('Label', { exact: true }).fill('l'.repeat(30));
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(form).toContainText(/The host name label "<hostname>-<id>" would be \d+ characters; the limit is 63\. Use a shorter label\./);

    const label = uniqueLabel('al');
    await form.getByLabel('Label', { exact: true }).fill(label);
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');
    await page.goto(`/instances/${project}--${label}`);
    await expect(page.getByTestId('instance-alias')).toContainText(`${hostname}-${project}--${label}`);
  });

  test('the form fits at the phone width with its errors', async ({ page }) => {
    test.skip(!isPhone(), 'phone width only');
    await page.goto(`/instances/new?project=${ADMIN_PROJECT}`);
    const form = page.getByTestId('deploy-form');
    await form.getByLabel('Branch', { exact: true }).fill('b'.repeat(150));
    await form.getByLabel('Label', { exact: true }).evaluate((input: HTMLInputElement) => input.removeAttribute('maxlength'));
    await form.getByLabel('Label', { exact: true }).fill('l'.repeat(60));
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(form).toContainText('The instance id would be 66 characters; the limit is 63. Use a shorter label.');
    await expectNoOverflow(page, 'the deploy form with an error');
  });
});

async function expectNoOverflowIfPhone(page: Page): Promise<void> {
  if (isPhone()) {
    await expectNoOverflow(page, 'the deploy form with several errors');
  }
}

test.describe('instance list: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('every combination of the three filters, and what the empty result says', async ({ page }) => {
    const project = await ownProject();
    const [a, b, c] = await deployMany(page, project, 3);
    // b is stopped; a and c keep running.
    await page.goto(`/instances?project=${project}`);
    await selectRows(page, [b]);
    await submitAndWait(page, bulkButton(page, 'Stop'));
    await page.goto(`/instances?project=${project}`);
    await expect(rowOf(page, b).getByTestId('instance-state')).toHaveAttribute('data-state', 'stopped');

    const tag = a.split('--')[1].replace(/-1$/, '');
    async function rowsFor(query: string): Promise<string[]> {
      await page.goto(`/instances?${query}`);
      await expect(page.getByTestId('instances-frame')).toBeVisible();
      return page
        .getByTestId('instance-row')
        .evaluateAll((rows) => rows.map((row) => (row as HTMLElement).dataset.instance as string));
    }

    expect(await rowsFor(`project=${project}`)).toEqual([a, b, c]);
    expect(await rowsFor(`project=${project}&state=running`)).toEqual([a, c]);
    expect(await rowsFor(`project=${project}&state=stopped`)).toEqual([b]);
    expect(await rowsFor(`q=${tag}`)).toEqual([a, b, c]);
    expect(await rowsFor(`q=${tag}-2`)).toEqual([b]);
    expect(await rowsFor(`q=${tag}&state=running`)).toEqual([a, c]);
    expect(await rowsFor(`q=${tag}&project=${project}&state=stopped`)).toEqual([b]);
    expect(await rowsFor(`q=${tag}-2&state=running`)).toEqual([]);
    expect(await rowsFor(`q=${tag}&project=${ADMIN_PROJECT}`)).toEqual([]);
    // The search ignores case and surrounding spaces; an unknown state matches nothing.
    expect(await rowsFor(`q=${encodeURIComponent(`  ${tag.toUpperCase()}-3 `)}`)).toEqual([c]);
    expect(await rowsFor(`project=${project}&state=bogus`)).toEqual([]);
    expect(await rowsFor(`project=${project}&state=failed`)).toEqual([]);

    const empty = page.getByTestId('instances-empty');
    await expect(empty).toContainText('No instance matches');
    await expect(empty).toContainText('Change or clear the filters.');
    await expectNoBlockingViolations(page, 'the list with no match');
  });

  test('the filter form submits, keeps its values and lists the choices', async ({ page }) => {
    const project = await ownProject();
    const [a, b] = await deployMany(page, project, 2);
    await page.goto('/instances');
    const filters = page.getByTestId('instance-filters');

    await expect(filters.getByLabel('Project').locator('option').first()).toHaveText('All projects');
    // Only projects that have an instance are offered.
    await expect(filters.getByLabel('Project').locator('option', { hasText: project })).toHaveCount(1);
    await expect(filters.getByLabel('State').locator('option')).toHaveText(['Any state', 'Deploying', 'Running', 'Stopped', 'Failed', 'Destroying', 'Unknown']);

    await filters.getByLabel('Search instances').fill(a);
    await filters.getByLabel('Project').selectOption(project);
    await filters.getByLabel('State').selectOption('running');
    await follow(page, page.getByTestId('filter-submit'));
    await expect(rowOf(page, a)).toBeVisible();
    await expect(page.getByTestId('instance-row')).toHaveCount(1);
    await expect(filters.getByLabel('Search instances')).toHaveValue(a);
    await expect(filters.getByLabel('Project')).toHaveValue(project);
    await expect(filters.getByLabel('State')).toHaveValue('running');

    // Clearing the search and the state widens the result again.
    await filters.getByLabel('Search instances').fill('');
    await filters.getByLabel('State').selectOption('');
    await follow(page, page.getByTestId('filter-submit'));
    await expect(rowOf(page, b)).toBeVisible();
    await expect(page.getByTestId('instance-row')).toHaveCount(2);
    await expectNoBlockingViolations(page, 'the filtered list');
  });

  test('the state badge shows a running job and a failed one, the row buttons wait for it', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto('/instances');
    const release = await holdBusy(page, id);
    try {
      await page.goto(`/instances?q=${id}`);
      const row = rowOf(page, id);
      await expect(row.getByTestId('instance-state')).toContainText('Working…');
      await expect(row.getByRole('button', { name: 'Stop', exact: true })).toBeDisabled();
      await row.getByLabel(`More actions for ${id}`).click();
      await expect(page.getByRole('menuitem', { name: 'Redeploy' })).toBeDisabled();
      await page.keyboard.press('Escape');
      await expectNoBlockingViolations(page, 'the list with a busy instance');
    } finally {
      release();
    }

    const failing = await deployOne(page);
    await runFakeJob(failing, { lines: 2, delay: 0, fail: true });
    await page.goto(`/instances?q=${failing}`);
    const link = rowOf(page, failing).getByTestId('last-job-failed');
    await expect(link).toHaveText('Last job failed');
    await follow(page, link);
    await expect(page.getByTestId('job-status')).toHaveAttribute('data-status', 'failed');
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
    await expectNoBlockingViolations(page, 'the destroy dialog of a row');
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

  test('the selection drives the bar and its count; select all is the box in the header', async ({ page }) => {
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
    await expectNoBlockingViolations(page, 'the list with the bulk bar');
  });

  test('stop and start act on the selection and say how many jobs were queued', async ({ page }) => {
    const project = await ownProject();
    const ids = await deployMany(page, project, 3);
    await page.goto(`/instances?project=${project}`);
    await selectRows(page, ids);
    await submitAndWait(page, bulkButton(page, 'Stop'));
    await expect(page).toHaveURL(/\/instances$/);
    await expect(page.getByTestId('flashes')).toContainText('3 jobs queued.');
    await page.goto(`/instances?project=${project}`);
    for (const id of ids) {
      expect(await stateOf(page, id)).toBe('stopped');
    }

    // One instance is "1 job", and a mixed selection (stopped and running) is accepted.
    await selectRows(page, [ids[0]]);
    await submitAndWait(page, bulkButton(page, 'Start'));
    await expect(page.getByTestId('flashes')).toContainText('1 job queued.');
    await page.goto(`/instances?project=${project}`);
    expect(await stateOf(page, ids[0])).toBe('running');
    expect(await stateOf(page, ids[1])).toBe('stopped');

    await selectRows(page, ids);
    await submitAndWait(page, bulkButton(page, 'Start'));
    await expect(page.getByTestId('flashes')).toContainText('3 jobs queued.');
    await page.goto(`/instances?project=${project}`);
    for (const id of ids) {
      expect(await stateOf(page, id)).toBe('running');
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
    await expectNoBlockingViolations(page, 'the bulk redeploy dialog');
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

  test('an instance with a job running refuses the whole request and nothing is queued', async ({ page }) => {
    const project = await ownProject();
    const ids = await deployMany(page, project, 2);
    const release = await holdBusy(page, ids[0]);
    try {
      await page.goto(`/instances?project=${project}`);
      await selectRows(page, ids);
      await submitAndWait(page, bulkButton(page, 'Stop'));
      await expect(page.getByTestId('flashes')).toContainText(`Busy: ${ids[0]} has a job queued or running. Nothing was started.`);
      await page.goto(`/instances/${ids[1]}`);
      await expect(page.getByTestId('timeline-job')).toHaveCount(1);
      expect(await (await page.request.get(`/instances/${ids[1]}`)).text()).toContain('data-state="running"');
    } finally {
      release();
    }
  });

  test('a hand-edited bulk request without ids, with an unknown action, an unknown id or no token is refused', async ({ page }) => {
    const project = await ownProject();
    const id = await deployOne(page, project);
    const stop = (): Locator => bulkButton(page, 'Stop');
    async function open(): Promise<void> {
      await page.goto(`/instances?project=${project}`);
      await selectRows(page, [id]);
    }

    // Selected, then unticked without the page noticing: no id is posted.
    await open();
    expect(await submitTampered(page, stop(), (button) => ((button.form!.elements.namedItem('ids[]') as HTMLInputElement).checked = false))).toBe(303);
    await expect(page.getByTestId('flashes')).toContainText('Select at least one instance first.');

    await open();
    expect(await submitTampered(page, stop(), (button) => (button.value = 'explode'))).toBe(400);

    // One id that does not exist makes the whole request a 404; the real one is not touched.
    await open();
    expect(
      await submitTampered(
        page,
        stop(),
        (button, ghost) => {
          const field = document.createElement('input');
          field.type = 'hidden';
          field.name = 'ids[]';
          field.value = ghost;
          button.form!.append(field);
        },
        `${project}--nothing-here`,
      ),
    ).toBe(404);

    await open();
    expect(await submitTampered(page, stop(), (button) => button.form!.querySelectorAll('input[name="_token"]').forEach((field) => field.remove()))).toBe(403);
    await open();
    expect(await submitTampered(page, stop(), (button) => button.form!.querySelectorAll('input[name="_token"]').forEach((field) => ((field as HTMLInputElement).value = 'wrong')))).toBe(403);

    await page.goto(`/instances/${id}`);
    await expect(page.getByTestId('timeline-job')).toHaveCount(1);
    await expect(page.getByTestId('instance-detail')).toContainText('Running');
  });

  test('a long id keeps the list, the bar and the dialog inside the phone width', async ({ page }) => {
    test.skip(!isPhone(), 'phone width only');
    const project = uniqueName('lg').padEnd(40, 'x').slice(0, 40);
    await createDeployableProject(project);
    const id = await deployOne(page, project, 'l'.repeat(21));
    expect(id).toHaveLength(63);
    await page.goto(`/instances?project=${project}`);
    await selectRows(page, [id]);
    await expectNoOverflow(page, 'the list with the bulk bar');
    const dialog = await openDialog(page, bulkButton(page, 'Redeploy…'));
    await expect(dialog).toContainText(id);
    await expectNoOverflow(page, 'the bulk dialog');
    await expectNoBlockingViolations(page, 'phone, the bulk dialog with a long id');
  });
});

test.describe('instance page: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('shows the facts, the addresses, the actions of the state and the timeline', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto(`/instances/${id}`);
    await expect(page.getByRole('heading', { level: 1, name: id })).toBeVisible();
    const detail = page.getByTestId('instance-detail');
    await expect(detail).toContainText('Template');
    await expect(detail).toContainText('default');
    await expect(detail).toContainText('Requested by');
    await expect(detail).toContainText('Head');
    await expect(detail).toContainText('Memory');
    await expect(detail.getByRole('link', { name: new RegExp(`^${id}\\.`) })).toHaveAttribute('href', new RegExp(`^https://${id}\\.`));
    await expect(page.getByTestId('open-site')).toHaveAttribute('target', '_blank');

    const actions = page.getByTestId('instance-actions');
    await expect(actions.getByRole('button', { name: 'Stop', exact: true })).toBeEnabled();
    await expect(actions.getByRole('button', { name: 'Start', exact: true })).toHaveCount(0);
    await expect(actions.getByRole('button', { name: 'Redeploy' })).toBeEnabled();
    await expect(page.getByTestId('active-job')).toHaveCount(0);
    await expect(page.getByTestId('timeline-job')).toHaveCount(1);
    await expect(page.getByTestId('timeline-job')).toContainText('deploy');
    await expectNoBlockingViolations(page, 'the instance page');
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
    await expectNoBlockingViolations(page, 'the page of a stopped instance');
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
    await expectNoBlockingViolations(page, 'the destroy dialog');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByTestId('timeline-job')).toHaveCount(1);

    const again = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
    await follow(page, again.getByRole('button', { name: 'Destroy instance' }));
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByTestId('job-status')).toHaveAttribute('data-status', 'succeeded');
    expect((await page.goto(`/instances/${id}`))?.status()).toBe(404);
  });

  test('while a job runs the buttons are off; a stale page is refused with the reason', async ({ page }) => {
    const id = await deployOne(page);
    // Loaded before the job: the buttons are still on.
    await page.goto(`/instances/${id}`);
    const release = await holdBusy(page, id);
    try {
      await follow(page, page.getByTestId('instance-actions').getByRole('button', { name: 'Stop', exact: true }));
      await expect(page).toHaveURL(new RegExp(`/instances/${id}$`));
      await expect(page.getByTestId('flashes')).toContainText(`Busy: ${id} has a job queued or running. Nothing was started.`);

      // Loaded during the job: the buttons are off, the running job and its log are on the page.
      const actions = page.getByTestId('instance-actions');
      await expect(actions.getByRole('button', { name: 'Stop', exact: true })).toBeDisabled();
      await expect(actions.getByRole('button', { name: 'Redeploy' })).toBeDisabled();
      await expect(page.getByTestId('active-job')).toContainText('is running');
      await expect(page.getByTestId('active-job').getByTestId('job-log')).toContainText('fake line 1 of 300');

      // Destroy is not switched off, the service says no.
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
      await follow(page, dialog.getByRole('button', { name: 'Destroy instance' }));
      await expect(page.getByTestId('flashes')).toContainText(`Busy: ${id} has a job queued or running.`);
      await expectNoBlockingViolations(page, 'the page of a busy instance');
    } finally {
      release();
    }
  });

  test('unknown ids, bad actions and a missing token', async ({ page }) => {
    const id = await deployOne(page);
    expect((await page.goto(`/instances/${ADMIN_PROJECT}--nothing-here`))?.status()).toBe(404);
    expect((await page.goto('/instances/Not_An_Id'))?.status()).toBe(404);
    expect((await page.goto(`/instances/${id}/stop`))?.status()).toBe(405);
    expect((await postForm(page, `/instances/${id}/explode`, { _token: 'x' })).status()).toBe(404);
    expect((await postForm(page, `/instances/${id}/stop`)).status()).toBe(403);
    expect((await postForm(page, `/instances/${ADMIN_PROJECT}--nothing-here/stop`, { _token: 'x' })).status()).toBe(404);
    await page.goto(`/instances/${id}`);
    await expect(page.getByTestId('timeline-job')).toHaveCount(1);
  });

  test('fits the phone width with a long id, a long branch and a long log line', async ({ page }) => {
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

test.describe('instances: member', () => {
  test('a member is refused what the role does not allow, and sees only their project', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
    const own = await deployOne(page, MEMBER_PROJECT);
    const foreign = await deployOne(page, ADMIN_PROJECT);

    await setStandInRole(context, 'member', baseURL!);
    await page.goto('/instances');
    await expect(rowOf(page, own)).toBeVisible();
    // Neither the project list nor the rows give away the foreign project.
    await expect(page.getByTestId('instance-filters').getByLabel('Project').locator('option')).toHaveText(['All projects', MEMBER_PROJECT]);
    await page.goto(`/instances?project=${ADMIN_PROJECT}`);
    await expect(page.getByTestId('instance-row')).toHaveCount(0);
    await expect(page.getByTestId('instances-empty')).toContainText('No instance matches');

    // The row menu has Redeploy only; the bulk bar has no Destroy.
    await page.goto(`/instances?q=${own}`);
    await rowOf(page, own).getByLabel(`More actions for ${own}`).click();
    await expect(page.getByRole('menuitem')).toHaveText(['Redeploy']);
    await page.keyboard.press('Escape');
    await selectRows(page, [own]);
    await expect(bulkButton(page, 'Redeploy…')).toBeVisible();
    await expect(bulkButton(page, 'Destroy…')).toHaveCount(0);
    await expectNoBlockingViolations(page, 'a member, the list with the bulk bar');

    // The requests no button sends, made by editing the page: the token is the member's own, so the answer is the role's.
    await page.goto(`/instances?q=${own}`);
    await selectRows(page, [own]);
    expect(await submitTampered(page, bulkButton(page, 'Stop'), (button) => (button.value = 'destroy'))).toBe(403);
    await page.goto(`/instances?q=${own}`);
    await selectRows(page, [own]);
    const addForeign = (button: HTMLButtonElement, ghost: string): void => {
      const field = document.createElement('input');
      field.type = 'hidden';
      field.name = 'ids[]';
      field.value = ghost;
      button.form!.append(field);
    };
    expect(await submitTampered(page, bulkButton(page, 'Stop'), addForeign, foreign)).toBe(404);
    await page.goto(`/instances/${own}`);
    const redeploy = page.getByTestId('instance-actions').getByRole('button', { name: 'Redeploy' });
    expect(await submitTampered(page, redeploy, (button, to) => button.setAttribute('formaction', to), `/instances/${own}/destroy`)).toBe(403);
    await page.goto(`/instances/${own}`);
    expect(await submitTampered(page, redeploy, (button, to) => button.setAttribute('formaction', to), `/instances/${foreign}/stop`)).toBe(404);
    await page.goto(`/instances/${own}`);
    await expect(page.getByTestId('timeline-job')).toHaveCount(1);

    // What the member may do, the bulk Stop, works on their own instance.
    await page.goto(`/instances?q=${own}`);
    await selectRows(page, [own]);
    await submitAndWait(page, bulkButton(page, 'Stop'));
    await expect(page.getByTestId('flashes')).toContainText('1 job queued.');
    await page.goto(`/instances?q=${own}`);
    expect(await stateOf(page, own)).toBe('stopped');
    await page.goto(`/instances/${own}`);
    await expect(page.getByTestId('instance-actions').getByRole('button', { name: 'Start', exact: true })).toBeVisible();
  });
});
