import { expect, test } from '@playwright/test';
import { ADMIN_PROJECT, deployOne, runFakeJob } from './support/instances';
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
  });
});
