import { expect, test } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';

// Inline help for the git access model (FLE-296). The SSH keys tab and the Keys & secrets tab are asserted in
// ssh-keys.spec.ts and keys-tab.spec.ts; this spec covers the two forms: the template's "SSH identities" and the
// project form's container SSH switch. Each help names the guide.

test.describe('git access help on the forms', () => {
  test('the template form explains SSH identities and what extras only changes @phone', async ({ page }) => {
    const project = uniqueName();
    try {
      await createProjectViaUi(page, project);
      await page.goto(`/admin/projects/${project}/templates/new`);
      const field = page.locator('form').filter({ hasText: 'SSH identities' });
      await expect(field).toContainText('keeps the host');
      await expect(field).toContainText('push (write) key out of a narrow-purpose instance');
      await expect(field).toContainText('docs/guides/10-git-access-and-ssh-keys.md');
      expect(await hasHorizontalOverflow(page)).toBe(false);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the new project form explains what container SSH allows @phone', async ({ page }) => {
    await page.goto('/admin/projects/new');
    const row = page.locator('[data-slot=checkbox-row]').filter({ hasText: 'Git over SSH inside the container' });
    await expect(row).toContainText('agents in an instance push with the host');
    await expect(row).toContainText('docs/guides/10-git-access-and-ssh-keys.md');
    expect(await hasHorizontalOverflow(page)).toBe(false);
  });
});
