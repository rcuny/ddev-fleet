import { expect, test, type Locator, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { blockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, follow, openSection, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// "Snapshot a running instance" on the Assets tab, at 1280 px (desktop project) and 390 px (phone project). The app under
// test has no deploy pages yet, so a running instance is recorded with the dev-only `fleet:dev:fake-instance` command
// against the app's FLEET_HOME; the recording command runner then answers `ddev export-db` with a tiny gzip. Every test
// owns a unique project and removes the instance, the dump and the project in `finally`.

const isPhone = (): boolean => test.info().project.name === 'phone';

/** Where the app under test keeps its data, as in assets.spec.ts. */
function fleetHome(): string {
  if (!process.env.FLEET_E2E_BASE_URL) {
    return fileURLToPath(new URL('./.run/fleet-home', import.meta.url));
  }
  if (!process.env.FLEET_HOME) {
    test.skip(true, 'A fake instance needs write access to the FLEET_HOME of the app under test.');
  }

  return process.env.FLEET_HOME as string;
}

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

async function expectTall(target: Locator, min = 44): Promise<void> {
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box, 'element has a box').not.toBeNull();
  expect(box!.height).toBeGreaterThanOrEqual(min - 0.5);
}

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal scroll on ${where}`).toBe(false);
}

const dumps = (project: string): string[] => {
  const dir = join(fleetHome(), 'assets', project, 'dumps');
  return existsSync(dir) ? readdirSync(dir).filter((name) => !name.startsWith('.')) : [];
};

test.describe('snapshot', () => {
  test('shows an empty state when no instance of the project is running', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const name = uniqueName();

    try {
      await createProjectViaUi(page, name);
      await openSection(page, 'Assets');
      await expect(page.getByTestId('asset-snapshot')).toContainText('Snapshot a running instance');
      await expect(page.getByTestId('snapshot-empty')).toBeVisible();
      await expect(page.getByTestId('snapshot-form')).toHaveCount(0);
      await expectNoOverflow(page, 'the Assets tab with no running instance');
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('takes a snapshot of a running instance, keeps the first one on the second, and downloads it', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const name = uniqueName();
    const instance = `${name}--main`;

    try {
      await createProjectViaUi(page, name);
      console_(['fleet:dev:fake-instance', name, 'main']);
      await openSection(page, 'Assets');

      const select = page.getByTestId('snapshot-instance');
      await expect(select).toHaveValue(instance);
      if (isPhone()) {
        await expectTall(select);
        await expectTall(page.getByTestId('snapshot-submit'));
      }
      await expectNoOverflow(page, 'the Assets tab with the snapshot card');

      await follow(page, page.getByTestId('snapshot-submit'));
      await expect(page.getByTestId('flashes')).toContainText(`Snapshot of ${instance} queued as job`);
      await expectNoOverflow(page, 'the Assets tab after a snapshot');

      // The dump is under dumps/ and downloads.
      await page.goto(`/admin/projects/${name}/assets?path=dumps`);
      const rows = page.getByTestId('asset-row');
      await expect(rows).toHaveCount(1);
      await expect(rows.first().getByTestId('asset-name')).toHaveText(new RegExp(`^default-${instance}-\\d{8}T\\d{6}Z\\.sql\\.gz$`));
      const download = page.waitForEvent('download');
      await rows.first().getByTestId('asset-download').click();
      expect((await download).suggestedFilename()).toMatch(/\.sql\.gz$/);

      // A second snapshot never replaces the first.
      await page.goto(`/admin/projects/${name}/assets`);
      await follow(page, page.getByTestId('snapshot-submit'));
      await expect(page.getByTestId('flashes')).toContainText('queued as job');
      expect(dumps(name)).toHaveLength(2);
      expect(errors).toEqual([]);
    } finally {
      console_(['fleet:dev:fake-instance', name, 'main', '--remove']);
      await deleteProjectViaUi(page, name);
    }
  });

  test('offers only running instances: a stopped one takes no snapshot', async ({ page }) => {
    const name = uniqueName();

    try {
      await createProjectViaUi(page, name);
      console_(['fleet:dev:fake-instance', name, 'old', 'stopped']);
      await openSection(page, 'Assets');

      await expect(page.getByTestId('snapshot-empty')).toBeVisible();
      expect(dumps(name)).toEqual([]);
    } finally {
      console_(['fleet:dev:fake-instance', name, 'old', 'stopped', '--remove']);
      await deleteProjectViaUi(page, name);
    }
  });

  for (const scheme of ['light', 'dark'] as const) {
    test.describe(`axe, ${scheme} theme`, () => {
      test.use({ colorScheme: scheme });

      test(`the snapshot card has no serious or critical violation (${scheme})`, async ({ page }) => {
        const name = uniqueName();

        try {
          await createProjectViaUi(page, name);
          console_(['fleet:dev:fake-instance', name, 'main']);
          await openSection(page, 'Assets');
          await expect(page.getByTestId('snapshot-form')).toBeVisible();
          if (scheme === 'dark') {
            await expect(page.locator('html')).toHaveClass(/\bdark\b/);
          }
          expect(await blockingViolations(page)).toEqual([]);
        } finally {
          console_(['fleet:dev:fake-instance', name, 'main', '--remove']);
          await deleteProjectViaUi(page, name);
        }
      });
    });
  }
});
