import { expect, test, type Page } from '@playwright/test';
import { createDeployableProject, deployOne, runFakeJob } from './support/instances';
import { uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// The coverage companion of jobs.spec.ts and instances.phone.spec.ts. What is plain HTTP (the job page, Retry and its
// refusals, the log endpoint, visibility for a member, the history filters and paging) is in JobControllerTest and
// JobHistoryTest; what stays here needs a browser: the live log following a running job and the phone layout.
// A job that has to be "running" is a fake job. Runs on the desktop (1280 px) and the phone (390 px) project.

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

/** The number of the newest job of an instance whose timeline entry contains `type`, read from its timeline. */
async function newestJob(page: Page, instanceId: string, type: string): Promise<number> {
  await page.goto(`/instances/${instanceId}`);
  const href = await page.getByTestId('timeline-job').filter({ hasText: type }).first().getByRole('link').getAttribute('href');
  return Number(/\/jobs\/(\d+)$/.exec(href ?? '')?.[1]);
}

/** A project of its own with one deployed instance: its jobs are the only ones a `project=` filter returns. */
async function ownInstance(page: Page): Promise<{ project: string; id: string }> {
  const project = uniqueName('jb');
  await createDeployableProject(project);
  return { project, id: await deployOne(page, project) };
}

test.describe('job page: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('the log follows a running job to its end', async ({ page }) => {
    const { id } = await ownInstance(page);
    const running = runFakeJob(id, { lines: 8, delay: 500 });
    let number = 0;
    await expect
      .poll(
        async () => {
          const body = await (await page.request.get(`/instances/${id}`)).text();
          number = Number(/href="\/jobs\/(\d+)"[^>]*>Job #\d+<\/a> is running/.exec(body)?.[1] ?? 0);
          return number;
        },
        { message: 'the fake job runs' },
      )
      .toBeGreaterThan(0);
    await page.goto(`/jobs/${number}`);
    await expect(page.getByTestId('job-log')).toContainText('fake line 1 of 8');
    await expect(page.getByTestId('job-log-indicator')).not.toHaveText('Finished');
    await expect(page.getByTestId('job-log')).toContainText('fake line 8 of 8');
    await expect(page.getByTestId('job-log-indicator')).toHaveText('Finished');
    await running;
    await page.reload();
    await expect(page.getByTestId('job-meta').getByTestId('job-status')).toHaveAttribute('data-status', 'succeeded');
    await expect(page.getByTestId('job-log')).toContainText('fake line 8 of 8');
  });

  test('a very long log line and the longest id stay inside the page @phone', async ({ page }) => {
    const project = uniqueName('jl').padEnd(40, 'x').slice(0, 40);
    await createDeployableProject(project);
    const id = await deployOne(page, project, 'l'.repeat(17));
    expect(id).toHaveLength(59);
    await runFakeJob(id, { lines: 2, delay: 0, width: 1800, fail: true });
    const number = await newestJob(page, id, 'fake');
    await page.goto(`/jobs/${number}`);
    await expect(page.getByTestId('job-log')).toContainText('x'.repeat(1500));
    await expect(page.getByTestId('job-error')).toBeVisible();
    await expectNoOverflow(page, 'the job page');
  });
});

test.describe('job history: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  test('fits the phone width with the longest id and shows the filters as stacked controls @phone', async ({ page }) => {
    test.skip(test.info().project.name !== 'phone', 'phone width only');
    const project = uniqueName('jw').padEnd(40, 'x').slice(0, 40);
    await createDeployableProject(project);
    const id = await deployOne(page, project, 'l'.repeat(17));
    await page.goto(`/admin/jobs?project=${project}`);
    await expect(page.getByTestId('job-row')).toHaveCount(1);
    await expectNoOverflow(page, 'the job history');
    for (const control of [page.getByLabel('Status'), page.getByLabel('Trigger'), page.getByLabel('Type'), page.getByLabel('Search jobs'), page.getByRole('button', { name: 'Filter' })]) {
      const box = await control.boundingBox();
      expect(box?.height ?? 0, 'a control is at least 44 px high').toBeGreaterThanOrEqual(43.5);
    }
    await expect(page.getByTestId('job-row')).toContainText(id);
  });
});
