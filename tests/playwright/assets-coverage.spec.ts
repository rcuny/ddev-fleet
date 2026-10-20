import { expect, test, type Locator, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, symlinkSync, truncateSync, unlinkSync, utimesSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expectNoBlockingViolations } from './support/axe';
import { runFakeJob } from './support/instances';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, openSection, submitAndWait, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// The coverage companion of assets.spec.ts and snapshot.spec.ts: what those two do not reach on the Assets tab. Every
// message the path rules, the store and the upload service can return (folders, uploads, the JSON endpoints, delete,
// download), symbolic links, the 8-folder depth limit, the upload cap, long names at 390 px, the listing (order, dates,
// plural, truncation) and the refusals of the snapshot card. Runs on the desktop (1280 px) and the phone (390 px)
// project. Files that must already be on disk ("copied over SSH") are written straight into the app's FLEET_HOME.
// Every test owns a unique project and removes it, and its assets directory, in `finally`.

const isPhone = (): boolean => test.info().project.name === 'phone';

/** Where the app under test keeps its data, as in assets.spec.ts. */
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

const CHUNK = 65_536;
const FILE_TIME = 1_700_000_000; // 2023-11-14 22:13:20 UTC

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

async function expectTall(target: Locator, min = 44): Promise<void> {
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box, 'element has a box').not.toBeNull();
  expect(box!.height).toBeGreaterThanOrEqual(min - 0.5);
}

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

/** Reloads the listing from disk through the Rescan link (keeps the folder). */
async function rescan(page: Page): Promise<void> {
  await follow(page, page.getByTestId('asset-rescan'));
}

/** The listing row whose name is exactly `name` (a plain hasText would also match "empty" inside "empty-ish" rows). */
const rowNamed = (page: Page, name: string): Locator => page.getByTestId('asset-row').filter({ has: page.getByTestId('asset-name').getByText(name, { exact: true }) });

const flashes = (page: Page): Locator => page.getByTestId('flashes');

/** Types a folder name and submits the form. */
async function createFolder(page: Page, folder: string): Promise<void> {
  await page.getByLabel('New folder').fill(folder);
  await submitAndWait(page, page.getByRole('button', { name: 'Create folder' }));
}

/** Writes a file into `dir` (made when missing) with the fixed modification time. */
function put(dir: string, name: string, content: string | Buffer = 'x'): string {
  mkdirSync(dir, { recursive: true });
  const path = join(dir, name);
  writeFileSync(path, content);
  utimesSync(path, FILE_TIME, FILE_TIME);
  return path;
}

/**
 * Chooses files in the upload field. The Stimulus controller loads after the HTML on a busy host and a change that came
 * first is lost, so the choice is repeated until the page shows an upload item for it (never once one shows).
 */
async function choose(page: Page, files: Array<{ name: string; buffer: Buffer } | string>): Promise<void> {
  const items = page.getByTestId('asset-upload-item');
  const before = await items.count();
  await expect(async () => {
    if ((await items.count()) === before) {
      const input = page.getByTestId('asset-input');
      if (typeof files[0] === 'string') {
        await input.setInputFiles(files as string[]);
      } else {
        await input.setInputFiles((files as Array<{ name: string; buffer: Buffer }>).map((file) => ({ name: file.name, mimeType: 'application/octet-stream', buffer: file.buffer })));
      }
    }
    await expect(items.nth(before)).toBeVisible({ timeout: 2_000 });
  }).toPass({ timeout: 30_000 });
}

/** The status line of the n-th upload item once it has ended in `state`. */
async function endedItem(page: Page, index: number, state: 'error' | 'done'): Promise<Locator> {
  const item = page.getByTestId('asset-upload-item').nth(index);
  await expect(item).toHaveAttribute('data-state', state, { timeout: 30_000 });
  return item;
}

async function deleteRow(page: Page, row: Locator): Promise<void> {
  const dialog = await openDialog(page, row.getByTestId('asset-delete'));
  await dialog.getByTestId('asset-delete-confirm').click();
}

/**
 * A call to one of the JSON endpoints from inside the page, the way the Stimulus controller makes it: the stateless
 * CSRF token is double-submitted (header plus matching cookie), see asset_upload_controller.js.
 */
async function api(page: Page, method: 'GET' | 'POST', path: string, init: { json?: unknown; raw?: string; csrf?: boolean } = {}): Promise<{ status: number; body: Record<string, unknown> }> {
  return page.evaluate(
    async ([verb, url, options]) => {
      const csrfName = (document.querySelector('[data-controller="asset-upload"]') as HTMLElement).dataset.assetUploadCsrfValue as string;
      const headers: Record<string, string> = { Accept: 'application/json' };
      let forget = (): void => {};
      if (options.csrf !== false) {
        const token = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(18))));
        const prefix = window.location.protocol === 'https:' ? '__Host-' : '';
        const suffix = window.location.protocol === 'https:' ? '; secure' : '';
        const cookie = `${prefix}${csrfName}_${token}`;
        document.cookie = `${cookie}=${csrfName}; path=/; samesite=strict${suffix}`;
        headers['X-CSRF-Token'] = token;
        forget = () => {
          document.cookie = `${cookie}=0; path=/; samesite=strict; max-age=0${suffix}`;
        };
      }
      let body: string | undefined;
      if (options.json !== undefined) {
        body = JSON.stringify(options.json);
        headers['Content-Type'] = 'application/json';
      } else if (options.raw !== undefined) {
        body = options.raw;
        headers['Content-Type'] = url.includes('/chunks/') ? 'application/octet-stream' : 'application/json';
      }
      try {
        const response = await fetch(url, { method: verb, headers, body, credentials: 'same-origin' });
        return { status: response.status, body: await response.json() };
      } finally {
        forget();
      }
    },
    [method, path, init] as const,
  );
}

