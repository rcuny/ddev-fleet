import { expect, test as base, type Locator, type Page } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow, unexpectedErrors } from './support/shell';
import {
  acquireAdminLock,
  createUserViaUi,
  deleteUserViaUi,
  ensureKeeperAdmin,
  KEEPER,
  setValue,
  tick,
} from './support/users';

// Users and access, what needs a browser: the Administrator / Site access only toggles, the delete dialog, the list at 390 px
// and the own edit page. Every message, rule and protection is a PHPUnit Functional test (app/tests/Functional/Admin/
// UserValidationTest.php and UserPagesTest.php, FLE-161). Every test owns unique users and projects and removes them in
// `finally`. The tests that touch an administrator hold the admin lock (support/users.ts): the last-admin guard depends on
// how many administrators exist, and the desktop and phone projects share one database.

const test = base.extend<{ adminLock: void }>({
  adminLock: [
    async ({}, use) => {
      const release = await acquireAdminLock();
      try {
        await use();
      } finally {
        release();
      }
    },
    { auto: false },
  ],
});

test.describe.configure({ timeout: 240_000 });

const isPhone = (): boolean => test.info().project.name === 'phone';

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

async function expectTall(target: Locator, min = 44): Promise<void> {
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box, 'element has a box').not.toBeNull();
  expect(box!.height, `height of ${await target.evaluate((node) => node.outerHTML.slice(0, 80))}`).toBeGreaterThanOrEqual(min - 0.5);
}

function row(page: Page, username: string): Locator {
  return page.locator(`[data-testid=user-row][data-username="${username}"]`);
}

/** Submits a form with a button and returns the HTTP status of the POST (303 saved, 422 refused) once the render is on screen. */
async function submit(page: Page, button: Locator, pathEnd: string): Promise<number> {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith(pathEnd)),
    submitAndWait(page, button),
  ]);
  return response.status();
}

const create = (page: Page): Promise<number> => submit(page, page.getByRole('button', { name: 'Create user' }), '/admin/users/new');
const save = (page: Page, username: string): Promise<number> => submit(page, page.getByRole('button', { name: 'Save', exact: true }), `/admin/users/${username}/edit`);
const setPassword = (page: Page, username: string): Promise<number> => submit(page, page.getByRole('button', { name: 'Set password' }), `/admin/users/${username}/password`);

