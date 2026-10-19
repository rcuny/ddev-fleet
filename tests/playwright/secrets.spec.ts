import { expect, test, type Locator, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { blockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, openDialog, openSection, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// Secrets in a real browser, at 1280 px (desktop project) and 390 px (phone project): the fleet-wide page, the project's
// Secrets tab, reveal, the audit log. Every test owns uniquely named secrets and removes them in `finally`, so the
// suite is repeatable against the shared dev database and safe in parallel. The "no placeholder" warning needs asset
// files on the server's disk, which a browser test cannot create: the functional tests cover it.

const isPhone = (): boolean => test.info().project.name === 'phone';

/** A valid secret name that is unique per call: capital letters, digits, underscores, at most 64 characters. */
function uniqueSecret(prefix = 'PW'): string {
  return `${prefix}_${Date.now().toString(36).toUpperCase()}_${randomBytes(3).toString('hex').toUpperCase()}`;
}

/** A value with no meaning anywhere else on the page, so a leak is unmistakable. */
function uniqueValue(): string {
  return `pw-value-${randomBytes(8).toString('hex')}`;
}

async function expectTall(target: Locator, min = 44): Promise<void> {
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box, 'element has a box').not.toBeNull();
  expect(box!.height, `height of ${await target.evaluate((node) => node.outerHTML.slice(0, 80))}`).toBeGreaterThanOrEqual(min - 0.5);
}

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

/** Fills the add/replace form and saves; waits for the confirmation. */
async function saveSecret(page: Page, name: string, value: string): Promise<void> {
  const form = page.getByTestId('secret-form');
  await form.getByLabel('Name').fill(name);
  await form.getByLabel('Value').fill(value);
  await form.getByTestId('secret-save').click();
  await expect(page.getByTestId('flashes')).toContainText(`Secret "${name}" saved.`);
}

/** Deletes a secret through its confirmation dialog; does nothing when it is gone (made for `finally`). */
async function deleteSecret(page: Page, listPath: string, name: string): Promise<void> {
  await page.goto(listPath);
  const row = page.getByTestId('secret-row').filter({ has: page.getByTestId('secret-name').getByText(name, { exact: true }) });
  if ((await row.count()) === 0) {
    return;
  }
  const dialog = await openDialog(page, row.getByTestId('secret-delete'));
  await dialog.getByTestId('secret-delete-confirm').click();
  await expect(page.getByTestId('flashes')).toContainText(`Secret "${name}" deleted.`);
}

function rowOf(page: Page, name: string): Locator {
  return page.getByTestId('secret-row').filter({ has: page.getByTestId('secret-name').getByText(name, { exact: true }) });
}

/** Presses Reveal on a row and returns the dialog showing the value (a plain form POST: the page is reloaded). */
async function reveal(page: Page, name: string): Promise<Locator> {
  await rowOf(page, name).getByTestId('secret-reveal').click();
  const dialog = page.getByTestId('reveal-dialog');
  await expect(dialog).toBeVisible({ timeout: 30_000 });
  return dialog;
}

test.describe('secrets', () => {
  test('fleet-wide: add, replace, reveal once, audit, delete', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueSecret();
    const first = uniqueValue();
    const second = uniqueValue();

    try {
      await page.goto('/admin/secrets');
      await expect(page.getByRole('heading', { level: 1, name: 'Secrets' })).toBeVisible();

      // Add: the list shows the name and the placeholder, never the value.
      await saveSecret(page, name, first);
      const row = rowOf(page, name);
      await expect(row).toBeVisible();
      await expect(row).toContainText(`[[${name.toLowerCase().replaceAll('_', '-')}]]`);
      await expect(row).toContainText('set by');
      expect(await page.content()).not.toContain(first);
      await expect(page.getByLabel('Value')).toHaveValue('');

      // Replace: the link fills the name in, the value box stays empty.
      await row.getByTestId('secret-replace').click();
      await expect(page.getByTestId('secret-form').getByLabel('Name')).toHaveValue(name);
      await expect(page.getByTestId('secret-form').getByLabel('Value')).toHaveValue('');
      await saveSecret(page, name, second);
      await expect(page.getByTestId('secret-row').filter({ hasText: name })).toHaveCount(1);
      expect(await page.content()).not.toContain(second);

      // Reveal: the dialog shows the latest value once; a plain visit afterwards shows nothing.
      const dialog = await reveal(page, name);
      await expect(dialog.getByTestId('revealed-value')).toHaveText(second);
      await expect(dialog).toContainText('only time');
      await dialog.getByRole('button', { name: 'Close' }).first().click();
      await expect(dialog).toBeHidden();
      await page.goto('/admin/secrets');
      expect(await page.content()).not.toContain(second);
      await expect(page.getByTestId('reveal-dialog')).toHaveCount(0);

      // The audit log has the three entries and no value.
      await page.goto('/admin/audit');
      await expect(page.getByRole('heading', { level: 1, name: 'Audit log' })).toBeVisible();
      const subject = page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` });
      await expect(subject).toHaveCount(3);
      await expect(subject.filter({ hasText: 'secret.reveal' })).toHaveCount(1);
      await expect(subject.filter({ hasText: 'secret.put' })).toHaveCount(2);
      expect(await page.content()).not.toContain(second);
      expect(await page.content()).not.toContain(first);

      // The filter narrows the list.
      await page.getByLabel('Action').selectOption('secret.reveal');
      await page.getByRole('button', { name: 'Filter' }).click();
      await expect(page).toHaveURL(/action=secret\.reveal/);
      await expect(page.getByTestId('audit-row').filter({ hasText: 'secret.put' })).toHaveCount(0);
      await expect(page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` })).toHaveCount(1);
    } finally {
      await deleteSecret(page, '/admin/secrets', name);
    }

    await page.goto('/admin/secrets');
    await expect(rowOf(page, name)).toHaveCount(0);
    expect(errors).toEqual([]);
  });

  test('refused input is explained and the value is not sent back', async ({ page }) => {
    const value = uniqueValue();
    await page.goto('/admin/secrets');

    const form = page.getByTestId('secret-form');
    await form.getByLabel('Name').fill('lower_case');
    await form.getByLabel('Value').fill(`"${value}"`);
    await form.getByTestId('secret-save').click();

    await expect(page.getByTestId('error-name')).toContainText('capital letters');
    await expect(page.getByTestId('error-value')).toContainText('without surrounding quotes');
    await expect(form.getByLabel('Name')).toHaveValue('lower_case');
    await expect(form.getByLabel('Value')).toHaveValue('');
    expect(await page.content()).not.toContain(value);
    await expect(page.getByTestId('secret-row')).toHaveCount(0);
  });

  test('project: the Secrets tab keeps its own secrets and reveals them', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    const name = uniqueSecret('PROJ');
    const value = uniqueValue();

    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Secrets');
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/secrets$`));
      await expect(page.getByRole('heading', { level: 1, name: project })).toBeVisible();
      await expect(page.getByTestId('secrets-empty')).toBeVisible();
      await expect(page.getByTestId('secrets-no-assets')).toContainText('No asset files yet');

      await saveSecret(page, name, value);
      await expect(rowOf(page, name)).toBeVisible();
      await expect(page.getByTestId('secret-no-placeholder')).toHaveCount(0);
      expect(await page.content()).not.toContain(value);

      const dialog = await reveal(page, name);
      await expect(dialog.getByTestId('revealed-value')).toHaveText(value);
      await dialog.getByRole('button', { name: 'Close' }).first().click();

      // The fleet-wide page does not list a project secret.
      await page.goto('/admin/secrets');
      await expect(rowOf(page, name)).toHaveCount(0);

      await deleteSecret(page, `/admin/projects/${project}/secrets`, name);
      await expect(page.getByTestId('secrets-empty')).toBeVisible();
    } finally {
      await deleteProjectViaUi(page, project);
    }
    expect(errors).toEqual([]);
  });

  test('a member cannot open any secrets page', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'member', baseURL!);
    for (const path of ['/admin/secrets', '/admin/audit', '/admin/projects/anything/secrets']) {
      const response = await page.goto(path);
      expect(response?.status(), path).toBe(403);
    }
  });

  test('pages have no serious accessibility violations', async ({ page }) => {
    test.setTimeout(120_000);
    const name = uniqueSecret('AXE');
    try {
      await page.goto('/admin/secrets');
      await saveSecret(page, name, uniqueValue());
      expect(await blockingViolations(page)).toEqual([]);
      await page.goto('/admin/audit');
      await expect(page.getByRole('heading', { level: 1, name: 'Audit log' })).toBeVisible();
      expect(await blockingViolations(page)).toEqual([]);
    } finally {
      await deleteSecret(page, '/admin/secrets', name);
    }
  });
});

test.describe('phone layout of the secrets pages (390 px)', () => {
  test.skip(() => !isPhone(), 'phone only');
  test.describe.configure({ timeout: 180_000 });

  test('the longest name fits: no overflow, buttons are 44 px targets, the dialog stays on screen', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const name = `PW_${randomBytes(4).toString('hex').toUpperCase()}_`.padEnd(64, 'X');
    expect(name).toHaveLength(64);
    const value = `${uniqueValue()}-${'v'.repeat(300)}`;

    try {
      await page.goto('/admin/secrets');
      await expectNoOverflow(page, 'the empty secrets page');
      await expectTall(page.getByTestId('secret-save'));
      await expectTall(page.getByLabel('Name'));

      await saveSecret(page, name, value);
      const row = rowOf(page, name);
      await expectTall(row.getByTestId('secret-reveal'));
      await expectTall(row.getByTestId('secret-replace'));
      await expectTall(row.getByTestId('secret-delete'));
      await expectNoOverflow(page, 'the secrets page with a 64-character name');

      const dialog = await reveal(page, name);
      await expect(dialog.getByTestId('revealed-value')).toHaveText(value);
      const box = (await dialog.boundingBox())!;
      expect(box.x).toBeGreaterThanOrEqual(-0.5);
      expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize()!.width + 0.5);
      await expectNoOverflow(page, 'the reveal dialog');
      await dialog.getByRole('button', { name: 'Close' }).first().click();

      await page.goto('/admin/audit');
      await expectNoOverflow(page, 'the audit log');
    } finally {
      await deleteSecret(page, '/admin/secrets', name);
    }
    expect(errors).toEqual([]);
  });
});
