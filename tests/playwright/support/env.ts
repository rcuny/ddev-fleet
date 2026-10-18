/**
 * Where the suite runs and how it reaches the app.
 *
 * FLEET_E2E_BASE_URL set  -> external server (the companion DDEV serves https://fleet.ddev.site); no webServer.
 * FLEET_E2E_BASE_URL unset -> Playwright's webServer starts `php -S` itself (support/start-app.mjs): the local and CI gate.
 */
export const externalBaseURL: string | undefined = process.env.FLEET_E2E_BASE_URL || undefined;

export const port: number = Number(process.env.FLEET_E2E_PORT || 8000);

export const baseURL: string = externalBaseURL ?? `http://127.0.0.1:${port}`;

/**
 * Chromium does not read the mkcert root of the companion, so certificate errors are ignored when asked
 * (FLEET_E2E_IGNORE_HTTPS_ERRORS) and for the companion's own `*.ddev.site` hosts, which carry that mkcert certificate.
 */
export const ignoreHTTPSErrors: boolean =
  !!process.env.FLEET_E2E_IGNORE_HTTPS_ERRORS || /^https:\/\/[^/]+\.ddev\.site(:\d+)?(\/|$)/.test(baseURL);
