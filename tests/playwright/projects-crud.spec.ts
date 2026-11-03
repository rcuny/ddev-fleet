import { expect, test } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { fillProject, openNewProject, projectForm, readProject, submitCreate, submitSave } from './support/projectForm';
import { createDeployableProject, deployOne, uniqueLabel } from './support/instances';
import { createProjectViaUi, deleteProjectViaUi, follow, GIT_URL, openDialog, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// The project pages end to end: list, create, edit, delete and what each one shows afterwards. Every test owns a
// project with a unique name and removes it in `finally`.

const isPhone = (): boolean => test.info().project.name === 'phone';

test.describe('project list', () => {
  test('a created project is on the list with its git URL, branch and template count, and its row opens it', async ({ page }) => {
    const name = uniqueName();
    try {
      await openNewProject(page);
      await fillProject(page, { name, git: GIT_URL, branch: 'develop' });
      await submitCreate(page);

      await page.goto('/admin/projects');
      await expect(page.getByRole('heading', { level: 1, name: 'Projects' })).toBeVisible();
      await expect(page.getByTestId('projects-empty')).toHaveCount(0);
      const row = page.getByTestId('project-row').filter({ hasText: name });
      await expect(row).toHaveCount(1);
      await expect(row).toContainText(`${GIT_URL} · develop`);
      await expect(row).toContainText('0 templates');
      await expectNoBlockingViolations(page, 'the project list');

      await follow(page, row.getByRole('link'));
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
      await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('a project without a default branch shows the URL alone, and one template reads "1 template"', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await page.goto('/admin/projects');
      const row = page.getByTestId('project-row').filter({ hasText: name });
      await expect(row).not.toContainText('·');
      await expect(row).toContainText('0 templates');

      await page.goto(`/admin/projects/${name}/templates/new`);
      await page.getByLabel('Name', { exact: true }).fill('web');
      await submitAndWait(page, page.getByRole('button', { name: 'Create template' }));
      await expect(page.getByTestId('flashes')).toContainText('Template created.');
      await page.goto('/admin/projects');
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toContainText('1 template');
      await expect(page.getByTestId('project-row').filter({ hasText: name })).not.toContainText('1 templates');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('New project and Import are offered, and Cancel on the create form returns to the list', async ({ page }) => {
    await page.goto('/admin/projects');
    await expect(page.getByRole('link', { name: 'Import' })).toHaveAttribute('href', /\/admin\/import/);
    await follow(page, page.getByRole('link', { name: 'New project' }).first());
    await expect(page).toHaveURL(/\/admin\/projects\/new$/);
    await expectNoBlockingViolations(page, 'the create form');
    await follow(page, page.getByRole('link', { name: 'Cancel' }));
    await expect(page).toHaveURL(/\/admin\/projects$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Projects' })).toBeVisible();
  });

  test('an unknown project is a 404 page, and "new" is the create form and not a project', async ({ page }) => {
    const gone = await page.goto(`/admin/projects/${uniqueName('nope')}`);
    expect(gone?.status()).toBe(404);
    const created = await page.goto('/admin/projects/new');
    expect(created?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'New project' })).toBeVisible();
  });
});

test.describe('create', () => {
  test('Save shows the new project right away: flash, address, heading, git URL and every field as typed', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const name = uniqueName();
    const fields = {
      git: 'ssh://git@git.example.com:2222/acme/blog.git',
      branch: 'release/1.0',
      hostnames: 'www\nshop',
      identityName: 'Acme Bot',
      identityEmail: 'bot@example.com',
      pattern: '(?:ABC|XYZ)-\\d{1,6}',
      submodule: 'web/themes/custom',
    };
    try {
      await openNewProject(page);
      await fillProject(page, { name, ...fields, mode: 'Custom identity', typesense: true });
      await submitCreate(page);

      // "Save did nothing" must never come back: the result is on screen without another click.
      await expect(page.getByTestId('flashes')).toContainText('Project created.');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
      await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
      await expect(page.getByRole('heading', { level: 1, name: 'New project' })).toHaveCount(0);
      await expect(page.getByText(fields.git).first()).toBeVisible();
      expect(await readProject(page)).toEqual({ ...fields, mode: 'Custom identity', typesense: true });
      await expect(page.getByTestId('danger-zone')).toBeVisible();
      await expectNoBlockingViolations(page, 'the edit page right after create');

      // The same state after a fresh load.
      await page.goto(`/admin/projects/${name}`);
      expect(await readProject(page)).toEqual({ ...fields, mode: 'Custom identity', typesense: true });
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the create form offers every field with its help text and the host default identity', async ({ page }) => {
    await openNewProject(page);
    const form = projectForm(page);
    for (const label of ['Name', 'Git URL', 'Default branch', 'Additional hostnames', 'Identity name', 'Identity email', 'Issue id pattern', 'Submodule whose branch is shown']) {
      await expect(page.getByLabel(label, { exact: label === 'Name' })).toBeVisible();
    }
    await expect(page.getByLabel('Default template')).toHaveCount(0);
    await expect(page.getByLabel('Use the host default', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Run a Typesense search server for each instance')).not.toBeChecked();
    await expect(form).toContainText('cannot be changed later');
    await expect(form).toContainText('Use a deploy key, not a token in the URL.');
    await expect(form).toContainText('One label per line.');
    await expect(form).toContainText('Leave blank to use the repository default.');
    await expect(page.getByRole('button', { name: 'Create project' })).toBeVisible();
  });

  test('a created project with nothing optional filled has the defaults on the edit page', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      expect(await readProject(page)).toEqual({ git: GIT_URL, branch: '', hostnames: '', mode: 'Use the host default', identityName: '', identityEmail: '', pattern: '', submodule: '', typesense: false });
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});

test.describe('edit', () => {
  test('every field can be changed, saved and read back after a reload, and the list follows', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);

      const changed = {
        git: 'git@git.example.com:acme/shop.git',
        branch: 'develop',
        hostnames: 'www\nshop\napi',
        identityName: 'Zoë Bot',
        identityEmail: 'zoe@example.com',
        pattern: 'PRJ-[0-9]+',
        submodule: 'web/modules/custom/blog',
      };
      await fillProject(page, { ...changed, mode: 'Custom identity', typesense: true });
      await submitSave(page);
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
      expect(await readProject(page)).toEqual({ ...changed, mode: 'Custom identity', typesense: true });

      await page.goto(`/admin/projects/${name}`);
      expect(await readProject(page)).toEqual({ ...changed, mode: 'Custom identity', typesense: true });
      await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();

      await page.goto('/admin/projects');
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toContainText(`${changed.git} · develop`);

      // Back to the defaults: every optional field cleared, the typesense flag off.
      await page.goto(`/admin/projects/${name}`);
      await fillProject(page, { branch: '', hostnames: '', mode: 'Use the host default', pattern: '', submodule: '', typesense: false });
      await submitSave(page);
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      await page.goto(`/admin/projects/${name}`);
      expect(await readProject(page)).toMatchObject({ branch: '', hostnames: '', mode: 'Use the host default', identityName: '', identityEmail: '', pattern: '', submodule: '', typesense: false });
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('saving without a change is a normal save and keeps the values', async ({ page }) => {
    const name = uniqueName();
    try {
      await openNewProject(page);
      await fillProject(page, { name, git: GIT_URL, branch: 'main', hostnames: 'www', typesense: true });
      await submitCreate(page);
      await submitSave(page);
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      expect(await readProject(page)).toMatchObject({ git: GIT_URL, branch: 'main', hostnames: 'www', typesense: true });
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the edit page offers the sections, the tester, the export card and the danger zone', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      const tabs = page.getByRole('navigation', { name: 'Project sections' });
      for (const section of ['General', 'Templates', 'Secrets', 'Ports', 'Assets']) {
        await expect(tabs.getByRole('link', { name: section })).toBeVisible();
      }
      await expect(page.getByTestId('issue-id-preview')).toBeVisible();
      await expect(page.getByTestId('export-project')).toBeVisible();
      await expect(page.getByTestId('danger-zone')).toContainText('It is refused while the project has instances.');
      await expectNoBlockingViolations(page, 'the edit page');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});

test.describe('delete', () => {
  test('the dialog names the project, Cancel and Escape keep it, the confirmation removes it everywhere', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);

      let dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
      await expect(dialog).toContainText(`Delete ${name}?`);
      await expect(dialog).toContainText('This removes the project, its templates and its port assignments. It cannot be undone.');
      await expectNoBlockingViolations(page, 'the delete dialog');
      if (isPhone()) {
        expect(await hasHorizontalOverflow(page), 'overflow with the dialog open').toBe(false);
      }
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(dialog).toBeHidden();
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
      await expect(page.getByTestId('flashes')).not.toContainText('deleted');

      dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await page.goto('/admin/projects');
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toHaveCount(1);

      await follow(page, page.getByTestId('project-row').filter({ hasText: name }).getByRole('link'));
      dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete project' }));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" deleted.`);
      await expect(page).toHaveURL(/\/admin\/projects$/);
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toHaveCount(0);
      await expectNoBlockingViolations(page, 'the list after a delete');

      expect((await page.goto(`/admin/projects/${name}`))?.status()).toBe(404);
      expect((await page.goto(`/admin/projects/${name}/templates`))?.status()).toBe(404);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('a deleted project\'s name can be used again and starts empty', async ({ page }) => {
    const name = uniqueName();
    try {
      await openNewProject(page);
      await fillProject(page, { name, git: GIT_URL, branch: 'old', hostnames: 'www', typesense: true });
      await submitCreate(page);
      await deleteProjectViaUi(page, name);
      await createProjectViaUi(page, name, 'https://git.example.com/acme/second.git');
      expect(await readProject(page)).toMatchObject({ git: 'https://git.example.com/acme/second.git', branch: '', hostnames: '', typesense: false });
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('a project with an instance is not deleted: a warning explains, the project stays', async ({ page }) => {
    test.setTimeout(180_000);
    const name = uniqueName();
    const label = uniqueLabel();
    let instance = '';
    try {
      await createDeployableProject(name);
      instance = await deployOne(page, name, label);

      await page.goto(`/admin/projects/${name}`);
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete project' }));
      await expect(page.getByTestId('flashes')).toContainText('The project was not deleted.');
      await expect(page.getByTestId('flashes')).toContainText('instance(s) exist: destroy them first');
      await expect(page.getByTestId('flashes')).toContainText(instance);
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
      await expectNoBlockingViolations(page, 'the refused delete');

      await page.goto('/admin/projects');
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toHaveCount(1);

      // Destroy the instance, then the delete works.
      await page.goto(`/instances/${instance}`);
      const destroy = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
      await destroy.getByRole('button', { name: 'Destroy instance' }).click();
      await expect(page).toHaveURL(/\/jobs\/\d+$/);
      await expect(page.getByTestId('job-log')).toContainText('destroy');
      instance = '';
      await page.goto(`/admin/projects/${name}`);
      const confirm = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
      await submitAndWait(page, confirm.getByRole('button', { name: 'Delete project' }));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" deleted.`);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});

test.describe('phone and long values', () => {
  test('the flash of a long project name does not widen the page', async ({ page }) => {
    const name = `pw-${Date.now().toString(36)}-`.padEnd(40, 'q');
    try {
      await createProjectViaUi(page, name, `https://git.example.com/${'g'.repeat(180)}.git`);
      expect(await hasHorizontalOverflow(page), 'overflow after create').toBe(false);
      await expect(page.getByTestId('flashes')).toContainText('Project created.');
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
      expect(await hasHorizontalOverflow(page), 'overflow with the delete dialog').toBe(false);
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete project' }));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" deleted.`);
      expect(await hasHorizontalOverflow(page), 'overflow after delete').toBe(false);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});
