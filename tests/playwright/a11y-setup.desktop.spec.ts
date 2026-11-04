import { type Locator, type Page } from '@playwright/test';
import { spawn, spawnSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { join } from 'node:path';
import { expectNoBlockingViolations } from './support/axe';
import { submitAndWait } from './support/projects';
import { expect, test, type WizardHost } from './support/setup-host';

// Axe sweep of the setup wizard (light and dark theme, see support/axe.ts): every page and every distinct refusal state
// that setup-wizard-coverage.spec.ts reaches, one test each. The coverage spec carries no axe call. Each test has a
// wizard host of its own (support/setup-host.ts), as there.

const ADMIN = {
  username: 'cov-admin',
  displayName: 'Coverage Admin',
  email: 'cov-admin@fleet.example.com',
  password: 'correct horse battery staple',
};
const CLAUDE_TOKEN = 'sk-ant-oat01-coverage-token-0123456789';
const OVH = { key: 'ovh-app-key-0001', secret: 'ovh-app-secret-0002', consumer: 'ovh-consumer-0003' };

async function sound(page: Page, where: string): Promise<void> {
  await page.waitForLoadState('networkidle');
  await expectNoBlockingViolations(page, where);
}

async function enter(page: Page, host: WizardHost): Promise<void> {
  await page.goto(host.setupUrl);
  await expect(page).toHaveURL(/\/setup\/admin$/);
}

/** Presses a submit button and returns the status of the POST (302 after success, 422 after a refusal). */
async function submit(page: Page, button: Locator): Promise<number> {
  const [response] = await Promise.all([
    page.waitForResponse((candidate) => candidate.request().method() === 'POST' && candidate.request().isNavigationRequest()),
    submitAndWait(page, button),
  ]);
  return response.status();
}

async function skipBrowserValidation(form: Locator): Promise<void> {
  await form.evaluate((element) => {
    (element as HTMLFormElement).noValidate = true;
  });
}

interface AdminValues {
  username: string;
  displayName: string;
  email: string;
  password: string;
  confirm: string;
}

async function fillAdmin(page: Page, values: Partial<AdminValues> = {}): Promise<void> {
  const v = { ...ADMIN, confirm: values.password ?? ADMIN.password, ...values };
  const username = page.getByLabel('Username', { exact: true });
  if (await username.isEnabled()) {
    await username.fill(v.username);
  }
  await page.getByLabel('Display name', { exact: true }).fill(v.displayName);
  await page.getByLabel('Email', { exact: true }).fill(v.email);
  await page.getByLabel('Password', { exact: true }).fill(v.password);
  await page.getByLabel('Confirm password', { exact: true }).fill(v.confirm);
}

const saveAndContinue = (page: Page): Locator => page.getByRole('button', { name: 'Save and continue' });

async function saveAdmin(page: Page): Promise<void> {
  await fillAdmin(page);
  expect(await submit(page, saveAndContinue(page))).toBe(302);
  await expect(page).toHaveURL(/\/setup\/tls$/);
}

async function saveTls(page: Page): Promise<void> {
  expect(await submit(page, saveAndContinue(page))).toBe(302);
  await expect(page).toHaveURL(/\/setup\/ssh$/);
}

async function chooseMode(page: Page, mode: 'http01' | 'ovh_dns'): Promise<void> {
  await page.getByText(mode === 'http01' ? 'Automatic, per host name (HTTP-01).' : 'DNS challenge through the OVH API (DNS-01).').click();
  await expect(page.locator(`input[name="tls[mode]"][value="${mode}"]`)).toBeChecked();
}

async function fillOvh(page: Page, values: { key?: string; secret?: string; consumer?: string; endpoint?: string } = {}): Promise<void> {
  await chooseMode(page, 'ovh_dns');
  if (values.endpoint) {
    await page.getByLabel('OVH API endpoint').selectOption(values.endpoint);
  }
  await page.getByLabel('OVH application key').fill(values.key ?? OVH.key);
  await page.getByLabel('OVH application secret').fill(values.secret ?? OVH.secret);
  await page.getByLabel('OVH consumer key').fill(values.consumer ?? OVH.consumer);
}

/** Skips the Claude step (or stores a token) from the SSH step on. */
async function throughSsh(page: Page, claudeToken?: string): Promise<void> {
  await submitAndWait(page, page.getByTestId('setup-next'));
  await expect(page).toHaveURL(/\/setup\/claude$/);
  if (claudeToken) {
    await page.getByLabel('Claude OAuth token').fill(claudeToken);
    expect(await submit(page, saveAndContinue(page))).toBe(302);
  } else {
    expect(await submit(page, page.getByRole('button', { name: 'Skip for now' }))).toBe(302);
  }
  await expect(page).toHaveURL(/\/setup\/finish$/);
}

/** From a fresh link to the Finish page, with the default HTTP-01 mode. */
async function toFinish(page: Page, host: WizardHost, claudeToken?: string): Promise<void> {
  await enter(page, host);
  await saveAdmin(page);
  await saveTls(page);
  await throughSsh(page, claudeToken);
}

async function finish(page: Page): Promise<number> {
  return submit(page, page.getByTestId('setup-finish'));
}

test.describe('axe sweep: setup entry pages', () => {
  test('the continue page', async ({ page, host }) => {
    // The browser sets Sec-Fetch-Site itself, so the request is repeated with the header a click in a webmail carries.
    await page.route('**/setup?token=*', async (route) => {
      const upstream = await route.fetch({ headers: { ...route.request().headers(), 'sec-fetch-site': 'cross-site' }, maxRedirects: 0 });
      await route.fulfill({ response: upstream });
    });
    const response = await page.goto(host.setupUrl);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Setup session started' })).toBeVisible();
    await sound(page, 'the continue page');
  });

  test('the refusal page', async ({ page }) => {
    const response = await page.goto('/setup?token=not-the-token');
    expect(response?.status()).toBe(403);
    await expect(page.getByRole('heading', { level: 1, name: 'Setup link needed' })).toBeVisible();
    await sound(page, 'the refusal page');
  });

  test('the throttled page', async ({ page, host, context }) => {
    const statuses: number[] = [];
    for (let attempt = 0; attempt < 15 && !statuses.includes(429); attempt += 1) {
      const response = await context.request.get(`/setup?token=guess-${attempt}`, { maxRedirects: 0 });
      statuses.push(response.status());
    }
    expect(statuses.at(-1), 'the throttle answers 429').toBe(429);
    const response = await page.goto(host.setupUrl);
    expect(response?.status()).toBe(429);
    await expect(page.getByRole('heading', { level: 1, name: 'Too many attempts' })).toBeVisible();
    await sound(page, 'the throttled page');
  });
});

test.describe('axe sweep: setup administrator step', () => {
  test('the administrator step', async ({ page, host }) => {
    await enter(page, host);
    await sound(page, 'the administrator step');
  });

  test('the administrator step with a refused submission', async ({ page, host }) => {
    // One submission with a username, an email and a password problem at once (the three refusals of the coverage spec).
    await enter(page, host);
    await skipBrowserValidation(page.getByTestId('setup-admin-form'));
    await fillAdmin(page, { username: 'Admin', email: 'not an address', password: 'short', confirm: 'different' });
    expect(await submit(page, saveAndContinue(page))).toBe(422);
    await expect(page).toHaveURL(/\/setup\/admin$/);
    await sound(page, 'the administrator step with a refused submission');
  });

  test('the administrator step with an existing administrator', async ({ page, host }) => {
    await enter(page, host);
    await saveAdmin(page);
    await submitAndWait(page, page.getByTestId('setup-stepper').getByRole('link').first());
    await expect(page.getByLabel('Username', { exact: true })).toBeDisabled();
    await sound(page, 'the administrator step with an existing administrator');
  });
});

test.describe('axe sweep: setup TLS step', () => {
  test('the TLS step', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/tls');
    await expect(page.getByRole('heading', { level: 1, name: 'Domain and certificates' })).toBeVisible();
    await sound(page, 'the TLS step');
  });

  test('the TLS step with a refused submission', async ({ page, host }) => {
    // A key of the wrong shape and the two other credentials missing: both kinds of message on one page.
    await enter(page, host);
    await page.goto('/setup/tls');
    await chooseMode(page, 'ovh_dns');
    await page.getByLabel('OVH application key').fill('abcdefg');
    expect(await submit(page, saveAndContinue(page))).toBe(422);
    await expect(page.getByTestId('setup-tls-form')).toContainText('Enter the OVH application secret.');
    await sound(page, 'the TLS step with a refused submission');
  });

  test('the TLS step with stored credentials', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/tls');
    await fillOvh(page, { endpoint: 'ovh-ca' });
    expect(await submit(page, saveAndContinue(page))).toBe(302);
    await page.goto('/setup/tls');
    await expect(page.getByText('Stored. Leave blank to keep it.')).toHaveCount(3);
    await sound(page, 'the TLS step with stored credentials');
  });
});

