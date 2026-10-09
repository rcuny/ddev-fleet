/**
 * Browser end-to-end tests for ddev-fleet.
 *
 * Two real daemons are started by `support/start-daemon.mjs` through `webServer`: one with a
 * host key (the Secrets form) and one without (the "no host key" instructions). Chromium runs
 * headless with the real CSP enforced. The daemons keep state on disk (FLEET_HOME), so tests
 * run serially in one worker and each starts from an empty secret store (support/fixtures.ts).
 *
 * Env: FLEET_PYTHON (interpreter with `fleet` installed; default <repo>/.venv/bin/python, else
 * python3), FLEET_E2E_PORT / FLEET_E2E_PORT_NOKEY (daemon ports).
 */
import { defineConfig, devices } from '@playwright/test';
import { DAEMONS, type DaemonKind } from './support/env';

function daemon(kind: DaemonKind) {
  const { port, stateFile } = DAEMONS[kind];
  const flags = kind === 'nokey' ? ' --no-key' : '';
  return {
    command: `node support/start-daemon.mjs --port ${port} --state ${JSON.stringify(stateFile)}${flags}`,
    url: `http://127.0.0.1:${port}/secrets`,
    reuseExistingServer: false,
    timeout: 60_000,
    // The launcher stops uvicorn, kills the gpg-agent and removes its temp home on SIGTERM.
    gracefulShutdown: { signal: 'SIGTERM' as const, timeout: 20_000 },
    stderr: 'pipe' as const,
  };
}

export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.ts',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  outputDir: 'test-results',
  expect: { timeout: 10_000 },
  use: {
    ...devices['Desktop Chrome'],
    headless: true,
    actionTimeout: 10_000,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
  webServer: [daemon('key'), daemon('nokey')],
});
