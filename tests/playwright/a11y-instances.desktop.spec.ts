import { expect, type Locator, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { mkdirSync, rmSync, symlinkSync, unlinkSync, utimesSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expectNoBlockingViolations } from './support/axe';
import { ADMIN_PROJECT, MEMBER_PROJECT, createDeployableProject, deployOne, rowOf, runFakeJob, submitDeploy, uniqueLabel } from './support/instances';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, openSection, submitAndWait, uniqueName } from './support/projects';
import { removeSecret, revealSecret, saveSecret, test, uniqueSecret, uniqueValue } from './support/secrets';
import { setStandInRole } from './support/standIn';

// The axe sweep of the instance, job, asset and audit pages (FLE-158): every page STATE the feature specs used to check
// inline, one test each, set up the way the feature spec set it up, checked in both themes by expectNoBlockingViolations.
// The feature specs keep their other assertions. Desktop only (1280 px): the phone checks are in a11y-phone.phone.spec.ts.
// Variants that differ only in text length (a 59-character id, a long log line, a long name) are folded into the base state.

// ---- helpers (copies of the ones in the feature specs) ----

/** A project with a default template, so the filters and the bulk bar see only its rows. */
async function ownProject(prefix = 'ax'): Promise<string> {
  const name = uniqueName(prefix);
  await createDeployableProject(name);
  return name;
}

/** Deploys `count` instances through the form and returns their ids once all are running. */
async function deployMany(page: Page, project: string, count: number): Promise<string[]> {
  const label = uniqueLabel('bk');
  await submitDeploy(page, { project, label, count });
  await expect(page.getByTestId('flashes')).toContainText(`${count} jobs queued.`);
  const ids = Array.from({ length: count }, (_, i) => `${project}--${label}-${i + 1}`);
  await page.goto(`/instances?project=${project}`);
  for (const id of ids) {
    await expect(rowOf(page, id).getByTestId('instance-state')).toHaveAttribute('data-state', 'running');
  }
  return ids;
}

async function selectRows(page: Page, ids: string[]): Promise<void> {
  for (const id of ids) {
    await page.getByLabel(`Select ${id}`).check();
  }
  await expect(page.getByTestId('bulk-bar')).toBeVisible();
}

const bulkButton = (page: Page, name: string): Locator => page.getByTestId('bulk-bar').getByRole('button', { name, exact: true });

/** Starts a long fake job and waits until the instance page shows it running; returns the function that kills it. */
async function holdBusy(page: Page, id: string): Promise<() => void> {
  const controller = new AbortController();
  runFakeJob(id, { lines: 300, delay: 100, signal: controller.signal }).catch(() => undefined);
  await expect
    .poll(async () => (await (await page.request.get(`/instances/${id}`)).text()).includes('data-testid="active-job"'), { message: `${id} is busy` })
    .toBe(true);
  return () => controller.abort();
}

/** The number of the newest job of an instance whose timeline entry contains `type`. */
async function newestJob(page: Page, instanceId: string, type: string): Promise<number> {
  await page.goto(`/instances/${instanceId}`);
  const href = await page.getByTestId('timeline-job').filter({ hasText: type }).first().getByRole('link').getAttribute('href');
  return Number(/\/jobs\/(\d+)$/.exec(href ?? '')?.[1]);
}

/** A project of its own with one deployed instance. */
async function ownInstance(page: Page): Promise<{ project: string; id: string }> {
  const project = await ownProject('jb');
  return { project, id: await deployOne(page, project) };
}

// ---- instances ----

