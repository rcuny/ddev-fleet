import { createHmac } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';
import { blockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

const isPhone = (testInfo: { project: { name: string } }) => testInfo.project.name === 'phone';

const sign = (body: string, secret: string): string => `sha256=${createHmac('sha256', secret).update(body).digest('hex')}`;

async function createSecret(page: Page, project: string): Promise<string> {
  await page.goto(`/admin/projects/${project}/webhooks`);
  await submitAndWait(page, page.locator('[data-testid=webhook-endpoint][data-source=jira]').getByTestId('webhook-create'));
  await expect(page.getByTestId('webhook-secret-once')).toBeVisible();
  const secret = await page.getByTestId('webhook-secret-value').inputValue();
  expect(secret).toMatch(/^[0-9a-f]{64}$/);
  return secret;
}

test.describe('webhooks', () => {
  test('the page lists every project with its addresses and works without overflow', async ({ page }, testInfo) => {
    const project = uniqueName('wh');
    const errors = collectBrowserErrors(page);
    await createProjectViaUi(page, project);
    try {
      await page.goto('/admin/webhooks');
      await page.waitForLoadState('networkidle');

      await expect(page).toHaveTitle(/Webhooks/);
      await expect(page.locator('h1')).toHaveCount(1);
      const mine = page.locator(`[data-testid=webhook-endpoint][data-project=${project}]`);
      await expect(mine).toHaveCount(2);
      await expect(page.locator(`[data-testid=webhook-endpoint][data-project=${project}][data-source=jira]`).getByTestId('webhook-url')).toHaveValue(new RegExp(`/hooks/jira/${project}$`));
      await expect(page.locator(`[data-testid=webhook-endpoint][data-project=${project}][data-source=bitbucket]`).getByTestId('webhook-url')).toHaveValue(new RegExp(`/hooks/bitbucket/${project}$`));
      await expect(mine.first().getByTestId('webhook-copy')).toBeVisible();

      expect(await hasHorizontalOverflow(page)).toBe(false);
      expect(await blockingViolations(page)).toEqual([]);
      if (isPhone(testInfo)) {
        for (const control of await mine.locator('button[type=submit], input:not([type=hidden]), button, summary').all()) {
          const box = await control.boundingBox();
          expect(box, 'a control is visible').not.toBeNull();
          expect(box!.height).toBeGreaterThanOrEqual(43.5);
        }
      }
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('a secret is shown once, a bad signature is refused and the old secret dies on rotation', async ({ page }) => {
    const project = uniqueName('wh');
    const errors = collectBrowserErrors(page);
    await createProjectViaUi(page, project);
    try {
      const first = await createSecret(page, project);
      expect(await hasHorizontalOverflow(page)).toBe(false);
      expect(await blockingViolations(page)).toEqual([]);

      await page.getByTestId('webhook-secret-done').click();
      await expect(page.getByTestId('webhook-rotated')).toBeVisible();
      expect(await page.content()).not.toContain(first);

      const body = JSON.stringify({ webhookEvent: 'jira:issue_updated', issue: { key: 'PW-1' }, changelog: { items: [{ field: 'status', fromString: 'To Do', toString: 'Dispatched' }] } });
      const url = `/hooks/jira/${project}`;
      const headers = (secret: string) => ({ 'Content-Type': 'application/json', 'X-Hub-Signature': sign(body, secret), 'X-Atlassian-Webhook-Identifier': `pw-${Date.now()}` });

      const bad = await page.request.post(url, { data: body, headers: headers('wrong-secret') });
      expect(bad.status()).toBe(401);
      const good = await page.request.post(url, { data: body, headers: headers(first) });
      expect(good.status()).toBe(200);
      expect((await good.json()).result).toBe('ignored');

      // The delivery log shows the accepted request and not the rejected one.
      await page.goto(`/admin/projects/${project}/webhooks`);
      await expect(page.getByTestId('delivery-row')).toHaveCount(1);
      await expect(page.getByTestId('delivery-row')).toContainText('PW-1');
      expect(await hasHorizontalOverflow(page)).toBe(false);
      expect(await blockingViolations(page)).toEqual([]);

      // The overview, with deliveries in it, must not need a sideways scroll on a phone either.
      await page.goto('/admin/webhooks');
      await expect(page.getByTestId('delivery-row').first()).toBeVisible();
      expect(await hasHorizontalOverflow(page)).toBe(false);
      expect(await blockingViolations(page)).toEqual([]);

      // Rotation: confirm inside the details, then the old secret stops working.
      const jira = page.locator('[data-testid=webhook-endpoint][data-source=jira]');
      await jira.getByText('Rotate secret').click();
      await submitAndWait(page, jira.getByTestId('webhook-rotate'));
      await expect(page.getByTestId('webhook-secret-once')).toBeVisible();
      const second = await page.getByTestId('webhook-secret-value').inputValue();
      expect(second).not.toBe(first);

      expect((await page.request.post(url, { data: body, headers: headers(first) })).status()).toBe(401);
      expect((await page.request.post(url, { data: body, headers: headers(second) })).status()).toBe(200);
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the Webhooks tab is part of the project sections', async ({ page }) => {
    const project = uniqueName('wh');
    await createProjectViaUi(page, project);
    try {
      await page.goto(`/admin/projects/${project}`);
      await page.getByRole('navigation', { name: 'Project sections' }).getByRole('link', { name: 'Webhooks' }).click();
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/webhooks$`));
      await expect(page.getByTestId('deliveries-empty')).toBeVisible();
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});
