import type { Page } from '@playwright/test';

/** The ten shell destinations in menu order (admin sees all of them). */
export const ADMIN_NAV = [
  { label: 'Home', path: '/' },
  { label: 'Instances', path: '/instances' },
  { label: 'Projects', path: '/admin/projects' },
  { label: 'Jobs', path: '/admin/jobs' },
  { label: 'Webhooks', path: '/admin/webhooks' },
  { label: 'Schedules', path: '/admin/schedules' },
  { label: 'Secrets', path: '/admin/secrets' },
  { label: 'Users', path: '/admin/users' },
  { label: 'Settings', path: '/admin/host' },
  { label: 'Design system', path: '/admin/design-system' },
] as const;

export const ADMIN_LABELS: string[] = ADMIN_NAV.map((item) => item.label);

/** console.error and uncaught page errors seen since the call; read the array after the page settled. */
export function collectBrowserErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(`console.error: ${message.text()} ${message.location().url}`.trimEnd());
    }
  });
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  return errors;
}

/**
 * True when the document is wider than the viewport (horizontal scroll). Measured against the configured width, not
 * `window.innerWidth`: a mobile browser zooms out to fit overflowing content, so `innerWidth` grows with it and hides
 * the overflow (FLE-78: a 64-character name in a flash widened the phone layout viewport to 724 px).
 */
export function hasHorizontalOverflow(page: Page): Promise<boolean> {
  const width = page.viewportSize()?.width;
  return page.evaluate((expected) => document.documentElement.scrollWidth > (expected ?? window.innerWidth), width);
}

/** The errors collected by {@link collectBrowserErrors} minus the browser's own line for a 4xx answer a test provokes on purpose. */
export function unexpectedErrors(errors: string[]): string[] {
  return errors.filter((message) => !/Failed to load resource: the server responded with a status of 4\d\d/.test(message));
}
