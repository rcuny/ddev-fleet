import { expect, type Page } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

export type WizardStepName = 'admin' | 'tls' | 'ssh' | 'claude' | 'finish' | 'done';

export interface WizardAdmin {
  username: string;
  displayName: string;
  email: string;
  password: string;
}

export interface WizardOptions {
  /**
   * The one-time link. Omit it when the page already holds a wizard session (the token was exchanged earlier):
   * the walk-through then starts from the clean entry URL, /setup.
   */
  setupUrl?: string;
  admin: WizardAdmin;
  /** Stored in the Claude step; the step is skipped without it. */
  claudeToken?: string;
  /** Called once per page, after that page's own assertions passed, so a caller can add checks (overflow, axe, copy). */
  onStep?: (step: WizardStepName, page: Page) => Promise<void>;
}

const SETUP_URL_FILE = fileURLToPath(new URL('../.run/setup-url.txt', import.meta.url));

/**
 * The one-time setup link: FLEET_LIVE_SETUP_URL (a real server), else the first line of .run/setup-url.txt, which the
 * self-started app (support/start-app.mjs) writes. Never log it: it is a credential until used.
 */
export function readSetupUrl(): string {
  const fromEnv = process.env.FLEET_LIVE_SETUP_URL?.trim();
  if (fromEnv) {
    return fromEnv;
  }
  if (!existsSync(SETUP_URL_FILE)) {
    throw new Error('No setup link: set FLEET_LIVE_SETUP_URL, or run without FLEET_E2E_BASE_URL so start-app.mjs writes tests/playwright/.run/setup-url.txt.');
  }
  const line = readFileSync(SETUP_URL_FILE, 'utf8').split('\n')[0]?.trim();
  if (!line) {
    throw new Error('tests/playwright/.run/setup-url.txt is empty.');
  }
  return line;
}

/** Walks the five steps (Administrator, Domain and TLS, SSH keys, Claude token, Finish), asserting each page, and presses Finish. */
export async function completeWizard(page: Page, options: WizardOptions): Promise<void> {
  const seen = async (step: WizardStepName): Promise<void> => {
    await options.onStep?.(step, page);
  };

  await page.goto(options.setupUrl ?? '/setup');

  // 1 Administrator
  await expect(page).toHaveTitle('Setup · Administrator · Fleet');
  await expect(page.getByRole('heading', { level: 1, name: 'Create the administrator' })).toBeVisible();
  await expect(page.getByTestId('setup-stepper').locator('li')).toHaveCount(5);
  await expect(page.getByTestId('setup-stepper').locator('[aria-current="step"]')).toHaveCount(1);
  await seen('admin');
  await page.getByLabel('Username', { exact: true }).fill(options.admin.username);
  await page.getByLabel('Display name', { exact: true }).fill(options.admin.displayName);
  await page.getByLabel('Email', { exact: true }).fill(options.admin.email);
  await page.getByLabel('Password', { exact: true }).fill(options.admin.password);
  await page.getByLabel('Confirm password', { exact: true }).fill(options.admin.password);
  await page.getByRole('button', { name: 'Save and continue' }).click();

  // 2 Domain and TLS (the default, HTTP-01, is kept)
  await expect(page).toHaveTitle('Setup · Domain and TLS · Fleet');
  await expect(page.getByRole('heading', { level: 1, name: 'Domain and certificates' })).toBeVisible();
  await expect(page.getByTestId('setup-domain')).not.toBeEmpty();
  await seen('tls');
  await page.getByRole('button', { name: 'Save and continue' }).click();

  // 3 SSH keys
  await expect(page).toHaveTitle('Setup · SSH keys · Fleet');
  await expect(page.getByRole('heading', { level: 1, name: 'SSH keys' })).toBeVisible();
  for (const role of ['deploy', 'push']) {
    await expect(page.locator(`#key-${role}`)).toHaveValue(/^ssh-ed25519 \S+/);
  }
  await seen('ssh');
  await page.getByTestId('setup-next').click();

  // 4 Claude token (optional)
  await expect(page).toHaveTitle('Setup · Claude token · Fleet');
  await expect(page.getByRole('heading', { level: 1, name: 'Claude token' })).toBeVisible();
  await seen('claude');
  if (options.claudeToken) {
    await page.getByLabel('Claude OAuth token').fill(options.claudeToken);
    await page.getByRole('button', { name: 'Save and continue' }).click();
  } else {
    await page.getByRole('button', { name: 'Skip for now' }).click();
  }

  // 5 Finish
  await expect(page).toHaveTitle('Setup · Finish · Fleet');
  await expect(page.getByRole('heading', { level: 1, name: 'Review and finish' })).toBeVisible();
  await expect(page.getByTestId('summary-admin')).toContainText(options.admin.username);
  await expect(page.getByTestId('summary-claude')).toHaveText(options.claudeToken ? 'Stored' : 'Not set (optional)');
  await seen('finish');
  await page.getByTestId('setup-finish').click();

  // Finish can take a minute or two on a real host; a refusal shows up at once and is reported as such.
  const done = page.getByTestId('setup-done');
  const failed = page.getByTestId('setup-finish-error');
  await expect(done.or(failed)).toBeVisible({ timeout: 240_000 });
  if (await failed.isVisible()) {
    throw new Error(`Finish was refused: ${(await failed.innerText()).replace(/\s+/g, ' ')}`);
  }
  await expect(page.getByRole('heading', { level: 1, name: 'Setup finished' })).toBeVisible();
  await seen('done');
}
