import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { blockingViolations } from './support/axe';
import { baseURL, externalBaseURL, ignoreHTTPSErrors } from './support/env';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { completeWizard, readSetupUrl, type WizardStepName } from './support/wizard';

// The setup wizard in a real browser. One token means one walk-through, so the tests run in order and share one
// browser context (the wizard session): refusal without a cookie, the token exchange, every step at desktop width
// (axe, light and dark), the walk-through at phone width ending in Finish, and what every wizard URL says afterwards.
//
// The wizard needs a host that is installed but not set up, with a one-time link. Only start-app.mjs makes one, in a
// throw-away FLEET_HOME; the served dev app of the companion shares a persistent FLEET_HOME, so against an external
// server (FLEET_E2E_BASE_URL) the spec skips itself. FLEET_LIVE_SETUP_URL is the live-server spec's business.
test.skip(!!externalBaseURL, 'needs a fresh FLEET_HOME and a one-time setup link: run with FLEET_E2E_BASE_URL unset (env -u FLEET_E2E_BASE_URL npx playwright test)');

// Axe is CPU-heavy: next to the other specs on a busy machine a test that takes 5 s alone can pass 30 s.
test.describe.configure({ mode: 'serial', timeout: 120_000 });

const COOKIE = '__Host-fleet_setup';
const PHONE = { width: 390, height: 844 };
const DESKTOP = { width: 1280, height: 800 };
const ADMIN = {
  username: 'wizard-admin',
  displayName: 'Wizard Admin',
  email: 'wizard-admin@fleet.example.com',
  password: 'correct horse battery staple',
};
const CLAUDE_TOKEN = 'sk-ant-oat01-wizard-e2e-token-0123456789';
const WIZARD_PATHS = ['/setup', '/setup/admin', '/setup/tls', '/setup/ssh', '/setup/claude', '/setup/finish'] as const;

let setupUrl: string;
let token: string;
let session: BrowserContext;
let page: Page;
let errors: string[];

function newContext(browser: import('@playwright/test').Browser, extra: Parameters<import('@playwright/test').Browser['newContext']>[0] = {}) {
  return browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
    viewport: DESKTOP,
    extraHTTPHeaders: { 'X-Fleet-Profiler-Toolbar': '0' },
    ...extra,
  });
}

async function expectNoHorizontalOverflow(target: Page, label: string): Promise<void> {
  expect(await hasHorizontalOverflow(target), `horizontal overflow on ${label}`).toBe(false);
}

test.beforeAll(async ({ browser }) => {
  setupUrl = readSetupUrl();
  token = new URL(setupUrl).searchParams.get('token') ?? '';
  expect(token.length, 'the setup link carries a token').toBeGreaterThan(20);

  session = await newContext(browser, { permissions: ['clipboard-read', 'clipboard-write'] });
  page = await session.newPage();
  errors = collectBrowserErrors(page);
});

test.afterAll(async () => {
  await session?.close();
});

test('a wizard route without a cookie is refused', async ({ browser }) => {
  const stranger = await newContext(browser);
  try {
    const strangerPage = await stranger.newPage();
    for (const path of ['/setup/admin', '/setup/finish', '/setup']) {
      const response = await strangerPage.goto(path);
      expect(response?.status(), path).toBe(403);
      await expect(strangerPage.getByRole('heading', { level: 1, name: 'Setup link needed' })).toBeVisible();
    }

    // A forged cookie and a wrong token get the same answer, and reveal nothing about the wizard.
    // (Sent as a raw header: a browser only stores a __Host- cookie from a Secure origin.)
    expect((await strangerPage.request.get('/setup/ssh', { headers: { Cookie: `${COOKIE}=forged` } })).status()).toBe(403);
    expect((await strangerPage.goto('/setup?token=not-the-token'))?.status()).toBe(403);
    await expect(strangerPage.getByRole('heading', { level: 1, name: 'Setup link needed' })).toBeVisible();
    expect(await strangerPage.content()).not.toContain('Create the administrator');
    expect(await blockingViolations(strangerPage)).toEqual([]);
  } finally {
    await stranger.close();
  }
});