test.describe('axe sweep: instance list and deploy form', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the deploy form', async ({ page }) => {
    await page.goto(`/instances/new?project=${ADMIN_PROJECT}`);
    await expect(page.getByTestId('deploy-form')).toBeVisible();
    await expectNoBlockingViolations(page, 'the deploy form');
  });

  test('the deploy form with an error (422)', async ({ page }) => {
    await page.goto(`/instances/new?project=${ADMIN_PROJECT}`);
    const form = page.getByTestId('deploy-form');
    await form.getByLabel('Branch', { exact: true }).fill('dev');
    await form.getByLabel('Label', { exact: true }).fill('!!!');
    await form.getByLabel('How many').fill('1');
    await submitAndWait(page, form.getByRole('button', { name: 'Deploy' }));
    await expect(form).toContainText('The label needs at least one letter or digit.');
    await expectNoBlockingViolations(page, 'the deploy form with an error');
  });

  test('the list with no match', async ({ page }) => {
    // The "no match" card only shows when at least one instance exists (total > 0); with none it is "No instances yet".
    // Own an instance instead of relying on rows left by other tests (parallel workers, FLE-335).
    await ownInstance(page);
    await page.goto(`/instances?q=${uniqueLabel('nomatch')}&state=stopped`);
    await expect(page.getByTestId('instances-empty')).toContainText('No instance matches');
    await expectNoBlockingViolations(page, 'the list with no match');
  });

  test('the filtered list', async ({ page }) => {
    const project = await ownProject();
    const [id] = await deployMany(page, project, 2);
    await page.goto(`/instances?q=${id}&project=${project}&state=running`);
    await expect(rowOf(page, id)).toBeVisible();
    await expect(page.getByTestId('instance-row')).toHaveCount(1);
    await expectNoBlockingViolations(page, 'the filtered list');
  });

  test('the list with a busy instance and its row menu closed', async ({ page }) => {
    const id = await deployOne(page);
    const release = await holdBusy(page, id);
    try {
      await page.goto(`/instances?q=${id}`);
      const row = rowOf(page, id);
      await expect(row.getByTestId('instance-state')).toContainText('Working…');
      await expect(row.getByRole('button', { name: 'Stop', exact: true })).toBeDisabled();
      await row.getByLabel(`More actions for ${id}`).click();
      await expect(page.getByRole('menuitem', { name: 'Redeploy' })).toBeDisabled();
      await page.keyboard.press('Escape');
      await expectNoBlockingViolations(page, 'the list with a busy instance');
    } finally {
      release();
    }
  });

  test('the destroy dialog of a row', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto(`/instances?q=${id}`);
    await rowOf(page, id).getByLabel(`More actions for ${id}`).click();
    const dialog = await openDialog(page, page.getByRole('menuitem', { name: 'Destroy…' }));
    await expect(dialog).toContainText(`Destroy ${id}?`);
    await expectNoBlockingViolations(page, 'the destroy dialog of a row');
  });

  test('the list with the bulk bar', async ({ page }) => {
    const project = await ownProject();
    const ids = await deployMany(page, project, 3);
    await page.goto(`/instances?project=${project}`);
    await selectRows(page, ids);
    await expect(page.getByTestId('bulk-bar')).toContainText('3 selected');
    await expectNoBlockingViolations(page, 'the list with the bulk bar');
  });

  test('the bulk redeploy dialog', async ({ page }) => {
    const project = await ownProject();
    const ids = await deployMany(page, project, 2);
    await page.goto(`/instances?project=${project}`);
    await selectRows(page, ids);
    const dialog = await openDialog(page, bulkButton(page, 'Redeploy…'));
    await expect(dialog).toContainText('Redeploy the selected instances?');
    await expectNoBlockingViolations(page, 'the bulk redeploy dialog');
  });

  test('a member, the list with the bulk bar', async ({ page, context, baseURL }) => {
    const own = await deployOne(page, MEMBER_PROJECT);
    await setStandInRole(context, 'member', baseURL!);
    await page.goto(`/instances?q=${own}`);
    await expect(rowOf(page, own)).toBeVisible();
    await selectRows(page, [own]);
    await expect(bulkButton(page, 'Destroy…')).toHaveCount(0);
    await expectNoBlockingViolations(page, 'a member, the list with the bulk bar');
  });
});

test.describe('axe sweep: instance detail', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the detail page of a deployed instance', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto(`/instances/${id}`);
    await expect(page.getByRole('heading', { level: 1, name: id })).toBeVisible();
    await expect(page.getByTestId('timeline-job')).toHaveCount(1);
    await expectNoBlockingViolations(page, 'the detail page of a deployed instance');
  });

  test('the page of a stopped instance', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto(`/instances/${id}`);
    await follow(page, page.getByTestId('instance-actions').getByRole('button', { name: 'Stop', exact: true }));
    await expect(page.getByTestId('job-status')).toHaveAttribute('data-status', 'succeeded');
    await page.goto(`/instances/${id}`);
    await expect(page.getByTestId('instance-detail')).toContainText('Stopped');
    await expectNoBlockingViolations(page, 'the page of a stopped instance');
  });

  test('the page of a busy instance after a refused destroy', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto(`/instances/${id}`);
    const release = await holdBusy(page, id);
    try {
      await follow(page, page.getByTestId('instance-actions').getByRole('button', { name: 'Stop', exact: true }));
      await expect(page.getByTestId('flashes')).toContainText(`Busy: ${id} has a job queued or running. Nothing was started.`);
      await expect(page.getByTestId('active-job').getByTestId('job-log')).toContainText('fake line 1 of 300');
      const dialog = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
      await follow(page, dialog.getByRole('button', { name: 'Destroy instance' }));
      await expect(page.getByTestId('flashes')).toContainText(`Busy: ${id} has a job queued or running.`);
      await expectNoBlockingViolations(page, 'the page of a busy instance');
    } finally {
      release();
    }
  });

  test('the destroy dialog', async ({ page }) => {
    const id = await deployOne(page);
    await page.goto(`/instances/${id}`);
    const dialog = await openDialog(page, page.getByRole('button', { name: 'Destroy…' }));
    await expect(dialog).toContainText(`Destroy ${id}?`);
    await expectNoBlockingViolations(page, 'the destroy dialog');
  });
});

