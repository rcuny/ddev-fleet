import { expect, test, type Page } from '@playwright/test';
import { fillProject, openNewProject, projectForm, submitCreate, type ProjectFields } from './support/projectForm';
import { createProjectViaUi, deleteProjectViaUi, GIT_URL, uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';

// Long values must not widen the page, on desktop and on the phone. The per-rule validation matrices are PHPUnit Functional
// tests (app/tests/Functional/Admin/ProjectValidationTest.php, FLE-127, FLE-161).

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

test.describe('git URL with a token', () => {
  test('a token in the URL is refused with a clear message @phone', async ({ page }) => {
    await expectRefused(page, { git: 'https://user:token@git.example.com/acme/blog.git' }, ['The URL contains a user name or token (user:token@). Remove it and use the deploy key instead.']);
    expect(await hasHorizontalOverflow(page), 'overflow on the refused form').toBe(false);
  });
});

test.describe('long values', () => {
  test('long refused values and messages do not widen the page @phone', async ({ page }) => {
    const fields = { name: 'n'.repeat(41), git: `https://git.example.com/${'a'.repeat(2030)}`, branch: 'b'.repeat(201), hostnames: 'h'.repeat(70), pattern: `A${'b'.repeat(200)}`, submodule: 's'.repeat(201) };
    await expectRefused(page, fields, ['The name must be at most 40 characters.', 'The URL must be at most 2048 characters.', 'The branch name must be at most 200 characters.', 'The pattern must be at most 200 characters.', 'The path must be at most 200 characters.']);
    expect(await hasHorizontalOverflow(page), 'overflow on the refused form with long values').toBe(false);
  });

  test('a 40-character name and a 200-character URL fit the list, the edit page and the flashes @phone', async ({ page }) => {
    const name = `pw-${Date.now().toString(36)}-`.padEnd(40, 'p');
    const git = `https://git.example.com/acme/${'r'.repeat(200 - 'https://git.example.com/acme/'.length - 4)}.git`;
    created = name;
    await createProjectViaUi(page, name, git);
    expect(await hasHorizontalOverflow(page), 'overflow after create').toBe(false);
    await page.goto(`/admin/projects/${name}/source`);
    expect(await hasHorizontalOverflow(page), 'overflow on the edit page').toBe(false);
    await page.goto('/admin/projects');
    await expect(page.getByTestId('project-row').filter({ hasText: name })).toContainText(git);
    expect(await hasHorizontalOverflow(page), 'overflow on the list').toBe(false);
  });
});