test.describe('assets coverage: folders', () => {
  const NAMES = 'Names may use letters, digits, spaces and . _ - ( ) + @ , = ~ only, and must not end with a space.';

  /** Submits each name and expects the refusal with its reason, the listing unchanged (`rows` rows). */
  async function expectRefusals(page: Page, refusals: Array<[string, string]>, rows: number): Promise<void> {
    for (const [value, reason] of refusals) {
      await createFolder(page, value);
      await expect(flashes(page), `folder "${value.slice(0, 20)}"`).toContainText(`The folder was not created. ${reason}`);
      await expect(page.getByTestId('asset-row')).toHaveCount(rows);
      await expect(page.getByLabel('New folder')).toHaveValue('');
    }
  }

  test('the New folder field is required and capped, and the name rules refuse each bad character', async ({ page }) => {
    await withProject(page, async () => {
      // The field is required and capped at 255 characters by the browser itself.
      const field = page.getByLabel('New folder');
      await expect(field).toHaveAttribute('required', '');
      await expect(field).toHaveAttribute('maxlength', '255');
      await page.getByRole('button', { name: 'Create folder' }).click();
      expect(await field.evaluate((input: HTMLInputElement) => input.validity.valueMissing)).toBe(true);
      await expect(page).toHaveURL(/\/assets$/);

      await expectRefusals(
        page,
        [
          ['..', 'The name must not contain "." or ".." parts.'],
          ['.', 'The name must not contain "." or ".." parts.'],
          ['sub/dir', NAMES],
          ['back\\slash', NAMES],
          ['bad;name', NAMES],
          ['trailing ', NAMES],
          [' leading', NAMES],
          ['-dash-first', NAMES],
        ],
        0,
      );
      await expectNoOverflow(page, 'a folder refusal');
      await expectNoBlockingViolations(page, 'a folder refusal');
    });
  });

  test('a reserved name, a name over 255 bytes and a name that exists are refused', async ({ page }) => {
    await withProject(page, async (_name, dir) => {
      put(dir, 'taken.sql');
      await rescan(page);
      await expectRefusals(
        page,
        [
          ['.fleet-upload-abc', 'That name is reserved.'],
          // 130 characters, 260 bytes: under the field's maxlength, over the 255 bytes the rule allows.
          ['é'.repeat(130), 'A name is longer than 255 bytes.'],
          ['taken.sql', 'A file or folder with that name exists already.'],
        ],
        1,
      );
      await createFolder(page, 'dup');
      await createFolder(page, 'dup');
      await expect(flashes(page)).toContainText('The folder was not created. A file or folder with that name exists already.');
      expect(readdirSync(dir).sort()).toEqual(['dup', 'taken.sql']);
    });
  });

  test('allowed punctuation, a leading dot and accents make a folder', async ({ page }) => {
    const errors = collectBrowserErrors(page);

    await withProject(page, async (_name, dir) => {
      for (const good of ['données-é', 'Mix_ed (1)+a@b,c=d~e-f.g', '.config']) {
        await createFolder(page, good);
        await expect(flashes(page)).toContainText('Folder created.');
        await expect(page.getByTestId('asset-name').filter({ hasText: good })).toBeVisible();
      }
      expect(readdirSync(dir).sort()).toEqual(['.config', 'Mix_ed (1)+a@b,c=d~e-f.g', 'données-é']);
      await expectNoOverflow(page, 'the listing of new folders');
    });
    expect(errors).toEqual([]);
  });

  test('nested folders: breadcrumbs, Rescan stays in the folder, and the eighth level is the last', async ({ page }) => {
    const errors = collectBrowserErrors(page);

    await withProject(page, async (_name, dir) => {
      await createFolder(page, 'alpha');
      await follow(page, page.getByTestId('asset-name').filter({ hasText: 'alpha' }));
      await createFolder(page, 'beta');
      await follow(page, page.getByTestId('asset-name').filter({ hasText: 'beta' }));
      await expect(page).toHaveURL(/\/assets\?path=alpha\/beta$/);
      await expect(page.getByTestId('assets-empty')).toHaveText('This folder is empty.');
      await expect(page.getByLabel('Choose files to upload to alpha/beta')).toBeVisible();
      const crumbs = page.getByTestId('asset-crumbs');
      await expect(crumbs.getByRole('link')).toHaveText(['Assets', 'alpha']);
      await expect(crumbs.locator('[aria-current="page"]')).toHaveText('beta');
      if (isPhone()) {
        await expectTall(crumbs.getByRole('link', { name: 'alpha' }));
      }

      // Rescan keeps the folder, and picks up a file copied in.
      put(join(dir, 'alpha', 'beta'), 'copied.sql');
      await rescan(page);
      await expect(page).toHaveURL(/path=alpha\/beta$/);
      await expect(page.getByTestId('asset-row')).toHaveCount(1);

      // A middle crumb goes up one level; deleting there lands in the parent.
      await follow(page, crumbs.getByRole('link', { name: 'alpha' }));
      await expect(page).toHaveURL(/path=alpha$/);
      await expect(page.getByTestId('asset-row')).toHaveCount(1);
      await follow(page, page.getByTestId('asset-crumbs').getByRole('link', { name: 'Assets' }));
      await expect(page).toHaveURL(/\/assets$/);

      // Eight levels deep is allowed (long names wrap in the crumbs); the ninth is not.
      const levels = Array.from({ length: 8 }, (_, i) => `level${i + 1}-${'n'.repeat(24)}`);
      mkdirSync(join(dir, ...levels), { recursive: true });
      put(join(dir, ...levels), 'child.sql');
      const deepest = levels.join('/');
      await page.goto(`${page.url().split('?')[0]}?path=${encodeURIComponent(deepest)}`);
      await expect(page.getByTestId('asset-crumbs')).toContainText(levels[7]!);
      await expect(page.getByTestId('asset-row')).toHaveCount(1);
      // A file at the eighth level is deeper than the rules allow a name to be addressed: listed, but no actions.
      await expect(page.getByTestId('asset-row').first()).toContainText('name not supported here');
      await expect(page.getByTestId('asset-delete')).toHaveCount(0);
      await expectNoOverflow(page, 'eight folders deep');

      await createFolder(page, 'ninth');
      await expect(flashes(page)).toContainText('The folder was not created. The path is more than 8 folders deep.');
      expect(existsSync(join(dir, ...levels, 'ninth'))).toBe(false);
      // The refusal sends the person back into the folder they were in.
      await expect(page).toHaveURL(/path=level1/);
      await expectNoBlockingViolations(page, 'a deep folder with a refusal');

      const tooDeep = await page.goto(`${page.url().split('?')[0]}?path=${encodeURIComponent(`${deepest}/ninth`)}`);
      expect(tooDeep?.status()).toBe(404);
    });
    expect(errors.filter((message) => !message.includes('Failed to load resource'))).toEqual([]);
  });

  test('a folder that does not exist, or a path the rules refuse, is a 404', async ({ page }) => {
    await withProject(page, async (name) => {
      const base = `/admin/projects/${name}/assets`;
      for (const path of ['nope', '..', 'a/../b', '/etc', 'a//b', 'bad;name', `${'a/'.repeat(600)}z`]) {
        const response = await page.goto(`${base}?path=${encodeURIComponent(path)}`);
        expect(response?.status(), `path ${path.slice(0, 20)}`).toBe(404);
      }
    });
  });
});

