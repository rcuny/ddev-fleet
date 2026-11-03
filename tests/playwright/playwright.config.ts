import { defineConfig, devices } from '@playwright/test';
import { baseURL, externalBaseURL, ignoreHTTPSErrors } from './support/env';

// FLEET_PW_TIER (FLE-126): `full` (default, the documented gate) runs every spec; `quick` is the Fleet CI merge gate for
// feature and Renovate branches: desktop without the coverage and validation matrices, phone limited to @smoke tests.
const tier = process.env.FLEET_PW_TIER ?? 'full';
if (tier !== 'quick' && tier !== 'full') {
  throw new Error(`FLEET_PW_TIER must be "quick" or "full", got "${tier}"`);
}
const quick = tier === 'quick';

export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.ts',
  fullyParallel: true,
  workers: process.env.CI ? 2 : undefined,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  outputDir: 'test-results',
  // Axe and the design-system page are CPU-heavy: next to other workers on a busy host (the companion shares its CPUs) a 5 s test passes 30 s.
  timeout: 60_000,
  expect: { timeout: 20_000 },
  use: {
    baseURL,
    trace: 'retain-on-failure',
    // A click that can never land (an overlay over the target) must fail naming the intercepting element, not hang to the test timeout.
    actionTimeout: 30_000,
    ignoreHTTPSErrors,
    // Hides the Symfony profiler toolbar per request: it would cover the phone bottom bar, and an external
    // server (the companion's php-fpm) has an environment this suite does not control.
    extraHTTPHeaders: { 'X-Fleet-Profiler-Toolbar': '0' },
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
      testIgnore: quick
        ? [/\.phone\.spec\.ts$/, /-coverage\.spec\.ts$/, /(projects|templates)-validation\.spec\.ts$/]
        : /\.phone\.spec\.ts$/,
    },
    {
      name: 'phone',
      use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
      testIgnore: /\.desktop\.spec\.ts$/,
      grep: quick ? /@smoke/ : undefined,
    },
  ],
  // Only when no external server is given: start the app ourselves (K25: `env -u FLEET_E2E_BASE_URL npx playwright test`).
  webServer: externalBaseURL
    ? undefined
    : {
        command: 'node support/start-app.mjs',
        url: `${baseURL}/health`,
        timeout: 180_000,
        reuseExistingServer: !process.env.CI,
        gracefulShutdown: { signal: 'SIGTERM', timeout: 10_000 },
      },
});
