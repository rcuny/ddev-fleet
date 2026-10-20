import { expect, type Locator, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { randomInt } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { follow, submitAndWait } from './projects';

export type TemplateFields = { name?: string; env?: string; postDeploy?: string; tty1?: string; tty2?: string };

/** Fills the template form (only the given fields; the Name field exists on the create form only). */
export async function fillTemplate(page: Page, fields: TemplateFields): Promise<void> {
  // The inputs carry maxlength, which the browser enforces while typing and in fill(); the specs test the server's
  // rules, so the attribute is lifted first (templates-validation.spec.ts asserts the attribute separately).
  await page.locator('[data-testid="template-form"] [maxlength]').evaluateAll((nodes) => nodes.forEach((node) => node.removeAttribute('maxlength')));
  if (fields.name !== undefined) {
    await page.getByLabel('Name', { exact: true }).fill(fields.name);
  }
  if (fields.env !== undefined) {
    await page.getByLabel('Drupal environment').fill(fields.env);
  }
  if (fields.postDeploy !== undefined) {
    await page.getByLabel('Post-deploy commands').fill(fields.postDeploy);
  }
  if (fields.tty1 !== undefined) {
    await page.getByLabel('Terminal 1 commands').fill(fields.tty1);
  }
  if (fields.tty2 !== undefined) {
    await page.getByLabel('Terminal 2 commands').fill(fields.tty2);
  }
}

/** Opens the New template form of a project from the list and submits it; the caller asserts the outcome. */
export async function submitNewTemplate(page: Page, project: string, fields: TemplateFields): Promise<void> {
  await page.goto(`/admin/projects/${project}/templates/new`);
  await fillTemplate(page, fields);
  await submitAndWait(page, page.getByRole('button', { name: 'Create template' }));
}

/** Creates a template through the browser and checks the confirmation; leaves the page on the template list. */
export async function createTemplateViaUi(page: Page, project: string, fields: TemplateFields & { name: string }): Promise<void> {
  await submitNewTemplate(page, project, fields);
  await expect(page.getByTestId('flashes')).toContainText('Template created.');
  await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates$`));
}

export const templateRow = (page: Page, name: string): Locator =>
  page.getByTestId('template-row').filter({ has: page.getByTestId('template-name').getByText(name, { exact: true }) });

export async function editTemplate(page: Page, project: string, name: string): Promise<void> {
  await follow(page, templateRow(page, name).getByRole('link', { name: `Edit ${name}` }));
}

/** Where the app under test keeps its data (as in snapshot.spec.ts). */
function fleetHome(): string | undefined {
  if (!process.env.FLEET_E2E_BASE_URL) {
    return fileURLToPath(new URL('../.run/fleet-home', import.meta.url));
  }
  return process.env.FLEET_HOME;
}

export function canRunConsole(): boolean {
  return fleetHome() !== undefined;
}

/** Runs `bin/console` of the app under test (the named-port catalogue has no web pages, only commands). */
export function consoleRun(args: string[]): { status: number | null; output: string } {
  const appDir = fileURLToPath(new URL('../../../app', import.meta.url));
  const php = spawnSync('php8.4', ['-v'], { stdio: 'ignore' }).status === 0 ? 'php8.4' : 'php';
  const result = spawnSync(php, ['bin/console', ...args, '--no-interaction'], {
    cwd: appDir,
    env: { ...process.env, APP_ENV: 'dev', FLEET_RUNNER: 'recording', FLEET_HOME: fleetHome() },
    encoding: 'utf8',
  });
  return { status: result.status, output: `${result.stdout}${result.stderr}` };
}

/** Two free-looking port numbers for a catalogue entry of one test (random: the suite shares one catalogue). */
export function randomPorts(): { pub: number; router: number } {
  return { pub: randomInt(20000, 30000), router: randomInt(30001, 40000) };
}
