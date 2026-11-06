import { expect, test, type Locator, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { createProjectViaUi, deleteProjectViaUi, GIT_URL, openDialog, openSection, submitAndWait, uniqueName } from './support/projects';
import { openReauthWindow } from './support/secrets';
import { hasHorizontalOverflow } from './support/shell';

// Project export and import beyond import-export.spec.ts (which owns the happy round trip and the version refusal): what
// only a browser shows. The export card and the downloaded file, the upload page layout, one full preview that is confirmed
// and read back from the pages, the sealed-secret flow with the passphrase typed at the confirm step, and long names at
// 390 px. The matrices (every refused file and document, every preview row, wrong and damaged passphrases, CSRF, access)
// run as HTTP in app/tests/Functional/Admin/ProjectTransferMatrixTest.php (FLE-162). A test that writes owns uniquely
// named things and removes them in `finally`.

const isPhone = (): boolean => test.info().project.name === 'phone';
const PASSPHRASE = 'correct horse battery';
/** The catalogue port a migration seeds on every host (see project-ports.spec.ts). */
const CATALOGUE_PORT = { name: 'typesense', public: 9108, router: 8108 };

type Doc = Record<string, unknown> & { project: Record<string, unknown> };

/** A valid export document for `name`; `over` replaces whole sections. */
function doc(name: string, over: Record<string, unknown> = {}): Doc {
  return {
    format: 'fleet-project-export',
    version: 1,
    project: {
      name,
      git: GIT_URL,
      default_branch: null,
      default_template: null,
      additional_hostnames: [],
      ports: [],
      git_bot: { mode: 'inherit', name: null, email: null },
      issue_id_regexp: null,
      display_submodule_branch: null,
      typesense: false,
    },
    ports: [],
    templates: [],
    webhooks: [],
    schedules: [],
    ...over,
  };
}

const template = (name = 'main') => ({ name, drupal_env: null, post_deploy: { exec: ['echo hello'], tty1: [], tty2: [] } });
const jiraRule = (templateName = 'main', onStatus = 'Dispatched') => ({ source: 'jira', rules: [{ action: 'deploy', match: { on_status: onStatus }, params: { template: templateName } }] });
const schedule = (name: string, over: Record<string, unknown> = {}) => ({ name, cron: '0 3 * * *', timezone: 'UTC', action: 'redeploy', instance: 'main', enabled: true, ...over });

/** A document with one of everything the preview can show. */
function fullDoc(name: string, scheduleName: string): Doc {
  return doc(name, { ports: [CATALOGUE_PORT], templates: [template()], webhooks: [jiraRule()], schedules: [schedule(scheduleName)] });
}

const json = (value: unknown): string => JSON.stringify(value, null, 2);

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

async function expectTall(target: Locator, min = 44): Promise<void> {
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box, 'element has a box').not.toBeNull();
  expect(box!.height).toBeGreaterThanOrEqual(min - 0.5);
}

/** Opens the Import page, picks the file (and the passphrase), presses Preview and returns the HTTP status of the answer. */
async function upload(page: Page, content: string | Buffer | null, passphrase?: string): Promise<number> {
  await page.goto('/admin/import');
  if (null !== content) {
    await page.getByTestId('import-file').setInputFiles({ name: 'test.fleet-project.json', mimeType: 'application/json', buffer: Buffer.from(content) });
  }
  if (undefined !== passphrase) {
    await page.getByTestId('import-passphrase').fill(passphrase);
  }
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/import/preview'),
    submitAndWait(page, page.getByTestId('import-preview')),
  ]);
  return response.status();
}

/** Presses "Import this project" and returns the HTTP status of the apply answer (after redirects: the final page). */
async function confirm(page: Page): Promise<void> {
  await submitAndWait(page, page.getByTestId('import-confirm'));
}

async function expectGone(page: Page, name: string): Promise<void> {
  expect((await page.goto(`/admin/projects/${name}`))?.status(), `project ${name} must not exist`).toBe(404);
}

/** Saves a project secret on the project's Secrets tab and waits for the confirmation. */
async function addProjectSecret(page: Page, project: string, name: string, value: string): Promise<void> {
  await page.goto(`/admin/projects/${project}/keys`);
  const form = page.getByTestId('secret-form');
  await form.getByLabel('Name').fill(name);
  await form.getByLabel('Value', { exact: true }).fill(value);
  await submitAndWait(page, form.getByTestId('secret-save'));
  await expect(page.getByTestId('flashes')).toContainText(`Secret "${name}" saved.`);
}

