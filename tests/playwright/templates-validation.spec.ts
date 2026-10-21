import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { createTemplateViaUi, editTemplate, fillTemplate, submitNewTemplate, templateRow } from './support/templates';

// What a browser adds to the template form's validation, on desktop and phone: the refused forms on screen (errors next to their
// fields, accessible, no overflow, no stray console error). The per-rule matrices of the create and edit forms, the length limits,
// the boundary values, the duplicate name, the rename attempt and the CSRF check are PHPUnit Functional tests in
// app/tests/Functional/Admin/TemplateValidationTest.php (FLE-127). A refused form answers 422, which the browser logs as a
// console error: the one expected console error of these tests.

const NAME_RULE = 'Use lowercase letters, digits and single dashes only, starting and ending with a letter or digit.';
const ENV_RULE = 'Use 1 to 64 letters, digits, dots, underscores or dashes.';
const TAB_RULE = 'contains a control character (a command is exactly one line, without tabs).';

/** The field's wrapper (label, widget, help, error) located by the field's label. */
function row(page: Page, label: string): Locator {
  return page.getByLabel(label, { exact: true }).locator('xpath=ancestor::div[1]');
}

const unexpected = (errors: string[]): string[] => errors.filter((error) => !error.includes('status of 422'));

test('all refusals show at once and the create form stays accessible without overflow', async ({ page }) => {
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
    expect(unexpected(errors)).toEqual([]);
  } finally {
    await deleteProjectViaUi(page, project);
  }
});

test('a refused edit shows its error, stays accessible and keeps the stored template', async ({ page }) => {
  const errors = collectBrowserErrors(page);
  const project = uniqueName();
  try {
    await createProjectViaUi(page, project);
    await createTemplateViaUi(page, project, { name: 'web', env: 'dev', postDeploy: 'keep me' });
    await editTemplate(page, project, 'web');
    await fillTemplate(page, { env: 'a b' });
    await submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
    await expect(row(page, 'Drupal environment')).toContainText(ENV_RULE);
    await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates/web$`));
    await expect(page.getByTestId('flashes')).toHaveCount(0);
    expect(await hasHorizontalOverflow(page)).toBe(false);
    await expectNoBlockingViolations(page, 'refused edit form');
    await page.goto(`/admin/projects/${project}/templates`);
    const stored = templateRow(page, 'web');
    await expect(stored.getByTestId('template-env')).toHaveText('dev');
    await expect(stored.getByTestId('template-post-deploy')).toHaveText('1');
    expect(unexpected(errors)).toEqual([]);
  } finally {
    await deleteProjectViaUi(page, project);
  }
});
