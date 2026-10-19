import { expect, type Locator, type Page, type Response } from '@playwright/test';
import { randomBytes } from 'node:crypto';

/** A git URL that passes the rules (https, no credentials). Neutral host: the public product names no owner. */
export const GIT_URL = 'https://git.example.com/acme/blog.git';

/**
 * A project/template name that is valid (lowercase letters, digits, single dashes), at most 40 characters and unique per call:
 * the suite runs fully parallel on one shared database, and against the shared dev database of the companion it must be
 * repeatable, so every test owns its own name.
 */
export function uniqueName(prefix = 'pw'): string {
  const name = `${prefix}-${Date.now().toString(36)}-${randomBytes(3).toString('hex')}`;
  return name.slice(0, 40);
}

/** Creates a project through the browser (New project form) and waits for the confirmation. */
export async function createProjectViaUi(page: Page, name: string, git: string = GIT_URL): Promise<void> {
  await page.goto('/admin/projects/new');
  await page.getByLabel('Name', { exact: true }).fill(name);
  await page.getByLabel('Git URL').fill(git);
  await page.getByRole('button', { name: 'Create project' }).click();
  await expect(page.getByTestId('flashes')).toContainText('Project created.');
  await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
}

/**
 * Deletes a project through its Danger zone dialog; does nothing when it is already gone (made for `finally`, so it
 * must not throw when the test failed before the project existed).
 */
export async function deleteProjectViaUi(page: Page, name: string): Promise<void> {
  const response = await page.goto(`/admin/projects/${name}`);
  if (!response || response.status() === 404) {
    return;
  }
  const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
  await dialog.getByRole('button', { name: 'Delete project' }).click();
  await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" deleted.`);
}

const isHtmlGet = (response: Response): boolean =>
  response.request().method() === 'GET' && (response.headers()['content-type'] ?? '').includes('text/html');

/**
 * Clicks (or otherwise triggers) a visit and returns once the new page is really on screen. Turbo first shows a cached
 * preview of a page visited before (html[data-turbo-preview]) and the URL already changes; a test that typed into that
 * preview would lose its input to the real render. The first HTML response is the visit itself.
 */
export async function navigate(page: Page, trigger: () => Promise<unknown>): Promise<void> {
  const loaded = page.waitForResponse(isHtmlGet);
  loaded.catch(() => undefined);
  await trigger();
  await loaded;
  await expect(page.locator('html')).not.toHaveAttribute('data-turbo-preview');
}

/** Follows a link or button that starts a visit, see {@link navigate}. */
export function follow(page: Page, target: Locator): Promise<void> {
  return navigate(page, () => target.click());
}

/**
 * Opens a dialog from its trigger. The controllers are loaded lazily and, on a busy host, after the HTML is
 * interactive: a click that came first does nothing, so it is repeated until the dialog shows (never while it is open).
 */
export async function openDialog(page: Page, trigger: Locator): Promise<Locator> {
  const dialog = page.getByRole('dialog');
  await expect(async () => {
    if (!(await dialog.isVisible())) {
      await trigger.click();
    }
    await expect(dialog).toBeVisible({ timeout: 2_000 });
  }).toPass({ timeout: 30_000 });
  return dialog;
}

/** Opens one of the project sections through the tab strip (the same links at every width, no script involved). */
export async function openSection(page: Page, section: 'General' | 'Templates' | 'Secrets' | 'Ports' | 'Assets'): Promise<void> {
  await follow(page, page.getByRole('navigation', { name: 'Project sections' }).getByRole('link', { name: section }));
}
