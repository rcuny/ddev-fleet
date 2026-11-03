import { expect, test } from '@playwright/test';

const DARK_BODY = 'rgb(2, 6, 23)';

test.describe('dark mode, light OS preference', () => {
  test.use({ colorScheme: 'light' });

  test('toggle switches the theme and the choice survives a reload', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);

    await page.locator('[data-slot=theme-toggle]:visible').click();
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe(DARK_BODY);

    await page.reload();
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe(DARK_BODY);
  });

  // The no-flash guarantee belongs to the inline script in <head> (head_theme_boot.html.twig), not to the Stimulus
  // theme controller. Module scripts run before DOMContentLoaded, so a DOMContentLoaded probe cannot tell the two
  // apart; instead every external script is blocked, which leaves only the inline boot able to set the class.
  test('the inline boot applies the stored choice with every external script blocked', async ({ page }) => {
    await page.goto('/');
    await page.locator('[data-slot=theme-toggle]:visible').click();
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);

    let blocked = 0;
    await page.route(/\.js(\?.*)?$/, async (route) => {
      blocked += 1;
      await route.abort();
    });
    await page.reload({ waitUntil: 'domcontentloaded' });

    expect(blocked, 'the page must have requested external scripts, or the block proves nothing').toBeGreaterThan(0);
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe(DARK_BODY);
  });
});

test.describe('dark mode, dark OS preference', () => {
  test.use({ colorScheme: 'dark' });

  test('a first visit with empty storage starts dark', async ({ page }) => {
    await page.goto('/');

    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe(DARK_BODY);
  });
});
