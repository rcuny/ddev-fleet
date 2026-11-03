import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { expectNoBlockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';

// Moving a project between hosts in a real browser, at 1280 px (desktop project) and 390 px (phone project): the Export
// download on the project page, then the Import page (upload, preview, confirm). The project is deleted after the
// export, so the same file imports it again; each test owns a uniquely named project and removes it in `finally`.

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

test('export a project, delete it, import the file, preview and confirm', async ({ page }) => {
  const name = uniqueName('pwx');
  try {
    await createProjectViaUi(page, name);

    // Export: a download named after the project, a versioned JSON document.
    await expectNoOverflow(page, 'project page');
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-download').click()]);
    expect(download.suggestedFilename()).toBe(`${name}.fleet-project.json`);
    const file = await download.path();
    const text = await readFile(file, 'utf8');
    const document = JSON.parse(text) as { format: string; version: number; project: { name: string } };
    expect(document.format).toBe('fleet-project-export');
    expect(document.version).toBe(1);
    expect(document.project.name).toBe(name);

    await deleteProjectViaUi(page, name);

    // Import: the upload page, then the preview.
    await page.goto('/admin/projects');
    await page.getByTestId('import-project').click();
    await expect(page).toHaveURL(/\/admin\/import$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Import a project' })).toBeVisible();
    await expectNoOverflow(page, 'import page');
    await expectNoBlockingViolations(page, 'import page');

    await page.getByTestId('import-file').setInputFiles({ name: `${name}.fleet-project.json`, mimeType: 'application/json', buffer: Buffer.from(text) });
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/import/preview'),
      page.getByTestId('import-preview').click(),
    ]);
    expect(response.status(), `preview answered ${response.status()}`).toBe(200);

    const rows = page.getByTestId('import-row');
    await expect(page.getByTestId('import-table')).toBeVisible();
    await expect(rows.first()).toBeVisible();
    await expect(page.getByTestId('import-row').filter({ hasText: name }).first()).toHaveAttribute('data-action', 'create');
    expect(await rows.evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-action')))).not.toContain('skipped');
    await expect(page.getByTestId('import-errors')).toHaveCount(0);
    await expectNoOverflow(page, 'import preview');
    await expectNoBlockingViolations(page, 'import preview');

    // Confirm: the project is back.
    await page.getByTestId('import-confirm').click();
    await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
    await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" imported.`);
  } finally {
    await deleteProjectViaUi(page, name);
  }
});

test('a file that is not an export is refused and nothing is imported', async ({ page }) => {
  await page.goto('/admin/import');
  await page.getByTestId('import-file').setInputFiles({ name: 'broken.json', mimeType: 'application/json', buffer: Buffer.from('{"format":"fleet-project-export","version":99}') });
  await page.getByTestId('import-preview').click();

  await expect(page.getByTestId('import-errors')).toBeVisible();
  await expect(page.getByTestId('import-error-item').first()).toContainText('version');
  await expect(page.getByTestId('import-confirm-form')).toHaveCount(0);
  await expectNoOverflow(page, 'refused import');
  await expectNoBlockingViolations(page, 'refused import');
});
