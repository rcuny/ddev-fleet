import { expect, type Locator, type Page } from '@playwright/test';
import { mkdirSync, rmSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test as secretsTest } from './secrets';

// The host settings are one row shared by every test, and the desktop and phone projects run at the same time: a
// test that saves a tab and then reads it back would see the other project's values. A directory is the lock (mkdir
// is atomic). A lock left by a crashed run is taken over after two minutes.
const lock = resolve(dirname(fileURLToPath(import.meta.url)), '../.run/host-settings.lock');

async function acquire(): Promise<void> {
  mkdirSync(dirname(lock), { recursive: true });
  for (;;) {
    try {
      mkdirSync(lock);
      return;
    } catch {
      try {
        if (Date.now() - statSync(lock).mtimeMs > 120_000) {
          rmSync(lock, { recursive: true, force: true });
        }
      } catch {
        // released between the two calls: try again
      }
      await new Promise((done) => setTimeout(done, 100));
    }
  }
}

/**
 * `test` of support/secrets.ts (an admin of its own per test) with a `hostLock` fixture. A test that saves host
 * settings asks for it (`{ hostLock }`) and holds the lock to its end.
 */
export const test = secretsTest.extend<{ hostLock: void }>({
  hostLock: [
    async ({}, use) => {
      await acquire();
      try {
        await use();
      } finally {
        rmSync(lock, { recursive: true, force: true });
      }
    },
    // Waiting for the lock is not test time: the saving tests of both projects take turns.
    { auto: false, timeout: 600_000 },
  ],
});

export const TAB_LABELS: Record<string, string> = {
  general: 'General',
  git: 'Git identity',
  claude: 'Claude token',
  notifications: 'Notifications',
  network: 'Networking',
  uploads: 'Uploads',
  backups: 'Backups',
};

/** Opens a tab by its URL and waits until the tab's card is on screen. */
export async function openHostTab(page: Page, key: string): Promise<Locator> {
  await page.goto(`/admin/host?tab=${key}`);
  const tab = page.getByTestId(`host-tab-${key}`);
  await expect(tab).toBeVisible();
  return tab;
}

/**
 * Turns the browser's own validation off for the form around `field`, as a client that skips it would: the host forms
 * are not `novalidate`, so the browser stops an e-mail without "@" or a number out of range before the server sees it.
 * The server's messages are the contract, and this is how a test reaches them.
 */
export async function skipBrowserValidation(field: Locator): Promise<void> {
  await field.evaluate((node) => {
    (node as HTMLInputElement).form!.noValidate = true;
  });
}

/** Sets a field's value as a property, so the input's `maxlength` does not cut it the way typing (`fill`) does. */
export async function setValue(field: Locator, value: string): Promise<void> {
  await field.evaluate((node, text) => {
    (node as HTMLInputElement).value = text;
  }, value);
}
