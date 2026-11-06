import { createHmac } from 'node:crypto';
import { expect, test, type APIResponse, type Locator, type Page } from '@playwright/test';
import { consoleOk, instanceRow } from './support/fleetData';
import { createProjectViaUi, deleteProjectViaUi, follow, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// Browser-only coverage of the Webhooks pages at 1280 px (desktop project) and 390 px (phone project): the copy button, the
// once-shown secret and the rotate details, the pipeline token form, the delivery log's layout, filter form and pager on a phone,
// and long values. Forms, refusals, rules, outcomes, paging and the CSRF and member guards are asserted in
// app/tests/Functional/Webhooks/ and app/tests/Integration/Command/Webhook/. webhooks.spec.ts keeps the first walks.
//
// Every test owns a project (uniqueName) and removes it in `finally`; deliveries and rules go with the project.

const isPhone = (): boolean => test.info().project.name === 'phone';

const sign = (body: string, secret: string): string => `sha256=${createHmac('sha256', secret).update(body).digest('hex')}`;

const endpoint = (page: Page, source: 'jira' | 'bitbucket', project?: string): Locator =>
  page.locator(`[data-testid=webhook-endpoint][data-source=${source}]${project ? `[data-project=${project}]` : ''}`);

async function expectClean(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

async function expectTall(target: Locator, what: string): Promise<void> {
  await expect(target, what).toBeVisible();
  const box = await target.boundingBox();
  expect(box!.height, what).toBeGreaterThanOrEqual(43.5);
}

/** Creates a secret with the button of the card and returns it (read from the page that shows it once). */
async function createSecret(page: Page, source: 'jira' | 'bitbucket', project: string, from = `/admin/projects/${project}/integrations`): Promise<string> {
  await page.goto(from);
  await submitAndWait(page, endpoint(page, source, project).getByTestId('webhook-create'));
  await expect(page.getByTestId('webhook-secret-once')).toBeVisible();
  const secret = await page.getByTestId('webhook-secret-value').inputValue();
  expect(secret).toMatch(/^[0-9a-f]{64}$/);
  await follow(page, page.getByTestId('webhook-secret-done'));
  return secret;
}

interface Hook {
  status: number;
  body: Record<string, unknown>;
}

/** POSTs a signed delivery to the receiver and returns its answer. */
async function deliver(page: Page, source: 'jira' | 'bitbucket', project: string, secret: string, payload: unknown, extra: Record<string, string> = {}, signWith = secret): Promise<Hook> {
  const body = typeof payload === 'string' ? payload : JSON.stringify(payload);
  const response: APIResponse = await page.request.post(`/hooks/${source}/${project}`, {
    // A Buffer is sent byte for byte: a string that is not valid JSON would be JSON-encoded, and the signature would no longer match.
    data: Buffer.from(body),
    headers: { 'Content-Type': 'application/json', 'X-Hub-Signature': sign(body, signWith), ...extra },
  });
  const text = await response.text();
  return { status: response.status(), body: text.startsWith('{') ? JSON.parse(text) : { text } };
}

const jiraMove = (key: string, to: string, from = 'To Do') => ({
  webhookEvent: 'jira:issue_updated',
  issue: { key },
  changelog: { items: [{ field: 'status', fromString: from, toString: to }] },
});

let sequence = 0;
const jiraId = (): Record<string, string> => ({ 'X-Atlassian-Webhook-Identifier': `pw-${Date.now()}-${sequence++}` });
const bitbucketId = (event: string): Record<string, string> => ({ 'X-Event-Key': event, 'X-Request-UUID': `pw-${Date.now()}-${sequence++}` });

/** Template and default branch of a project, so a Jira rule can deploy. */
function prepareDeploy(project: string): void {
  consoleOk(['fleet:template:create', project, 'web']);
  consoleOk(['fleet:project:update', project, '--default-branch=develop']);
}

test.describe('webhooks, full coverage', () => {
  test('both pages list every receiver with its address, empty states and a working copy button @phone', async ({ page, context, baseURL }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName('wc');
    try {
      await createProjectViaUi(page, project);
      await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: baseURL });

      // Overview: a project without secrets has its two cards, nothing is configured, nothing was delivered.
      await page.goto('/admin/webhooks');
      await expect(page).toHaveTitle(/Webhooks/);
      await expect(page.getByRole('heading', { level: 1, name: 'Webhooks' })).toBeVisible();
      for (const source of ['jira', 'bitbucket'] as const) {
        const card = endpoint(page, source, project);
        await expect(card).toHaveCount(1);
        await expect(card.getByTestId('webhook-secret-state')).toHaveText('No secret');
        await expect(card.getByTestId('webhook-url')).toHaveValue(new RegExp(`^https?://[^/]+/hooks/${source}/${project}$`));
        await expect(card.getByTestId('webhook-create')).toBeVisible();
        await expect(card.getByTestId('webhook-rotated')).toHaveCount(0);
        await expect(card.getByTestId('webhook-rules')).toHaveCount(0);
        await expect(card.getByTestId('webhook-no-rules')).toHaveCount(0);
        await expect(card.getByTestId('webhook-token')).toHaveCount(0);
        await expect(card.getByTestId('webhook-rotate-details')).toHaveCount(0);
        await expect(card.getByTestId('webhook-latest')).toHaveCount(0);
      }
      await expect(page.getByTestId('webhook-deliveries').getByRole('heading', { level: 2, name: 'Deliveries' })).toBeVisible();
      await expect(page.getByTestId('delivery-filter-clear')).toHaveCount(0);
      await expectClean(page, 'the overview');

      // Copy puts the address on the clipboard and says so.
      const jira = endpoint(page, 'jira', project);
      await jira.getByTestId('webhook-copy').click();
      await expect(jira.locator('[data-clipboard-target=status]')).toHaveText('Copied');
      expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(await jira.getByTestId('webhook-url').inputValue());
      if (isPhone()) {
        await expectTall(jira.getByTestId('webhook-copy'), 'Copy');
        await expectTall(jira.getByTestId('webhook-create'), 'Create secret');
        await expectTall(jira.getByTestId('webhook-url'), 'address field');
      }

      // The card title leads to the project's tab, which has just that project's two cards and an empty log.
      await follow(page, jira.getByRole('link', { name: project }));
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/integrations$`));
      await expect(page).toHaveTitle(new RegExp(`Webhooks · ${project}`));
      await expect(page.getByTestId('webhook-endpoint')).toHaveCount(2);
      await expect(page.getByTestId('deliveries-empty')).toHaveText('No delivery has arrived yet.');
      await expect(page.getByTestId('delivery-row')).toHaveCount(0);
      await expect(page.getByRole('navigation', { name: 'Delivery pages' })).toHaveCount(0);
      await expect(page.getByRole('navigation', { name: 'Project sections' }).getByRole('link', { name: 'Integrations' })).toHaveAttribute('aria-current', 'page');
      await expectClean(page, 'the project tab');

      // Hide ignored is a link that toggles the filter and its own label.
      await follow(page, page.getByTestId('webhook-filter'));
      await expect(page).toHaveURL(/hide-ignored=1/);
      await expect(page.getByTestId('webhook-filter')).toHaveText('Show ignored');
      await follow(page, page.getByTestId('webhook-filter'));
      await expect(page.getByTestId('webhook-filter')).toHaveText('Hide ignored');
      await expect(page).not.toHaveURL(/hide-ignored/);

      // A project that does not exist has no tab.
      expect((await page.goto('/admin/projects/no-such-project/integrations'))?.status()).toBe(404);
      expect(errors.filter((e) => !e.includes('status of 404'))).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('a secret is created and rotated from either page, shown once, with the rotate details opening and closing @phone', async ({ page, context }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName('ws');
    try {
      await createProjectViaUi(page, project);

      // Created from the overview, for Bitbucket: the answer is the one page that shows it.
      await page.goto('/admin/webhooks');
      const [created] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(`/webhooks/bitbucket/secret`)),
        submitAndWait(page, endpoint(page, 'bitbucket', project).getByTestId('webhook-create')),
      ]);
      expect(created.status()).toBe(200);
      expect(created.headers()['cache-control']).toContain('no-store');
      await expect(page.getByRole('heading', { level: 1, name: 'Webhook secret' })).toBeVisible();
      await expect(page.getByTestId('webhook-secret-once')).toContainText('This is the only time it is shown.');
      const first = await page.getByTestId('webhook-secret-value').inputValue();
      expect(first).toMatch(/^[0-9a-f]{64}$/);
      await expect(page.getByLabel('Address')).toHaveValue(new RegExp(`/hooks/bitbucket/${project}$`));
      await expect(page.getByTestId('webhook-secret-value')).toHaveAttribute('readonly', '');
      await expectClean(page, 'the secret page');
      if (isPhone()) {
        await expectTall(page.getByTestId('webhook-secret-copy'), 'Copy secret');
        await expectTall(page.getByTestId('webhook-secret-done'), 'Done');
      }
      await follow(page, page.getByTestId('webhook-secret-done'));
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/integrations$`));
      const card = endpoint(page, 'bitbucket', project);
      await expect(card.getByTestId('webhook-secret-state')).toHaveText('Secret set');
      await expect(card.getByTestId('webhook-rotated')).toContainText(/Secret created or rotated \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC\. It cannot be shown again\./);
      await expect(card.getByTestId('webhook-no-rules')).toContainText('No rules yet');
      await expect(card.getByTestId('webhook-create')).toHaveCount(0);
      // The other source is untouched.
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-secret-state')).toHaveText('No secret');
      expect(await page.content()).not.toContain(first);
      await page.reload();
      expect(await page.content()).not.toContain(first);

      // The secret address answers only the POST that makes it: opening it is not a way to see a secret.
      expect((await page.goto(`/admin/projects/${project}/webhooks/bitbucket/secret`))?.status()).toBe(404);
      expect((await page.goto(`/admin/projects/${project}/webhooks/gitlab/secret`))?.status()).toBe(404);

      const jiraFirst = await createSecret(page, 'jira', project);

      // Rotation: the details open and close without doing anything, the old secret works until "Rotate now".
      const body = jiraMove('PW-1', 'Done');
      await page.goto(`/admin/projects/${project}/integrations`);
      const jira = endpoint(page, 'jira', project);
      await expect(jira.getByTestId('webhook-rotate')).toBeHidden();
      await jira.getByText('Rotate secret').click();
      await expect(jira.getByTestId('webhook-rotate')).toBeVisible();
      await expect(jira.getByTestId('webhook-rotate-details')).toContainText('The old secret stops working at once.');
      if (isPhone()) {
        await expectTall(jira.getByTestId('webhook-rotate'), 'Rotate now');
      }
      await expectClean(page, 'the open rotate details');
      await jira.getByText('Rotate secret').click();
      await expect(jira.getByTestId('webhook-rotate')).toBeHidden();
      expect((await deliver(page, 'jira', project, jiraFirst, body, jiraId())).status).toBe(200);

      await jira.getByText('Rotate secret').click();
      await submitAndWait(page, jira.getByTestId('webhook-rotate'));
      await expect(page.getByTestId('webhook-secret-once')).toBeVisible();
      const second = await page.getByTestId('webhook-secret-value').inputValue();
      expect(second).toMatch(/^[0-9a-f]{64}$/);
      expect(second).not.toBe(jiraFirst);
      expect((await deliver(page, 'jira', project, jiraFirst, body, jiraId())).status).toBe(401);
      expect((await deliver(page, 'jira', project, second, body, jiraId())).status).toBe(200);
      expect(errors.filter((e) => !e.includes('status of 404'))).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the delivery log with every kind of row fits the screen, shows the outcome where the width allows and filters through its form @phone', async ({ page }) => {
    test.setTimeout(240_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName('wl');
    let deployed = '';
    try {
      await createProjectViaUi(page, project);
      prepareDeploy(project);
      const jira = await createSecret(page, 'jira', project);
      const bitbucket = await createSecret(page, 'bitbucket', project);
      consoleOk(['fleet:webhook:rule:add', project, '--source=jira', '--on-status=Dispatched', '--template=web']);
      consoleOk(['fleet:webhook:rule:add', project, '--source=bitbucket', '--on-event=pullrequest:approved', '--repo=acme/blog', '--action=run-pipeline', '--pattern=renovate-merge', '--ref=develop']);

      // One row of each result (the outcomes and their reasons are asserted in WebhookPagesTest and WebhookReceiverTest).
      const id = jiraId();
      const accepted = await deliver(page, 'jira', project, jira, jiraMove('PW-10', 'Dispatched'), id);
      expect(accepted.body.result).toBe('accepted');
      deployed = String(accepted.body.instance);
      const jobId = Number(accepted.body.job);
      expect((await deliver(page, 'jira', project, jira, jiraMove('PW-10', 'Dispatched'), id)).body.result).toBe('duplicate');
      expect((await deliver(page, 'jira', project, jira, jiraMove('PW-11', 'Done'), jiraId())).body.result).toBe('ignored');
      expect((await deliver(page, 'bitbucket', project, bitbucket, { repository: { full_name: 'acme/blog' } }, bitbucketId('pullrequest:approved'))).body.result).toBe('failed');

      await page.goto(`/admin/projects/${project}/integrations`);
      const rows = page.getByTestId('delivery-row');
      const count = (result: string): Locator => page.locator(`[data-testid=delivery-row][data-result=${result}]`);
      await expect(rows).toHaveCount(4);
      await expect(count('accepted').getByTestId('delivery-job').first()).toHaveText(`job #${jobId}`);
      if (!isPhone()) {
        await expect(count('failed').getByTestId('delivery-outcome')).toContainText('no pipeline token');
        await expect(count('ignored').getByTestId('delivery-outcome')).toContainText('no rule for this status');
      } else {
        await expect(count('failed').getByTestId('delivery-outcome-phone')).toContainText('no pipeline token');
        await expect(count('ignored').getByTestId('delivery-outcome-phone')).toContainText('no rule for this status');
      }
      await expectClean(page, 'the log with every result');

      // The filter form works with a real select and link; on a phone its controls are full-size targets.
      if (isPhone()) {
        await expectTall(page.getByLabel('Source'), 'Source filter');
        await expectTall(page.getByLabel('Outcome'), 'Outcome filter');
        await expectTall(page.getByTestId('delivery-filter-apply'), 'Filter');
      }
      await page.getByLabel('Outcome').selectOption('failed');
      await follow(page, page.getByTestId('delivery-filter-apply'));
      await expect(rows).toHaveCount(1);
      await expect(page.getByLabel('Outcome')).toHaveValue('failed');
      await follow(page, page.getByTestId('delivery-filter-clear'));
      await expect(rows).toHaveCount(4);
      await expectClean(page, 'the filtered log');

      await page.goto('/admin/webhooks');
      await expect(rows.first()).toBeVisible();
      await expectClean(page, 'the overview log');
      expect(errors).toEqual([]);
    } finally {
      // The accepted delivery queued a deploy, which made an instance row; a project with an instance cannot be deleted.
      if (deployed) {
        instanceRow('delete', project, deployed.slice(project.length + 2));
      }
      await deleteProjectViaUi(page, project);
    }
  });

  test('the second page of a long log fits the screen and its pager links are full-size targets @phone', async ({ page }) => {
    test.setTimeout(300_000);
    const project = uniqueName('wp');
    try {
      await createProjectViaUi(page, project);
      const secret = await createSecret(page, 'jira', project);
      for (let i = 0; i < 51; i++) {
        const sent = await deliver(page, 'jira', project, secret, { webhookEvent: 'jira:issue_created', issue: { key: `PG-${i}` } }, jiraId());
        expect(sent.status).toBe(200);
      }
      const rows = page.getByTestId('delivery-row');
      const pager = page.getByRole('navigation', { name: 'Delivery pages' });

      // Fifty to a page, the paging maths and the overview's latest twenty-five are asserted in WebhookPagesTest.
      await page.goto(`/admin/projects/${project}/integrations`);
      await expect(rows).toHaveCount(50);
      await follow(page, pager.getByRole('link', { name: 'Older' }));
      await expect(page).toHaveURL(/page=2/);
      await expect(rows).toHaveCount(1);
      if (isPhone()) {
        await expectTall(pager.getByRole('link', { name: 'Newer' }), 'Newer');
      }
      await expectClean(page, 'page two of the log');
      await follow(page, pager.getByRole('link', { name: 'Newer' }));
      await expect(rows).toHaveCount(50);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the pipeline token form validates in the browser, can be cancelled, replaced and removed, and is never shown @phone', async ({ page }) => {
    test.setTimeout(240_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName('wt');
    try {
      await createProjectViaUi(page, project);
      await createSecret(page, 'bitbucket', project);
      await page.goto(`/admin/projects/${project}/integrations`);
      const card = endpoint(page, 'bitbucket', project);
      const state = card.getByTestId('webhook-token-state');
      const open = async (): Promise<void> => {
        const input = card.getByTestId('webhook-token-input');
        if (!(await input.isVisible())) {
          await card.locator('summary', { hasText: 'the pipeline token' }).click();
        }
        await expect(input).toBeVisible();
      };
      const save = async (value: string): Promise<void> => {
        await open();
        await card.getByTestId('webhook-token-input').fill(value);
        await submitAndWait(page, card.getByTestId('webhook-token-save'));
      };

      await expect(state).toContainText('No pipeline token: a run-pipeline rule starts nothing until one is set.');
      await expect(card.getByTestId('webhook-token-clear')).toHaveCount(0);
      await expect(card.getByText('Set the pipeline token')).toBeVisible();

      // The field is required and hidden: nothing is sent for an empty one.
      await open();
      const input = card.getByTestId('webhook-token-input');
      await expect(input).toHaveAttribute('type', 'password');
      await expect(input).toHaveAttribute('maxlength', '2048');
      await expect(input).toHaveAttribute('autocomplete', 'off');
      expect(await input.evaluate((node) => (node as HTMLInputElement).validity.valueMissing)).toBe(true);
      await expect(page.getByTestId('flashes')).toHaveCount(0);

      // Cancel: type, close the details, nothing was saved.
      await input.fill('ATCTT-typed-then-abandoned');
      await card.getByText('Set the pipeline token').click();
      await expect(state).toContainText('No pipeline token');
      await page.reload();
      await expect(state).toContainText('No pipeline token');

      // A token is saved, replaced and removed; the page never shows it (refusals: WebhookPagesTest).
      await save('ATCTT-first-token-value');
      await expect(page.getByTestId('flashes')).toContainText('The pipeline token is saved. It cannot be shown again.');
      await expect(state).toContainText(/Pipeline token set \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC\. It cannot be shown again\./);
      await expect(card.getByText('Replace the pipeline token')).toBeVisible();
      expect(await page.content()).not.toContain('ATCTT-first-token-value');
      await expect(card.getByTestId('webhook-token-input')).not.toHaveValue('ATCTT-first-token-value');
      await expectClean(page, 'the saved token');
      if (isPhone()) {
        await expectTall(card.getByTestId('webhook-token-clear'), 'Remove token');
      }

      await save('ATCTT-replacement-token');
      await expect(page.getByTestId('flashes')).toContainText('The pipeline token is saved.');
      await expect(state).toContainText('Pipeline token set');
      await submitAndWait(page, card.getByTestId('webhook-token-clear'));
      await expect(page.getByTestId('flashes')).toContainText('The pipeline token was removed.');
      await expect(state).toContainText('No pipeline token');
      await expect(card.getByTestId('webhook-token-clear')).toHaveCount(0);

      // The Jira card never has a token form, the token form is on the overview too.
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-token')).toHaveCount(0);
      await page.goto('/admin/webhooks');
      await expect(endpoint(page, 'bitbucket', project).getByTestId('webhook-token')).toBeVisible();
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('long project names, rules, subjects and reasons wrap on both pages', async ({ page }) => {
    test.setTimeout(240_000);
    const project = uniqueName('wlong').padEnd(40, 'z');
    let deployed = '';
    try {
      expect(project.length).toBe(40);
      await createProjectViaUi(page, project);
      prepareDeploy(project);
      const jira = await createSecret(page, 'jira', project);
      const bitbucket = await createSecret(page, 'bitbucket', project);
      const status = 'Waiting for a very long review by the whole team '.repeat(2).trim().slice(0, 100);
      consoleOk(['fleet:webhook:rule:add', project, '--source=jira', `--on-status=${status}`, '--template=web']);
      consoleOk(['fleet:webhook:rule:add', project, '--source=bitbucket', '--on-event=pullrequest:comment_created', '--repo=very-long-workspace-name/very-long-repository-slug-name', `--match-branch=${'feature/'.repeat(24)}*`, `--comment=${'/merge '.repeat(28)}`.trimEnd(), '--action=run-pipeline', `--pattern=${'p'.repeat(100)}`, `--ref=${'r'.repeat(60)}`]);

      const key = `LONG-${'9'.repeat(100)}`;
      const accepted = await deliver(page, 'jira', project, jira, jiraMove(key, status, 'A very long previous status name'), jiraId());
      expect(accepted.body.result).toBe('accepted');
      deployed = String(accepted.body.instance);
      expect((await deliver(page, 'jira', project, jira, jiraMove(key, 'Another status that is quite long indeed'), jiraId())).body.result).toBe('ignored');
      expect((await deliver(page, 'bitbucket', project, bitbucket, { repository: { full_name: 'very-long-workspace-name/very-long-repository-slug-name' }, comment: { content: { raw: '/merge '.repeat(28).trimEnd() } }, pullrequest: { source: { branch: { name: `${'feature/'.repeat(24)}x` } } } }, bitbucketId('pullrequest:comment_created'))).body.result).toMatch(/^(failed|ignored)$/);

      for (const path of [`/admin/projects/${project}/integrations`, '/admin/webhooks']) {
        await page.goto(path);
        await expect(page.getByTestId('delivery-row').first()).toBeVisible();
        await expect(endpoint(page, 'jira', project).getByTestId('webhook-rules')).toContainText(status.slice(0, 40));
        await expectClean(page, `${path} with long values`);
      }
    } finally {
      if (deployed) {
        instanceRow('delete', project, deployed.slice(project.length + 2));
      }
      await deleteProjectViaUi(page, project);
    }
  });
});