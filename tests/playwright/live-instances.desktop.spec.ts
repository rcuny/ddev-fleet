import { expect, test, type Page } from '@playwright/test';
import { baseURL, externalBaseURL } from './support/env';
import { createProjectViaUi, follow, uniqueName } from './support/projects';
import { collectBrowserErrors } from './support/shell';

// Instances and jobs on a real installation: two deploys at once stream their logs, both sites answer, then stop, start,
// redeploy and destroy singly and in bulk. Signs in through the Authelia portal as the administrator.
//
// Needs FLEET_E2E_BASE_URL (https://<domain>), FLEET_LIVE_ADMIN_PASSWORD and FLEET_LIVE_GIT_URL (a public DDEV project
// repository). Without them the whole file is skipped, so the default gate never touches a server. Optional:
// FLEET_LIVE_ADMIN_USERNAME (default admin), FLEET_LIVE_BRANCH (blank = the project default).
const password = process.env.FLEET_LIVE_ADMIN_PASSWORD ?? '';
const gitUrl = process.env.FLEET_LIVE_GIT_URL ?? '';
const branch = process.env.FLEET_LIVE_BRANCH ?? '';

test.skip(!externalBaseURL || !password || !gitUrl, 'live instances spec: set FLEET_E2E_BASE_URL, FLEET_LIVE_ADMIN_PASSWORD and FLEET_LIVE_GIT_URL');
test.describe.configure({ mode: 'serial', timeout: 900_000 });

const HOST = new URL(baseURL).hostname;
const project = uniqueName('live');
const ids: string[] = [];
let errors: string[];

async function signIn(page: Page): Promise<void> {
  await page.goto('/instances');
  await expect(page).toHaveURL(new RegExp(`^https://auth\\.${HOST.replace(/\./g, '\\.')}/`));
  await page.getByRole('textbox', { name: /^Username\b/ }).fill(process.env.FLEET_LIVE_ADMIN_USERNAME ?? 'admin');
  await page.getByRole('textbox', { name: /^Password\b/ }).fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL(new RegExp(`^https://${HOST.replace(/\./g, '\\.')}/`), { timeout: 30_000 });
}

async function waitForState(page: Page, id: string, state: string): Promise<void> {
  await expect(async () => {
    await page.goto(`/instances?q=${id}`);
    await expect(page.locator(`[data-instance="${id}"]`).getByTestId('instance-state')).toHaveAttribute('data-state', state, { timeout: 2_000 });
  }).toPass({ timeout: 600_000, intervals: [3_000] });
}

test('sign in and create the project', async ({ page }) => {
  errors = collectBrowserErrors(page);
  await signIn(page);
  await createProjectViaUi(page, project, gitUrl);
});

test('two deploys submitted together stream their logs and reach running', async ({ browser }) => {
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: false });
  const [a, b] = [await context.newPage(), await context.newPage()];
  await signIn(a);
  const submit = async (page: Page, label: string): Promise<void> => {
    await page.goto(`/instances/new?project=${project}`);
    await page.getByLabel('Label').fill(label);
    if (branch) await page.getByLabel('Branch').fill(branch);
    await page.getByLabel('Deploy at the same time').check();
  };
  await Promise.all([submit(a, 'one'), submit(b, 'two')]);
  await Promise.all([a.getByRole('button', { name: 'Deploy' }).click(), b.getByRole('button', { name: 'Deploy' }).click()]);
  for (const page of [a, b]) {
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(page.getByTestId('job-log')).not.toBeEmpty({ timeout: 120_000 });
    await expect(page.getByTestId('job-status')).toHaveAttribute('data-status', 'succeeded', { timeout: 600_000 });
  }
  ids.push(`${project}--one`, `${project}--two`);
  await Promise.all(ids.map((id) => waitForState(a, id, 'running')));
  for (const id of ids) {
    const response = await a.request.get(`https://${id}.${HOST}/`, { failOnStatusCode: false });
    expect(response.status(), `${id} answers`).toBeLessThan(500);
  }
  await context.close();
});

test('stop, start, redeploy and destroy singly and in bulk', async ({ page }) => {
  await signIn(page);
  const [one, two] = ids;
  const act = async (id: string, name: string, state: string): Promise<void> => {
    await page.goto(`/instances/${id}`);
    await page.getByRole('button', { name, exact: true }).click();
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await waitForState(page, id, state);
  };
  await act(one, 'Stop', 'stopped');
  await act(one, 'Start', 'running');
  await act(one, 'Redeploy', 'running');

  await page.goto(`/instances?q=${project}`);
  for (const id of ids) await page.getByLabel(`Select ${id}`).check();
  await page.getByTestId('bulk-bar').getByRole('button', { name: 'Stop', exact: true }).click();
  await Promise.all(ids.map((id) => waitForState(page, id, 'stopped')));
  await page.goto(`/instances?q=${project}`);
  for (const id of ids) await page.getByLabel(`Select ${id}`).check();
  await page.getByTestId('bulk-bar').getByRole('button', { name: 'Start', exact: true }).click();
  await Promise.all(ids.map((id) => waitForState(page, id, 'running')));

  await page.goto(`/instances/${two}`);
  await page.getByRole('button', { name: 'Destroy…' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Destroy instance' }).click();
  await expect(page).toHaveURL(/\/jobs\/\d+$/);
  await expect(async () => {
    await page.goto(`/instances?q=${two}`);
    await expect(page.locator(`[data-instance="${two}"]`)).toHaveCount(0, { timeout: 2_000 });
  }).toPass({ timeout: 300_000, intervals: [3_000] });

  await page.goto(`/instances?q=${project}`);
  await page.getByLabel(`Select ${one}`).check();
  await page.getByTestId('bulk-bar').getByRole('button', { name: 'Destroy…' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Destroy instances' }).click();
  await expect(async () => {
    await page.goto(`/instances?q=${project}`);
    await expect(page.locator(`[data-instance="${one}"]`)).toHaveCount(0, { timeout: 2_000 });
  }).toPass({ timeout: 300_000, intervals: [3_000] });
  expect(errors.filter((e) => !/Mercure|EventSource/i.test(e))).toEqual([]);
});
