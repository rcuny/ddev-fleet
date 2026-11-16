import { expect, test } from '@playwright/test';
import { deployOne, runConsole, uniqueLabel } from './support/instances';
import { setStandInRole } from './support/standIn';

// FLE-344: a template with Mailpit on gives the instance page a Mail link to mailpit--<instance>.<domain>.
test.describe('instance Mailpit link', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the instance page links to the instance\'s Mailpit when its template has it on', async ({ page }) => {
    const project = `mp${uniqueLabel('x')}`;
    await runConsole(['fleet:project:create', project, '--git=https://git.example.com/acme/blog.git', '--default-branch=main']);
    await runConsole(['fleet:template:create', project, 'default', '--mailpit=yes']);
    await runConsole(['fleet:project:update', project, '--default-template=default']);

    const id = await deployOne(page, project);
    await page.goto(`/instances/${id}`);
    const link = page.getByTestId('mailpit-link');
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute('href', `https://mailpit--${id}.fleet.example.com`);
  });

  test('the template form offers Mailpit right after the database option', async ({ page }) => {
    await page.goto('/admin/projects/acme/templates/default');
    const labels = await page.locator('form label').allTextContents();
    const database = labels.findIndex((l) => l.includes('Instances have a database'));
    const mailpit = labels.findIndex((l) => l.trim() === 'Mailpit');
    expect(database).toBeGreaterThanOrEqual(0);
    expect(mailpit).toBe(database + 1);
    await expect(page.getByLabel('Mailpit', { exact: true })).not.toBeChecked();
  });
});
