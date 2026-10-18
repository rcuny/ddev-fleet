/** The Scheduled jobs screen in a real browser (FLE-31): real htmx, real CSP, seeded state. */
import { appendFileSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import type { Daemon } from './support/fixtures';
import { expect, test } from './support/fixtures';

const INSTANCE = 'demo--e2e-sched';

function schedDir(daemon: Daemon): string {
  return join(daemon.home, 'schedules');
}

function instanceDir(daemon: Daemon): string {
  return join(daemon.home, 'instances', INSTANCE);
}

function seedHistory(daemon: Daemon, entries: object[]) {
  mkdirSync(schedDir(daemon), { recursive: true });
  appendFileSync(
    join(schedDir(daemon), 'history.jsonl'),
    entries.map((e) => JSON.stringify(e) + '\n').join(''),
  );
}

test.beforeEach(async ({ daemon }) => {
  // The daemon (and its home) is shared by all tests: start from no state and no instance.
  rmSync(schedDir(daemon), { recursive: true, force: true });
  rmSync(instanceDir(daemon), { recursive: true, force: true });
});

test.afterEach(async ({ daemon }) => {
  rmSync(schedDir(daemon), { recursive: true, force: true });
  rmSync(instanceDir(daemon), { recursive: true, force: true });
});

test('the page loads under the CSP with the nav link and the seeded schedules', async ({
  page,
  daemon,
}) => {
  seedHistory(daemon, [
    {
      schedule: 'e2e-nightly',
      ts: '2026-10-18T01:00:00Z',
      trigger: 'cron',
      instance: INSTANCE,
      result: 'succeeded',
      reason: null,
      job_id: 'abc123def456',
    },
  ]);
  const response = await page.goto(daemon.url + '/schedules');
  expect(response?.headers()['content-security-policy']).toContain("script-src 'self'");

  await expect(page.locator('nav.main-nav a')).toHaveText([
    'Instances',
    'Secrets',
    'Webhooks',
    'Scheduled jobs',
  ]);
  await expect(page.getByRole('heading', { name: 'Scheduled jobs' })).toBeVisible();

  const rows = page.locator('tr.schedule-row');
  await expect(rows).toHaveCount(3);
  const nightly = page.locator('#schedule-e2e-nightly');
  await expect(nightly).toContainText('0 3 * * *');
  await expect(nightly).toContainText('Europe/Paris');
  await expect(nightly).toContainText('redeploy');
  await expect(nightly).toContainText(INSTANCE);
  await expect(nightly.locator('.schedule-state')).toHaveText('active');
  await expect(nightly).toContainText(/03:00 CES?T/); // next run, in the schedule's timezone
  await expect(page.locator('#schedule-e2e-disabled .schedule-state')).toHaveText(
    'disabled in fleet.yml',
  );
  const elsewhere = page.locator('#schedule-e2e-elsewhere');
  await expect(elsewhere.locator('.schedule-state')).toHaveText('not on this server');
  await expect(elsewhere.getByRole('button')).toHaveCount(0);

  const history = page.locator('tr.schedule-history-row');
  await expect(history).toHaveCount(1);
  await expect(history.first()).toContainText('2026-10-18 01:00:00 UTC');
  await expect(history.first()).toContainText('abc123def456');
});

test('the nav link opens the page from the dashboard', async ({ page, daemon }) => {
  await page.goto(daemon.url + '/');
  await page.getByRole('link', { name: 'Scheduled jobs' }).click();
  await expect(page).toHaveURL(/\/schedules$/);
  await expect(page.locator('tr.schedule-row')).toHaveCount(3);
});

test('pause and resume toggle the state', async ({ page, daemon }) => {
  await page.goto(daemon.url + '/schedules');
  const nightly = page.locator('#schedule-e2e-nightly');
  await expect(nightly.locator('.schedule-state')).toHaveText('active');

  await nightly.getByRole('button', { name: 'Pause' }).click();
  await expect(page.locator('.schedule-notice')).toContainText('Paused e2e-nightly.');
  await expect(nightly.locator('.schedule-state')).toHaveText('paused');
  await expect(nightly.getByRole('button', { name: 'Resume' })).toBeVisible();
  await expect(nightly.getByRole('button', { name: 'Pause' })).toHaveCount(0);

  // the state survives a reload (it is on disk)
  await page.reload();
  await expect(page.locator('#schedule-e2e-nightly .schedule-state')).toHaveText('paused');

  await page.locator('#schedule-e2e-nightly').getByRole('button', { name: 'Resume' }).click();
  await expect(page.locator('.schedule-notice')).toContainText('Resumed e2e-nightly.');
  await expect(page.locator('#schedule-e2e-nightly .schedule-state')).toHaveText('active');
});

test('run now on a missing instance records a failure and keeps the page working', async ({
  page,
  daemon,
}) => {
  await page.goto(daemon.url + '/schedules');
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('#schedule-e2e-nightly').getByRole('button', { name: 'Run now' }).click();

  await expect(page.locator('.schedule-notice')).toContainText(`instance ${INSTANCE} not found`);
  const last = page.locator('#schedule-e2e-nightly .schedule-result');
  await expect(last).toHaveText('failed');
  const history = page.locator('tr.schedule-history-row');
  await expect(history).toHaveCount(1);
  await expect(history.first()).toContainText('manual-ui');
  await expect(history.first()).toContainText('failed');
});

test('run now submits a job whose end shows up on refresh', async ({ page, daemon }) => {
  // An instance directory without recorded deploy parameters: redeploy() refuses at once.
  mkdirSync(instanceDir(daemon), { recursive: true });
  await page.goto(daemon.url + '/schedules');
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('#schedule-e2e-nightly').getByRole('button', { name: 'Run now' }).click();
  await expect(page.locator('.schedule-notice')).toContainText(/Started e2e-nightly.*\(job \w+\)/);

  const last = page.locator('#schedule-e2e-nightly .schedule-result');
  await expect(async () => {
    await page.getByRole('button', { name: 'Refresh' }).click();
    await expect(last).toHaveText('failed', { timeout: 1_000 });
  }).toPass({ timeout: 10_000 });
  await expect(page.locator('#schedule-e2e-nightly')).toContainText('no recorded deploy parameters');
});

test('declining the confirmation runs nothing', async ({ page, daemon }) => {
  await page.goto(daemon.url + '/schedules');
  page.once('dialog', (dialog) => dialog.dismiss());
  await page.locator('#schedule-e2e-nightly').getByRole('button', { name: 'Run now' }).click();
  await expect(page.locator('.schedule-notice')).toHaveCount(0);
  await expect(page.locator('tr.schedule-history-row')).toHaveCount(0);
});

test('a corrupt state file shows a banner and no actions instead of an error page', async ({
  page,
  daemon,
}) => {
  mkdirSync(schedDir(daemon), { recursive: true });
  writeFileSync(join(schedDir(daemon), 'state.json'), '{ not json');
  const response = await page.goto(daemon.url + '/schedules');
  expect(response?.status()).toBe(200);
  const banner = page.locator('.schedule-state-error');
  await expect(banner).toContainText(join(schedDir(daemon), 'state.json'));
  await expect(banner).toContainText('Fix:');
  await expect(page.locator('tr.schedule-row')).toHaveCount(3);
  await expect(page.locator('#schedule-e2e-nightly .schedule-state')).toHaveText('state unreadable');
  await expect(page.locator('#schedule-e2e-nightly').getByRole('button')).toHaveCount(0);
});
