import { expect, test } from '@playwright/test';

const DARK_BODY = 'rgb(2, 6, 23)';

test.describe('dark mode, light OS preference', () => {
  test.use({ colorScheme: 'light' });

  test('toggle switches the theme and the choice survives a reload without a flash', async ({ page }) => {
    // Recorded at DOMContentLoaded, before any deferred module could repaint the class.
    await page.addInitScript(() => {
      document.addEventListener('DOMContentLoaded', () => {
        (window as unknown as { __darkAtDcl: boolean }).__darkAtDcl = document.documentElement.classList.contains('dark');
      });
    });

    await page.goto('/');
    await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);

    await page.locator('[data-slot=theme-toggle]:visible').click();
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe(DARK_BODY);

    await page.reload({ waitUntil: 'domcontentloaded' });
    expect(await page.evaluate(() => (window as unknown as { __darkAtDcl: boolean }).__darkAtDcl)).toBe(true);
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
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
