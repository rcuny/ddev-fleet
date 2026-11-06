import { expect, type Locator, type Page } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, navigate, openDialog, openSection, submitAndWait, uniqueName } from './support/projects';
import { openReauthWindow, placeholderOf, removeSecret, revealSecret, saveSecret, secretRow, test, uniqueSecret, uniqueValue } from './support/secrets';
import { collectBrowserErrors, hasHorizontalOverflow, unexpectedErrors } from './support/shell';

// Secrets, what needs a browser: the 422 answer with its a11y wiring, the normalised value in the reveal dialog, the delete dialog
// and its cancel paths, the reveal dialog and the throttle on screen, the "no placeholder" warning (the asset file is uploaded
// through the Assets tab), the host secrets list and the 390 px layout. The messages, the 64 KiB boundary, the accepted
// values, the Replace link, stale pages, the same name in the fleet and a project and the per-user throttle are PHPUnit
// Functional tests (app/tests/Functional/Admin/SecretPagesTest.php, FLE-161). `test` signs in as an admin of its own (see
// support/secrets.ts), so the throttle and the audit actor belong to one test. Each test removes what it made.

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

async function expectTall(target: Locator, min = 44): Promise<void> {
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box, 'element has a box').not.toBeNull();
  expect(box!.height, `height of ${await target.evaluate((node) => node.outerHTML.slice(0, 80))}`).toBeGreaterThanOrEqual(min - 0.5);
}

/** Closes the reveal dialog; the rest of the page is inert while it is open. */
async function closeReveal(page: Page): Promise<void> {
  const dialog = page.getByTestId('reveal-dialog');
  await dialog.getByRole('button', { name: 'Close' }).first().click();
  await expect(dialog).toBeHidden();
}

/**
 * Saves through the form and waits for the Turbo render of the answer; returns the HTTP status of the POST (303 saved,
 * 422 refused). The name is set as a property: `fill` types it, and the input's maxlength would cut a too long one.
 */
async function saveAndWait(page: Page, name: string, value: string): Promise<number> {
  const form = page.getByTestId('secret-form');
  await form.getByLabel('Name').evaluate((input, text) => ((input as HTMLInputElement).value = text), name);
  await form.getByLabel('Value').fill(value);
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/secrets')),
    // requestSubmit, not a click: on a CPU-starved box the page can shift between Playwright's hit-target check and the
    // click, and a click that lands beside the button starts no visit (FLE-143: "Turbo events seen: (none)").
    navigate(page, () => form.evaluate((node) => (node as HTMLFormElement).requestSubmit(node.querySelector('[data-testid="secret-save"]') as HTMLElement))),
  ]);
  return response.status();
}

test.describe('secrets: the form', () => {
  test('an empty name and an empty value: one message each, answered 422, nothing is stored @phone', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    await page.goto('/admin/secrets');
    const form = page.getByTestId('secret-form');

    expect(await saveAndWait(page, '', '')).toBe(422);
    await expect(page.getByTestId('error-name')).toHaveText('Enter a name.');
    await expect(page.getByTestId('error-value')).toHaveText('Enter a value.');
    await expect(form.getByLabel('Name')).toHaveAttribute('aria-invalid', 'true');
    await expect(form.getByLabel('Value')).toHaveAttribute('aria-invalid', 'true');
    await expect(form.getByLabel('Name')).toHaveAttribute('aria-describedby', 'secret-name-error');
    await expect(form.getByLabel('Value')).toHaveAttribute('aria-describedby', 'secret-value-error');
    await expectNoOverflow(page, 'the form with two errors');
    expect(unexpectedErrors(errors)).toEqual([]);
  });





});

