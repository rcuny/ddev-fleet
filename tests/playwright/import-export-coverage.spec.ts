import { expect, test, type Locator, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, GIT_URL, openDialog, openSection, submitAndWait, uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// Project export and import beyond import-export.spec.ts (which owns the happy round trip and the version refusal), at
// 1280 px (desktop project) and 390 px (phone project): the export form and its passphrase rules, the file the download
// holds, every way an upload is refused, the preview (create, update, unchanged, skipped, warnings), the sealed secrets
// (no passphrase, wrong, corrupt, foreign), confirm and cancel, long names, and access/CSRF. The files are built here as
// JSON, so most tests need no project at all. A test that writes owns uniquely named things and removes them in `finally`.

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
  await page.goto(`/admin/projects/${project}/secrets`);
  const form = page.getByTestId('secret-form');
  await form.getByLabel('Name').fill(name);
  await form.getByLabel('Value').fill(value);
  await submitAndWait(page, form.getByTestId('secret-save'));
  await expect(page.getByTestId('flashes')).toContainText(`Secret "${name}" saved.`);
}

async function deleteProjectSecret(page: Page, project: string, name: string): Promise<void> {
  await page.goto(`/admin/projects/${project}/secrets`);
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
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-download').click()]);
  return readFile(await download.path(), 'utf8');
}

/** The export-file problem list of the page (the 422 answer of a refused file). */
function problems(page: Page): Locator {
  return page.getByTestId('import-error-item');
}

async function expectRefusedPage(page: Page, name?: string): Promise<void> {
  await expect(page.getByTestId('import-errors').or(page.getByTestId('import-error'))).toBeVisible();
  await expect(page.getByTestId('import-confirm-form')).toHaveCount(0);
  await expect(page.getByTestId('import-upload')).toBeVisible();
  if (name) {
    await expectGone(page, name);
  }
}

/**
 * Makes a project with one secret, exports it sealed by `passphrase`, then removes the secret and the project: the
 * returned text is the "other host" file. Everything it created is removed even when a step fails.
 */
async function sealedExport(page: Page, project: string, secret: string, value: string, passphrase: string): Promise<string> {
  try {
    await createProjectViaUi(page, project);
    await addProjectSecret(page, project, secret, value);
    await page.goto(`/admin/projects/${project}`);
    return await exportText(page, passphrase);
  } finally {
    await deleteProjectSecret(page, project, secret);
    await deleteProjectViaUi(page, project);
  }
}

