import { expect, test, type Locator, type Page } from '@playwright/test';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, utimesSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { blockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, follow, openDialog, openSection, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// The Assets tab of a project in a real browser, at 1280 px (desktop project) and 390 px (phone project): the chunked
// upload (several chunks, the sha256 the server reports), the listing, download, delete, new folder, resuming and the
// replace prompt. The spec has no .desktop/.phone suffix: it runs on both. Every test owns a unique project and removes
// it in `finally`; the files it uploaded are deleted through the page before that.

const isPhone = (): boolean => test.info().project.name === 'phone';

/** The smallest chunk the server accepts: a 200 KiB file then travels in four requests. */
const CHUNK = 65_536;
const CHUNK_ATTRIBUTE = 'data-asset-upload-chunk-size-value';

/** `length` bytes that differ from block to block, so a chunk sent twice or out of place changes the checksum. */
function sample(length: number, seed = 1): Buffer {
  const bytes = Buffer.alloc(length);
  for (let i = 0; i < length; i++) {
    bytes[i] = (i * 31 + Math.floor(i / 251) * seed + seed) & 0xff;
  }
  return bytes;
}

const sha256 = (bytes: Buffer): string => createHash('sha256').update(bytes).digest('hex');

async function expectTall(target: Locator, min = 44): Promise<void> {
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box, 'element has a box').not.toBeNull();
  expect(box!.height, `height of ${await target.evaluate((node) => node.outerHTML.slice(0, 80))}`).toBeGreaterThanOrEqual(min - 0.5);
}

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

