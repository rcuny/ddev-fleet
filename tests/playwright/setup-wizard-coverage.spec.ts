import { type BrowserContext, type Locator, type Page } from '@playwright/test';
import { spawn, spawnSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { join } from 'node:path';
import { submitAndWait } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';
import { expect, opensshEd25519Key, test, type WizardHost } from './support/setup-host';

// The coverage companion of setup-wizard.desktop.spec.ts: every step and field of the setup wizard, its refusals, its
// guards and the "fresh host only" rule. That spec walks the one wizard of the served app once; this one gives every
// test a wizard host of its own (support/setup-host.ts: a copy of a prepared FLEET_HOME, its own php server and its own
// client address for the token throttle), so tests are independent, run on the desktop and the phone project, and may
// finish the wizard, reopen it or break it on purpose.

const COOKIE = '__Host-fleet_setup';
const ADMIN = {
  username: 'cov-admin',
  displayName: 'Coverage Admin',
  email: 'cov-admin@fleet.example.com',
  password: 'correct horse battery staple',
};
const CLAUDE_TOKEN = 'sk-ant-oat01-coverage-token-0123456789';
const OVH = { key: 'ovh-app-key-0001', secret: 'ovh-app-secret-0002', consumer: 'ovh-consumer-0003' };
const WIZARD_PATHS = ['/setup', '/setup/admin', '/setup/tls', '/setup/ssh', '/setup/claude', '/setup/finish'];

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

/** Settles the page and checks that it fits the viewport (axe lives in a11y-setup.desktop.spec.ts). */
async function expectSound(page: Page, where: string): Promise<void> {
  await page.waitForLoadState('networkidle');
  await expectNoOverflow(page, where);
}

/** Opens the wizard with the host's one-time link: lands on the first step. */
async function enter(page: Page, host: WizardHost): Promise<void> {
  await page.goto(host.setupUrl);
  await expect(page).toHaveURL(/\/setup\/admin$/);
}

/** The session cookie as a raw header, for API requests (a Secure __Host- cookie is not sent over http by the request context). */
async function sessionHeader(context: BrowserContext): Promise<{ Cookie: string }> {
  const cookie = (await context.cookies()).find((candidate) => candidate.name === COOKIE);
  expect(cookie, 'the wizard session cookie').toBeDefined();
  return { Cookie: `${COOKIE}=${cookie!.value}` };
}

/** Presses a submit button and returns the status of the POST (302 after success, 422 after a refusal). */
async function submit(page: Page, button: Locator): Promise<number> {
  const [response] = await Promise.all([
    page.waitForResponse((candidate) => candidate.request().method() === 'POST' && candidate.request().isNavigationRequest()),
    submitAndWait(page, button),
  ]);
  return response.status();
}

/** The browser would stop an empty required field before the request; the server's own messages are the point here. */
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

async function saveAdmin(page: Page, values: Partial<AdminValues> = {}): Promise<void> {
  await fillAdmin(page, values);
  expect(await submit(page, saveAndContinue(page))).toBe(302);
  await expect(page).toHaveURL(/\/setup\/tls$/);
}

async function saveTls(page: Page): Promise<void> {
  expect(await submit(page, saveAndContinue(page))).toBe(302);
  await expect(page).toHaveURL(/\/setup\/ssh$/);
}

/** The mode radios are visually hidden behind their labels: choose by clicking the label, as a person does. */
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

/** The two public keys and their fingerprints, as the SSH step shows them. */
async function readKeys(page: Page): Promise<Record<'deploy' | 'push', { key: string; fingerprint: string }>> {
  const read = async (role: string): Promise<{ key: string; fingerprint: string }> => ({
    key: await page.locator(`#key-${role}`).inputValue(),
    fingerprint: (await page.getByTestId(`setup-key-${role}`).locator('code').innerText()).trim(),
  });
  return { deploy: await read('deploy'), push: await read('push') };
}

// ---------------------------------------------------------------------------------------------------------------------
test.describe('entry: the one-time link', () => {
  test('a valid link sets a two hour session and the entry URL resumes it', async ({ page, host, context }) => {
    const response = await page.goto(host.setupUrl);
    expect(response?.request().redirectedFrom(), 'the exchange answers with a redirect').not.toBeNull();
    await expect(page).toHaveURL(/\/setup\/admin$/);
    expect(page.url()).not.toContain(host.token);

    const cookie = (await context.cookies()).find((candidate) => candidate.name === COOKIE);
    expect(cookie).toMatchObject({ httpOnly: true, secure: true, sameSite: 'Strict', path: '/' });
    const lifetime = cookie!.expires - Date.now() / 1000;
    expect(lifetime, 'the session lasts about two hours').toBeGreaterThan(7000);
    expect(lifetime).toBeLessThan(7300);

    // The entry URL needs no token once the session exists and forwards to the first step that is still open.
    await page.goto('/setup');
    await expect(page).toHaveURL(/\/setup\/admin$/);
  });

  test('a link opened from another site shows a continue page instead of a redirect', async ({ page, host, context }) => {
    // A SameSite=Strict cookie is not sent on a redirect chain that began on another site (a webmail, a chat): the
    // answer is a page of ours, and the click on it is same-site.
    // The browser sets Sec-Fetch-Site itself, so the request is repeated by the test with the header a click in a
    // webmail would carry, and the answer is handed to the page.
    await page.route('**/setup?token=*', async (route) => {
      const upstream = await route.fetch({ headers: { ...route.request().headers(), 'sec-fetch-site': 'cross-site' }, maxRedirects: 0 });
      await route.fulfill({ response: upstream });
    });
    const response = await page.goto(host.setupUrl);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Setup session started' })).toBeVisible();
    await expect(page.getByText('The setup link was accepted. Continue to the next step.')).toBeVisible();
    // The token has left the address bar (a script swaps it for the clean entry URL).
    await expect(page).toHaveURL(/\/setup$/);
    expect(page.url()).not.toContain(host.token);
    expect((await context.cookies()).some((candidate) => candidate.name === COOKIE)).toBe(true);
    await expectSound(page, 'the continue page');

    await submitAndWait(page, page.getByTestId('setup-continue'));
    await expect(page).toHaveURL(/\/setup\/admin$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Create the administrator' })).toBeVisible();
  });
});

// ---------------------------------------------------------------------------------------------------------------------
test.describe('step 1: administrator', () => {
  test('the form, its hints and the stepper', async ({ page, host }) => {
    await enter(page, host);

    await expect(page).toHaveTitle('Setup · Administrator · Fleet');
    await expect(page.getByRole('heading', { level: 1, name: 'Create the administrator' })).toBeVisible();
    await expect(page.getByText('This account signs in to Fleet once setup is finished.')).toBeVisible();
    await expect(page.getByText('It already exists')).toHaveCount(0);
    await expect(page.getByText('Lowercase letters, digits, dot, underscore or dash. You sign in with it.')).toBeVisible();
    await expect(page.getByText('At least 12 characters. A passphrase works well.')).toBeVisible();

    const username = page.getByLabel('Username', { exact: true });
    await expect(username).toBeEnabled();
    await expect(username).toHaveValue('');
    await expect(username).toHaveAttribute('autocomplete', 'username');
    await expect(page.getByLabel('Display name', { exact: true })).toHaveAttribute('autocomplete', 'name');
    await expect(page.getByLabel('Email', { exact: true })).toHaveAttribute('type', 'email');
    for (const label of ['Password', 'Confirm password']) {
      const field = page.getByLabel(label, { exact: true });
      await expect(field).toHaveAttribute('type', 'password');
      await expect(field).toHaveAttribute('autocomplete', 'new-password');
      await expect(field).toHaveValue('');
    }

    // Five steps, the first is the current one, every step is a link.
    const stepper = page.getByTestId('setup-stepper');
    await expect(stepper.locator('li')).toHaveCount(5);
    await expect(stepper.locator('[aria-current="step"]')).toHaveCount(1);
    await expect(stepper.locator('li').first().locator('a')).toHaveAttribute('aria-current', 'step');
    const hrefs = await stepper.locator('a').evaluateAll((links) => links.map((link) => new URL((link as HTMLAnchorElement).href).pathname));
    expect(hrefs).toEqual(['/setup/admin', '/setup/tls', '/setup/ssh', '/setup/claude', '/setup/finish']);
    await expectSound(page, 'the administrator step');
  });

  test('the browser stops an empty form before the server is asked', async ({ page, host }) => {
    await enter(page, host);
    await saveAndContinue(page).click();
    await expect(page).toHaveURL(/\/setup\/admin$/);
    expect(await page.getByLabel('Username', { exact: true }).evaluate((field) => (field as HTMLInputElement).validity.valueMissing)).toBe(true);
    await expect(page.getByText('Enter a username.')).toHaveCount(0);
  });

  test('the longest values wrap on every page that shows them @phone', async ({ page, host }) => {
    const username = `a${'b'.repeat(62)}c`;
    const displayName = 'D'.repeat(255);
    const email = `${'e'.repeat(64)}@${'x'.repeat(63)}.${'y'.repeat(63)}.${'z'.repeat(55)}.com`;
    await enter(page, host);
    await saveAdmin(page, { username, displayName, email });
    await expectNoOverflow(page, 'the TLS step after a long administrator');

    await page.goto('/setup/admin');
    await expect(page.getByLabel('Display name', { exact: true })).toHaveValue(displayName);
    await expect(page.getByLabel('Email', { exact: true })).toHaveValue(email);
    await expectNoOverflow(page, 'the administrator step with long values');

    await page.goto('/setup/ssh');
    await submitAndWait(page, page.getByTestId('setup-next'));
    expect(await submit(page, page.getByRole('button', { name: 'Skip for now' }))).toBe(302);
    await expect(page.getByTestId('summary-admin')).toContainText(username);
    await expect(page.getByTestId('summary-admin')).toContainText(email);
    await expectNoOverflow(page, 'the finish step with a long administrator');
  });
});

// ---------------------------------------------------------------------------------------------------------------------
test.describe('step 2: domain and TLS', () => {
  test('the form shows the domain, HTTP-01 chosen and empty write-only OVH fields', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/tls');

    await expect(page).toHaveTitle('Setup · Domain and TLS · Fleet');
    await expect(page.getByRole('heading', { level: 1, name: 'Domain and certificates' })).toBeVisible();
    await expect(page.getByTestId('setup-domain')).toHaveText('fleet.example.com');
    await expect(page.getByText('The domain was set by the installer.')).toBeVisible();

    const modes = page.getByRole('radio');
    await expect(modes).toHaveCount(2);
    await expect(modes.first()).toBeChecked();
    await expect(modes.last()).not.toBeChecked();
    await expect(page.getByText('Automatic, per host name (HTTP-01). Needs DNS for the domain and auth.<domain> pointing here.')).toBeVisible();
    await expect(page.getByText('DNS challenge through the OVH API (DNS-01). Needs OVH API credentials.')).toBeVisible();

    await expect(page.getByText('Only used in DNS mode. Stored encrypted; never shown again.')).toBeVisible();
    await expect(page.getByLabel('OVH API endpoint').locator('option')).toHaveText(['ovh-eu', 'ovh-ca', 'ovh-us']);
    await expect(page.getByLabel('OVH API endpoint')).toHaveValue('ovh-eu');
    await expect(page.getByText('Use an API application created on the regional OVH site of that endpoint.')).toBeVisible();
    for (const [label, hint] of [
      ['OVH application key', 'From your OVH API application.'],
      ['OVH application secret', 'From your OVH API application.'],
      ['OVH consumer key', 'Needs the right to manage DNS records of the zone.'],
    ]) {
      const field = page.getByLabel(label);
      await expect(field).toHaveAttribute('type', 'password');
      await expect(field).toHaveValue('');
      await expect(page.getByText(hint).first()).toBeVisible();
    }
    await expectSound(page, 'the TLS step');
  });

  test('a stored OVH mode with missing credentials sends the visitor back to the TLS step', async ({ page, host }) => {
    expect(host.console(['fleet:host:init', '--tls-mode=ovh_dns']).status).toBe(0);
    await enter(page, host);
    await saveAdmin(page);
    // The wizard's next open step is TLS, not SSH, until the credentials exist.
    await page.goto('/setup');
    await expect(page).toHaveURL(/\/setup\/tls$/);
    await expect(page.getByRole('radio').last()).toBeChecked();
    await page.goto('/setup/finish');
    await expect(page).toHaveURL(/\/setup\/tls$/);
    await expect(page.getByText('Finish this step first: Domain and TLS.')).toBeVisible();

    await fillOvh(page);
    expect(await submit(page, saveAndContinue(page))).toBe(302);
    await expect(page).toHaveURL(/\/setup\/ssh$/);
  });
});

// ---------------------------------------------------------------------------------------------------------------------
test.describe('step 3: SSH keys', () => {
  test('both public keys are shown in full with fingerprints and stay the same', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/ssh');

    await expect(page).toHaveTitle('Setup · SSH keys · Fleet');
    await expect(page.getByRole('heading', { level: 1, name: 'SSH keys' })).toBeVisible();
    await expect(page.getByText('Deploy key (clones repositories)')).toBeVisible();
    await expect(page.getByText('Push key (pushes to remotes)')).toBeVisible();
    const keys = await readKeys(page);
    for (const role of ['deploy', 'push'] as const) {
      expect(keys[role].key).toMatch(/^ssh-ed25519 [A-Za-z0-9+/=]+ \S+$/);
      expect(keys[role].fingerprint).toMatch(/^SHA256:[A-Za-z0-9+/]{43}$/);
      await expect(page.locator(`#key-${role}`)).toHaveAttribute('readonly', '');
    }
    expect(keys.deploy.key).not.toBe(keys.push.key);
    expect(await page.content()).not.toContain('PRIVATE KEY');

    // Copy puts exactly the shown key on the clipboard and says so politely.
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: host.baseURL });
    for (const role of ['deploy', 'push'] as const) {
      await page.getByTestId(`copy-${role}`).click();
      await expect(page.getByTestId(`setup-key-${role}`).locator('[aria-live="polite"]')).toHaveText('Copied');
      expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(keys[role].key);
    }

    // The keys exist once: a reload shows the same ones, and Finish quotes their fingerprints.
    await page.reload();
    expect(await readKeys(page)).toEqual(keys);
    expect(host.scalar('SELECT COUNT(*) FROM ssh_key')).toBe(2);
    await expectSound(page, 'the SSH step');
  });

  test('the known servers: the seeded providers are listed and Continue goes to the Claude step', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/ssh');
    await expect(page.getByRole('heading', { level: 2, name: 'Trusted Git servers' })).toBeVisible();
    const rows = page.getByTestId('setup-known-hosts').locator('tbody tr');
    await expect(rows).toHaveCount(3);
    for (const name of ['github.com', 'gitlab.com', 'bitbucket.org']) {
      await expect(page.getByTestId('setup-known-hosts')).toContainText(name);
    }
    await expect(page.getByTestId('setup-known-hosts')).toContainText('ed25519');
    await expect(page.getByLabel('Add a server')).toHaveAttribute('placeholder', 'git.example.com');
    await expect(page.getByText('A host name, or [host]:port for another port. Fleet reads its keys; you confirm them.')).toBeVisible();

    await submitAndWait(page, page.getByTestId('setup-next'));
    await expect(page).toHaveURL(/\/setup\/claude$/);
  });

  const syntax = 'Enter a host name such as git.example.com, or [git.example.com]:2222 for another port.';
  const octets = 'Write an IP address as four decimal numbers, for example 203.0.113.7.';
  const serverRefusals: Record<string, Array<[string, string, string]>> = {
    'names that are not host names': [
      ['blank', '', syntax],
      ['spaces', 'git example com', syntax],
      ['a URL', 'https://git.example.com/', syntax],
      ['an unclosed bracket', '[git.example.com', syntax],
    ],
    'addresses and names that point at this machine': [
      ['localhost', 'localhost', "That is this machine; enter the git server's own host name."],
      ['a localhost subdomain', 'db.localhost', "That is this machine; enter the git server's own host name."],
      ['a loopback address', '127.0.0.1', 'Loopback, link-local and "this host" addresses cannot be scanned.'],
      ['the unspecified address', '0.0.0.0', 'Loopback, link-local and "this host" addresses cannot be scanned.'],
      ['a link-local address', '169.254.169.254', 'Loopback, link-local and "this host" addresses cannot be scanned.'],
      ['three octets', '10.0.0', octets],
      ['an octet over 255', '10.0.0.256', octets],
      ['a leading zero', '10.00.0.1', octets],
    ],
    'servers that are trusted already or offer no key': [
      ['an already trusted server', 'github.com', '"github.com" is already trusted; nothing to add.'],
      ['an already trusted server in capitals', 'GitLab.com', '"gitlab.com" is already trusted; nothing to add.'],
      ['a server that offers no key', 'git.example.org', 'No SSH host key could be read from "git.example.org". Check the name and that the server answers on that port.'],
      ['a server on another port', '[git.example.org]:2222', 'No SSH host key could be read from "[git.example.org]:2222".'],
    ],
  };
  for (const [group, cases] of Object.entries(serverRefusals)) {
    test(`adding a server is refused for ${group}`, async ({ page, host }) => {
      await enter(page, host);
      await page.goto('/setup/ssh');
      for (const [what, value, expected] of cases) {
        await page.getByLabel('Add a server').fill(value);
        expect(await submit(page, page.getByRole('button', { name: 'Read host keys' })), what).toBe(422);
        await expect(page.getByTestId('setup-ssh-errors'), what).toContainText(expected);
        await expect(page.getByTestId('setup-ssh-scan'), what).toHaveCount(0);
        await expect(page.getByTestId('setup-known-hosts').locator('tbody tr'), what).toHaveCount(3);
      }
      await expectSound(page, 'the SSH step with a refused server');
    });
  }

  test('a very long server name does not widen the page @phone', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/ssh');
    const name = `${'a'.repeat(63)}.${'b'.repeat(63)}.${'c'.repeat(63)}.example.org`;
    await page.getByLabel('Add a server').fill(name);
    expect(await submit(page, page.getByRole('button', { name: 'Read host keys' }))).toBe(422);
    await expect(page.getByTestId('setup-ssh-errors')).toContainText('No SSH host key could be read from');
    await expectNoOverflow(page, 'the SSH step with a long server name');

    await page.getByLabel('Add a server').fill('z'.repeat(400));
    expect(await submit(page, page.getByRole('button', { name: 'Read host keys' }))).toBe(422);
    await expect(page.getByTestId('setup-ssh-errors')).toContainText(/Enter a host name such as git\.example\.com/);
    await expectNoOverflow(page, 'the SSH step with an absurd server name');
  });

  test('an unknown action is refused', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/ssh');
    await page.locator('form:has(input[name="action"][value="scan"]) input[name="action"]').evaluate((input) => {
      (input as HTMLInputElement).value = 'format-disk';
    });
    await page.getByLabel('Add a server').fill('git.example.org');
    expect(await submit(page, page.getByRole('button', { name: 'Read host keys' }))).toBe(422);
    await expect(page.getByTestId('setup-ssh-errors')).toContainText('Unknown action.');
  });

  test('replacing a key needs the confirmation and a real key choice', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/ssh');
    const before = await readKeys(page);
    await page.getByText('Replace a key').click();
    const form = page.locator('form:has(input[name="action"][value="regenerate"])');
    const unconfirmed = 'Tick the box to confirm: the old key stops working wherever it is registered.';

    await expect(form.getByText('Generates a fresh key. The old one stops working wherever it is registered.')).toBeVisible();
    await expect(form.locator('select')).toHaveValue('deploy');
    await expect(form.locator('select option')).toHaveText(['Deploy key', 'Push key']);
    expect(await submit(page, form.getByRole('button', { name: 'Generate a new key' }))).toBe(422);
    await expect(page.getByTestId('setup-ssh-errors')).toContainText(unconfirmed);
    expect(await readKeys(page)).toEqual(before);

    await page.getByText('Replace a key').click();
    await form.locator('select').evaluate((select) => {
      const option = document.createElement('option');
      option.value = 'root';
      select.append(option);
      (select as HTMLSelectElement).value = 'root';
    });
    await form.getByRole('checkbox').check();
    expect(await submit(page, form.getByRole('button', { name: 'Generate a new key' }))).toBe(422);
    await expect(page.getByTestId('setup-ssh-errors')).toContainText('Choose which key to replace.');
    expect(await readKeys(page)).toEqual(before);
    await expectSound(page, 'the SSH step with a refused replacement');
  });

  test('a replaced key changes only that key, and Finish quotes the new fingerprint', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/ssh');
    const before = await readKeys(page);

    await page.getByText('Replace a key').click();
    const form = page.locator('form:has(input[name="action"][value="regenerate"])');
    await form.locator('select').selectOption('push');
    await form.getByRole('checkbox').check();
    expect(await submit(page, form.getByRole('button', { name: 'Generate a new key' }))).toBe(302);
    await expect(page.getByText('A new push key was generated. Register its public key.')).toBeVisible();

    const after = await readKeys(page);
    expect(after.deploy).toEqual(before.deploy);
    expect(after.push.key).not.toBe(before.push.key);
    expect(after.push.fingerprint).not.toBe(before.push.fingerprint);
    expect(host.scalar('SELECT COUNT(*) FROM ssh_key')).toBe(2);

    await saveAdminForFinish(page);
    await expect(page.getByTestId('summary-key-push')).toHaveText(after.push.fingerprint);
    await expect(page.getByTestId('summary-key-deploy')).toHaveText(after.deploy.fingerprint);
  });

  // Importing runs ssh-keygen -y to derive the public key, which the recording runner of the dev app cannot do.
  test.describe('with the real command runner', () => {
    test.use({ hostOptions: { init: true, proxySecret: true, runner: 'process' } });
    test.skip(spawnSync('ssh-keygen', ['-?'], { stdio: 'ignore' }).error !== undefined, 'needs the ssh-keygen binary (openssh-client)');

    test('importing a key: every refusal, then a real key replaces the chosen one', async ({ page, host }) => {
      await enter(page, host);
      await page.goto('/setup/ssh');
      const before = await readKeys(page);
      const errors = page.getByTestId('setup-ssh-errors');
      const form = page.locator('form:has(input[name="action"][value="import"])');
      const importButton = form.getByRole('button', { name: 'Import this key' });
      const open = async (): Promise<void> => {
        if (!(await form.isVisible())) {
          await page.getByText('Replace a key').click();
        }
      };
      const attempt = async (what: string, file: { name: string; buffer: Buffer } | null, confirm: boolean, expected: string): Promise<void> => {
        await open();
        if (file) {
          await form.getByLabel('Private key file').setInputFiles({ name: file.name, mimeType: 'application/octet-stream', buffer: file.buffer });
        }
        if (confirm) {
          await form.getByRole('checkbox').check();
        }
        expect(await submit(page, importButton), what).toBe(422);
        await expect(errors, what).toContainText(expected);
        expect(await readKeys(page), `${what}: nothing changed`).toEqual(before);
      };

      const real = opensshEd25519Key();
      await attempt('no confirmation', { name: 'id', buffer: Buffer.from(real.pem) }, false, 'Tick the box to confirm: the old key stops working wherever it is registered.');
      await attempt('no file', null, true, 'Choose the private key file to import.');
      await attempt('a text file', { name: 'notes.txt', buffer: Buffer.from('this is not a key\n') }, true, 'That is not an unencrypted SSH private key.');
      await attempt('a public key', { name: 'id.pub', buffer: Buffer.from(`${real.publicLine} comment\n`) }, true, 'That is not an unencrypted SSH private key.');
      await attempt('a NUL byte', { name: 'id', buffer: Buffer.from(`${real.pem}\0`) }, true, 'That is not an unencrypted SSH private key.');
      await attempt('a broken key', { name: 'id', buffer: Buffer.from('-----BEGIN OPENSSH PRIVATE KEY-----\nbm90IGEga2V5\n-----END OPENSSH PRIVATE KEY-----\n') }, true, 'The private key is invalid or protected by a passphrase.');
      await attempt('a file over 16 KiB', { name: 'big', buffer: Buffer.from(`-----BEGIN OPENSSH PRIVATE KEY-----\n${'A'.repeat(17000)}\n-----END OPENSSH PRIVATE KEY-----\n`) }, true, 'That file is too large to be an SSH private key.');
      await expectSound(page, 'the SSH step with a refused import');

      // A real key: the chosen role changes, to exactly the key that was uploaded.
      await open();
      await form.locator('select').selectOption('deploy');
      await form.getByLabel('Private key file').setInputFiles({ name: 'id_ed25519', mimeType: 'application/octet-stream', buffer: Buffer.from(real.pem) });
      await form.getByRole('checkbox').check();
      expect(await submit(page, importButton)).toBe(302);
      await expect(page.getByText('The deploy key was replaced by the imported one.')).toBeVisible();
      const after = await readKeys(page);
      expect(after.deploy.fingerprint).toBe(real.fingerprint);
      expect(after.deploy.key.startsWith(real.publicLine)).toBe(true);
      expect(after.push).toEqual(before.push);
      expect(await page.content()).not.toContain('PRIVATE KEY');
    });
  });
});