// ---- jobs ----

test.describe('axe sweep: jobs', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the instance page with a finished job', async ({ page }) => {
    const id = await deployOne(page, ADMIN_PROJECT);
    // 4 lines x 2 s: the job outlives page load and the 1 s visibility poll even on a loaded CI box (it was 300 ms, ~1.2 s).
    const running = runFakeJob(id, { lines: 4, delay: 2000 });
    await expect(async () => {
      await page.goto(`/instances/${id}`);
      await expect(page.getByTestId('active-job')).toBeVisible({ timeout: 1_000 });
    }).toPass({ timeout: 30_000 });
    const log = page.getByTestId('active-job').getByTestId('job-log');
    await expect(log).toContainText('fake line 4 of 4', { timeout: 30_000 });
    await running;
    await expect(page.getByTestId('active-job').getByTestId('job-log-indicator')).not.toContainText('Loading');
    await expectNoBlockingViolations(page, 'instance page with a finished job');
  });

  test('the page of a finished job', async ({ page }) => {
    const { id } = await ownInstance(page);
    const number = await newestJob(page, id, 'deploy');
    await page.goto(`/jobs/${number}`);
    await expect(page.getByTestId('job-log-indicator')).toHaveText('Finished');
    await expectNoBlockingViolations(page, 'the page of a finished job');
  });

  test('the page of a failed job', async ({ page }) => {
    const { id } = await ownInstance(page);
    await runFakeJob(id, { lines: 2, delay: 0, fail: true });
    await page.goto(`/jobs/${await newestJob(page, id, 'fake')}`);
    await expect(page.getByTestId('job-error')).toHaveText('Fake job failed on purpose.');
    await expect(page.getByTestId('job-log')).toContainText('fake line 2 of 2');
    await expectNoBlockingViolations(page, 'the page of a failed job');
  });

  test('the job page with a long line', async ({ page }) => {
    const { id } = await ownInstance(page);
    await runFakeJob(id, { lines: 2, delay: 0, width: 1800, fail: true });
    await page.goto(`/jobs/${await newestJob(page, id, 'fake')}`);
    await expect(page.getByTestId('job-log')).toContainText('x'.repeat(1500));
    await expect(page.getByTestId('job-error')).toBeVisible();
    await expectNoBlockingViolations(page, 'the job page with a long line');
  });

  test('a member, the page of a failed job', async ({ page, context, baseURL }) => {
    const own = await deployOne(page, MEMBER_PROJECT);
    await runFakeJob(own, { lines: 1, delay: 0, fail: true });
    const failed = await newestJob(page, own, 'fake');
    await setStandInRole(context, 'member', baseURL!);
    await page.goto(`/jobs/${failed}`);
    await expect(page.getByTestId('job-error')).toHaveText('Fake job failed on purpose.');
    await expectNoBlockingViolations(page, 'a member, the page of a failed job');
  });

  test('the job history with a finished and a failed job', async ({ page }) => {
    const { project, id } = await ownInstance(page);
    await runFakeJob(id, { lines: 2, delay: 0, fail: true });
    await page.goto(`/admin/jobs?project=${project}`);
    await expect(page.getByTestId('job-row')).toHaveCount(2);
    await expect(page.getByTestId('job-row').locator('[data-status="succeeded"]').first()).toBeVisible();
    await expect(page.getByTestId('job-row').locator('[data-status="failed"]')).toHaveCount(1);
    await expectNoBlockingViolations(page, 'the job history');
  });

  test('the job history with no match', async ({ page }) => {
    const { project, id } = await ownInstance(page);
    await page.goto(`/admin/jobs?project=${project}&instance=${id}&type=start`);
    await expect(page.getByTestId('jobs-empty')).toContainText('No jobs');
    await expectNoBlockingViolations(page, 'the job history with no match');
  });

  test('the first page of the job history (paged)', async ({ page }) => {
    // 51 jobs through the UI (as in jobs-coverage.spec.ts): one batch deploy of 20, a bulk stop of 20 and a bulk start of 11.
    test.slow();
    const project = await ownProject('pg');
    await submitDeploy(page, { project, label: uniqueLabel('pg'), count: 20 });
    await expect(page.getByTestId('flashes')).toContainText('20 jobs queued.');
    for (const [action, count] of [['Stop', 20], ['Start', 11]] as const) {
      await page.goto(`/instances?project=${project}`);
      await expect(page.getByTestId('instance-row')).toHaveCount(20);
      await page.locator('input[name="ids[]"]').evaluateAll((boxes, n) =>
        boxes.slice(0, n).forEach((box) => {
          (box as HTMLInputElement).checked = true;
          box.dispatchEvent(new Event('change', { bubbles: true }));
        }),
      count);
      await expect(page.getByTestId('bulk-bar')).toContainText(`${count} selected`);
      await submitAndWait(page, bulkButton(page, action));
      await expect(page.getByTestId('flashes')).toContainText(`${count} jobs queued.`);
    }
    await page.goto(`/admin/jobs?project=${project}`);
    await expect(page.getByTestId('job-row')).toHaveCount(50);
    await expect(page.getByRole('navigation', { name: 'Pages' })).toContainText('Page 1');
    await expectNoBlockingViolations(page, 'the first page of the job history');
  });
});

