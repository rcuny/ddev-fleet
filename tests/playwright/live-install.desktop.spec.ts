import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { baseURL, externalBaseURL } from './support/env';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { completeWizard, type WizardStepName } from './support/wizard';

// A real installation, set up through a real browser over HTTPS (no ignoreHTTPSErrors: the certificate must be valid).
//
// It needs a host the installer just set up: FLEET_E2E_BASE_URL (https://<domain>), FLEET_LIVE_SETUP_URL (the link the
// installer printed) and FLEET_LIVE_ADMIN_PASSWORD (chosen by the caller, 12 characters or more). Without them the
// whole file is skipped, so the default gate never touches a server. Optional: FLEET_LIVE_ADMIN_USERNAME / _NAME /
// _EMAIL, FLEET_LIVE_VIEWPORT=desktop (default: phone width, 390x844) and FLEET_LIVE_OUT (screenshots and the
// public keys land there; the setup link and the password never do).
//
// One link means one walk-through: the tests share one browser context and run in order.
const setupUrl = process.env.FLEET_LIVE_SETUP_URL?.trim() ?? '';
const password = process.env.FLEET_LIVE_ADMIN_PASSWORD ?? '';

test.skip(!externalBaseURL || !setupUrl || !password, 'live install spec: set FLEET_E2E_BASE_URL, FLEET_LIVE_SETUP_URL and FLEET_LIVE_ADMIN_PASSWORD');
test.describe.configure({ mode: 'serial', timeout: 120_000 });

const ADMIN = {
  username: process.env.FLEET_LIVE_ADMIN_USERNAME ?? 'admin',
  displayName: process.env.FLEET_LIVE_ADMIN_NAME ?? 'Fleet admin',
  email: process.env.FLEET_LIVE_ADMIN_EMAIL ?? 'admin@fleet.example.com',
  password,
};
const VIEWPORT = 'desktop' === process.env.FLEET_LIVE_VIEWPORT ? { width: 1280, height: 800 } : { width: 390, height: 844 };
const OUT = process.env.FLEET_LIVE_OUT ?? 'test-results/live';
const HOST = new URL(baseURL).hostname;
const AUTH_HOST = `auth.${HOST}`;

interface RecordedKey {
  role: string;
  publicKey: string;
  fingerprint: string;
  copied: boolean;
}

let session: BrowserContext;
let page: Page;
let errors: string[];
const keys: RecordedKey[] = [];
let finished = false;
let signedIn = false;

async function shot(target: Page, name: string): Promise<void> {
  mkdirSync(OUT, { recursive: true });
  await target.screenshot({ path: join(OUT, `${name}.png`), fullPage: true });
}

async function signIn(target: Page): Promise<void> {
  await target.getByLabel('Username', { exact: true }).fill(ADMIN.username);
  await target.getByLabel('Password', { exact: true }).fill(ADMIN.password);
  await target.getByRole('button', { name: 'Sign in' }).click();
}

test.beforeAll(async ({ browser }) => {
  // baseURL and the default browser flags come from the config; certificate errors are deliberately NOT ignored.
  session = await browser.newContext({
    baseURL,
    viewport: VIEWPORT,
    permissions: ['clipboard-read', 'clipboard-write'],
    ignoreHTTPSErrors: false,
  });
  page = await session.newPage();
  errors = collectBrowserErrors(page);
});

test.afterAll(async () => {
  await session?.close();
});

