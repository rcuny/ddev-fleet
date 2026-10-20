import { expect, test, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, openSection, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { createTemplateViaUi, editTemplate, fillTemplate, templateRow } from './support/templates';

// Templates of a project in a real browser (desktop 1280 px and phone 390 px): list, empty state, create, edit, delete with
// its dialog, the default-template refusal and long values. Every test owns a unique project, removed in `finally`.

const isPhone = (): boolean => test.info().project.name === 'phone';

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

test.describe('templates', () => {
  test('empty state, then the new template form starts blank', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Templates');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates$`));
      await expect(page.getByTestId('templates-empty')).toBeVisible();
      await expect(page.getByTestId('templates-empty')).toContainText('No templates yet');
      await expect(page.getByTestId('template-row')).toHaveCount(0);
      await expectNoOverflow(page, 'empty template list');
      await expectNoBlockingViolations(page, 'empty template list');

      await follow(page, page.getByRole('link', { name: 'New template' }));
      await expect(page.getByTestId('template-heading')).toHaveText('New template');
      await expect(page.getByLabel('Name', { exact: true })).toHaveValue('');
      await expect(page.getByLabel('Drupal environment')).toHaveValue('');
      await expect(page.getByLabel('Post-deploy commands')).toHaveValue('');
      await expect(page.getByLabel('Terminal 1 commands')).toHaveValue('');
      await expect(page.getByLabel('Terminal 2 commands')).toHaveValue('');
      await expect(page.getByRole('button', { name: 'Create template' })).toBeVisible();
      await expectNoOverflow(page, 'new template form');
      await expectNoBlockingViolations(page, 'new template form');
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('a template with every field is created, listed and re-read in its edit form', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await page.goto(`/admin/projects/${project}/templates/new`);

      // Blank lines are dropped, spaces around a line are trimmed, CRLF and LF both split.
      await fillTemplate(page, {
        name: 'web',
        env: 'dev',
        postDeploy: '  composer install  \n\n\ndrush deploy\r\ndrush cr',
        tty1: 'npm run watch',
        tty2: 'tail -f var/log/dev.log\ndrush uli',
      });
      await submitAndWait(page, page.getByRole('button', { name: 'Create template' }));
      await expect(page.getByTestId('flashes')).toContainText('Template created.');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates$`));
      await expect(page.getByTestId('templates-empty')).toHaveCount(0);

      const row = templateRow(page, 'web');
      await expect(row).toHaveCount(1);
      await expect(row.getByTestId('template-env')).toHaveText('dev');
      await expect(row.getByTestId('template-post-deploy')).toHaveText('3');
      await expect(row.getByTestId('template-tty1')).toHaveText('1');
      await expect(row.getByTestId('template-tty2')).toHaveText('2');
      await expect(row.getByTestId('template-default')).toHaveCount(0);
      await expectNoOverflow(page, 'template list');
      await expectNoBlockingViolations(page, 'template list');

      await editTemplate(page, project, 'web');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates/web$`));
      await expect(page.getByTestId('template-heading')).toContainText('web');
      await expect(page.getByLabel('Name', { exact: true })).toHaveCount(0);
      await expect(page.getByLabel('Drupal environment')).toHaveValue('dev');
      await expect(page.getByLabel('Post-deploy commands')).toHaveValue('composer install\ndrush deploy\ndrush cr');
      await expect(page.getByLabel('Terminal 1 commands')).toHaveValue('npm run watch');
      await expect(page.getByLabel('Terminal 2 commands')).toHaveValue('tail -f var/log/dev.log\ndrush uli');
      await expect(page.getByTestId('danger-zone')).toBeVisible();
      await expectNoOverflow(page, 'edit template form');
      await expectNoBlockingViolations(page, 'edit template form');
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('optional fields may stay empty and the list shows a dash for the Drupal environment', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'bare' });
      const row = templateRow(page, 'bare');
      await expect(row.getByTestId('template-env')).toHaveText('-');
      await expect(row.getByTestId('template-post-deploy')).toHaveText('0');
      await expect(row.getByTestId('template-tty1')).toHaveText('0');
      await expect(row.getByTestId('template-tty2')).toHaveText('0');

      // Whitespace-only fields are the same as empty ones.
      await createTemplateViaUi(page, project, { name: 'blank', env: '   ', postDeploy: ' \n\t\n  ', tty1: '\n\n', tty2: '  ' });
      const blank = templateRow(page, 'blank');
      await expect(blank.getByTestId('template-env')).toHaveText('-');
      await expect(blank.getByTestId('template-post-deploy')).toHaveText('0');
      await expect(blank.getByTestId('template-tty2')).toHaveText('0');
      // The list is ordered by name.
      await expect(page.getByTestId('template-name')).toHaveText(['bare', 'blank']);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('edit changes every field, saves, flashes and shows the new state', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'web', env: 'dev', postDeploy: 'composer install', tty1: 'one', tty2: 'two' });
      await editTemplate(page, project, 'web');
      await fillTemplate(page, { env: 'prod.1_x-y', postDeploy: 'a\nb\nc\nd', tty1: 'x\ny', tty2: 'z' });
      await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
      await expect(page.getByTestId('flashes')).toContainText('Template saved.');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates$`));
      let row = templateRow(page, 'web');
      await expect(row.getByTestId('template-env')).toHaveText('prod.1_x-y');
      await expect(row.getByTestId('template-post-deploy')).toHaveText('4');
      await expect(row.getByTestId('template-tty1')).toHaveText('2');
      await expect(row.getByTestId('template-tty2')).toHaveText('1');
      await editTemplate(page, project, 'web');
      await expect(page.getByLabel('Drupal environment')).toHaveValue('prod.1_x-y');
      await expect(page.getByLabel('Post-deploy commands')).toHaveValue('a\nb\nc\nd');
      await expect(page.getByLabel('Terminal 1 commands')).toHaveValue('x\ny');
      await expect(page.getByLabel('Terminal 2 commands')).toHaveValue('z');

      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('clearing every optional field empties them', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'web', env: 'dev', postDeploy: 'composer install', tty1: 'one', tty2: 'two' });
      await editTemplate(page, project, 'web');
      await fillTemplate(page, { env: '', postDeploy: '', tty1: '', tty2: '' });
      await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
      await expect(page.getByTestId('flashes')).toContainText('Template saved.');
      const row = templateRow(page, 'web');
      await expect(row.getByTestId('template-env')).toHaveText('-');
      await expect(row.getByTestId('template-post-deploy')).toHaveText('0');
      await expect(row.getByTestId('template-tty1')).toHaveText('0');
      await expect(row.getByTestId('template-tty2')).toHaveText('0');
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('Cancel on the new and the edit form leaves without saving', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'web', env: 'dev' });

      await follow(page, page.getByRole('link', { name: 'New template' }));
      await fillTemplate(page, { name: 'ghost', env: 'x' });
      await follow(page, page.getByRole('link', { name: 'Cancel' }));
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates$`));
      await expect(templateRow(page, 'ghost')).toHaveCount(0);

      await editTemplate(page, project, 'web');
      await fillTemplate(page, { env: 'changed' });
      await follow(page, page.getByRole('link', { name: 'Cancel' }));
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates$`));
      await expect(templateRow(page, 'web').getByTestId('template-env')).toHaveText('dev');
      await expect(page.getByTestId('flashes')).toHaveCount(0);

      // The back link above the heading goes to the list as well.
      await editTemplate(page, project, 'web');
      await follow(page, page.getByRole('link', { name: 'Templates', exact: true }).last());
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates$`));
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('delete: the dialog can be cancelled, then confirmed; the empty state returns', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'web', env: 'dev' });
      await editTemplate(page, project, 'web');

      let dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this template' }));
      await expect(dialog).toContainText('Delete web?');
      await expect(dialog).toContainText(`This removes the template from ${project}. It cannot be undone.`);
      await expectNoBlockingViolations(page, 'delete template dialog');
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(page.getByRole('dialog')).toBeHidden();
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates/web$`));
      await follow(page, page.getByRole('link', { name: 'Cancel' }));
      await expect(templateRow(page, 'web')).toHaveCount(1);

      await editTemplate(page, project, 'web');
      dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this template' }));
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete template' }));
      await expect(page.getByTestId('flashes')).toContainText('Template deleted.');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates$`));
      await expect(page.getByTestId('templates-empty')).toBeVisible();

      // The deleted template is gone for good: its edit page is a 404 and the name is free again.
      const gone = await page.goto(`/admin/projects/${project}/templates/web`);
      expect(gone?.status()).toBe(404);
      await createTemplateViaUi(page, project, { name: 'web' });
    } finally {
      await deleteProjectViaUi(page, project);
    }
    // The 404 above is the one expected console error.
    expect(errors.filter((error) => !error.includes('404'))).toEqual([]);
  });

  test('the default template cannot be deleted; after choosing another default it can', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'alpha' });
      await createTemplateViaUi(page, project, { name: 'beta' });

      await openSection(page, 'General');
      await page.getByLabel('Default template').selectOption('alpha');
      await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      await openSection(page, 'Templates');
      await expect(templateRow(page, 'alpha').getByTestId('template-default')).toHaveText('Default');
      await expect(templateRow(page, 'beta').getByTestId('template-default')).toHaveCount(0);

      await editTemplate(page, project, 'alpha');
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this template' }));
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete template' }));
      await expect(page.getByTestId('flashes')).toContainText('The template was not deleted. This is the default template of the project: choose another default template first.');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates$`));
      await expect(templateRow(page, 'alpha')).toHaveCount(1);
      await expectNoBlockingViolations(page, 'default template refusal');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('after choosing another default the former default template can be deleted', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'alpha' });
      await createTemplateViaUi(page, project, { name: 'beta' });
      await openSection(page, 'General');
      await page.getByLabel('Default template').selectOption('beta');
      await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      await openSection(page, 'Templates');
      await editTemplate(page, project, 'alpha');
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this template' }));
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete template' }));
      await expect(page.getByTestId('flashes')).toContainText('Template deleted.');
      await expect(templateRow(page, 'alpha')).toHaveCount(0);
      await expect(templateRow(page, 'beta').getByTestId('template-default')).toHaveText('Default');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('long values wrap: a 40 character name and 2000 character command lines never widen the page', async ({ page }) => {
    const project = uniqueName();
    const longName = `${'long-template-name-'.repeat(3)}x`.slice(0, 40).replace(/-$/, 'x');
    const longLine = `echo ${'0123456789'.repeat(199)}`;
    const longEnv = 'e'.repeat(64);
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: longName, env: longEnv, postDeploy: longLine, tty1: longLine, tty2: longLine });
      const row = templateRow(page, longName);
      await expect(row).toHaveCount(1);
      await expect(row.getByTestId('template-env')).toHaveText(longEnv);
      await expectNoOverflow(page, 'template list with long values');
      await expectNoBlockingViolations(page, 'template list with long values');

      await editTemplate(page, project, longName);
      await expect(page.getByTestId('template-heading')).toContainText(longName);
      await expect(page.getByLabel('Post-deploy commands')).toHaveValue(longLine);
      await expectNoOverflow(page, 'edit template form with long values');
      await expectNoBlockingViolations(page, 'edit template form with long values');
      if (isPhone()) {
        const box = await page.getByRole('button', { name: 'Save', exact: true }).boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(43.5);
      }
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('unknown project or template is a 404, and a project without templates still lists the New template link', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      for (const path of [`/admin/projects/${project}/templates/missing`, `/admin/projects/${uniqueName('nope')}/templates`, `/admin/projects/${uniqueName('nope')}/templates/new`]) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(404);
      }
      await page.goto(`/admin/projects/${project}/templates`);
      await expect(page.getByRole('link', { name: 'New template' })).toBeVisible();
      if (isPhone()) {
        const box = await page.getByRole('link', { name: 'New template' }).boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(43.5);
      }
      // A delete without a valid token is refused.
      const post = await page.request.post(`/admin/projects/${project}/templates/missing/delete`, { form: { _token: 'x' } });
      expect(post.status()).toBe(404);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});