test.describe('axe sweep: setup SSH step', () => {
  test('the SSH step', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/ssh');
    await expect(page.getByRole('heading', { level: 2, name: 'Trusted Git servers' })).toBeVisible();
    await sound(page, 'the SSH step');
  });

  test('the SSH step with a refused server', async ({ page, host }) => {
    // Every refusal of the coverage spec renders the same error list; one server that offers no key stands for them.
    await enter(page, host);
    await page.goto('/setup/ssh');
    await page.getByLabel('Add a server').fill('git.example.org');
    expect(await submit(page, page.getByRole('button', { name: 'Read host keys' }))).toBe(422);
    await expect(page.getByTestId('setup-ssh-errors')).toContainText('No SSH host key could be read');
    await sound(page, 'the SSH step with a refused server');
  });

  test('the SSH step with a refused replacement', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/ssh');
    await page.getByText('Replace a key').click();
    const form = page.locator('form:has(input[name="action"][value="regenerate"])');
    expect(await submit(page, form.getByRole('button', { name: 'Generate a new key' }))).toBe(422);
    await expect(page.getByTestId('setup-ssh-errors')).toContainText('Tick the box to confirm');
    await sound(page, 'the SSH step with a refused replacement');
  });

  // Importing runs ssh-keygen -y to derive the public key, which the recording runner of the dev app cannot do.
  test.describe('with the real command runner', () => {
    test.use({ hostOptions: { init: true, proxySecret: true, runner: 'process' } });
    test.skip(spawnSync('ssh-keygen', ['-?'], { stdio: 'ignore' }).error !== undefined, 'needs the ssh-keygen binary (openssh-client)');

    test('the SSH step with a refused import', async ({ page, host }) => {
      await enter(page, host);
      await page.goto('/setup/ssh');
      await page.getByText('Replace a key').click();
      const form = page.locator('form:has(input[name="action"][value="import"])');
      await form.getByLabel('Private key file').setInputFiles({ name: 'notes.txt', mimeType: 'application/octet-stream', buffer: Buffer.from('this is not a key\n') });
      await form.getByRole('checkbox').check();
      expect(await submit(page, form.getByRole('button', { name: 'Import this key' }))).toBe(422);
      await expect(page.getByTestId('setup-ssh-errors')).toContainText('That is not an unencrypted SSH private key.');
      await sound(page, 'the SSH step with a refused import');
    });
  });
});

