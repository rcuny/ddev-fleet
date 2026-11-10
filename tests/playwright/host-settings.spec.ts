import { expect, type Page } from '@playwright/test';
// Tab texts, refusal messages, saves, write-only secrets, the 403 page and the retention bounds are covered by
// app/tests/Functional/Host/HostSettingsMatrixTest.php and HostPageTest.php; this spec keeps what needs a browser.
import { test } from './support/host';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

const TABS = [
  { key: 'general', label: 'General' },
  { key: 'git', label: 'Git identity' },
  { key: 'claude', label: 'Claude token' },
  { key: 'notifications', label: 'Notifications' },
  { key: 'network', label: 'Networking' },
  { key: 'certificates', label: 'Certificates' },
  { key: 'uploads', label: 'Uploads' },
  { key: 'jobs', label: 'Jobs' },
  { key: 'capacity', label: 'Capacity' },
  { key: 'ssh', label: 'SSH keys' },
  { key: 'hardening', label: 'Hardening' },
  { key: 'drift', label: 'Drift' },
  { key: 'backups', label: 'Backups' },
] as const;

const isPhone = (testInfo: { project: { name: string } }) => testInfo.project.name === 'phone';

async function openTab(page: Page, key: string): Promise<void> {
  await page.goto(`/admin/host?tab=${key}`);
  await page.waitForLoadState('networkidle');
  await expect(page.getByTestId(`host-tab-${key}`)).toBeVisible();
}

test.describe('host settings', () => {
  test('the page has one h1, the Settings entry in the menu and no browser errors @phone', async ({ page }, testInfo) => {
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

  test('tabs: a strip on desktop, a select with a Go button on the phone @phone', async ({ page }, testInfo) => {
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
    test(`no horizontal overflow and 44 px tap targets on the ${label} tab @phone`, async ({ page }, testInfo) => {
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

  test('the Networking tab switches the port mirror off and on @phone', async ({ page }, testInfo) => {
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

  test('Back up now adds a backup that can be downloaded @phone', async ({ page }) => {
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
});
