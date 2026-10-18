/** The Webhooks screen in a real browser (FLE-30): real htmx, real CSP, seeded delivery logs. */
import { appendFileSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import type { Daemon } from './support/fixtures';
import { expect, test } from './support/fixtures';

const INSTANCE = 'demo--fle-30';

function logDir(daemon: Daemon): string {
  return join(daemon.home, 'logs', 'webhooks');
}

function seed(daemon: Daemon, file: string, entries: object[]) {
  mkdirSync(logDir(daemon), { recursive: true });
  appendFileSync(join(logDir(daemon), file), entries.map((e) => JSON.stringify(e) + '\n').join(''));
}

test.beforeEach(async ({ daemon }) => {
  // The daemon (and its home) is shared by all tests: start from empty logs and no instance.
  rmSync(logDir(daemon), { recursive: true, force: true });
  rmSync(join(daemon.home, 'instances', INSTANCE), { recursive: true, force: true });
  mkdirSync(join(daemon.home, 'instances', INSTANCE), { recursive: true });
  seed(daemon, 'jira.jsonl', [
    {
      ts: '2026-10-18T09:00:00+00:00',
      project: 'demo',
      delivery_id: 'jira-delivery-e2e',
      event: 'jira:issue_updated',
      issue: 'FLE-30',
      from: 'To Do',
      to: 'Dispatched',
      result: 'accepted',
      reason: null,
      instance: INSTANCE,
      job: 'abc123def456',
      action: 'deploy',
      template: 'jira-work',
      label: 'FLE-30',
      rule: 'on_status=Dispatched',
    },
  ]);
  seed(daemon, 'bitbucket.jsonl', [
    {
      ts: '2026-10-18T10:00:00+00:00',
      project: 'demo',
      delivery_id: 'bb-delivery-e2e',
      event: 'repo:push',
      branch: 'feature/x--pw-run',
      result: 'ignored',
      reason: 'no matching rule',
      action: 'deploy',
      template: 'ci-pw',
      rule: 'on_event=repo:push branch=*--pw-run',
    },
  ]);
});

test.afterEach(async ({ daemon }) => {
  rmSync(logDir(daemon), { recursive: true, force: true });
  rmSync(join(daemon.home, 'instances', INSTANCE), { recursive: true, force: true });
});

test('the page loads under the CSP with the nav link and both sources, newest first', async ({
  page,
  daemon,
}) => {
  const response = await page.goto(daemon.url + '/webhooks');
  expect(response?.headers()['content-security-policy']).toContain("script-src 'self'");

  const nav = page.locator('nav.main-nav a');
  await expect(nav).toHaveText(['Instances', 'Secrets', 'Webhooks', 'Scheduled jobs']);
  await expect(page.getByRole('heading', { name: 'Webhooks' })).toBeVisible();

  const rows = page.locator('tr.webhook-row');
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0)).toContainText('repo:push');
  await expect(rows.nth(0)).toContainText('bitbucket');
  await expect(rows.nth(0)).toContainText('bb-delivery-e2e');
  await expect(rows.nth(1)).toContainText('FLE-30');
  await expect(rows.nth(1)).toContainText('To Do → Dispatched');
  await expect(rows.nth(1)).toContainText('on_status=Dispatched');
  await expect(rows.nth(1)).toContainText('deploy jira-work');
  await expect(rows.nth(0)).toContainText('on_event=repo:push branch=*--pw-run');
  // The deployed instance exists, so it is a link to its URL.
  await expect(rows.nth(1).getByRole('link', { name: INSTANCE })).toHaveAttribute(
    'href',
    `https://${INSTANCE}.fleet.example.test`,
  );
});

test('the nav link opens the page from the dashboard', async ({ page, daemon }) => {
  await page.goto(daemon.url + '/');
  await page.getByRole('link', { name: 'Webhooks' }).click();
  await expect(page).toHaveURL(/\/webhooks$/);
  await expect(page.locator('tr.webhook-row')).toHaveCount(2);
});

