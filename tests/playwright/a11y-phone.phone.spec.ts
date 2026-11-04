import { expect, test } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { createDeployableProject, deployOne, runFakeJob } from './support/instances';
import { ADMIN_NAV } from './support/shell';
import { setStandInRole } from './support/standIn';
import { uniqueName } from './support/projects';

// The axe sweep on the phone project (FLE-158): the shell destinations and the instance and job pages that the feature
// specs used to check inline. Phone only, so the viewport is the one the phone project gives (390 px). The long-id
// (59 character) variants of the feature specs are folded into the base states: axe does not depend on text length.

test.describe('axe sweep: phone shell @phone', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  for (const { label, path } of ADMIN_NAV) {
    test(`${path} (${label})`, async ({ page }) => {
      await page.goto(path);
      await page.waitForLoadState('networkidle');
      await expectNoBlockingViolations(page, `phone, ${path}`);
    });
  }
});

test.describe('axe sweep: phone instances and jobs @phone', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  /** A project of its own with one deployed instance. */
  async function ownInstance(page: import('@playwright/test').Page): Promise<{ project: string; id: string }> {
    const project = uniqueName('ph');
    await createDeployableProject(project);
    return { project, id: await deployOne(page, project) };
  }

  test('the list', async ({ page }) => {
    const { id } = await ownInstance(page);
    await page.goto(`/instances?q=${id}`);
    await expect(page.getByTestId('instance-row')).toHaveCount(1);
    await expectNoBlockingViolations(page, 'phone, the list');
  });

  test('the deploy form', async ({ page }) => {
    const { project } = await ownInstance(page);
    await page.goto(`/instances/new?project=${project}`);
    await expect(page.getByTestId('deploy-form')).toBeVisible();
    await expectNoBlockingViolations(page, 'phone, the deploy form');
  });

  test('the detail page', async ({ page }) => {
    const { id } = await ownInstance(page);
    await page.goto(`/instances/${id}`);
    await expect(page.getByRole('heading', { level: 1, name: id })).toBeVisible();
    await expectNoBlockingViolations(page, 'phone, the detail page');
  });

  test('the job page', async ({ page }) => {
    const { id } = await ownInstance(page);
    await runFakeJob(id, { lines: 3, delay: 0, width: 200 });
    await page.goto(`/instances/${id}`);
    await page.getByTestId('timeline-job').filter({ hasText: 'fake' }).first().getByRole('link').click();
    await expect(page.getByTestId('job-log')).toContainText('fake line 3 of 3');
    await expectNoBlockingViolations(page, 'phone, the job page');
  });

  test('the failed job page', async ({ page }) => {
    const { id } = await ownInstance(page);
    await runFakeJob(id, { lines: 2, delay: 0, fail: true, width: 200 });
    await page.goto(`/instances/${id}`);
    await page.getByTestId('timeline-job').filter({ hasText: 'fake' }).first().getByRole('link').click();
    await expect(page.getByTestId('job-error')).toBeVisible();
    await expectNoBlockingViolations(page, 'phone, the failed job page');
  });

  test('the job history', async ({ page }) => {
    const { project } = await ownInstance(page);
    await page.goto(`/admin/jobs?project=${project}`);
    await expect(page.getByTestId('job-row')).toHaveCount(1);
    await expectNoBlockingViolations(page, 'phone, the job history');
  });
});