test.describe('assets coverage: symbolic links', () => {
  test('a symbolic link is listed but never followed, downloaded, entered or written through', async ({ page }) => {
    const errors = collectBrowserErrors(page);

    await withProject(page, async (name, dir) => {
      const real = join(dir, 'real');
      mkdirSync(real, { recursive: true });
      put(real, 'inside.sql');
      // A file with both buttons next to the badges: on a phone the row must still fit (the badge wraps).
      put(dir, 'plain.sql');
      symlinkSync('real', join(dir, 'link'));
      symlinkSync('/etc', join(dir, 'outside'));
      symlinkSync('nowhere', join(dir, 'dangling'));
      await rescan(page);

      // The three links are rows with a badge and no Delete/Download; the real folder is a normal row.
      const links = page.getByTestId('asset-row').filter({ has: page.getByTestId('asset-link-state') });
      await expect(links).toHaveCount(3);
      // Each link says where it points and whether a deploy honours it.
      await expect(links.getByTestId('asset-link-state')).toHaveText([/^symbolic link, (seeded on deploy|not seeded: .+)$/, /^symbolic link, (seeded on deploy|not seeded: .+)$/, /^symbolic link, (seeded on deploy|not seeded: .+)$/]);
      await expect(links.filter({ hasText: 'outside' }).getByTestId('asset-link-target')).toHaveText('→ /etc');
      await expect(links.getByTestId('asset-delete')).toHaveCount(0);
      await expect(links.getByTestId('asset-download')).toHaveCount(0);
      await expect(links.locator('a')).toHaveCount(0);
      await expect(page.getByTestId('asset-row').filter({ hasNotText: 'symbolic link' })).toHaveCount(2);
      await expectNoOverflow(page, 'a listing with symbolic links');
      await expectNoBlockingViolations(page, 'a listing with symbolic links');

      // Typing the address of a link: a message, and the top of the project instead.
      await page.goto(`/admin/projects/${name}/assets?path=link`);
      await expect(page.getByTestId('assets-problem')).toContainText('A symbolic link is in the way. The browser never follows symbolic links.');
      await expect(page.getByTestId('asset-row')).toHaveCount(5);
      await expectNoBlockingViolations(page, 'the symbolic link problem');

      // Downloads through a link are refused (409), also when the link leads out of the directory.
      for (const path of ['link/inside.sql', 'outside/passwd', 'dangling']) {
        const response = await page.request.get(`/admin/projects/${name}/assets/download?path=${encodeURIComponent(path)}`);
        expect(response.status(), path).toBe(409);
      }

      // The folder turns into a link behind the page's back: creating a folder or uploading in it is refused.
      await page.goto(`/admin/projects/${name}/assets?path=real`);
      await expect(page.getByTestId('asset-row')).toHaveCount(1);
      const aside = join(dir, 'moved');
      mkdirSync(aside);
      rmSync(real, { recursive: true });
      symlinkSync('moved', real);

      await choose(page, [{ name: 'through.sql', buffer: Buffer.from('through a link') }]);
      const item = await endedItem(page, 0, 'error');
      await expect(item.getByTestId('asset-upload-status')).toContainText('A symbolic link is in the way. The browser never follows symbolic links.');
      expect(readdirSync(aside)).toEqual([]);

      await createFolder(page, 'child');
      await expect(flashes(page)).toContainText('The folder was not created. A symbolic link is in the way.');
      await expect(page.getByTestId('assets-problem')).toBeVisible();
      expect(readdirSync(aside)).toEqual([]);
    });
    expect(errors.filter((message) => !message.includes('Failed to load resource'))).toEqual([]);
  });

  test('a project assets directory that is a symbolic link shows a message and an empty listing', async ({ page }) => {
    await withProject(page, async (name, dir) => {
      const target = `${dir}-target`;
      mkdirSync(target, { recursive: true });
      put(target, 'hidden.sql');
      try {
        rmSync(dir, { recursive: true, force: true });
        symlinkSync(target, dir);
        await rescan(page);
        await expect(page.getByTestId('assets-problem')).toContainText("The project's assets directory is a symbolic link. The browser never follows symbolic links.");
        await expect(page.getByTestId('assets-empty')).toBeVisible();
        await expect(page.getByTestId('asset-row')).toHaveCount(0);

        await createFolder(page, 'child');
        await expect(flashes(page)).toContainText('The folder was not created. A symbolic link is in the way.');
        expect(readdirSync(target)).toEqual(['hidden.sql']);
        await expectNoBlockingViolations(page, 'a linked assets directory');
      } finally {
        unlinkSync(dir);
        rmSync(target, { recursive: true, force: true });
      }
    });
  });
});