// ---- assets and snapshot ----

/** Where the app under test keeps its data, as in assets-coverage.spec.ts. */
function fleetHome(): string {
  if (!process.env.FLEET_E2E_BASE_URL) {
    return fileURLToPath(new URL('./.run/fleet-home', import.meta.url));
  }
  if (!process.env.FLEET_HOME) {
    test.skip(true, 'These specs need write access to the FLEET_HOME of the app under test.');
  }
  return process.env.FLEET_HOME as string;
}

const assetsDir = (project: string): string => join(fleetHome(), 'assets', project);

/** Runs a console command of the app under test (dev environment, recording runner, the app's own FLEET_HOME). */
function console_(args: string[]): void {
  const appDir = fileURLToPath(new URL('../../app', import.meta.url));
  const php = spawnSync('php8.4', ['-v'], { stdio: 'ignore' }).status === 0 ? 'php8.4' : 'php';
  const result = spawnSync(php, ['bin/console', ...args, '--no-interaction'], {
    cwd: appDir,
    env: { ...process.env, APP_ENV: 'dev', FLEET_RUNNER: 'recording', FLEET_HOME: fleetHome() },
    encoding: 'utf8',
  });
  expect(result.status, `bin/console ${args.join(' ')}: ${result.stdout}${result.stderr}`).toBe(0);
}

const FILE_TIME = 1_700_000_000;
const flashes = (page: Page): Locator => page.getByTestId('flashes');
const NAMES = 'Names may use letters, digits, spaces and . _ - ( ) + @ , = ~ only, and must not end with a space.';

/** A project with its Assets tab open; the project and its assets directory are removed afterwards. */
async function withProject(page: Page, body: (name: string, dir: string) => Promise<void>): Promise<void> {
  const name = uniqueName();
  try {
    await createProjectViaUi(page, name);
    await openSection(page, 'Assets');
    await expect(page).toHaveURL(/\/assets$/);
    await expect(page.getByTestId('asset-folder-form')).toBeVisible();
    await body(name, assetsDir(name));
  } finally {
    await deleteProjectViaUi(page, name);
    rmSync(assetsDir(name), { recursive: true, force: true });
  }
}

/** A project with running fake instances `labels` and its Assets tab open; everything is removed afterwards. */
async function withInstances(page: Page, labels: string[], body: (name: string) => Promise<void>): Promise<void> {
  const name = uniqueName();
  try {
    await createProjectViaUi(page, name);
    for (const label of labels) {
      console_(['fleet:dev:fake-instance', name, label]);
    }
    await openSection(page, 'Assets');
    await expect(page.getByTestId('snapshot-form')).toBeVisible();
    await body(name);
  } finally {
    for (const label of labels) {
      console_(['fleet:dev:fake-instance', name, label, '--remove']);
    }
    await deleteProjectViaUi(page, name);
    rmSync(assetsDir(name), { recursive: true, force: true });
  }
}

