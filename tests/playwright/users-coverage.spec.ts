import { expect, test as base, type Locator, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow, unexpectedErrors } from './support/shell';
import { setStandInRole } from './support/standIn';
import {
  acquireAdminLock,
  createUserViaUi,
  deleteUserViaUi,
  ensureKeeperAdmin,
  KEEPER,
  PASSWORD,
  setValue,
  tick,
  toggleProject,
} from './support/users';

// Users and access, full coverage: every message UserService, PasswordPolicy and LastAdminGuard return, on the pages that
// show them (New user, Edit user, Set a new password, the Enable / Disable / Delete buttons), the list and its badges,
// project memberships, the site-only kind, the protections of the last administrator and of yourself, a member's
// refusals, long values at 390 px and axe in both themes on every state. Every test owns unique users and projects and
// removes them in `finally`. The tests that create or change an administrator hold the admin lock (support/users.ts):
// the last-admin guard depends on how many administrators exist, and the desktop and phone projects share one database.

const USERNAME_MESSAGE = 'The username must be 1-64 characters: lowercase letters, digits, dot, underscore or dash, starting with a letter or digit.';
const DISPLAY_NAME_MESSAGE = 'The display name must be 1-255 characters without control characters.';
const EMAIL_MESSAGE = 'The email address is not valid.';
const SHORT_PASSWORD = 'The password must be at least 12 characters long.';
const SITE_ONLY_SHORT_PASSWORD = 'The password must be at least 4 characters long.';
const LONG_PASSWORD = 'The password must be at most 128 characters long.';
const SITE_ONLY_ADMIN = 'A site-only user cannot be an administrator.';

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