test('the setup link starts a session: the cookie survives the redirect and the token leaves the address bar', async () => {
  const response = await page.goto(setupUrl);

  // A Strict cookie is sent on a same-site redirect: had it been dropped, the redirect target would be the 403 page.
  const hop = response?.request().redirectedFrom();
  expect(hop, 'the exchange answers with a redirect').not.toBeNull();
  expect((await hop!.response())?.status()).toBe(302);
  expect(response?.status()).toBe(200);
  await expect(page).toHaveURL(/\/setup\/admin$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Create the administrator' })).toBeVisible();

  const url = new URL(page.url());
  expect(url.search).toBe('');
  expect(page.url()).not.toContain(token);
  expect(await page.content()).not.toContain(token);

  const cookie = (await session.cookies()).find((c) => c.name === COOKIE);
  expect(cookie, 'the wizard cookie').toBeDefined();
  expect(cookie).toMatchObject({ httpOnly: true, secure: true, sameSite: 'Strict', path: '/' });
  expect(await page.evaluate(() => document.cookie)).not.toContain('fleet_setup');

  // A reload (no token any more) still works, and the entry URL forwards to the first open step.
  await page.goto('/setup');
  await expect(page).toHaveURL(/\/setup\/admin$/);
});

for (const scheme of ['light', 'dark'] as const) {
  test(`every step fits at desktop width and has no serious or critical axe violation, ${scheme} theme`, async () => {
    await page.setViewportSize(DESKTOP);
    await page.emulateMedia({ colorScheme: scheme });

    for (const path of ['/setup/admin', '/setup/tls', '/setup/ssh', '/setup/claude']) {
      await page.goto(path);
      await page.waitForLoadState('networkidle');
      if ('dark' === scheme) {
        await expect(page.locator('html')).toHaveClass(/\bdark\b/);
      }
      await expectNoHorizontalOverflow(page, `${path} (${scheme})`);
      expect(await blockingViolations(page), `${path} (${scheme})`).toEqual([]);
    }

    // Finish is not reachable before the earlier steps are done: it sends the visitor to the first open one.
    await page.goto('/setup/finish');
    await expect(page).toHaveURL(/\/setup\/admin$/);
    await expect(page.getByText('Finish this step first: Administrator.')).toBeVisible();
    await page.emulateMedia({ colorScheme: null });
  });
}

test('the administrator step refuses a short password and keeps nothing', async () => {
  await page.setViewportSize(PHONE);
  await page.goto('/setup/admin');
  await page.getByLabel('Username', { exact: true }).fill(ADMIN.username);
  await page.getByLabel('Display name', { exact: true }).fill(ADMIN.displayName);
  await page.getByLabel('Email', { exact: true }).fill(ADMIN.email);
  await page.getByLabel('Password', { exact: true }).fill('short');
  await page.getByLabel('Confirm password', { exact: true }).fill('short');
  await page.getByRole('button', { name: 'Save and continue' }).click();

  await expect(page).toHaveURL(/\/setup\/admin$/);
  await expect(page.getByRole('alert').or(page.locator('[id$="_errors"], .text-destructive')).first()).toBeVisible();
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
  await expectNoHorizontalOverflow(page, 'the admin step with an error');
  expect(await blockingViolations(page)).toEqual([]);

  // Chromium logs a form answered with 422 as a failed resource load; that is the refusal itself, not a page error.
  expect(errors.splice(0)).toEqual([expect.stringContaining('status of 422')]);
});

test('the wizard completes at phone width; public keys are visible and copyable', async () => {
  test.setTimeout(300_000);
  await page.setViewportSize(PHONE);
  const seen: WizardStepName[] = [];

  await completeWizard(page, {
    admin: ADMIN,
    claudeToken: CLAUDE_TOKEN,
    onStep: async (step, current) => {
      seen.push(step);
      await current.waitForLoadState('networkidle');
      await expectNoHorizontalOverflow(current, `the ${step} step at 390 px`);
      expect(await blockingViolations(current), `axe on the ${step} step`).toEqual([]);

      if ('ssh' === step) {
        // Both public keys are on the page in full, and Copy puts exactly the visible text on the clipboard.
        for (const role of ['deploy', 'push']) {
          const field = current.locator(`#key-${role}`);
          await expect(field).toBeVisible();
          const shown = await field.inputValue();
          expect(shown).toMatch(/^ssh-ed25519 \S+/);
          expect(shown).not.toContain('PRIVATE KEY');

          await current.getByTestId(`copy-${role}`).click();
          expect(await current.evaluate(() => navigator.clipboard.readText())).toBe(shown);
          expect(await current.getByTestId(`setup-key-${role}`).innerText()).toMatch(/SHA256:/);
        }
      }
      if ('finish' === step) {
        // Review shows facts, not secrets.
        const summary = await current.getByTestId('setup-summary').innerText();
        expect(summary).toContain(ADMIN.username);
        expect(summary).not.toContain(ADMIN.password);
        expect(summary).not.toContain(CLAUDE_TOKEN);
      }
      if ('done' === step) {
        const body = await current.locator('body').innerText();
        expect(body).not.toContain(ADMIN.password);
        expect(body).not.toContain(CLAUDE_TOKEN);
        await expect(current.getByTestId('setup-sign-in')).toBeVisible();
      }
    },
  });

  expect(seen).toEqual(['admin', 'tls', 'ssh', 'claude', 'finish', 'done']);
  expect(errors, 'browser errors during the walk-through').toEqual([]);
});

test('after finish every wizard URL says setup is complete', async ({ browser }) => {
  const complete = async (target: Page, path: string): Promise<void> => {
    const response = await target.goto(path);
    expect(response?.status(), path).toBe(404);
    await expect(target.getByRole('heading', { level: 1, name: 'Setup is complete' }), path).toBeVisible();
    await expect(target.getByTestId('setup-complete')).toContainText('auth.fleet.example.com');
  };

  // The browser that did the setup, and a stranger, see the same page.
  await page.setViewportSize(PHONE);
  for (const path of WIZARD_PATHS) {
    await complete(page, path);
  }
  await expectNoHorizontalOverflow(page, 'the complete page at 390 px');
  expect(await blockingViolations(page)).toEqual([]);

  const stranger = await newContext(browser);
  try {
    const strangerPage = await stranger.newPage();
    for (const path of WIZARD_PATHS) {
      await complete(strangerPage, path);
    }
    // Writing is refused too, whatever the form says: the administrator cannot be overwritten.
    for (const path of ['/setup/admin', '/setup/finish']) {
      expect((await stranger.request.post(path, { form: { x: 'y' } })).status(), `POST ${path}`).toBe(404);
    }
  } finally {
    await stranger.close();
  }
});

test('the old setup URL stops working after finish', async ({ browser }) => {
  const late = await newContext(browser);
  try {
    const latePage = await late.newPage();
    const response = await latePage.goto(setupUrl);
    expect(response?.status()).toBe(404);
    await expect(latePage.getByRole('heading', { level: 1, name: 'Setup is complete' })).toBeVisible();
    expect((await late.cookies()).some((c) => c.name === COOKIE)).toBe(false);
  } finally {
    await late.close();
  }
  // The same link in the session that finished, too.
  expect((await page.goto(setupUrl))?.status()).toBe(404);
  // Chromium logs every 404 page as a failed resource load, so only uncaught script errors are asserted here.
  expect(errors.filter((error) => error.startsWith('pageerror'))).toEqual([]);
});
