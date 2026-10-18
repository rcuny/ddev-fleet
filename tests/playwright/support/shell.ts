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
  { label: 'Settings', path: '/admin/settings' },
  { label: 'Design system', path: '/admin/design-system' },
] as const;

export const ADMIN_LABELS: string[] = ADMIN_NAV.map((item) => item.label);

/** console.error and uncaught page errors seen since the call; read the array after the page settled. */
export function collectBrowserErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(`console.error: ${message.text()}`);
    }
  });
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  return errors;
}

/** True when the document is wider than the viewport (horizontal scroll). */
export function hasHorizontalOverflow(page: Page): Promise<boolean> {
  return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
}
