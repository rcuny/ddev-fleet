import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { expect, type Locator, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { openHostTab, setValue, skipBrowserValidation, TAB_LABELS, test } from './support/host';
import { submitAndWait } from './support/projects';
import { acquire, clearFlag, flag, fleetHome, lock, packages } from './support/rebootFlag';
import { uniqueValue } from './support/secrets';

// Axe sweep of the host settings page (light and dark theme, see support/axe.ts): every tab in its plain state and the
// states the feature specs reach with a refused save, a stored token, a stored password and a backup list. The
// feature specs (host-settings*.spec.ts) carry no axe call. Tests that save take the host lock.

async function save(page: Page, tab: Locator, label = 'Save'): Promise<void> {
  await submitAndWait(page, tab.getByRole('button', { name: label, exact: true }));
}

test.describe('axe sweep: host settings tabs', () => {
  for (const [key, label] of Object.entries(TAB_LABELS)) {
    test(`the ${label} tab`, async ({ page }) => {
      await openHostTab(page, key);
      await expectNoBlockingViolations(page, `${label} tab`);
    });
  }
});

test.describe('axe sweep: host settings with errors and stored values', () => {
  test('General tab with an error', async ({ page, hostLock }) => {
    void hostLock;
    const tab = await openHostTab(page, 'general');
    const field = tab.getByLabel('ACME contact e-mail');
    await skipBrowserValidation(field);
    await setValue(field, 'nobody');
    await save(page, tab);
    await expectNoBlockingViolations(page, 'General tab with an error');
  });

  test('Git identity tab with an error', async ({ page, hostLock }) => {
    void hostLock;
    const tab = await openHostTab(page, 'git');
    await skipBrowserValidation(tab.getByLabel('E-mail'));
    await setValue(tab.getByLabel('Name'), 'Fleet Bot');
    await setValue(tab.getByLabel('E-mail'), 'not an address');
    await save(page, tab);
    await expectNoBlockingViolations(page, 'Git identity tab with an error');
  });

  test('Claude tab with an error', async ({ page, hostLock }) => {
    void hostLock;
    const tab = await openHostTab(page, 'claude');
    await setValue(page.getByLabel(/Claude OAuth token/), 'sk-ant-oat01-with space');
    await save(page, tab, (await tab.getByRole('button', { name: /Store token|Replace token/ }).innerText()).trim());
    await expectNoBlockingViolations(page, 'Claude tab with an error');
  });

  test('Claude tab with a stored token', async ({ page, hostLock }) => {
    void hostLock;
    test.setTimeout(120_000);
    const tab = await openHostTab(page, 'claude');
    await setValue(page.getByLabel(/Claude OAuth token/), `sk-ant-oat01-${uniqueValue()}`);
    await save(page, tab, (await tab.getByRole('button', { name: /Store token|Replace token/ }).innerText()).trim());
    await expectNoBlockingViolations(page, 'Claude tab with a stored token');
  });

  test('Notifications tab with an error', async ({ page, hostLock }) => {
    void hostLock;
    const tab = await openHostTab(page, 'notifications');
    await skipBrowserValidation(tab.getByLabel('SMTP host'));
    await setValue(tab.getByLabel('SMTP host'), 'smtp://mail.example.com');
    await save(page, tab);
    await expectNoBlockingViolations(page, 'Notifications tab with an error');
  });

  test('Notifications tab with a stored password', async ({ page, hostLock }) => {
    void hostLock;
    test.setTimeout(120_000);
    let tab = await openHostTab(page, 'notifications');
    try {
      await page.getByLabel('SMTP host').fill('smtp.example.com');
      await page.getByLabel('SMTP port').fill('2525');
      await page.getByLabel('SMTP user').fill('mailer@example.com');
      await page.getByLabel(/SMTP password/).fill(`smtp-${uniqueValue()}`);
      await page.getByLabel('Sender address').fill('fleet@example.com');
      await page.getByLabel('Recipient address').fill('ops@example.com');
      await save(page, tab);
      tab = await openHostTab(page, 'notifications');
      await expectNoBlockingViolations(page, 'Notifications tab with a stored password');
    } finally {
      // Leave the shared row as the other specs expect it: password removed, everything empty.
      tab = await openHostTab(page, 'notifications');
      if (await page.getByLabel('Remove the stored password').count()) {
        await page.getByLabel('Remove the stored password').check();
      }
      for (const label of ['SMTP host', 'SMTP port', 'SMTP user', 'Sender address', 'Recipient address']) {
        await page.getByLabel(label).fill('');
      }
      await save(page, tab);
    }
  });

  test('Uploads tab with an error', async ({ page, hostLock }) => {
    void hostLock;
    const tab = await openHostTab(page, 'uploads');
    await skipBrowserValidation(tab.getByLabel('Largest file (MiB)'));
    await setValue(tab.getByLabel('Largest file (MiB)'), '0');
    await save(page, tab);
    await expectNoBlockingViolations(page, 'Uploads tab with an error');
  });

  test('Backups tab with an error', async ({ page, hostLock }) => {
    void hostLock;
    const tab = await openHostTab(page, 'backups');
    await skipBrowserValidation(tab.getByLabel(/Keep backups for/));
    await setValue(tab.getByLabel(/Keep backups for/), '0');
    await save(page, tab);
    await expectNoBlockingViolations(page, 'Backups tab with an error');
  });

  test('Backups tab with a backup list', async ({ page }) => {
    const tab = await openHostTab(page, 'backups');
    await submitAndWait(page, tab.getByRole('button', { name: 'Back up now' }));
    await expectNoBlockingViolations(page, 'Backups tab');
  });

  test('Networking tab', async ({ page }) => {
    await openHostTab(page, 'network');
    await expectNoBlockingViolations(page, 'Networking tab');
  });
});

test.describe('axe sweep: reboot banner', () => {
  test('the reboot banner with its package list', async ({ page }) => {
    await acquire();
    try {
      clearFlag();
      mkdirSync(fleetHome, { recursive: true });
      writeFileSync(flag, '*** System restart required ***\n');
      writeFileSync(packages, 'linux-image-6.12.0-amd64\nlibc6\n');
      await page.goto('/');
      await page.waitForLoadState('networkidle');
      await expect(page.getByTestId('reboot-banner')).toBeVisible();
      await expectNoBlockingViolations(page, 'the reboot banner');
    } finally {
      clearFlag();
      rmSync(lock, { recursive: true, force: true });
    }
  });
});