test.describe('assets coverage: uploads', () => {
  test('refusals: empty file, over the cap, a bad name, a folder in the way, and the queue goes on', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = collectBrowserErrors(page);

    await withProject(page, async (name, dir) => {
      await expect(page.getByTestId('asset-upload')).toContainText('Up to 2.0 GiB per file');
      mkdirSync(join(dir, 'occupied'), { recursive: true });
      await rescan(page);

      // An empty file.
      await choose(page, [{ name: 'empty.sql', buffer: Buffer.alloc(0) }]);
      let item = await endedItem(page, 0, 'error');
      await expect(item.getByTestId('asset-upload-status')).toContainText('The file is empty. Choose the same file again to resume.');
      await expect(item.getByTestId('asset-upload-status')).toHaveAttribute('role', 'alert');

      // Over the cap: a sparse 3 GiB file is refused by the begin request, before one byte is read.
      const big = test.info().outputPath('huge.bin');
      writeFileSync(big, '');
      truncateSync(big, 3 * 1024 ** 3);
      await choose(page, [big]);
      item = await endedItem(page, 1, 'error');
      await expect(item.getByTestId('asset-upload-status')).toContainText(`The file is 3.0 GiB, more than the upload limit of 2.0 GiB. Copy bigger files into ${dir} over SSH instead.`);
      await expectNoOverflow(page, 'the cap message');
      rmSync(big);

      // Names the rules refuse, a name longer than 255 bytes, and the name of a folder.
      const names = 'Names may use letters, digits, spaces and . _ - ( ) + @ , = ~ only, and must not end with a space.';
      await choose(page, [{ name: `${'long-name-'.repeat(12)};.sql`, buffer: Buffer.from('x') }]);
      item = await endedItem(page, 2, 'error');
      await expect(item.getByTestId('asset-upload-status')).toContainText(names);
      await expectNoOverflow(page, 'an upload item with a long name');

      await choose(page, [{ name: `${'n'.repeat(256)}.sql`, buffer: Buffer.from('x') }]);
      item = await endedItem(page, 3, 'error');
      await expect(item.getByTestId('asset-upload-status')).toContainText('A name is longer than 255 bytes.');

      await choose(page, [{ name: '.fleet-upload-x', buffer: Buffer.from('x') }]);
      item = await endedItem(page, 4, 'error');
      await expect(item.getByTestId('asset-upload-status')).toContainText('That name is reserved.');

      await choose(page, [{ name: 'occupied', buffer: Buffer.from('x') }]);
      item = await endedItem(page, 5, 'error');
      await expect(item.getByTestId('asset-upload-status')).toContainText('A folder with that name exists.');
      await expectNoBlockingViolations(page, 'the refused upload items');

      // Several files at once go one after the other; a refused one does not stop the next.
      await choose(page, [
        { name: 'bad;one.sql', buffer: Buffer.from('x') },
        { name: 'first.sql', buffer: Buffer.from('first file') },
        { name: 'second.sql', buffer: Buffer.from('second file!') },
      ]);
      await endedItem(page, 6, 'error');
      const first = await endedItem(page, 7, 'done');
      const second = await endedItem(page, 8, 'done');
      await expect(first).toContainText('Uploaded, 10 B.');
      await expect(second).toContainText('Uploaded, 12 B.');
      await expect(page.getByTestId('asset-row')).toHaveCount(3);
      await expect(page.getByTestId('assets-summary')).toContainText('3 items, 22 B in files.');
      expect(readFileSync(join(dir, 'second.sql'), 'utf8')).toBe('second file!');
      // Nothing of the refused files, and no temporary file, is left behind.
      expect(readdirSync(dir).sort()).toEqual(['first.sql', 'occupied', 'second.sql']);
      void name;
    });
    expect(errors.filter((message) => !message.includes('Failed to load resource'))).toEqual([]);
  });

  test('a name that exists asks first: Cancel keeps the file on disk, OK replaces it', async ({ page }) => {
    test.setTimeout(90_000);

    await withProject(page, async (_name, dir) => {
      const path = put(dir, 'exists.sql', 'the original');
      await rescan(page);

      let prompt = '';
      page.once('dialog', (dialog) => {
        prompt = dialog.message();
        void dialog.dismiss();
      });
      await choose(page, [{ name: 'exists.sql', buffer: Buffer.from('the newcomer') }]);
      const skipped = await endedItem(page, 0, 'error');
      expect(prompt).toBe('"exists.sql" already exists here. Replace it?');
      await expect(skipped.getByTestId('asset-upload-status')).toContainText('Skipped: a file with that name exists.');
      expect(readFileSync(path, 'utf8')).toBe('the original');

      page.once('dialog', (dialog) => void dialog.accept());
      await choose(page, [{ name: 'exists.sql', buffer: Buffer.from('the newcomer') }]);
      const replaced = await endedItem(page, 1, 'done');
      await expect(replaced).toContainText('Uploaded, 12 B.');
      expect(readFileSync(path, 'utf8')).toBe('the newcomer');
      await expect(page.getByTestId('asset-row')).toHaveCount(1);
      await expect(page.getByTestId('assets-summary')).toContainText('1 item, 12 B in files.');
    });
  });
});

