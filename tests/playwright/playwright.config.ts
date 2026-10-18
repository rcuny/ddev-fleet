import { defineConfig, devices } from '@playwright/test';
import { baseURL, externalBaseURL, ignoreHTTPSErrors } from './support/env';

export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.ts',
  fullyParallel: true,
  workers: process.env.CI ? 2 : undefined,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  outputDir: 'test-results',
  expect: { timeout: 10_000 },
  use: {
    baseURL,
    trace: 'retain-on-failure',
    ignoreHTTPSErrors,
    // Hides the Symfony profiler toolbar per request: it would cover the phone bottom bar, and an external
    // server (the companion's php-fpm) has an environment this suite does not control.
    extraHTTPHeaders: { 'X-Fleet-Profiler-Toolbar': '0' },
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
      testIgnore: /\.phone\.spec\.ts$/,
    },
    {
      name: 'phone',
      use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
      testIgnore: /\.desktop\.spec\.ts$/,
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
