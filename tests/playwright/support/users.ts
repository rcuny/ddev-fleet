import { expect, type Locator, type Page } from '@playwright/test';
import { mkdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { follow, openDialog } from './projects';

/** The password every spec user starts with (12 or more characters, see PasswordPolicy). */
export const PASSWORD = 'correct horse battery staple';

/**
 * Deletes a user through its Danger zone dialog; does nothing when it is already gone (made for `finally`, so it must
 * not throw when the test failed before the user existed). The signed-in stand-in has no database row, so a spec user
 * is never "yourself" and, being a member, never the last administrator.
 */
export async function deleteUserViaUi(page: Page, username: string): Promise<void> {
  const response = await page.goto(`/admin/users/${username}/edit`);
  if (!response || response.status() === 404) {
    return;
  }
  const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this user' }));
  await dialog.getByRole('button', { name: 'Delete user' }).click();
  await expect(page.getByTestId('flashes')).toContainText(`User "${username}" deleted.`);
}

export interface NewUser {
  username: string;
  displayName?: string;
  email?: string;
  password?: string;
  siteOnly?: boolean;
  admin?: boolean;
  projects?: string[];
}

/** Ticks a checkbox of a user form by clicking its label (the label's ::after covers the row, the input is never hit). */
export async function tick(page: Page, form: 'user_create' | 'user_edit', field: 'siteOnly' | 'admin'): Promise<void> {
  await page.locator(`label[for=${form}_${field}]`).click();
}

/** Toggles a project row of the memberships field: a tap on the far edge of the full-width row. */
export async function toggleProject(page: Page, project: string): Promise<void> {
  const row = page.locator('[data-slot=checkbox-choice]').filter({ hasText: project });
  await expect(row).toHaveCount(1);
  const box = (await row.boundingBox())!;
  await row.click({ position: { x: box.width - 3, y: box.height - 3 } });
}

/** Creates a user through the New user form and waits for the confirmation (the edit page of the new user). */
export async function createUserViaUi(page: Page, user: NewUser): Promise<void> {
  await page.goto('/admin/users/new');
  await page.getByLabel('Username').fill(user.username);
  await page.getByLabel('Display name').fill(user.displayName ?? 'Spec Person');
  if (user.siteOnly) {
    await tick(page, 'user_create', 'siteOnly');
  } else {
    await page.getByLabel('Email').fill(user.email ?? `${user.username}@example.com`);
  }
  await page.getByLabel('Initial password').fill(user.password ?? PASSWORD);
  if (user.admin) {
    await tick(page, 'user_create', 'admin');
  }
  for (const project of user.projects ?? []) {
    await toggleProject(page, project);
  }
  await page.getByRole('button', { name: 'Create user' }).click();
  await expect(page.getByTestId('flashes')).toContainText(`User "${user.username}" created.`);
  await expect(page).toHaveURL(new RegExp(`/admin/users/${user.username}/edit$`));
}

/** Sets an input's value as a property: `fill` types it, and the input's maxlength would cut a too long one. */
export async function setValue(input: Locator, text: string): Promise<void> {
  await input.evaluate((node, value) => ((node as HTMLInputElement).value = value), text);
}

// The last-admin guard never lets the last enabled administrator go, not even through the UI or the CLI, so a spec that
// creates an administrator can only remove it while another one is around. The keeper is that other one: created once
// per database and never deleted. Specs that depend on how many administrators exist (every spec that creates or
// changes one) hold the admin lock, a directory in the temp dir shared by all workers (the desktop and the phone
// project run side by side on one database).

export const KEEPER = 'pw-keeper-admin';

const LOCK = join(tmpdir(), 'fleet-pw-admin-lock');
const LOCK_STALE_MS = 300_000;

/** Waits for the admin lock; returns the function that releases it. A lock left by a killed run is broken after 5 minutes. */
export async function acquireAdminLock(): Promise<() => void> {
  const deadline = Date.now() + 280_000;
  for (;;) {
    try {
      mkdirSync(LOCK);
      return () => rmSync(LOCK, { recursive: true, force: true });
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'EEXIST') {
        throw error;
      }
    }
    try {
      if (Date.now() - statSync(LOCK).mtimeMs > LOCK_STALE_MS) {
        rmSync(LOCK, { recursive: true, force: true });
      }
    } catch {
      // The holder released it between the two calls.
    }
    if (Date.now() > deadline) {
      throw new Error('The admin lock was not released within 280 s.');
    }
    await new Promise((done) => setTimeout(done, 250 + Math.random() * 250));
  }
}

/** The keeper exists, is an enabled administrator. Call it holding the admin lock. */
export async function ensureKeeperAdmin(page: Page): Promise<void> {
  const response = await page.goto(`/admin/users/${KEEPER}/edit`);
  if (!response || response.status() === 404) {
    await createUserViaUi(page, { username: KEEPER, displayName: 'Spec keeper', admin: true });
    return;
  }
  const enable = page.getByRole('button', { name: 'Enable user' });
  if (await enable.isVisible()) {
    await follow(page, enable);
  }
  const admin = page.getByRole('checkbox', { name: 'Administrator' });
  if (!(await admin.isChecked())) {
    await tick(page, 'user_edit', 'admin');
    await follow(page, page.getByRole('button', { name: 'Save', exact: true }));
    await expect(page.getByTestId('flashes')).toContainText('User saved.');
  }
}
