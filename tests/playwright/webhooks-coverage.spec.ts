import { createHmac } from 'node:crypto';
import { expect, test, type APIResponse, type Locator, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { consoleJson, consoleOk, instanceRow, runConsole } from './support/fleetData';
import { createProjectViaUi, deleteProjectViaUi, follow, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// Full coverage of the Webhooks pages at 1280 px (desktop project) and 390 px (phone project): the overview and the
// project tab, creating and rotating a secret (shown once), the rules (the console is the only way to add or remove
// one, so the specs run it and read the result from the pages), every outcome of a delivery in the log, the filters and
// pages of the log, the Bitbucket pipeline token, the CSRF and member guards, long values on a phone and axe in both
// themes. webhooks.spec.ts and webhook-actions.spec.ts keep the first walks; this file is the exhaustive one.
//
// Every test owns a project (uniqueName) and removes it in `finally`; deliveries and rules go with the project.

const isPhone = (): boolean => test.info().project.name === 'phone';

const sign = (body: string, secret: string): string => `sha256=${createHmac('sha256', secret).update(body).digest('hex')}`;

const TOKEN_MESSAGE = 'Enter the access token: printable characters without spaces, at most 2048.';

const endpoint = (page: Page, source: 'jira' | 'bitbucket', project?: string): Locator =>
  page.locator(`[data-testid=webhook-endpoint][data-source=${source}]${project ? `[data-project=${project}]` : ''}`);

async function expectClean(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
  await expectNoBlockingViolations(page, where);
}

async function expectTall(target: Locator, what: string): Promise<void> {
  await expect(target, what).toBeVisible();
  const box = await target.boundingBox();
  expect(box!.height, what).toBeGreaterThanOrEqual(43.5);
}

/** Creates a secret with the button of the card and returns it (read from the page that shows it once). */
async function createSecret(page: Page, source: 'jira' | 'bitbucket', project: string, from = `/admin/projects/${project}/webhooks`): Promise<string> {
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
  test('both pages list every receiver with its address, empty states and a working copy button', async ({ page, context, baseURL }) => {
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
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/webhooks$`));
      await expect(page).toHaveTitle(new RegExp(`Webhooks · ${project}`));
      await expect(page.getByTestId('webhook-endpoint')).toHaveCount(2);
      await expect(page.getByTestId('deliveries-empty')).toHaveText('No delivery has arrived yet.');
      await expect(page.getByTestId('delivery-row')).toHaveCount(0);
      await expect(page.getByRole('navigation', { name: 'Delivery pages' })).toHaveCount(0);
      await expect(page.getByRole('navigation', { name: 'Project sections' }).getByRole('link', { name: 'Webhooks' })).toHaveAttribute('aria-current', 'page');
      await expectClean(page, 'the project tab');

      // Hide ignored is a link that toggles the filter and its own label.
      await follow(page, page.getByTestId('webhook-filter'));
      await expect(page).toHaveURL(/hide-ignored=1/);
      await expect(page.getByTestId('webhook-filter')).toHaveText('Show ignored');
      await follow(page, page.getByTestId('webhook-filter'));
      await expect(page.getByTestId('webhook-filter')).toHaveText('Hide ignored');
      await expect(page).not.toHaveURL(/hide-ignored/);

      // A project that does not exist has no tab.
      expect((await page.goto('/admin/projects/no-such-project/webhooks'))?.status()).toBe(404);
      expect(errors.filter((e) => !e.includes('status of 404'))).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('a secret is created and rotated from either page, shown once, and a stale tab cannot create a second one', async ({ page, context }) => {
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
      await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/webhooks$`));
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
      await page.goto(`/admin/projects/${project}/webhooks`);
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

  test('a second create from a stale tab is refused with a message and keeps the secret', async ({ page, context }) => {
    test.setTimeout(120_000);
    const project = uniqueName('wd');
    try {
      await createProjectViaUi(page, project);
      const stale = await context.newPage();
      await stale.goto(`/admin/projects/${project}/webhooks`);
      await expect(endpoint(stale, 'jira', project).getByTestId('webhook-create')).toBeVisible();

      const secret = await createSecret(page, 'jira', project);

      const [response] = await Promise.all([
        stale.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/jira/secret')),
        endpoint(stale, 'jira', project).getByTestId('webhook-create').click(),
      ]);
      expect(response.status(), 'a refused second create is not a server error').toBeLessThan(500);
      await expect(stale.getByTestId('flashes')).toContainText('already has a webhook secret for Jira');
      await expect(stale.getByTestId('webhook-secret-once')).toHaveCount(0);
      await expectClean(stale, 'the refusal');
      await stale.close();

      // The first secret still works.
      const answer = await deliver(page, 'jira', project, secret, jiraMove('PW-2', 'Done'), jiraId());
      expect(answer.status).toBe(200);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('every console refusal of a rule has its own message, and rules show on the card until they are removed', async ({ page }) => {
    test.setTimeout(300_000);
    const project = uniqueName('wr');
    const other = uniqueName('wo');
    const refuse = (args: string[], message: string): void => {
      const result = runConsole(['fleet:webhook:rule:add', project, ...args]);
      expect(result.status, `${args.join(' ')}: ${result.text}`).not.toBe(0);
      expect(result.text, args.join(' ')).toContain(message);
    };
    try {
      await createProjectViaUi(page, project);
      await createProjectViaUi(page, other);
      prepareDeploy(project);

      // No receiver yet: nothing to add a rule to.
      refuse(['--source=jira', '--on-status=Dispatched', '--template=web'], `${project} has no Jira webhook yet: create its secret first.`);
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--action=run-pipeline', '--pattern=x'], `${project} has no Bitbucket webhook yet: create its secret first.`);
      await createSecret(page, 'jira', project);
      await createSecret(page, 'bitbucket', project);

      // Jira rules.
      refuse(['--source=jira', '--action=run-pipeline', '--on-status=Dispatched', '--template=web'], 'A Jira rule can only deploy.');
      refuse(['--source=jira', '--template=web'], 'Enter the Jira status the issue moves into (at most 100 characters).');
      refuse(['--source=jira', `--on-status=${'s'.repeat(101)}`, '--template=web'], 'Enter the Jira status the issue moves into (at most 100 characters).');
      refuse(['--source=jira', '--on-status=Dispatched'], "Choose one of the project's templates.");
      refuse(['--source=jira', '--on-status=Dispatched', '--template=nope'], "Choose one of the project's templates.");
      refuse(['--source=jira', '--on-status=Dispatched', '--template=web', '--branch=-bad'], 'Enter a valid branch name.');
      refuse(['--source=jira', '--on-status=Dispatched', '--template=web', '--label=fixed'], 'Use lowercase letters, digits and single dashes, at most 40 characters.');
      refuse(['--source=jira', '--on-status=Dispatched', '--template=web', '--label-prefix=fb'], 'A Jira rule names its instance after the issue key.');
      const dispatched = consoleJson<{ id: number }>(['fleet:webhook:rule:add', project, '--source=jira', '--on-status=Dispatched', '--template=web', '--branch=develop']);
      refuse(['--source=jira', '--on-status=dispatched', '--template=web'], 'There is already a rule for this status.');
      const review = consoleJson<{ id: number }>(['fleet:webhook:rule:add', project, '--source=jira', '--on-status=For review', '--template=web']);

      // Bitbucket rules.
      refuse(['--source=bitbucket', '--repo=acme/blog', '--action=run-pipeline', '--pattern=x'], 'Enter a Bitbucket event key such as pullrequest:approved or repo:push.');
      refuse(['--source=bitbucket', '--on-event=push', '--repo=acme/blog', '--action=run-pipeline', '--pattern=x'], 'Enter a Bitbucket event key such as pullrequest:approved or repo:push.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--action=run-pipeline', '--pattern=x'], 'Enter the repository as workspace/slug.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme', '--action=run-pipeline', '--pattern=x'], 'Enter the repository as workspace/slug.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=a/b/c', '--action=run-pipeline', '--pattern=x'], 'Enter the repository as workspace/slug.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--match-branch=bad branch!', '--action=run-pipeline', '--pattern=x'], 'Use letters, digits, dot, underscore, dash, slash and the wildcards * and ?.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--state=1', '--action=run-pipeline', '--pattern=x'], 'Enter a commit status state such as SUCCESSFUL.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', `--comment=${'c'.repeat(201)}`, '--action=run-pipeline', '--pattern=x'], 'Enter the comment line to look for (one line, at most 200 characters).');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--action=run-pipeline'], 'Enter the name of the custom pipeline.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--action=run-pipeline', '--pattern=two words'], 'Enter the name of the custom pipeline.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--action=run-pipeline', '--pattern=x', '--ref=-bad'], 'Enter a valid branch name.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--action=teleport'], 'The action is deploy or run-pipeline.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--template=web'], 'A deploy rule needs the branch glob to deploy.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--match-branch=feature/*', '--template=nope'], "Choose one of the project's templates.");
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--match-branch=feature/*', '--template=web', '--label=Fixed'], 'Use lowercase letters, digits and single dashes, at most 40 characters.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--match-branch=feature/*', '--template=web', '--label=fixed', '--label-prefix=fb'], 'A fixed label cannot be combined with a label prefix or a suffix to strip.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--match-branch=feature/*', '--template=web', '--label-prefix=-fb'], 'Use lowercase letters, digits and dashes, at most 20 characters.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--match-branch=feature/*', '--template=web', '--strip-suffix=-x'], 'A suffix to strip needs a label prefix too.');
      refuse(['--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--match-branch=feature/*', '--template=web', '--label-prefix=fb', '--strip-suffix=-x'], 'The branch glob must end with the suffix to strip.');
      consoleOk(['fleet:webhook:rule:add', project, '--source=bitbucket', '--on-event=pullrequest:approved', '--repo=acme/blog', '--action=run-pipeline', '--pattern=renovate-merge', '--ref=develop']);
      consoleOk(['fleet:webhook:rule:add', project, '--source=bitbucket', '--on-event=repo:push', '--repo=acme/blog', '--match-branch=feature/*-wip', '--template=web', '--label-prefix=fb', '--strip-suffix=-wip']);

      // The cards list the rules in order, with the template or pipeline each one runs.
      await page.goto(`/admin/projects/${project}/webhooks`);
      const jiraRules = endpoint(page, 'jira', project).getByTestId('webhook-rules').locator('li');
      await expect(jiraRules).toHaveCount(2);
      await expect(jiraRules.nth(0)).toContainText('on_status=Dispatched');
      await expect(jiraRules.nth(0)).toContainText('→ deploy');
      await expect(jiraRules.nth(0)).toContainText('web');
      await expect(jiraRules.nth(1)).toContainText('on_status=For review');
      const bitbucketRules = endpoint(page, 'bitbucket', project).getByTestId('webhook-rules').locator('li');
      await expect(bitbucketRules).toHaveCount(2);
      await expect(bitbucketRules.nth(0)).toContainText('on_event=pullrequest:approved');
      await expect(bitbucketRules.nth(0)).toContainText('→ run-pipeline');
      await expect(bitbucketRules.nth(0)).toContainText('renovate-merge');
      await expect(bitbucketRules.nth(1)).toContainText('branch=feature/*-wip');
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-no-rules')).toHaveCount(0);
      await expectClean(page, 'the cards with rules');
      // The overview shows the same rules.
      await page.goto('/admin/webhooks');
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-rules').locator('li')).toHaveCount(2);

      // Removing: the rule leaves its card; a second removal and another project's id are refused.
      consoleOk(['fleet:webhook:rule:remove', project, String(dispatched.id)]);
      const again = runConsole(['fleet:webhook:rule:remove', project, String(dispatched.id)]);
      expect(again.status).not.toBe(0);
      expect(again.text).toContain(`Rule ${dispatched.id} does not exist in project ${project}.`);
      const foreign = runConsole(['fleet:webhook:rule:remove', other, String(review.id)]);
      expect(foreign.status).not.toBe(0);
      expect(foreign.text).toContain(`Rule ${review.id} does not exist in project ${other}.`);
      await page.goto(`/admin/projects/${project}/webhooks`);
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-rules').locator('li')).toHaveCount(1);
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-rules')).not.toContainText('Dispatched');
      consoleOk(['fleet:webhook:rule:remove', project, String(review.id)]);
      await page.reload();
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-rules')).toHaveCount(0);
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-no-rules')).toContainText('No rules yet');
      await expectClean(page, 'the card after the rules were removed');
    } finally {
      await deleteProjectViaUi(page, project);
      await deleteProjectViaUi(page, other);
    }
  });

  test('every outcome of a delivery is in the log with its reason, and the receiver refuses what it should', async ({ page }) => {
    test.setTimeout(300_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName('wl');
    const bare = uniqueName('wb');
    let deployed = '';
    try {
      await createProjectViaUi(page, project);
      await createProjectViaUi(page, bare);
      prepareDeploy(project);
      const jira = await createSecret(page, 'jira', project);
      const bitbucket = await createSecret(page, 'bitbucket', project);
      consoleOk(['fleet:webhook:rule:add', project, '--source=jira', '--on-status=Dispatched', '--template=web']);
      consoleOk(['fleet:webhook:rule:add', project, '--source=bitbucket', '--on-event=pullrequest:approved', '--repo=acme/blog', '--action=run-pipeline', '--pattern=renovate-merge', '--ref=develop']);
      const bareSecret = await createSecret(page, 'jira', bare);

      // Accepted: a deploy is queued and the row says which job.
      const id = jiraId();
      const accepted = await deliver(page, 'jira', project, jira, jiraMove('PW-10', 'Dispatched'), id);
      expect(accepted.status).toBe(200);
      expect(accepted.body.result).toBe('accepted');
      expect(accepted.body.performed).toBe(true);
      expect(String(accepted.body.note)).toMatch(/^deploy queued: .+ \(job \d+\)$/);
      const jobId = Number(accepted.body.job);
      deployed = String(accepted.body.instance);
      expect(jobId).toBeGreaterThan(0);
      // The same delivery id again, and the same body under another id: duplicates, nothing queued twice.
      const sameId = await deliver(page, 'jira', project, jira, jiraMove('PW-10', 'Dispatched'), id);
      expect(sameId.body.result).toBe('duplicate');
      const sameBody = await deliver(page, 'jira', project, jira, jiraMove('PW-10', 'Dispatched'), jiraId());
      expect(sameBody.body.result).toBe('duplicate');
      // Ignored, with three different reasons.
      expect((await deliver(page, 'jira', project, jira, jiraMove('PW-11', 'Done'), jiraId())).body).toMatchObject({ result: 'ignored', reason: 'no rule for this status' });
      expect((await deliver(page, 'jira', project, jira, { webhookEvent: 'jira:issue_created', issue: { key: 'PW-12' } }, jiraId())).body).toMatchObject({ result: 'ignored', reason: 'event is not an issue update' });
      expect((await deliver(page, 'jira', project, jira, { webhookEvent: 'jira:issue_updated', issue: { key: 'PW-13' }, changelog: { items: [{ field: 'summary', fromString: 'a', toString: 'b' }] } }, jiraId())).body).toMatchObject({ result: 'ignored', reason: 'no status change' });
      // Failed: bad bodies are answered 400 and logged.
      expect(await deliver(page, 'jira', project, jira, '[1,2]', jiraId())).toMatchObject({ status: 400 });
      expect(await deliver(page, 'jira', project, jira, 'not json', jiraId())).toMatchObject({ status: 400 });
      expect(await deliver(page, 'jira', project, jira, { webhookEvent: 'jira:issue_updated' }, jiraId())).toMatchObject({ status: 400 });
      expect(await deliver(page, 'bitbucket', project, bitbucket, { repository: { full_name: 'acme/blog' } })).toMatchObject({ status: 400 });
      // Failed: a rule that matches but cannot act (no pipeline token).
      const failed = await deliver(page, 'bitbucket', project, bitbucket, { repository: { full_name: 'acme/blog' } }, bitbucketId('pullrequest:approved'));
      expect(failed.body.result).toBe('failed');
      expect(String(failed.body.note)).toContain('no pipeline token');
      // Ignored (Bitbucket): another repository, and an event no rule wants.
      expect((await deliver(page, 'bitbucket', project, bitbucket, { repository: { full_name: 'acme/other' } }, bitbucketId('pullrequest:approved'))).body).toMatchObject({ result: 'ignored' });
      expect((await deliver(page, 'bitbucket', project, bitbucket, { repository: { full_name: 'acme/blog' } }, bitbucketId('repo:fork'))).body).toMatchObject({ result: 'ignored' });

      // Refused before anything is logged.
      expect((await deliver(page, 'jira', project, jira, jiraMove('PW-14', 'Dispatched'), jiraId(), 'the-wrong-secret')).status).toBe(401);
      const unsigned = await page.request.post(`/hooks/jira/${project}`, { data: '{}', headers: { 'Content-Type': 'application/json' } });
      expect(unsigned.status()).toBe(401);
      expect((await deliver(page, 'jira', 'no-such-project', jira, {}, jiraId())).status).toBe(404);
      expect((await deliver(page, 'bitbucket', bare, bareSecret, {}, bitbucketId('repo:push'))).status).toBe(404);
      expect((await page.request.post(`/hooks/gitlab/${project}`, { data: '{}' })).status()).toBe(404);
      expect((await page.request.get(`/hooks/jira/${project}`)).status()).toBe(405);
      const huge = JSON.stringify({ padding: 'x'.repeat(1_048_577) });
      expect((await deliver(page, 'jira', project, jira, huge, jiraId())).status).toBe(413);

      // The tab: the rows, newest first, with their results and reasons.
      await page.goto(`/admin/projects/${project}/webhooks`);
      const rows = page.getByTestId('delivery-row');
      const count = (result: string): Locator => page.locator(`[data-testid=delivery-row][data-result=${result}]`);
      await expect(count('accepted')).toHaveCount(1);
      await expect(count('duplicate')).toHaveCount(2);
      await expect(count('ignored')).toHaveCount(5);
      await expect(count('failed')).toHaveCount(5);
      await expect(rows).toHaveCount(13);
      const acceptedRow = count('accepted');
      await expect(acceptedRow).toContainText('PW-10');
      await expect(acceptedRow).toContainText('To Do -> Dispatched');
      await expect(acceptedRow.getByTestId('delivery-job').first()).toHaveText(`job #${jobId}`);
      if (!isPhone()) {
        await expect(acceptedRow.getByTestId('delivery-decision')).not.toBeEmpty();
        await expect(acceptedRow.getByTestId('delivery-outcome')).toContainText(`job #${jobId}`);
        await expect(acceptedRow.getByTestId('delivery-outcome')).toContainText('deploy queued');
        await expect(count('duplicate').first().getByTestId('delivery-outcome')).toContainText(/this (delivery id|body) was already handled/);
        await expect(page.locator('[data-testid=delivery-row][data-result=ignored]').getByTestId('delivery-outcome').filter({ hasText: 'no status change' })).toHaveCount(1);
        await expect(count('failed').getByTestId('delivery-outcome').filter({ hasText: 'the body is not a JSON object' })).toHaveCount(2);
        await expect(count('failed').getByTestId('delivery-outcome').filter({ hasText: 'The event has no issue key.' })).toHaveCount(1);
        await expect(count('failed').getByTestId('delivery-outcome').filter({ hasText: 'The X-Event-Key header is missing.' })).toHaveCount(1);
        await expect(count('failed').getByTestId('delivery-outcome').filter({ hasText: 'no pipeline token' })).toHaveCount(1);
      } else {
        await expect(count('failed').getByTestId('delivery-outcome-phone').filter({ hasText: 'no pipeline token' })).toHaveCount(1);
        await expect(count('ignored').getByTestId('delivery-outcome-phone').filter({ hasText: 'no rule for this status' })).toHaveCount(1);
      }
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-latest')).toContainText(/^Last delivery \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC: \w+\.$/);
      await expectClean(page, 'the log with every outcome');

      // The job the accepted delivery queued exists.
      expect((await page.goto(`/jobs/${jobId}`))?.status()).toBe(200);

      // Filters on the tab: each outcome, a source, both, and the way back.
      await page.goto(`/admin/projects/${project}/webhooks`);
      for (const [result, expected] of [['accepted', 1], ['duplicate', 2], ['ignored', 5], ['failed', 5]] as const) {
        await page.getByLabel('Outcome').selectOption(result);
        await follow(page, page.getByTestId('delivery-filter-apply'));
        await expect(rows, result).toHaveCount(expected);
        await expect(page.locator(`[data-testid=delivery-row]:not([data-result=${result}])`)).toHaveCount(0);
        await expect(page.getByLabel('Outcome')).toHaveValue(result);
      }
      await page.getByLabel('Outcome').selectOption('');
      await page.getByLabel('Source').selectOption('bitbucket');
      await follow(page, page.getByTestId('delivery-filter-apply'));
      await expect(rows).toHaveCount(4);
      await expect(page.getByLabel('Source')).toHaveValue('bitbucket');
      await page.getByLabel('Outcome').selectOption('accepted');
      await follow(page, page.getByTestId('delivery-filter-apply'));
      await expect(rows).toHaveCount(0);
      await expect(page.getByTestId('deliveries-empty')).toBeVisible();
      await expectClean(page, 'the filtered empty log');
      await follow(page, page.getByTestId('delivery-filter-clear'));
      await expect(rows).toHaveCount(13);
      await expect(page.getByLabel('Source')).toHaveValue('');
      await expect(page.getByLabel('Outcome')).toHaveValue('');
      if (isPhone()) {
        await expectTall(page.getByLabel('Source'), 'Source filter');
        await expectTall(page.getByLabel('Outcome'), 'Outcome filter');
        await expectTall(page.getByTestId('delivery-filter-apply'), 'Filter');
      }

      // Unknown filter values are no filter. Hide ignored keeps the other outcomes and survives a filter.
      await page.goto(`/admin/projects/${project}/webhooks?source=gitlab&result=bogus`);
      await expect(rows).toHaveCount(13);
      await expect(page.getByLabel('Source')).toHaveValue('');
      await follow(page, page.getByTestId('webhook-filter'));
      await expect(page.locator('[data-testid=delivery-row][data-result=ignored]')).toHaveCount(0);
      await expect(rows).toHaveCount(8);
      await page.getByLabel('Source').selectOption('jira');
      await follow(page, page.getByTestId('delivery-filter-apply'));
      await expect(page).toHaveURL(/hide-ignored=1/);
      await expect(page.getByTestId('webhook-filter')).toHaveText('Show ignored');
      await expect(rows).toHaveCount(6);
      await follow(page, page.getByTestId('delivery-filter-clear'));
      await expect(page).toHaveURL(/hide-ignored=1/);
      await expect(rows).toHaveCount(8);

      // The overview: the same rows with the project column, filters through the query, no paging.
      await page.goto(`/admin/webhooks?source=jira&result=accepted`);
      // Every project's deliveries are on this page: only this test's own row is counted.
      await expect(page.locator(`[data-testid=delivery-row][data-result=accepted]`).filter({ hasText: project }).filter({ hasText: 'PW-10' })).toHaveCount(1);
      await expect(page.locator('[data-testid=delivery-row]:not([data-result=accepted])')).toHaveCount(0);
      await expect(page.getByLabel('Source')).toHaveValue('jira');
      await expect(page.getByLabel('Outcome')).toHaveValue('accepted');
      await follow(page, page.getByTestId('delivery-filter-clear'));
      await expect(page.getByRole('navigation', { name: 'Delivery pages' })).toHaveCount(0);
      await expectClean(page, 'the overview log');
      expect(errors.filter((e) => !/status of (4\d\d)/.test(e))).toEqual([]);
    } finally {
      // The accepted delivery queued a deploy, which made an instance row; a project with an instance cannot be deleted.
      if (deployed) {
        instanceRow('delete', project, deployed.slice(project.length + 2));
      }
      await deleteProjectViaUi(page, project);
      await deleteProjectViaUi(page, bare);
    }
  });

  test('the log is paged fifty to a page and the overview shows the latest twenty-five', async ({ page }) => {
    test.setTimeout(300_000);
    const project = uniqueName('wp');
    try {
      await createProjectViaUi(page, project);
      const secret = await createSecret(page, 'jira', project);
      for (let i = 0; i < 53; i++) {
        const sent = await deliver(page, 'jira', project, secret, { webhookEvent: 'jira:issue_created', issue: { key: `PG-${i}` } }, jiraId());
        expect(sent.status).toBe(200);
      }
      const rows = page.getByTestId('delivery-row');
      const pager = page.getByRole('navigation', { name: 'Delivery pages' });

      await page.goto(`/admin/projects/${project}/webhooks`);
      await expect(rows).toHaveCount(50);
      await expect(pager.getByRole('link', { name: 'Newer' })).toHaveCount(0);
      const seen = new Set<string>();
      for (const text of await rows.allInnerTexts()) {
        seen.add(/PG-\d+/.exec(text)![0]);
      }
      await follow(page, pager.getByRole('link', { name: 'Older' }));
      await expect(page).toHaveURL(/page=2/);
      await expect(rows).toHaveCount(3);
      await expect(pager.getByRole('link', { name: 'Older' })).toHaveCount(0);
      for (const text of await rows.allInnerTexts()) {
        seen.add(/PG-\d+/.exec(text)![0]);
      }
      // Fifty and three rows, no row on both pages and none lost.
      expect(seen.size).toBe(53);
      if (isPhone()) {
        await expectTall(pager.getByRole('link', { name: 'Newer' }), 'Newer');
      }
      await expectClean(page, 'page two of the log');
      await follow(page, pager.getByRole('link', { name: 'Newer' }));
      await expect(rows).toHaveCount(50);

      // The pager keeps a filter; a page number out of range is the first page or an empty one.
      await page.goto(`/admin/projects/${project}/webhooks?result=ignored&page=2`);
      await expect(rows).toHaveCount(3);
      await expect(pager.getByRole('link', { name: 'Newer' })).toHaveAttribute('href', /result=ignored/);
      await page.goto(`/admin/projects/${project}/webhooks?page=0`);
      await expect(rows).toHaveCount(50);
      await page.goto(`/admin/projects/${project}/webhooks?page=-4`);
      await expect(rows).toHaveCount(50);
      await page.goto(`/admin/projects/${project}/webhooks?page=99`);
      await expect(rows).toHaveCount(0);
      await expect(page.getByTestId('deliveries-empty')).toBeVisible();
      await expect(pager.getByRole('link', { name: 'Newer' })).toBeVisible();

      // The overview has no pages: the latest twenty-five of everything.
      await page.goto('/admin/webhooks');
      expect(await rows.count()).toBeLessThanOrEqual(25);
      await expect(rows.first()).toBeVisible();
      await expect(pager).toHaveCount(0);
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the pipeline token refuses a bad value, can be cancelled, replaced and removed, and is never shown', async ({ page }) => {
    test.setTimeout(240_000);
    const errors = collectBrowserErrors(page);
    const project = uniqueName('wt');
    try {
      await createProjectViaUi(page, project);
      await createSecret(page, 'bitbucket', project);
      await page.goto(`/admin/projects/${project}/webhooks`);
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

      // Refusals: a blank, an inner space, a non-ASCII character. The message never echoes the value. (A 2049th character
      // cannot be typed: the field stops at its maxlength.)
      for (const bad of ['   ', 'two words', 'tökën']) {
        await save(bad);
        await expect(page.getByTestId('flashes'), `token ${bad.slice(0, 12)}`).toContainText(TOKEN_MESSAGE);
        expect(await page.content()).not.toContain('two words');
        await expect(state).toContainText('No pipeline token');
        await expect(card.getByTestId('webhook-token-clear')).toHaveCount(0);
      }
      await expectClean(page, 'the refused token');

      // The longest accepted token (2048 characters) is saved, replaced and removed; the page never shows it.
      await save('A'.repeat(2048));
      await expect(page.getByTestId('flashes')).toContainText('The pipeline token is saved. It cannot be shown again.');
      await expect(state).toContainText(/Pipeline token set \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC\. It cannot be shown again\./);
      await expect(card.getByText('Replace the pipeline token')).toBeVisible();
      expect(await page.content()).not.toContain('AAAAAAAAAAAAAAAA');
      await expect(card.getByTestId('webhook-token-input')).not.toHaveValue('A'.repeat(2048));
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

  test('every change needs the CSRF token, a token needs a Bitbucket receiver, and members are kept out', async ({ page, context, baseURL }) => {
    test.setTimeout(180_000);
    const project = uniqueName('wg');
    try {
      await createProjectViaUi(page, project);
      await createSecret(page, 'jira', project);
      await page.goto(`/admin/projects/${project}/webhooks`);

      // Without the CSRF token: refused, nothing changes.
      expect((await page.request.post(`/admin/projects/${project}/webhooks/bitbucket/secret`, { form: {} })).status()).toBe(403);
      expect((await page.request.post(`/admin/projects/${project}/webhooks/jira/secret`, { form: { rotate: '1' } })).status()).toBe(403);
      expect((await page.request.post(`/admin/projects/${project}/webhooks/bitbucket/token`, { form: { token: 'abc' } })).status()).toBe(403);
      expect((await page.request.post(`/admin/projects/${project}/webhooks/bitbucket/token`, { form: { _token: 'forged', token: 'abc' } })).status()).toBe(403);
      await page.goto(`/admin/projects/${project}/webhooks`);
      await expect(endpoint(page, 'bitbucket', project).getByTestId('webhook-secret-state')).toHaveText('No secret');

      // A token for a project that has no Bitbucket receiver is refused with a message. The CSRF token is made by the
      // page's script on submit, so the request is sent through one of the page's own forms.
      await page.evaluate((action) => {
        const form = document.querySelector<HTMLFormElement>('form[data-turbo=false]')!;
        form.action = action;
        form.querySelector('input[name=rotate]')?.remove();
        const token = document.createElement('input');
        token.name = 'token';
        token.value = 'ATCTT-x';
        form.append(token);
        form.requestSubmit();
      }, `/admin/projects/${project}/webhooks/bitbucket/token`);
      await expect(page.getByTestId('flashes')).toContainText(`${project} has no Bitbucket webhook yet: create its secret first.`);
      await expectClean(page, 'the refused token');

      // An unknown project has no receivers at all.
      expect((await page.request.post('/admin/projects/no-such-project/webhooks/jira/secret', { form: {} })).status()).toBe(404);
      expect((await page.request.post('/admin/projects/no-such-project/webhooks/bitbucket/token', { form: { token: 'x' } })).status()).toBe(404);

      // A member may not read or change any of it.
      await setStandInRole(context, 'member', baseURL!);
      for (const path of ['/admin/webhooks', `/admin/projects/${project}/webhooks`]) {
        expect((await page.goto(path))?.status(), path).toBe(403);
      }
      expect((await page.request.post(`/admin/projects/${project}/webhooks/bitbucket/secret`, { form: {} })).status()).toBe(403);
      expect((await page.request.post(`/admin/projects/${project}/webhooks/bitbucket/token`, { form: { token: 'x' } })).status()).toBe(403);
      await setStandInRole(context, 'admin', baseURL!);
      await page.goto(`/admin/projects/${project}/webhooks`);
      await expect(endpoint(page, 'bitbucket', project).getByTestId('webhook-secret-state')).toHaveText('No secret');
    } finally {
      await setStandInRole(context, 'admin', baseURL!);
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

      for (const path of [`/admin/projects/${project}/webhooks`, '/admin/webhooks']) {
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