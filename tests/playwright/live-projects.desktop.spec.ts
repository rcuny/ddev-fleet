import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { baseURL, externalBaseURL } from './support/env';
import { follow, openDialog, openSection } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// Projects and templates on a real installation, in a real browser over HTTPS (no ignoreHTTPSErrors: the certificate
// must be valid), signed in through the Authelia portal as the administrator.
//
// It needs an installed host: FLEET_E2E_BASE_URL (https://<domain>) and FLEET_LIVE_ADMIN_PASSWORD. Without them the
// whole file is skipped, so the default gate never touches a server. Optional: FLEET_LIVE_ADMIN_USERNAME (default
// admin), FLEET_LIVE_ADMIN_NAME (the display name, default "Fleet admin"), FLEET_LIVE_VIEWPORT=desktop (default: phone
// width, 390x844), FLEET_LIVE_OUT (screenshots; the password never lands there), FLEET_LIVE_PROJECT (default demo),
// FLEET_LIVE_TEMPLATE (default blog), FLEET_LIVE_GIT (default a public https repository).
//
// FLEET_LIVE_PHASE splits the walk-through around host-side checks done over SSH (default: every phase in order):
//   build   sign in, field errors, create + edit the project, create + edit the template, issue id tester
//   ports   (FLEET_LIVE_PORT names a catalogue port the host operator assigned) the Ports tab ticks it
//   clean   delete the template and the project through the browser, leaving no test data behind
// The tests share one browser context and run in order.
const password = process.env.FLEET_LIVE_ADMIN_PASSWORD ?? '';
const phase = process.env.FLEET_LIVE_PHASE ?? 'all';
const runs = (name: string): boolean => 'all' === phase || phase === name;

test.skip(!externalBaseURL || !password, 'live projects spec: set FLEET_E2E_BASE_URL and FLEET_LIVE_ADMIN_PASSWORD');
test.describe.configure({ mode: 'serial', timeout: 180_000 });

const ADMIN = {
  username: process.env.FLEET_LIVE_ADMIN_USERNAME ?? 'admin',
  displayName: process.env.FLEET_LIVE_ADMIN_NAME ?? 'Fleet admin',
  password,
};
const VIEWPORT = 'desktop' === process.env.FLEET_LIVE_VIEWPORT ? { width: 1280, height: 800 } : { width: 390, height: 844 };
const OUT = process.env.FLEET_LIVE_OUT ?? 'test-results/live-projects';
const PROJECT = process.env.FLEET_LIVE_PROJECT ?? 'demo';
const TEMPLATE = process.env.FLEET_LIVE_TEMPLATE ?? 'blog';
const GIT = process.env.FLEET_LIVE_GIT ?? 'https://github.com/ddev/ddev.git';
const PORT = process.env.FLEET_LIVE_PORT ?? '';
const HOST = new URL(baseURL).hostname;
const AUTH_HOST = `auth.${HOST}`;
const label = VIEWPORT.width >= 768 ? 'desktop' : 'phone';

let session: BrowserContext;
let page: Page;
let errors: string[];

async function shot(name: string): Promise<void> {
  mkdirSync(OUT, { recursive: true });
  await page.screenshot({ path: join(OUT, `${label}-${name}.png`), fullPage: true });
}

