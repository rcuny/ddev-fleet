import type { BrowserContext } from '@playwright/test';

export type StandInRole = 'admin' | 'member';

/** The dev/test stand-in reads this cookie (set by /_dev/stand-in/{role}); no cookie means the .env.dev default, admin. */
export const STAND_IN_COOKIE = 'fleet_stand_in_role';

export async function setStandInRole(context: BrowserContext, role: StandInRole, baseURL: string): Promise<void> {
  await context.addCookies([{ name: STAND_IN_COOKIE, value: role, url: baseURL }]);
}
