import { createHmac, randomBytes } from 'node:crypto';
import { type APIResponse, type Locator, type Page, expect } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { consoleJson, consoleOk, instanceRow, runConsole } from './support/fleetData';
import { createProjectViaUi, deleteProjectViaUi, follow, openDeliveryLog, openDialog, openSection, submitAndWait, uniqueName } from './support/projects';
import { removeSecret, revealSecret, saveSecret, secretRow, submitSecret, test as secretsTest, uniqueSecret, uniqueValue } from './support/secrets';
import {
  acquireAdminLock,
  createUserViaUi,
  deleteUserViaUi,
  ensureKeeperAdmin,
  KEEPER,
  setValue,
  tick,
  toggleProject,
} from './support/users';

// Axe sweep of the admin pages: users, secrets, schedules and webhooks. The feature specs of these pages no longer run
// NAMING: this file sorts after setup-wizard.desktop.spec.ts on purpose. Some states below create an enabled administrator in the
// shared app, and the wizard spec needs a host with none (SetupProgress::firstIncomplete): a name before it lets both run at once.
// axe; every page state they used to check (refused forms, dialogs, stored and warning states) is set up here, once,
// and checked in the light and the dark theme. Desktop only (`.desktop.spec.ts`): axe does not depend on the viewport
// and the phone project checked the same pages. Long-text variants of a state are not repeated: axe is text-length
// independent (the overflow checks stay in the feature specs). Every test owns unique records and removes them in
// `finally`, as the feature specs do.

const test = secretsTest.extend<{ adminLock: void }>({
  adminLock: [
    async ({}, use) => {
      const release = await acquireAdminLock();
      try {
        await use();
      } finally {
        release();
      }
    },
    { auto: false },
  ],
});

test.describe.configure({ timeout: 180_000 });

const clean = expectNoBlockingViolations;

// ---------------------------------------------------------------------------------------------------------------- users

