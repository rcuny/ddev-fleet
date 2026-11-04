import { expect, test, type Page } from '@playwright/test';
import { ADMIN_PROJECT, deployOne, rowOf, uniqueLabel } from './support/instances';
import { follow, openDialog } from './support/projects';
import { collectBrowserErrors } from './support/shell';
import { setStandInRole } from './support/standIn';

async function selectRows(page: Page, ids: string[]): Promise<void> {
  for (const id of ids) {
    await page.getByLabel(`Select ${id}`).check();
  }
  await expect(page.getByTestId('bulk-bar')).toBeVisible();
}

test.describe('instances: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('deploys a project and lands on a job page with the log @smoke', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    // The test server has no Mercure hub (the pages fall back to polling): its 404 is expected, any other one is not.
    page.on('response', (r) => r.status() === 404 && !r.url().includes('/.well-known/mercure') && errors.push(`404 ${r.url()}`));
    const id = await deployOne(page);

    await expect(page.getByRole('heading', { level: 1, name: /^Job #\d+$/ })).toBeVisible();
    await expect(page.getByTestId('job-status')).toHaveAttribute('data-status', 'succeeded');
    await page.goto('/instances');
    await expect(rowOf(page, id)).toBeVisible();
    await expect(rowOf(page, id).getByTestId('instance-state')).toHaveAttribute('data-state', 'running');
    expect(errors.filter((e) => !e.startsWith('console.error: Failed to load resource'))).toEqual([]);
  });

  test('the filter form narrows the list', async ({ page }) => {
    // Every combination of the filters and the empty result are asserted over HTTP (InstanceControllerTest).
    const one = uniqueLabel('fa');
    const two = uniqueLabel('fb');
    const first = await deployOne(page, ADMIN_PROJECT, one);
    const second = await deployOne(page, ADMIN_PROJECT, two);

    await page.goto('/instances');
    await page.getByLabel('Search instances').fill(one);
    await follow(page, page.getByTestId('filter-submit'));
    await expect(rowOf(page, first)).toBeVisible();
    await expect(rowOf(page, second)).toHaveCount(0);
  });

  test('bulk stop then start', async ({ page }) => {
    const a = await deployOne(page, ADMIN_PROJECT, uniqueLabel('ba'));
    const b = await deployOne(page, ADMIN_PROJECT, uniqueLabel('bb'));

    await page.goto('/instances?q=--b');
    await selectRows(page, [a, b]);
    await page.getByTestId('bulk-bar').getByRole('button', { name: 'Stop', exact: true }).click();
    await page.goto('/instances?q=--b');
    await expect(rowOf(page, a).getByTestId('instance-state')).toHaveAttribute('data-state', 'stopped');
    await expect(rowOf(page, b).getByTestId('instance-state')).toHaveAttribute('data-state', 'stopped');

    await selectRows(page, [a, b]);
    await page.getByTestId('bulk-bar').getByRole('button', { name: 'Start', exact: true }).click();
    await page.goto('/instances?q=--b');
    await expect(rowOf(page, a).getByTestId('instance-state')).toHaveAttribute('data-state', 'running');
    await expect(rowOf(page, b).getByTestId('instance-state')).toHaveAttribute('data-state', 'running');
  });

  test('destroy asks for confirmation and removes the row', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto(`/instances/${id}`);
    const dialog = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
    await expect(dialog).toContainText(`Destroy ${id}?`);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.goto('/instances');
    await expect(rowOf(page, id)).toBeVisible();

    await page.goto(`/instances/${id}`);
    const confirm = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
    await confirm.getByRole('button', { name: 'Destroy instance' }).click();
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByTestId('job-log')).toContainText('destroy');
    await page.goto('/instances');
    await expect(rowOf(page, id)).toHaveCount(0);
  });
});
