import { expect, type Page } from '@playwright/test';
import { blockingViolations } from './support/axe';
import { test } from './support/host';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

const TABS = [
  { key: 'general', label: 'General' },
  { key: 'git', label: 'Git identity' },
  { key: 'claude', label: 'Claude token' },
  { key: 'notifications', label: 'Notifications' },
  { key: 'network', label: 'Networking' },
  { key: 'uploads', label: 'Uploads' },
  { key: 'backups', label: 'Backups' },
] as const;

// Not a real credential; unique so a leak into the page is unmistakable.
const TOKEN = 'sk-ant-oat01-e2e-host-settings-0123456789-abcdef';
const SMTP_PASSWORD = 'e2e smtp password 7Hk-unique';

const isPhone = (testInfo: { project: { name: string } }) => testInfo.project.name === 'phone';

async function openTab(page: Page, key: string): Promise<void> {
  await page.goto(`/admin/host?tab=${key}`);
  await page.waitForLoadState('networkidle');
  await expect(page.getByTestId(`host-tab-${key}`)).toBeVisible();
}

test.describe('host settings', () => {
  test('the page has one h1, the Settings entry in the menu and no browser errors', async ({ page }, testInfo) => {
    const errors = collectBrowserErrors(page);
    await page.goto('/admin/host');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveTitle(/Host settings/);
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('h1')).toHaveText('Host settings');
    await expect(page.getByTestId('host-domain')).toContainText('fleet.example.com');
    await expect(page.getByTestId('host-tls-mode')).toContainText('http01');
    if (!isPhone(testInfo)) {
      await expect(page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Settings' })).toHaveAttribute('href', '/admin/host');
    }
    expect(errors).toEqual([]);
  });

  test('tabs: a strip on desktop, a select with a Go button on the phone', async ({ page }, testInfo) => {
    await openTab(page, 'general');

    if (isPhone(testInfo)) {
      await expect(page.getByTestId('host-tabs')).toBeHidden();
      // Scoped: the desktop strip's aria-label ("Host settings sections") also contains "Section".
      const select = page.getByTestId('host-tab-select').getByLabel('Section');
      await expect(select).toBeVisible();
      await select.selectOption('git');
      await page.getByTestId('host-tab-select').getByRole('button', { name: 'Go' }).click();
    } else {
      await expect(page.getByTestId('host-tab-select')).toBeHidden();
      await expect(page.getByTestId('host-tabs').locator('a[aria-current=page]')).toHaveText('General');
      await page.getByTestId('host-tabs').getByRole('link', { name: 'Git identity' }).click();
    }

    await expect(page).toHaveURL(/\/admin\/host\?tab=git$/);
    await expect(page.getByTestId('host-tab-git')).toBeVisible();
  });

  for (const { key, label } of TABS) {
    test(`no horizontal overflow and 44 px tap targets on the ${label} tab`, async ({ page }, testInfo) => {
      await openTab(page, key);

      expect(await hasHorizontalOverflow(page)).toBe(false);
      if (isPhone(testInfo)) {
        const controls = page.getByTestId(`host-tab-${key}`).locator('button[type=submit], input:not([type=hidden]):not([type=checkbox]), a[href]');
        for (const control of await controls.all()) {
          const box = await control.boundingBox();
          expect(box, `${label}: ${await control.evaluate((el) => el.outerHTML.slice(0, 80))}`).not.toBeNull();
          expect(box!.height).toBeGreaterThanOrEqual(43.5);
        }
      }
    });
  }

  for (const scheme of ['light', 'dark'] as const) {
    test.describe(`axe, ${scheme} theme`, () => {
      test.use({ colorScheme: scheme });

      for (const { key, label } of TABS) {
        test(`no serious or critical violations on the ${label} tab`, async ({ page }) => {
          await openTab(page, key);
          if (scheme === 'dark') {
            await expect(page.locator('html')).toHaveClass(/\bdark\b/);
          }

          expect(await blockingViolations(page)).toEqual([]);
        });
      }
    });
  }

  test('the Networking tab switches the port mirror off and on', async ({ page }, testInfo) => {
    // The setting is one row shared by both projects, which run in parallel: toggling it twice at once is a race.
    test.skip(isPhone(testInfo), 'desktop only: the switch is shared host state');
    const errors = collectBrowserErrors(page);
    await openTab(page, 'network');
    const box = page.getByLabel('Mirror the named ports inside the Docker network');
    const save = page.getByTestId('host-network-form').getByRole('button', { name: 'Save' });
    const ports = page.getByTestId('host-mirror-ports');
    // Turbo swaps the page after each redirect: wait for what only the new page shows before the next click, or the
    // click lands on the old page and the swap undoes it.
    await box.check({ force: true });
    await save.click();
    await expect(page.getByText('Port mirror setting saved.')).toBeVisible();
    await expect(box).toBeChecked();

    await page.getByText('Mirror the named ports inside the Docker network').click();
    await expect(box).not.toBeChecked();
    await save.click();
    await expect(ports).toContainText('The mirror is off');
    await expect(box).not.toBeChecked();

    await box.check({ force: true });
    await expect(box).toBeChecked();
    await save.click();
    await expect(ports).not.toContainText('The mirror is off');
    await expect(box).toBeChecked();
    expect(await hasHorizontalOverflow(page)).toBe(false);
    expect(errors).toEqual([]);
  });

  test('a Claude token is stored write-only: the page never shows it back', async ({ page }) => {
    await openTab(page, 'claude');
    await page.getByLabel(/Claude OAuth token/).fill(TOKEN);
    await page.getByRole('button', { name: /Store token|Replace token/ }).click();

    await expect(page).toHaveURL(/\/admin\/host\?tab=claude$/);
    await expect(page.getByTestId('flashes')).toContainText('Claude token stored.');
    await expect(page.getByTestId('host-claude-status')).toContainText('Set');
    await expect(page.getByTestId('host-claude-status')).toContainText(/Last rotated \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/);
    expect(await page.content()).not.toContain(TOKEN);
    await expect(page.getByLabel(/New Claude OAuth token/)).toHaveValue('');
  });

  test('the Claude tab names the rotation command and shows the workspace checks without values', async ({ page }) => {
    await openTab(page, 'claude');
    await page.getByLabel(/Claude OAuth token/).fill(TOKEN);
    await page.getByRole('button', { name: /Store token|Replace token/ }).click();
    await expect(page.getByTestId('flashes')).toContainText('Claude token stored.');

    await expect(page.getByTestId('host-claude-command')).toContainText('sudo fleet claude:token:set');
    await expect(page.getByTestId('host-claude-status')).toContainText(/Last rotated \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/);
    const checks = page.getByTestId('host-workspace-checks');
    await expect(checks).toBeVisible();
    await expect(checks.getByTestId('host-check-claude-token')).toContainText('Present');
    // The push key may or may not exist on the machine running the suite: either way, a word and no value.
    await expect(checks.getByTestId('host-check-push-key')).toContainText(/Present|Missing/);
    await expect(checks.getByTestId('host-check-git-identity')).toContainText(/Present|Missing/);
    expect(await checks.innerText()).not.toContain(TOKEN);
    expect(await hasHorizontalOverflow(page)).toBe(false);
  });

  test('the Git identity tab shows the same workspace checks', async ({ page }) => {
    await openTab(page, 'git');

    const checks = page.getByTestId('host-workspace-checks');
    await expect(checks).toBeVisible();
    for (const key of ['push-key', 'git-identity', 'claude-token']) {
      await expect(checks.getByTestId(`host-check-${key}`)).toContainText(/Present|Missing/);
    }
    expect(await hasHorizontalOverflow(page)).toBe(false);
  });

  test('a token with a space is refused and is not echoed', async ({ page }) => {
    await openTab(page, 'claude');
    await page.getByLabel(/Claude OAuth token/).fill(`two words ${TOKEN}`);
    await page.getByRole('button', { name: /Store token|Replace token/ }).click();

    await expect(page.getByTestId('host-tab-claude')).toContainText('has no spaces or control characters');
    expect(await page.content()).not.toContain(TOKEN);
  });

  test('the git identity needs both fields and keeps the saved values', async ({ page, hostLock }) => {
    void hostLock;
    await openTab(page, 'git');
    // The host settings are shared by every test (and by the desktop and phone projects): an earlier run may have
    // saved both fields, so empty the e-mail explicitly to get a name without an e-mail.
    await page.getByLabel('Name').fill('Fleet Bot');
    await page.getByLabel('E-mail').fill('');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByTestId('host-tab-git')).toContainText('Set both the name and the e-mail address');

    await page.getByLabel('E-mail').fill('bot@example.com');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByTestId('flashes')).toContainText('Git identity saved.');
    await expect(page.getByLabel('Name')).toHaveValue('Fleet Bot');
    await expect(page.getByLabel('E-mail')).toHaveValue('bot@example.com');
  });

  test('notification e-mail is saved and the SMTP password is never shown back', async ({ page, hostLock }) => {
    void hostLock;
    await openTab(page, 'notifications');
    await page.getByLabel('SMTP host').fill('smtp.example.com');
    await page.getByLabel('SMTP port').fill('587');
    await page.getByLabel(/SMTP password/).fill(SMTP_PASSWORD);
    await page.getByLabel('Sender address').fill('fleet@example.com');
    await page.getByLabel('Recipient address').fill('ops@example.com');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByTestId('flashes')).toContainText('Notification e-mail settings saved.');
    await expect(page.getByTestId('host-smtp-status')).toContainText('Set');
    await expect(page.getByLabel('SMTP host')).toHaveValue('smtp.example.com');
    expect(await page.content()).not.toContain(SMTP_PASSWORD);
  });

  test('Back up now adds a backup that can be downloaded', async ({ page }) => {
    await openTab(page, 'backups');
    const before = await page.getByTestId('backup-row').count();

    await page.getByRole('button', { name: 'Back up now' }).click();
    await expect(page.getByTestId('flashes')).toContainText(/Backup written: fleet-\d{8}-\d{6}\.sqlite/);
    // Backups made in the same second share a name, so the list can stay the same length.
    expect(await page.getByTestId('backup-row').count()).toBeGreaterThanOrEqual(Math.max(1, before));

    const link = page.getByTestId('backup-row').first().getByRole('link', { name: /Download fleet-/ });
    await expect(link).toHaveAttribute('href', /\/admin\/host\/backups\/fleet-\d{8}-\d{6}\.sqlite$/);
    const download = page.waitForEvent('download');
    await link.click();
    expect((await download).suggestedFilename()).toMatch(/^fleet-\d{8}-\d{6}\.sqlite$/);
    expect(await hasHorizontalOverflow(page)).toBe(false);
  });

  test('the retention field accepts 1 to 3650 days', async ({ page, hostLock }) => {
    void hostLock;
    await openTab(page, 'backups');
    await page.getByLabel(/Keep backups for/).fill('0');
    await page.getByRole('button', { name: 'Save' }).click();
    // The browser blocks a value below min before the server sees it; either way nothing is saved.
    await openTab(page, 'backups');
    await expect(page.getByLabel(/Keep backups for/)).not.toHaveValue('0');

    await page.getByLabel(/Keep backups for/).fill('21');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByTestId('flashes')).toContainText('Backup retention saved.');
    await expect(page.getByLabel(/Keep backups for/)).toHaveValue('21');
  });

  test('a member is refused', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'member', baseURL!);
    await page.goto('/admin/host');

    await expect(page.getByText('You do not have access to this page')).toBeVisible();
    await expect(page.getByTestId('host-tab-general')).toHaveCount(0);
  });
});
