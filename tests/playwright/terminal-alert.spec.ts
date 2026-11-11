import { expect, test } from '@playwright/test';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// FLE-289: the admin shell shows a toast for every terminal write sent over the API. The e2e server has no Mercure hub,
// so EventSource is replaced by a fake that delivers the update the hub would send. Content rules (private topic, no
// typed text, failed path) are in app/tests/Functional/Api/ApiTerminalWriteTest.php.

test.describe('terminal write alert', () => {
  test('a sent and a failed write each show a dismissible toast with a link to the audit view @phone @smoke', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    await page.addInitScript(() => {
      class FakeEventSource {
        onmessage: ((event: { data: string }) => void) | null = null;
        onerror: (() => void) | null = null;
        readyState = 1;
        constructor(public url: URL | string) {
          (window as unknown as { __alertSource: FakeEventSource }).__alertSource = this;
        }
        close() {
          this.readyState = 2;
        }
      }
      (window as unknown as { EventSource: unknown }).EventSource = FakeEventSource;
    });

    await page.goto('/');
    await expect(page.getByTestId('terminal-alert')).toBeAttached();
    const subscribed = await page.evaluate(() => String((window as unknown as { __alertSource: { url: URL } }).__alertSource.url));
    expect(subscribed).toContain('topic=%2Falerts%2Fterminal-write');

    const send = (outcome: string) =>
      page.evaluate((o) => {
        const source = (window as unknown as { __alertSource: { onmessage: (e: { data: string }) => void } }).__alertSource;
        source.onmessage({ data: JSON.stringify({ call: 1, instance: 'blog--main', pane: 'tty2', actor: 'token:7:orch', length: 11, outcome: o, at: '2026-11-11T10:00:00+00:00' }) });
      }, outcome);

    await send('sent');
    await send('failed');
    const toasts = page.getByTestId('terminal-alert-toast');
    await expect(toasts).toHaveCount(2);
    await expect(toasts.first()).toContainText('may have been typed');
    await expect(toasts.last()).toContainText('Terminal input was sent: blog--main tty2, 11 bytes');
    await expect(toasts.first().getByRole('link', { name: 'Read it in the audit view' })).toHaveAttribute('href', '/admin/audit');
    expect(await hasHorizontalOverflow(page)).toBe(false);

    await toasts.first().getByRole('button', { name: 'Dismiss' }).click();
    await expect(toasts).toHaveCount(1);
    expect(errors).toEqual([]);
  });
});
