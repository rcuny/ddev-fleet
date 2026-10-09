/** The Secrets screen in a real browser (FLE-24): real clicks, real JS, real CSP. */
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { dirname } from 'node:path';
import type { Page, Request } from '@playwright/test';
import { expect, test } from './support/fixtures';

// An <input type=password> cannot hold a newline (the browser strips it), so the stress is
// non-ASCII plus leading and trailing spaces, which must survive untouched.
const PLAINTEXT = '  päss wörd é € trailing spaces  ';
const SECRETS_URL = '/secrets?project=demo';

const isSecretPost = (request: Request) =>
  request.method() === 'POST' && request.url().endsWith('/ui/secrets/demo');

async function save(page: Page, name: string, value: string) {
  await page.locator('input[name=key]').fill(name);
  await page.locator('#secret-value').fill(value);
  await page.getByRole('button', { name: 'Encrypt and save' }).click();
}

function decrypt(gnupg: string, asc: string): string {
  return execFileSync('gpg', ['--homedir', gnupg, '--batch', '--quiet', '--decrypt', asc], {
    encoding: 'utf8',
  });
}

test('save encrypts in the browser and the host decrypts it', async ({ page, daemon }) => {
  await page.goto(daemon.url + SECRETS_URL);

  const shown = (await page.locator('#host-fingerprint').innerText()).replace(/\s/g, '');
  expect(shown).toBe(daemon.fingerprint);

  const received = page.waitForResponse((r) => isSecretPost(r.request()));
  await save(page, 'FLEET_E2E', PLAINTEXT);
  const response = await received;
  const request = response.request();

  const body = request.postData() ?? '';
  const fields = new URLSearchParams(body);
  expect([...new Set(fields.keys())].sort(), 'only these fields may be sent').toEqual([
    'armored',
    'key',
    'project',
  ]);
  expect(fields.getAll('key')).toEqual(['FLEET_E2E']);
  expect(fields.get('armored')).toMatch(/^-----BEGIN PGP MESSAGE-----/);
  for (const needle of ['päss', 'wörd', 'trailing']) {
    expect(body, `plaintext ${needle} was sent`).not.toContain(needle);
    expect(decodeURIComponent(body.replace(/\+/g, ' ')), `plaintext ${needle} was sent`).not.toContain(
      needle,
    );
  }
  expect(response.status()).toBe(200);

  await expect(page.locator('.secret-notice')).toContainText('Stored FLEET_E2E');
  await expect(page.locator('#secret-names')).toContainText('FLEET_E2E');

  const asc = daemon.asc('FLEET_E2E');
  expect(existsSync(asc)).toBe(true);
  expect(decrypt(daemon.gnupg, asc)).toBe(PLAINTEXT);
});

test('an empty value shows a client-side error and sends nothing', async ({ page, daemon }) => {
  await page.goto(daemon.url + SECRETS_URL);
  const posts: Request[] = [];
  page.on('request', (r) => {
    if (r.method() === 'POST') posts.push(r);
  });

  await page.locator('input[name=key]').fill('FLEET_E2E');
  await page.getByRole('button', { name: 'Encrypt and save' }).click();

  const error = page.locator('#secret-client-error');
  await expect(error).toBeVisible();
  await expect(error).toContainText('empty');
  await page.waitForTimeout(500); // a request, if one were going to be sent, is out by now
  expect(posts).toEqual([]);
});

test('an invalid name shows the error, keeps the name and stores nothing', async ({
  page,
  daemon,
  expectedConsoleErrors,
}) => {
  expectedConsoleErrors.push('status of 400');
  await page.goto(daemon.url + SECRETS_URL);

  // The Name input's `pattern` is native validation and would block the submit before any
  // request; the server-side error panel is what this test is about, so lift only that pattern.
  await page.locator('input[name=key]').evaluate((el) => el.removeAttribute('pattern'));
  const received = page.waitForResponse((r) => isSecretPost(r.request()));
  await save(page, 'bad-name', 'some-value');

  expect((await received).status()).toBe(400);
  const panel = page.locator('#secret-feedback');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText(/name/i);
  await expect(page.locator('input[name=key]')).toHaveValue('bad-name');

  const dir = dirname(daemon.asc('x'));
  const stored = existsSync(dir) ? readdirSync(dir).filter((f) => f.endsWith('.asc')) : [];
  expect(stored).toEqual([]);
});

test('delete removes the secret', async ({ page, daemon }) => {
  await page.goto(daemon.url + SECRETS_URL);

  const saved = page.waitForResponse((r) => isSecretPost(r.request()));
  await save(page, 'FLEET_E2E', 'to-be-deleted');
  await saved;
  await expect(page.locator('.secret-notice')).toBeVisible();
  expect(existsSync(daemon.asc('FLEET_E2E'))).toBe(true);

  const dialogs: string[] = [];
  page.on('dialog', (dialog) => {
    dialogs.push(dialog.message());
    void dialog.accept();
  });
  const deleted = page.waitForResponse((r) => r.url().endsWith('/FLEET_E2E/delete'));
  await page.locator('#secret-names button.destroy').click();
  await deleted;

  await expect(page.locator('#secret-names code')).toHaveCount(0);
  await expect(page.locator('#secret-names')).not.toContainText('FLEET_E2E');
  expect(dialogs).toHaveLength(1);
  expect(dialogs[0]).toContain('FLEET_E2E');
  expect(existsSync(daemon.asc('FLEET_E2E'))).toBe(false);
});

test('without a host key the page shows instructions and no form', async ({
  page,
  daemonNoKey,
}) => {
  await page.goto(daemonNoKey.url + SECRETS_URL);

  await expect(page.locator('main, body').first()).toContainText('fleet keys init');
  await expect(page.locator('#secret-form')).toHaveCount(0);
});
