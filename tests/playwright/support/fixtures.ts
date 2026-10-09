/**
 * Test fixtures: the daemons, and a `page` that fails its test on any console error, uncaught
 * page error or CSP violation. Chromium runs with the CSP enforced (never `bypassCSP`).
 */
import { rmSync } from 'node:fs';
import { join } from 'node:path';
import { test as base, expect, type Page } from '@playwright/test';
import { readDaemonState, DAEMONS, type DaemonKind } from './env';

declare global {
  interface Window {
    __cspViolations?: string[];
  }
}

export interface Daemon {
  /** e.g. http://127.0.0.1:18791 */
  url: string;
  /** The daemon's FLEET_HOME. */
  home: string;
  /** `gpg --homedir` of the host key. */
  gnupg: string;
  /** Host key fingerprint, upper-case without spaces (empty without a host key). */
  fingerprint: string;
  /** Path of the stored ciphertext of secret `name` of project `demo`. */
  asc(name: string): string;
}

function describeDaemon(kind: DaemonKind): Daemon {
  const state = readDaemonState(kind);
  // Each test starts from an empty secret store: the daemon, and so its home, is shared.
  rmSync(join(state.home, 'secrets'), { recursive: true, force: true });
  return {
    url: `http://127.0.0.1:${DAEMONS[kind].port}`,
    home: state.home,
    gnupg: join(state.home, 'gnupg'),
    fingerprint: state.fingerprint,
    asc: (name) => join(state.home, 'secrets', 'demo', `${name}.asc`),
  };
}

// Collected in every page: CSP violations are DOM events, not (only) console messages.
// An init script is injected over CDP, so the page's own CSP does not apply to it.
function listenForCspViolations(): void {
  window.__cspViolations = [];
  document.addEventListener('securitypolicyviolation', (e) => {
    window.__cspViolations?.push(`${e.violatedDirective} blocked ${e.blockedURI} (${e.sourceFile})`);
  });
}

async function collectProblems(page: Page, expected: string[], problems: string[]) {
  await page.addInitScript(listenForCspViolations);
  page.on('pageerror', (error) => problems.push(`pageerror: ${error}`));
  page.on('console', (msg) => {
    if (msg.type() === 'error' && !expected.some((text) => msg.text().includes(text))) {
      problems.push(`console.${msg.type()}: ${msg.text()}`);
    }
  });
}

export const test = base.extend<{
  daemon: Daemon;
  daemonNoKey: Daemon;
  expectedConsoleErrors: string[];
}>({
  daemon: async ({}, use) => use(describeDaemon('key')),
  daemonNoKey: async ({}, use) => use(describeDaemon('nokey')),

  /**
   * Substrings of console errors a test deliberately provokes. Chromium logs every 4xx/5xx
   * response as "Failed to load resource: ... status of 400"; a test that asserts the server's
   * error panel pushes that text here. Nothing else is ever ignored, and only for that test.
   */
  expectedConsoleErrors: async ({}, use) => use([]),

  page: async ({ page, expectedConsoleErrors }, use) => {
    const problems: string[] = [];
    await collectProblems(page, expectedConsoleErrors, problems);
    await use(page);
    try {
      const violations = await page.evaluate(() => window.__cspViolations ?? []);
      problems.push(...violations.map((v) => `CSP: ${v}`));
    } catch {
      // page already closed or navigated to a non-document
    }
    expect(problems, 'browser reported problems').toEqual([]);
  },
});

export { expect };