test('wizard completes over valid HTTPS', async () => {
  test.setTimeout(420_000);
  expect(baseURL.startsWith('https://'), 'the live host is served over https').toBe(true);

  const seen: WizardStepName[] = [];
  await completeWizard(page, {
    setupUrl,
    admin: ADMIN,
    onStep: async (step, current) => {
      seen.push(step);
      await current.waitForLoadState('networkidle');
      await shot(current, `wizard-${seen.length}-${step}`);
      expect(await hasHorizontalOverflow(current), `horizontal overflow on the ${step} step`).toBe(false);

      if ('ssh' === step) {
        // Recorded now: the SSH step is gone once setup is complete.
        for (const role of ['deploy', 'push']) {
          const field = current.locator(`#key-${role}`);
          await expect(field).toBeVisible();
          const shown = await field.inputValue();
          expect(shown).toMatch(/^ssh-ed25519 \S+/);
          expect(shown).not.toContain('PRIVATE KEY');

          await current.getByTestId(`copy-${role}`).click();
          const copied = (await current.evaluate(() => navigator.clipboard.readText())) === shown;
          const fingerprint = (await current.getByTestId(`setup-key-${role}`).innerText()).match(/SHA256:\S+/)?.[0] ?? '';
          keys.push({ role, publicKey: shown, fingerprint, copied });
        }
      }
      if ('finish' === step) {
        const summary = await current.getByTestId('setup-summary').innerText();
        expect(summary).not.toContain(ADMIN.password);
        await expect(current.getByTestId('summary-claude')).toHaveText('Not set (optional)');
      }
      if ('done' === step) {
        expect(await current.locator('body').innerText()).not.toContain(ADMIN.password);
      }
    },
  });

  finished = true;
  expect(seen).toEqual(['admin', 'tls', 'ssh', 'claude', 'finish', 'done']);
  expect(errors.filter((error) => error.startsWith('pageerror')), 'uncaught script errors').toEqual([]);
});

test('public keys are visible and copyable', async () => {
  expect(finished, 'the wizard ran first').toBe(true);
  expect(keys.map((key) => key.role)).toEqual(['deploy', 'push']);
  for (const key of keys) {
    expect(key.publicKey).toMatch(/^ssh-ed25519 \S+/);
    expect(key.fingerprint, `${key.role} fingerprint`).toMatch(/^SHA256:/);
    expect(key.copied, `${key.role} Copy put the visible key on the clipboard`).toBe(true);
  }
  mkdirSync(OUT, { recursive: true });
  writeFileSync(join(OUT, 'ssh-public-keys.txt'), keys.map((key) => `${key.role}\t${key.fingerprint}\t${key.publicKey}`).join('\n') + '\n');
});

test('reloading shows the Authelia login', async () => {
  expect(finished, 'the wizard ran first').toBe(true);
  // A fresh browser state: the wizard cookie must not matter, and there is no Authelia session yet.
  await session.clearCookies();
  await page.goto('/');
  await expect(page).toHaveURL(new RegExp(`^https://${AUTH_HOST.replace(/\./g, '\\.')}/`));
  await expect(page.getByLabel('Username', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
  await shot(page, 'authelia-login');
});

test('signing in as the new admin lands on the dashboard', async () => {
  await signIn(page);
  await page.waitForURL(new RegExp(`^https://${HOST.replace(/\./g, '\\.')}/`), { timeout: 30_000 });
  await page.waitForLoadState('networkidle');
  await expect(page).toHaveTitle(/Fleet/);
  await expect(page.locator('main#main')).toBeVisible();
  await expect(page.getByTestId('role-summary')).toContainText(ADMIN.displayName);
  if (VIEWPORT.width >= 768) {
    await expect(page.getByTestId('identity')).toContainText(ADMIN.displayName);
  }
  // The admin shell: the Users menu entry exists and its page opens.
  const users = await page.goto('/admin/users');
  expect(users?.status()).toBe(200);
  await shot(page, 'dashboard-admin-users');
  signedIn = true;
  expect(errors.filter((error) => error.startsWith('pageerror')), 'uncaught script errors').toEqual([]);
});

test('the old setup URL no longer works', async ({ browser }) => {
  const late = await browser.newContext({ baseURL, viewport: VIEWPORT, ignoreHTTPSErrors: false });
  try {
    const response = await (await late.newPage()).goto(setupUrl);
    expect(response?.status()).toBe(404);
    expect((await late.cookies()).some((cookie) => cookie.name === '__Host-fleet_setup')).toBe(false);
  } finally {
    await late.close();
  }
});

test('a second token-less visit to /setup is refused after sign-out', async () => {
  expect(signedIn, 'signed in first').toBe(true);
  await page.goto(`https://${AUTH_HOST}/logout`);
  await page.waitForLoadState('networkidle');
  const response = await session.request.get('/setup', { maxRedirects: 0 });
  expect(response.status(), '/setup while signed out').toBe(404);
  expect(await response.text()).not.toContain('Create the administrator');
  const home = await session.request.get('/', { maxRedirects: 0 });
  expect([302, 303, 401], 'signed out, / does not serve the app').toContain(home.status());
});
