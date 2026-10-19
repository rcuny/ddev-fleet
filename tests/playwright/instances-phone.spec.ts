import { expect, test, type Locator, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { createDeployableProject, deployOne, runFakeJob } from './support/instances';
import { hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// 390 px: no horizontal scroll on the instance and job pages with the longest id (63 characters) and a 200-character
// log line, and every control is at least 44 px high.
async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

async function expectTouchTargets(where: string, controls: Locator[]): Promise<void> {
  for (const control of controls) {
    await expect(control.first(), `${where}: a control is visible`).toBeVisible();
    for (const element of await control.all()) {
      const box = await element.boundingBox();
      expect(box?.height ?? 0, `${where}: ${await element.innerText()}`).toBeGreaterThanOrEqual(44 - 0.5);
    }
  }
}

test.describe('instances on a phone', () => {
  test.skip(({ isMobile }) => !isMobile, 'phone project only');

  test('list, deploy form, detail and job page fit and have touch targets @smoke', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
    // A 40-character project name and a 21-character label make the longest id, 63 characters.
    const project = `ph${randomBytes(19).toString('hex')}`.slice(0, 40);
    await createDeployableProject(project);
    const id = await deployOne(page, project, 'l'.repeat(21));
    expect(id).toHaveLength(63);
    await runFakeJob(id, { lines: 3, delay: 0, width: 200 });

    await page.goto('/instances');
    await expectNoOverflow(page, 'the list');
    await expectTouchTargets('list', [page.getByTestId('deploy-link'), page.getByTestId('filter-submit'), page.getByLabel('Search instances'), page.getByLabel(`Select ${id}`).locator('xpath=ancestor::label'), page.getByRole('button', { name: 'Stop' })]);

    await page.goto(`/instances/new?project=${project}`);
    await expectNoOverflow(page, 'the deploy form');
    const form = page.getByTestId('deploy-form');
    await expectTouchTargets('deploy form', [form.getByRole('button', { name: 'Deploy' }), form.getByLabel('Label'), form.getByLabel('How many')]);

    await page.goto(`/instances/${id}`);
    await expectNoOverflow(page, 'the detail page');
    await expectTouchTargets('detail', [page.getByTestId('instance-actions').getByRole('button')]);

    await page.goto(`/instances/${id}`);
    await page.getByTestId('timeline-job').filter({ hasText: 'fake' }).first().getByRole('link').click();
    await expect(page.getByTestId('job-log')).toContainText('fake line 3 of 3');
    await expect(page.getByTestId('job-log')).toContainText('x'.repeat(150));
    await expectNoOverflow(page, 'the job page');
  });

  test('a failed job page keeps Retry reachable', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
    const id = await deployOne(page);
    await runFakeJob(id, { lines: 2, delay: 0, fail: true, width: 200 });
    await page.goto(`/instances/${id}`);
    await page.getByTestId('timeline-job').filter({ hasText: 'fake' }).first().getByRole('link').click();
    await expectNoOverflow(page, 'the failed job page');
    await expectTouchTargets('failed job', [page.getByTestId('job-retry')]);
  });
});
