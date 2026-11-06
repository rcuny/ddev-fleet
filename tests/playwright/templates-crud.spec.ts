import { expect, test, type Page } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, openSection, submitAndWait, uniqueName } from './support/projects';
import { expectNoBlockingViolations } from './support/axe';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { createTemplateViaUi, editTemplate, fillTemplate, templateRow } from './support/templates';

// Templates of a project in a real browser (desktop 1280 px and phone 390 px): empty state, create with every field, delete with its
// dialog and long values. The plain HTTP behaviour (edit, clear, cancel links, default-template refusal, 404s) is in
// app/tests/Functional/Admin/TemplatePagesTest.php (FLE-161). Every test owns a unique project, removed in `finally`.

const isPhone = (): boolean => test.info().project.name === 'phone';

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

test.describe('templates', () => {
  test('empty state, then the new template form starts blank @phone', async ({ page }) => {
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

      if (isPhone()) {
        const box = await page.getByRole('link', { name: 'New template' }).first().boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(43.5);
      }
      await follow(page, page.getByRole('link', { name: 'New template' }));
      await expect(page.getByTestId('template-heading')).toHaveText('New template');
      await expect(page.getByLabel('Name', { exact: true })).toHaveValue('');
      await expect(page.getByLabel('Drupal environment')).toHaveValue('');
      await expect(page.getByLabel('Post-deploy commands')).toHaveValue('');
      await expect(page.getByLabel('Terminal 1 commands')).toHaveValue('');
      await expect(page.getByLabel('Terminal 2 commands')).toHaveValue('');
      await expect(page.getByRole('button', { name: 'Create template' })).toBeVisible();
      await expectNoOverflow(page, 'new template form');
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the default template is chosen on the Templates tab and the template form has its sections @phone', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'alpha' });
      await openSection(page, 'Templates');
      await expect(page.getByTestId('default-template')).toBeVisible();
      await page.getByLabel('Default template').selectOption('alpha');
      await submitAndWait(page, page.getByRole('button', { name: 'Save default' }));
      await expect(page.getByTestId('flashes')).toContainText('Default template saved.');
      await expect(page.getByLabel('Default template')).toHaveValue('alpha');
      await expect(page.getByTestId('template-default')).toBeVisible();
      await expectNoOverflow(page, 'templates tab with the default template form');
      await expectNoBlockingViolations(page, 'templates tab with the default template form');

      // The Source tab no longer carries it, and saving Source keeps it.
      await openSection(page, 'Source');
      await expect(page.getByLabel('Default template')).toHaveCount(0);
      await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      await openSection(page, 'Templates');
      await expect(page.getByLabel('Default template')).toHaveValue('alpha');

      await follow(page, page.getByRole('link', { name: 'New template' }));
      for (const heading of ['Environment', 'Commands', 'Terminals']) {
        await expect(page.getByRole('heading', { name: heading, level: 3 })).toBeVisible();
      }
      await expectNoOverflow(page, 'new template form with sections');
      await expectNoBlockingViolations(page, 'new template form with sections');
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('a template with every field is created, listed and re-read in its edit form @phone', async ({ page }) => {
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
      expect(errors).toEqual([]);
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

  test('long values wrap: a 40 character name and 2000 character command lines never widen the page @phone', async ({ page }) => {
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

      await editTemplate(page, project, longName);
      await expect(page.getByTestId('template-heading')).toContainText(longName);
      await expect(page.getByLabel('Post-deploy commands')).toHaveValue(longLine);
      await expectNoOverflow(page, 'edit template form with long values');
      if (isPhone()) {
        const box = await page.getByRole('button', { name: 'Save', exact: true }).boundingBox();
        expect(box!.height).toBeGreaterThanOrEqual(43.5);
      }
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});
