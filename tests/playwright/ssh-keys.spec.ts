import { expect, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { expectNoBlockingViolations } from './support/axe';
import { confirmPassword, test } from './support/secrets';
import { collectBrowserErrors, hasHorizontalOverflow, unexpectedErrors } from './support/shell';

// The SSH keys view of the host settings (FLE-266). Rows, validation, re-auth gating and the warning are covered by
// app/tests/Functional/Host/SshKeysPageTest.php; this spec keeps what needs a browser: the page works at both widths, a
// key can be generated through the re-auth detour, its public half is shown with a copy button and it can be deleted.

const uniqueKey = (): string => `pw-${randomBytes(4).toString('hex')}`;

async function openKeys(page: Page): Promise<void> {
  await page.goto('/admin/host/ssh-keys');
  await page.waitForLoadState('networkidle');
  await expect(page.getByTestId('host-tab-ssh')).toBeVisible();
}

test.describe('SSH keys view', () => {
  test('the view has no overflow, no axe violations and the add forms @phone', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    await openKeys(page);

    await expect(page.locator('h1')).toHaveText('Host settings');
    await expect(page.locator('[data-testid=host-tabs]:visible, [data-testid=host-tab-select]:visible')).toBeVisible();
    await expect(page.getByTestId('ssh-key-card').first()).toBeVisible();
    await expect(page.getByTestId('ssh-key-create-form')).toBeVisible();
    await expect(page.getByTestId('ssh-key-import-form')).toBeVisible();
    expect(await hasHorizontalOverflow(page)).toBe(false);
    await expectNoBlockingViolations(page, 'SSH keys view');
    expect(unexpectedErrors(errors)).toEqual([]);
  });

  test('a generated key shows its public half with a copy button and can be deleted @phone', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const name = uniqueKey();
    await openKeys(page);

    // The first attempt sends the admin to the password page and back to this view.
    await page.getByTestId('ssh-key-create-form').getByLabel('Name').fill(name);
    await page.getByTestId('ssh-key-create').click();
    await expect(page).toHaveURL(/\/admin\/reauth\?next=\/admin\/host\/ssh-keys$/);
    await confirmPassword(page);
    await expect(page).toHaveURL(/\/admin\/host\/ssh-keys$/);

    await page.getByTestId('ssh-key-create-form').getByLabel('Name').fill(name);
    await page.getByTestId('ssh-key-create').click();
    await expect(page.getByTestId('flashes')).toContainText(`SSH key "${name}" created`);

    const card = page.getByTestId('ssh-key-card').and(page.locator(`[data-key="${name}"]`));
    await expect(card).toHaveCount(1);
    await expect(card.getByTestId('ssh-key-fingerprint')).toContainText('SHA256:');
    await expect(card.getByTestId('ssh-key-public')).toHaveValue(/^ssh-ed25519 /);
    await expect(card.getByTestId('ssh-key-copy')).toBeVisible();
    await expect(card.getByTestId('ssh-key-used-by')).toContainText('nothing yet');
    expect(await page.content()).not.toContain('PRIVATE KEY');
    expect(await hasHorizontalOverflow(page)).toBe(false);

    // The window closed after the create: deleting asks for the password again.
    await card.getByTestId('ssh-key-delete').click();
    await expect(page).toHaveURL(/\/admin\/reauth/);
    await confirmPassword(page);
    await card.getByTestId('ssh-key-delete').click();
    await expect(page.getByTestId('flashes')).toContainText(`SSH key "${name}" deleted`);
    await expect(card).toHaveCount(0);
    expect(unexpectedErrors(errors)).toEqual([]);
  });
});