async function rescan(page: Page): Promise<void> {
  await follow(page, page.getByTestId('asset-rescan'));
}

async function createFolder(page: Page, folder: string): Promise<void> {
  await page.getByLabel('New folder').fill(folder);
  await submitAndWait(page, page.getByRole('button', { name: 'Create folder' }));
}

function put(dir: string, name: string, content: string | Buffer = 'x'): string {
  mkdirSync(dir, { recursive: true });
  const path = join(dir, name);
  writeFileSync(path, content);
  utimesSync(path, FILE_TIME, FILE_TIME);
  return path;
}

/** Chooses files in the upload field, repeated until the page shows an upload item for it (the controller loads late). */
async function choose(page: Page, files: Array<{ name: string; buffer: Buffer }>): Promise<void> {
  const items = page.getByTestId('asset-upload-item');
  const before = await items.count();
  await expect(async () => {
    if ((await items.count()) === before) {
      await page.getByTestId('asset-input').setInputFiles(files.map((file) => ({ name: file.name, mimeType: 'application/octet-stream', buffer: file.buffer })));
    }
    await expect(items.nth(before)).toBeVisible({ timeout: 2_000 });
  }).toPass({ timeout: 30_000 });
}

async function endedItem(page: Page, index: number, state: 'error' | 'done'): Promise<Locator> {
  const item = page.getByTestId('asset-upload-item').nth(index);
  await expect(item).toHaveAttribute('data-state', state, { timeout: 30_000 });
  return item;
}

