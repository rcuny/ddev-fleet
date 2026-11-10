import { expect } from '@playwright/test';
import { rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { expectNoBlockingViolations } from './support/axe';
import { openHostTab, test } from './support/host';
import { fleetHome } from './support/rebootFlag';
import { confirmPassword } from './support/secrets';
import { collectBrowserErrors, hasHorizontalOverflow, unexpectedErrors } from './support/shell';

// The Hardening tab (FLE-260): the optional role's settings, the password before a change, the job with its log and the
// firewall dead-man's switch, which the page reports but never confirms. The dev app reads the root helper's target
// list, the playbook and the host answers from FLEET_HOME (config/services.yaml); the command runner is a recording
// one, so no real `sudo` runs. Server-side rules (lock-out, bad addresses, the audit trail) are in
// app/tests/Functional/Host/HostHardeningTabTest.php.

const files = ['test-apply-helper', 'test-site.yml', 'test-host-vars.yml', 'test-hardening.conf', 'test-firewall-confirmed'].map((n) => join(fleetHome, n));

function reset(): void {
  for (const file of files) rmSync(file, { force: true });
}

// Only `provision` is listed, so the CPU slice sync (which looks for `slices`) keeps skipping.
function enableProvision(): void {
  writeFileSync(join(fleetHome, 'test-apply-helper'), "ALLOWED_TARGETS='provision'\n");
  writeFileSync(join(fleetHome, 'test-site.yml'), '---\n');
}

test.describe('hardening tab', () => {
  // The files are shared by the desktop and phone runs: they are only touched while holding the host lock.

  test('says so when the host cannot apply optional roles, and the save button is off @phone', async ({ page, hostLock }) => {
    void hostLock;
    reset();
    const errors = collectBrowserErrors(page);
    const tab = await openHostTab(page, 'hardening');

    await expect(page.getByTestId('hardening-unavailable')).toBeVisible();
    await expect(tab.getByRole('button', { name: 'Save and apply' })).toBeDisabled();
    expect(await hasHorizontalOverflow(page)).toBe(false);
    await expectNoBlockingViolations(page, 'hardening tab, unavailable');
    expect(unexpectedErrors(errors)).toEqual([]);
  });

  test('a change asks for the password, then runs as a job; an unconfirmed firewall is reported, never confirmed @phone', async ({ page, hostLock }) => {
    void hostLock;
    reset();
    const errors = collectBrowserErrors(page);
    try {
      enableProvision();
      const tab = await openHostTab(page, 'hardening');
      await expect(page.getByTestId('hardening-unavailable')).toHaveCount(0);
      await expect(page.getByTestId('hardening-firewall-state')).toHaveAttribute('data-state', 'off');
      expect(await hasHorizontalOverflow(page)).toBe(false);
      await expectNoBlockingViolations(page, 'hardening tab');

      const fill = async (): Promise<void> => {
        // The label overlays its checkbox (the design system's check pattern): click the label.
        await tab.getByText('Harden this host', { exact: true }).click();
        await tab.getByLabel('SSH accounts allowed to log in').fill('admin\ndeploy');
        await tab.getByLabel('The SSH account you log in with').fill('admin');
        await tab.getByLabel('Trusted addresses').fill('203.0.113.10');
        await tab.getByLabel('Firewall grace period (minutes)').fill('15');
      };

      // Account missing from the allow list: refused here, before any password is asked.
      await fill();
      await tab.getByLabel('The SSH account you log in with').fill('someone');
      await tab.getByRole('button', { name: 'Save and apply' }).click();
      await expect(page.getByText('must be in the allow list')).toBeVisible();
      await expectNoBlockingViolations(page, 'hardening tab with an error');

      // Valid: the password page first, then back to the host settings.
      await fill();
      await tab.getByRole('button', { name: 'Save and apply' }).click();
      await expect(page).toHaveURL(/\/admin\/reauth\?next=\/admin\/host$/);
      await confirmPassword(page);
      await expect(page).toHaveURL(/\/admin\/host$/);

      const again = await openHostTab(page, 'hardening');
      await fill();
      await again.getByRole('button', { name: 'Save and apply' }).click();
      await expect(page).toHaveURL(/\/jobs\/\d+$/);
      await expect(page.getByTestId('job-log')).toBeVisible();

      // The firewall is on and unconfirmed: the tab says how to confirm and has no way to do it.
      writeFileSync(join(fleetHome, 'test-hardening.conf'), 'ENABLED=1\n');
      const armed = await openHostTab(page, 'hardening');
      await expect(page.getByTestId('hardening-firewall-state')).toHaveAttribute('data-state', /armed|expired/);
      await expect(armed.getByRole('button', { name: /confirm/i })).toHaveCount(0);

      writeFileSync(join(fleetHome, 'test-firewall-confirmed'), '');
      await openHostTab(page, 'hardening');
      await expect(page.getByTestId('hardening-firewall-state')).toHaveAttribute('data-state', 'confirmed');
    } finally {
      reset();
    }
    expect(unexpectedErrors(errors)).toEqual([]);
  });
});