/** Opens the Assets tab of a project that was just created. */
async function openAssets(page: Page): Promise<void> {
  await openSection(page, 'Assets');
  await expect(page).toHaveURL(/\/assets$/);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

/** Makes the page send small chunks (the value is read when an upload starts), so a small file is several chunks. */
async function useSmallChunks(page: Page): Promise<void> {
  await page.locator('[data-controller="asset-upload"]').evaluate((element, [attribute, size]) => element.setAttribute(attribute, size), [CHUNK_ATTRIBUTE, String(CHUNK)]);
}

/** Counts the chunk requests of a page, by chunk number. */
function watchChunks(page: Page): number[] {
  const sent: number[] = [];
  page.on('request', (request) => {
    const match = /\/assets\/uploads\/[0-9a-f]{32}\/chunks\/(\d+)$/.exec(new URL(request.url()).pathname);
    if (match && request.method() === 'POST') {
      sent.push(Number(match[1]));
    }
  });
  return sent;
}

async function upload(page: Page, name: string, bytes: Buffer): Promise<void> {
  await page.getByTestId('asset-input').setInputFiles({ name, mimeType: 'application/octet-stream', buffer: bytes });
}

/**
 * Chooses a file from disk. Unlike a `buffer`, whose last-modified time is "now" on every call, a file keeps its
 * modification time, so choosing it again has the same fingerprint (name, size, last modified) and resumes the upload.
 */
async function uploadFromDisk(page: Page, name: string, bytes: Buffer): Promise<void> {
  const path = test.info().outputPath(name);
  writeFileSync(path, bytes);
  utimesSync(path, 1_700_000_000, 1_700_000_000);
  await page.getByTestId('asset-input').setInputFiles(path);
}

/**
 * Where the app under test keeps its data: the throw-away home the launcher made (.run/fleet-home) when Playwright
 * starts the app itself, else the FLEET_HOME of this environment (the DDEV dev app shares it with the tests).
 */
function fleetHome(): string {
  if (!process.env.FLEET_E2E_BASE_URL) {
    return fileURLToPath(new URL('./.run/fleet-home', import.meta.url));
  }
  if (!process.env.FLEET_HOME) {
    test.skip(true, 'Rescan needs write access to the FLEET_HOME of the app under test.');
  }

  return process.env.FLEET_HOME as string;
}

async function deleteRow(page: Page, row: Locator): Promise<void> {
  const dialog = await openDialog(page, row.getByTestId('asset-delete'));
  await dialog.getByTestId('asset-delete-confirm').click();
  await expect(page.getByTestId('flashes')).toContainText('Deleted.');
}

test.describe('assets', () => {
  test('uploads a file in chunks, lists it, downloads it and deletes it', async ({ page }) => {
    test.setTimeout(150_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();
    const bytes = sample(CHUNK * 3 + 8192);

    try {
      await createProjectViaUi(page, name);
      await openAssets(page);

      await expect(page.getByTestId('assets-empty')).toBeVisible();
      await expect(page.getByTestId('asset-upload')).toContainText('2.0 GiB');
      await expectNoOverflow(page, 'the empty Assets tab');
      if (isPhone()) {
        await expectTall(page.getByTestId('asset-input'));
        await expectTall(page.getByRole('button', { name: 'Create folder' }));
        await expectTall(page.getByTestId('asset-rescan'));
      }

      await useSmallChunks(page);
      const chunks = watchChunks(page);
      await upload(page, 'db.sql.gz', bytes);

      // Done: the server's checksum is the one of the bytes we sent.
      const item = page.getByTestId('asset-upload-item');
      await expect(item).toHaveAttribute('data-state', 'done', { timeout: 60_000 });
      await expect(item.getByTestId('asset-sha256')).toHaveText(sha256(bytes));
      await expect(item).toContainText('Uploaded, 200.0 KiB.');
      expect([...chunks].sort()).toEqual([0, 1, 2, 3]);

      // The listing was refreshed in place.
      const rows = page.getByTestId('asset-row');
      await expect(rows).toHaveCount(1);
      await expect(rows.first().getByTestId('asset-name')).toHaveText('db.sql.gz');
      await expect(page.getByTestId('assets-empty')).toHaveCount(0);
      await expect(page.getByTestId('assets-summary')).toContainText('1 item');
      await expectNoOverflow(page, 'the Assets tab with a file');
      if (isPhone()) {
        await expectTall(rows.first().getByTestId('asset-download'));
        await expectTall(rows.first().getByTestId('asset-delete'));
        // The size and the time sit under the name on a phone; the columns are hidden.
        await expect(rows.first().getByTestId('asset-size')).toBeHidden();
        await expect(rows.first()).toContainText('200.0 KiB');
      } else {
        await expect(rows.first().getByTestId('asset-size')).toHaveText('200.0 KiB');
      }

      // A reload (Rescan) shows the same thing: the list is the disk.
      await follow(page, page.getByTestId('asset-rescan'));
      await expect(page.getByTestId('asset-row')).toHaveCount(1);

      // Download: an attachment with the same bytes.
      const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('asset-row').first().getByTestId('asset-download').click()]);
      expect(download.suggestedFilename()).toBe('db.sql.gz');
      const downloaded = readFileSync((await download.path()) as string);
      expect(downloaded.length).toBe(bytes.length);
      expect(sha256(downloaded)).toBe(sha256(bytes));

      // Delete: a dialog first, then the file is gone.
      await deleteRow(page, page.getByTestId('asset-row').first());
      await expect(page.getByTestId('assets-empty')).toBeVisible();
      await expect(page.getByTestId('asset-row')).toHaveCount(0);

      expect(errors, 'browser errors').toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('creates a folder, uploads into it and finds the way back', async ({ page }) => {
    test.setTimeout(150_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();
    const bytes = sample(CHUNK + 5, 3);

    try {
      await createProjectViaUi(page, name);
      await openAssets(page);

      await page.getByLabel('New folder').fill('dumps');
      await page.getByRole('button', { name: 'Create folder' }).click();
      await expect(page.getByTestId('flashes')).toContainText('Folder created.');
      const folder = page.getByTestId('asset-row').first();
      await expect(folder).toHaveAttribute('data-kind', 'dir');

      // A name that breaks the rules is refused with a message, nothing is created.
      await page.getByLabel('New folder').fill('..');
      await page.getByRole('button', { name: 'Create folder' }).click();
      await expect(page.getByTestId('flashes')).toContainText('not created');
      await expect(page.getByTestId('asset-row')).toHaveCount(1);

      await follow(page, page.getByTestId('asset-row').first().getByTestId('asset-name'));
      await expect(page).toHaveURL(/\/assets\?path=dumps$/);
      await expect(page.getByTestId('assets-empty')).toContainText('This folder is empty.');
      await expect(page.getByTestId('asset-crumbs')).toContainText('dumps');

      await useSmallChunks(page);
      await upload(page, 'data.bin', bytes);
      const item = page.getByTestId('asset-upload-item');
      await expect(item).toHaveAttribute('data-state', 'done', { timeout: 60_000 });
      await expect(item.getByTestId('asset-sha256')).toHaveText(sha256(bytes));
      await expect(page.getByTestId('asset-row')).toHaveCount(1);
      await expectNoOverflow(page, 'a folder of the Assets tab');

      // Back to the top through the breadcrumb, where the file is not.
      await follow(page, page.getByTestId('asset-crumbs').getByRole('link', { name: 'Assets' }));
      await expect(page.getByTestId('asset-row')).toHaveCount(1);
      await expect(page.getByTestId('asset-row').first()).toHaveAttribute('data-kind', 'dir');

      // A folder with something in it is not deleted.
      await deleteRowRefused(page, page.getByTestId('asset-row').first());

      // Clean up: the file, then the folder.
      await follow(page, page.getByTestId('asset-row').first().getByTestId('asset-name'));
      await deleteRow(page, page.getByTestId('asset-row').first());
      await follow(page, page.getByTestId('asset-crumbs').getByRole('link', { name: 'Assets' }));
      await deleteRow(page, page.getByTestId('asset-row').first());
      await expect(page.getByTestId('assets-empty')).toBeVisible();

      expect(errors, 'browser errors').toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('an interrupted upload resumes with only the missing chunks, and a name that exists asks first', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();
    const bytes = sample(CHUNK * 3 + 100, 5);

    try {
      await createProjectViaUi(page, name);
      await openAssets(page);
      await useSmallChunks(page);

      // The connection "drops" at chunk 2.
      await page.route(/\/chunks\/2$/, (route) => route.abort('connectionreset'));
      const chunks = watchChunks(page);
      await uploadFromDisk(page, 'big.sql', bytes);
      const item = page.getByTestId('asset-upload-item').first();
      await expect(item).toHaveAttribute('data-state', 'error', { timeout: 60_000 });
      await expect(item.getByTestId('asset-upload-status')).toContainText('Choose the same file again to resume.');
      await expect(page.getByTestId('assets-empty')).toBeVisible();

      // Back online: the same file again sends only chunks 2 and 3.
      await page.unroute(/\/chunks\/2$/);
      chunks.length = 0;
      await uploadFromDisk(page, 'big.sql', bytes);
      const resumed = page.getByTestId('asset-upload-item').nth(1);
      await expect(resumed).toHaveAttribute('data-state', 'done', { timeout: 60_000 });
      await expect(resumed.getByTestId('asset-sha256')).toHaveText(sha256(bytes));
      expect([...chunks].sort()).toEqual([2, 3]);
      await expect(page.getByTestId('asset-row')).toHaveCount(1);

      // The same name again: refusing the prompt leaves the file alone, accepting replaces it.
      page.once('dialog', (dialog) => void dialog.dismiss());
      await upload(page, 'big.sql', bytes);
      const skipped = page.getByTestId('asset-upload-item').nth(2);
      await expect(skipped).toHaveAttribute('data-state', 'error', { timeout: 60_000 });
      await expect(skipped.getByTestId('asset-upload-status')).toContainText('Skipped');

      const replacement = sample(CHUNK + 1, 9);
      page.once('dialog', (dialog) => void dialog.accept());
      await upload(page, 'big.sql', replacement);
      const replaced = page.getByTestId('asset-upload-item').nth(3);
      await expect(replaced).toHaveAttribute('data-state', 'done', { timeout: 60_000 });
      await expect(replaced.getByTestId('asset-sha256')).toHaveText(sha256(replacement));
      await expect(page.getByTestId('asset-row')).toHaveCount(1);
      await expect(page.getByTestId('assets-summary')).toContainText('64.0 KiB');

      await deleteRow(page, page.getByTestId('asset-row').first());
      expect(errors.filter((message) => !message.includes('connectionreset') && !message.includes('ERR_CONNECTION_RESET') && !message.includes('Failed to load resource'))).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('Rescan shows a file copied into the assets directory over SSH', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const name = uniqueName();
    const directory = join(fleetHome(), 'assets', name);

    try {
      await createProjectViaUi(page, name);
      await openAssets(page);
      await expect(page.getByTestId('assets-empty')).toBeVisible();
      if (isPhone()) {
        await expectTall(page.getByTestId('asset-rescan'));
      }

      // "Over SSH": the file lands on disk without the page knowing.
      mkdirSync(directory, { recursive: true });
      writeFileSync(join(directory, 'from-ssh.sql'), 'copied over ssh');
      await expect(page.getByTestId('asset-row')).toHaveCount(0);

      await page.getByTestId('asset-rescan').click();
      await expect(page).toHaveURL(/\/assets$/);
      await expect(page.getByTestId('asset-row')).toHaveCount(1);
      await expect(page.getByTestId('asset-row')).toContainText('from-ssh.sql');
      await expect(page.getByTestId('assets-empty')).toHaveCount(0);
      await expectNoOverflow(page, 'the Assets tab after a rescan');

      await deleteRow(page, page.getByTestId('asset-row').first());
      await expect(page.getByTestId('assets-empty')).toBeVisible();
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('a member cannot open the Assets tab or its endpoints', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'member', baseURL!);

    for (const path of ['/admin/projects/anything/assets', '/admin/projects/anything/assets/download?path=a.sql']) {
      const response = await page.goto(path);
      expect(response?.status(), path).toBe(403);
      await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible();
    }

    const begin = await context.request.post('/admin/projects/anything/assets/uploads', { data: { path: '', name: 'a.sql', size: 1 }, headers: { 'X-CSRF-Token': 'csrf-token' } });
    expect(begin.status()).toBe(403);
  });

  for (const scheme of ['light', 'dark'] as const) {
    test.describe(`axe, ${scheme} theme`, () => {
      test.use({ colorScheme: scheme });

      test('no serious or critical violations on the Assets tab', async ({ page }) => {
        test.setTimeout(150_000);
        const name = uniqueName();
        try {
          await createProjectViaUi(page, name);
          await openAssets(page);
          await useSmallChunks(page);
          await upload(page, 'a11y.sql', sample(CHUNK + 1));
          await expect(page.getByTestId('asset-upload-item')).toHaveAttribute('data-state', 'done', { timeout: 60_000 });
          await expect(page.getByTestId('asset-row')).toHaveCount(1);
          if (scheme === 'dark') {
            await expect(page.locator('html')).toHaveClass(/\bdark\b/);
          }
          expect(await blockingViolations(page)).toEqual([]);
          await deleteRow(page, page.getByTestId('asset-row').first());
        } finally {
          await deleteProjectViaUi(page, name);
        }
      });
    });
  }
});

/** Deleting a folder that still holds a file ends with a warning, and the folder stays. */
async function deleteRowRefused(page: Page, row: Locator): Promise<void> {
  const dialog = await openDialog(page, row.getByTestId('asset-delete'));
  await dialog.getByTestId('asset-delete-confirm').click();
  await expect(page.getByTestId('flashes')).toContainText('Nothing was deleted');
  await expect(page.getByTestId('asset-row')).toHaveCount(1);
}
