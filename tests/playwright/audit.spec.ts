import { expect } from '@playwright/test';
import { createProjectViaUi, deleteProjectViaUi, openSection, uniqueName } from './support/projects';
import { removeSecret, saveSecret, test, uniqueSecret, uniqueValue } from './support/secrets';
import { hasHorizontalOverflow } from './support/shell';

// The audit log page, browser part: the layout at 390 px (no overflow, 44 px controls, the longest subject wraps).
// Content, filter, recorded actions and access rules are in app/tests/Functional/Admin/AuditPageTest.php (axe is in
// a11y-instances.desktop.spec.ts). Entries are found by the secret name: the log is shared by every test on the database.

test.describe('audit log', () => {
  test('no horizontal overflow, and 44 px filter controls on the phone @phone @smoke', async ({ page }) => {
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
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });

  test('the longest subject, project:<40 characters>/<64 characters>, wraps instead of widening the page @phone', async ({ page }) => {
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