test.describe('axe sweep: assets', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the listing', async ({ page }) => {
    await withProject(page, async (_name, dir) => {
      mkdirSync(join(dir, 'zeta'), { recursive: true });
      put(dir, 'b.sql', 'x'.repeat(1536));
      put(dir, 'A.sql', 'y');
      await rescan(page);
      await expect(page.getByTestId('asset-name')).toHaveText(['zeta', 'A.sql', 'b.sql']);
      await expectNoBlockingViolations(page, 'the listing');
    });
  });

  test('the Assets tab with a finished upload', async ({ page }) => {
    await withProject(page, async () => {
      await choose(page, [{ name: 'a11y.sql', buffer: Buffer.from('upload for axe') }]);
      await endedItem(page, 0, 'done');
      await expect(page.getByTestId('asset-row')).toHaveCount(1);
      await expectNoBlockingViolations(page, 'the Assets tab with a finished upload');
    });
  });

  test('a folder refusal', async ({ page }) => {
    await withProject(page, async () => {
      await createFolder(page, 'bad;name');
      await expect(flashes(page)).toContainText(`The folder was not created. ${NAMES}`);
      await expectNoBlockingViolations(page, 'a folder refusal');
    });
  });

  test('a deep folder with a refusal', async ({ page }) => {
    await withProject(page, async (_name, dir) => {
      const levels = Array.from({ length: 8 }, (_, i) => `level${i + 1}-${'n'.repeat(24)}`);
      put(join(dir, ...levels), 'child.sql');
      await page.goto(`${page.url().split('?')[0]}?path=${encodeURIComponent(levels.join('/'))}`);
      await expect(page.getByTestId('asset-crumbs')).toContainText(levels[7]!);
      await createFolder(page, 'ninth');
      await expect(flashes(page)).toContainText('The folder was not created. The path is more than 8 folders deep.');
      await expectNoBlockingViolations(page, 'a deep folder with a refusal');
    });
  });

  test('a listing with symbolic links', async ({ page }) => {
    await withProject(page, async (_name, dir) => {
      mkdirSync(join(dir, 'real'), { recursive: true });
      put(join(dir, 'real'), 'inside.sql');
      put(dir, 'plain.sql');
      symlinkSync('real', join(dir, 'link'));
      symlinkSync('/etc', join(dir, 'outside'));
      symlinkSync('nowhere', join(dir, 'dangling'));
      await rescan(page);
      await expect(page.getByTestId('asset-row').filter({ has: page.getByTestId('asset-link-state') })).toHaveCount(3);
      await expectNoBlockingViolations(page, 'a listing with symbolic links');
    });
  });

  test('the symbolic link problem', async ({ page }) => {
    await withProject(page, async (name, dir) => {
      mkdirSync(join(dir, 'real'), { recursive: true });
      symlinkSync('real', join(dir, 'link'));
      await page.goto(`/admin/projects/${name}/assets?path=link`);
      await expect(page.getByTestId('assets-problem')).toContainText('A symbolic link is in the way. The browser never follows symbolic links.');
      await expectNoBlockingViolations(page, 'the symbolic link problem');
    });
  });

  test('a linked assets directory', async ({ page }) => {
    await withProject(page, async (_name, dir) => {
      const target = `${dir}-target`;
      mkdirSync(target, { recursive: true });
      put(target, 'hidden.sql');
      try {
        rmSync(dir, { recursive: true, force: true });
        symlinkSync(target, dir);
        await rescan(page);
        await expect(page.getByTestId('assets-problem')).toContainText("The project's assets directory is a symbolic link.");
        await createFolder(page, 'child');
        await expect(flashes(page)).toContainText('The folder was not created. A symbolic link is in the way.');
        await expectNoBlockingViolations(page, 'a linked assets directory');
      } finally {
        unlinkSync(dir);
        rmSync(target, { recursive: true, force: true });
      }
    });
  });

  test('the refused upload items', async ({ page }) => {
    // The 3 GiB cap refusal is the same markup as the others and is left out: it needs a sparse file of that size.
    await withProject(page, async (_name, dir) => {
      mkdirSync(join(dir, 'occupied'), { recursive: true });
      await rescan(page);
      await choose(page, [{ name: 'empty.sql', buffer: Buffer.alloc(0) }]);
      await endedItem(page, 0, 'error');
      await choose(page, [{ name: `${'long-name-'.repeat(12)};.sql`, buffer: Buffer.from('x') }]);
      await endedItem(page, 1, 'error');
      await choose(page, [{ name: '.fleet-upload-x', buffer: Buffer.from('x') }]);
      await endedItem(page, 2, 'error');
      await choose(page, [{ name: 'occupied', buffer: Buffer.from('x') }]);
      const item = await endedItem(page, 3, 'error');
      await expect(item.getByTestId('asset-upload-status')).toContainText('A folder with that name exists.');
      await expectNoBlockingViolations(page, 'the refused upload items');
    });
  });

  test('the delete dialog', async ({ page }) => {
    await withProject(page, async (_name, dir) => {
      put(dir, 'keep.sql');
      await rescan(page);
      const dialog = await openDialog(page, page.getByTestId('asset-row').filter({ hasText: 'keep.sql' }).getByTestId('asset-delete'));
      await expect(dialog).toContainText('Delete keep.sql?');
      await expectNoBlockingViolations(page, 'the delete dialog');
    });
  });

  test('the snapshot card with a running instance', async ({ page }) => {
    await withInstances(page, ['main'], async () => {
      await expect(page.getByTestId('snapshot-form')).toBeVisible();
      await expectNoBlockingViolations(page, 'the snapshot card');
    });
  });

  test('a snapshot refusal', async ({ page }) => {
    await withInstances(page, ['main'], async (name) => {
      console_(['fleet:dev:fake-instance', name, 'main', '--remove']);
      console_(['fleet:dev:fake-instance', name, 'main', 'stopped']);
      await submitAndWait(page, page.getByTestId('snapshot-submit'));
      await expect(flashes(page)).toContainText(`No snapshot was started. Instance ${name}--main is not running (stopped)`);
      await expectNoBlockingViolations(page, 'a snapshot refusal');
    });
  });
});

// ---- audit log ----

test.describe('axe sweep: audit log', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the audit log', async ({ page }) => {
    const name = uniqueSecret('AX');
    await page.goto('/admin/secrets');
    try {
      await saveSecret(page, name, uniqueValue());
      await page.goto('/admin/audit');
      await expect(page.getByTestId('audit-row').first()).toBeVisible();
      await expectNoBlockingViolations(page, 'audit log');
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });

  test('the audit log filtered', async ({ page }) => {
    const name = uniqueSecret('FIL');
    await page.goto('/admin/secrets');
    try {
      await saveSecret(page, name, uniqueValue());
      await revealSecret(page, name);
      await page.goto('/admin/audit');
      await page.getByLabel('Action').selectOption('secret.reveal');
      await submitAndWait(page, page.getByRole('button', { name: 'Filter' }));
      await expect(page.getByTestId('audit-row').filter({ hasText: `fleet/${name}` })).toContainText('secret.reveal');
      await expectNoBlockingViolations(page, 'audit log filtered');
    } finally {
      await removeSecret(page, '/admin/secrets', name);
    }
  });
});
