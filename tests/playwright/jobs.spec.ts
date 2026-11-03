import { expect, test } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { ADMIN_PROJECT, deployOne, runFakeJob } from './support/instances';
import { follow } from './support/projects';
import { setStandInRole } from './support/standIn';

// There is no Mercure hub behind the test server: the pages follow a job by polling (2 s), which is what these
// specs exercise. The fake job runs in a separate process, as a worker would.
test.describe('jobs', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the lines of a running job arrive without a reload @smoke', async ({ page }) => {
    const id = await deployOne(page, ADMIN_PROJECT);
    const running = runFakeJob(id, { lines: 12, delay: 400 });

    await expect(async () => {
      await page.goto(`/instances/${id}`);
      await expect(page.getByTestId('active-job')).toBeVisible({ timeout: 1_000 });
    }).toPass({ timeout: 30_000 });
    const log = page.getByTestId('active-job').getByTestId('job-log');
    await expect(log).toContainText('fake line 1 of 12');
    await expect(log).toContainText('fake line 12 of 12', { timeout: 30_000 });
    await running;
    await expect(page.getByTestId('active-job').getByTestId('job-log-indicator')).not.toContainText('Loading');
    await expectNoBlockingViolations(page, 'instance page with a finished job');
  });

  test('a failed job shows its error and Retry makes a new job', async ({ page }) => {
    const id = await deployOne(page, ADMIN_PROJECT);
    await runFakeJob(id, { lines: 3, delay: 10, fail: true });

    await page.goto(`/instances/${id}`);
    await follow(page, page.getByTestId('timeline-job').filter({ hasText: 'fake' }).first().getByRole('link'));
    const failedUrl = page.url();
    await expect(page.getByTestId('job-status')).toHaveAttribute('data-status', 'failed');
    await expect(page.getByTestId('job-log')).toContainText('Fake job failed on purpose.');
    await expectNoBlockingViolations(page, 'failed job page');

    await page.getByTestId('job-retry').click();
    await expect(page).not.toHaveURL(failedUrl);
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByTestId('job-log')).toContainText('fake line 3 of 3');
  });

  test('the jobs page lists both a finished and a failed job', async ({ page }) => {
    const id = await deployOne(page, ADMIN_PROJECT);
    await runFakeJob(id, { lines: 2, delay: 0 });
    await runFakeJob(id, { lines: 2, delay: 0, fail: true });

    await page.goto(`/admin/jobs?q=${id}`);
    const rows = page.getByTestId('job-row').filter({ hasText: id });
    await expect(rows.filter({ hasText: 'fake' }).getByTestId('job-status')).toHaveCount(2);
    await expect(rows.locator('[data-status="succeeded"]').first()).toBeVisible();
    await expect(rows.locator('[data-status="failed"]')).toHaveCount(1);
    await expectNoBlockingViolations(page, 'jobs page');
  });
});