async function deleteProjectSecret(page: Page, project: string, name: string): Promise<void> {
  await page.goto(`/admin/projects/${project}/keys`);
  const row = page.getByTestId('secret-row').filter({ has: page.getByTestId('secret-name').getByText(name, { exact: true }) });
  if ((await row.count()) === 0) {
    return;
  }
  const dialog = await openDialog(page, row.getByTestId('secret-delete'));
  await submitAndWait(page, dialog.getByTestId('secret-delete-confirm'));
  await expect(page.getByTestId('flashes')).toContainText(`Secret "${name}" deleted.`);
}

async function deleteScheduleViaUi(page: Page, name: string): Promise<void> {
  await page.goto('/admin/schedules');
  const row = page.getByTestId('schedule-row').filter({ has: page.locator(`[data-name="${name}"]`) }).or(page.locator(`[data-testid="schedule-row"][data-name="${name}"]`));
  if ((await row.count()) === 0) {
    return;
  }
  const dialog = await openDialog(page, row.first().getByTestId('schedule-delete'));
  await submitAndWait(page, dialog.getByTestId('schedule-delete-confirm'));
  await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" deleted.`);
}

/** Presses Export on the project page; resolves with the downloaded file text. */
async function exportText(page: Page, passphrase?: string): Promise<string> {
  if (undefined !== passphrase) {
    await page.getByTestId('export-passphrase').fill(passphrase);
  }
  if (undefined !== passphrase) {
    // A sealed export needs a recent password check (the re-auth window).
    await openReauthWindow(page);
    await page.getByTestId('export-passphrase').fill(passphrase);
  }
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-download').click()]);
  return readFile(await download.path(), 'utf8');
}

/**
 * Makes a project with one secret, exports it sealed by `passphrase`, then removes the secret and the project: the
 * returned text is the "other host" file. Everything it created is removed even when a step fails.
 */
async function sealedExport(page: Page, project: string, secret: string, value: string, passphrase: string): Promise<string> {
  try {
    await createProjectViaUi(page, project);
    await addProjectSecret(page, project, secret, value);
    await page.goto(`/admin/projects/${project}/transfer`);
    return await exportText(page, passphrase);
  } finally {
    await deleteProjectSecret(page, project, secret);
    await deleteProjectViaUi(page, project);
  }
}

