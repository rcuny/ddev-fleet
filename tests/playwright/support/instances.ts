import { expect, type APIResponse, type Locator, type Page } from '@playwright/test';
import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { externalBaseURL } from './env';
import { navigate } from './projects';

const here = dirname(fileURLToPath(import.meta.url));
const appDir = resolve(here, '../../../app');

/** The project the admin specs deploy (seeded by start-app.mjs), and the stand-in member's project (its group is "demo"). */
export const ADMIN_PROJECT = 'acme';
export const MEMBER_PROJECT = 'demo';

/** A label that is valid and unique per call: the suite runs fully parallel on one database. */
export function uniqueLabel(prefix = 'pw'): string {
  return `${prefix}${randomBytes(3).toString('hex')}`;
}

/** The environment of the app's own console: the throw-away FLEET_HOME start-app.mjs made, or the served app's one. */
function consoleEnv(): NodeJS.ProcessEnv {
  const fleetHome = (externalBaseURL && process.env.FLEET_HOME) || resolve(here, '../.run/fleet-home');
  return {
    ...process.env,
    APP_ENV: 'dev',
    FLEET_RUNNER: 'recording',
    FLEET_HOME: fleetHome,
    // Only read to boot the kernel; the console never serves a request.
    FLEET_PROXY_SECRET: process.env.FLEET_PROXY_SECRET ?? 'console-only-proxy-secret-0123456789abcdef',
  };
}

export interface FakeJobOptions {
  lines?: number;
  delay?: number;
  width?: number;
  fail?: boolean;
  /** Aborting kills the command: the job then stays `running`, which is how a spec holds an instance busy. */
  signal?: AbortSignal;
}

/** Runs one console command against the app's database; resolves when it exited 0. */
export function runConsole(args: string[], signal?: AbortSignal): Promise<void> {
  return new Promise((done, fail) => {
    const child = spawn(process.env.FLEET_PHP || 'php8.4', ['bin/console', ...args, '--no-interaction', '-q'], { cwd: appDir, env: consoleEnv(), signal, stdio: ['ignore', 'ignore', 'pipe'] });
    let stderr = '';
    child.stderr.on('data', (chunk) => (stderr += chunk.toString()));
    child.on('error', fail);
    child.on('exit', (code) => (code === 0 ? done() : fail(new Error(`bin/console ${args[0]} exited ${code}: ${stderr.slice(-400)}`))));
  });
}

/** Runs `fleet:dev:fake-job` against the app's database; resolves when the job ended. Not awaited = a live job. */
export function runFakeJob(instanceId: string, options: FakeJobOptions = {}): Promise<void> {
  const args = ['fleet:dev:fake-job', instanceId];
  if (options.lines !== undefined) args.push(`--lines=${options.lines}`);
  if (options.delay !== undefined) args.push(`--delay=${options.delay}`);
  if (options.width !== undefined) args.push(`--width=${options.width}`);
  if (options.fail) args.push('--fail');
  return runConsole(args, options.signal);
}

/** A project with a default template, made through the CLI (the deploy form needs one). */
export async function createDeployableProject(name: string): Promise<void> {
  await runConsole(['fleet:project:create', name, '--git=https://git.example.com/acme/blog.git', '--default-branch=main']);
  await runConsole(['fleet:template:create', name, 'default']);
  await runConsole(['fleet:project:update', name, '--default-template=default']);
}

export interface DeployOptions {
  project?: string;
  label?: string;
  count?: number;
}

/**
 * Fills the deploy form and submits it. The project comes from the query string, so the form is not reloaded under the
 * typed values. Returns the labels asked for.
 */
export async function submitDeploy(page: Page, options: DeployOptions = {}): Promise<void> {
  await page.goto(`/instances/new?project=${options.project ?? ADMIN_PROJECT}`);
  const form = page.getByTestId('deploy-form');
  await expect(form).toBeVisible();
  await form.getByLabel('Label').fill(options.label ?? '');
  await form.getByLabel('How many').fill(String(options.count ?? 1));
  await form.getByRole('button', { name: 'Deploy' }).click();
}

/** Deploys one instance through the form and waits for its job page to show the end of the log; returns the id. */
export async function deployOne(page: Page, project: string = ADMIN_PROJECT, label: string = uniqueLabel()): Promise<string> {
  await submitDeploy(page, { project, label });
  await expect(page).toHaveURL(/\/jobs\/\d+$/);
  await expect(page.getByTestId('job-log')).toContainText('deploy complete');
  return `${project}--${label}`;
}

export function rowOf(page: Page, id: string): Locator {
  return page.locator(`[data-testid="instance-row"][data-instance="${id}"]`);
}

/**
 * Clicks a submit button after `tamper` changed it or its form (a hidden field added, a value or a formaction changed,
 * the token removed), the way a hand-edited page would; returns the status of the POST (a redirect is 303). The
 * request is the browser's own, so the CSRF token is the real one.
 */
export async function submitTampered(page: Page, button: Locator, tamper: (button: HTMLButtonElement, arg: string) => void, arg = ''): Promise<number> {
  await button.evaluate(tamper as (element: HTMLElement | SVGElement, arg: string) => void, arg);
  const response = page.waitForResponse((r) => r.request().method() === 'POST');
  await navigate(page, () => button.click());
  return (await response).status();
}

/**
 * Posts a form without a browser's help, for the answers that do not depend on a valid CSRF token (a route that is
 * not there, a missing or wrong token, a wrong method); a request that must pass the token goes through
 * {@link submitTampered}. A field may repeat, which the `form:` option does not allow; a redirect is not followed.
 */
export function postForm(page: Page, url: string, fields: Record<string, string | string[]> = {}): Promise<APIResponse> {
  const body = new URLSearchParams();
  for (const [name, value] of Object.entries(fields)) {
    for (const one of Array.isArray(value) ? value : [value]) {
      body.append(name, one);
    }
  }
  return page.request.post(url, { data: body.toString(), headers: { 'content-type': 'application/x-www-form-urlencoded', origin: new URL(page.url()).origin }, maxRedirects: 0 });
}
