import { expect, test, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expectNoBlockingViolations } from './support/axe';
import { externalBaseURL } from './support/env';
import { createProjectViaUi, deleteProjectViaUi, openDialog, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// Schedules in a real browser, at 1280 px (desktop project) and 390 px (phone project): create, validation errors,
// pause and resume, run now, history, edit, delete; axe in light and dark. The redeploy action needs an existing
// instance and there is no browser path that creates one yet, so the test inserts one row straight into the SQLite
// database of the server under test (FLEET_HOME: the throw-away home of the webServer, or the container's for the
// served dev app). Every test owns uniquely named rows and removes them in `finally`.

const here = dirname(fileURLToPath(import.meta.url));

function fleetHome(): string {
  return externalBaseURL ? (process.env.FLEET_HOME ?? '/tmp/sqlite/fleet-home') : join(resolve(here), '.run', 'fleet-home');
}

/** Inserts (or, with remove, deletes) a Running instance of an existing project, bypassing the application. */
function instanceRow(action: 'insert' | 'delete', project: string, label: string): void {
  const script = `
    $pdo = new PDO('sqlite:' . $argv[1] . '/var/fleet.sqlite');
    $pdo->exec('PRAGMA busy_timeout=10000');
    $id = $argv[3] . '--' . $argv[4];
    if ($argv[2] === 'delete') { $pdo->prepare('DELETE FROM instance WHERE id = ?')->execute([$id]); exit(0); }
    $p = $pdo->prepare('SELECT id FROM project WHERE name = ?'); $p->execute([$argv[3]]);
    $projectId = $p->fetchColumn();
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO instance (id, label, template_name, branch, state, requested_by, created_at, updated_at, project_id) VALUES (?, ?, 'full', 'develop', 'running', 'admin', ?, ?, ?)")
        ->execute([$id, $argv[4], $now, $now, $projectId]);
  `;
  const result = spawnSync('php', ['-r', script, fleetHome(), action, project, label], { encoding: 'utf8' });
  expect(result.status, `instance ${action}: ${result.stderr}`).toBe(0);
}

async function expectTall(page: Page, testId: string): Promise<void> {
  const target = page.getByTestId(testId).first();
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box!.height, testId).toBeGreaterThanOrEqual(43.5);
}

test.describe('schedules', () => {
  test('create, pause, resume, run now, history, edit, delete', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName('blog');
    const name = uniqueName('nightly');
    const phone = test.info().project.name === 'phone';
    await createProjectViaUi(page, project);
    instanceRow('insert', project, 'main');
    try {
      await page.goto('/admin/schedules');
      await expect(page.getByRole('heading', { level: 1, name: 'Schedules' })).toBeVisible();

      // Validation errors are shown next to the fields and nothing is created.
      await page.getByTestId('schedule-new').click();
      await page.getByLabel('Name', { exact: true }).fill(name);
      await page.getByLabel('Cron expression').fill('not a cron');
      await page.getByLabel('Timezone').fill('Mars/Base');
      await page.getByLabel('Instance').fill(`${project}--nope`);
      await page.getByTestId('schedule-save').click();
      await expect(page.getByTestId('error-cron')).toBeVisible();
      await expect(page.getByTestId('error-timezone')).toBeVisible();
      await expect(page.getByTestId('error-instance')).toBeVisible();
      await expectNoBlockingViolations(page);

      // Create.
      await page.getByLabel('Cron expression').fill('0 3 * * *');
      await page.getByLabel('Timezone').fill('Europe/Paris');
      await page.getByLabel('Instance').fill(`${project}--main`);
      await page.getByTestId('schedule-save').click();
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" created.`);
      const row = page.getByTestId('schedule-row').filter({ hasText: name });
      await expect(row).toHaveCount(1);
      await expect(row.getByTestId('schedule-state')).toHaveText('Active');
      await expect(row.getByTestId('schedule-next')).toContainText('UTC');
      if (phone) {
        await expectTall(page, 'schedule-run');
      }
      expect(await hasHorizontalOverflow(page), 'horizontal overflow').toBe(false);
      await expectNoBlockingViolations(page);

      // Pause, resume.
      await row.getByTestId('schedule-pause').click();
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" paused.`);
      await expect(row.getByTestId('schedule-state')).toHaveText('Paused');
      await expect(row.getByTestId('schedule-next')).toContainText('none');
      await row.getByTestId('schedule-resume').click();
      await expect(page.getByTestId('flashes')).toContainText('resumed');
      await expect(row.getByTestId('schedule-state')).toHaveText('Active');

      // Run now, then the history shows the manual run with its job.
      await row.getByTestId('schedule-run').click();
      await expect(page.getByTestId('flashes')).toContainText(/started job #\d+/);
      await row.getByTestId('schedule-history').click();
      await expect(page.getByRole('heading', { level: 1, name: `History of ${name}` })).toBeVisible();
      const run = page.getByTestId('schedule-run-row');
      await expect(run).toHaveCount(1);
      await expect(run.getByTestId('run-trigger')).toHaveText('manual');
      await expect(run.getByTestId('run-job')).toHaveText(/^#\d+$/);
      expect(await hasHorizontalOverflow(page), 'horizontal overflow on history').toBe(false);
      await expectNoBlockingViolations(page);

      // Edit.
      await page.goto('/admin/schedules');
      await page.getByTestId('schedule-row').filter({ hasText: name }).getByTestId('schedule-edit').click();
      await page.getByLabel('Cron expression').fill('30 4 * * *');
      await page.getByTestId('schedule-save').click();
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" saved.`);
      await expect(page.getByTestId('schedule-row').filter({ hasText: name }).getByTestId('schedule-cron')).toHaveText('30 4 * * *');

      // Delete through the dialog.
      const dialog = await openDialog(page, page.getByTestId('schedule-row').filter({ hasText: name }).getByTestId('schedule-delete'));
      await dialog.getByTestId('schedule-delete-confirm').click();
      await expect(page.getByTestId('flashes')).toContainText(`Schedule "${name}" deleted.`);
      await expect(page.getByTestId('schedule-row').filter({ hasText: name })).toHaveCount(0);
      // The one expected console error is the 422 of the invalid form.
      expect(errors.filter((e) => !e.includes('status of 422'))).toEqual([]);
    } finally {
      await page.goto('/admin/schedules');
      const left = page.getByTestId('schedule-row').filter({ hasText: name });
      if ((await left.count()) > 0) {
        const dialog = await openDialog(page, left.getByTestId('schedule-delete'));
        await dialog.getByTestId('schedule-delete-confirm').click();
        await expect(page.getByTestId('flashes')).toContainText('deleted.');
      }
      // Jobs reference the instance by id only, so the row can go; the project then follows.
      instanceRow('delete', project, 'main');
      await deleteProjectViaUi(page, project);
    }
  });

  test('empty state is accessible', async ({ page }) => {
    await page.goto('/admin/schedules');
    await expect(page.getByRole('heading', { level: 1, name: 'Schedules' })).toBeVisible();
    expect(await hasHorizontalOverflow(page)).toBe(false);
    await expectNoBlockingViolations(page);
  });
});