test('a filter narrows the rows and refresh keeps it', async ({ page, daemon }) => {
  await page.goto(daemon.url + '/webhooks');
  const rows = page.locator('tr.webhook-row');
  await expect(rows).toHaveCount(2);

  const partial = page.waitForResponse((r) => r.url().includes('/ui/webhooks/table'));
  await page.locator('select[name=source]').selectOption('jira');
  expect((await partial).url()).toContain('source=jira');
  await expect(rows).toHaveCount(1);
  await expect(rows.first()).toContainText('FLE-30');
  await expect(page.locator('select[name=source]')).toHaveValue('jira'); // the form is not re-rendered

  // A new delivery arrives; Refresh re-fetches the table with the current filter.
  seed(daemon, 'jira.jsonl', [
    { ts: '2026-10-18T12:00:00+00:00', project: 'demo', event: 'jira:issue_updated', issue: 'FLE-31', result: 'ignored' },
  ]);
  seed(daemon, 'bitbucket.jsonl', [
    { ts: '2026-10-18T13:00:00+00:00', project: 'demo', event: 'repo:push', branch: 'late', result: 'ignored' },
  ]);
  await page.getByRole('button', { name: 'Refresh' }).click();
  await expect(rows).toHaveCount(2);
  await expect(rows.first()).toContainText('FLE-31');
  await expect(page.locator('tr.webhook-row', { hasText: 'late' })).toHaveCount(0);

  await page.locator('select[name=source]').selectOption('');
  await expect(rows).toHaveCount(4);
});

test('Hide ignored hooks is unchecked by default, hides ignored rows and survives Refresh', async ({
  page,
  daemon,
}) => {
  await page.goto(daemon.url + '/webhooks');
  const rows = page.locator('tr.webhook-row');
  const hide = page.getByLabel('Hide ignored hooks');
  await expect(hide).not.toBeChecked();
  await expect(rows).toHaveCount(2);
  await expect(page.locator('tr.webhook-row', { hasText: 'bb-delivery-e2e' })).toHaveCount(1);

  const partial = page.waitForResponse((r) => r.url().includes('/ui/webhooks/table'));
  await hide.check();
  expect((await partial).url()).toContain('hide_ignored=1');
  await expect(rows).toHaveCount(1);
  await expect(rows.first()).toContainText('FLE-30');
  await expect(page.locator('tr.webhook-row', { hasText: 'bb-delivery-e2e' })).toHaveCount(0);
  await expect(hide).toBeChecked();

  // Refresh keeps the box: a new ignored delivery stays hidden, a new accepted one shows.
  seed(daemon, 'bitbucket.jsonl', [
    { ts: '2026-10-18T13:00:00+00:00', project: 'demo', event: 'repo:push', branch: 'late', result: 'ignored' },
    { ts: '2026-10-18T14:00:00+00:00', project: 'demo', event: 'repo:push', branch: 'ontime', result: 'triggered' },
  ]);
  await page.getByRole('button', { name: 'Refresh' }).click();
  await expect(rows).toHaveCount(2);
  await expect(page.locator('tr.webhook-row', { hasText: 'late' })).toHaveCount(0);
  await expect(page.locator('tr.webhook-row', { hasText: 'ontime' })).toHaveCount(1);

  await hide.uncheck();
  await expect(rows).toHaveCount(4);
});

test('a filter with no match shows the empty state, not an error', async ({ page, daemon }) => {
  await page.goto(daemon.url + '/webhooks?project=ghost');
  await expect(page.locator('tr.webhook-row')).toHaveCount(0);
  await expect(page.locator('.webhook-empty')).toContainText('No delivery matches');
});

test('missing logs show the empty state', async ({ page, daemon }) => {
  rmSync(logDir(daemon), { recursive: true, force: true });
  await page.goto(daemon.url + '/webhooks');
  await expect(page.locator('.webhook-empty')).toContainText('No webhook delivery has been logged');
});