async function expectNoOverflow(where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

test.beforeAll(async ({ browser }) => {
  expect(baseURL.startsWith('https://'), 'the live host is served over https').toBe(true);
  // Certificate errors are deliberately NOT ignored.
  session = await browser.newContext({ baseURL, viewport: VIEWPORT, ignoreHTTPSErrors: false });
  page = await session.newPage();
  errors = collectBrowserErrors(page);
});

test.afterAll(async () => {
  await session?.close();
});

test('sign in through Authelia as the administrator', async () => {
  await page.goto('/admin/projects');
  await expect(page).toHaveURL(new RegExp(`^https://${AUTH_HOST.replace(/\./g, '\\.')}/`));
  await page.getByRole('textbox', { name: /^Username\b/ }).fill(ADMIN.username);
  await page.getByRole('textbox', { name: /^Password\b/ }).fill(ADMIN.password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL(new RegExp(`^https://${HOST.replace(/\./g, '\\.')}/`), { timeout: 30_000 });
  await page.goto('/admin/projects');
  await expect(page.getByRole('heading', { level: 1, name: 'Projects' })).toBeVisible();
  if (VIEWPORT.width >= 768) {
    await expect(page.getByTestId('identity')).toContainText(ADMIN.displayName);
  }
  await shot('1-list');
  await expectNoOverflow('the project list');
});

test.describe('build', () => {
  test.skip(!runs('build'), 'phase');

  test('bad input shows field errors and keeps what was typed', async () => {
    await page.goto('/admin/projects/new');
    await page.getByLabel('Name', { exact: true }).fill('Bad Name');
    await page.getByLabel('Git URL').fill('http://example.com/x.git');
    const [refused] = await Promise.all([
      page.waitForResponse((response) => 'POST' === response.request().method()),
      page.getByRole('button', { name: 'Create project' }).click(),
    ]);
    expect(refused.status()).toBe(422);
    const form = page.getByTestId('project-form');
    await expect(form).toContainText('Use lowercase letters, digits and single dashes only');
    await expect(form).toContainText('Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.');
    await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Bad Name');
    await shot('2-field-errors');
    await expectNoOverflow('the field errors');
  });

  test('creates the project', async () => {
    await page.getByLabel('Name', { exact: true }).fill(PROJECT);
    await page.getByLabel('Git URL').fill(GIT);
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page.getByTestId('flashes')).toContainText('Project created.');
    await expect(page).toHaveURL(new RegExp(`/admin/projects/${PROJECT}$`));
    await expect(page.getByRole('heading', { level: 1, name: PROJECT })).toBeVisible();
    await shot('3-created');
    await expectNoOverflow('the new project');
  });

  test('edits the project', async () => {
    await page.getByLabel('Default branch').fill('main');
    await page.getByLabel('Additional hostnames').fill('www');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByTestId('flashes')).toContainText('Project saved.');
    await expect(page.getByLabel('Default branch')).toHaveValue('main');
    await expect(page.getByLabel('Additional hostnames')).toHaveValue('www');
    await shot('4-edited');
    await expectNoOverflow('the edited project');
  });

  test('the issue id tester extracts a key and refuses a hostile pattern', async () => {
    const tester = page.getByTestId('issue-id-preview');
    await expect(tester).toBeVisible();
    const pattern = tester.getByLabel('Pattern');
    await expect(async () => {
      const rerender = page.waitForResponse((response) => response.url().includes('/_components/') && 200 === response.status(), { timeout: 4_000 });
      rerender.catch(() => undefined);
      await pattern.fill('');
      await pattern.fill('ABC-[0-9]+');
      await rerender;
    }).toPass({ timeout: 60_000 });
    await tester.getByLabel('Sample instance label').fill('abc-1781');
    await expect(tester.getByTestId('issue-id-extracted')).toHaveText('ABC-1781');
    await expect(tester.getByTestId('issue-id-result')).toHaveAttribute('data-state', 'match');
    await shot('5-tester-match');
    await pattern.fill('(a+)+$');
    await expect(tester.getByTestId('issue-id-result')).toHaveAttribute('data-state', 'error');
    await expect(tester.getByTestId('issue-id-message')).toContainText('nested quantifier');
    await shot('6-tester-refused');
    await expectNoOverflow('the issue id tester');
  });

  test('creates and reopens a template', async () => {
    await openSection(page, 'Templates');
    await expect(page.getByTestId('templates-empty')).toBeVisible();
    await follow(page, page.getByRole('link', { name: 'New template' }));
    await page.getByLabel('Name', { exact: true }).fill(TEMPLATE);
    await page.getByLabel('Drupal environment').fill('dev');
    await page.getByLabel('Post-deploy commands').fill('composer install\ndrush deploy');
    await page.getByLabel('Terminal 1 commands').fill('ddev npm run watch');
    await page.getByLabel('Terminal 2 commands').fill('ddev logs -f');
    await page.getByRole('button', { name: 'Create template' }).click();
    await expect(page.getByTestId('flashes')).toContainText('Template created.');
    const row = page.getByTestId('template-row').filter({ hasText: TEMPLATE });
    await expect(row).toHaveCount(1);
    await expect(row.getByTestId('template-post-deploy')).toHaveText('2');
    await shot('7-templates');
    await expectNoOverflow('the template list');

    await follow(page, row.getByRole('link', { name: `Edit ${TEMPLATE}` }));
    await expect(page.getByLabel('Post-deploy commands')).toHaveValue('composer install\ndrush deploy');
    await page.getByLabel('Drupal environment').fill('prod');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByTestId('flashes')).toContainText('Template saved.');
    await follow(page, page.getByTestId('template-row').filter({ hasText: TEMPLATE }).getByRole('link', { name: `Edit ${TEMPLATE}` }));
    await expect(page.getByLabel('Drupal environment')).toHaveValue('prod');
    await shot('8-template-edited');
    await expectNoOverflow('the template editor');
  });

  test('the project list shows the project', async () => {
    await page.goto('/admin/projects');
    const row = page.getByTestId('project-row').filter({ hasText: PROJECT });
    await expect(row).toContainText('1 template');
    await expect(row).toContainText(GIT);
    await shot('9-list-with-project');
    await expectNoOverflow('the project list');
    expect(errors.filter((error) => !error.includes('status of 422')), 'browser errors').toEqual([]);
  });
});

test.describe('ports', () => {
  test.skip(!runs('ports') || '' === PORT, 'phase, or FLEET_LIVE_PORT not set');

  test('the Ports tab ticks the assigned port', async () => {
    await page.goto(`/admin/projects/${PROJECT}`);
    await openSection(page, 'Ports');
    await expect(page.getByTestId('ports-note')).toBeVisible();
    const row = page.getByTestId('port-row').filter({ hasText: PORT });
    await expect(row).toHaveCount(1);
    await expect(row.getByRole('checkbox')).toBeChecked();
    await shot('10-ports');
    await expectNoOverflow('the Ports tab');
  });
});

test.describe('clean', () => {
  test.skip(!runs('clean'), 'phase');

  test('deletes the template and the project', async () => {
    await page.goto(`/admin/projects/${PROJECT}/templates`);
    await follow(page, page.getByRole('link', { name: `Edit ${TEMPLATE}` }));
    const templateDialog = await openDialog(page, page.getByRole('button', { name: 'Delete this template' }));
    await shot('11-delete-template-dialog');
    await templateDialog.getByRole('button', { name: 'Delete template' }).click();
    await expect(page.getByTestId('flashes')).toContainText('Template deleted.');

    await openSection(page, 'General');
    const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
    await expect(dialog).toContainText(`Delete ${PROJECT}?`);
    await shot('12-delete-project-dialog');
    await expectNoOverflow('the delete dialog');
    await dialog.getByRole('button', { name: 'Delete project' }).click();
    await expect(page.getByTestId('flashes')).toContainText(`Project "${PROJECT}" deleted.`);
    await expect(page).toHaveURL(/\/admin\/projects$/);
    await expect(page.getByTestId('project-row').filter({ hasText: PROJECT })).toHaveCount(0);
    const gone = await page.goto(`/admin/projects/${PROJECT}`);
    expect(gone?.status()).toBe(404);
  });
});
