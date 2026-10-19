import { expect, type Page } from '@playwright/test';
import { openDialog } from './projects';

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