test.describe('axe sweep: setup Claude step', () => {
  test('the Claude step', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/claude');
    await expect(page.getByTestId('setup-claude-stored')).toHaveText('No token is stored.');
    await sound(page, 'the Claude step');
  });

  test('the Claude step with a refused token', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/claude');
    await page.getByLabel('Claude OAuth token').fill('abc');
    expect(await submit(page, saveAndContinue(page))).toBe(422);
    await expect(page.getByTestId('setup-claude-form')).toContainText('That does not look like a Claude token.');
    await sound(page, 'the Claude step with a refused token');
  });

  test('the Claude step with a stored token', async ({ page, host }) => {
    await enter(page, host);
    await saveAdmin(page);
    await page.goto('/setup/ssh');
    await page.goto('/setup/claude');
    await page.getByLabel('Claude OAuth token').fill(CLAUDE_TOKEN);
    expect(await submit(page, saveAndContinue(page))).toBe(302);
    await page.goto('/setup/claude');
    await expect(page.getByTestId('setup-claude-stored')).toHaveText('A token is stored.');
    await sound(page, 'the Claude step with a stored token');
  });
});

test.describe('axe sweep: setup finish step', () => {
  test('the finish step', async ({ page, host }) => {
    await toFinish(page, host, CLAUDE_TOKEN);
    await expect(page.getByRole('heading', { level: 1, name: 'Review and finish' })).toBeVisible();
    await sound(page, 'the finish step');
  });

  test('the finish step in DNS mode', async ({ page, host }) => {
    await enter(page, host);
    await saveAdmin(page);
    await fillOvh(page);
    expect(await submit(page, saveAndContinue(page))).toBe(302);
    await throughSsh(page);
    await expect(page.getByTestId('summary-tls')).toHaveText('DNS challenge through OVH (DNS-01)');
    await sound(page, 'the finish step in DNS mode');
  });

  test.describe('when the configuration cannot be rendered', () => {
    test.use({ hostOptions: { init: true, proxySecret: false, runner: 'recording' } });

    test('the finish step after a refusal', async ({ page, host }) => {
      await toFinish(page, host);
      expect(await finish(page)).toBe(422);
      await expect(page.getByTestId('setup-finish-error')).toBeVisible();
      await sound(page, 'the finish step after a refusal');
    });

    test('the finish step while another Finish runs', async ({ page, host }) => {
      test.skip(spawnSync('flock', ['--version'], { stdio: 'ignore' }).error !== undefined, 'needs flock (util-linux)');
      await toFinish(page, host);
      // The first press leaves the lock file behind; another process holding its lock is "a Finish that is running".
      expect(await finish(page)).toBe(422);
      const lockDir = join(host.home, 'locks');
      const lockFile = readdirSync(lockDir).find((name) => name.includes('setup-finish'));
      expect(lockFile, 'the Finish lock file').toBeDefined();
      const holder = spawn('flock', ['--exclusive', join(lockDir, lockFile!), 'sh', '-c', 'echo locked; exec sleep 60'], {
        stdio: ['ignore', 'pipe', 'ignore'],
        detached: true,
      });
      try {
        await new Promise<void>((resolve, reject) => {
          holder.stdout!.once('data', () => resolve());
          holder.once('error', reject);
          holder.once('close', () => reject(new Error('the lock holder ended before it had the lock')));
        });
        expect(await finish(page)).toBe(409);
        await expect(page.getByTestId('setup-finish-running')).toContainText('Finish is already running');
        await sound(page, 'the finish step while another Finish runs');
      } finally {
        // flock runs sleep as a child that keeps the lock: end the whole process group.
        process.kill(-holder.pid!, 'SIGKILL');
      }
    });
  });

  test('the done page', async ({ page, host }) => {
    await toFinish(page, host, CLAUDE_TOKEN);
    expect(await finish(page)).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Setup finished' })).toBeVisible();
    await sound(page, 'the done page');
  });
});

