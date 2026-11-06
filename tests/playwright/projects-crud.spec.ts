import { expect, test } from '@playwright/test';
import { fillNetwork, fillProject, openNewProject, readNetwork, readProject, saveNetworkConfirmed, submitCreate, submitSave } from './support/projectForm';
import { createDeployableProject, deployOne, uniqueLabel } from './support/instances';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, openDeleteDialog, fillDeleteName, openSection, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// The project pages end to end: list, create, edit, delete and what each one shows afterwards. Every test owns a
// project with a unique name and removes it in `finally`.

const isPhone = (): boolean => test.info().project.name === 'phone';

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
      await openSection(page, 'Source');
      const { hostnames, ...sourceFields } = fields;
      expect(await readProject(page)).toEqual({ ...sourceFields, mode: 'Custom identity' });
      await expect(page.getByTestId('danger-zone')).toHaveCount(0);
      await openSection(page, 'Network');
      expect(await readNetwork(page)).toEqual({ hostnames, typesense: true });

      // The same state after a fresh load.
      await page.goto(`/admin/projects/${name}/source`);
      expect(await readProject(page)).toEqual({ ...sourceFields, mode: 'Custom identity' });
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('Git over SSH in the container is off by default and survives a save and a reload (FLE-145)', async ({ page }) => {
    const name = uniqueName();
    const box = page.getByLabel(/Git over SSH inside the container/);
    try {
      await openNewProject(page);
      await fillProject(page, { name, git: 'ssh://git@git.example.com:2222/acme/blog.git' });
      await expect(box).not.toBeChecked();
      await page.locator('[data-slot=checkbox-row]').filter({ hasText: 'Git over SSH inside the container' }).click();
      await expect(box).toBeChecked();
      await submitCreate(page);
      await expect(page.getByTestId('flashes')).toContainText('Project created.');

      await page.goto(`/admin/projects/${name}/source`);
      await expect(box).toBeChecked();
      await page.locator('[data-slot=checkbox-row]').filter({ hasText: 'Git over SSH inside the container' }).click();
      await submitSave(page);
      await page.goto(`/admin/projects/${name}/source`);
      await expect(box).not.toBeChecked();
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
        identityName: 'Zoë Bot',
        identityEmail: 'zoe@example.com',
        pattern: 'PRJ-[0-9]+',
        submodule: 'web/modules/custom/blog',
      };
      await fillProject(page, { ...changed, mode: 'Custom identity' });
      await submitSave(page);
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/source$`));
      expect(await readProject(page)).toEqual({ ...changed, mode: 'Custom identity' });

      // The hostnames and the Typesense toggle are saved from the Network tab, which asks before a new name goes live.
      await openSection(page, 'Network');
      await fillNetwork(page, { hostnames: 'www\nshop\napi', typesense: true });
      await saveNetworkConfirmed(page);
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');
      expect(await readNetwork(page)).toEqual({ hostnames: 'www\nshop\napi', typesense: true });

      await page.goto(`/admin/projects/${name}/source`);
      expect(await readProject(page)).toEqual({ ...changed, mode: 'Custom identity' });
      await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();

      await page.goto('/admin/projects');
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toContainText(`${changed.git} · develop`);

      // Back to the defaults: every optional field cleared, the typesense flag off.
      await page.goto(`/admin/projects/${name}/source`);
      await fillProject(page, { branch: '', mode: 'Use the host default', pattern: '', submodule: '' });
      await submitSave(page);
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      await page.goto(`/admin/projects/${name}/source`);
      expect(await readProject(page)).toMatchObject({ branch: '', mode: 'Use the host default', identityName: '', identityEmail: '', pattern: '', submodule: '' });
      await openSection(page, 'Network');
      await fillNetwork(page, { hostnames: '', typesense: false });
      await saveNetworkConfirmed(page);
      await expect(page.getByTestId('flashes')).toContainText('Network settings saved.');
      expect(await readNetwork(page)).toEqual({ hostnames: '', typesense: false });
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

});

test.describe('delete', () => {
  test('the delete button stays inert until the exact project name is typed; a wrong name keeps the project @phone', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      const dialog = await openDeleteDialog(page);
      await expect(dialog.getByTestId('delete-confirm-name')).toHaveAttribute('required', '');
      await expect(dialog.locator('label')).toContainText(`Type ${name} to confirm`);
      if (isPhone()) {
        expect(await hasHorizontalOverflow(page), 'overflow with the typed-name dialog open').toBe(false);
      }
      // A wrong name is refused by the server and leaves the project (the browser's own check is bypassed on purpose).
      await dialog.getByTestId('delete-confirm-name').fill(`${name}x`);
      await dialog.locator('form').evaluate((form: HTMLFormElement) => form.setAttribute('novalidate', ''));
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete project' }));
      await expect(page.getByTestId('flashes')).toContainText('The project was not deleted. Type the project name exactly to confirm.');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/transfer$`));
      await page.goto('/admin/projects');
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toHaveCount(1);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the dialog names the project, Cancel and Escape keep it, the confirmation removes it everywhere @phone', async ({ page }) => {
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);

      let dialog = await openDeleteDialog(page);
      await expect(dialog).toContainText(`Delete ${name}?`);
      await expect(dialog).toContainText('This removes the project, its templates and its port assignments. It cannot be undone.');
      if (isPhone()) {
        expect(await hasHorizontalOverflow(page), 'overflow with the dialog open').toBe(false);
      }
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(dialog).toBeHidden();
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/transfer$`));
      await expect(page.getByTestId('flashes')).toHaveCount(0);

      dialog = await openDeleteDialog(page);
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await page.goto('/admin/projects');
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toHaveCount(1);

      await follow(page, page.getByTestId('project-row').filter({ hasText: name }).getByRole('link'));
      await openSection(page, 'Source');
      dialog = await openDeleteDialog(page);
      await fillDeleteName(dialog);
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete project' }));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" deleted.`);
      await expect(page).toHaveURL(/\/admin\/projects$/);
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toHaveCount(0);

      expect((await page.goto(`/admin/projects/${name}`))?.status()).toBe(404);
      expect((await page.goto(`/admin/projects/${name}/templates`))?.status()).toBe(404);
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

      await page.goto(`/admin/projects/${name}/source`);
      const dialog = await openDeleteDialog(page);
      await fillDeleteName(dialog);
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete project' }));
      await expect(page.getByTestId('flashes')).toContainText('The project was not deleted.');
      await expect(page.getByTestId('flashes')).toContainText('instance(s) exist: destroy them first');
      await expect(page.getByTestId('flashes')).toContainText(instance);
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/transfer$`));

      await page.goto('/admin/projects');
      await expect(page.getByTestId('project-row').filter({ hasText: name })).toHaveCount(1);

      // Destroy the instance, then the delete works.
      await page.goto(`/instances/${instance}`);
      const destroy = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
      await destroy.getByRole('button', { name: 'Destroy instance' }).click();
      await expect(page).toHaveURL(/\/jobs\/\d+$/);
      await expect(page.getByTestId('job-log')).toContainText('destroy');
      instance = '';
      await page.goto(`/admin/projects/${name}/source`);
      const confirm = await openDeleteDialog(page);
      await fillDeleteName(confirm);
      await submitAndWait(page, confirm.getByRole('button', { name: 'Delete project' }));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" deleted.`);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});

test.describe('phone and long values', () => {
  test('the flash of a long project name does not widen the page @phone', async ({ page }) => {
    const name = `pw-${Date.now().toString(36)}-`.padEnd(40, 'q');
    try {
      await createProjectViaUi(page, name, `https://git.example.com/${'g'.repeat(180)}.git`, 'overview');
      expect(await hasHorizontalOverflow(page), 'overflow after create').toBe(false);
      await expect(page.getByTestId('flashes')).toContainText('Project created.');
      await openSection(page, 'Source');
      const dialog = await openDeleteDialog(page);
      expect(await hasHorizontalOverflow(page), 'overflow with the delete dialog').toBe(false);
      await fillDeleteName(dialog);
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete project' }));
      await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" deleted.`);
      expect(await hasHorizontalOverflow(page), 'overflow after delete').toBe(false);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});
