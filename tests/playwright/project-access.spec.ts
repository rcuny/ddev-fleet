import { expect, test } from '@playwright/test';
import { blockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors } from './support/shell';
import { createUserViaUi, deleteUserViaUi } from './support/users';

// The Access tab (FLE-230): members and site-only users, edited from the project and in step with the user page.
// The page rules are PHPUnit: ProjectAccessTabTest. Desktop here; the phone view is project-access.phone.spec.ts.

test.describe.configure({ timeout: 180_000 });

test.describe('project Access tab', () => {
  test('add and remove a member here, and see it on the user page', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName('prj');
    const regular = uniqueName('usr');
    const siteOnly = uniqueName('sit');

    try {
      await createProjectViaUi(page, project, undefined, 'overview');
      await createUserViaUi(page, { username: regular });
      await createUserViaUi(page, { username: siteOnly, siteOnly: true });

      await page.goto(`/admin/projects/${project}/access`);
      await expect(page.getByTestId('members-none')).toBeVisible();
      await expect(page.getByTestId('access-ports-none')).toBeVisible();

      await page.getByTestId('member-select').selectOption(siteOnly);
      await submitAndWait(page, page.getByTestId('member-add'));
      await expect(page.getByTestId('flashes')).toContainText(`"${siteOnly}" is now a member of "${project}".`);
      const row = page.getByTestId('member-row').filter({ hasText: siteOnly });
      await expect(row).toHaveCount(1);
      await expect(row.getByTestId('member-site-only')).toBeVisible();

      await page.getByTestId('member-select').selectOption(regular);
      await submitAndWait(page, page.getByTestId('member-add'));
      await expect(page.getByTestId('member-row').filter({ hasText: regular }).getByTestId('member-full')).toBeVisible();
      expect(await blockingViolations(page)).toEqual([]);

      // The user page shows the same membership.
      await page.goto(`/admin/users/${regular}/edit`);
      await expect(page.locator('[data-slot=checkbox-choice]').filter({ hasText: project })).toHaveCount(1);
      await expect(page.locator('[data-slot=checkbox-choice]').filter({ hasText: project }).locator('input')).toBeChecked();

      // Removing asks first; cancelling changes nothing.
      await page.goto(`/admin/projects/${project}/access`);
      const trigger = page.getByRole('button', { name: `Remove ${regular} from ${project}` });
      let dialog = await openDialog(page, trigger);
      await expect(dialog).toContainText(`Remove ${regular}?`);
      expect(await blockingViolations(page)).toEqual([]);
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(page.getByTestId('member-row')).toHaveCount(2);

      dialog = await openDialog(page, trigger);
      await submitAndWait(page, dialog.getByRole('button', { name: 'Remove member' }));
      await expect(page.getByTestId('flashes')).toContainText(`"${regular}" is no longer a member of "${project}".`);
      await expect(page.getByTestId('member-row')).toHaveCount(1);

      await page.goto(`/admin/users/${regular}/edit`);
      await expect(page.locator('[data-slot=checkbox-choice]').filter({ hasText: project }).locator('input')).not.toBeChecked();
      expect(errors).toEqual([]);
    } finally {
      await deleteUserViaUi(page, regular);
      await deleteUserViaUi(page, siteOnly);
      await deleteProjectViaUi(page, project);
    }
  });

  test('a membership made on the user page shows on the project tab', async ({ page }) => {
    const project = uniqueName('prj');
    const username = uniqueName('usr');

    try {
      await createProjectViaUi(page, project, undefined, 'overview');
      await createUserViaUi(page, { username, projects: [project] });
      await page.goto(`/admin/projects/${project}/access`);
      await expect(page.getByTestId('member-row').filter({ hasText: username })).toHaveCount(1);
      await follow(page, page.getByRole('link', { name: username }));
      await expect(page).toHaveURL(new RegExp(`/admin/users/${username}/edit$`));
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });
});