test.describe('axe sweep: setup guards and reopening', () => {
  test('a step with a guard warning', async ({ page, host }) => {
    await enter(page, host);
    await saveAdmin(page);
    await page.goto('/setup/finish');
    await expect(page).toHaveURL(/\/setup\/ssh$/);
    await expect(page.getByText('Finish this step first: SSH keys.')).toBeVisible();
    await sound(page, 'a step with a guard warning');
  });

  test('the setup complete page', async ({ page, host }) => {
    await toFinish(page, host);
    expect(await finish(page)).toBe(200);
    const response = await page.goto('/setup/admin');
    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1, name: 'Setup is complete' })).toBeVisible();
    await sound(page, 'the setup complete page');
  });

  test('the reopened administrator step', async ({ page, host }) => {
    await toFinish(page, host, CLAUDE_TOKEN);
    expect(await finish(page)).toBe(200);
    const reset = host.console(['fleet:setup:reset', '--yes']);
    expect(reset.status, reset.stderr).toBe(0);
    const link = reset.stdout.split('\n').find((line) => /\/setup\?token=/.test(line));
    expect(link).toBeDefined();
    const reopened = new URL(link!.trim());
    reopened.protocol = new URL(host.baseURL).protocol;
    reopened.host = new URL(host.baseURL).host;
    await page.goto(reopened.href);
    await expect(page).toHaveURL(/\/setup\/finish$/);
    await page.goto('/setup/admin');
    await expect(page.getByLabel('Username', { exact: true })).toBeDisabled();
    await sound(page, 'the reopened administrator step');
  });
});
