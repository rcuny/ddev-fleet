import { expect } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, follow, openSection, submitAndWait, uniqueName } from './support/projects';
import { removeSecret, revealSecret, saveSecret, test, uniqueSecret, uniqueValue } from './support/secrets';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// The audit log page: the table and its columns, the newest-first order, the action filter (options, selection,
// reset, an unknown value), the entries each action leaves (with the actor of this test, see support/secrets.ts), long
// subjects at 390 px, the access rules and axe in both themes. Entries are found by the secret name or the actor of
// the test: the log is shared by every test on the database and holds at most the latest 200 entries on the page.

const TIME = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;

test.describe('audit log', () => {
  test('the page, its description and the way to and from the Secrets page', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    await page.goto('/admin/secrets');
    await follow(page, page.getByRole('link', { name: 'Audit log' }));
    await expect(page).toHaveURL(/\/admin\/audit$/);
    await expect(page).toHaveTitle(/Audit log/);
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.getByRole('heading', { level: 1, name: 'Audit log' })).toBeVisible();
    await expect(page.getByText('Who changed or revealed a secret, and when. Values are never recorded.')).toBeVisible();

    await follow(page, page.getByRole('link', { name: 'Secrets', exact: true }).last());
    await expect(page).toHaveURL(/\/admin\/secrets$/);
    expect(errors).toEqual([]);
  });

  test('the entries of a secret: newest first, four columns, the actor, a UTC time and no value', async ({ page, actor }) => {
    test.setTimeout(120_000);
    const name = uniqueSecret('AUD');
    const first = uniqueValue();
    const second = uniqueValue();
    await page.goto('/admin/secrets');

    try {
      await saveSecret(page, name, first);
      await saveSecret(page, name, second);
      await revealSecret(page, name);
      await page.goto('/admin/secrets');
      await removeSecret(page, '/admin/secrets', name);

      await page.goto('/admin/audit');
      const table = page.getByRole('table', { name: 'Audit log' });
      await expect(table.getByRole('columnheader')).toHaveText(['When (UTC)', 'Who', 'Action', 'Subject']);
      await expect(page.getByText(/^The latest 200 entries, newest first\.$/)).toBeVisible();
      expect(await page.getByTestId('audit-row').count()).toBeLessThanOrEqual(200);

      const mine = page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` });
      await expect(mine).toHaveCount(4);
      // Newest first: delete, reveal, put, put.
      const actions = await mine.evaluateAll((rows) => rows.map((row) => row.children[2].textContent?.trim()));
      expect(actions).toEqual(['secret.delete', 'secret.reveal', 'secret.put', 'secret.put']);
      for (const row of await mine.all()) {
        await expect(row.getByRole('cell').nth(1)).toHaveText(actor);
        await expect(row.getByRole('cell').nth(3)).toHaveText(`fleet/${name}`);
        expect((await row.getByRole('cell').nth(0).innerText()).trim()).toMatch(TIME);
      }
      const times = await mine.evaluateAll((rows) => rows.map((row) => row.children[0].textContent?.trim() ?? ''));
      expect([...times].sort().reverse()).toEqual(times);
      const html = await page.content();
      expect(html).not.toContain(first);
      expect(html).not.toContain(second);
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });

  test('the filter lists the recorded actions, narrows the list, keeps its selection and resets', async ({ page }) => {
    test.setTimeout(120_000);
    const name = uniqueSecret('FIL');
    await page.goto('/admin/secrets');

    try {
      await saveSecret(page, name, uniqueValue());
      await revealSecret(page, name);

      await page.goto('/admin/audit');
      const select = page.getByLabel('Action');
      await expect(select).toHaveValue('');
      const options = await select.locator('option').allTextContents();
      expect(options[0]).toBe('All actions');
      expect(options).toEqual(expect.arrayContaining(['secret.put', 'secret.reveal']));
      expect(options.slice(1)).toEqual([...options.slice(1)].sort());
      expect(new Set(options).size).toBe(options.length);

      await select.selectOption('secret.put');
      await submitAndWait(page, page.getByRole('button', { name: 'Filter' }));
      await expect(page).toHaveURL(/\?action=secret\.put$/);
      await expect(page.getByLabel('Action')).toHaveValue('secret.put');
      const actions = await page.getByTestId('audit-row').evaluateAll((rows) => rows.map((row) => row.children[2].textContent?.trim()));
      expect(actions.length).toBeGreaterThan(0);
      expect(new Set(actions)).toEqual(new Set(['secret.put']));
      await expect(page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` })).toHaveCount(1);

      await page.getByLabel('Action').selectOption('secret.reveal');
      await submitAndWait(page, page.getByRole('button', { name: 'Filter' }));
      await expect(page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` })).toHaveCount(1);
      await expect(page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` })).toContainText('secret.reveal');
      await expectNoBlockingViolations(page, 'audit log filtered');

      await page.getByLabel('Action').selectOption('');
      await submitAndWait(page, page.getByRole('button', { name: 'Filter' }));
      await expect(page.getByLabel('Action')).toHaveValue('');
      await expect(page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` })).toHaveCount(2);
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });

  test('an action that is not in the log is ignored and shows every entry; a malformed one is a 400', async ({ page }) => {
    const name = uniqueSecret('IGN');
    await page.goto('/admin/secrets');

    try {
      await saveSecret(page, name, uniqueValue());
      await page.goto('/admin/audit?action=no.such.action');
      await expect(page.getByLabel('Action')).toHaveValue('');
      await expect(page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` })).toHaveCount(1);

      const malformed = await page.goto('/admin/audit?action[]=secret.put');
      expect(malformed?.status()).toBe(400);

      const hostile = '"><script>window.__pwAudit=1</script>';
      await page.goto(`/admin/audit?action=${encodeURIComponent(hostile)}`);
      expect(await page.evaluate(() => (window as unknown as { __pwAudit?: number }).__pwAudit)).toBeUndefined();
      expect(await page.content()).not.toContain('<script>window.__pwAudit');
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });

  test('a stored Claude token is recorded as claude.token.rotate with the subject and the actor, never the token', async ({ page, actor }) => {
    const token = `sk-ant-oat01-e2e-audit-${uniqueValue()}`;
    await page.goto('/admin/host?tab=claude');
    await page.getByLabel(/Claude OAuth token/).fill(token);
    await submitAndWait(page, page.getByRole('button', { name: /Store token|Replace token/ }));
    await expect(page.getByTestId('flashes')).toContainText('Claude token stored.');

    await page.goto('/admin/audit?action=claude.token.rotate');
    const latest = page.getByTestId('audit-row').first();
    await expect(latest).toContainText(actor);
    await expect(latest).toContainText('claude.token.rotate');
    await expect(latest).toContainText('fleet/claude_oauth_token');
    expect(await page.content()).not.toContain(token);
  });

  test('a notification password stored from the host page is recorded as a secret.put of the host secret', async ({ page, actor }) => {
    await page.goto('/admin/host?tab=notifications');
    await page.getByLabel(/SMTP password/).fill(`audit-smtp-${uniqueValue()}`);
    await submitAndWait(page, page.getByRole('button', { name: 'Save' }));
    await expect(page.getByTestId('flashes')).toContainText('Notification e-mail settings saved.');

    await page.goto('/admin/audit?action=secret.put');
    const row = page.getByTestId('audit-row').filter({ hasText: 'fleet/smtp_password' }).filter({ hasText: actor });
    await expect(row).toHaveCount(1);
  });

  test('a project secret and its deletion with the project are recorded under project:<name>/<SECRET>', async ({ page, actor }) => {
    test.setTimeout(120_000);
    const project = uniqueName();
    const name = uniqueSecret('PAUD');
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Secrets');
      await saveSecret(page, name, uniqueValue());
      await revealSecret(page, name);
      await page.goto(`/admin/projects/${project}`);
      await deleteProjectViaUi(page, project);

      await page.goto('/admin/audit');
      const mine = page.getByTestId('audit-row').filter({ hasText: `project:${project}/${name}` });
      await expect(mine).toHaveCount(3);
      expect(await mine.evaluateAll((rows) => rows.map((row) => row.children[2].textContent?.trim()))).toEqual(['secret.delete', 'secret.reveal', 'secret.put']);
      await expect(mine.filter({ hasText: actor })).toHaveCount(3);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('a member and a visitor without a session cannot read the log', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'member', baseURL!);
    const member = await page.goto('/admin/audit');
    expect(member?.status()).toBe(403);
    await expect(page.getByTestId('audit-row')).toHaveCount(0);

    await context.clearCookies();
    await context.addCookies([{ name: 'fleet_stand_in_role', value: 'none', url: baseURL! }]);
    const visitor = await page.goto('/admin/audit');
    expect(visitor?.status()).not.toBe(200);
    await expect(page.getByTestId('audit-row')).toHaveCount(0);
  });

  test('no horizontal overflow, 44 px filter controls and no violations in both themes', async ({ page }) => {
    test.setTimeout(120_000);
    const name = uniqueSecret('AX');
    await page.goto('/admin/secrets');

    try {
      await saveSecret(page, name, uniqueValue());
      await page.goto('/admin/audit');
      await expect(page.getByTestId('audit-row').first()).toBeVisible();
      expect(await hasHorizontalOverflow(page)).toBe(false);
      if (test.info().project.name === 'phone') {
        for (const control of [page.getByLabel('Action'), page.getByRole('button', { name: 'Filter' })]) {
          const box = (await control.boundingBox())!;
          expect(box.height).toBeGreaterThanOrEqual(43.5);
        }
      }
      await expectNoBlockingViolations(page, 'audit log');
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });

  test('the longest subject, project:<40 characters>/<64 characters>, wraps instead of widening the page', async ({ page }) => {
    test.setTimeout(180_000);
    const project = uniqueName().padEnd(40, 'z');
    const name = `PW_${uniqueValue().slice(-6).toUpperCase().replace(/[^A-Z0-9]/g, 'X')}_`.padEnd(64, 'Q');
    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Secrets');
      await saveSecret(page, name, uniqueValue());
      await page.goto('/admin/audit');
      const row = page.getByTestId('audit-row').filter({ hasText: `project:${project}/${name}` });
      await expect(row).toHaveCount(1);
      await expect(row.getByRole('cell').nth(3)).toHaveText(`project:${project}/${name}`);
      expect(await hasHorizontalOverflow(page)).toBe(false);
      const box = (await row.getByRole('cell').nth(3).boundingBox())!;
      expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize()!.width + 0.5);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});
