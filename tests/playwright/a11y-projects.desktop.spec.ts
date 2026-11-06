import { expect, test, type Locator, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { expectNoBlockingViolations } from './support/axe';
import { createDeployableProject, deployOne, uniqueLabel } from './support/instances';
import { createPort, deletePort } from './support/ports';
import { fillNetwork, fillProject, openNewProject, submitCreate, submitNetwork, submitSave } from './support/projectForm';
import { createProjectViaUi, deleteProjectViaUi, GIT_URL, openDialog, openSection, submitAndWait, uniqueName } from './support/projects';
import { openReauthWindow } from './support/secrets';
import { createTemplateViaUi, editTemplate, fillTemplate, submitNewTemplate } from './support/templates';

// Axe sweep of the project pages: every page state the feature specs used to check inline (list, forms, 422 refusals,
// dialogs, flashes, ports, the issue id tester, templates, export and import) is set up once here and checked in the
// light and the dark theme. The feature specs keep their behaviour assertions only. One test per distinct state; axe does not
// read text length, so the "long values" variants of the feature specs are folded into these base states.
// Desktop only: the rules do not depend on the width.

const PASSPHRASE = 'correct horse battery';
const CATALOGUE_PORT = { name: 'typesense', public: 9108, router: 8108 };

// ---- project pages ---------------------------------------------------------------------------------------------------

test.describe('axe sweep: projects', () => {
  test('the project list', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await page.goto('/admin/projects');
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toHaveCount(1);
      await expectNoBlockingViolations(page, 'the project list');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the create form', async ({ page }) => {
    await openNewProject(page);
    await expectNoBlockingViolations(page, 'the create form');
  });

  test('the edit page right after create', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await expect(page.getByTestId('danger-zone')).toBeVisible();
      await expectNoBlockingViolations(page, 'the edit page right after create');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the edit page, with the export card, the tester and the danger zone', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await page.goto(`/admin/projects/${name}/source`);
      await expect(page.getByTestId('issue-id-preview')).toBeVisible();
      await expect(page.getByTestId('export-project')).toBeVisible();
      await expect(page.getByTestId('danger-zone')).toBeVisible();
      await expectNoBlockingViolations(page, 'the edit page');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the delete dialog', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
      await expect(dialog).toContainText(`Delete ${name}?`);
      await expectNoBlockingViolations(page, 'the delete dialog');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the list after a delete', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await deleteProjectViaUi(page, name);
      await expect(page).toHaveURL(/\/admin\/projects$/);
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toHaveCount(0);
      await expectNoBlockingViolations(page, 'the list after a delete');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the refused delete of a project with an instance', async ({ page }) => {
    test.setTimeout(180_000);
    const name = uniqueName();
    let instance = '';
    try {
      await createDeployableProject(name);
      instance = await deployOne(page, name, uniqueLabel());

      await page.goto(`/admin/projects/${name}/source`);
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete project' }));
      await expect(page.getByTestId('flashes')).toContainText('The project was not deleted.');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/source$`));
      await expectNoBlockingViolations(page, 'the refused delete');

      // Destroy the instance so the project can be removed.
      await page.goto(`/instances/${instance}`);
      const destroy = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
      await destroy.getByRole('button', { name: 'Destroy instance' }).click();
      await expect(page).toHaveURL(/\/jobs\/\d+$/);
      await expect(page.getByTestId('job-log')).toContainText('destroy');
      instance = '';
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the refused create form (422)', async ({ page }) => {
    await openNewProject(page);
    await submitCreate(page);
    await expect(page).toHaveURL(/\/admin\/projects\/new$/);
    await expect(page.getByTestId('project-form')).toContainText('Enter a name.');
    await expectNoBlockingViolations(page, 'the refused create form');
  });

  test('the refused edit form (422)', async ({ page }) => {
    const name = uniqueName();
    try {
      await openNewProject(page);
      await fillProject(page, { name, git: GIT_URL, branch: 'main', hostnames: 'www' });
      await submitCreate(page);
      await expect(page.getByTestId('flashes')).toContainText('Project created.');
      await openSection(page, 'Source');

      await fillProject(page, { git: 'http://x', branch: 'dev' });
      await submitSave(page);
      await expect(page.getByTestId('project-form')).toContainText('Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.');
      await expectNoBlockingViolations(page, 'the refused edit form');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});

// ---- the issue id tester -----------------------------------------------------------------------------------------------

interface Tester {
  box: Locator;
  pattern: Locator;
  label: Locator;
  result: Locator;
}

function tester(page: Page): Tester {
  const box = page.getByTestId('issue-id-preview');
  return { box, pattern: box.getByLabel('Pattern'), label: box.getByLabel('Sample instance label'), result: box.getByTestId('issue-id-result') };
}

/** The live controller loads lazily: typing is repeated until the first re-render request proves it is running. */
async function warm(page: Page, t: Tester, first: string): Promise<void> {
  await expect(async () => {
    const rerender = page.waitForResponse((response) => response.url().includes('/_components/') && response.status() === 200, { timeout: 4_000 });
    rerender.catch(() => undefined);
    await t.pattern.fill('');
    await t.pattern.fill(first);
    await rerender;
  }).toPass({ timeout: 60_000 });
}

test.describe('axe sweep: issue id tester', () => {
  test('the tester with a match', async ({ page }) => {
    test.setTimeout(150_000);
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      const t = tester(page);
      await warm(page, t, 'OAKS-[0-9]+');
      await t.label.fill('oaks-1781');
      await expect(t.result).toHaveAttribute('data-state', 'match');
      await expect(t.box.getByTestId('issue-id-extracted')).toHaveText('OAKS-1781');
      await expectNoBlockingViolations(page, 'the tester with a match');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the tester with an error', async ({ page }) => {
    test.setTimeout(150_000);
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      const t = tester(page);
      await warm(page, t, 'x');
      await t.pattern.fill('(a+)+$');
      await expect(t.result).toHaveAttribute('data-state', 'error');
      await expect(t.box.getByTestId('issue-id-message')).toContainText('nested quantifier');
      await expectNoBlockingViolations(page, 'the tester with an error');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});

// ---- templates -------------------------------------------------------------------------------------------------------

test.describe('axe sweep: templates', () => {
  test('the empty template list', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Templates');
      await expect(page.getByTestId('templates-empty')).toBeVisible();
      await expectNoBlockingViolations(page, 'empty template list');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the new template form', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await page.goto(`/admin/projects/${project}/templates/new`);
      await expect(page.getByTestId('template-heading')).toHaveText('New template');
      await expectNoBlockingViolations(page, 'new template form');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the template list', async ({ page }) => {
    const project = uniqueName();
    const longLine = `echo ${'0123456789'.repeat(199)}`;
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'web', env: 'dev', postDeploy: longLine, tty1: 'npm run watch', tty2: 'drush uli' });
      await expect(page.getByTestId('template-row')).toHaveCount(1);
      await expectNoBlockingViolations(page, 'template list');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the edit template form', async ({ page }) => {
    const project = uniqueName();
    const longLine = `echo ${'0123456789'.repeat(199)}`;
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'web', env: 'dev', postDeploy: longLine, tty1: 'npm run watch', tty2: 'drush uli' });
      await editTemplate(page, project, 'web');
      await expect(page.getByTestId('danger-zone')).toBeVisible();
      await expectNoBlockingViolations(page, 'edit template form');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the delete template dialog', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'web', env: 'dev' });
      await editTemplate(page, project, 'web');
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this template' }));
      await expect(dialog).toContainText('Delete web?');
      await expectNoBlockingViolations(page, 'delete template dialog');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the default template refusal', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'alpha' });
      await createTemplateViaUi(page, project, { name: 'beta' });
      await openSection(page, 'Templates');
      await page.getByLabel('Default template').selectOption('alpha');
      await submitAndWait(page, page.getByRole('button', { name: 'Save default' }));
      await expect(page.getByTestId('flashes')).toContainText('Default template saved.');
      await editTemplate(page, project, 'alpha');
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this template' }));
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete template' }));
      await expect(page.getByTestId('flashes')).toContainText('The template was not deleted.');
      await expectNoBlockingViolations(page, 'default template refusal');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the refused template form (422)', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await submitNewTemplate(page, project, { name: 'Bad Name', env: 'no good', postDeploy: 'a\tb', tty1: 'c\td', tty2: 'e\tf' });
      await expect(page.getByLabel('Name', { exact: true }).locator('xpath=ancestor::div[1]')).toContainText('Use lowercase letters');
      await expectNoBlockingViolations(page, 'refused template form');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the refused edit template form (422)', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'web', env: 'dev', postDeploy: 'keep me' });
      await editTemplate(page, project, 'web');
      await fillTemplate(page, { env: 'a b' });
      await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
      await expect(page.getByLabel('Drupal environment').locator('xpath=ancestor::div[1]')).toContainText('Use 1 to 64 letters');
      await expectNoBlockingViolations(page, 'refused edit form');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});

// ---- access tab ------------------------------------------------------------------------------------------------------

test.describe('axe sweep: access tab', () => {
  test('the access tab, empty', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await page.goto(`/admin/projects/${name}/access`);
      await expect(page.getByTestId('project-section-access')).toBeVisible();
      await expectNoBlockingViolations(page, 'the access tab');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});

// ---- ports tab -------------------------------------------------------------------------------------------------------

test.describe('axe sweep: ports tab', () => {
  const box = (page: Page, port: string) => page.getByRole('checkbox', { name: `Use port ${port}` });

  test('the Ports tab with a catalogue entry (a 40-character name)', async ({ page }) => {
    const project = uniqueName();
    const port = createPort(`lp-${'a1b2c3d4-'.repeat(5)}`.slice(0, 40).replace(/-$/, 'z'));
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Network');
      await expect(box(page, port.name)).not.toBeChecked();
      await expectNoBlockingViolations(page, 'Ports tab');
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
  });

  test('the Ports tab, saved', async ({ page }) => {
    const project = uniqueName();
    const port = createPort(uniqueName('pa'));
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Network');
      await box(page, port.name).check();
      await submitAndWait(page, page.getByRole('button', { name: 'Save network settings' }));
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');
      await expect(box(page, port.name)).toBeChecked();
      await expectNoBlockingViolations(page, 'Ports tab, saved');
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
  });

  test('the Network tab: confirmation dialog, editable access and refused hostnames', async ({ page }) => {
    const project = uniqueName();
    const port = createPort(uniqueName('pn'));
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Network');
      await box(page, port.name).check();
      await submitNetwork(page);
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');
      await expectNoBlockingViolations(page, 'Network tab with an access select');

      await page.getByRole('combobox', { name: `Access of port ${port.name}` }).selectOption('public');
      await submitNetwork(page);
      await expect(page.getByRole('dialog')).toBeVisible();
      await expectNoBlockingViolations(page, 'Network tab, confirmation dialog');
      await page.keyboard.press('Escape');
      await expect(page.getByRole('dialog')).toBeHidden();

      await page.getByRole('combobox', { name: `Access of port ${port.name}` }).selectOption('gated');
      await fillNetwork(page, { hostnames: 'bad.label' });
      await submitNetwork(page);
      await expect(page.getByTestId('ports-form')).toContainText('Hostname 1:');
      await expectNoBlockingViolations(page, 'Network tab, refused hostnames');
    } finally {
      await deleteProjectViaUi(page, project);
      deletePort(port.name);
    }
  });

  test('the Ports tab, error (422)', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Network');
      // A stale form: a port the catalogue does not know.
      await page.getByTestId('ports-form').evaluate((form) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ports[]';
        input.value = 'ghost-port';
        form.append(input);
      });
      await submitAndWait(page, page.getByRole('button', { name: 'Save network settings' }));
      await expect(page.getByTestId('ports-errors')).toContainText('Unknown port(s): ghost-port.');
      await expectNoBlockingViolations(page, 'Ports tab, error');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});

// ---- import and export -----------------------------------------------------------------------------------------------

type Doc = Record<string, unknown> & { project: Record<string, unknown> };

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
const json = (value: unknown): string => JSON.stringify(value, null, 2);

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

/** Makes a project with one secret, exports it sealed by `passphrase`, then removes both: the text is the "other host" file. */
async function sealedExport(page: Page, project: string, secret: string, value: string, passphrase: string): Promise<string> {
  try {
    await createProjectViaUi(page, project);
    await addProjectSecret(page, project, secret, value);
    await page.goto(`/admin/projects/${project}/source`);
    await openReauthWindow(page);
    await page.getByTestId('export-passphrase').fill(passphrase);
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-download').click()]);
    return await readFile(await download.path(), 'utf8');
  } finally {
    await deleteProjectSecret(page, project, secret);
    await deleteProjectViaUi(page, project);
  }
}

test.describe('axe sweep: import', () => {
  test('the empty import page', async ({ page }) => {
    await page.goto('/admin/import');
    await expect(page.getByRole('heading', { level: 1, name: 'Import a project' })).toBeVisible();
    await expect(page.getByTestId('import-preview-section')).toHaveCount(0);
    await expectNoBlockingViolations(page, 'empty import page');
  });

  test('the no-file refusal (422)', async ({ page }) => {
    expect(await upload(page, null)).toBe(422);
    await expect(page.getByTestId('import-error')).toContainText('Choose the export file to upload.');
    await expectNoBlockingViolations(page, 'no-file refusal');
  });

  test('the refused upload (not JSON, 422)', async ({ page }) => {
    expect(await upload(page, 'nope')).toBe(422);
    await expectNoBlockingViolations(page, 'refused upload');
  });

  test('the refused import (unreadable version, 422)', async ({ page }) => {
    expect(await upload(page, json({ format: 'fleet-project-export', version: 99 }))).toBe(422);
    await expect(page.getByTestId('import-error-item').first()).toContainText('version');
    await expect(page.getByTestId('import-confirm-form')).toHaveCount(0);
    await expectNoBlockingViolations(page, 'refused import');
  });

  test('the refused preview with a skipped item (422)', async ({ page }) => {
    const name = uniqueName('pwr');
    expect(await upload(page, json(doc(name, { ports: [{ ...CATALOGUE_PORT, router: 8109 }], schedules: [schedule(`${name}-bad`.slice(0, 40), { cron: 'x' })] })))).toBe(422);
    await expect(page.getByTestId('import-row').filter({ hasText: CATALOGUE_PORT.name })).toHaveAttribute('data-action', 'skipped');
    await expectNoBlockingViolations(page, 'refused preview');
  });

  test('the full preview of a new project', async ({ page }) => {
    const name = uniqueName('pwi');
    const scheduleName = `${name}-nightly`.slice(0, 40);
    const long = `t${'y'.repeat(39)}`;
    expect(await upload(page, json(doc(name, { ports: [CATALOGUE_PORT], templates: [template(long)], webhooks: [jiraRule(long)], schedules: [schedule(scheduleName)] })))).toBe(200);
    await expect(page.getByRole('heading', { level: 2, name: `Preview: ${name}` })).toBeVisible();
    await expect(page.getByTestId('import-confirm')).toBeVisible();
    await expectNoBlockingViolations(page, 'full preview');
  });

  test('the update preview of an existing project', async ({ page }) => {
    const name = uniqueName('pwu');
    try {
      await createProjectViaUi(page, name);
      const changed = doc(name, { project: { ...doc(name).project, git: 'https://git.example.com/acme/other.git' }, templates: [template()] });
      expect(await upload(page, json(changed))).toBe(200);
      await expect(page.getByTestId('import-counts')).toHaveText('1 create, 1 update, 0 unchanged, 0 skipped.');
      await expectNoBlockingViolations(page, 'update preview');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the preview with nothing to import', async ({ page }) => {
    const name = uniqueName('pwn');
    try {
      const file = json(doc(name, { templates: [template()] }));
      expect(await upload(page, file)).toBe(200);
      await submitAndWait(page, page.getByTestId('import-confirm'));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);
      expect(await upload(page, file)).toBe(200);
      await expect(page.getByTestId('import-nothing')).toContainText('there is nothing to import');
      await expectNoBlockingViolations(page, 'nothing to import');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the sealed preview without a passphrase', async ({ page }) => {
    test.setTimeout(180_000);
    const name = uniqueName('pwq');
    const secret = `PW_SEAL_${randomBytes(3).toString('hex').toUpperCase()}`;
    const text = await sealedExport(page, name, secret, `pw-value-${randomBytes(8).toString('hex')}`, PASSPHRASE);
    expect(await upload(page, text)).toBe(200);
    await expect(page.getByTestId('import-warning')).toContainText('no passphrase was given');
    await expectNoBlockingViolations(page, 'sealed preview');
  });

  test('the wrong passphrase refusal (422)', async ({ page }) => {
    test.setTimeout(180_000);
    const name = uniqueName('pwg');
    const secret = `PW_BAD_${randomBytes(3).toString('hex').toUpperCase()}`;
    const text = await sealedExport(page, name, secret, `pw-value-${randomBytes(8).toString('hex')}`, PASSPHRASE);
    expect(await upload(page, text, 'not the passphrase')).toBe(422);
    await expect(page.getByTestId('import-error-item')).toHaveText(['The passphrase is wrong, or the sealed secrets are damaged.']);
    await expectNoBlockingViolations(page, 'wrong passphrase');
  });
});