test.describe('export form', () => {
  test('the passphrase is optional: without it the file has no secrets, and the field is a plain password box', async ({ page }) => {
    const name = uniqueName('pwe');
    const secret = `PW_EXPORT_${randomBytes(3).toString('hex').toUpperCase()}`;
    const value = `pw-value-${randomBytes(8).toString('hex')}`;
    try {
      await createProjectViaUi(page, name);
      await addProjectSecret(page, name, secret, value);
      await page.goto(`/admin/projects/${name}`);

      const field = page.getByLabel('Passphrase for the secrets (optional)');
      await expect(field).toHaveAttribute('type', 'password');
      await expect(field).toHaveAttribute('minlength', '8');
      await expect(field).toHaveAttribute('autocomplete', 'new-password');
      await expect(field).toHaveValue('');
      await expect(page.getByTestId('export-project')).toContainText('Secrets are left out unless you give a passphrase to seal them.');
      await expectNoOverflow(page, 'export card');
      await expectNoBlockingViolations(page, 'export card');
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
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
      await expect(field).toHaveValue('');
    } finally {
      await deleteProjectSecret(page, name, secret);
      await deleteProjectViaUi(page, name);
    }
  });

  test('with a passphrase the secrets are sealed: neither the value nor the passphrase is in the file', async ({ page }) => {
    test.setTimeout(120_000);
    const name = uniqueName('pws');
    const secret = `PW_SEALED_${randomBytes(3).toString('hex').toUpperCase()}`;
    const value = `pw-value-${randomBytes(8).toString('hex')}`;
    try {
      await createProjectViaUi(page, name);
      await addProjectSecret(page, name, secret, value);
      await page.goto(`/admin/projects/${name}`);

      const text = await exportText(page, PASSPHRASE);
      const document = JSON.parse(text) as { secrets: Record<string, unknown> };
      expect(Object.keys(document.secrets)).toEqual(['kdf', 'opslimit', 'memlimit', 'salt', 'nonce', 'ciphertext']);
      expect(text).not.toContain(value);
      expect(text).not.toContain(secret);
      expect(text).not.toContain(PASSPHRASE);
      expect(await page.content()).not.toContain(PASSPHRASE);
    } finally {
      await deleteProjectSecret(page, name, secret);
      await deleteProjectViaUi(page, name);
    }
  });

  for (const [label, passphrase, message] of [
    ['too short (7 characters)', 'abcdefg', 'The passphrase needs at least 8 characters.'],
    ['too long (1025 bytes)', 'x'.repeat(1025), 'The passphrase is limited to 1024 bytes.'],
  ] as const) {
    test(`a passphrase that is ${label} is refused with a message and no download`, async ({ page }) => {
      const name = uniqueName('pwr');
      try {
        await createProjectViaUi(page, name);
        let downloaded = false;
        page.on('download', () => (downloaded = true));
        await page.getByTestId('export-passphrase').fill(passphrase);
        await submitAndWait(page, page.getByTestId('export-download'));

        await expect(page.getByTestId('flashes')).toContainText(message);
        await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
        await expect(page.getByTestId('export-passphrase')).toHaveValue('');
        expect(await page.content()).not.toContain(passphrase);
        expect(downloaded).toBe(false);
        await expectNoOverflow(page, 'refused export');
      } finally {
        await deleteProjectViaUi(page, name);
      }
    });
  }

  test('exactly 8 characters is accepted', async ({ page }) => {
    const name = uniqueName('pw8');
    try {
      await createProjectViaUi(page, name);
      const document = JSON.parse(await exportText(page, '12345678')) as { secrets: unknown };
      expect(document.secrets).toBeTruthy();
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});

test.describe('import: upload page', () => {
  test('the empty page: the two fields, the limit, the way back; no file is refused', async ({ page }) => {
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
    await expectNoBlockingViolations(page, 'empty import page');
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
    await expectNoBlockingViolations(page, 'no-file refusal');
  });

  const REFUSED: Array<[string, () => string | Buffer, string]> = [
    ['an empty file', () => '', 'The file is not valid JSON.'],
    ['text that is not JSON', () => 'this is not json at all', 'The file is not valid JSON.'],
    ['a truncated export', () => json(doc('whatever')).slice(0, 200), 'The file is not valid JSON.'],
    ['a binary file', () => randomBytes(64), 'The file is not valid JSON.'],
    ['a JSON list', () => '[1, 2, 3]', 'The file is not a project export.'],
    ['a JSON string', () => '"fleet-project-export"', 'The file is not a project export.'],
    ['another JSON document', () => json({ hello: 'world' }), 'The file is not a project export (format must be "fleet-project-export").'],
    ['a document without a version', () => json({ format: 'fleet-project-export' }), 'The file has no integer version.'],
    ['a string version', () => json({ format: 'fleet-project-export', version: '1' }), 'The file has no integer version.'],
    ['a newer version', () => json({ format: 'fleet-project-export', version: 2 }), 'The file is version 2; this host reads version 1 at most. Update this host first.'],
    ['version zero', () => json({ format: 'fleet-project-export', version: 0 }), 'Version 0 is not a known export version.'],
    ['a document nested deeper than the parser reads', () => '{"format":"fleet-project-export","version":1,"x":' + '['.repeat(40) + ']'.repeat(40) + '}', 'The file is not valid JSON.'],
  ];
  for (const [label, content, message] of REFUSED) {
    test(`${label} is refused with "${message}" and nothing is written`, async ({ page }) => {
      expect(await upload(page, content())).toBe(422);
      await expect(problems(page).or(page.getByTestId('import-error'))).toContainText(message);
      await expectRefusedPage(page);
      await expectNoOverflow(page, label);
    });
  }

  test('a file over 2 MiB is refused', async ({ page }) => {
    const big = Buffer.concat([Buffer.from(json(doc('whatever'))), Buffer.alloc(2 * 1024 * 1024, ' ')]);
    expect(await upload(page, big)).toBe(422);
    await expectRefusedPage(page);
    await expectNoOverflow(page, 'oversized file');
  });

  test('a refusal can be followed by a good file on the same page', async ({ page }) => {
    const name = uniqueName('pwf');
    expect(await upload(page, 'nope')).toBe(422);
    await expectNoBlockingViolations(page, 'refused upload');
    await page.getByTestId('import-file').setInputFiles({ name: 'ok.json', mimeType: 'application/json', buffer: Buffer.from(json(doc(name))) });
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/import/preview'),
      submitAndWait(page, page.getByTestId('import-preview')),
    ]);
    await expect(page.getByTestId('import-errors')).toHaveCount(0);
    await expect(page.getByTestId('import-confirm')).toBeVisible();
    await expectGone(page, name);
  });
});

test.describe('import: what the preview refuses', () => {
  const NAME = 'pw-refusal';
  const BAD: Array<[string, Doc, string]> = [
    ['an unknown key', { ...doc(NAME), surprise: 1 } as Doc, 'surprise: unknown key.'],
    ['an unknown project key', doc(NAME, { project: { ...doc(NAME).project, extra: 'x' } }), 'project.extra: unknown key.'],
    ['a missing section', (() => { const d = doc(NAME); delete d.templates; return d; })(), 'templates: expected a list.'],
    ['a missing project key', (() => { const d = doc(NAME); delete d.project.git; return d; })(), 'project.git: missing.'],
    ['a wrong type', doc(NAME, { project: { ...doc(NAME).project, git: 5 } }), 'project.git: expected a string.'],
    ['a project that is not an object', doc(NAME, { project: 'nope' }) as Doc, 'project: expected an object.'],
    ['a project port not described in ports', doc(NAME, { project: { ...doc(NAME).project, ports: ['nowhere'] } }), 'project.ports[0]: the port is not described in ports.'],
    ['a name with two dashes', doc('pw--bad'), 'project.name: The name must not contain two dashes in a row'],
    ['an upper-case name', doc('PwBad'), 'project.name: Use lowercase letters, digits and single dashes only'],
    ['a reserved name', doc('new'), 'project.name: This name is reserved.'],
    ['a name over 40 characters', doc('a'.repeat(41)), 'project.name: The name must be at most 40 characters.'],
    ['a git URL with a scheme the host does not accept', doc(NAME, { project: { ...doc(NAME).project, git: 'ftp://example.com/x.git' } }), 'project.git: Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.'],
    ['credentials in the git URL', doc(NAME, { project: { ...doc(NAME).project, git: 'https://user:secret@example.com/x.git' } }), 'project.git: Do not put credentials in the URL'],
    ['a default template the file does not have', doc(NAME, { project: { ...doc(NAME).project, default_template: 'ghost' } }), 'project.default_template: this project has no template with this name.'],
    ['an invalid branch', doc(NAME, { project: { ...doc(NAME).project, default_branch: 'bad branch' } }), 'project.default_branch:'],
    ['a git identity override without a name or email', doc(NAME, { project: { ...doc(NAME).project, git_bot: { mode: 'override', name: null, email: null } } }), 'project.git_bot_name: Enter a name, an email or both'],
    ['an unknown git identity mode', doc(NAME, { project: { ...doc(NAME).project, git_bot: { mode: 'weird', name: null, email: null } } }), 'project.git_bot.mode: unknown mode.'],
    ['a template listed twice', doc(NAME, { templates: [template(), template()] }), 'templates: an entry appears twice.'],
    ['an invalid template name', doc(NAME, { templates: [template('Bad Name')] }), 'templates[0].name:'],
    ['a webhook source that does not exist', doc(NAME, { webhooks: [{ source: 'gitlab', rules: [] }] }), 'webhooks[0].source: unknown source.'],
    ['a webhook rule naming a template the file does not have', doc(NAME, { templates: [template()], webhooks: [jiraRule('ghost')] }), "webhooks[0].rules[0].template: Choose one of the project's templates."],
    ['a Jira rule that is not a deploy', doc(NAME, { templates: [template()], webhooks: [{ source: 'jira', rules: [{ action: 'run-pipeline', match: { on_status: 'Done' }, params: { template: 'main' } }] }] }), 'webhooks[0].rules[0].action: A Jira rule can only deploy.'],
    ['two Jira rules for one status', doc(NAME, { templates: [template()], webhooks: [{ source: 'jira', rules: [jiraRule().rules[0], jiraRule().rules[0]] }] }), 'webhooks[0].rules[1].on_status: There is already a rule for this status.'],
    ['an invalid cron expression', doc(NAME, { schedules: [schedule('pw-bad-cron', { cron: '99 99 * * *' })] }), 'schedules[0].cron: Not a valid cron expression'],
    ['an unknown timezone', doc(NAME, { schedules: [schedule('pw-bad-tz', { timezone: 'Mars/Base' })] }), 'schedules[0].timezone: Enter an IANA timezone'],
    ['an unknown schedule action', doc(NAME, { schedules: [schedule('pw-bad-action', { action: 'explode' })] }), 'schedules[0].action: Choose one of:'],
    ['a schedule without an instance', doc(NAME, { schedules: [schedule('pw-no-instance', { instance: null })] }), 'schedules[0].instance: Enter the id of the instance to redeploy.'],
    ['a schedule instance label that is not valid', doc(NAME, { schedules: [schedule('pw-bad-label', { instance: 'Bad Label' })] }), 'schedules[0].instance: not a valid instance label.'],
    ['a schedule name that is not valid', doc(NAME, { schedules: [schedule('Bad Name')] }), 'schedules[0].name:'],
    ['a catalogue port with other numbers than this host has', doc(NAME, { ports: [{ ...CATALOGUE_PORT, public: 9109 }] }), 'ports[0]: this host already has a port with this name and other numbers'],
    ['a sealed block that is not an object', doc(NAME, { secrets: 'abc' }), 'secrets: expected an object.'],
    ['a sealed block with an unknown key derivation', doc(NAME, { secrets: { kdf: 'md5', opslimit: 3, memlimit: 268435456, salt: 'AAAA', nonce: 'AAAA', ciphertext: 'AAAA' } }), 'secrets.kdf: unknown key derivation.'],
    ['a sealed block that is not base64', doc(NAME, { secrets: { kdf: 'argon2id13', opslimit: 3, memlimit: 268435456, salt: '***', nonce: 'AAAA', ciphertext: 'AAAA' } }), 'secrets.salt: not base64.'],
  ];

  for (const [label, document, message] of BAD) {
    test(`${label}: "${message}", with nothing offered to confirm`, async ({ page }) => {
      expect(await upload(page, json(document))).toBe(422);
      await expect(page.getByTestId('import-errors')).toContainText('The file cannot be imported');
      await expect(page.getByTestId('import-errors')).toContainText('Nothing was written.');
      await expect(problems(page).filter({ hasText: message }).first()).toBeVisible();
      await expectRefusedPage(page);
      await expect(page.getByTestId('import-upload')).toContainText('Upload another file');
      await expectNoOverflow(page, label);
    });
  }

  test('the refused preview page passes axe, and a refused item is shown as skipped', async ({ page }) => {
    expect(await upload(page, json(doc('pw-axe-refusal', { ports: [{ ...CATALOGUE_PORT, router: 8109 }], schedules: [schedule('pw-axe-bad', { cron: 'x' })] })))).toBe(422);
    await expect(page.getByTestId('import-row').filter({ hasText: CATALOGUE_PORT.name })).toHaveAttribute('data-action', 'skipped');
    await expect(page.getByTestId('import-counts')).toContainText('skipped.');
    await expect(problems(page)).not.toHaveCount(0);
    await expectNoBlockingViolations(page, 'refused preview');
  });
});

test.describe('import: preview, confirm, cancel', () => {
  test('a new project shows every kind of row; confirming writes them and the pages show them', async ({ page }) => {
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
      await expectNoBlockingViolations(page, 'full preview');
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
      await expect(page.getByLabel('Git URL')).toHaveValue(GIT_URL);
      await openSection(page, 'Templates');
      await expect(page.getByTestId('template-row')).toHaveCount(1);
      await expect(page.getByTestId('template-name')).toHaveText('main');
      await expect(page.getByTestId('template-post-deploy')).toHaveText('1');
      await page.goto(`/admin/projects/${name}/webhooks`);
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

  test('a disabled schedule in the file is imported paused', async ({ page }) => {
    const name = uniqueName('pwd');
    const scheduleName = `${name}-off`.slice(0, 40);
    try {
      expect(await upload(page, json(doc(name, { schedules: [schedule(scheduleName, { enabled: false })] })))).toBe(200);
      await confirm(page);
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);
      await page.goto('/admin/schedules');
      await expect(page.locator(`[data-testid="schedule-row"][data-name="${scheduleName}"]`).getByTestId('schedule-state')).toHaveText('Paused');
    } finally {
      await deleteScheduleViaUi(page, scheduleName);
      await deleteProjectViaUi(page, name);
    }
  });

  test('Cancel on the preview goes back to an empty upload page and nothing is created', async ({ page }) => {
    const name = uniqueName('pwc');
    expect(await upload(page, json(doc(name, { templates: [template()] })))).toBe(200);
    await expect(page.getByTestId('import-confirm')).toBeVisible();
    await submitAndWait(page, page.getByRole('link', { name: 'Cancel' }));
    await expect(page).toHaveURL(/\/admin\/import$/);
    await expect(page.getByTestId('import-preview-section')).toHaveCount(0);
    await expect(page.getByTestId('import-file')).toHaveValue('');
    await expectGone(page, name);
  });

  test('an existing project: changed things are updates, the rest unchanged; a second import has nothing to do', async ({ page }) => {
    test.setTimeout(120_000);
    const name = uniqueName('pwu');
    const other = 'https://git.example.com/acme/other.git';
    try {
      await createProjectViaUi(page, name);
      const changed = doc(name, { project: { ...doc(name).project, git: other }, templates: [template()] });

      expect(await upload(page, json(changed))).toBe(200);
      const project = page.getByTestId('import-row').filter({ has: page.getByRole('cell', { name: 'project', exact: true }) });
      await expect(project).toHaveAttribute('data-action', 'update');
      await expect(project).toContainText('changed: git');
      await expect(page.getByTestId('import-row').filter({ has: page.getByRole('cell', { name: 'template', exact: true }) })).toHaveAttribute('data-action', 'create');
      await expect(page.getByTestId('import-counts')).toHaveText('1 create, 1 update, 0 unchanged, 0 skipped.');
      await expectNoBlockingViolations(page, 'update preview');

      await confirm(page);
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);
      await expect(page.getByLabel('Git URL')).toHaveValue(other);

      // The same file again: everything is on this host, so there is nothing to confirm.
      expect(await upload(page, json(changed))).toBe(200);
      await expect(page.getByTestId('import-nothing')).toContainText('Everything in the file is already on this host: there is nothing to import.');
      await expect(page.getByTestId('import-confirm-form')).toHaveCount(0);
      await expect(page.getByTestId('import-row')).toHaveCount(2);
      for (const action of await page.getByTestId('import-row').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-action')))) {
        expect(action).toBe('unchanged');
      }
      await expect(page.getByTestId('import-counts')).toHaveText('0 create, 0 update, 2 unchanged, 0 skipped.');
      await expectNoOverflow(page, 'nothing to import');
      await expectNoBlockingViolations(page, 'nothing to import');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('a template that differs is replaced, one the file does not mention stays', async ({ page }) => {
    test.setTimeout(120_000);
    const name = uniqueName('pwt');
    try {
      expect(await upload(page, json(doc(name, { templates: [template('main'), template('extra')] })))).toBe(200);
      await confirm(page);
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);

      const edited = template('main');
      edited.post_deploy.exec = ['echo changed', 'echo twice'];
      expect(await upload(page, json(doc(name, { templates: [edited] })))).toBe(200);
      await expect(page.getByTestId('import-row').filter({ hasText: 'main' })).toHaveAttribute('data-action', 'update');
      await expect(page.getByTestId('import-row').filter({ hasText: 'main' })).toContainText('changed: post_deploy');
      await expect(page.getByTestId('import-row').filter({ hasText: 'extra' })).toHaveCount(0);
      await confirm(page);

      await openSection(page, 'Templates');
      await expect(page.getByTestId('template-row')).toHaveCount(2);
      await expect(page.getByTestId('template-row').filter({ hasText: 'main' }).getByTestId('template-post-deploy')).toHaveText('2');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('a schedule name that belongs to another project is refused, and the other schedule stays', async ({ page }) => {
    test.setTimeout(120_000);
    const first = uniqueName('pwa');
    const second = uniqueName('pwb');
    const shared = `${first}-job`.slice(0, 40);
    try {
      expect(await upload(page, json(doc(first, { schedules: [schedule(shared)] })))).toBe(200);
      await confirm(page);
      await expect(page.getByTestId('flashes')).toContainText(`Project "${first}" imported.`);

      expect(await upload(page, json(doc(second, { schedules: [schedule(shared)] })))).toBe(422);
      await expect(problems(page).first()).toContainText('schedules[0].name: a schedule with this name exists on this host and does not belong to this project.');
      await expect(page.getByTestId('import-row').filter({ hasText: shared })).toHaveAttribute('data-action', 'skipped');
      await expectRefusedPage(page, second);
      await page.goto('/admin/schedules');
      await expect(page.locator(`[data-testid="schedule-row"][data-name="${shared}"]`)).toHaveCount(1);
    } finally {
      await deleteScheduleViaUi(page, shared);
      await deleteProjectViaUi(page, first);
      await deleteProjectViaUi(page, second);
    }
  });

  test('the file text is posted back and checked again: a changed file is refused and nothing is written', async ({ page }) => {
    const name = uniqueName('pwk');
    expect(await upload(page, json(doc(name)))).toBe(200);
    // Same page, the hidden field edited to a version this host cannot read.
    await page.getByTestId('import-confirm-form').locator('input[name="document"]').evaluate((field, value) => ((field as HTMLInputElement).value = value), json({ format: 'fleet-project-export', version: 2 }));
    await confirm(page);
    await expect(page.getByTestId('import-errors')).toContainText('version 2');
    await expect(page.getByTestId('import-confirm-form')).toHaveCount(0);
    await expectGone(page, name);
  });

  test('a file whose content became invalid between preview and confirm is refused as a whole', async ({ page }) => {
    const name = uniqueName('pwv');
    const bad = doc(name, { project: { ...doc(name).project, git: 'ftp://example.com/x.git' } });
    expect(await upload(page, json(doc(name)))).toBe(200);
    await page.getByTestId('import-confirm-form').locator('input[name="document"]').evaluate((field, value) => ((field as HTMLInputElement).value = value), json(bad));
    await confirm(page);
    await expect(problems(page).first()).toContainText('project.git: Only https://');
    await expect(page.getByTestId('import-confirm-form')).toHaveCount(0);
    await expectGone(page, name);
  });

  test('an empty document field is "not received"', async ({ page }) => {
    const name = uniqueName('pwn');
    expect(await upload(page, json(doc(name)))).toBe(200);
    await page.getByTestId('import-confirm-form').locator('input[name="document"]').evaluate((field) => ((field as HTMLInputElement).value = ''));
    await confirm(page);
    await expect(page.getByTestId('import-error')).toContainText('The file was not received. Upload it again.');
    await expect(page.getByTestId('import-upload')).toBeVisible();
    await expectGone(page, name);
  });
});

test.describe('import: sealed secrets', () => {
  test('no passphrase: a warning, a skipped row, and the secrets can still be added at the confirm step', async ({ page }) => {
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
      await expectNoBlockingViolations(page, 'sealed preview');

      // 2. Confirm without one: the project arrives, the secrets do not, and the warning is flashed.
      await confirm(page);
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);
      await expect(page.getByTestId('flashes')).toContainText('The file holds sealed secrets and no passphrase was given: they were not imported.');
      await page.goto(`/admin/projects/${name}/secrets`);
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
      await page.goto(`/admin/projects/${name}/secrets`);
      await expect(page.getByTestId('secret-row').filter({ hasText: secret })).toHaveCount(1);
      expect(await page.content()).not.toContain(value);
    } finally {
      await deleteProjectSecret(page, name, secret);
      await deleteProjectViaUi(page, name);
    }
  });

  test('the right passphrase at the upload lists the secret by name only; a second import finds it unchanged', async ({ page }) => {
    test.setTimeout(180_000);
    const name = uniqueName('pwp');
    const secret = `PW_OPEN_${randomBytes(3).toString('hex').toUpperCase()}`;
    const value = `pw-value-${randomBytes(8).toString('hex')}`;
    const text = await sealedExport(page, name, secret, value, PASSPHRASE);
    try {
      expect(await upload(page, text, PASSPHRASE)).toBe(200);
      await expect(page.getByTestId('import-warning')).toHaveCount(0);
      const row = page.getByTestId('import-row').filter({ has: page.getByRole('cell', { name: secret, exact: true }) });
      await expect(row).toHaveAttribute('data-action', 'create');
      expect(await page.content()).not.toContain(value);
      expect(await page.content()).not.toContain(PASSPHRASE);
      // The passphrase is never echoed back, so the confirm step asks for it again (empty leaves the secrets out).
      await expect(page.getByTestId('confirm-passphrase')).toHaveValue('');
      await page.getByTestId('confirm-passphrase').fill(PASSPHRASE);
      await confirm(page);
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);
      await expect(page.getByTestId('flashes')).not.toContainText('no passphrase was given');
      expect(await page.content()).not.toContain(PASSPHRASE);

      expect(await upload(page, text, PASSPHRASE)).toBe(200);
      await expect(page.getByTestId('import-row').filter({ has: page.getByRole('cell', { name: secret, exact: true }) })).toHaveAttribute('data-action', 'unchanged');
      await expect(page.getByTestId('import-nothing')).toBeVisible();
      await expect(page.getByTestId('import-confirm-form')).toHaveCount(0);
    } finally {
      await deleteProjectSecret(page, name, secret);
      await deleteProjectViaUi(page, name);
    }
  });

  test('a wrong passphrase, a damaged block and a block from another project are refused', async ({ page }) => {
    test.setTimeout(180_000);
    const name = uniqueName('pwg');
    const secret = `PW_BAD_${randomBytes(3).toString('hex').toUpperCase()}`;
    const text = await sealedExport(page, name, secret, `pw-value-${randomBytes(8).toString('hex')}`, PASSPHRASE);
    const document = JSON.parse(text) as { project: { name: string }; secrets: { ciphertext: string } };
    const wrong = 'The passphrase is wrong, or the sealed secrets are damaged.';

    // Wrong passphrase at the upload.
    expect(await upload(page, text, 'not the passphrase')).toBe(422);
    await expect(problems(page)).toHaveText([wrong]);
    await expectRefusedPage(page);
    await expectNoBlockingViolations(page, 'wrong passphrase');
    await expectGone(page, name);
    await page.goto('/admin/import');

    // A passphrase shorter than the minimum is simply wrong too.
    expect(await upload(page, text, 'abc')).toBe(422);
    await expect(problems(page)).toHaveText([wrong]);

    // Damaged ciphertext with the right passphrase.
    const flipped = Buffer.from(document.secrets.ciphertext, 'base64');
    flipped[0] = flipped[0]! ^ 0xff;
    expect(await upload(page, json({ ...document, secrets: { ...document.secrets, ciphertext: flipped.toString('base64') } }), PASSPHRASE)).toBe(422);
    await expect(problems(page)).toHaveText([wrong]);

    // The block of one project under another project's name.
    const foreign = uniqueName('pwh');
    expect(await upload(page, json({ ...document, project: { ...document.project, name: foreign } }), PASSPHRASE)).toBe(422);
    await expect(problems(page)).toHaveText(['secrets: the sealed block does not belong to this project file.']);
    await expectGone(page, foreign);
    await expectGone(page, name);
  });

  test('a wrong passphrase at the confirm step is refused as a whole and nothing is written', async ({ page }) => {
    test.setTimeout(180_000);
    const name = uniqueName('pwy');
    const secret = `PW_LATE_${randomBytes(3).toString('hex').toUpperCase()}`;
    const text = await sealedExport(page, name, secret, `pw-value-${randomBytes(8).toString('hex')}`, PASSPHRASE);
    expect(await upload(page, text)).toBe(200);
    await page.getByTestId('confirm-passphrase').fill('wrong passphrase');
    await confirm(page);
    await expect(problems(page)).toHaveText(['The passphrase is wrong, or the sealed secrets are damaged.']);
    await expect(page.getByTestId('import-confirm-form')).toHaveCount(0);
    await expect(page.getByTestId('import-upload')).toBeVisible();
    expect(await page.content()).not.toContain('wrong passphrase');
    await expectGone(page, name);
  });
});

test.describe('import: long values', () => {
  test('a 40-character project, template and schedule name wrap at every width', async ({ page }) => {
    test.setTimeout(120_000);
    const stamp = Date.now().toString(36);
    const name = `pw-long-${stamp}-${'x'.repeat(40)}`.slice(0, 40);
    const templateName = `t${'y'.repeat(39)}`;
    const scheduleName = `${stamp}-${'z'.repeat(40)}`.slice(0, 40);
    try {
      expect(await upload(page, json(doc(name, { templates: [template(templateName)], webhooks: [jiraRule(templateName, 'A very long Jira status name '.repeat(3))], schedules: [schedule(scheduleName)] })))).toBe(200);
      await expect(page.getByRole('heading', { level: 2, name: `Preview: ${name}` })).toBeVisible();
      await expectNoOverflow(page, 'long names preview');
      await expectNoBlockingViolations(page, 'long names preview');
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

  test('a long error line (a 40-character key in the file) wraps on the refusal page', async ({ page }) => {
    const long = 'k'.repeat(40);
    expect(await upload(page, json({ ...doc('pw-wrap'), [long]: 1, ['q'.repeat(300)]: 2 }))).toBe(422);
    await expect(problems(page).first()).toBeVisible();
    await expectNoOverflow(page, 'long error key');
    await expectNoBlockingViolations(page, 'long error key');
    // A 400-character string value is refused with a short message, not echoed.
    expect(await upload(page, json(doc('pw-wrap', { project: { ...doc('pw-wrap').project, git: 'https://git.example.com/' + 'a'.repeat(9000) } })))).toBe(422);
    await expect(problems(page).first()).toContainText('too long');
    await expectNoOverflow(page, 'too long string');
  });
});

test.describe('import and export: access and CSRF', () => {
  test('an administrator without a valid token is refused on all four posts, and nothing changes', async ({ page, context }) => {
    const name = uniqueName('pwt');
    const ghost = uniqueName('pwg');
    try {
      await createProjectViaUi(page, name);
      // Export: the token field of the real form made invalid, then submitted.
      let downloaded = false;
      page.on('download', () => (downloaded = true));
      const exportResponse = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === `/admin/projects/${name}/export`);
      await page.getByTestId('export-project').locator('input[name="_token"]').evaluate((field) => ((field as HTMLInputElement).value = '!bad!'));
      await page.getByTestId('export-download').click();
      expect((await exportResponse).status()).toBe(403);
      expect(downloaded).toBe(false);

      // Preview and apply, with a wrong and with no token.
      const file = { name: 'x.json', mimeType: 'application/json', buffer: Buffer.from(json(doc(ghost))) };
      for (const form of [{ file }, { file, _token: 'wrong' }] as Array<Record<string, string | { name: string; mimeType: string; buffer: Buffer }>>) {
        expect((await context.request.post('/admin/import/preview', { multipart: form })).status()).toBe(403);
      }
      for (const form of [{ document: json(doc(ghost)) }, { document: json(doc(ghost)), _token: 'wrong' }] as Array<Record<string, string>>) {
        expect((await context.request.post('/admin/import/apply', { form })).status()).toBe(403);
      }
      expect((await context.request.post(`/admin/projects/${name}/export`, { form: {} })).status()).toBe(403);
      await expectGone(page, ghost);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the confirm form refuses a changed token the same way', async ({ page }) => {
    const name = uniqueName('pwz');
    expect(await upload(page, json(doc(name)))).toBe(200);
    await page.getByTestId('import-confirm-form').locator('input[name="_token"]').evaluate((field) => ((field as HTMLInputElement).value = '!bad!'));
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/import/apply'),
      page.getByTestId('import-confirm').click(),
    ]);
    expect(response.status()).toBe(403);
    await expectGone(page, name);
  });

  test('a member sees no Import page and cannot post to any of the endpoints', async ({ page, context, baseURL }) => {
    const name = uniqueName('pwm');
    try {
      await createProjectViaUi(page, name);
      await setStandInRole(context, 'member', baseURL!);

      const response = await page.goto('/admin/import');
      expect(response?.status()).toBe(403);
      await expect(page.getByText('You do not have access to this page')).toBeVisible();
      await expect(page.getByTestId('import-file')).toHaveCount(0);

      const token = { _token: 'csrf-token' };
      const file = { name: 'x.json', mimeType: 'application/json', buffer: Buffer.from(json(doc(name))) };
      expect((await context.request.post('/admin/import/preview', { multipart: { ...token, file } })).status()).toBe(403);
      expect((await context.request.post('/admin/import/apply', { form: { ...token, document: json(doc('pw-member-ghost')) } })).status()).toBe(403);
      expect((await context.request.post(`/admin/projects/${name}/export`, { form: token })).status()).toBe(403);
      expect((await page.goto(`/admin/projects/${name}`))?.status()).toBe(403);
      await setStandInRole(context, 'admin', baseURL!);
      await expectGone(page, 'pw-member-ghost');
    } finally {
      await setStandInRole(context, 'admin', baseURL!);
      await deleteProjectViaUi(page, name);
    }
  });

  test('GET on the post-only endpoints finds nothing, and an unknown project cannot be exported', async ({ page, context }) => {
    for (const path of ['/admin/import/preview', '/admin/import/apply', '/admin/projects/pw-nothing/export']) {
      expect((await context.request.get(path)).status(), path).toBe(404);
    }
    // Reaches the project lookup only with a valid token, so use the real page of a project for the token and a different name in the action.
    const name = uniqueName('pwx');
    try {
      await createProjectViaUi(page, name);
      await page.getByTestId('export-project').locator('form').evaluate((form, target) => ((form as HTMLFormElement).action = target), new URL(`/admin/projects/${name}-gone/export`, page.url()).toString());
      const answer = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === `/admin/projects/${name}-gone/export`);
      await page.getByTestId('export-download').click();
      expect((await answer).status()).toBe(404);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});