test.describe('secrets: values', () => {
  test('a value is stored as typed except for line endings and the newlines at its end @phone', async ({ page }) => {
    const name = uniqueSecret('NORM');
    const body = `  first ${uniqueValue()}\nsecond é ü 日本\n\tthird`;
    await page.goto('/admin/secrets');

    try {
      // The browser submits a textarea with CRLF; the trailing blank lines are dropped, the indentation is kept.
      await saveSecret(page, name, `${body}\n\n\n`);
      const dialog = await revealSecret(page, name);
      await expect(dialog.getByTestId('revealed-value')).toHaveText(body);
      expect(await dialog.getByTestId('revealed-value').evaluate((node) => node.textContent)).not.toContain('\r');
      await expectNoOverflow(page, 'the reveal dialog with a multi-line value');
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });


});

test.describe('secrets: delete', () => {
  test('the dialog names the secret, Cancel and Escape keep it, Delete removes it and the audit log says so', async ({ page, actor }) => {
    test.setTimeout(120_000);
    const name = uniqueSecret('DEL');
    await page.goto('/admin/secrets');

    try {
      await saveSecret(page, name, uniqueValue());
      const row = secretRow(page, name);

      let dialog = await openDialog(page, row.getByTestId('secret-delete'));
      await expect(dialog).toContainText(`Delete ${name}?`);
      await expect(dialog).toContainText('Instances deployed later no longer receive this value. It cannot be recovered.');
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(dialog).toBeHidden();
      await expect(row).toBeVisible();

      dialog = await openDialog(page, row.getByTestId('secret-delete'));
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(row).toBeVisible();

      dialog = await openDialog(page, row.getByTestId('secret-delete'));
      await submitAndWait(page, dialog.getByTestId('secret-delete-confirm'));
      await expect(page.getByTestId('flashes')).toContainText(`Secret "${name}" deleted.`);
      await expect(secretRow(page, name)).toHaveCount(0);

      await page.goto('/admin/audit?action=secret.delete');
      const entry = page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` });
      await expect(entry).toHaveCount(1);
      await expect(entry).toContainText(actor);
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });


});

test.describe('secrets: reveal', () => {
  test('the dialog shows the value once, fits the screen; the response is not cached @phone', async ({ page, actor }) => {
    test.setTimeout(120_000);
    const name = uniqueSecret('SHOW');
    const value = `${uniqueValue()}-${'long-'.repeat(60)}`;
    await page.goto('/admin/secrets');

    try {
      await saveSecret(page, name, value);
      await openReauthWindow(page);
      const [response] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(`/secrets/${name}/reveal`)),
        secretRow(page, name).getByTestId('secret-reveal').click(),
      ]);
      expect(response.status()).toBe(200);
      expect(response.headers()['cache-control']).toMatch(/no-store/);
      const dialog = page.getByTestId('reveal-dialog');
      await expect(dialog).toBeVisible();
      await expect(dialog).toContainText(name);
      await expect(dialog).toContainText('This is the only time the value is shown. The reveal was recorded in the audit log.');
      await expect(dialog.getByTestId('revealed-value')).toHaveText(value);
      const box = (await dialog.boundingBox())!;
      expect(box.x).toBeGreaterThanOrEqual(-0.5);
      expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize()!.width + 0.5);
      await expectNoOverflow(page, 'the reveal dialog');
      await closeReveal(page);

      // Reload (GET): the value is gone and the audit log holds the reveal by this user.
      await page.goto('/admin/secrets');
      await expect(page.getByTestId('reveal-dialog')).toHaveCount(0);
      expect(await page.content()).not.toContain(value);
      await page.goto('/admin/audit?action=secret.reveal');
      await expect(page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` })).toContainText(actor);
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });

  test('the 11th reveal within a minute is refused: 429, Retry-After, the alert, an audit entry and no value @phone', async ({ page, actor }) => {
    test.setTimeout(240_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueSecret('THR');
    const value = uniqueValue();
    await page.goto('/admin/secrets');

    try {
      await saveSecret(page, name, value);
      for (let n = 1; n <= 10; n++) {
        const dialog = await revealSecret(page, name);
        await expect(dialog.getByTestId('revealed-value'), `reveal ${n}`).toHaveText(value);
        await closeReveal(page);
      }
      await expect(page.getByTestId('reveal-throttled')).toHaveCount(0);

      const [response] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(`/secrets/${name}/reveal`)),
        secretRow(page, name).getByTestId('secret-reveal').click(),
      ]);
      expect(response.status()).toBe(429);
      expect(Number(response.headers()['retry-after'])).toBeGreaterThanOrEqual(1);
      expect(Number(response.headers()['retry-after'])).toBeLessThanOrEqual(60);
      const alert = page.getByTestId('reveal-throttled');
      await expect(alert).toBeVisible();
      await expect(alert).toContainText(/Too many reveals\. Try again in \d+ seconds?\./);
      await expect(page.getByTestId('reveal-dialog')).toHaveCount(0);
      expect(await page.content()).not.toContain(value);
      // The page is still usable while the throttle holds: the list and the form are there.
      await expect(secretRow(page, name)).toBeVisible();
      await expect(page.getByTestId('secret-form')).toBeVisible();
      await expectNoOverflow(page, 'the throttled page');

      // Saving is not throttled, only reveals are.
      await saveSecret(page, name, uniqueValue());

      await page.goto('/admin/audit?action=secret.reveal-refused');
      const refused = page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` });
      await expect(refused).toHaveCount(1);
      await expect(refused).toContainText(actor);
      await page.goto('/admin/audit?action=secret.reveal');
      await expect(page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` })).toHaveCount(10);
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
    expect(unexpectedErrors(errors)).toEqual([]);
  });

});

