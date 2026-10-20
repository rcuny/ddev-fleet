import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, follow, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { createTemplateViaUi, editTemplate, fillTemplate, submitNewTemplate, templateRow, type TemplateFields } from './support/templates';

// Every message the template form can show, from the Rule classes and TemplateService, on the create form and the edit form
// (desktop and phone). A refused form answers 422 and keeps what was typed; the browser logs each 422 as a console error,
// which is the one expected console error of these tests.

const NAME_RULE = 'Use lowercase letters, digits and single dashes only, starting and ending with a letter or digit.';
const ENV_RULE = 'Use 1 to 64 letters, digits, dots, underscores or dashes.';
const TAB_RULE = 'contains a control character (a command is exactly one line, without tabs).';

/** The field's wrapper (label, widget, help, error) located by the field's label. */
function row(page: Page, label: string): Locator {
  return page.getByLabel(label, { exact: true }).locator('xpath=ancestor::div[1]');
}

const unexpected = (errors: string[]): string[] => errors.filter((error) => !error.includes('status of 422'));

const CREATE_CASES: Array<{ title: string; fields: TemplateFields; label: string; message: string }> = [
  { title: 'empty name', fields: { name: '' }, label: 'Name', message: 'Enter a name.' },
  { title: 'blank name', fields: { name: '   ' }, label: 'Name', message: 'Enter a name.' },
  { title: 'uppercase name', fields: { name: 'Web' }, label: 'Name', message: NAME_RULE },
  { title: 'name with a space', fields: { name: 'my web' }, label: 'Name', message: NAME_RULE },
  { title: 'name with an underscore', fields: { name: 'my_web' }, label: 'Name', message: NAME_RULE },
  { title: 'name starting with a dash', fields: { name: '-web' }, label: 'Name', message: NAME_RULE },
  { title: 'name ending with a dash', fields: { name: 'web-' }, label: 'Name', message: NAME_RULE },
  { title: 'name with a double dash', fields: { name: 'my--web' }, label: 'Name', message: 'The name must not contain two dashes in a row ("--" separates the project from the instance label).' },
  { title: 'name of 41 characters', fields: { name: 'a'.repeat(41) }, label: 'Name', message: 'The name must be at most 40 characters.' },
  { title: 'reserved name', fields: { name: 'new' }, label: 'Name', message: 'This name is reserved.' },
  { title: 'env with a space', fields: { name: 'ok', env: 'a b' }, label: 'Drupal environment', message: ENV_RULE },
  { title: 'env with a quote', fields: { name: 'ok', env: 'a"b' }, label: 'Drupal environment', message: ENV_RULE },
  { title: 'env with a shell metacharacter', fields: { name: 'ok', env: 'dev;rm' }, label: 'Drupal environment', message: ENV_RULE },
  { title: 'env of 65 characters', fields: { name: 'ok', env: 'e'.repeat(65) }, label: 'Drupal environment', message: ENV_RULE },
  { title: 'post-deploy line with an inner tab', fields: { name: 'ok', postDeploy: 'good\nbad\tline' }, label: 'Post-deploy commands', message: `Command line 2 ${TAB_RULE}` },
  { title: 'terminal 1 line with an escape character', fields: { name: 'ok', tty1: 'a\u001bb' }, label: 'Terminal 1 commands', message: `Command line 1 ${TAB_RULE}` },
  { title: 'terminal 2 line with a zero-width space', fields: { name: 'ok', tty2: 'one\ntwo\nth​ree' }, label: 'Terminal 2 commands', message: `Command line 3 ${TAB_RULE}` },
  { title: 'terminal 2 line with a line separator', fields: { name: 'ok', tty2: 'a b' }, label: 'Terminal 2 commands', message: `Command line 1 ${TAB_RULE}` },
  { title: '51 post-deploy lines', fields: { name: 'ok', postDeploy: Array.from({ length: 51 }, (_, i) => `cmd ${i}`).join('\n') }, label: 'Post-deploy commands', message: 'At most 50 commands are allowed.' },
  { title: '51 terminal 1 lines', fields: { name: 'ok', tty1: Array.from({ length: 51 }, (_, i) => `cmd ${i}`).join('\n') }, label: 'Terminal 1 commands', message: 'At most 50 commands are allowed.' },
  { title: 'a command line of 2001 characters', fields: { name: 'ok', postDeploy: `ok\n${'x'.repeat(2001)}` }, label: 'Post-deploy commands', message: 'Command line 2 is longer than 2000 characters.' },
];

