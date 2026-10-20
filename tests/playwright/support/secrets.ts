import { expect, test as base, type Locator, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { openDialog } from './projects';

/** A valid secret name that is unique per call: capital letters, digits, underscores, at most 64 characters. */
export function uniqueSecret(prefix = 'PW'): string {
  return `${prefix}_${Date.now().toString(36).toUpperCase()}_${randomBytes(3).toString('hex').toUpperCase()}`;
}

/** A value with no meaning anywhere else on the page, so a leak is unmistakable. */
export function uniqueValue(): string {
  return `pw-value-${randomBytes(8).toString('hex')}`;
}

/** The placeholder a name gives: `[[jira-token]]` for `JIRA_TOKEN`. */
export function placeholderOf(name: string): string {
  return `[[${name.toLowerCase().replaceAll('_', '-')}]]`;
}

/**
 * `test` with an automatic `actor` fixture: the browser signs in as an admin of its own (the stand-in user cookie), so
 * the reveal throttle (10 a minute per user) and the audit log (the actor column) belong to this test alone, whatever
 * runs next to it on the shared database.
 */
export const test = base.extend<{ actor: string }>({
  actor: [
    async ({ context, baseURL }, use) => {
      const actor = `pw-${randomBytes(4).toString('hex')}`;
      await context.addCookies([{ name: 'fleet_stand_in_user', value: actor, url: baseURL! }]);
      await use(actor);
    },
    { auto: true },
  ],
});

export function secretRow(page: Page, name: string): Locator {
  return page.getByTestId('secret-row').filter({ has: page.getByTestId('secret-name').getByText(name, { exact: true }) });
}

/** Fills the add/replace form and presses Save, without waiting for the answer. */
export async function submitSecret(page: Page, name: string, value: string): Promise<void> {
  const form = page.getByTestId('secret-form');
  await form.getByLabel('Name').fill(name);
  await form.getByLabel('Value').fill(value);
  await form.getByTestId('secret-save').click();
}

/** Fills the form, saves and waits for the confirmation; a refused save reports its status. */
export async function saveSecret(page: Page, name: string, value: string): Promise<void> {
  const form = page.getByTestId('secret-form');
  await form.getByLabel('Name').fill(name);
  await form.getByLabel('Value').fill(value);
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/secrets')),
    form.getByTestId('secret-save').click(),
  ]);
  expect(response.status(), `save of ${name} answered ${response.status()}`).toBeLessThan(400);
  await expect(page.getByTestId('flashes')).toContainText(`Secret "${name}" saved.`);
}

/** Presses Reveal on a row and returns the dialog showing the value (a plain form POST: the page is reloaded). */
export async function revealSecret(page: Page, name: string): Promise<Locator> {
  await secretRow(page, name).getByTestId('secret-reveal').click();
  const dialog = page.getByTestId('reveal-dialog');
  await expect(dialog).toBeVisible({ timeout: 30_000 });
  return dialog;
}

/** Deletes a secret through its confirmation dialog; does nothing when it is gone (made for `finally`). */
export async function removeSecret(page: Page, listPath: string, name: string): Promise<void> {
  await page.goto(listPath);
  const row = secretRow(page, name);
  if ((await row.count()) === 0) {
    return;
  }
  const dialog = await openDialog(page, row.getByTestId('secret-delete'));
  await dialog.getByTestId('secret-delete-confirm').click();
  await expect(page.getByTestId('flashes')).toContainText(`Secret "${name}" deleted.`);
}