test.describe('assets coverage: upload endpoints', () => {
  test('the JSON endpoints answer each refusal with a status and a message, and abort ends an upload', async ({ page }) => {
    await withProject(page, async (name) => {
      const base = `/admin/projects/${name}/assets/uploads`;
      const begin = (json: unknown) => api(page, 'POST', base, { json });
      const valid = { path: '', name: 'api.bin', size: 70_000, chunkSize: CHUNK, fingerprint: 'fp-1' };

      // The body.
      expect(await api(page, 'POST', base, { raw: 'null' })).toMatchObject({ status: 400, body: { error: 'The request body must be a JSON object.' } });
      expect(await begin({ name: 'a.bin', size: 'big' })).toMatchObject({ status: 400, body: { error: 'Send path, name, size and fingerprint as text and whole numbers.' } });
      expect(await begin({ ...valid, size: 0 })).toMatchObject({ status: 400, body: { error: 'The file is empty.', fields: { size: 'The file is empty.' } } });
      expect(await begin({ ...valid, fingerprint: 'f'.repeat(513) })).toMatchObject({ status: 400, body: { error: 'The fingerprint is longer than 512 bytes.' } });

      // The path and the name.
      expect(await begin({ ...valid, path: '/etc' })).toMatchObject({ status: 400, body: { error: "The path must be relative to the project's assets directory." } });
      expect(await begin({ ...valid, path: 'a/../b' })).toMatchObject({ status: 400, body: { error: 'The path must not contain "." or ".." parts.' } });
      expect(await begin({ ...valid, path: 'a//b' })).toMatchObject({ status: 400, body: { error: 'The path is empty or has an empty part.' } });
      expect(await begin({ ...valid, path: 'a\u0000b' })).toMatchObject({ status: 400, body: { error: 'The path contains a NUL character.' } });
      expect(await begin({ ...valid, path: 'a/'.repeat(9) + 'z' })).toMatchObject({ status: 400, body: { error: 'The path is more than 8 folders deep.' } });
      expect(await begin({ ...valid, path: `${'a/'.repeat(600)}z` })).toMatchObject({ status: 400, body: { error: 'The path is longer than 1024 bytes.' } });
      expect(await begin({ ...valid, name: 'a/b' })).toMatchObject({ status: 400, body: { error: expect.stringContaining('Names may use letters') } });
      expect(await begin({ ...valid, name: '..' })).toMatchObject({ status: 400, body: { error: 'The name must not contain "." or ".." parts.' } });
      expect(await begin({ ...valid, name: 'x'.repeat(256) })).toMatchObject({ status: 400, body: { error: 'A name is longer than 255 bytes.' } });

      // The cap: 413 with the reason in words.
      const tooBig = await begin({ ...valid, size: 3 * 1024 ** 3 });
      expect(tooBig.status).toBe(413);
      expect(tooBig.body.error).toEqual(expect.stringContaining('more than the upload limit of 2.0 GiB'));

      // A real upload: 70 000 bytes in two chunks of 65 536 (the smallest the server accepts), resumed by its fingerprint.
      const started = await begin(valid);
      expect(started.status).toBe(200);
      expect(started.body).toMatchObject({ name: 'api.bin', path: 'api.bin', size: 70_000, chunkSize: CHUNK, chunks: 2, received: [] });
      const id = started.body.id as string;
      expect(await begin(valid)).toMatchObject({ status: 200, body: { id } });
      expect(await api(page, 'GET', `${base}/${id}`)).toMatchObject({ status: 200, body: { id, received: [] } });

      expect(await api(page, 'POST', `${base}/${id}/chunks/7`, { raw: 'x' })).toMatchObject({ status: 400, body: { error: 'There is no chunk 7: this upload has 2.' } });
      expect(await api(page, 'POST', `${base}/${id}/chunks/0`, { raw: 'x'.repeat(10) })).toMatchObject({ status: 400, body: { error: 'Chunk 0 should be 65536 bytes; 10 bytes arrived.' } });
      expect(await api(page, 'POST', `${base}/${id}/complete`)).toMatchObject({ status: 422, body: { error: 'The upload is not complete: chunk 0, 1 are missing.' } });
      expect(await api(page, 'POST', `${base}/${id}/chunks/0`, { raw: 'x'.repeat(CHUNK) })).toMatchObject({ status: 200, body: { received: [0] } });
      expect(await api(page, 'POST', `${base}/${id}/complete`)).toMatchObject({ status: 422, body: { error: 'The upload is not complete: chunk 1 is missing.' } });

      // The page's CSRF token is required on writes; reading a status needs none.
      expect(await api(page, 'POST', `${base}/${id}/abort`, { csrf: false })).toMatchObject({ status: 403, body: { error: 'Invalid CSRF token. Reload the page.' } });
      expect(await api(page, 'GET', `${base}/${id}`, { csrf: false })).toMatchObject({ status: 200, body: { id, received: [0] } });

      // Abort removes the staged upload; it is gone afterwards, as is an id that never existed.
      expect(await api(page, 'POST', `${base}/${id}/abort`)).toMatchObject({ status: 200, body: { aborted: true } });
      expect((await api(page, 'GET', `${base}/${id}`)).status).toBe(404);
      expect((await api(page, 'POST', `${base}/${id}/complete`)).status).toBe(404);
      expect((await api(page, 'GET', `${base}/${'0'.repeat(32)}`)).status).toBe(404);

      // Nothing was published, and the listing says so.
      await rescan(page);
      await expect(page.getByTestId('assets-empty')).toBeVisible();
    });
  });
});

