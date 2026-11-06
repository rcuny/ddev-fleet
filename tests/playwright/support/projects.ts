import { expect, type Locator, type Page } from '@playwright/test';
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
export async function createProjectViaUi(page: Page, name: string, git: string = GIT_URL, landOn: 'source' | 'overview' = 'source'): Promise<void> {
  await page.goto('/admin/projects/new');
  await page.getByLabel('Name', { exact: true }).fill(name);
  await page.getByLabel('Git URL').fill(git);
  await page.getByRole('button', { name: 'Create project' }).click();
  await expect(page.getByTestId('flashes')).toContainText('Project created.');
  // A new project lands on its Overview; most callers want the form, which is on the Source tab.
  await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
  if (landOn === 'source') {
    await openSection(page, 'Source');
  }
}

/** Types the project name the delete dialog asks for (read from the dialog itself, so the caller needs no name). */
export async function fillDeleteName(dialog: Locator): Promise<void> {
  const name = (await dialog.locator('label strong').textContent())?.trim() ?? '';
  await dialog.getByTestId('delete-confirm-name').fill(name);
}

/** Opens the project's Delete dialog on its Transfer & danger tab (goes there first unless the page is already on it). */
export async function openDeleteDialog(page: Page): Promise<Locator> {
  const match = /\/admin\/projects\/([a-z0-9-]+)/.exec(new URL(page.url()).pathname);
  if (match && !page.url().endsWith('/transfer')) {
    await page.goto(`/admin/projects/${match[1]}/transfer`);
  }
  return openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
}

/**
 * Deletes a project through its Danger zone dialog; does nothing when it is already gone (made for `finally`, so it
 * must not throw when the test failed before the project existed).
 */
export async function deleteProjectViaUi(page: Page, name: string): Promise<void> {
  const response = await page.goto(`/admin/projects/${name}/transfer`);
  if (!response || response.status() === 404) {
    return;
  }
  const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
  await fillDeleteName(dialog);
  await dialog.getByRole('button', { name: 'Delete project' }).click();
  await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" deleted.`);
}

/**
 * Clicks (or otherwise triggers) a visit and returns once the new page is really on screen. Two things look like
 * "the page arrived" and are not: Turbo shows a cached preview of a page visited before (html[data-turbo-preview]) and
 * the URL already changes, and Turbo prefetches a link on hover/mousedown, so an HTML response can come back before
 * the visit has rendered anything. A test that opened a dialog or typed into the old page would lose it to the real
 * render (the element "was detached from the DOM"). So the visit is waited for by what it does to the page: a marker
 * on <html> (which Turbo keeps) is cleared by the next page or frame render, and the preview flag must be gone. A full page load
 * (data-turbo="false") replaces <html>, which drops the marker as well.
 */
export async function navigate(page: Page, trigger: () => Promise<unknown>): Promise<void> {
  await page.evaluate(() => {
    const html = document.documentElement;
    html.dataset.navPending = '1';
    html.dataset.navLog = '';
    // Which Turbo events the visit went through, for the failure message (a visit with no before-fetch-request never started: the click was lost).
    const seen = [
      'turbo:click', 'turbo:visit', 'turbo:before-fetch-request', 'turbo:before-fetch-response', 'turbo:submit-end',
      'turbo:fetch-request-error', 'turbo:before-render', 'turbo:frame-render', 'turbo:render', 'turbo:load',
    ];
    const note = (event: Event): void => {
      html.dataset.navLog = `${html.dataset.navLog ?? ''} ${event.type}`.trim();
    };
    // Every way a visit can end clears the marker: a page or frame render (a form inside a <turbo-frame> renders the
    // frame only; a 422 or a redirect ends in a render too), turbo:load (the visit finished, also when it was served
    // from the cache), a request that failed (turbo:fetch-request-error: offline, aborted or cancelled), and an answer
    // that has nothing to render (204/205). Without these the flag stayed set for the whole expect timeout.
    const terminal = ['turbo:render', 'turbo:frame-render', 'turbo:load', 'turbo:fetch-request-error'];
    const done = (): void => {
      delete html.dataset.navPending;
      terminal.forEach((name) => document.removeEventListener(name, done));
      document.removeEventListener('turbo:before-fetch-response', noContent);
      seen.forEach((name) => document.removeEventListener(name, note));
    };
    const noContent = (event: Event): void => {
      const status = (event as CustomEvent<{ fetchResponse?: { statusCode?: number } }>).detail?.fetchResponse?.statusCode;
      if (status === 204 || status === 205) {
        done();
      }
    };
    seen.forEach((name) => document.addEventListener(name, note));
    terminal.forEach((name) => document.addEventListener(name, done));
    document.addEventListener('turbo:before-fetch-response', noContent);
  });
  await trigger();
  const html = page.locator('html');
  try {
    await expect(html).not.toHaveAttribute('data-nav-pending');
  } catch (error) {
    const log = (await html.getAttribute('data-nav-log').catch(() => null)) ?? '(page gone)';
    throw new Error(`The visit never rendered. Turbo events seen: ${log || '(none)'}\n${String(error)}`);
  }
  await expect(html).not.toHaveAttribute('data-turbo-preview');
}

/** Follows a link or button that starts a visit, see {@link navigate}. */
export function follow(page: Page, target: Locator): Promise<void> {
  return navigate(page, () => target.click());
}

/**
 * Clicks a submit button (or a button that posts a form) and returns once the Turbo render of the response is on
 * screen. Without it the next assertion can read the page from before the submit (a stale flash) and the next click
 * can hit an element the render then replaces. Same wait as {@link navigate}.
 */
export function submitAndWait(page: Page, target: Locator): Promise<void> {
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

/** The ten project sections, in tab order. */
export type ProjectSection =
  | 'Overview'
  | 'Source'
  | 'Templates'
  | 'Assets'
  | 'Network'
  | 'Access'
  | 'Capacity'
  | 'Keys & secrets'
  | 'Integrations'
  | 'Transfer & danger';

/** The names the sections had before the ten tabs (FLE-226): still accepted by openSection(). */
const SECTION_ALIASES = { General: 'Source', Ports: 'Network', Secrets: 'Keys & secrets', Webhooks: 'Integrations' } as const;

/**
 * Opens one of the project sections. From the sm breakpoint the tab strip is a row of links; below it the same
 * navigation is a labelled <select> that goes to the chosen tab. Old section names (General, Ports, Secrets, Webhooks)
 * map to the tab that took them over.
 */
export async function openSection(page: Page, section: ProjectSection | keyof typeof SECTION_ALIASES): Promise<void> {
  const target: ProjectSection = section in SECTION_ALIASES ? SECTION_ALIASES[section as keyof typeof SECTION_ALIASES] : (section as ProjectSection);
  const link = page.getByRole('navigation', { name: 'Project sections' }).getByRole('link', { name: target });
  if (await link.isVisible()) {
    await follow(page, link);
    return;
  }
  const select = page.getByRole('combobox', { name: 'Project section' });
  const option = select.locator('option', { hasText: target });
  const value = await option.getAttribute('value');
  await Promise.all([page.waitForURL((url) => url.pathname === value || url.href.endsWith(value ?? '')), select.selectOption({ label: (await option.textContent())?.trim() ?? target })]);
  await page.waitForLoadState('networkidle');
}
