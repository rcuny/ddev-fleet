import { expect, test, type Locator, type Page } from '@playwright/test';
import { blockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';
import { deleteUserViaUi, PASSWORD } from './support/users';

// Users and project access in a real browser, at 1280 px (desktop project) and 390 px (phone project). The spec has no
// .desktop/.phone suffix: it runs on both. Every test owns a unique project and user (uniqueName) and removes them in
// `finally`, so the suite is repeatable against the shared dev database and safe in parallel.

const isPhone = (): boolean => test.info().project.name === 'phone';

/** The element is at least `min` px tall: the tap-target size of the phone design (rule: 44 px). */
async function expectTall(target: Locator, min = 44): Promise<void> {
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box, 'element has a box').not.toBeNull();
  expect(box!.height, `height of ${await target.evaluate((node) => node.outerHTML.slice(0, 80))}`).toBeGreaterThanOrEqual(min - 0.5);
}

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

test.describe('users and project access', () => {
  test('admin adds a user to a project', async ({ page }) => {
    // About 20 page loads; a busy shared host needs minutes, not seconds.
    test.setTimeout(240_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName('prj');
    const username = uniqueName('usr');

    try {
      await createProjectViaUi(page, project);

      // From the list through "New user".
      await page.goto('/admin/users');
      await expect(page.getByRole('heading', { level: 1, name: 'Users' })).toBeVisible();
      await follow(page, page.getByRole('link', { name: 'New user' }).first());
      await expect(page.getByRole('heading', { level: 1, name: 'New user' })).toBeVisible();

      await page.getByLabel('Username').fill(username);
      await page.getByLabel('Display name').fill('Spec Person');
      await page.getByLabel('Email').fill(`${username}@example.com`);
      await page.getByLabel('Initial password').fill(PASSWORD);

      // The project is one full-width checkbox row: tapping its far edge toggles it (390 px: at least 44 px tall).
      const row = page.locator('[data-slot=checkbox-choice]').filter({ hasText: project });
      await expect(row).toHaveCount(1);
      const box = (await row.boundingBox())!;
      if (isPhone()) {
        expect(box.height).toBeGreaterThanOrEqual(43.5);
        await expectNoOverflow(page, 'the new user form with a project row');
      }
      const checkbox = page.getByRole('checkbox', { name: project });
      await expect(checkbox).not.toBeChecked();
      await row.click({ position: { x: box.width - 3, y: box.height - 3 } });
      await expect(checkbox).toBeChecked();

      await page.getByRole('button', { name: 'Create user' }).click();
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" created.`);
      await expect(page).toHaveURL(new RegExp(`/admin/users/${username}/edit$`));
      await expect(page.getByRole('heading', { level: 1, name: 'Spec Person' })).toBeVisible();
      await expect(page.getByRole('checkbox', { name: project })).toBeChecked();
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).not.toBeChecked();

      // The list shows the member with one project and no admin badge.
      await page.goto('/admin/users');
      const listed = page.getByTestId('user-row').filter({ hasText: username });
      await expect(listed).toHaveCount(1);
      await expect(listed.getByTestId('user-projects')).toHaveText('1 project');
      await expect(listed.getByTestId('user-admin')).toHaveCount(0);
      if (isPhone()) {
        await expectTall(listed.getByRole('link'));
        await expectNoOverflow(page, 'the user list');
      }

      // Remove the membership again from the edit page.
      await follow(page, listed.getByRole('link'));
      // Click the row, never the input: the label's ::after covers the whole row, so Playwright finds the label on top of
      // the input and keeps retrying "intercepts pointer events" (the config sets no action timeout: it would wait for the
      // whole test timeout). A click on the row reaches the label, which toggles the box natively.
      const editRow = page.locator('[data-slot=checkbox-choice]').filter({ hasText: project });
      await expect(editRow).toHaveCount(1);
      await expect(page.getByRole('checkbox', { name: project })).toBeChecked();
      await editRow.click();
      await expect(page.getByRole('checkbox', { name: project })).not.toBeChecked();
      await page.getByRole('button', { name: 'Save', exact: true }).click();
      await expect(page.getByTestId('flashes')).toContainText('User saved.');
      await expect(page.getByRole('checkbox', { name: project })).not.toBeChecked();
      await page.goto('/admin/users');
      await expect(page.getByTestId('user-row').filter({ hasText: username }).getByTestId('user-projects')).toHaveText('0 projects');

      expect(errors).toEqual([]);
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });

  test('creating a project and a user shows the result at once and keeps the admin signed in', async ({ page }) => {
    // FLE-100: both used to restart Authelia (everyone signed out, the save looked like it did nothing). The stand-in
    // stands in for the session: it must still be there, and each page shows what was just created.
    test.setTimeout(240_000);
    const project = uniqueName('prj');
    const username = uniqueName('usr');

    try {
      await createProjectViaUi(page, project);
      await expect(page.getByTestId('flashes')).toContainText('Project created.');
      await expect(page.getByRole('heading', { level: 1 })).toContainText(project);

      await page.goto('/admin/users/new');
      await page.getByLabel('Username').fill(username);
      await page.getByLabel('Display name').fill('Spec Person');
      await page.getByLabel('Email').fill(`${username}@example.com`);
      await page.getByLabel('Initial password').fill(PASSWORD);
      const row = page.locator('[data-slot=checkbox-choice]').filter({ hasText: project });
      const box = (await row.boundingBox())!;
      await row.click({ position: { x: box.width - 3, y: box.height - 3 } });
      await expect(page.getByRole('checkbox', { name: project })).toBeChecked();
      await page.getByRole('button', { name: 'Create user' }).click();
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" created.`);
      await expect(page.getByRole('checkbox', { name: project })).toBeChecked();

      // Still signed in: an admin page opens without a sign-in redirect, and it lists the new user and project.
      const response = await page.goto('/admin/users');
      expect(response?.status()).toBe(200);
      await expect(page.getByTestId('user-row').filter({ hasText: username })).toHaveCount(1);
      await page.goto('/admin/projects');
      await expect(page.getByText(project).first()).toBeVisible();
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });

  test('admin sets a password, disables and deletes a user', async ({ page }) => {
    test.setTimeout(240_000);
    const errors = collectBrowserErrors(page);
    const username = uniqueName('usr');

    try {
      await page.goto('/admin/users/new');
      await page.getByLabel('Username').fill(username);
      await page.getByLabel('Display name').fill('Spec Person');
      await page.getByLabel('Email').fill(`${username}@example.com`);
      await page.getByLabel('Initial password').fill('short');
      await page.getByRole('button', { name: 'Create user' }).click();
      // A password below the policy is refused under its field and nothing is created.
      await expect(page.getByTestId('user-form')).toContainText('The password must be at least 12 characters long.');
      await expect(page.getByLabel('Username')).toHaveValue(username);
      await expect(page.getByLabel('Initial password')).toHaveValue('');
      await page.getByLabel('Initial password').fill(PASSWORD);
      await page.getByRole('button', { name: 'Create user' }).click();
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" created.`);

      // Set a new password: refused when weak, accepted otherwise.
      await page.getByLabel('New password').fill('tiny');
      await submitAndWait(page, page.getByRole('button', { name: 'Set password' }));
      await expect(page.getByTestId('password-form')).toContainText('The password must be at least 12 characters long.');
      await page.getByLabel('New password').fill('another long passphrase');
      await submitAndWait(page, page.getByRole('button', { name: 'Set password' }));
      await expect(page.getByTestId('flashes')).toContainText(`The password of "${username}" was changed.`);

      // Disable and enable.
      await submitAndWait(page, page.getByRole('button', { name: 'Disable user' }));
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" disabled.`);
      await expect(page.getByTestId('user-status')).toContainText('Disabled');
      await page.goto('/admin/users');
      await expect(page.getByTestId('user-row').filter({ hasText: username }).getByTestId('user-disabled')).toBeVisible();
      await page.goto(`/admin/users/${username}/edit`);
      await submitAndWait(page, page.getByRole('button', { name: 'Enable user' }));
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" enabled.`);

      // The delete dialog fits on the screen and its buttons are tap-sized.
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this user' }));
      await expect(dialog).toContainText(`Delete ${username}?`);
      if (isPhone()) {
        await expectNoOverflow(page, 'the delete dialog');
        await expectTall(dialog.getByRole('button', { name: 'Cancel' }));
        await expectTall(dialog.getByRole('button', { name: 'Delete user' }));
      }
      await dialog.getByRole('button', { name: 'Delete user' }).click();
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" deleted.`);
      await expect(page).toHaveURL(/\/admin\/users$/);
      await expect(page.getByTestId('user-row').filter({ hasText: username })).toHaveCount(0);

      // The browser logs each refused form as a console error ("status of 422"): the two refusals above, nothing else.
      expect(errors.filter((error) => !error.includes('status of 422'))).toEqual([]);
      expect(errors).toHaveLength(2);
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

  test('a member cannot open the users pages', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'member', baseURL!);

    for (const path of ['/admin/users', '/admin/users/new', '/admin/users/anyone/edit']) {
      const response = await page.goto(path);
      expect(response?.status(), path).toBe(403);
      await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible();
      // No Users entry in any navigation (sidebar, bottom bar, More sheet).
      await expect(page.locator('a[href="/admin/users"]')).toHaveCount(0);
    }
  });

  test('the user pages fit the screen and pass axe in both themes', async ({ page }) => {
    test.setTimeout(240_000);
    const project = uniqueName('prj');
    const username = uniqueName('usr');

    try {
      await createProjectViaUi(page, project);
      await page.goto('/admin/users/new');
      await page.getByLabel('Username').fill(username);
      await page.getByLabel('Display name').fill('Spec Person');
      await page.getByLabel('Email').fill(`${username}@example.com`);
      await page.getByLabel('Initial password').fill(PASSWORD);
      await page.getByRole('button', { name: 'Create user' }).click();
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" created.`);

      for (const scheme of ['light', 'dark'] as const) {
        await page.emulateMedia({ colorScheme: scheme });
        for (const [label, path] of [
          ['list', '/admin/users'],
          ['new', '/admin/users/new'],
          ['edit', `/admin/users/${username}/edit`],
        ] as const) {
          await page.goto(path);
          await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
          if (scheme === 'dark') {
            await expect(page.locator('html')).toHaveClass(/\bdark\b/);
          }
          if (isPhone()) {
            await expectNoOverflow(page, `the ${label} page`);
          }
          expect(await blockingViolations(page), `axe, ${scheme} theme, ${label} page`).toEqual([]);
        }
      }

      if (isPhone()) {
        // Tap targets on the edit page.
        await page.goto(`/admin/users/${username}/edit`);
        await expectTall(page.getByRole('button', { name: 'Save', exact: true }));
        await expectTall(page.getByRole('button', { name: 'Set password' }));
        await expectTall(page.getByRole('button', { name: 'Disable user' }));
        await expectTall(page.getByRole('button', { name: 'Delete this user' }));
        await expectTall(page.getByLabel('Display name'));
        await expectTall(page.getByLabel('Email'));
      }
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });
});