test.describe('assets coverage: listing, delete and download', () => {
  test('the listing: folders first, then files by name ignoring case, sizes, UTC times, plural and empty states', async ({ page }) => {
    await withProject(page, async (_name, dir) => {
      await expect(page.getByTestId('assets-empty')).toHaveText('No files yet. Upload one above, or copy files in over SSH and press Rescan.');
      await expect(page.getByTestId('assets-summary')).toHaveCount(0);

      mkdirSync(join(dir, 'zeta'), { recursive: true });
      put(dir, 'b.sql', 'x'.repeat(1536));
      put(dir, 'A.sql', 'y');
      put(dir, 'c.sql', 'z'.repeat(3 * 1024 * 1024));
      await rescan(page);

      await expect(page.getByTestId('asset-name')).toHaveText(['zeta', 'A.sql', 'b.sql', 'c.sql']);
      await expect(page.getByTestId('asset-row').first()).toHaveAttribute('data-kind', 'dir');
      await expect(page.getByTestId('assets-summary')).toContainText('4 items, 3.0 MiB in files.');
      const row = page.getByTestId('asset-row').filter({ hasText: 'b.sql' });
      await expect(row).toContainText('1.5 KiB');
      await expect(row).toContainText('2023-11-14 22:13');
      if (!isPhone()) {
        await expect(row.getByTestId('asset-size')).toHaveText('1.5 KiB');
        await expect(page.getByTestId('asset-row').first().getByTestId('asset-size')).toHaveText('-');
        await expect(page.getByRole('columnheader')).toHaveText(['Name', 'Size', 'Modified (UTC)', 'Actions']);
      }
      // A folder has no Download, a file has both buttons.
      await expect(page.getByTestId('asset-row').first().getByTestId('asset-download')).toHaveCount(0);
      await expect(row.getByTestId('asset-download')).toBeVisible();
      await expectNoOverflow(page, 'the listing');
      await expectNoBlockingViolations(page, 'the listing');
    });
  });

  test('Cancel and Escape keep the file; a file that vanished, or a folder with content, is reported; an empty folder goes', async ({ page }) => {
    await withProject(page, async (_name, dir) => {
      const keep = put(dir, 'keep.sql');
      put(dir, 'gone.sql');
      mkdirSync(join(dir, 'full'), { recursive: true });
      put(join(dir, 'full'), 'inside.sql');
      mkdirSync(join(dir, 'empty'), { recursive: true });
      await rescan(page);

      // The dialog names the file and says what happens; Cancel and Escape close it without a request.
      const keepRow = page.getByTestId('asset-row').filter({ hasText: 'keep.sql' });
      let dialog = await openDialog(page, keepRow.getByTestId('asset-delete'));
      await expect(dialog).toContainText('Delete keep.sql?');
      await expect(dialog).toContainText('The file is removed from the assets directory. It cannot be undone.');
      if (isPhone()) {
        await expectTall(dialog.getByRole('button', { name: 'Cancel' }));
        await expectTall(dialog.getByTestId('asset-delete-confirm'));
      }
      await expectNoBlockingViolations(page, 'the delete dialog');
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await expect(page.getByRole('dialog')).toBeHidden();
      dialog = await openDialog(page, keepRow.getByTestId('asset-delete'));
      await page.keyboard.press('Escape');
      await expect(page.getByRole('dialog')).toBeHidden();
      expect(existsSync(keep)).toBe(true);
      await expect(flashes(page)).toHaveCount(0);

      // A folder's dialog says "if it is empty".
      const fullRow = rowNamed(page, 'full');
      dialog = await openDialog(page, fullRow.getByTestId('asset-delete'));
      await expect(dialog).toContainText('The folder is deleted if it is empty.');
      await page.keyboard.press('Escape');
      await expect(page.getByRole('dialog')).toBeHidden();

      // Gone since the page loaded: the confirm says so and the listing refreshes.
      const goneRow = page.getByTestId('asset-row').filter({ hasText: 'gone.sql' });
      dialog = await openDialog(page, goneRow.getByTestId('asset-delete'));
      unlinkSync(join(dir, 'gone.sql'));
      await submitAndWait(page, dialog.getByTestId('asset-delete-confirm'));
      await expect(flashes(page)).toContainText('Nothing was deleted. That file or folder does not exist.');
      await expect(page.getByTestId('asset-name').filter({ hasText: 'gone.sql' })).toHaveCount(0);

      // A folder with something in it stays, an empty one is deleted.
      await deleteRow(page, fullRow);
      await expect(flashes(page)).toContainText('Nothing was deleted. The folder is not empty. Delete what is in it first.');
      expect(existsSync(join(dir, 'full', 'inside.sql'))).toBe(true);
      await deleteRow(page, rowNamed(page, 'empty'));
      await expect(flashes(page)).toContainText('Deleted.');
      expect(existsSync(join(dir, 'empty'))).toBe(false);

      // Deleting inside a folder lands in that folder.
      await follow(page, page.getByTestId('asset-name').filter({ hasText: 'full' }));
      await deleteRow(page, page.getByTestId('asset-row').first());
      await expect(flashes(page)).toContainText('Deleted.');
      await expect(page).toHaveURL(/path=full$/);
      await expect(page.getByTestId('assets-empty')).toHaveText('This folder is empty.');
    });
  });

  test('download refusals and a name with accents', async ({ page }) => {
    await withProject(page, async (name, dir) => {
      const accented = 'résumé données (1).sql';
      put(dir, accented, 'accents');
      mkdirSync(join(dir, 'folder'), { recursive: true });
      await rescan(page);

      const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('asset-row').filter({ hasText: accented }).getByTestId('asset-download').click()]);
      expect(download.suggestedFilename()).toBe(accented);
      expect(readFileSync((await download.path()) as string, 'utf8')).toBe('accents');

      const url = (path: string): string => `/admin/projects/${name}/assets/download?path=${encodeURIComponent(path)}`;
      const file = await page.request.get(url(accented));
      expect(file.headers()['content-type']).toBe('application/octet-stream');
      expect(file.headers()['x-content-type-options']).toBe('nosniff');
      expect(file.headers()['cache-control']).toContain('no-store');
      expect(file.headers()['content-disposition']).toContain('attachment');
      expect((await page.request.get(url('folder'))).status()).toBe(409);
      expect((await page.request.get(url('missing.sql'))).status()).toBe(404);
      expect((await page.request.get(url('../etc/passwd'))).status()).toBe(404);
      expect((await page.request.get(url(''))).status()).toBe(404);
      expect((await page.request.get(`/admin/projects/${name}/assets/download`)).status()).toBe(404);
    });
  });

  test('long names wrap at every width: rows, unsupported names, the delete dialog', async ({ page }) => {
    await withProject(page, async (_name, dir) => {
      const longFile = `${'a-long-file-name-'.repeat(14)}.sql`.slice(0, 250);
      const longFolder = 'folder'.repeat(35);
      put(dir, longFile);
      mkdirSync(join(dir, longFolder), { recursive: true });
      // Names the browser can list but not address: a semicolon, a leading space, and a word with no break at all.
      put(dir, 'bad;name.sql');
      put(dir, ' leading-space.sql');
      put(dir, 'u'.repeat(200));
      await rescan(page);

      await expect(page.getByTestId('asset-row')).toHaveCount(5);
      const unsupported = page.getByTestId('asset-row').filter({ hasText: 'name not supported here' });
      await expect(unsupported).toHaveCount(2);
      await expect(unsupported.getByTestId('asset-delete')).toHaveCount(0);
      await expect(unsupported.getByTestId('asset-download')).toHaveCount(0);
      await expectNoOverflow(page, 'long and unsupported names');

      const dialog = await openDialog(page, page.getByTestId('asset-row').filter({ hasText: longFile.slice(0, 20) }).getByTestId('asset-delete'));
      await expect(dialog).toContainText(`Delete ${longFile}?`);
      const box = await dialog.boundingBox();
      const width = page.viewportSize()!.width;
      expect(box!.x).toBeGreaterThanOrEqual(-0.5);
      expect(box!.x + box!.width).toBeLessThanOrEqual(width + 0.5);
      await expectNoOverflow(page, 'the delete dialog of a long name');
      await expectNoBlockingViolations(page, 'the delete dialog of a long name');
      await page.keyboard.press('Escape');

      // The folder crumb of a long name wraps as well.
      await follow(page, page.getByTestId('asset-name').filter({ hasText: longFolder }));
      await expect(page.getByTestId('asset-crumbs')).toContainText(longFolder);
      await expectNoOverflow(page, 'a folder with a long name');
    });
  });
});

