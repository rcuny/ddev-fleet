import { mkdirSync, rmSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { externalBaseURL } from './env';

const here = dirname(fileURLToPath(import.meta.url));

// The dev and test environments read the "reboot required" flag from FLEET_HOME/test-reboot-required (not /run), so
// this spec can switch it: the app's own FLEET_HOME when Playwright started it (support/start-app.mjs; the environment's
// FLEET_HOME is not that one), otherwise the FLEET_HOME of the environment (the companion's served dev app shares it).
export const fleetHome = (externalBaseURL && process.env.FLEET_HOME) || resolve(here, '../.run/fleet-home');
export const flag = join(fleetHome, 'test-reboot-required');
export const packages = `${flag}.pkgs`;

export function clearFlag(): void {
  rmSync(flag, { force: true });
  rmSync(packages, { force: true });
}

// The flag is one file shared by every worker, and the desktop and phone projects run at the same time: a directory
// is the lock (mkdir is atomic). A lock left by a crashed run is taken over after a minute.
export const lock = `${flag}.lock`;

export async function acquire(): Promise<void> {
  mkdirSync(fleetHome, { recursive: true });
  for (;;) {
    try {
      mkdirSync(lock);
      return;
    } catch {
      try {
        if (Date.now() - statSync(lock).mtimeMs > 60_000) {
          rmSync(lock, { recursive: true, force: true });
        }
      } catch {
        // released between the two calls: try again
      }
      await new Promise((done) => setTimeout(done, 100));
    }
  }
}