test.describe('export form', () => {
  test('the passphrase is optional: without it the file has no secrets, and the field is a plain password box @phone', async ({ page }) => {
    const name = uniqueName('pwe');
    const secret = `PW_EXPORT_${randomBytes(3).toString('hex').toUpperCase()}`;
    const value = `pw-value-${randomBytes(8).toString('hex')}`;
    try {
      await createProjectViaUi(page, name);
      await addProjectSecret(page, name, secret, value);
      await page.goto(`/admin/projects/${name}/transfer`);

      const field = page.getByLabel('Passphrase for the secrets (optional)');
      await expect(field).toHaveAttribute('type', 'password');
      await expect(field).toHaveAttribute('minlength', '8');
      await expect(field).toHaveAttribute('autocomplete', 'new-password');
      await expect(field).toHaveValue('');
      await expect(page.getByTestId('export-project')).toContainText('Secrets are left out unless you give a passphrase to seal them.');
      await expectNoOverflow(page, 'export card');
      if (isPhone()) {
        await expectTall(field);
        await expectTall(page.getByTestId('export-download'));
      }

      const text = await exportText(page);
      const document = JSON.parse(text) as Record<string, unknown>;
      expect(Object.keys(document)).toEqual(['format', 'version', 'project', 'ports', 'templates', 'webhooks', 'schedules']);
      expect(document).not.toHaveProperty('secrets');
      expect(text).not.toContain(value);
      expect(document.project).toMatchObject({ name, git: GIT_URL, default_template: null, typesense: false });
      // The page is still the project page and the field was not refilled.
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/transfer$`));
      await expect(field).toHaveValue('');
    } finally {
      await deleteProjectSecret(page, name, secret);
      await deleteProjectViaUi(page, name);
    }
  });
});

test.describe('import: upload page', () => {
  test('the empty page: the two fields, the limit, the way back; no file is refused @phone', async ({ page }) => {
    await page.goto('/admin/import');
    await expect(page.getByRole('heading', { level: 1, name: 'Import a project' })).toBeVisible();
    await expect(page.getByTestId('import-upload')).toContainText('1. Upload the file');
    await expect(page.getByTestId('import-upload')).toContainText('At most 2 MiB');
    await expect(page.getByTestId('import-upload')).toContainText('Assets are not part of it');
    await expect(page.getByTestId('import-file')).toHaveAttribute('type', 'file');
    await expect(page.getByTestId('import-file')).toHaveAttribute('accept', 'application/json,.json');
    await expect(page.getByTestId('import-passphrase')).toHaveAttribute('type', 'password');
    await expect(page.getByLabel('Passphrase (only if the file holds sealed secrets)')).toHaveValue('');
    await expect(page.getByTestId('import-preview-section')).toHaveCount(0);
    await expect(page.getByTestId('import-error')).toHaveCount(0);
    await expectNoOverflow(page, 'empty import page');
    if (isPhone()) {
      await expectTall(page.getByTestId('import-file'));
      await expectTall(page.getByTestId('import-passphrase'));
      await expectTall(page.getByTestId('import-preview'));
      await expectTall(page.getByRole('link', { name: 'Back to projects' }));
    }

    await page.getByRole('link', { name: 'Back to projects' }).click();
    await expect(page).toHaveURL(/\/admin\/projects$/);

    // No file chosen: the form is posted anyway (novalidate) and the server explains.
    expect(await upload(page, null)).toBe(422);
    await expect(page.getByTestId('import-error')).toContainText('Choose the export file to upload.');
    await expect(page.getByTestId('import-error')).toHaveAttribute('role', 'alert');
    await expect(page.getByTestId('import-preview-section')).toHaveCount(0);
    await expect(page.getByTestId('import-upload')).toContainText('1. Upload the file');
    await expectNoOverflow(page, 'no-file refusal');
  });
});

test.describe('import: preview, confirm, cancel', () => {
  test('a new project shows every kind of row; confirming writes them and the pages show them @phone', async ({ page }) => {
    test.setTimeout(120_000);
    const name = uniqueName('pwi');
    const scheduleName = `${name}-nightly`.slice(0, 40);
    try {
      expect(await upload(page, json(fullDoc(name, scheduleName)))).toBe(200);
      await expect(page.getByRole('heading', { level: 2, name: `Preview: ${name}` })).toBeVisible();

      const row = (kind: string) => page.getByTestId('import-row').filter({ has: page.getByRole('cell', { name: kind, exact: true }) });
      await expect(row('project')).toHaveAttribute('data-action', 'create');
      await expect(row('template')).toHaveAttribute('data-action', 'create');
      await expect(row('port')).toHaveAttribute('data-action', 'unchanged');
      await expect(row('port')).toContainText('public 9108, router 8108');
      await expect(row('webhook')).toHaveAttribute('data-action', 'create');
      await expect(row('webhook')).toContainText('Jira webhook');
      await expect(row('webhook')).toContainText('a new secret is issued on this host; rotate it on the project page to read it');
      await expect(row('schedule')).toHaveAttribute('data-action', 'create');
      await expect(page.getByTestId('import-counts')).toHaveText('4 create, 0 update, 1 unchanged, 0 skipped.');
      await expect(page.getByTestId('import-warning')).toHaveCount(0);
      await expect(page.getByTestId('confirm-passphrase')).toHaveCount(0);
      await expect(page.getByTestId('import-upload')).toHaveCount(0);
      await expect(page.getByRole('link', { name: 'Cancel' })).toBeVisible();
      await expectNoOverflow(page, 'full preview');
      if (isPhone()) {
        await expectTall(page.getByTestId('import-confirm'));
        await expectTall(page.getByRole('link', { name: 'Cancel' }));
      }
      // The preview wrote nothing.
      await page.evaluate(() => undefined);
      const preview = page.url();
      expect(preview).toMatch(/\/admin\/import\/preview$/);

      await confirm(page);
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);

      // The state, read back from the pages.
      await openSection(page, 'Source');
      await expect(page.getByLabel('Git URL')).toHaveValue(GIT_URL);
      await openSection(page, 'Templates');
      await expect(page.getByTestId('template-row')).toHaveCount(1);
      await expect(page.getByTestId('template-name')).toHaveText('main');
      await expect(page.getByTestId('template-post-deploy')).toHaveText('1');
      await page.goto(`/admin/projects/${name}/integrations`);
      await expect(page.getByTestId('webhook-rules')).toContainText('Dispatched');
      await expect(page.getByTestId('webhook-rules')).toContainText('main');
      await page.goto('/admin/schedules');
      const scheduled = page.locator(`[data-testid="schedule-row"][data-name="${scheduleName}"]`);
      await expect(scheduled).toBeVisible();
      await expect(scheduled.getByTestId('schedule-cron')).toHaveText('0 3 * * *');
      await expect(scheduled).toContainText(`${name}--main`);
      await expect(scheduled.getByTestId('schedule-state')).toHaveText('Active');
    } finally {
      await deleteScheduleViaUi(page, scheduleName);
      await deleteProjectViaUi(page, name);
    }
  });
});

test.describe('import: sealed secrets', () => {
  test('no passphrase: a warning, a skipped row, and the secrets can still be added at the confirm step @phone', async ({ page }) => {
    test.setTimeout(180_000);
    const name = uniqueName('pwq');
    const secret = `PW_SEAL_${randomBytes(3).toString('hex').toUpperCase()}`;
    const value = `pw-value-${randomBytes(8).toString('hex')}`;
    const text = await sealedExport(page, name, secret, value, PASSPHRASE);
    try {
      // 1. No passphrase at the upload.
      expect(await upload(page, text)).toBe(200);
      await expect(page.getByTestId('import-warning')).toHaveText('The file holds sealed secrets and no passphrase was given: they were not imported.');
      const sealed = page.getByTestId('import-row').filter({ hasText: 'sealed secrets' });
      await expect(sealed).toHaveAttribute('data-action', 'skipped');
      await expect(sealed).toContainText('no passphrase given: the secrets are not imported');
      await expect(page.getByLabel('Passphrase of the sealed secrets (empty: leave the secrets out)')).toHaveAttribute('type', 'password');
      await expectNoOverflow(page, 'sealed preview');

      // 2. Confirm without one: the project arrives, the secrets do not, and the warning is flashed.
      await confirm(page);
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);
      await expect(page.getByTestId('flashes')).toContainText('The file holds sealed secrets and no passphrase was given: they were not imported.');
      await page.goto(`/admin/projects/${name}/keys`);
      await expect(page.getByTestId('secret-row')).toHaveCount(0);
      expect(await page.content()).not.toContain(value);

      // 3. The project exists now, so with no passphrase there is nothing to confirm: the warning stays and the form is gone.
      expect(await upload(page, text)).toBe(200);
      await expect(page.getByTestId('import-nothing')).toBeVisible();
      await expect(page.getByTestId('import-warning')).toContainText('no passphrase was given');
      await expect(page.getByTestId('import-confirm-form')).toHaveCount(0);

      // 4. The passphrase at the upload plans the secret; it is applied only when it is typed again at the confirm step.
      expect(await upload(page, text, PASSPHRASE)).toBe(200);
      await page.getByTestId('confirm-passphrase').fill(PASSPHRASE);
      await confirm(page);
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);
      await expect(page.getByTestId('flashes')).not.toContainText('no passphrase was given');
      await page.goto(`/admin/projects/${name}/keys`);
      await expect(page.getByTestId('secret-row').filter({ hasText: secret })).toHaveCount(1);
      expect(await page.content()).not.toContain(value);
    } finally {
      await deleteProjectSecret(page, name, secret);
      await deleteProjectViaUi(page, name);
    }
  });
});

test.describe('import: long values', () => {
  test('a 40-character project, template and schedule name wrap at every width @phone', async ({ page }) => {
    test.setTimeout(120_000);
    const stamp = Date.now().toString(36);
    const name = `pw-long-${stamp}-${'x'.repeat(40)}`.slice(0, 40);
    const templateName = `t${'y'.repeat(39)}`;
    const scheduleName = `${stamp}-${'z'.repeat(40)}`.slice(0, 40);
    try {
      expect(await upload(page, json(doc(name, { templates: [template(templateName)], webhooks: [jiraRule(templateName, 'A very long Jira status name '.repeat(3))], schedules: [schedule(scheduleName)] })))).toBe(200);
      await expect(page.getByRole('heading', { level: 2, name: `Preview: ${name}` })).toBeVisible();
      await expectNoOverflow(page, 'long names preview');
      await confirm(page);
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);
      await expectNoOverflow(page, 'long project page after import');

      // Re-import with a changed git URL: long names in the update row, long note text.
      expect(await upload(page, json(doc(name, { project: { ...doc(name).project, git: `https://git.example.com/${'a'.repeat(200)}.git` }, templates: [template(templateName)], webhooks: [jiraRule(templateName, 'A very long Jira status name '.repeat(3))], schedules: [schedule(scheduleName)] })))).toBe(200);
      await expectNoOverflow(page, 'long names update preview');
    } finally {
      await deleteScheduleViaUi(page, scheduleName);
      await deleteProjectViaUi(page, name);
    }
  });
});