test.describe('template validation, create form', () => {
  for (const { title, fields, label, message } of CREATE_CASES) {
    test(`refuses ${title}`, async ({ page }) => {
      const errors = collectBrowserErrors(page);
      const project = uniqueName();
      try {
        await createProjectViaUi(page, project);
        await submitNewTemplate(page, project, fields);
        await expect(row(page, label)).toContainText(message);
        await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates/new$`));
        await expect(page.getByTestId('flashes')).toHaveCount(0);
        // What was typed is still there (the name only when it was not blank).
        if (fields.name?.trim()) {
          await expect(page.getByLabel('Name', { exact: true })).toHaveValue(fields.name);
        }
        // Nothing was stored.
        await page.goto(`/admin/projects/${project}/templates`);
        await expect(page.getByTestId('template-row')).toHaveCount(0);
      } finally {
        await deleteProjectViaUi(page, project);
      }
      expect(unexpected(errors)).toEqual([]);
    });
  }

  test('all refusals show at once and the page stays accessible without overflow', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await submitNewTemplate(page, project, { name: 'Bad Name', env: 'no good', postDeploy: 'a\tb', tty1: 'c\td', tty2: 'e\tf' });
      await expect(row(page, 'Name')).toContainText(NAME_RULE);
      await expect(row(page, 'Drupal environment')).toContainText(ENV_RULE);
      await expect(row(page, 'Post-deploy commands')).toContainText(`Command line 1 ${TAB_RULE}`);
      await expect(row(page, 'Terminal 1 commands')).toContainText(`Command line 1 ${TAB_RULE}`);
      await expect(row(page, 'Terminal 2 commands')).toContainText(`Command line 1 ${TAB_RULE}`);
      expect(await hasHorizontalOverflow(page)).toBe(false);
      await expectNoBlockingViolations(page, 'refused template form');

      // Fixing the fields one by one: the form then goes through.
      await fillTemplate(page, { name: 'fixed', env: 'dev', postDeploy: 'a b', tty1: 'c d', tty2: 'e f' });
      await submitAndWait(page, page.getByRole('button', { name: 'Create template' }));
      await expect(page.getByTestId('flashes')).toContainText('Template created.');
      await expect(templateRow(page, 'fixed')).toHaveCount(1);
    } finally {
      await deleteProjectViaUi(page, project);
    }
    expect(unexpected(errors)).toEqual([]);
  });

  test('the inputs carry the length limits the rules enforce', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await page.goto(`/admin/projects/${project}/templates/new`);
      await expect(page.getByLabel('Name', { exact: true })).toHaveAttribute('maxlength', '40');
      await expect(page.getByLabel('Drupal environment')).toHaveAttribute('maxlength', '64');
      for (const label of ['Post-deploy commands', 'Terminal 1 commands', 'Terminal 2 commands']) {
        await expect(page.getByLabel(label)).toHaveAttribute('maxlength', String(50 * 2002));
      }
      await expect(page.getByLabel('Name', { exact: true })).toHaveAttribute('autocapitalize', 'none');
      await expect(page.getByLabel('Post-deploy commands')).toHaveAttribute('wrap', 'off');
      await expect(page.getByLabel('Name', { exact: true })).not.toHaveAttribute('required', 'true');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('a name that exists in the project is refused, the same name in another project is fine', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const first = uniqueName();
    const second = uniqueName();
    try {
      await createProjectViaUi(page, first);
      await createProjectViaUi(page, second);
      await createTemplateViaUi(page, first, { name: 'web' });
      await submitNewTemplate(page, first, { name: 'web', env: 'other' });
      await expect(row(page, 'Name')).toContainText('A template with this name already exists in this project.');
      await page.goto(`/admin/projects/${first}/templates`);
      await expect(page.getByTestId('template-row')).toHaveCount(1);
      await expect(templateRow(page, 'web').getByTestId('template-env')).toHaveText('-');

      await createTemplateViaUi(page, second, { name: 'web' });
      await expect(templateRow(page, 'web')).toHaveCount(1);
    } finally {
      await deleteProjectViaUi(page, first);
      await deleteProjectViaUi(page, second);
    }
    expect(unexpected(errors)).toEqual([]);
  });

  test('boundary values are accepted: 40 character name, 64 character env, 50 lines, 2000 character line, inner spaces', async ({ page }) => {
    const project = uniqueName();
    const name = `a${'b'.repeat(38)}c`;
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, {
        name,
        env: 'E'.repeat(64),
        postDeploy: Array.from({ length: 50 }, (_, i) => `cmd ${i}`).join('\n'),
        tty1: 'x'.repeat(2000),
        tty2: 'echo "a  b"  &&  ls',
      });
      const created = templateRow(page, name);
      await expect(created.getByTestId('template-env')).toHaveText('E'.repeat(64));
      await expect(created.getByTestId('template-post-deploy')).toHaveText('50');
      await expect(created.getByTestId('template-tty1')).toHaveText('1');
      await editTemplate(page, project, name);
      await expect(page.getByLabel('Terminal 2 commands')).toHaveValue('echo "a  b"  &&  ls');

      // A name with single inner dashes and digits.
      await createTemplateViaUi(page, project, { name: '1-2-3' });
      await expect(templateRow(page, '1-2-3')).toHaveCount(1);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});

test.describe('template validation, edit form', () => {
  const EDIT_CASES: Array<{ title: string; fields: TemplateFields; label: string; message: string }> = [
    { title: 'env with a space', fields: { env: 'a b' }, label: 'Drupal environment', message: ENV_RULE },
    { title: 'env of 65 characters', fields: { env: 'e'.repeat(65) }, label: 'Drupal environment', message: ENV_RULE },
    { title: 'post-deploy line with a tab', fields: { postDeploy: 'a\tb' }, label: 'Post-deploy commands', message: `Command line 1 ${TAB_RULE}` },
    { title: 'terminal 1 line with a control character', fields: { tty1: 'ok\nb\u0007c' }, label: 'Terminal 1 commands', message: `Command line 2 ${TAB_RULE}` },
    { title: '51 terminal 2 lines', fields: { tty2: Array.from({ length: 51 }, (_, i) => `c${i}`).join('\n') }, label: 'Terminal 2 commands', message: 'At most 50 commands are allowed.' },
    { title: 'a 2001 character line', fields: { tty1: 'y'.repeat(2001) }, label: 'Terminal 1 commands', message: 'Command line 1 is longer than 2000 characters.' },
  ];

  for (const { title, fields, label, message } of EDIT_CASES) {
    test(`refuses ${title} and keeps the stored template`, async ({ page }) => {
      const errors = collectBrowserErrors(page);
      const project = uniqueName();
      try {
        await createProjectViaUi(page, project);
        await createTemplateViaUi(page, project, { name: 'web', env: 'dev', postDeploy: 'keep me' });
        await editTemplate(page, project, 'web');
        await fillTemplate(page, fields);
        await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
        await expect(row(page, label)).toContainText(message);
        await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates/web$`));
        await expect(page.getByTestId('flashes')).toHaveCount(0);
        await expectNoBlockingViolations(page, 'refused edit form');
        await page.goto(`/admin/projects/${project}/templates`);
        const stored = templateRow(page, 'web');
        await expect(stored.getByTestId('template-env')).toHaveText('dev');
        await expect(stored.getByTestId('template-post-deploy')).toHaveText('1');
      } finally {
        await deleteProjectViaUi(page, project);
      }
      expect(unexpected(errors)).toEqual([]);
    });
  }

  test('a posted name cannot rename the template', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await createTemplateViaUi(page, project, { name: 'web' });
      await editTemplate(page, project, 'web');
      // The edit form has no Name field; a crafted extra field is not accepted by the form at all.
      const token = await page.locator('input[name$="[_token]"]').inputValue().catch(() => '');
      const response = await page.request.post(`/admin/projects/${project}/templates/web`, {
        form: { 'template[name]': 'renamed', 'template[_token]': token },
      });
      expect([200, 302, 422]).toContain(response.status());
      await page.goto(`/admin/projects/${project}/templates`);
      await expect(templateRow(page, 'web')).toHaveCount(1);
      await expect(templateRow(page, 'renamed')).toHaveCount(0);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});

test('the delete route refuses a missing or wrong CSRF token', async ({ page }) => {
  const project = uniqueName();
  try {
    await createProjectViaUi(page, project);
    await createTemplateViaUi(page, project, { name: 'web' });
    const response = await page.request.post(`/admin/projects/${project}/templates/web/delete`, { form: { _token: 'wrong' } });
    expect(response.status()).toBe(403);
    await page.goto(`/admin/projects/${project}/templates`);
    await expect(templateRow(page, 'web')).toHaveCount(1);
    await follow(page, page.getByRole('link', { name: 'General' }));
  } finally {
    await deleteProjectViaUi(page, project);
  }
});