/** A valid username short enough for the "password equals username" cases (below 12 characters). */
function shortName(): string {
  return `pw${randomBytes(3).toString('hex')}`;
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

/** The message list under a field of a form (`user_create`, `user_edit`, `user_password`). */
const fieldErrors = (page: Page, form: string, field: string): Locator => page.locator(`#${form}_${field}_errors`);

async function expectOnlyError(page: Page, form: string, field: string, message: string): Promise<void> {
  await expect(fieldErrors(page, form, field)).toHaveText(message);
  await expect(page.locator(`[id^=${form}_][id$=_errors]`)).toHaveCount(1);
  await expect(page.getByTestId('user-form').getByRole('alert')).toHaveCount(0);
}

test.describe('users: the New user form refuses', () => {
  test('a username, display name, email or password that breaks a rule: one message under its field, answered 422', async ({ page }) => {
    // 22 form round trips in one test: about 50 s on a loaded phone run, against the 60 s limit.
    test.slow();
    const errors = collectBrowserErrors(page);
    const existing = uniqueName('usr');
    const base = shortName();

    try {
      await createUserViaUi(page, { username: existing });
      await page.goto('/admin/users/new');
      const username = page.getByLabel('Username');
      const displayName = page.getByLabel('Display name');
      const email = page.getByLabel('Email');
      const password = page.getByLabel('Initial password');

      // The inputs carry the length limits of the rules (a keyboard cannot go past them).
      await expect(username).toHaveAttribute('maxlength', '64');
      await expect(displayName).toHaveAttribute('maxlength', '255');
      await expect(email).toHaveAttribute('maxlength', '254');
      await expect(password).toHaveAttribute('maxlength', '128');

      const attempt = async (values: { username?: string; displayName?: string; email?: string; password?: string }): Promise<number> => {
        await setValue(username, values.username ?? base);
        await setValue(displayName, values.displayName ?? 'Spec Person');
        await setValue(email, values.email ?? `${base}@example.com`);
        await setValue(password, values.password ?? PASSWORD);
        return create(page);
      };

      // Username: empty, uppercase, a space, a leading dash or dot, an at sign, 65 characters. The rest is valid.
      for (const bad of ['', 'Upper', 'has space', '-dash', '.dot', 'at@sign', 'a'.repeat(65)]) {
        expect(await attempt({ username: bad }), `username "${bad}"`).toBe(422);
        await expectOnlyError(page, 'user_create', 'username', USERNAME_MESSAGE);
        await expect(username).toHaveAttribute('aria-invalid', 'true');
        await expect(username).toHaveAttribute('aria-describedby', /user_create_username_errors/);
        await expect(page).toHaveURL(/\/admin\/users\/new$/);
      }
      expect(await attempt({ username: existing }), 'a username that is taken').toBe(422);
      await expectOnlyError(page, 'user_create', 'username', 'A user with this username already exists.');

      // Display name: empty, blank (trimmed to nothing), 256 characters, a control character, an invisible format character.
      for (const bad of ['', '   ', 'x'.repeat(256), 'be\u0007ll', 'zero​width']) {
        expect(await attempt({ displayName: bad }), `display name "${bad}"`).toBe(422);
        await expectOnlyError(page, 'user_create', 'displayName', DISPLAY_NAME_MESSAGE);
        await expect(displayName).toHaveAttribute('aria-invalid', 'true');
      }

      // Email: empty, no at sign, no dot in the domain, a space inside, 255 characters.
      for (const bad of ['', 'not-an-email', 'name@host', 'na me@example.com', `${'a'.repeat(244)}@example.com`]) {
        expect(await attempt({ email: bad }), `email "${bad}"`).toBe(422);
        await expectOnlyError(page, 'user_create', 'email', EMAIL_MESSAGE);
        await expect(email).toHaveAttribute('aria-invalid', 'true');
      }

      // Password: empty, 11 characters, 129 characters, NUL, the username in another case.
      for (const [bad, message] of [
        ['', SHORT_PASSWORD],
        ['elevenchars', SHORT_PASSWORD],
        ['x'.repeat(129), LONG_PASSWORD],
        [`nul\u0000${'x'.repeat(12)}`, 'The password must not contain NUL characters.'],
        [base.toUpperCase(), `${SHORT_PASSWORD} The password must not be the username.`],
      ] as const) {
        expect(await attempt({ password: bad }), `password "${bad}"`).toBe(422);
        await expectOnlyError(page, 'user_create', 'password', message);
        await expect(password).toHaveAttribute('aria-invalid', 'true');
        // A password is never sent back.
        await expect(password).toHaveValue('');
      }

      // The values that were valid stay in the form; nothing was created along the way.
      await expect(username).toHaveValue(base);
      await expect(displayName).toHaveValue('Spec Person');
      await page.goto('/admin/users');
      await expect(row(page, base)).toHaveCount(0);
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await deleteUserViaUi(page, existing);
    }
  });

  test('the limits are inclusive: 64-character username, 255-character display name, 12-character and 128-character passwords', async ({ page }) => {
    const longName = `${uniqueName('lim')}.`.padEnd(64, 'x');
    const other = uniqueName('lim');

    try {
      expect(longName).toHaveLength(64);
      await createUserViaUi(page, { username: longName, displayName: 'D'.repeat(255), password: 'p'.repeat(128), email: `${'e'.repeat(64)}@${'d'.repeat(60)}.example.com` });
      await expect(page.getByLabel('Display name')).toHaveValue('D'.repeat(255));
      await createUserViaUi(page, { username: other, password: 'twelve chars' });
      // Dots, underscores and dashes in the middle of a username work all the way through (edit page, delete).
      const dotted = `${shortName()}.a_b-c`;
      await createUserViaUi(page, { username: dotted });
      await deleteUserViaUi(page, dotted);
    } finally {
      await deleteUserViaUi(page, longName);
      await deleteUserViaUi(page, other);
    }
  });

  test('surrounding spaces are trimmed, a password keeps its spaces and any script counts by characters', async ({ page }) => {
    const username = uniqueName('usr');

    try {
      await page.goto('/admin/users/new');
      await page.getByLabel('Username').fill(username);
      await page.getByLabel('Display name').fill('   Spaced Person  ');
      await page.getByLabel('Email').fill(`  ${username}@example.com `);
      // Twelve characters, 36 bytes: the length is counted in characters.
      await page.getByLabel('Initial password').fill('パスワードパスワードパスワード');
      expect(await create(page)).toBe(303);
      await expect(page.getByRole('heading', { level: 1, name: 'Spaced Person' })).toBeVisible();
      await expect(page.getByLabel('Display name')).toHaveValue('Spaced Person');
      await expect(page.getByLabel('Email')).toHaveValue(`${username}@example.com`);
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

  test('site access only: no email, a password of 4 or more characters, never an administrator', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const project = uniqueName('prj');
    const username = uniqueName('shd');

    try {
      await createProjectViaUi(page, project);
      await page.goto('/admin/users/new');
      await tick(page, 'user_create', 'siteOnly');
      await page.getByLabel('Username').fill(username);
      await page.getByLabel('Display name').fill('Client shield');
      await toggleProject(page, project);

      // 3 characters are refused with the site-only minimum; the form keeps its site-only shape and the project.
      await page.getByLabel('Initial password').fill('abc');
      expect(await create(page)).toBe(422);
      await expectOnlyError(page, 'user_create', 'password', SITE_ONLY_SHORT_PASSWORD);
      await expect(page.getByRole('checkbox', { name: 'Site access only' })).toBeChecked();
      await expect(page.getByLabel('Email')).toBeHidden();
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeHidden();
      await expect(page.getByTestId('site-only-warning')).toBeVisible();
      await expect(page.getByRole('checkbox', { name: project })).toBeChecked();
      await expectNoBlockingViolations(page, 'axe, new site-only user refused');

      // The display name is still required.
      await page.getByLabel('Display name').fill('');
      await page.getByLabel('Initial password').fill('abcd');
      expect(await create(page)).toBe(422);
      await expectOnlyError(page, 'user_create', 'displayName', DISPLAY_NAME_MESSAGE);

      // A password equal to the username is fine for a site-only user; a typed email is ignored.
      await page.getByLabel('Display name').fill('Client shield');
      await page.getByLabel('Initial password').fill(username);
      await page.getByLabel('Email').evaluate((input) => ((input as HTMLInputElement).value = 'ignored@example.com'));
      expect(await create(page)).toBe(303);
      await expect(page.getByRole('heading', { level: 1, name: 'Client shield' })).toBeVisible();
      await expect(page.getByLabel('Email')).toBeHidden();

      await page.goto('/admin/users');
      const listed = row(page, username);
      await expect(listed.getByTestId('user-site-only')).toHaveText('Site access only');
      await expect(listed.getByTestId('user-projects')).toHaveText('1 project');
      await expect(listed).not.toContainText('ignored@example.com');
      await expect(listed).not.toContainText('@');
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });

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

  test('Cancel leaves without creating anything; the page is reached from the list', async ({ page }) => {
    const username = uniqueName('usr');
    await page.goto('/admin/users');
    await follow(page, page.getByRole('link', { name: 'New user' }).first());
    await expect(page).toHaveURL(/\/admin\/users\/new$/);
    await page.getByLabel('Username').fill(username);
    await page.getByLabel('Display name').fill('Never created');
    await follow(page, page.getByRole('link', { name: 'Cancel' }));
    await expect(page).toHaveURL(/\/admin\/users$/);
    await expect(row(page, username)).toHaveCount(0);
    await expect(page.getByTestId('flashes')).toHaveCount(0);
  });
});

test.describe('users: the list', () => {
  test('shows the badges and the project count of each kind of user, and opens the edit page', async ({ page }) => {
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
      await expectNoBlockingViolations(page, 'axe, user list');

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

  test('long values wrap or truncate at every width: no horizontal overflow on the list, new and edit pages', async ({ page }) => {
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
      await expectNoBlockingViolations(page, 'axe, refused new user form with long values');
    } finally {
      await deleteUserViaUi(page, username);
    }
  });
});

test.describe('users: edit', () => {
  test('the profile saves, each refusal sits under its field and changes nothing; the username is fixed', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const username = uniqueName('usr');

    try {
      await createUserViaUi(page, { username, displayName: 'Before Name' });
      await expect(page.getByLabel('Username')).toHaveCount(0);
      await expect(page.getByLabel('Display name')).toHaveAttribute('maxlength', '255');
      await expect(page.getByLabel('Email')).toHaveAttribute('maxlength', '254');

      for (const [field, bad, message] of [
        ['displayName', '', DISPLAY_NAME_MESSAGE],
        ['displayName', 'x'.repeat(256), DISPLAY_NAME_MESSAGE],
        ['displayName', 'be\u0007ll', DISPLAY_NAME_MESSAGE],
        ['email', '', EMAIL_MESSAGE],
        ['email', 'not-an-email', EMAIL_MESSAGE],
        ['email', 'name@host', EMAIL_MESSAGE],
        ['email', `${'a'.repeat(244)}@example.com`, EMAIL_MESSAGE],
      ] as const) {
        await page.goto(`/admin/users/${username}/edit`);
        await setValue(page.getByLabel(field === 'email' ? 'Email' : 'Display name'), bad);
        expect(await save(page, username), `${field} "${bad}"`).toBe(422);
        await expectOnlyError(page, 'user_edit', field, message);
        await expect(page.getByLabel(field === 'email' ? 'Email' : 'Display name')).toHaveAttribute('aria-invalid', 'true');
      }

      // Nothing was changed by the refusals.
      await page.goto(`/admin/users/${username}/edit`);
      await expect(page.getByLabel('Display name')).toHaveValue('Before Name');
      await expect(page.getByLabel('Email')).toHaveValue(`${username}@example.com`);

      await setValue(page.getByLabel('Display name'), '  After Name ');
      await setValue(page.getByLabel('Email'), `new.${username}@example.org`);
      expect(await save(page, username)).toBe(303);
      await expect(page.getByTestId('flashes')).toContainText('User saved.');
      await expect(page).toHaveURL(new RegExp(`/admin/users/${username}/edit$`));
      await expect(page.getByRole('heading', { level: 1, name: 'After Name' })).toBeVisible();
      await expect(page.getByLabel('Display name')).toHaveValue('After Name');
      await expect(page.getByLabel('Email')).toHaveValue(`new.${username}@example.org`);
      await page.goto('/admin/users');
      await expect(row(page, username)).toContainText(`${username} · new.${username}@example.org`);
      await expect(row(page, username)).toContainText('After Name');

      // Back to users leaves the form.
      await follow(page, row(page, username).getByRole('link'));
      await follow(page, page.getByRole('link', { name: 'Back to users' }));
      await expect(page).toHaveURL(/\/admin\/users$/);
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

  test('project memberships: add, remove, clear; the list count follows', async ({ page }) => {
    const p1 = uniqueName('prj');
    const p2 = uniqueName('prj');
    const username = uniqueName('usr');

    try {
      await createProjectViaUi(page, p1);
      await createProjectViaUi(page, p2);
      await createUserViaUi(page, { username });
      await expect(page.getByRole('checkbox', { name: p1 })).not.toBeChecked();
      await expect(page.getByRole('checkbox', { name: p2 })).not.toBeChecked();

      await toggleProject(page, p1);
      await toggleProject(page, p2);
      expect(await save(page, username)).toBe(303);
      await expect(page.getByRole('checkbox', { name: p1 })).toBeChecked();
      await expect(page.getByRole('checkbox', { name: p2 })).toBeChecked();
      await page.goto('/admin/users');
      await expect(row(page, username).getByTestId('user-projects')).toHaveText('2 projects');

      await page.goto(`/admin/users/${username}/edit`);
      await toggleProject(page, p1);
      expect(await save(page, username)).toBe(303);
      await expect(page.getByRole('checkbox', { name: p1 })).not.toBeChecked();
      await expect(page.getByRole('checkbox', { name: p2 })).toBeChecked();
      await page.goto('/admin/users');
      await expect(row(page, username).getByTestId('user-projects')).toHaveText('1 project');

      await page.goto(`/admin/users/${username}/edit`);
      await toggleProject(page, p2);
      expect(await save(page, username)).toBe(303);
      await expect(page.getByRole('checkbox', { name: p2 })).not.toBeChecked();
      await page.goto('/admin/users');
      await expect(row(page, username).getByTestId('user-projects')).toHaveText('0 projects');
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, p1);
      await deleteProjectViaUi(page, p2);
    }
  });

  test('a regular user becomes site-only and back: the email goes, a valid one is needed to return', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const username = uniqueName('usr');

    try {
      await createUserViaUi(page, { username, displayName: 'Regular' });
      await expect(page.getByTestId('user-site-only')).toHaveCount(0);
      await expect(page.getByTestId('site-only-warning')).toBeHidden();
      await expect(page.getByTestId('password-card')).toContainText('Replaces the current password. The person can also reset it');

      await tick(page, 'user_edit', 'siteOnly');
      await expect(page.getByLabel('Email')).toBeHidden();
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeHidden();
      await expect(page.getByTestId('site-only-warning')).toBeVisible();
      expect(await save(page, username)).toBe(303);
      await expect(page.getByTestId('flashes')).toContainText('User saved.');
      await expect(page.getByRole('checkbox', { name: 'Site access only' })).toBeChecked();
      await expect(page.getByTestId('user-status').getByTestId('user-site-only')).toBeVisible();
      await expect(page.getByText(`${username} · site access only`)).toBeVisible();
      await expect(page.getByTestId('password-card')).toContainText('at least 4 characters');
      await expectNoBlockingViolations(page, 'axe, site-only edit page');

      // Back to a regular user without an email: refused under the email, and the user stays site-only.
      await tick(page, 'user_edit', 'siteOnly');
      await expect(page.getByLabel('Email')).toBeVisible();
      await expect(page.getByLabel('Email')).toHaveValue('');
      expect(await save(page, username)).toBe(422);
      await expectOnlyError(page, 'user_edit', 'email', EMAIL_MESSAGE);
      await page.goto(`/admin/users/${username}/edit`);
      await expect(page.getByRole('checkbox', { name: 'Site access only' })).toBeChecked();

      await tick(page, 'user_edit', 'siteOnly');
      await page.getByLabel('Email').fill(`${username}@example.com`);
      expect(await save(page, username)).toBe(303);
      await expect(page.getByRole('checkbox', { name: 'Site access only' })).not.toBeChecked();
      await expect(page.getByTestId('user-site-only')).toHaveCount(0);
      await expect(page.getByText(`${username} · ${username}@example.com`)).toBeVisible();
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

  test('a user that does not exist answers 404 on every page and action; a wrong CSRF token answers 403', async ({ page }) => {
    const username = uniqueName('usr');
    const missing = uniqueName('gone');

    try {
      await createUserViaUi(page, { username });
      const response = await page.goto(`/admin/users/${missing}/edit`);
      expect(response?.status()).toBe(404);
      for (const action of ['password', 'enable', 'disable', 'delete']) {
        const post = await page.request.post(`/admin/users/${missing}/${action}`, { form: { _token: 'x' }, maxRedirects: 0 });
        expect(post.status(), `${action} of a missing user`).toBe(404);
      }
      // A user with a bad token is refused and unchanged.
      for (const action of ['enable', 'disable', 'delete']) {
        const post = await page.request.post(`/admin/users/${username}/${action}`, { form: { _token: 'x' }, maxRedirects: 0 });
        expect(post.status(), `${action} with a wrong token`).toBe(403);
      }
      await page.goto(`/admin/users/${username}/edit`);
      await expect(page.getByTestId('user-status')).toContainText('Active');
    } finally {
      await deleteUserViaUi(page, username);
    }
  });
});

test.describe('users: set a new password', () => {
  test('each rule has its message, the field is emptied, the success notice warns about the Authelia ban', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const username = shortName();

    try {
      await createUserViaUi(page, { username });
      const field = page.getByLabel('New password');
      await expect(field).toHaveAttribute('maxlength', '128');
      await expect(field).toHaveAttribute('autocomplete', 'new-password');

      for (const [bad, message] of [
        ['', SHORT_PASSWORD],
        ['elevenchars', SHORT_PASSWORD],
        ['x'.repeat(129), LONG_PASSWORD],
        [username.toUpperCase(), `${SHORT_PASSWORD} The password must not be the username.`],
        [`nul\u0000${'x'.repeat(12)}`, 'The password must not contain NUL characters.'],
      ] as const) {
        await setValue(field, bad);
        expect(await setPassword(page, username), `password "${bad.slice(0, 20)}"`).toBe(422);
        await expectOnlyError(page, 'user_password', 'password', message);
        await expect(field).toHaveAttribute('aria-invalid', 'true');
        await expect(field).toHaveValue('');
        // The profile form next to it is intact and shows no error.
        await expect(page.getByLabel('Display name')).toHaveValue('Spec Person');
        await expect(page.locator('#user_edit_displayName_errors')).toHaveCount(0);
      }
      await expectNoBlockingViolations(page, 'axe, refused password');

      // 12 characters (one script, many bytes) and 128 characters are the inclusive limits.
      for (const good of ['é'.repeat(12), 'p'.repeat(128), PASSWORD]) {
        await setValue(field, good);
        expect(await setPassword(page, username)).toBe(303);
        await expect(page.getByTestId('flashes')).toContainText(`The password of "${username}" was changed.`);
        await expect(page.getByTestId('flashes')).toContainText(`Authelia may still block "${username}" for up to`);
        await expect(field).toHaveValue('');
        // The password is never echoed anywhere on the page.
        expect(await page.content()).not.toContain(good.length > 20 ? good.slice(0, 20) : 'correct horse');
      }
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

  test('a site-only user: 4 characters is enough, 3 are not, the username is allowed', async ({ page }) => {
    const username = uniqueName('shd');

    try {
      await createUserViaUi(page, { username, siteOnly: true, password: 'abcd' });
      const field = page.getByLabel('New password');
      await setValue(field, 'abc');
      expect(await setPassword(page, username)).toBe(422);
      await expectOnlyError(page, 'user_password', 'password', SITE_ONLY_SHORT_PASSWORD);
      await setValue(field, 'x'.repeat(129));
      expect(await setPassword(page, username)).toBe(422);
      await expectOnlyError(page, 'user_password', 'password', LONG_PASSWORD);
      await setValue(field, 'wxyz');
      expect(await setPassword(page, username)).toBe(303);
      await expect(page.getByTestId('flashes')).toContainText(`The password of "${username}" was changed.`);
      await setValue(field, username);
      expect(await setPassword(page, username)).toBe(303);
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

  test('a regular user needs 12 characters when the site-only box was the only thing that allowed less', async ({ page }) => {
    const username = uniqueName('usr');

    try {
      // Site-only with a 4-character password, then back to regular: the short password stays until it is replaced.
      await createUserViaUi(page, { username, siteOnly: true, password: 'abcd' });
      await tick(page, 'user_edit', 'siteOnly');
      await page.getByLabel('Email').fill(`${username}@example.com`);
      expect(await save(page, username)).toBe(303);
      await setValue(page.getByLabel('New password'), 'abcd');
      expect(await setPassword(page, username)).toBe(422);
      await expectOnlyError(page, 'user_password', 'password', SHORT_PASSWORD);
    } finally {
      await deleteUserViaUi(page, username);
    }
  });
});

test.describe('users: disable, enable and delete', () => {
  test('a disabled user is marked everywhere, can still be edited and is enabled again', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const username = uniqueName('usr');

    try {
      await createUserViaUi(page, { username, displayName: 'Switch Me' });
      await expect(page.getByTestId('user-status')).toContainText('Active');
      await expect(page.getByTestId('user-status')).toContainText('A disabled user cannot sign in and loses access to every project.');
      await expect(page.getByRole('button', { name: 'Enable user' })).toHaveCount(0);

      await submitAndWait(page, page.getByRole('button', { name: 'Disable user' }));
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" disabled.`);
      await expect(page.getByTestId('user-status')).toContainText('Disabled');
      await expect(page.getByTestId('user-status')).toContainText('This user cannot sign in. Enable the account to give the access back.');
      await expect(page.getByRole('button', { name: 'Disable user' })).toHaveCount(0);
      await expectNoBlockingViolations(page, 'axe, disabled user edit page');

      // Editing a disabled user keeps it disabled.
      await setValue(page.getByLabel('Display name'), 'Still Off');
      expect(await save(page, username)).toBe(303);
      await expect(page.getByTestId('user-status')).toContainText('Disabled');
      await page.goto('/admin/users');
      await expect(row(page, username).getByTestId('user-disabled')).toHaveText('Disabled');
      await expect(row(page, username)).toContainText('Still Off');

      await page.goto(`/admin/users/${username}/edit`);
      await submitAndWait(page, page.getByRole('button', { name: 'Enable user' }));
      await expect(page.getByTestId('flashes')).toContainText(`User "${username}" enabled.`);
      await expect(page.getByTestId('user-status')).toContainText('Active');
      await page.goto('/admin/users');
      await expect(row(page, username).getByTestId('user-disabled')).toHaveCount(0);
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

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
      await expectNoBlockingViolations(page, 'axe, delete user dialog');
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

test.describe('users: a member', () => {
  test('is refused every page and every action, and learns nothing about the users', async ({ page, context, baseURL }) => {
    const username = uniqueName('usr');

    try {
      await createUserViaUi(page, { username, displayName: 'Hidden Person' });
      await setStandInRole(context, 'member', baseURL!);
      for (const path of ['/admin/users', '/admin/users/new', `/admin/users/${username}/edit`]) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(403);
        await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible();
        await expect(page.locator('body')).not.toContainText('Hidden Person');
      }
      for (const path of ['/admin/users/new', `/admin/users/${username}/edit`, `/admin/users/${username}/password`, `/admin/users/${username}/enable`, `/admin/users/${username}/disable`, `/admin/users/${username}/delete`]) {
        const post = await page.request.post(path, { form: { _token: 'x' }, maxRedirects: 0 });
        expect(post.status(), `POST ${path}`).toBe(403);
      }
      await setStandInRole(context, 'admin', baseURL!);
      await page.goto(`/admin/users/${username}/edit`);
      await expect(page.getByTestId('user-status')).toContainText('Active');
      await expect(page.getByLabel('Display name')).toHaveValue('Hidden Person');
    } finally {
      await setStandInRole(context, 'admin', baseURL!);
      await deleteUserViaUi(page, username);
    }
  });
});

/** Signs the browser in as another stand-in user (a name with no database row, or one that has). */
async function actAs(page: Page, username: string, baseURL: string): Promise<void> {
  await page.context().addCookies([{ name: 'fleet_stand_in_user', value: username, url: baseURL }]);
}

/** The enabled administrators of the list other than the keeper: the last-admin specs need there to be none. */
async function otherEnabledAdmins(page: Page): Promise<number> {
  await page.goto('/admin/users');
  return page
    .locator('[data-testid=user-row]')
    .filter({ has: page.getByTestId('user-admin') })
    .filter({ hasNot: page.getByTestId('user-disabled') })
    .filter({ hasNot: page.locator(`xpath=self::*[@data-username="${KEEPER}"]`) })
    .count();
}

test.describe('users: administrators', () => {
  test('an administrator sees All projects, can be demoted and promoted, and is never site-only', async ({ page, adminLock }) => {
    void adminLock;
    const errors = collectBrowserErrors(page);
    const username = uniqueName('adm');

    try {
      await ensureKeeperAdmin(page);
      await createUserViaUi(page, { username, displayName: 'Second Admin', admin: true });
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeChecked();
      await expect(page.getByTestId('user-status')).toContainText('Admin');
      await page.goto('/admin/users');
      await expect(row(page, username).getByTestId('user-admin')).toHaveText('Admin');
      await expect(row(page, username).getByTestId('user-projects')).toHaveText('All projects');
      await expect(row(page, KEEPER).getByTestId('user-admin')).toBeVisible();

      // An administrator cannot become site-only: the message sits under the visible box.
      await page.goto(`/admin/users/${username}/edit`);
      await tick(page, 'user_edit', 'siteOnly');
      expect(await save(page, username)).toBe(422);
      await expectOnlyError(page, 'user_edit', 'siteOnly', SITE_ONLY_ADMIN);
      await page.goto(`/admin/users/${username}/edit`);
      await expect(page.getByRole('checkbox', { name: 'Site access only' })).not.toBeChecked();
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeChecked();

      // Demote (another administrator exists), then promote again.
      await tick(page, 'user_edit', 'admin');
      expect(await save(page, username)).toBe(303);
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).not.toBeChecked();
      await page.goto('/admin/users');
      await expect(row(page, username).getByTestId('user-admin')).toHaveCount(0);
      await expect(row(page, username).getByTestId('user-projects')).toHaveText('0 projects');
      await page.goto(`/admin/users/${username}/edit`);
      await tick(page, 'user_edit', 'admin');
      expect(await save(page, username)).toBe(303);
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeChecked();

      await page.goto(`/admin/users/${username}/edit`);
      await expectNoBlockingViolations(page, 'axe, administrator edit page');
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

  test('the last enabled administrator cannot be disabled, deleted or demoted; a second one lifts the guard', async ({ page, adminLock, baseURL }) => {
    void adminLock;
    const errors = collectBrowserErrors(page);
    const second = uniqueName('adm');
    const actor = `pw-${randomBytes(4).toString('hex')}`;

    try {
      await ensureKeeperAdmin(page);
      test.skip((await otherEnabledAdmins(page)) > 0, 'the database holds another enabled administrator than the spec keeper');
      await actAs(page, actor, baseURL!);

      const guarded = async (): Promise<void> => {
        await page.goto(`/admin/users/${KEEPER}/edit`);
        await submitAndWait(page, page.getByRole('button', { name: 'Disable user' }));
        await expect(page.getByTestId('flashes')).toContainText(`The user was not disabled. "${KEEPER}" is the last enabled administrator and cannot be disabled.`);
        await expect(page.getByTestId('user-status')).toContainText('Active');

        const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this user' }));
        await submitAndWait(page, dialog.getByRole('button', { name: 'Delete user' }));
        await expect(page).toHaveURL(new RegExp(`/admin/users/${KEEPER}/edit$`));
        await expect(page.getByTestId('flashes')).toContainText(`The user was not deleted. "${KEEPER}" is the last enabled administrator and cannot be deleted.`);

        await tick(page, 'user_edit', 'admin');
        expect(await save(page, KEEPER)).toBe(422);
        await expect(page.getByTestId('user-form').getByRole('alert')).toHaveText(`"${KEEPER}" is the last enabled administrator and cannot be removed from the administrators.`);
        await page.goto(`/admin/users/${KEEPER}/edit`);
        await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeChecked();
      };

      await guarded();
      await expectNoBlockingViolations(page, 'axe, refused last-admin change');

      // A second enabled administrator: the keeper may go in principle, so the guard protects whoever is left.
      await createUserViaUi(page, { username: second, admin: true });
      await submitAndWait(page, page.getByRole('button', { name: 'Disable user' }));
      await expect(page.getByTestId('flashes')).toContainText(`User "${second}" disabled.`);
      // A disabled administrator does not count: the keeper is the last enabled one again.
      await guarded();
      await page.goto(`/admin/users/${second}/edit`);
      await submitAndWait(page, page.getByRole('button', { name: 'Enable user' }));
      await expect(page.getByTestId('flashes')).toContainText(`User "${second}" enabled.`);
      // Two enabled: either may be demoted (the keeper is checked, not changed).
      await tick(page, 'user_edit', 'admin');
      expect(await save(page, second)).toBe(303);
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).not.toBeChecked();
      await guarded();
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await actAs(page, 'admin', baseURL!);
      await deleteUserViaUi(page, second);
    }
  });

  test('you cannot disable, demote or delete your own account, not even with a crafted request', async ({ page, adminLock, baseURL }) => {
    void adminLock;
    const errors = collectBrowserErrors(page);
    const other = uniqueName('usr');

    try {
      await ensureKeeperAdmin(page);
      await createUserViaUi(page, { username: other });
      await actAs(page, KEEPER, baseURL!);

      await page.goto(`/admin/users/${KEEPER}/edit`);
      await expect(page.getByTestId('user-status')).toContainText('You are signed in as this user. You cannot disable, demote or delete your own account.');
      await expect(page.getByRole('button', { name: 'Disable user' })).toHaveCount(0);
      await expect(page.getByRole('button', { name: 'Delete this user' })).toHaveCount(0);
      await expect(page.getByTestId('danger-zone')).toHaveCount(0);
      const admin = page.getByRole('checkbox', { name: 'Administrator' });
      await expect(admin).toBeChecked();
      await expect(admin).toBeDisabled();
      await expect(page.getByText('You cannot change your own role. Ask another administrator.')).toBeVisible();
      await expectNoBlockingViolations(page, 'axe, own edit page');
      if (isPhone()) {
        await expectNoOverflow(page, 'the own edit page');
      }

      // The profile and the password can be changed.
      await setValue(page.getByLabel('Display name'), 'Keeper Renamed');
      expect(await save(page, KEEPER)).toBe(303);
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeChecked();
      await setValue(page.getByLabel('Display name'), 'Spec keeper');
      expect(await save(page, KEEPER)).toBe(303);

      // The buttons are not there, so the request is crafted the only way a browser can: another user's forms, aimed at
      // the signed-in account (the CSRF token is the real one).
      for (const [action, message] of [
        ['disable', 'The user was not disabled. Your own account cannot be disabled.'],
        ['delete', 'The user was not deleted. Your own account cannot be deleted.'],
      ] as const) {
        await page.goto(`/admin/users/${other}/edit`);
        await page.locator(`form[action$="/${other}/${action}"]`).evaluate((form, target) => form.setAttribute('action', target), `/admin/users/${KEEPER}/${action}`);
        if (action === 'delete') {
          const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this user' }));
          await submitAndWait(page, dialog.getByRole('button', { name: 'Delete user' }));
        } else {
          await submitAndWait(page, page.getByRole('button', { name: 'Disable user' }));
        }
        await expect(page).toHaveURL(new RegExp(`/admin/users/${KEEPER}/edit$`));
        await expect(page.getByTestId('flashes')).toContainText(message);
        await expect(page.getByTestId('user-status')).toContainText('You are signed in');
      }
      await actAs(page, 'admin', baseURL!);
      await page.goto('/admin/users');
      await expect(row(page, KEEPER).getByTestId('user-admin')).toBeVisible();
      await expect(row(page, KEEPER).getByTestId('user-disabled')).toHaveCount(0);
      expect(unexpectedErrors(errors)).toEqual([]);
    } finally {
      await actAs(page, 'admin', baseURL!);
      await deleteUserViaUi(page, other);
    }
  });
});

test.describe('users: layout and accessibility of every state', () => {
  test('refused and filled forms, the dialog and the lists pass axe in both themes and fit 390 px', async ({ page }) => {
    const project = uniqueName('prj');
    const username = uniqueName('usr');

    try {
      await createProjectViaUi(page, project);
      await createUserViaUi(page, { username, projects: [project] });

      await page.goto('/admin/users/new');
      await expectNoBlockingViolations(page, 'axe, empty new user form');
      expect(await create(page)).toBe(422);
      await expectNoBlockingViolations(page, 'axe, new user form with a username error');
      await tick(page, 'user_create', 'siteOnly');
      await expectNoBlockingViolations(page, 'axe, new site-only user form');
      if (isPhone()) {
        await expectNoOverflow(page, 'the refused new user form');
        await expectTall(page.getByRole('button', { name: 'Create user' }));
      }

      await page.goto(`/admin/users/${username}/edit`);
      await setValue(page.getByLabel('Email'), 'nope');
      expect(await save(page, username)).toBe(422);
      await expectNoBlockingViolations(page, 'axe, edit form with an email error');
      await setValue(page.getByLabel('New password'), 'tiny');
      expect(await setPassword(page, username)).toBe(422);
      await expectNoBlockingViolations(page, 'axe, edit page with a password error');
      if (isPhone()) {
        await expectNoOverflow(page, 'the refused edit page');
        for (const name of ['Set password', 'Disable user', 'Delete this user']) {
          await expectTall(page.getByRole('button', { name }));
        }
        await expectTall(page.getByLabel('New password'));
      }
      await follow(page, page.getByRole('link', { name: 'Back to users' }));
      await expectNoBlockingViolations(page, 'axe, user list');
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });
});