test.describe('assets coverage: snapshot card refusals', () => {
  /** Runs a body with a project that has running fake instances `labels`; everything is removed afterwards. */
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

  const submit = (page: Page): Promise<void> => submitAndWait(page, page.getByTestId('snapshot-submit'));

  test('the select lists the running instances in order and the chosen one is dumped', async ({ page }) => {
    await withInstances(page, ['bb', 'aa'], async (name) => {
      await expect(page.getByTestId('snapshot-instance').locator('option')).toHaveText([`${name}--aa`, `${name}--bb`]);
      await expect(page.getByTestId('snapshot-instance')).toHaveAttribute('required', '');
      await page.getByTestId('snapshot-instance').selectOption(`${name}--bb`);
      await submit(page);
      await expect(flashes(page)).toContainText(`Snapshot of ${name}--bb queued as job`);
      await expect(flashes(page)).toContainText('The dump appears under dumps/ when it is done: press Rescan.');
      const dumps = readdirSync(join(assetsDir(name), 'dumps'));
      expect(dumps).toHaveLength(1);
      expect(dumps[0]).toContain(`default-${name}--bb-`);

      // Rescan shows it in the dumps folder, which the page links to.
      await page.goto(`/admin/projects/${name}/assets?path=dumps`);
      await expect(page.getByTestId('asset-row')).toHaveCount(1);
      await expectNoOverflow(page, 'the dumps folder');
    });
  });

  test('an instance stopped after the page loaded is refused with its state', async ({ page }) => {
    await withInstances(page, ['main'], async (name) => {
      console_(['fleet:dev:fake-instance', name, 'main', '--remove']);
      console_(['fleet:dev:fake-instance', name, 'main', 'stopped']);
      await submit(page);
      await expect(flashes(page)).toContainText(`No snapshot was started. Instance ${name}--main is not running (stopped): only a running instance can be snapshotted.`);
      await expect(page.getByTestId('snapshot-empty')).toBeVisible();
      expect(existsSync(join(assetsDir(name), 'dumps'))).toBe(false);
      await expectNoOverflow(page, 'a snapshot refusal');
      await expectNoBlockingViolations(page, 'a snapshot refusal');
    });
  });

  test('an instance with a job running is refused as busy', async ({ page }) => {
    await withInstances(page, ['main'], async (name) => {
      const id = `${name}--main`;
      const running = runFakeJob(id, { lines: 20, delay: 300 });
      try {
        // Wait until the job really runs (it is its own process), then ask for the snapshot.
        await expect(async () => {
          await page.goto(`/admin/jobs?q=${id}`);
          await expect(page.getByTestId('job-status').first()).toHaveAttribute('data-status', 'running', { timeout: 1_000 });
        }).toPass({ timeout: 30_000 });
        await page.goto(`/admin/projects/${name}/assets`);
        await submit(page);
        await expect(flashes(page)).toContainText(`No snapshot was started. Busy: ${id} has a job queued or running. Nothing was started.`);
        expect(existsSync(join(assetsDir(name), 'dumps'))).toBe(false);
      } finally {
        await running;
      }
    });
  });

  test('an instance of another project, or one that does not exist, is refused', async ({ page }) => {
    const other = uniqueName('other');
    try {
      await createProjectViaUi(page, other);
      console_(['fleet:dev:fake-instance', other, 'main']);
      await withInstances(page, ['main'], async (name) => {
        for (const forged of [`${other}--main`, `${name}--ghost`, 'not an instance id']) {
          await page.getByTestId('snapshot-instance').evaluate((select: HTMLSelectElement, value) => {
            select.options[0]!.value = value;
            select.value = value;
          }, forged);
          await submit(page);
          await expect(flashes(page)).toContainText('No snapshot was started. Instance "');
          await expect(flashes(page)).toContainText('does not exist.');
        }
        expect(existsSync(join(assetsDir(name), 'dumps'))).toBe(false);
        expect(existsSync(join(assetsDir(other), 'dumps'))).toBe(false);
      });
    } finally {
      console_(['fleet:dev:fake-instance', other, 'main', '--remove']);
      await deleteProjectViaUi(page, other);
      rmSync(assetsDir(other), { recursive: true, force: true });
    }
  });

  test('a long instance id wraps in the select and in the flash on the phone', async ({ page }) => {
    await withInstances(page, ['l'.repeat(30)], async (name) => {
      await expectNoOverflow(page, 'a card with a long instance id');
      if (isPhone()) {
        await expectTall(page.getByTestId('snapshot-instance'));
      }
      await submit(page);
      await expect(flashes(page)).toContainText('queued as job');
      await expectNoOverflow(page, 'a flash with a long instance id');
      void name;
    });
  });
});