/** From the SSH step on to Finish without a Claude token (the administrator is saved first when there is none). */
async function saveAdminForFinish(page: Page): Promise<void> {
  await page.goto('/setup/admin');
  await fillAdmin(page);
  expect(await submit(page, saveAndContinue(page))).toBe(302);
  await page.goto('/setup/ssh');
  await throughSsh(page);
}

// ---------------------------------------------------------------------------------------------------------------------
test.describe('step 4: Claude token', () => {
  test('the step starts empty and says no token is stored', async ({ page, host }) => {
    await enter(page, host);
    await page.goto('/setup/claude');

    await expect(page).toHaveTitle('Setup · Claude token · Fleet');
    await expect(page.getByRole('heading', { level: 1, name: 'Claude token' })).toBeVisible();
    await expect(page.getByTestId('setup-claude-stored')).toHaveText('No token is stored.');
    await expect(page.getByText('Run "claude setup-token" on any machine and paste the result. Optional: you can add it later.')).toBeVisible();
    const field = page.getByLabel('Claude OAuth token');
    await expect(field).toHaveAttribute('type', 'password');
    await expect(field).toHaveAttribute('autocomplete', 'off');
    await expect(field).not.toHaveAttribute('required');
    await expect(page.getByRole('button', { name: 'Save and continue' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Skip for now' })).toBeVisible();
    await expectSound(page, 'the Claude step');
  });

  test('skipping stores nothing and the summary says so', async ({ page, host }) => {
    await toFinish(page, host);
    await expect(page.getByTestId('summary-claude')).toHaveText('Not set (optional)');
    expect(host.scalar("SELECT COUNT(*) FROM secret WHERE name = 'claude_oauth_token'")).toBe(0);
    await page.goto('/setup/claude');
    await expect(page.getByTestId('setup-claude-stored')).toHaveText('No token is stored.');
  });
});

// ---------------------------------------------------------------------------------------------------------------------
test.describe('step 5: finish', () => {
  test('the summary lists the answers without secrets, and every step is one link away', async ({ page, host }) => {
    await toFinish(page, host, CLAUDE_TOKEN);

    await expect(page).toHaveTitle('Setup · Finish · Fleet');
    await expect(page.getByRole('heading', { level: 1, name: 'Review and finish' })).toBeVisible();
    await expect(page.getByText('Finishing switches on the sign-in gate.')).toBeVisible();
    await expect(page.getByTestId('summary-domain')).toHaveText('fleet.example.com');
    await expect(page.getByTestId('summary-tls')).toHaveText('Automatic per host name (HTTP-01)');
    await expect(page.getByTestId('summary-admin')).toHaveText(`${ADMIN.username} (${ADMIN.email})`);
    await expect(page.getByTestId('summary-claude')).toHaveText('Stored');
    await expect(page.getByText('Deploy key fingerprint')).toBeVisible();
    await expect(page.getByText('Push key fingerprint')).toBeVisible();
    for (const role of ['deploy', 'push']) {
      await expect(page.getByTestId(`summary-key-${role}`)).toHaveText(/^SHA256:[A-Za-z0-9+/]{43}$/);
    }
    const summary = await page.getByTestId('setup-summary').innerText();
    expect(summary).not.toContain(ADMIN.password);
    expect(summary).not.toContain(CLAUDE_TOKEN);
    await expect(page.getByTestId('setup-finish')).toHaveText('Finish setup');
    await expect(page.getByTestId('setup-stepper').locator('[aria-current="step"]')).toHaveCount(1);
    await expectSound(page, 'the finish step');

    // The SSH step shows the fingerprints the summary quotes.
    const summaryKeys = [await page.getByTestId('summary-key-deploy').innerText(), await page.getByTestId('summary-key-push').innerText()];
    await submitAndWait(page, page.getByTestId('setup-stepper').getByRole('link').nth(2));
    const keys = await readKeys(page);
    expect([keys.deploy.fingerprint, keys.push.fingerprint]).toEqual(summaryKeys);
  });

  test('the summary names the DNS-01 mode after an OVH choice', async ({ page, host }) => {
    await enter(page, host);
    await saveAdmin(page);
    await fillOvh(page);
    expect(await submit(page, saveAndContinue(page))).toBe(302);
    await throughSsh(page);
    await expect(page.getByTestId('summary-tls')).toHaveText('DNS challenge through OVH (DNS-01)');
    const html = await page.content();
    for (const value of Object.values(OVH)) {
      expect(html).not.toContain(value);
    }
    await expectSound(page, 'the finish step in DNS mode');
  });

  test.describe('when the configuration cannot be rendered', () => {
    test.use({ hostOptions: { init: true, proxySecret: false, runner: 'recording' } });

    test('Finish says why, marks nothing complete and leaves the link working', async ({ page, host }) => {
      await toFinish(page, host);
      expect(await finish(page)).toBe(422);

      const failed = page.getByTestId('setup-finish-error');
      await expect(failed).toBeVisible();
      await expect(failed).toContainText('Setup did not finish');
      await expect(failed).toContainText('The settings cannot be applied:');
      await expect(failed).toContainText('Nothing was marked complete and this link still works. Fix the problem, then press Finish again.');
      await expect(page.getByTestId('setup-done')).toHaveCount(0);
      // The summary is still there to review, and the same click can be repeated.
      await expect(page.getByTestId('summary-admin')).toContainText(ADMIN.username);
      await expectSound(page, 'the finish step after a refusal');
      expect(await finish(page)).toBe(422);
      await expect(failed).toBeVisible();

      expect(host.scalar('SELECT setup_completed_at FROM host_settings')).toBeNull();
      expect(host.scalar('SELECT setup_token_hash IS NOT NULL FROM host_settings')).toBe(1);
      expect((await page.goto('/setup/finish'))?.status()).toBe(200);
      await expect(page.getByRole('heading', { level: 1, name: 'Review and finish' })).toBeVisible();
    });

    test('a second Finish while one is running is told to wait', async ({ page, host }) => {
      test.skip(spawnSync('flock', ['--version'], { stdio: 'ignore' }).error !== undefined, 'needs flock (util-linux)');
      await toFinish(page, host);
      // The first press leaves the lock file behind; another process holding its lock is "a Finish that is running".
      expect(await finish(page)).toBe(422);
      const lockDir = join(host.home, 'locks');
      const lockFile = readdirSync(lockDir).find((name) => name.includes('setup-finish'));
      expect(lockFile, 'the Finish lock file').toBeDefined();
      // The holder says so on stdout once flock has the lock, so nothing here races the process start on a busy host.
      const holder = spawn('flock', ['--exclusive', join(lockDir, lockFile!), 'sh', '-c', 'echo locked; exec sleep 60'], {
        stdio: ['ignore', 'pipe', 'ignore'],
        detached: true,
      });
      // 'close' fires once every process holding the pipe (flock and the sleep it runs) is gone, i.e. the lock is released.
      const holderGone = new Promise<void>((resolve) => holder.once('close', () => resolve()));
      try {
        await new Promise<void>((resolve, reject) => {
          holder.stdout!.once('data', () => resolve());
          holder.once('error', reject);
          holder.once('close', () => reject(new Error('the lock holder ended before it had the lock')));
        });
        expect(await finish(page)).toBe(409);
        const running = page.getByTestId('setup-finish-running');
        await expect(running).toContainText('Finish is already running');
        await expect(running).toContainText('Another request is finishing the setup');
        await expect(page.getByTestId('setup-finish-error')).toHaveCount(0);
        await expectSound(page, 'the finish step while another Finish runs');
      } finally {
        // flock runs sleep as a child that keeps the lock: end the whole process group.
        process.kill(-holder.pid!, 'SIGKILL');
      }
      // Once the other one is gone the button works again (and gives the ordinary refusal of this host).
      await holderGone;
      expect(await finish(page)).toBe(422);
      await expect(page.getByTestId('setup-finish-error')).toBeVisible();
    });
  });

  test('Finish switches setup off: the done page, the cleared session and a closed token', async ({ page, host, context }) => {
    await toFinish(page, host, CLAUDE_TOKEN);
    expect(await finish(page)).toBe(200);

    await expect(page).toHaveTitle('Setup · Finish · Fleet');
    await expect(page.getByRole('heading', { level: 1, name: 'Setup finished' })).toBeVisible();
    await expect(page.getByTestId('setup-done')).toHaveText('Fleet is ready on fleet.example.com.');
    await expect(page.getByTestId('setup-steps-done').locator('li')).toHaveText([
      'Administrator written to the sign-in service',
      'Sign-in service restarted',
      'Sign-in gate switched on',
      'Setup marked complete',
    ]);
    await expect(page.getByText('Sign-in is now handled by Authelia. Use the administrator you just created.')).toBeVisible();
    await expect(page.getByTestId('setup-sign-in')).toHaveAttribute('href', '/');
    await expect(page.getByTestId('setup-stepper')).toHaveCount(0);
    const body = await page.locator('body').innerText();
    expect(body).not.toContain(ADMIN.password);
    expect(body).not.toContain(CLAUDE_TOKEN);
    await expectSound(page, 'the done page');

    expect((await context.cookies()).some((candidate) => candidate.name === COOKIE)).toBe(false);
    expect(host.scalar('SELECT setup_completed_at IS NOT NULL FROM host_settings')).toBe(1);
    expect(host.scalar('SELECT setup_token_hash IS NULL FROM host_settings')).toBe(1);
    expect(host.scalar("SELECT COUNT(*) FROM fleet_user WHERE username = 'cov-admin'")).toBe(1);
  });

  test('Finish in DNS mode also reports the certificate settings @phone', async ({ page, host }) => {
    await enter(page, host);
    await saveAdmin(page);
    await fillOvh(page);
    expect(await submit(page, saveAndContinue(page))).toBe(302);
    await throughSsh(page);
    expect(await finish(page)).toBe(200);

    await expect(page.getByTestId('setup-steps-done').locator('li')).toHaveText([
      'Administrator written to the sign-in service',
      'Sign-in service restarted',
      'Sign-in gate switched on',
      'Certificate settings applied',
      'Setup marked complete',
    ]);
    await expectNoOverflow(page, 'the done page in DNS mode');
  });
});

// ---------------------------------------------------------------------------------------------------------------------
test.describe('step guards and back navigation', () => {
  test('Finish is out of reach until the earlier steps are done, and the entry URL goes to the first open step', async ({ page, host, context }) => {
    await enter(page, host);
    const header = await sessionHeader(context);
    const entry = async (): Promise<string | undefined> => (await page.request.get(`${host.baseURL}/setup`, { headers: header, maxRedirects: 0 })).headers()['location'];

    await page.goto('/setup/finish');
    await expect(page).toHaveURL(/\/setup\/admin$/);
    await expect(page.getByText('Finish this step first: Administrator.')).toBeVisible();
    expect(await entry()).toMatch(/\/setup\/admin$/);

    await saveAdmin(page);
    // The keys are made when the SSH step is first opened: until then it is the open step.
    expect(await entry()).toMatch(/\/setup\/ssh$/);
    await page.goto('/setup/finish');
    await expect(page).toHaveURL(/\/setup\/ssh$/);
    await expect(page.getByText('Finish this step first: SSH keys.')).toBeVisible();
    await expectSound(page, 'a step with a guard warning');

    // That visit made the keys: everything required is in place, and the entry URL goes to the summary.
    expect(await entry()).toMatch(/\/setup\/finish$/);
    await page.goto('/setup/admin');
    await page.goto('/setup/finish');
    await expect(page).toHaveURL(/\/setup\/finish$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Review and finish' })).toBeVisible();
  });

  test('the middle steps open directly, and the stepper links go to every step', async ({ page, host }) => {
    await enter(page, host);
    const names = ['Administrator', 'Domain and TLS', 'SSH keys', 'Claude token', 'Review and finish'];
    const headings = ['Create the administrator', 'Domain and certificates', 'SSH keys', 'Claude token', 'Review and finish'];
    await saveAdmin(page);
    await page.goto('/setup/ssh');
    await page.goto('/setup/finish');
    const stepper = page.getByTestId('setup-stepper');
    const links = stepper.getByRole('link');
    await expect(links).toHaveCount(5);
    for (let index = 0; index < 5; index += 1) {
      await submitAndWait(page, page.getByTestId('setup-stepper').getByRole('link').nth(index));
      await expect(page.getByRole('heading', { level: 1, name: headings[index] }), names[index]).toBeVisible();
      await expect(page.getByTestId('setup-stepper').locator('[aria-current="step"]')).toHaveCount(1);
      await expect(page.getByTestId('setup-stepper').getByRole('link').nth(index)).toHaveAttribute('aria-current', 'step');
    }
  });

  test('going back keeps every answer', async ({ page, host }) => {
    await enter(page, host);
    await saveAdmin(page);
    await fillOvh(page, { endpoint: 'ovh-us' });
    expect(await submit(page, saveAndContinue(page))).toBe(302);
    await page.goto('/setup/ssh');
    const keys = await readKeys(page);
    await throughSsh(page, CLAUDE_TOKEN);

    // Step by step with the browser's Back button, then forward again by the stepper.
    await page.goBack();
    await expect(page).toHaveURL(/\/setup\/claude$/);
    await page.goBack();
    await expect(page).toHaveURL(/\/setup\/ssh$/);
    expect(await readKeys(page)).toEqual(keys);
    await page.goBack();
    await expect(page).toHaveURL(/\/setup\/tls$/);
    await page.goto('/setup/tls');
    await expect(page.getByRole('radio').last()).toBeChecked();
    await expect(page.getByLabel('OVH API endpoint')).toHaveValue('ovh-us');
    await expect(page.getByText('Stored. Leave blank to keep it.')).toHaveCount(3);
    await page.goto('/setup/admin');
    await expect(page.getByLabel('Display name', { exact: true })).toHaveValue(ADMIN.displayName);
    await page.goto('/setup/claude');
    await expect(page.getByTestId('setup-claude-stored')).toHaveText('A token is stored.');
    await page.goto('/setup/finish');
    await expect(page.getByTestId('summary-tls')).toHaveText('DNS challenge through OVH (DNS-01)');
    await expect(page.getByTestId('summary-claude')).toHaveText('Stored');
  });
});

// ---------------------------------------------------------------------------------------------------------------------
test.describe('the wizard exists only on a fresh host', () => {
  test.describe('before the installer prepared the host', () => {
    test.use({ hostOptions: { init: false, proxySecret: true, runner: 'recording' } });

    test('every wizard URL is a plain 404 and no link can be made', async ({ page, host }) => {
      for (const path of [...WIZARD_PATHS, '/setup?token=anything']) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(404);
        const text = await page.content();
        expect(text, path).not.toContain('Create the administrator');
        expect(text, path).not.toContain('Setup is complete');
        expect(text, path).not.toContain('Setup link needed');
      }
      for (const path of ['/setup/admin', '/setup/finish', '/setup']) {
        expect((await page.request.post(`${host.baseURL}${path}`, { form: { x: 'y' }, maxRedirects: 0 })).status(), `POST ${path}`).toBe(404);
      }
      const minted = host.console(['fleet:setup:token']);
      expect(minted.status).toBe(1);
      expect(minted.stderr.replace(/\s+/g, ' ')).toContain('The host is not initialised; run fleet:host:init first.');
      const reset = host.console(['fleet:setup:reset', '--yes']);
      expect(reset.status).toBe(1);
      expect(reset.stderr.replace(/\s+/g, ' ')).toContain('The host is not initialised; run fleet:host:init first.');
    });
  });

  test('on a completed host every wizard URL says setup is complete, for everyone, and nothing can be written', async ({ page, host, context }) => {
    await toFinish(page, host);
    expect(await finish(page)).toBe(200);
    const header: Record<string, string> = {};

    const urls = [...WIZARD_PATHS, '/setup?token=anything', `/setup?token=${host.token}`, '/Setup/Admin', '/setup/nothing-here'];
    for (const path of urls) {
      const response = await page.goto(path);
      expect(response?.status(), path.replace(host.token, '<token>')).toBe(404);
      await expect(page.getByRole('heading', { level: 1, name: 'Setup is complete' })).toBeVisible();
      await expect(page.getByTestId('setup-complete')).toContainText('auth.fleet.example.com');
    }
    await expect(page.getByTestId('setup-complete-sign-in')).toHaveText('Continue to sign in');
    await expect(page.getByTestId('setup-complete-sign-in')).toHaveAttribute('href', '/');
    await expectSound(page, 'the setup complete page');

    for (const path of ['/setup/admin', '/setup/tls', '/setup/ssh', '/setup/claude', '/setup/finish', '/setup']) {
      const write = await context.request.post(`${host.baseURL}${path}`, { headers: header, form: { x: 'y' }, maxRedirects: 0 });
      expect(write.status(), `POST ${path}`).toBe(404);
      expect(await write.text(), `POST ${path}`).toContain('Setup is complete');
    }
    // No new link either: the console refuses to mint one, and reopening needs the host shell.
    const minted = host.console(['fleet:setup:token']);
    expect(minted.status).toBe(1);
    expect(minted.stderr.replace(/\s+/g, ' ')).toContain('Setup is already complete; run fleet:setup:reset to open it again.');
    const unconfirmed = host.console(['fleet:setup:reset']);
    expect(unconfirmed.status).toBe(1);
    expect(host.scalar('SELECT setup_completed_at IS NOT NULL FROM host_settings')).toBe(1);
  });

  test('reopening the wizard from the host shell keeps the answers and kills the old links', async ({ page, host, context }) => {
    await toFinish(page, host, CLAUDE_TOKEN);
    const keys = await (async () => {
      await page.goto('/setup/ssh');
      return readKeys(page);
    })();
    await page.goto('/setup/finish');
    expect(await finish(page)).toBe(200);
    expect((await page.goto(host.setupUrl))?.status(), 'the used link').toBe(404);

    const reset = host.console(['fleet:setup:reset', '--yes']);
    expect(reset.status, reset.stderr).toBe(0);
    const link = reset.stdout.split('\n').find((line) => /\/setup\?token=/.test(line));
    expect(link).toBeDefined();
    const reopened = new URL(link!.trim());
    reopened.protocol = new URL(host.baseURL).protocol;
    reopened.host = new URL(host.baseURL).host;

    // The link of the first run is dead for good, the new one opens the wizard again.
    const stranger = await context.browser()!.newContext({ baseURL: host.baseURL });
    try {
      const strangerPage = await stranger.newPage();
      expect((await strangerPage.goto(host.setupUrl))?.status(), 'the old link after a reset').toBe(403);
    } finally {
      await stranger.close();
    }
    // Everything is answered, so the link opens the summary; the administrator step is one click away.
    await page.goto(reopened.href);
    await expect(page).toHaveURL(/\/setup\/finish$/);
    await page.goto('/setup/admin');

    // The administrator exists: its name is fixed, its answers are filled in, the password is asked for again.
    await expect(page.getByLabel('Username', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Username', { exact: true })).toHaveValue(ADMIN.username);
    await expect(page.getByLabel('Display name', { exact: true })).toHaveValue(ADMIN.displayName);
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
    await expectSound(page, 'the reopened administrator step');

    // Everything else is still answered: the entry URL goes to the summary.
    await page.goto('/setup');
    await expect(page).toHaveURL(/\/setup\/finish$/);
    await expect(page.getByTestId('summary-claude')).toHaveText('Stored');
    await page.goto('/setup/ssh');
    expect(await readKeys(page)).toEqual(keys);
    expect(host.scalar('SELECT COUNT(*) FROM fleet_user')).toBe(1);

    await page.goto('/setup/finish');
    expect(await finish(page)).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Setup finished' })).toBeVisible();
  });
});