test.describe('secrets: in a project', () => {
  test('"No placeholder" appears only for a secret no asset file mentions, and the asset notice follows the files @phone', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName();
    const used = uniqueSecret('USED');
    const unused = uniqueSecret('UNUSED');

    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Secrets');
      await expect(page.getByTestId('secrets-no-assets')).toContainText('No asset files yet, so placeholders cannot be checked.');
      // Without files nothing can be said about a secret: no warning.
      await saveSecret(page, used, uniqueValue());
      await expect(page.getByTestId('secret-no-placeholder')).toHaveCount(0);

      await openSection(page, 'Assets');
      await page.getByTestId('asset-input').setInputFiles({ name: 'settings.env', mimeType: 'text/plain', buffer: Buffer.from(`TOKEN="${placeholderOf(used)}"\n`) });
      await expect(page.getByTestId('asset-upload-item')).toHaveAttribute('data-state', 'done', { timeout: 60_000 });

      await openSection(page, 'Secrets');
      await expect(page.getByTestId('secrets-no-assets')).toHaveCount(0);
      await saveSecret(page, unused, uniqueValue());

      await expect(secretRow(page, used).getByTestId('secret-no-placeholder')).toHaveCount(0);
      await expect(secretRow(page, used).getByTestId('secret-warning')).toHaveCount(0);
      const warned = secretRow(page, unused);
      await expect(warned.getByTestId('secret-no-placeholder')).toHaveText('No placeholder');
      await expect(warned.getByTestId('secret-warning')).toContainText(`No file in this project's assets mentions ${placeholderOf(unused)}, so instances never receive this value.`);
      await expect(page.getByTestId('secret-no-placeholder')).toHaveCount(1);
      await expectNoOverflow(page, 'the project secrets tab with a warning');

      // Replacing a value does not change the verdict; deleting the unused secret removes the warning.
      await saveSecret(page, unused, uniqueValue());
      await expect(page.getByTestId('secret-no-placeholder')).toHaveCount(1);
      await removeSecret(page, `/admin/projects/${project}/keys`, unused);
      await expect(page.getByTestId('secret-no-placeholder')).toHaveCount(0);
    } finally {
      await deleteProjectViaUi(page, project);
    }
    expect(unexpectedErrors(errors)).toEqual([]);
  });

});

test.describe('secrets: host secrets', () => {
  test('a stored Claude token appears in the read-only Host secrets list, without a value or any button @phone', async ({ page }) => {
    const token = `sk-ant-oat01-e2e-host-secrets-${uniqueValue()}`;
    await page.goto('/admin/host?tab=claude');
    await page.getByLabel(/Claude OAuth token/).fill(token);
    await submitAndWait(page, page.getByRole('button', { name: /Store token|Replace token/ }));
    await expect(page.getByTestId('flashes')).toContainText('Claude token stored.');

    await page.goto('/admin/secrets');
    const section = page.getByTestId('host-secrets');
    await expect(section).toBeVisible();
    await expect(section).toContainText('Set by the setup wizard');
    const row = section.getByTestId('host-secret-row').filter({ hasText: 'claude_oauth_token' });
    await expect(row).toHaveCount(1);
    await expect(row).toContainText(/set by .+ on \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/);
    await expect(section.getByRole('button')).toHaveCount(0);
    await expect(section.getByRole('link')).toHaveCount(0);
    expect(await page.content()).not.toContain(token);
    // The managed list never mixes with the host's own.
    await expect(page.getByTestId('secret-row').filter({ hasText: 'claude_oauth_token' })).toHaveCount(0);
    await expectNoOverflow(page, 'the host secrets list');
  });
});

test.describe('secrets: phone @phone', () => {
  test.skip(() => test.info().project.name !== 'phone', 'phone only');

  test('a 64-character name, a long invalid name echoed back and the warning fit 390 px with 44 px targets', async ({ page }) => {
    test.setTimeout(180_000);
    const project = uniqueName();
    const name = `PW_${uniqueValue().slice(-8).toUpperCase().replace(/[^A-Z0-9]/g, 'X')}_`.padEnd(64, 'Y');
    expect(name).toHaveLength(64);

    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Secrets');
      await expectTall(page.getByTestId('secret-save'));
      await expectTall(page.getByLabel('Name'));
      await expectTall(page.getByLabel('Value'), 80);

      expect(await saveAndWait(page, 'x'.repeat(200), uniqueValue())).toBe(422);
      await expectNoOverflow(page, 'a long invalid name echoed in the form');

      await openSection(page, 'Assets');
      await page.getByTestId('asset-input').setInputFiles({ name: 'a.env', mimeType: 'text/plain', buffer: Buffer.from('nothing here\n') });
      await expect(page.getByTestId('asset-upload-item')).toHaveAttribute('data-state', 'done', { timeout: 60_000 });
      await openSection(page, 'Secrets');
      await saveSecret(page, name, uniqueValue());
      const row = secretRow(page, name);
      await expect(row.getByTestId('secret-warning')).toBeVisible();
      for (const id of ['secret-reveal', 'secret-replace', 'secret-delete']) {
        await expectTall(row.getByTestId(id));
      }
      await expectNoOverflow(page, 'the project secrets tab with a 64-character name and a warning');
      const dialog = await openDialog(page, row.getByTestId('secret-delete'));
      const box = (await dialog.boundingBox())!;
      expect(box.x).toBeGreaterThanOrEqual(-0.5);
      expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize()!.width + 0.5);
      await expectTall(dialog.getByTestId('secret-delete-confirm'));
      await dialog.getByRole('button', { name: 'Cancel' }).click();
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});
