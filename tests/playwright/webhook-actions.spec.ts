import { createHmac } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import { blockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// M0.08b: the Bitbucket pipeline token form and the delivery outcome on both Webhooks pages. A `run-pipeline` rule is the
// action that can be shown without a deploy or a call to Bitbucket: with no token stored it records a clear note and
// makes no HTTP call. The token is only ever set AFTER the delivery, so the app under test never calls Bitbucket.

const isPhone = (): boolean => test.info().project.name === 'phone';

const sign = (body: string, secret: string): string => `sha256=${createHmac('sha256', secret).update(body).digest('hex')}`;

/** Where the app under test keeps its data (as in snapshot.spec.ts). */
function fleetHome(): string {
  if (!process.env.FLEET_E2E_BASE_URL) {
    return fileURLToPath(new URL('./.run/fleet-home', import.meta.url));
  }
  if (!process.env.FLEET_HOME) {
    test.skip(true, 'Adding a rule needs the FLEET_HOME of the app under test.');
  }

  return process.env.FLEET_HOME as string;
}

/** Runs a console command of the app under test. */
function console_(args: string[]): void {
  const appDir = fileURLToPath(new URL('../../app', import.meta.url));
  const php = spawnSync('php8.4', ['-v'], { stdio: 'ignore' }).status === 0 ? 'php8.4' : 'php';
  const result = spawnSync(php, ['bin/console', ...args, '--no-interaction'], {
    cwd: appDir,
    env: { ...process.env, APP_ENV: 'dev', FLEET_RUNNER: 'recording', FLEET_HOME: fleetHome() },
    encoding: 'utf8',
  });
  expect(result.status, `bin/console ${args.join(' ')}: ${result.stdout}${result.stderr}`).toBe(0);
}

async function createBitbucketSecret(page: Page, project: string): Promise<string> {
  await page.goto(`/admin/projects/${project}/webhooks`);
  await submitAndWait(page, page.locator('[data-testid=webhook-endpoint][data-source=bitbucket]').getByTestId('webhook-create'));
  await expect(page.getByTestId('webhook-secret-once')).toBeVisible();
  const secret = await page.getByTestId('webhook-secret-value').inputValue();
  await page.getByTestId('webhook-secret-done').click();
  return secret;
}

test.describe('webhook actions', () => {
  test('the pipeline token is set, replaced and removed, and never shown again', async ({ page }) => {
    const project = uniqueName('wa');
    const errors = collectBrowserErrors(page);
    await createProjectViaUi(page, project);
    try {
      await page.goto(`/admin/projects/${project}/webhooks`);
      // No Bitbucket secret yet: the token form is not offered, the Jira card never has one.
      await expect(page.getByTestId('webhook-token')).toHaveCount(0);

      await createBitbucketSecret(page, project);
      const card = page.locator('[data-testid=webhook-endpoint][data-source=bitbucket]');
      await expect(card.getByTestId('webhook-token-state')).toContainText('No pipeline token');
      await expect(page.locator('[data-testid=webhook-endpoint][data-source=jira]').getByTestId('webhook-token')).toHaveCount(0);

      await card.getByText('Set the pipeline token').click();
      const input = card.getByTestId('webhook-token-input');
      await expect(input).toHaveAttribute('type', 'password');
      expect(await hasHorizontalOverflow(page)).toBe(false);
      expect(await blockingViolations(page)).toEqual([]);
      if (isPhone()) {
        for (const control of [input, card.getByTestId('webhook-token-save')]) {
          const box = await control.boundingBox();
          expect(box, 'a control is visible').not.toBeNull();
          expect(box!.height).toBeGreaterThanOrEqual(43.5);
        }
      }

      await input.fill('ATCTT-playwright-token-1');
      await submitAndWait(page, card.getByTestId('webhook-token-save'));
      await expect(page.getByTestId('flashes')).toContainText('The pipeline token is saved');
      await expect(card.getByTestId('webhook-token-state')).toContainText('Pipeline token set');
      expect(await page.content()).not.toContain('ATCTT-playwright-token-1');
      expect(await blockingViolations(page)).toEqual([]);

      await card.getByText('Replace the pipeline token').click();
      await card.getByTestId('webhook-token-input').fill('ATCTT-playwright-token-2');
      await submitAndWait(page, card.getByTestId('webhook-token-save'));
      await expect(page.getByTestId('flashes')).toContainText('The pipeline token is saved');
      expect(await page.content()).not.toContain('ATCTT-playwright-token');

      await submitAndWait(page, card.getByTestId('webhook-token-clear'));
      await expect(page.getByTestId('flashes')).toContainText('The pipeline token was removed');
      await expect(card.getByTestId('webhook-token-state')).toContainText('No pipeline token');
      await expect(card.getByTestId('webhook-token-clear')).toHaveCount(0);
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('a delivery shows its outcome and both pages filter by source and outcome', async ({ page }) => {
    const project = uniqueName('wa');
    const errors = collectBrowserErrors(page);
    await createProjectViaUi(page, project);
    try {
      const secret = await createBitbucketSecret(page, project);
      console_(['fleet:webhook:rule:add', project, '--source=bitbucket', '--action=run-pipeline', '--on-event=pullrequest:approved', '--repo=acme/blog', '--pattern=renovate-merge', '--ref=develop']);

      const send = async (event: string, repo: string, uuid: string) => {
        const body = JSON.stringify({ repository: { full_name: repo }, uuid });
        const response = await page.request.post(`/hooks/bitbucket/${project}`, {
          data: body,
          headers: { 'Content-Type': 'application/json', 'X-Hub-Signature': sign(body, secret), 'X-Event-Key': event, 'X-Request-UUID': uuid },
        });
        expect(response.status()).toBe(200);
        return response.json();
      };
      // Matches the rule but there is no token: nothing is called, and the row says why.
      const failed = await send('pullrequest:approved', 'acme/blog', `pw-${Date.now()}-a`);
      expect(failed.result).toBe('failed');
      expect(failed.note).toContain('no pipeline token');
      expect((await send('pullrequest:approved', 'acme/other', `pw-${Date.now()}-b`)).result).toBe('ignored');

      await page.goto(`/admin/projects/${project}/webhooks`);
      await expect(page.getByTestId('delivery-row')).toHaveCount(2);
      const failedRow = page.locator('[data-testid=delivery-row][data-result=failed]');
      await expect(failedRow).toHaveCount(1);
      if (isPhone()) {
        await expect(failedRow.getByTestId('delivery-outcome-phone')).toContainText('no pipeline token');
      } else {
        await expect(failedRow.getByTestId('delivery-outcome')).toContainText('no pipeline token');
        await expect(failedRow.getByTestId('delivery-decision')).toContainText('run pipeline renovate-merge on develop');
      }
      expect(await hasHorizontalOverflow(page)).toBe(false);
      expect(await blockingViolations(page)).toEqual([]);

      // Filters on the project page.
      await page.getByLabel('Outcome').selectOption('failed');
      await page.getByTestId('delivery-filter-apply').click();
      await expect(page.getByTestId('delivery-row')).toHaveCount(1);
      await expect(page.getByTestId('delivery-row')).toHaveAttribute('data-result', 'failed');
      await page.getByLabel('Source').selectOption('jira');
      await page.getByTestId('delivery-filter-apply').click();
      await expect(page.getByTestId('delivery-row')).toHaveCount(0);
      await page.getByTestId('delivery-filter-clear').click();
      await expect(page.getByTestId('delivery-row')).toHaveCount(2);

      // And on the cross-project page.
      await page.goto('/admin/webhooks?source=bitbucket&result=ignored');
      await expect(page.locator(`[data-testid=delivery-row][data-result=ignored]`).first()).toBeVisible();
      await expect(page.locator('[data-testid=delivery-row][data-result=failed]')).toHaveCount(0);
      await expect(page.getByLabel('Source')).toHaveValue('bitbucket');
      await expect(page.getByLabel('Outcome')).toHaveValue('ignored');
      expect(await hasHorizontalOverflow(page)).toBe(false);
      expect(await blockingViolations(page)).toEqual([]);
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});
