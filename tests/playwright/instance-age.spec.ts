import { expect, test } from '@playwright/test';
import { deployOne, rowOf } from './support/instances';
import { collectBrowserErrors, unexpectedErrors } from './support/shell';
import { setStandInRole } from './support/standIn';

// FLE-367: the instance list shows when an instance was created and last deployed.

test.describe('instance age', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the list shows the created and deployed times', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const id = await deployOne(page);
    await page.goto('/instances');

    const row = rowOf(page, id);
    await expect(row.getByTestId('instance-created')).toContainText(/Created \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC \(\d+ d\)/);
    await expect(row.getByTestId('instance-deployed')).toContainText(/Deployed \d{4}-\d{2}-\d{2}|Not deployed yet/);
    expect(unexpectedErrors(errors)).toEqual([]);
  });
});