test.describe('axe sweep: users', () => {
  const createButton = (page: Page): Locator => page.getByRole('button', { name: 'Create user' });
  const saveButton = (page: Page): Locator => page.getByRole('button', { name: 'Save', exact: true });

  test('the user list: regular, project, site-only and disabled users', async ({ page }) => {
    const project = uniqueName('prj');
    const names = [uniqueName('usr'), uniqueName('usr'), uniqueName('shd'), uniqueName('usr')];
    const [none, member, shield, off] = names;

    try {
      await createProjectViaUi(page, project);
      await createUserViaUi(page, { username: none, displayName: 'No projects' });
      await createUserViaUi(page, { username: member, displayName: 'One project', projects: [project] });
      await createUserViaUi(page, { username: shield, displayName: 'Shield', siteOnly: true, password: 'abcd', projects: [project] });
      await createUserViaUi(page, { username: off, displayName: 'Turned off', projects: [project] });
      await submitAndWait(page, page.getByRole('button', { name: 'Disable user' }));
      await page.goto('/admin/users');
      await expect(page.getByTestId('user-disabled')).not.toHaveCount(0);
      await clean(page, 'user list');
    } finally {
      for (const username of names) {
        await deleteUserViaUi(page, username);
      }
      await deleteProjectViaUi(page, project);
    }
  });

  test('the New user form: empty, refused, site-only, site-only refused and refused with long values', async ({ page }) => {
    const project = uniqueName('prj');
    const username = uniqueName('shd');

    try {
      await createProjectViaUi(page, project);
      await page.goto('/admin/users/new');
      await clean(page, 'empty new user form');

      await submitAndWait(page, createButton(page));
      await expect(page.locator('#user_create_username_errors')).toBeVisible();
      await clean(page, 'new user form with a username error');

      await tick(page, 'user_create', 'siteOnly');
      await expect(page.getByTestId('site-only-warning')).toBeVisible();
      await clean(page, 'new site-only user form');

      // Site-only with a 3-character password and a project ticked: refused, the form keeps its shape.
      await page.getByLabel('Username').fill(username);
      await page.getByLabel('Display name').fill('Client shield');
      await toggleProject(page, project);
      await page.getByLabel('Initial password').fill('abc');
      await submitAndWait(page, createButton(page));
      await expect(page.locator('#user_create_password_errors')).toBeVisible();
      await expect(page.getByTestId('site-only-warning')).toBeVisible();
      await clean(page, 'new site-only user refused');

      // The longest values in every field, errors included (a regular user form: reload it).
      await page.goto('/admin/users/new');
      await setValue(page.getByLabel('Username'), 'U'.repeat(64));
      await setValue(page.getByLabel('Display name'), 'W'.repeat(255));
      await setValue(page.getByLabel('Email'), 'e'.repeat(254));
      await setValue(page.getByLabel('Initial password'), 'p'.repeat(128));
      await submitAndWait(page, createButton(page));
      await expect(page.locator('[id^=user_create_][id$=_errors]').first()).toBeVisible();
      await clean(page, 'refused new user form with long values');
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });

  test('the edit page of a regular user: clean, email error, password error, delete dialog, disabled', async ({ page }) => {
    const project = uniqueName('prj');
    const username = uniqueName('usr');

    try {
      await createProjectViaUi(page, project);
      await createUserViaUi(page, { username, projects: [project] });
      await clean(page, 'user edit page');

      await setValue(page.getByLabel('Email'), 'nope');
      await submitAndWait(page, saveButton(page));
      await expect(page.locator('#user_edit_email_errors')).toBeVisible();
      await clean(page, 'edit form with an email error');

      await setValue(page.getByLabel('New password'), 'tiny');
      await submitAndWait(page, page.getByRole('button', { name: 'Set password' }));
      await expect(page.locator('#user_password_password_errors')).toBeVisible();
      await clean(page, 'edit page with a password error');

      await page.goto(`/admin/users/${username}/edit`);
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this user' }));
      await expect(dialog).toContainText(`Delete ${username}?`);
      await clean(page, 'delete user dialog');
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();

      await submitAndWait(page, page.getByRole('button', { name: 'Disable user' }));
      await expect(page.getByTestId('user-status')).toContainText('Disabled');
      await clean(page, 'disabled user edit page');
    } finally {
      await deleteUserViaUi(page, username);
      await deleteProjectViaUi(page, project);
    }
  });

  test('the edit page of a site-only user', async ({ page }) => {
    const username = uniqueName('usr');

    try {
      await createUserViaUi(page, { username, displayName: 'Regular' });
      await tick(page, 'user_edit', 'siteOnly');
      await submitAndWait(page, saveButton(page));
      await expect(page.getByRole('checkbox', { name: 'Site access only' })).toBeChecked();
      await expect(page.getByTestId('user-status').getByTestId('user-site-only')).toBeVisible();
      await clean(page, 'site-only edit page');
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

  test('the edit page of an administrator', async ({ page, adminLock }) => {
    void adminLock;
    const username = uniqueName('adm');

    try {
      await ensureKeeperAdmin(page);
      await createUserViaUi(page, { username, displayName: 'Second Admin', admin: true });
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeChecked();
      await clean(page, 'administrator edit page');
    } finally {
      await deleteUserViaUi(page, username);
    }
  });

  test('your own edit page: no role change, no disable, no delete', async ({ page, adminLock, baseURL }) => {
    void adminLock;

    try {
      await ensureKeeperAdmin(page);
      await page.context().addCookies([{ name: 'fleet_stand_in_user', value: KEEPER, url: baseURL! }]);
      await page.goto(`/admin/users/${KEEPER}/edit`);
      await expect(page.getByRole('checkbox', { name: 'Administrator' })).toBeDisabled();
      await expect(page.getByText('You cannot change your own role. Ask another administrator.')).toBeVisible();
      await clean(page, 'own edit page');
    } finally {
      await page.context().addCookies([{ name: 'fleet_stand_in_user', value: 'admin', url: baseURL! }]);
    }
  });

  test('the refused changes of the last enabled administrator', async ({ page, adminLock, baseURL }) => {
    void adminLock;
    const actor = `pw-${randomBytes(4).toString('hex')}`;

    try {
      await ensureKeeperAdmin(page);
      await page.goto('/admin/users');
      const others = await page
        .locator('[data-testid=user-row]')
        .filter({ has: page.getByTestId('user-admin') })
        .filter({ hasNot: page.getByTestId('user-disabled') })
        .filter({ hasNot: page.locator(`xpath=self::*[@data-username="${KEEPER}"]`) })
        .count();
      test.skip(others > 0, 'the database holds another enabled administrator than the spec keeper');
      await page.context().addCookies([{ name: 'fleet_stand_in_user', value: actor, url: baseURL! }]);

      await page.goto(`/admin/users/${KEEPER}/edit`);
      await submitAndWait(page, page.getByRole('button', { name: 'Disable user' }));
      await expect(page.getByTestId('flashes')).toContainText('is the last enabled administrator and cannot be disabled.');
      await clean(page, 'refused last-admin disable');

      await tick(page, 'user_edit', 'admin');
      await submitAndWait(page, saveButton(page));
      await expect(page.getByTestId('user-form').getByRole('alert')).toContainText('is the last enabled administrator');
      await clean(page, 'refused last-admin demotion');
    } finally {
      await page.context().addCookies([{ name: 'fleet_stand_in_user', value: 'admin', url: baseURL! }]);
    }
  });
});

// -------------------------------------------------------------------------------------------------------------- secrets

test.describe('axe sweep: secrets', () => {
  const closeReveal = async (page: Page): Promise<void> => {
    const dialog = page.getByTestId('reveal-dialog');
    await dialog.getByRole('button', { name: 'Close' }).first().click();
    await expect(dialog).toBeHidden();
  };

  test('the fleet-wide form refused (empty name and value)', async ({ page }) => {
    await page.goto('/admin/secrets');
    await submitSecret(page, '', '');
    await expect(page.getByTestId('error-name')).toBeVisible();
    await expect(page.getByTestId('error-value')).toBeVisible();
    await clean(page, 'secrets form with errors');
  });

  test('the project Secrets tab refused', async ({ page }) => {
    const project = uniqueName();

    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Secrets');
      await submitSecret(page, 'bad name', `"${uniqueValue()}"`);
      await expect(page.getByTestId('error-name')).toBeVisible();
      await expect(page.getByTestId('error-value')).toBeVisible();
      await expect(page.getByTestId('secrets-empty')).toBeVisible();
      await clean(page, 'project secrets with errors');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the secrets list, its delete dialog and the audit log', async ({ page }) => {
    const name = uniqueSecret('AXE');

    try {
      await page.goto('/admin/secrets');
      await saveSecret(page, name, uniqueValue());
      await clean(page, 'secrets list');

      const dialog = await openDialog(page, secretRow(page, name).getByTestId('secret-delete'));
      await expect(dialog).toContainText(`Delete ${name}?`);
      await clean(page, 'delete dialog');
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();

      await page.goto('/admin/audit');
      await expect(page.getByRole('heading', { level: 1, name: 'Audit log' })).toBeVisible();
      await clean(page, 'audit log');
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });

  test('the reveal dialog (a long value)', async ({ page }) => {
    const name = uniqueSecret('SHOW');
    const value = `${uniqueValue()}-${'long-'.repeat(60)}`;

    try {
      await page.goto('/admin/secrets');
      await saveSecret(page, name, value);
      const dialog = await revealSecret(page, name);
      await expect(dialog.getByTestId('revealed-value')).toHaveText(value);
      await clean(page, 'reveal dialog');
      await closeReveal(page);
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });

  test('the throttled page after the 11th reveal', async ({ page }) => {
    test.setTimeout(240_000);
    const name = uniqueSecret('THR');

    try {
      await page.goto('/admin/secrets');
      await saveSecret(page, name, uniqueValue());
      for (let n = 1; n <= 10; n++) {
        await revealSecret(page, name);
        await closeReveal(page);
      }
      await secretRow(page, name).getByTestId('secret-reveal').click();
      await expect(page.getByTestId('reveal-throttled')).toBeVisible();
      await clean(page, 'throttled page');
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });

  test('a project secret with the "No placeholder" warning', async ({ page }) => {
    const project = uniqueName();
    const used = uniqueSecret('USED');
    const unused = uniqueSecret('UNUSED');

    try {
      await createProjectViaUi(page, project);
      await openSection(page, 'Assets');
      await page.getByTestId('asset-input').setInputFiles({ name: 'settings.env', mimeType: 'text/plain', buffer: Buffer.from(`TOKEN="[[${used.toLowerCase().replaceAll('_', '-')}]]"\n`) });
      await expect(page.getByTestId('asset-upload-item')).toHaveAttribute('data-state', 'done', { timeout: 60_000 });

      await openSection(page, 'Secrets');
      await saveSecret(page, used, uniqueValue());
      await saveSecret(page, unused, uniqueValue());
      await expect(secretRow(page, unused).getByTestId('secret-no-placeholder')).toHaveText('No placeholder');
      await clean(page, 'project secrets with a warning');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the read-only host secrets list', async ({ page }) => {
    await page.goto('/admin/host?tab=claude');
    await page.getByLabel(/Claude OAuth token/).fill(`sk-ant-oat01-e2e-host-secrets-${uniqueValue()}`);
    await submitAndWait(page, page.getByRole('button', { name: /Store token|Replace token/ }));
    await expect(page.getByTestId('flashes')).toContainText('Claude token stored.');
    await page.goto('/admin/secrets');
    await expect(page.getByTestId('host-secrets')).toBeVisible();
    await clean(page, 'host secrets list');
  });
});

// ------------------------------------------------------------------------------------------------------------ schedules

interface Fixture {
  project: string;
  instance: string;
  names: string[];
}

async function fixture(page: Page): Promise<Fixture> {
  const project = uniqueName('sc');
  await createProjectViaUi(page, project);
  instanceRow('insert', project, 'main');
  return { project, instance: `${project}--main`, names: [] };
}

async function cleanup(page: Page, fx: Fixture): Promise<void> {
  for (const name of fx.names) {
    runConsole(['fleet:schedule:delete', name, '--yes']);
  }
  instanceRow('delete', fx.project, 'main');
  instanceRow('delete', fx.project, 'other');
  instanceRow('delete', fx.project, 'gone');
  await deleteProjectViaUi(page, fx.project);
}

function makeSchedule(fx: Fixture, name: string, cron = '0 3 * * *', instance = fx.instance): void {
  consoleOk(['fleet:schedule:create', name, `--cron=${cron}`, '--timezone=UTC', `--instance=${instance}`]);
  fx.names.push(name);
}

const scheduleRow = (page: Page, name: string): Locator => page.locator(`[data-testid=schedule-row][data-name=${name}]`);

test.describe('axe sweep: schedules', () => {
  test('the new-schedule form: empty, then with four errors and with an instance error', async ({ page }) => {
    await page.goto('/admin/schedules');
    await follow(page, page.getByTestId('schedule-new'));
    await expect(page.getByRole('heading', { level: 1, name: 'New schedule' })).toBeVisible();
    await clean(page, 'the empty schedule form');

    await page.getByLabel('Timezone').fill('');
    await submitAndWait(page, page.getByTestId('schedule-save'));
    await expect(page.locator('[aria-invalid=true]')).toHaveCount(4);
    await clean(page, 'the schedule form with four errors');

    await page.getByLabel('Name', { exact: true }).fill('instance-check');
    await page.getByLabel('Cron expression').fill('0 3 * * *');
    await page.getByLabel('Timezone').fill('UTC');
    await submitAndWait(page, page.getByTestId('schedule-save'));
    await expect(page.getByTestId('error-instance')).toBeVisible();
    await clean(page, 'the schedule form with an instance error');
  });

  test('the list: active, paused and target-missing schedules, then the flash of a run', async ({ page }) => {
    const fx = await fixture(page);
    const active = uniqueName('act');
    const paused = uniqueName('pau');
    const missing = uniqueName('mis');

    try {
      makeSchedule(fx, active);
      makeSchedule(fx, paused, '*/15 8-18 * * MON-FRI');
      makeSchedule(fx, missing, '0 5 * * *', `${fx.project}--other`);
      await page.goto('/admin/schedules');
      await submitAndWait(page, scheduleRow(page, paused).getByTestId('schedule-pause'));
      await expect(scheduleRow(page, paused).getByTestId('schedule-state')).toHaveText('Paused');
      await expect(scheduleRow(page, missing).getByTestId('schedule-target-missing')).toBeVisible();
      await clean(page, 'the schedule list');

      await submitAndWait(page, scheduleRow(page, active).getByTestId('schedule-run'));
      await expect(page.getByTestId('flashes')).toContainText('started job #');
      await clean(page, 'the schedule list with the flash of a run');
    } finally {
      await cleanup(page, fx);
    }
  });

  test('the edit form: clean and with errors', async ({ page }) => {
    const fx = await fixture(page);
    const name = uniqueName('edit');

    try {
      makeSchedule(fx, name);
      await page.goto('/admin/schedules');
      await follow(page, scheduleRow(page, name).getByTestId('schedule-edit'));
      await expect(page.getByRole('heading', { level: 1, name: `Edit ${name}` })).toBeVisible();
      await clean(page, 'the schedule edit form');

      await page.getByLabel('Cron expression').fill('nope');
      await page.getByLabel('Timezone').fill('Mars/Base');
      await page.getByLabel('Instance').fill('');
      await submitAndWait(page, page.getByTestId('schedule-save'));
      await expect(page.getByTestId('error-cron')).toBeVisible();
      await clean(page, 'the schedule edit form with errors');
    } finally {
      await cleanup(page, fx);
    }
  });

  test('the history: empty, with two runs and of a run that did not start', async ({ page }) => {
    const fx = await fixture(page);
    const name = uniqueName('life');
    const orphan = uniqueName('orphan');

    try {
      makeSchedule(fx, name);
      instanceRow('insert', fx.project, 'gone');
      makeSchedule(fx, orphan, '0 4 * * *', `${fx.project}--gone`);
      await page.goto('/admin/schedules');
      await follow(page, scheduleRow(page, name).getByTestId('schedule-history'));
      await expect(page.getByTestId('schedule-history-empty')).toBeVisible();
      await clean(page, 'the empty schedule history');

      await page.goto('/admin/schedules');
      await submitAndWait(page, scheduleRow(page, name).getByTestId('schedule-run'));
      await expect(page.getByTestId('flashes')).toContainText('started job #');
      await submitAndWait(page, scheduleRow(page, name).getByTestId('schedule-run'));
      await follow(page, scheduleRow(page, name).getByTestId('schedule-history'));
      await expect(page.getByTestId('schedule-run-row')).toHaveCount(2);
      await clean(page, 'the schedule history with two runs');

      instanceRow('delete', fx.project, 'gone');
      await page.goto('/admin/schedules');
      await submitAndWait(page, scheduleRow(page, orphan).getByTestId('schedule-run'));
      await expect(scheduleRow(page, orphan).getByTestId('schedule-last')).toHaveText('skipped');
      await follow(page, scheduleRow(page, orphan).getByTestId('schedule-history'));
      await expect(page.getByTestId('schedule-run-row')).toHaveCount(1);
      await clean(page, 'the schedule history of a run that did not start');
    } finally {
      await cleanup(page, fx);
    }
  });

  test('the delete dialog', async ({ page }) => {
    const fx = await fixture(page);
    const name = uniqueName('doomed');

    try {
      makeSchedule(fx, name);
      await page.goto('/admin/schedules');
      const dialog = await openDialog(page, scheduleRow(page, name).getByTestId('schedule-delete'));
      await expect(dialog.getByRole('heading', { name: `Delete ${name}?` })).toBeVisible();
      await clean(page, 'the schedule delete dialog');
      await page.keyboard.press('Escape');
    } finally {
      await cleanup(page, fx);
    }
  });
});

// ------------------------------------------------------------------------------------------------------------- webhooks

const sign = (body: string, secret: string): string => `sha256=${createHmac('sha256', secret).update(body).digest('hex')}`;

const endpoint = (page: Page, source: 'jira' | 'bitbucket', project?: string): Locator =>
  page.locator(`[data-testid=webhook-endpoint][data-source=${source}]${project ? `[data-project=${project}]` : ''}`);

/** Creates a secret with the card's button, returns it and leaves the page on the project's Webhooks tab. */
async function createSecret(page: Page, source: 'jira' | 'bitbucket', project: string): Promise<string> {
  await page.goto(`/admin/projects/${project}/integrations`);
  await submitAndWait(page, endpoint(page, source, project).getByTestId('webhook-create'));
  await expect(page.getByTestId('webhook-secret-once')).toBeVisible();
  const secret = await page.getByTestId('webhook-secret-value').inputValue();
  await follow(page, page.getByTestId('webhook-secret-done'));
  return secret;
}

/** POSTs a signed delivery to the receiver and returns the answer's status and JSON body. */
async function deliver(page: Page, source: 'jira' | 'bitbucket', project: string, secret: string, payload: unknown, extra: Record<string, string> = {}): Promise<{ status: number; body: Record<string, unknown> }> {
  const body = typeof payload === 'string' ? payload : JSON.stringify(payload);
  const response: APIResponse = await page.request.post(`/hooks/${source}/${project}`, {
    data: Buffer.from(body),
    headers: { 'Content-Type': 'application/json', 'X-Hub-Signature': sign(body, secret), ...extra },
  });
  const text = await response.text();
  return { status: response.status(), body: text.startsWith('{') ? JSON.parse(text) : { text } };
}

const jiraMove = (key: string, to: string) => ({
  webhookEvent: 'jira:issue_updated',
  issue: { key },
  changelog: { items: [{ field: 'status', fromString: 'To Do', toString: to }] },
});

let sequence = 0;
const jiraId = (): Record<string, string> => ({ 'X-Atlassian-Webhook-Identifier': `pw-${Date.now()}-${sequence++}` });
const bitbucketId = (event: string): Record<string, string> => ({ 'X-Event-Key': event, 'X-Request-UUID': `pw-${Date.now()}-${sequence++}` });

test.describe('axe sweep: webhooks', () => {
  test('the overview and the project tab of a project without secrets', async ({ page }) => {
    const project = uniqueName('wc');

    try {
      await createProjectViaUi(page, project);
      await page.goto('/admin/webhooks');
      await expect(endpoint(page, 'jira', project)).toHaveCount(1);
      await clean(page, 'the webhooks overview');

      await page.goto(`/admin/projects/${project}/integrations`);
      await openDeliveryLog(page);
      await expect(page.getByTestId('deliveries-empty')).toBeVisible();
      await clean(page, 'the webhooks project tab');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the secret page, the open rotate details and the refusal of a stale second create', async ({ page, context }) => {
    const project = uniqueName('ws');

    try {
      await createProjectViaUi(page, project);
      const stale = await context.newPage();
      await stale.goto(`/admin/projects/${project}/integrations`);

      await page.goto(`/admin/projects/${project}/integrations`);
      await submitAndWait(page, endpoint(page, 'jira', project).getByTestId('webhook-create'));
      await expect(page.getByTestId('webhook-secret-once')).toBeVisible();
      await clean(page, 'the webhook secret page');
      await follow(page, page.getByTestId('webhook-secret-done'));

      const jira = endpoint(page, 'jira', project);
      const rotateDialog = await openDialog(page, jira.getByTestId('webhook-rotate-open'));
      await expect(rotateDialog.getByTestId('webhook-rotate')).toBeVisible();
      await clean(page, 'the open rotate dialog');
      await rotateDialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(rotateDialog).toBeHidden();

      const addDialog = await openDialog(page, jira.getByTestId('webhook-rule-add'));
      await expect(addDialog.getByTestId('webhook-rule-form')).toBeVisible();
      await clean(page, 'the open add-rule dialog');
      await addDialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(addDialog).toBeHidden();

      await endpoint(stale, 'jira', project).getByTestId('webhook-create').click();
      await expect(stale.getByTestId('flashes')).toContainText('already has a webhook secret for Jira');
      await clean(stale, 'the refusal of a second create');
      await stale.close();
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the cards with rules and after the rules were removed', async ({ page }) => {
    const project = uniqueName('wr');

    try {
      await createProjectViaUi(page, project);
      consoleOk(['fleet:template:create', project, 'web']);
      await createSecret(page, 'jira', project);
      await createSecret(page, 'bitbucket', project);
      const rule = consoleJson<{ id: number }>(['fleet:webhook:rule:add', project, '--source=jira', '--on-status=Dispatched', '--template=web']);
      consoleOk(['fleet:webhook:rule:add', project, '--source=bitbucket', '--on-event=pullrequest:approved', '--repo=acme/blog', '--action=run-pipeline', '--pattern=renovate-merge', '--ref=develop']);

      await page.goto(`/admin/projects/${project}/integrations`);
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-rules').locator('li')).toHaveCount(1);
      await clean(page, 'the cards with rules');

      consoleOk(['fleet:webhook:rule:remove', project, String(rule.id)]);
      await page.reload();
      await expect(endpoint(page, 'jira', project).getByTestId('webhook-no-rules')).toBeVisible();
      await clean(page, 'the card after the rules were removed');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('the delivery log with every outcome, filtered, on the overview and on its second page', async ({ page }) => {
    test.setTimeout(300_000);
    const project = uniqueName('wl');
    let deployed = '';

    try {
      await createProjectViaUi(page, project);
      consoleOk(['fleet:template:create', project, 'web']);
      consoleOk(['fleet:project:update', project, '--default-branch=develop']);
      const jira = await createSecret(page, 'jira', project);
      const bitbucket = await createSecret(page, 'bitbucket', project);
      consoleOk(['fleet:webhook:rule:add', project, '--source=jira', '--on-status=Dispatched', '--template=web']);
      consoleOk(['fleet:webhook:rule:add', project, '--source=bitbucket', '--on-event=pullrequest:approved', '--repo=acme/blog', '--action=run-pipeline', '--pattern=renovate-merge', '--ref=develop']);

      // accepted, duplicate, ignored, failed (bad body) and failed (no pipeline token).
      const id = jiraId();
      const accepted = await deliver(page, 'jira', project, jira, jiraMove('PW-10', 'Dispatched'), id);
      expect(accepted.body.result).toBe('accepted');
      deployed = String(accepted.body.instance);
      expect((await deliver(page, 'jira', project, jira, jiraMove('PW-10', 'Dispatched'), id)).body.result).toBe('duplicate');
      expect((await deliver(page, 'jira', project, jira, jiraMove('PW-11', 'Done'), jiraId())).body.result).toBe('ignored');
      expect((await deliver(page, 'jira', project, jira, '[1,2]', jiraId())).status).toBe(400);
      expect((await deliver(page, 'bitbucket', project, bitbucket, { repository: { full_name: 'acme/blog' } }, bitbucketId('pullrequest:approved'))).body.result).toBe('failed');

      await page.goto(`/admin/projects/${project}/integrations`);
      await openDeliveryLog(page);
      for (const result of ['accepted', 'duplicate', 'ignored', 'failed']) {
        await expect(page.locator(`[data-testid=delivery-row][data-result=${result}]`).first()).toBeVisible();
      }
      await clean(page, 'the delivery log with every outcome');

      await page.goto(`/admin/projects/${project}/integrations?source=bitbucket&result=accepted`);
      await expect(page.getByTestId('deliveries-empty')).toBeVisible();
      await clean(page, 'the filtered empty delivery log');

      await page.goto('/admin/webhooks?source=jira&result=accepted');
      await expect(page.locator('[data-testid=delivery-row][data-result=accepted]').filter({ hasText: project })).toHaveCount(1);
      await clean(page, 'the overview delivery log');

      // 51 rows: the pager appears and page two holds the last one.
      for (let i = 0; i < 46; i++) {
        const sent = await deliver(page, 'jira', project, jira, { webhookEvent: 'jira:issue_created', issue: { key: `PG-${i}` } }, jiraId());
        expect(sent.status).toBe(200);
      }
      await page.goto(`/admin/projects/${project}/integrations?page=2`);
      await expect(page.getByRole('navigation', { name: 'Delivery pages' }).getByRole('link', { name: 'Newer' })).toBeVisible();
      await clean(page, 'page two of the delivery log');
    } finally {
      if (deployed) {
        instanceRow('delete', project, deployed.slice(project.length + 2));
      }
      await deleteProjectViaUi(page, project);
    }
  });

  test('the pipeline token form: open, refused and saved', async ({ page }) => {
    const project = uniqueName('wt');

    try {
      await createProjectViaUi(page, project);
      await createSecret(page, 'bitbucket', project);
      await page.goto(`/admin/projects/${project}/integrations`);
      const card = endpoint(page, 'bitbucket', project);
      await card.getByText('Set the pipeline token').click();
      await expect(card.getByTestId('webhook-token-input')).toBeVisible();
      await clean(page, 'the open pipeline token form');

      await card.getByTestId('webhook-token-input').fill('two words');
      await submitAndWait(page, card.getByTestId('webhook-token-save'));
      await expect(page.getByTestId('flashes')).toContainText('Enter the access token');
      await clean(page, 'the refused pipeline token');

      await card.locator('summary', { hasText: 'the pipeline token' }).click();
      await card.getByTestId('webhook-token-input').fill('ATCTT-playwright-token');
      await submitAndWait(page, card.getByTestId('webhook-token-save'));
      await expect(page.getByTestId('flashes')).toContainText('The pipeline token is saved');
      await expect(card.getByTestId('webhook-token-clear')).toBeVisible();
      await clean(page, 'the saved pipeline token');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });

  test('a pipeline token refused for a project without a Bitbucket receiver', async ({ page }) => {
    const project = uniqueName('wg');

    try {
      await createProjectViaUi(page, project);
      await createSecret(page, 'jira', project);
      await page.goto(`/admin/projects/${project}/integrations`);
      // The CSRF token is made by the page's script on submit, so the request goes through one of the page's own forms.
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
      await clean(page, 'the token refused without a receiver');
    } finally {
      await deleteProjectViaUi(page, project);
    }
  });
});