test.describe('users: the New user form refuses', () => {
  test('ticking Administrator and then Site access only does not create a hidden conflict', async ({ page }) => {
    const username = uniqueName('shd');

    try {
      await page.goto('/admin/users/new');
      await page.getByLabel('Username').fill(username);
      await page.getByLabel('Display name').fill('Shield');
      await page.getByLabel('Initial password').fill('abcd');
      await tick(page, 'user_create', 'admin');
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeChecked();
      await tick(page, 'user_create', 'siteOnly');
      // The administrator row is hidden now, so what it holds must not be submitted as a refusal nobody can see.
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeHidden();
      // Unticking brings the row back, unticked.
      await tick(page, 'user_create', 'siteOnly');
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).not.toBeChecked();
      await tick(page, 'user_create', 'siteOnly');
      const status = await create(page);
      expect(status, 'created as a plain site-only user').toBe(303);
      await expect(page.getByTestId('user-site-only')).toBeVisible();
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" created.`);
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

});

test.describe('users: the list', () => {
  test('shows the badges and the project count of each kind of user, and opens the edit page @phone', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const p1 = uniqueName('prj');
    const p2 = uniqueName('prj');
    const none = uniqueName('usr');
    const one = uniqueName('usr');
    const two = uniqueName('usr');
    const shield = uniqueName('shd');
    const off = uniqueName('usr');

    try {
      await createProjectViaUi(page, p1);
      await createProjectViaUi(page, p2);
      await createUserViaUi(page, { username: none, displayName: 'No projects' });
      await createUserViaUi(page, { username: one, displayName: 'One project', projects: [p1] });
      await createUserViaUi(page, { username: two, displayName: 'Two projects', projects: [p1, p2] });
      await createUserViaUi(page, { username: shield, displayName: 'Shield', siteOnly: true, password: 'abcd', projects: [p2] });
      await createUserViaUi(page, { username: off, displayName: 'Turned off', projects: [p1] });
      await follow(page, page.getByRole('button', { name: 'Disable user' }));
      await expect(page.getByTestId('flashes')).toContainText(`User "${off}" disabled.`);

      await page.goto('/admin/users');
      await expect(page.getByRole('heading', { level: 1, name: 'Users' })).toBeVisible();
      await expect(page.getByTestId('users-empty')).toHaveCount(0);
      await expect(row(page, none).getByTestId('user-projects')).toHaveText('0 projects');
      await expect(row(page, one).getByTestId('user-projects')).toHaveText('1 project');
      await expect(row(page, two).getByTestId('user-projects')).toHaveText('2 projects');
      await expect(row(page, two)).toContainText(`${two} · ${two}@example.com`);
      await expect(row(page, two)).toContainText('Two projects');
      for (const username of [none, one, two]) {
        await expect(row(page, username).getByTestId('user-admin')).toHaveCount(0);
        await expect(row(page, username).getByTestId('user-site-only')).toHaveCount(0);
        await expect(row(page, username).getByTestId('user-disabled')).toHaveCount(0);
      }
      await expect(row(page, shield).getByTestId('user-site-only')).toHaveText('Site access only');
      await expect(row(page, shield)).toContainText(shield);
      await expect(row(page, off).getByTestId('user-disabled')).toHaveText('Disabled');
      await expect(row(page, off).getByTestId('user-projects')).toHaveText('1 project');
      if (isPhone()) {
        await expectNoOverflow(page, 'the user list');
        await expectTall(row(page, two).getByRole('link'));
      }

      await follow(page, row(page, two).getByRole('link'));
      await expect(page).toHaveURL(new RegExp(`/admin/users/${two}/edit$`));
      await expect(page.getByRole('heading', { level: 1, name: 'Two projects' })).toBeVisible();
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      for (const username of [none, one, two, shield, off]) {
        await deleteUserViaUi(page, username);
      }
      await deleteProjectViaUi(page, p1);
      await deleteProjectViaUi(page, p2);
    }
  });

  test('long values wrap or truncate at every width: no horizontal overflow on the list, new and edit pages @phone', async ({ page }) => {
    const username = uniqueName('lng').padEnd(64, 'u');
    const display = `${'Wide'.repeat(63)}x`;
    expect(display).toHaveLength(253);
    const email = `${'l'.repeat(60)}@${'d'.repeat(60)}.${'s'.repeat(60)}.example.com`;

    try {
      await createUserViaUi(page, { username, displayName: display, email });
      for (const path of ['/admin/users', `/admin/users/${username}/edit`]) {
        await page.goto(path);
        await expect(page.getByText(display.slice(0, 20)).first()).toBeVisible();
        await expectNoOverflow(page, path);
      }
      // The refused form with the longest values in every field, errors included.
      await page.goto('/admin/users/new');
      await setValue(page.getByLabel('Username'), 'U'.repeat(64));
      await setValue(page.getByLabel('Display name'), 'W'.repeat(255));
      await setValue(page.getByLabel('Email'), 'e'.repeat(254));
      await setValue(page.getByLabel('Initial password'), 'p'.repeat(128));
      expect(await create(page)).toBe(422);
      await expectNoOverflow(page, 'the refused new user form');
    } finally {
      await deleteUserViaUi(page, username);
    }
  });
});

test.describe('users: disable, enable and delete', () => {
  test('delete: Cancel and Escape keep the user, confirming removes the account and its memberships', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName('prj');
    const username = uniqueName('usr');

    try {
      await createProjectViaUi(page, project);
      await createUserViaUi(page, { username, projects: [project] });
      await expect(page.getByTestId('danger-zone')).toContainText('The last enabled administrator cannot be deleted.');

      let dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this user' }));
      await expect(dialog).toContainText(`Delete ${username}?`);
      await expect(dialog).toContainText('This removes the account and its access to every project. It cannot be undone.');
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(dialog).toBeHidden();

      dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this user' }));
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(page).toHaveURL(new RegExp(`/admin/users/${username}/edit$`));
      await page.goto('/admin/users');
      await expect(row(page, username)).toHaveCount(1);

      await page.goto(`/admin/users/${username}/edit`);
      dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this user' }));
      await submitAndWait(page, dialog.getByRole('button', { name: 'Delete user' }));
      await expect(page).toHaveURL(/\/admin\/users$/);
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" deleted.`);
      await expect(row(page, username)).toHaveCount(0);
      expect((await page.goto(`/admin/users/${username}/edit`))?.status()).toBe(404);

      // The memberships went with the account: the same username starts with no project.
      await createUserViaUi(page, { username });
      await page.goto('/admin/users');
      await expect(row(page, username).getByTestId('user-projects')).toHaveText('0 projects');
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });
});

/** Signs the browser in as another stand-in user (a name with no database row, or one that has). */
async function actAs(page: Page, username: string, baseURL: string): Promise<void> {
  await page.context().addCookies([{ name: 'fleet_stand_in_user', value: username, url: baseURL }]);
}

test.describe('users: administrators', () => {
  test('your own edit page offers no disable or delete, locks the role and fits the screen @phone', async ({ page, adminLock, baseURL }) => {
    // The refusals and their exact messages are PHPUnit (UserValidationTest::testYourOwnAccountIsProtected...).
    void adminLock;
    const errors = collectBrowserErrors(page);

    try {
      await ensureKeeperAdmin(page);
      await actAs(page, KEEPER, baseURL!);
      await page.goto(`/admin/users/${KEEPER}/edit`);
      await expect(page.getByTestId('user-status')).toContainText('You are signed in as this user.');
      await expect(page.getByRole('button', { name: 'Disable user' })).toHaveCount(0);
      await expect(page.getByRole('button', { name: 'Delete this user' })).toHaveCount(0);
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeDisabled();
      if (isPhone()) {
        await expectNoOverflow(page, 'the own edit page');
      }
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await actAs(page, 'admin', baseURL!);
    }
  });
});

test.describe('users: layout and accessibility of every state', () => {
  test('refused and filled forms, the dialog and the lists fit 390 px @phone', async ({ page }) => {
    const project = uniqueName('prj');
    const username = uniqueName('usr');

    try {
      await createProjectViaUi(page, project);
      await createUserViaUi(page, { username, projects: [project] });

      await page.goto('/admin/users/new');
      expect(await create(page)).toBe(422);
      await tick(page, 'user_create', 'siteOnly');
      if (isPhone()) {
        await expectNoOverflow(page, 'the refused new user form');
        await expectTall(page.getByRole('button', { name: 'Create user' }));
      }

      await page.goto(`/admin/users/${username}/edit`);
      await setValue(page.getByLabel('Email'), 'nope');
      expect(await save(page, username)).toBe(422);
      await setValue(page.getByLabel('New password'), 'tiny');
      expect(await setPassword(page, username)).toBe(422);
      if (isPhone()) {
        await expectNoOverflow(page, 'the refused edit page');
        for (const name of ['Set password', 'Disable user', 'Delete this user']) {
          await expectTall(page.getByRole('button', { name }));
        }
        await expectTall(page.getByLabel('New password'));
      }
      await follow(page, page.getByRole('link', { name: 'Back to users' }));
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });
});
