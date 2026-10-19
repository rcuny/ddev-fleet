import { expect, test, type Page } from '@playwright/test';
import { blockingViolations } from './support/axe';
import { ADMIN_PROJECT, MEMBER_PROJECT, deployOne, rowOf, submitDeploy, uniqueLabel } from './support/instances';
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
    expect(await blockingViolations(page), 'axe').toEqual([]);
    expect(errors.filter((e) => !e.startsWith('console.error: Failed to load resource'))).toEqual([]);
  });

  test('a count of two gives numbered instances, the same label again a suffix', async ({ page }) => {
    const label = uniqueLabel();
    await submitDeploy(page, { label, count: 2 });
    await expect(page.getByTestId('flashes')).toContainText('2 jobs queued.');
    await expect(rowOf(page, `${ADMIN_PROJECT}--${label}-1`)).toBeVisible();
    await expect(rowOf(page, `${ADMIN_PROJECT}--${label}-2`)).toBeVisible();

    await submitDeploy(page, { label });
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await submitDeploy(page, { label });
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await page.goto(`/instances?q=${label}`);
    await expect(rowOf(page, `${ADMIN_PROJECT}--${label}`)).toBeVisible();
    await expect(rowOf(page, `${ADMIN_PROJECT}--${label}-3`)).toBeVisible();
  });

  test('the form shows a field error and keeps what was typed', async ({ page }) => {
    await page.goto(`/instances/new?project=${ADMIN_PROJECT}`);
    const form = page.getByTestId('deploy-form');
    await form.getByLabel('Label').fill('Bad Label');
    await form.getByLabel('How many').fill('1');
    await form.getByRole('button', { name: 'Deploy' }).click();
    await expect(form.getByLabel('Label')).toHaveValue('Bad Label');
    await expect(form).toContainText(/lowercase|label/i);
  });

  test('filters narrow the list', async ({ page }) => {
    const one = uniqueLabel('fa');
    const two = uniqueLabel('fb');
    const first = await deployOne(page, ADMIN_PROJECT, one);
    const second = await deployOne(page, ADMIN_PROJECT, two);

    await page.goto('/instances');
    await page.getByLabel('Search instances').fill(one);
    await follow(page, page.getByTestId('filter-submit'));
    await expect(rowOf(page, first)).toBeVisible();
    await expect(rowOf(page, second)).toHaveCount(0);

    await page.goto(`/instances?q=${one}&state=stopped`);
    await expect(page.getByTestId('instances-empty')).toBeVisible();
    await page.goto(`/instances?q=${two}&project=${ADMIN_PROJECT}&state=running`);
    await expect(rowOf(page, second)).toBeVisible();
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

test.describe('instances: member', () => {
  test('a member redeploys but cannot destroy or deploy, and sees only their project', async ({ page, context, baseURL }) => {
    // The admin makes one instance of each project; the member (group "demo") may open only the demo one.
    await setStandInRole(context, 'admin', baseURL!);
    const own = await deployOne(page, MEMBER_PROJECT);
    const foreign = await deployOne(page, ADMIN_PROJECT);

    await setStandInRole(context, 'member', baseURL!);
    await page.goto('/instances');
    await expect(rowOf(page, own)).toBeVisible();
    await expect(rowOf(page, foreign)).toHaveCount(0);
    await expect(page.getByTestId('deploy-link')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Destroy…' })).toHaveCount(0);

    expect((await page.goto('/instances/new'))?.status()).toBe(403);
    expect((await page.goto(`/instances/${foreign}`))?.status()).toBe(404);

    await page.goto(`/instances/${own}`);
    await expect(page.getByRole('button', { name: 'Destroy…' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Redeploy' }).click();
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');
  });
});
