import { expect, type Locator, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { follow, submitAndWait } from './support/projects';
import { openHostTab, setValue, skipBrowserValidation, TAB_LABELS, test } from './support/host';
import { uniqueValue } from './support/secrets';
import { collectBrowserErrors, hasHorizontalOverflow, unexpectedErrors } from './support/shell';
import { setStandInRole } from './support/standIn';

// Host settings, full coverage: every tab, every field, every message the rules return, the saved state re-read from
// the page, the write-only secrets, the 404s of the backup download, the member's 403, and axe in both themes on the
// states with errors. The host settings are one shared row: a test that saves takes `hostLock` (support/host.ts) and
// the existing host-settings.spec.ts takes it too. The forms are not `novalidate`, so a browser stops a bad e-mail or
// an out-of-range number itself; the server messages are reached by skipping that validation, like a client would.

const MAX_UPLOAD_MIB = 1_048_576;

async function save(page: Page, tab: Locator, label = 'Save'): Promise<void> {
  await submitAndWait(page, tab.getByRole('button', { name: label, exact: true }));
}

/** The text of the error shown for a field: the message sits in the field's row. */
function fieldRow(tab: Locator, label: string | RegExp): Locator {
  return tab.getByLabel(label).locator('xpath=ancestor::*[@data-slot="form-row" or contains(@class,"grid")][1]');
}

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

test.describe('host settings: tabs', () => {
  test('an unknown tab shows General, and each tab link marks itself current', async ({ page }) => {
    test.skip(test.info().project.name === 'phone', 'the strip is desktop only; the phone has the select');
    await page.goto('/admin/host?tab=nonsense');
    await expect(page.getByTestId('host-tab-general')).toBeVisible();
    await expect(page.getByTestId('host-tabs').locator('a[aria-current=page]')).toHaveText('General');

    for (const [key, label] of Object.entries(TAB_LABELS)) {
      await follow(page, page.getByTestId('host-tabs').getByRole('link', { name: label, exact: true }));
      await expect(page).toHaveURL(new RegExp(`/admin/host\\?tab=${key}$`));
      await expect(page.getByTestId(`host-tab-${key}`)).toBeVisible();
      await expect(page.getByTestId('host-tabs').locator('a[aria-current=page]')).toHaveText(label);
    }
  });

  test('the phone select lists the seven tabs, names the current one and opens another with Go', async ({ page }) => {
    test.skip(test.info().project.name !== 'phone', 'phone only');
    await page.goto('/admin/host?tab=uploads');
    const select = page.getByTestId('host-tab-select').getByLabel('Section');
    await expect(select).toHaveValue('uploads');
    expect(await select.locator('option').allTextContents()).toEqual(Object.values(TAB_LABELS));

    await select.selectOption('backups');
    await submitAndWait(page, page.getByTestId('host-tab-select').getByRole('button', { name: 'Go' }));
    await expect(page).toHaveURL(/\/admin\/host\?tab=backups$/);
    await expect(page.getByTestId('host-tab-backups')).toBeVisible();
  });
});

test.describe('host settings: General', () => {
  test('the domain and TLS mode are shown read-only with the way to change them', async ({ page }) => {
    const tab = await openHostTab(page, 'general');
    await expect(tab.getByTestId('host-domain')).toContainText(/\S+\.\S+/);
    await expect(tab.getByTestId('host-tls-mode')).toContainText(/http01|ovh_dns/);
    await expect(tab.getByRole('textbox')).toHaveCount(1);
    const cli = tab.getByTestId('host-general-cli');
    await expect(cli).toContainText('Changing it after setup is not supported yet');
    await expect(cli).toContainText('sudo fleet setup:reset');
    await expect(cli).toContainText('sudo fleet host:init --tls-mode=http01 --force');
    await expect(tab).toContainText('After saving, apply the new address to Caddy with sudo fleet host:render --apply on the host.');
    await expectNoOverflow(page, 'the General tab');
  });

  test('the ACME contact: a bad address is refused, a good one is saved and read back, an empty one clears it', async ({ page, hostLock }) => {
    void hostLock;
    const errors = collectBrowserErrors(page);
    const tab = await openHostTab(page, 'general');
    const field = tab.getByLabel('ACME contact e-mail');
    await expect(tab).toContainText('The address the certificate authority may use to warn about expiring certificates. Optional.');

    // The browser's own check comes first: no request leaves the page.
    await field.fill('nobody');
    await tab.getByRole('button', { name: 'Save' }).click();
    expect(await field.evaluate((node) => (node as HTMLInputElement).validity.typeMismatch)).toBe(true);
    await expect(page).toHaveURL(/\/admin\/host\?tab=general$/);

    for (const bad of ['nobody', 'a@b', 'two words@example.com', `${'a'.repeat(250)}@example.com`]) {
      await skipBrowserValidation(field);
      await setValue(field, bad);
      await save(page, tab);
      await expect(page.getByTestId('host-tab-general'), bad).toContainText('That is not a valid e-mail address.');
      await expect(page.getByTestId('flashes')).toHaveCount(0);
    }
    await expectNoBlockingViolations(page, 'General tab with an error');

    await tab.getByLabel('ACME contact e-mail').fill('certs@example.com');
    await save(page, tab);
    await expect(page.getByTestId('flashes')).toContainText('ACME contact saved. Apply it to Caddy with "sudo fleet host:render --apply" on the host.');
    await expect(tab.getByLabel('ACME contact e-mail')).toHaveValue('certs@example.com');
    await page.goto('/admin/host?tab=general');
    await expect(page.getByLabel('ACME contact e-mail')).toHaveValue('certs@example.com');

    await page.getByLabel('ACME contact e-mail').fill('');
    await save(page, page.getByTestId('host-tab-general'));
    await expect(page.getByTestId('flashes')).toContainText('ACME contact saved.');
    await page.goto('/admin/host?tab=general');
    await expect(page.getByLabel('ACME contact e-mail')).toHaveValue('');
    expect(unexpectedErrors(errors)).toEqual([]);
  });
});

test.describe('host settings: Git identity', () => {
  test('every refusal has its message, and the saved identity flips the workspace check', async ({ page, hostLock }) => {
    void hostLock;
    test.setTimeout(120_000);
    const tab = await openHostTab(page, 'git');
    const name = tab.getByLabel('Name');
    const email = tab.getByLabel('E-mail');
    const row = (label: string) => fieldRow(page.getByTestId('host-tab-git'), label);

    // An e-mail without a name: the message is on the missing one.
    await name.fill('');
    await skipBrowserValidation(email);
    await email.fill('bot@example.com');
    await save(page, tab);
    await expect(row('Name')).toContainText('Set both the name and the e-mail address, or leave both empty.');

    const cases: [string, string, string, string][] = [
      ['Name', `${'n'.repeat(101)}`, 'bot@example.com', 'The name must be at most 100 characters.'],
      ['Name', 'Fleet <Bot>', 'bot@example.com', 'The name must not contain control characters, "<" or ">".'],
      ['Name', 'Fleet\tBot', 'bot@example.com', 'The name must not contain control characters, "<" or ">".'],
      ['E-mail', 'Fleet Bot', 'bot@localhost', 'Enter a valid email address.'],
      ['E-mail', 'Fleet Bot', 'not an address', 'Enter a valid email address.'],
      ['E-mail', 'Fleet Bot', `${'a'.repeat(70)}@example.com`, 'Enter a valid email address.'],
    ];
    for (const [field, nameValue, emailValue, message] of cases) {
      await skipBrowserValidation(page.getByLabel('E-mail'));
      await setValue(page.getByLabel('Name'), nameValue);
      await setValue(page.getByLabel('E-mail'), emailValue);
      await save(page, page.getByTestId('host-tab-git'));
      await expect(row(field), `${field}: ${emailValue}`).toContainText(message);
      await expect(page.getByTestId('flashes')).toHaveCount(0);
    }
    await expectNoBlockingViolations(page, 'Git identity tab with an error');

    // Surrounding blanks are trimmed; a name with accents and CJK is fine.
    await page.getByLabel('Name').fill('  Flëet Bøt 日本  ');
    await page.getByLabel('E-mail').fill('  bot@example.com ');
    await save(page, page.getByTestId('host-tab-git'));
    await expect(page.getByTestId('flashes')).toContainText('Git identity saved.');
    await expect(page.getByLabel('Name')).toHaveValue('Flëet Bøt 日本');
    await expect(page.getByLabel('E-mail')).toHaveValue('bot@example.com');
    await expect(page.getByTestId('host-check-git-identity')).toContainText('Present');
    await expect(page.getByTestId('host-git-stored-invalid')).toHaveCount(0);
    await page.goto('/admin/host?tab=git');
    await expect(page.getByLabel('Name')).toHaveValue('Flëet Bøt 日本');

    // Both empty clears the identity.
    await page.getByLabel('Name').fill('');
    await page.getByLabel('E-mail').fill('');
    await save(page, page.getByTestId('host-tab-git'));
    await expect(page.getByTestId('flashes')).toContainText('Git identity saved.');
    await expect(page.getByLabel('Name')).toHaveValue('');
    await expect(page.getByLabel('E-mail')).toHaveValue('');
    await expect(page.getByTestId('host-check-git-identity')).toContainText('Missing');
    await expectNoOverflow(page, 'the Git identity tab');
  });
});

test.describe('host settings: Claude token', () => {
  test('an empty or malformed token is refused and never shown back; a good one is stored, rotated and recorded', async ({ page, actor }) => {
    test.setTimeout(120_000);
    const tab = await openHostTab(page, 'claude');
    const field = tab.getByLabel(/Claude OAuth token/);
    const message = 'A token has no spaces or control characters and is at most 512 characters. Copy it again from "claude setup-token".';

    await tab.getByRole('button', { name: /Store token|Replace token/ }).click();
    await expect(page.getByTestId('host-tab-claude')).toContainText('Paste the token to store it.');

    const secret = `sk-ant-oat01-${uniqueValue()}`;
    for (const bad of [`${secret} with space`, `${secret}\twith-tab`, `${secret}${'x'.repeat(520)}`, `é-${secret}`]) {
      await setValue(page.getByLabel(/Claude OAuth token/), bad);
      await save(page, page.getByTestId('host-tab-claude'), (await page.getByTestId('host-tab-claude').getByRole('button', { name: /Store token|Replace token/ }).innerText()).trim());
      await expect(page.getByTestId('host-tab-claude')).toContainText(message);
      expect(await page.content()).not.toContain(secret);
      await expect(page.getByLabel(/Claude OAuth token/)).toHaveValue('');
    }
    await expectNoBlockingViolations(page, 'Claude tab with an error');

    // A token in the longest allowed size is stored.
    const longest = `sk-${'a'.repeat(509)}`;
    expect(longest).toHaveLength(512);
    await setValue(page.getByLabel(/Claude OAuth token/), longest);
    await save(page, page.getByTestId('host-tab-claude'), (await page.getByTestId('host-tab-claude').getByRole('button', { name: /Store token|Replace token/ }).innerText()).trim());
    await expect(page.getByTestId('flashes')).toContainText('Claude token stored.');

    // After the first store the form says "New" and "Replace"; the status names the minute of the last rotation.
    await expect(page.getByLabel('New Claude OAuth token')).toBeVisible();
    await expect(page.getByTestId('host-claude-form').getByRole('button', { name: 'Replace token' })).toBeVisible();
    const status = page.getByTestId('host-claude-status');
    await expect(status).toContainText('Set');
    await expect(status).toContainText(/Last rotated \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/);
    await expect(page.getByTestId('host-check-claude-token')).toContainText('Present');
    await expect(page.getByTestId('host-claude-command')).toContainText('sudo fleet claude:token:set');
    expect(await page.content()).not.toContain(longest);

    await page.getByLabel('New Claude OAuth token').fill(secret);
    await save(page, page.getByTestId('host-tab-claude'), 'Replace token');
    await expect(page.getByTestId('flashes')).toContainText('Claude token stored.');
    expect(await page.content()).not.toContain(secret);
    await expectNoOverflow(page, 'the Claude token tab');
    await expectNoBlockingViolations(page, 'Claude tab with a stored token');

    await page.goto('/admin/audit?action=claude.token.rotate');
    const mine = page.getByTestId('audit-row').filter({ hasText: actor });
    await expect(mine).toHaveCount(2);
    await expect(mine.first()).toContainText('fleet/claude_oauth_token');
    expect(await page.content()).not.toContain(secret);
  });
});

test.describe('host settings: Notifications', () => {
  test('every field has its message and nothing is saved while one is wrong', async ({ page, hostLock }) => {
    void hostLock;
    test.setTimeout(120_000);
    const tab = await openHostTab(page, 'notifications');
    const host = tab.getByLabel('SMTP host');
    const row = (label: string | RegExp) => fieldRow(page.getByTestId('host-tab-notifications'), label);
    await skipBrowserValidation(host);

    const cases: [string | RegExp, Record<string, string>, string][] = [
      ['SMTP host', { 'SMTP host': 'smtp://mail.example.com' }, 'Enter a host name such as smtp.example.com (no scheme, port or path).'],
      ['SMTP host', { 'SMTP host': 'mail.example.com:587' }, 'Enter a host name such as smtp.example.com (no scheme, port or path).'],
      ['SMTP host', { 'SMTP host': '-bad.example.com' }, 'Enter a host name such as smtp.example.com (no scheme, port or path).'],
      ['SMTP host', { 'SMTP host': 'mail example.com' }, 'Enter a host name such as smtp.example.com (no scheme, port or path).'],
      ['SMTP port', { 'SMTP host': 'mail.example.com', 'SMTP port': '0' }, 'The port must be between 1 and 65535.'],
      ['SMTP port', { 'SMTP host': 'mail.example.com', 'SMTP port': '65536' }, 'The port must be between 1 and 65535.'],
      ['SMTP user', { 'SMTP host': 'mail.example.com', 'SMTP user': 'a\tb' }, 'The user name must be at most 254 characters without control characters.'],
      ['SMTP user', { 'SMTP host': 'mail.example.com', 'SMTP user': 'u'.repeat(255) }, 'The user name must be at most 254 characters without control characters.'],
      ['Sender address', { 'Sender address': 'nobody' }, 'That is not a valid e-mail address.'],
      ['Sender address', { 'Sender address': 'a b@example.com' }, 'That is not a valid e-mail address.'],
      ['Recipient address', { 'Recipient address': `${'r'.repeat(250)}@example.com` }, 'That is not a valid e-mail address.'],
      [/SMTP password/, { 'SMTP password': 'p'.repeat(257) }, 'The password must be 1 to 256 characters without control characters.'],
      [/SMTP password/, { 'SMTP password': 'pass\tword' }, 'The password must be 1 to 256 characters without control characters.'],
    ];
    for (const [field, values, message] of cases) {
      await openHostTab(page, 'notifications');
      await skipBrowserValidation(page.getByLabel('SMTP host'));
      for (const [label, value] of Object.entries(values)) {
        await setValue(page.getByLabel(label === 'SMTP password' ? /SMTP password/ : label), value);
      }
      await save(page, page.getByTestId('host-tab-notifications'));
      await expect(row(field), JSON.stringify(values).slice(0, 80)).toContainText(message);
      await expect(page.getByTestId('flashes')).toHaveCount(0);
    }
    await expectNoBlockingViolations(page, 'Notifications tab with an error');
    await expectNoOverflow(page, 'the Notifications tab with an error');
  });

  test('saved settings are read back; the password is write-only, replaced, removed and refused with both', async ({ page, hostLock, actor }) => {
    void hostLock;
    test.setTimeout(180_000);
    const password = `smtp-${uniqueValue()}`;
    const replacement = `smtp-${uniqueValue()}`;
    let tab = await openHostTab(page, 'notifications');

    await page.getByLabel('SMTP host').fill('smtp.example.com');
    await page.getByLabel('SMTP port').fill('2525');
    await page.getByLabel('SMTP user').fill('mailer@example.com');
    await page.getByLabel(/SMTP password/).fill(password);
    await page.getByLabel('Sender address').fill('fleet@example.com');
    await page.getByLabel('Recipient address').fill('ops@example.com');
    await save(page, tab);
    await expect(page.getByTestId('flashes')).toContainText('Notification e-mail settings saved.');
    await expect(page.getByTestId('host-smtp-status')).toContainText('Set');
    await expect(page.getByTestId('host-smtp-status')).toContainText(/Updated \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/);
    expect(await page.content()).not.toContain(password);

    await page.goto('/admin/host?tab=notifications');
    tab = page.getByTestId('host-tab-notifications');
    await expect(tab.getByLabel('SMTP host')).toHaveValue('smtp.example.com');
    await expect(tab.getByLabel('SMTP port')).toHaveValue('2525');
    await expect(tab.getByLabel('SMTP user')).toHaveValue('mailer@example.com');
    await expect(tab.getByLabel('Sender address')).toHaveValue('fleet@example.com');
    await expect(tab.getByLabel('Recipient address')).toHaveValue('ops@example.com');
    // With a stored password the label says "New", the field is empty and a checkbox offers to remove it.
    await expect(tab.getByLabel('New SMTP password')).toHaveValue('');
    await expect(tab).toContainText('A password is stored. Leave blank to keep it, or type a new one to replace it.');
    await expectNoBlockingViolations(page, 'Notifications tab with a stored password');

    // A new password and "remove" together are refused, and nothing changes.
    await tab.getByLabel('New SMTP password').fill(replacement);
    await tab.getByLabel('Remove the stored password').check();
    await save(page, tab);
    await expect(page.getByTestId('host-tab-notifications')).toContainText('Type a new password or remove the stored one, not both.');
    await expect(page.getByTestId('host-smtp-status')).toContainText('Set');

    // Only a new password: replaced.
    await page.goto('/admin/host?tab=notifications');
    await page.getByLabel('New SMTP password').fill(replacement);
    await save(page, page.getByTestId('host-tab-notifications'));
    await expect(page.getByTestId('flashes')).toContainText('Notification e-mail settings saved.');
    expect(await page.content()).not.toContain(replacement);

    // Only "remove": the status turns to Not set and the checkbox is gone.
    await page.getByLabel('Remove the stored password').check();
    await save(page, page.getByTestId('host-tab-notifications'));
    await expect(page.getByTestId('flashes')).toContainText('Notification e-mail settings saved.');
    await expect(page.getByTestId('host-smtp-status')).toContainText('Not set');
    await expect(page.getByLabel('Remove the stored password')).toHaveCount(0);
    await expect(page.getByLabel('SMTP password', { exact: true })).toHaveValue('');

    // Everything empty turns the e-mail off and is saved.
    for (const label of ['SMTP host', 'SMTP port', 'SMTP user', 'Sender address', 'Recipient address']) {
      await page.getByLabel(label).fill('');
    }
    await save(page, page.getByTestId('host-tab-notifications'));
    await expect(page.getByTestId('flashes')).toContainText('Notification e-mail settings saved.');
    await page.goto('/admin/host?tab=notifications');
    for (const label of ['SMTP host', 'SMTP port', 'SMTP user', 'Sender address', 'Recipient address']) {
      await expect(page.getByLabel(label)).toHaveValue('');
    }

    // The password changes are in the audit log, without the values.
    await page.goto('/admin/audit');
    const mine = page.getByTestId('audit-row').filter({ hasText: 'fleet/smtp_password' }).filter({ hasText: actor });
    expect(await mine.evaluateAll((rows) => rows.map((row) => row.children[2].textContent?.trim()))).toEqual(['secret.delete', 'secret.put', 'secret.put']);
    const html = await page.content();
    expect(html).not.toContain(password);
    expect(html).not.toContain(replacement);
  });

  test('the browser stops a bad e-mail and a port out of range before any request', async ({ page, hostLock }) => {
    void hostLock;
    const tab = await openHostTab(page, 'notifications');
    let posts = 0;
    page.on('request', (request) => {
      if (request.method() === 'POST') {
        posts++;
      }
    });
    await tab.getByLabel('Sender address').fill('nobody');
    await tab.getByLabel('SMTP port').fill('70000');
    await tab.getByRole('button', { name: 'Save' }).click();
    expect(await tab.getByLabel('Sender address').evaluate((node) => (node as HTMLInputElement).validity.typeMismatch)).toBe(true);
    expect(await tab.getByLabel('SMTP port').evaluate((node) => (node as HTMLInputElement).validity.rangeOverflow)).toBe(true);
    expect(posts).toBe(0);
  });
});

test.describe('host settings: Uploads', () => {
  test('the limit refuses 0, a negative number, text and more than a TiB; a valid one is saved, shown and restored', async ({ page, hostLock }) => {
    void hostLock;
    test.setTimeout(120_000);
    const tab = await openHostTab(page, 'uploads');
    const field = tab.getByLabel('Largest file (MiB)');
    await expect(tab).toContainText(`1 to ${MAX_UPLOAD_MIB}. Bigger files are copied into the assets directory over SSH.`);
    const before = await field.inputValue();
    await skipBrowserValidation(field);

    for (const bad of ['0', '-5', String(MAX_UPLOAD_MIB + 1), '']) {
      await skipBrowserValidation(page.getByLabel('Largest file (MiB)'));
      await setValue(page.getByLabel('Largest file (MiB)'), bad);
      await save(page, page.getByTestId('host-tab-uploads'));
      await expect(page.getByTestId('host-tab-uploads'), `value "${bad}"`).toContainText(`Enter a number of MiB from 1 to ${MAX_UPLOAD_MIB}.`);
      await expect(page.getByTestId('flashes')).toHaveCount(0);
    }
    await expectNoBlockingViolations(page, 'Uploads tab with an error');

    try {
      await page.goto('/admin/host?tab=uploads');
      await page.getByLabel('Largest file (MiB)').fill('4096');
      await save(page, page.getByTestId('host-tab-uploads'));
      await expect(page.getByTestId('flashes')).toContainText('Upload limit saved.');
      await expect(page.getByLabel('Largest file (MiB)')).toHaveValue('4096');
      await expect(page.getByTestId('host-upload-limit-current')).toHaveText('4.0 GiB');

      await page.getByLabel('Largest file (MiB)').fill('1');
      await save(page, page.getByTestId('host-tab-uploads'));
      await expect(page.getByTestId('host-upload-limit-current')).toHaveText('1.0 MiB');
      await page.getByLabel('Largest file (MiB)').fill(String(MAX_UPLOAD_MIB));
      await save(page, page.getByTestId('host-tab-uploads'));
      await expect(page.getByTestId('host-upload-limit-current')).toHaveText('1.0 TiB');
      await expectNoOverflow(page, 'the Uploads tab');
    } finally {
      await page.goto('/admin/host?tab=uploads');
      await page.getByLabel('Largest file (MiB)').fill(before);
      await save(page, page.getByTestId('host-tab-uploads'));
      await expect(page.getByLabel('Largest file (MiB)')).toHaveValue(before);
    }
  });
});

test.describe('host settings: Backups', () => {
  test('the retention refuses 0, 3651, text and nothing; 1 and 3650 are accepted and the value is read back', async ({ page, hostLock }) => {
    void hostLock;
    test.setTimeout(120_000);
    const tab = await openHostTab(page, 'backups');
    const field = tab.getByLabel(/Keep backups for/);
    await expect(tab).toContainText('1 to 3650. The newest backup is always kept, whatever its age.');
    const before = await field.inputValue();
    await skipBrowserValidation(field);

    for (const bad of ['0', '-1', '3651', '']) {
      await skipBrowserValidation(page.getByLabel(/Keep backups for/));
      await setValue(page.getByLabel(/Keep backups for/), bad);
      await save(page, page.getByTestId('host-tab-backups'), 'Save');
      await expect(page.getByTestId('host-tab-backups'), `value "${bad}"`).toContainText('Enter a number of days from 1 to 3650.');
      await expect(page.getByTestId('flashes')).toHaveCount(0);
    }
    await expectNoBlockingViolations(page, 'Backups tab with an error');

    try {
      // A fresh page for the valid saves: the error page was rendered by a refused POST and then flipped by axe.
      await page.goto('/admin/host?tab=backups');
      for (const days of ['1', '3650']) {
        await page.getByLabel(/Keep backups for/).fill(days);
        await save(page, page.getByTestId('host-tab-backups'), 'Save');
        await expect(page.getByTestId('flashes')).toContainText('Backup retention saved.');
        await page.goto('/admin/host?tab=backups');
        await expect(page.getByLabel(/Keep backups for/)).toHaveValue(days);
      }
    } finally {
      await page.getByLabel(/Keep backups for/).fill(before);
      await save(page, page.getByTestId('host-tab-backups'), 'Save');
      await expect(page.getByLabel(/Keep backups for/)).toHaveValue(before);
    }
  });

  test('the list shows date, size and a download; a new backup is added; unknown names answer 404', async ({ page }) => {
    const tab = await openHostTab(page, 'backups');
    await expect(tab).toContainText('A snapshot of the fleet database is written every day.');
    await expect(tab).toContainText('keys/secret.key');
    await submitAndWait(page, tab.getByRole('button', { name: 'Back up now' }));
    await expect(page.getByTestId('flashes')).toContainText(/Backup written: fleet-\d{8}-\d{6}\.sqlite \(\d+(\.\d)? (B|KiB|MiB)\)\./);

    const table = page.getByTestId('backups-table');
    await expect(table.getByRole('columnheader')).toHaveText(['Date (UTC)', 'Size', 'Download']);
    const row = page.getByTestId('backup-row').first();
    await expect(row.getByRole('cell').nth(0)).toHaveText(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/);
    await expect(row.getByRole('cell').nth(1)).not.toBeEmpty();
    const href = await row.getByRole('link', { name: /Download fleet-/ }).getAttribute('href');
    expect(href).toMatch(/^\/admin\/host\/backups\/fleet-\d{8}-\d{6}\.sqlite$/);

    const download = await page.request.get(href!);
    expect(download.status()).toBe(200);
    expect(download.headers()['content-disposition']).toMatch(/attachment; filename="?fleet-\d{8}-\d{6}\.sqlite"?/);
    expect((await download.body()).subarray(0, 15).toString()).toBe('SQLite format 3');

    for (const name of ['fleet-19990101-000000.sqlite', 'nothing.sqlite', '..%2Fvar%2Ffleet.sqlite', 'fleet-1.sqlite%00']) {
      const missing = await page.request.get(`/admin/host/backups/${name}`);
      expect(missing.status(), name).toBe(404);
    }
    await expectNoOverflow(page, 'the Backups tab');
    await expectNoBlockingViolations(page, 'Backups tab');
  });
});

test.describe('host settings: Networking and access', () => {
  test('the Networking tab explains the mirror and shows its state, with 44 px controls on the phone', async ({ page }) => {
    const tab = await openHostTab(page, 'network');
    await expect(tab).toContainText('Named ports exist on the host\'s Caddy only.');
    await expect(tab.getByLabel('Mirror the named ports inside the Docker network')).toBeAttached();
    await expect(tab).toContainText('Switching it off removes the mirror files at once');
    await expect(page.getByTestId('host-mirror-ports')).toContainText(/The mirror is off|No port is mirrored now|Mirrored public ports/);
    await expect(page.getByText('fleet port:refresh')).toBeVisible();
    await expectNoOverflow(page, 'the Networking tab');
    await expectNoBlockingViolations(page, 'Networking tab');
  });

  test('a member gets 403 on the page and on every save endpoint; a post without the CSRF token changes nothing', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'member', baseURL!);
    for (const path of ['/admin/host', '/admin/host?tab=git', '/admin/host/backups/fleet-20200101-000000.sqlite']) {
      expect((await page.goto(path))?.status(), path).toBe(403);
    }
    for (const path of ['general', 'git', 'claude', 'notifications', 'network', 'uploads', 'backups', 'backups/retention']) {
      const response = await page.request.post(`/admin/host/${path}`, { form: { x: '1' } });
      expect(response.status(), path).toBe(403);
    }

    await setStandInRole(context, 'admin', baseURL!);
    for (const path of ['general', 'git', 'claude', 'notifications', 'uploads', 'backups/retention']) {
      const response = await page.request.post(`/admin/host/${path}`, { form: { x: '1' } });
      expect(response.status(), path).toBe(422);
    }
    const backup = await page.request.post('/admin/host/backups', { form: { x: '1' } });
    expect(backup.status()).toBe(422);
  });
});
