import { expect, test, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { fillProject, openNewProject, projectForm, readProject, submitCreate, submitSave, type ProjectFields } from './support/projectForm';
import { deleteProjectViaUi, GIT_URL, uniqueName } from './support/projects';
import { collectBrowserErrors } from './support/shell';

// What a browser adds to the project form's validation: the refused form on screen (errors next to their fields, typed values
// kept, accessible, no stray console error) and the controls that only exist in the page. The per-rule matrices (every name,
// URL, branch, hostname, identity, pattern and path case, refused and accepted) are PHPUnit Functional tests in
// app/tests/Functional/Admin/ProjectValidationTest.php (FLE-127). Desktop only: the phone has its overflow checks in
// projects-validation.spec.ts.

let created: string | null = null;

test.afterEach(async ({ page }) => {
  if (created !== null) {
    const name = created;
    created = null;
    await deleteProjectViaUi(page, name);
  }
});

/** Fills a valid project with `fields` replacing the defaults, submits, and expects a 422 page that names `messages`. */
async function expectRefused(page: Page, fields: ProjectFields, messages: string[]): Promise<string> {
  const name = fields.name ?? uniqueName();
  created = name;
  await openNewProject(page);
  await fillProject(page, { name, git: GIT_URL, ...fields });
  await submitCreate(page);
  await expect(page).toHaveURL(/\/admin\/projects\/new$/);
  for (const message of messages) {
    await expect(projectForm(page)).toContainText(message);
  }
  await expect(page.getByTestId('flashes')).toHaveCount(0);
  created = null;
  return name;
}

const ONLY_SCHEMES = 'Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.';

test('the three git identity modes are offered, the host default is preselected on a new project', async ({ page }) => {
  await openNewProject(page);
  await expect(page.locator('[data-slot=radio-row]')).toHaveCount(3);
  await expect(page.getByLabel('Use the host default', { exact: true })).toBeChecked();
  await expect(page.getByLabel('Identity name')).toHaveValue('');
  await expect(page.getByLabel('Identity email')).toHaveValue('');
});

test('an empty form shows the two required-field messages together and creates nothing', async ({ page }) => {
  const errors = collectBrowserErrors(page);
  await openNewProject(page);
  await submitCreate(page);
  await expect(page).toHaveURL(/\/admin\/projects\/new$/);
  await expect(projectForm(page)).toContainText('Enter a name.');
  await expect(projectForm(page)).toContainText('Enter the repository URL.');
  await expect(projectForm(page)).not.toContainText('Enter a branch name.');
  await expectNoBlockingViolations(page, 'the refused create form');
  expect(errors.filter((error) => !error.includes('status of 422'))).toEqual([]);
});

test('several errors are shown at once, each next to its own field, and every typed value stays', async ({ page }) => {
  const fields = { name: 'Bad Name', git: 'ftp://x', branch: 'a..b', hostnames: 'a.b', pattern: '(a+)+', submodule: '/x' };
  await expectRefused(page, fields, [
    'Use lowercase letters, digits and single dashes only',
    ONLY_SCHEMES,
    'This is not a valid git branch name',
    'Hostname 1: A hostname label',
    'nested quantifier',
    'Use a relative path such as web/themes/custom',
  ]);
  await expect(page.getByLabel('Name', { exact: true })).toHaveValue(fields.name);
  await expect(page.getByLabel('Git URL')).toHaveValue(fields.git);
  await expect(page.getByLabel('Default branch')).toHaveValue(fields.branch);
  await expect(page.getByLabel('Additional hostnames')).toHaveValue(fields.hostnames);
  await expect(page.getByLabel('Issue id pattern')).toHaveValue(fields.pattern);
  await expect(page.getByLabel('Submodule whose branch is shown')).toHaveValue(fields.submodule);
});

test('a refused Save keeps what was typed, shows no success flash and saves nothing', async ({ page }) => {
  const name = uniqueName();
  created = name;
  await openNewProject(page);
  await fillProject(page, { name, git: GIT_URL, branch: 'main', hostnames: 'www' });
  await submitCreate(page);
  await expect(page.getByTestId('flashes')).toContainText('Project created.');

  await fillProject(page, { git: 'http://x', branch: 'dev', hostnames: 'bad.label' });
  await submitSave(page);
  await expect(projectForm(page)).toContainText(ONLY_SCHEMES);
  await expect(projectForm(page)).toContainText('Hostname 1:');
  await expect(page.getByTestId('flashes')).toHaveCount(0);
  await expect(page.getByLabel('Git URL')).toHaveValue('http://x');
  await expect(page.getByLabel('Default branch')).toHaveValue('dev');
  await expectNoBlockingViolations(page, 'the refused edit form');

  // Nothing was saved, not even the valid field.
  await page.goto(`/admin/projects/${name}`);
  expect(await readProject(page)).toMatchObject({ git: GIT_URL, branch: 'main', hostnames: 'www' });
});

test('the issue id tester starts from the saved pattern', async ({ page }) => {
  const name = uniqueName();
  const pattern = '(?:ABC|XYZ)-\\d{1,6}';
  created = name;
  await openNewProject(page);
  await fillProject(page, { name, git: GIT_URL, pattern });
  await submitCreate(page);
  await expect(page.getByTestId('flashes')).toContainText('Project created.');
  await expect(page.getByLabel('Issue id pattern')).toHaveValue(pattern);
  await expect(page.getByTestId('issue-id-preview').getByLabel('Pattern')).toHaveValue(pattern);
});