test.describe('assets coverage: access and CSRF', () => {
  test('a member is refused on every write endpoint, and an admin without a valid token as well', async ({ page, context, baseURL }) => {
    await withProject(page, async (name, dir) => {
      put(dir, 'safe.sql');
      const base = `/admin/projects/${name}/assets`;
      const forms: Array<[string, Record<string, string>]> = [
        [`${base}/folders`, { path: '', name: 'sneaky' }],
        [`${base}/delete`, { path: 'safe.sql' }],
        [`${base}/snapshot`, { instance: `${name}--main` }],
      ];
      // An admin whose token is wrong or missing is refused too, and nothing changes.
      for (const [url, form] of forms) {
        expect((await context.request.post(url, { form: { ...form, _token: 'wrong' } })).status(), url).toBe(403);
        expect((await context.request.post(url, { form })).status(), url).toBe(403);
      }
      expect(readdirSync(dir)).toEqual(['safe.sql']);

      await setStandInRole(context, 'member', baseURL!);
      const id = '0'.repeat(32);
      for (const [url, form] of forms) {
        expect((await context.request.post(url, { form: { ...form, _token: 'csrf-token' } })).status(), url).toBe(403);
      }
      for (const path of [`${base}/uploads/${id}`]) {
        expect((await context.request.get(path)).status(), path).toBe(403);
      }
      for (const path of [`${base}/uploads/${id}/chunks/0`, `${base}/uploads/${id}/complete`, `${base}/uploads/${id}/abort`]) {
        expect((await context.request.post(path, { headers: { 'X-CSRF-Token': 'csrf-token' }, data: '' })).status(), path).toBe(403);
      }
      await setStandInRole(context, 'admin', baseURL!);
      expect(readdirSync(dir)).toEqual(['safe.sql']);
    });
  });
});
