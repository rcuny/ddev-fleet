import { expect, test, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { ADMIN_PROJECT, MEMBER_PROJECT, createDeployableProject, deployOne, postForm, runFakeJob, submitDeploy, submitTampered, uniqueLabel } from './support/instances';
import { follow, submitAndWait, uniqueName } from './support/projects';
import { hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// The coverage companion of jobs.spec.ts and instances-phone.spec.ts: the job page (facts, log, Retry and its
// refusals, unknown jobs, the log endpoint, what a member may see) and the job history at /admin/jobs (every filter,
// the paging, the empty states). A job that has to be "running" is a fake job that is killed at the end of the test.
// Runs on the desktop (1280 px) and the phone (390 px) project; every test owns its project and its instances.

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

  test('a finished job shows its facts and its log, and has no Retry @smoke', async ({ page }) => {
    const { id } = await ownInstance(page);
    const number = await newestJob(page, id, 'deploy');
    await page.goto(`/jobs/${number}`);

    await expect(page.getByRole('heading', { level: 1, name: `Job #${number}` })).toBeVisible();
    await expect(page.getByText(`deploy · ${id}`)).toBeVisible();
    const meta = page.getByTestId('job-meta');
    await expect(meta.getByTestId('job-status')).toHaveAttribute('data-status', 'succeeded');
    await expect(meta).toContainText('ui');
    await expect(meta).toContainText('Requested by');
    await expect(meta.getByRole('link', { name: id })).toHaveAttribute('href', `/instances/${id}`);
    for (const label of ['Queued', 'Started', 'Finished']) {
      await expect(meta.locator('div', { hasText: new RegExp(`^${label}\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2} UTC$`) })).toHaveCount(1);
    }
    await expect(meta).not.toContainText('Not yet');
    await expect(page.getByTestId('job-error')).toHaveCount(0);
    await expect(page.getByTestId('retry-of')).toHaveCount(0);
    await expect(page.getByTestId('job-retry')).toHaveCount(0);
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');
    await expect(page.getByTestId('job-log-indicator')).toHaveText('Finished');
    await expect(page.getByRole('log', { name: `Log of job #${number}` })).toBeVisible();
    await expectNoBlockingViolations(page, 'the page of a finished job');

    await follow(page, meta.getByRole('link', { name: id }));
    await expect(page).toHaveURL(new RegExp(`/instances/${id}$`));
  });

  test('a failed job shows its error, Retry makes a new job that links back, a second Retry is possible', async ({ page }) => {
    const { id } = await ownInstance(page);
    await runFakeJob(id, { lines: 2, delay: 0, fail: true });
    const failed = await newestJob(page, id, 'fake');
    await page.goto(`/jobs/${failed}`);

    await expect(page.getByTestId('job-meta').getByTestId('job-status')).toHaveAttribute('data-status', 'failed');
    await expect(page.getByTestId('job-error')).toHaveText('Fake job failed on purpose.');
    await expect(page.getByTestId('job-meta')).toContainText('cli');
    await expect(page.getByTestId('job-meta')).toContainText('cli:fake-job');
    await expect(page.getByTestId('job-log')).toContainText('fake line 2 of 2');
    await expectNoBlockingViolations(page, 'the page of a failed job');

    await follow(page, page.getByTestId('job-retry'));
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    expect(page.url()).not.toMatch(new RegExp(`/jobs/${failed}$`));
    await expect(page.getByTestId('retry-of')).toHaveText(`#${failed}`);
    await expect(page.getByTestId('job-meta')).toContainText('ui');
    // The retry fails again (same parameters), so it can itself be retried; the original stays failed.
    await expect(page.getByTestId('job-meta').getByTestId('job-status')).toHaveAttribute('data-status', 'failed');
    await follow(page, page.getByTestId('retry-of'));
    await expect(page).toHaveURL(new RegExp(`/jobs/${failed}$`));
    await expect(page.getByTestId('job-retry')).toBeVisible();
  });

  test('Retry is refused with the reason while the instance has a job running', async ({ page }) => {
    const { id } = await ownInstance(page);
    await runFakeJob(id, { lines: 1, delay: 0, fail: true });
    const failed = await newestJob(page, id, 'fake');
    await page.goto(`/jobs/${failed}`);

    const controller = new AbortController();
    runFakeJob(id, { lines: 300, delay: 100, signal: controller.signal }).catch(() => undefined);
    try {
      await expect
        .poll(async () => (await (await page.request.get(`/instances/${id}`)).text()).includes('data-testid="active-job"'), { message: `${id} is busy` })
        .toBe(true);
      await submitAndWait(page, page.getByTestId('job-retry'));
      await expect(page).toHaveURL(new RegExp(`/jobs/${failed}$`));
      await expect(page.getByTestId('flashes')).toContainText(`Busy: ${id} has a job queued or running. Nothing was started.`);
    } finally {
      controller.abort();
    }
  });

  test('a job that is not failed or interrupted cannot be retried, even by an edited page', async ({ page }) => {
    const { id } = await ownInstance(page);
    await runFakeJob(id, { lines: 1, delay: 0, fail: true });
    const failed = await newestJob(page, id, 'fake');
    const deploy = await newestJob(page, id, 'deploy');
    await page.goto(`/jobs/${failed}`);

    // The Retry form of the failed job, pointed at the finished deploy.
    const status = await submitTampered(page, page.getByTestId('job-retry'), (button, to) => (button.form!.action = new URL(to, location.href).href), `/jobs/${deploy}/retry`);
    expect(status).toBe(303);
    await expect(page).toHaveURL(new RegExp(`/jobs/${deploy}$`));
    await expect(page.getByTestId('flashes')).toContainText('Only a failed or interrupted job can be retried.');
    await page.goto(`/instances/${id}`);
    await expect(page.getByTestId('timeline-job')).toHaveCount(2);
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

  test('the log endpoint answers in pieces, and refuses a bad offset', async ({ page }) => {
    const { id } = await ownInstance(page);
    await runFakeJob(id, { lines: 5, delay: 0 });
    const number = await newestJob(page, id, 'fake');
    await page.goto(`/jobs/${number}`);

    const whole = await page.request.get(`/jobs/${number}/log`);
    expect(whole.status()).toBe(200);
    expect(whole.headers()['cache-control']).toContain('no-store');
    const all = await whole.json();
    expect(Object.keys(all).sort()).toEqual(['done', 'next', 'size', 'status', 'text']);
    expect(all.status).toBe('succeeded');
    expect(all.done).toBe(true);
    expect(all.text).toContain('fake line 1 of 5');
    expect(all.text).toContain('fake line 5 of 5');
    expect(all.next).toBe(all.size);

    // From the middle: the tail only. From the end or beyond: nothing, still done.
    const tail = await (await page.request.get(`/jobs/${number}/log?from=${all.size - 10}`)).json();
    expect(all.text.endsWith(tail.text)).toBe(true);
    expect(tail.text.length).toBeLessThanOrEqual(10);
    const end = await (await page.request.get(`/jobs/${number}/log?from=${all.size}`)).json();
    expect(end).toMatchObject({ text: '', next: all.size, done: true });
    const beyond = await (await page.request.get(`/jobs/${number}/log?from=${all.size + 500}`)).json();
    expect(beyond).toMatchObject({ text: '', next: all.size, done: true });

    for (const bad of ['abc', '-1', '1.5', '1234567890123456', '']) {
      expect((await page.request.get(`/jobs/${number}/log?from=${bad}`)).status(), `from=${bad}`).toBe(400);
    }
  });

  test('an unknown job, a bad number and a wrong method are refused', async ({ page }) => {
    const { id } = await ownInstance(page);
    const number = await newestJob(page, id, 'deploy');
    expect((await page.goto('/jobs/999999999'))?.status()).toBe(404);
    expect((await page.goto('/jobs/abc'))?.status()).toBe(404);
    expect((await page.goto('/jobs/0x10'))?.status()).toBe(404);
    expect((await page.request.get('/jobs/999999999/log')).status()).toBe(404);
    expect((await postForm(page, '/jobs/999999999/retry', { _token: 'x' })).status()).toBe(404);
    expect((await page.goto(`/jobs/${number}/retry`))?.status()).toBe(405);
    expect((await postForm(page, `/jobs/${number}`, { _token: 'x' })).status()).toBe(405);
    // No token, no Retry.
    await runFakeJob(id, { lines: 1, delay: 0, fail: true });
    const failed = await newestJob(page, id, 'fake');
    await page.goto(`/jobs/${failed}`);
    expect((await postForm(page, `/jobs/${failed}/retry`)).status()).toBe(403);
    expect((await postForm(page, `/jobs/${failed}/retry`, { _token: 'wrong' })).status()).toBe(403);
    await page.goto(`/instances/${id}`);
    await expect(page.getByTestId('timeline-job').filter({ hasText: 'fake' })).toHaveCount(1);
  });

  test('a very long log line and the longest id stay inside the page', async ({ page }) => {
    const project = uniqueName('jl').padEnd(40, 'x').slice(0, 40);
    await createDeployableProject(project);
    const id = await deployOne(page, project, 'l'.repeat(21));
    expect(id).toHaveLength(63);
    await runFakeJob(id, { lines: 2, delay: 0, width: 1800, fail: true });
    const number = await newestJob(page, id, 'fake');
    await page.goto(`/jobs/${number}`);
    await expect(page.getByTestId('job-log')).toContainText('x'.repeat(1500));
    await expect(page.getByTestId('job-error')).toBeVisible();
    await expectNoOverflow(page, 'the job page');
    await expectNoBlockingViolations(page, 'the job page with a long line');
  });
});

test.describe('job page: member', () => {
  test('a member sees the jobs of their project and nothing else', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
    const own = await deployOne(page, MEMBER_PROJECT);
    const foreign = await deployOne(page, ADMIN_PROJECT);
    await runFakeJob(own, { lines: 1, delay: 0, fail: true });
    await runFakeJob(foreign, { lines: 1, delay: 0, fail: true });
    const ownFailed = await newestJob(page, own, 'fake');
    const foreignFailed = await newestJob(page, foreign, 'fake');
    const ownDeploy = await newestJob(page, own, 'deploy');

    await setStandInRole(context, 'member', baseURL!);
    await page.goto(`/jobs/${ownDeploy}`);
    await expect(page.getByTestId('job-log')).toContainText('deploy complete');
    await expect(page.getByTestId('job-retry')).toHaveCount(0);

    expect((await page.goto(`/jobs/${foreignFailed}`))?.status()).toBe(404);
    expect((await page.request.get(`/jobs/${foreignFailed}/log`)).status()).toBe(404);
    expect((await page.goto('/admin/jobs'))?.status()).toBe(403);

    // The member's own failed job: the log endpoint answers, and Retry works.
    expect((await page.request.get(`/jobs/${ownFailed}/log`)).status()).toBe(200);
    await page.goto(`/jobs/${ownFailed}`);
    await expect(page.getByTestId('job-error')).toHaveText('Fake job failed on purpose.');
    await expectNoBlockingViolations(page, 'a member, the page of a failed job');
    // Its Retry form pointed at a foreign job: the job does not exist for the member.
    expect(await submitTampered(page, page.getByTestId('job-retry'), (button, to) => (button.form!.action = new URL(to, location.href).href), `/jobs/${foreignFailed}/retry`)).toBe(404);
    await page.goto(`/jobs/${ownFailed}`);
    await follow(page, page.getByTestId('job-retry'));
    await expect(page.getByTestId('retry-of')).toHaveText(`#${ownFailed}`);
  });
});

/** Makes 51 jobs for a project: one batch deploy of 20 instances, a bulk stop of all of them and a bulk start of 11. */
async function jobsForProject(page: Page, project: string): Promise<void> {
  await submitDeploy(page, { project, label: uniqueLabel('pg'), count: 20 });
  await expect(page.getByTestId('flashes')).toContainText('20 jobs queued.');
  for (const [action, count] of [['Stop', 20], ['Start', 11]] as const) {
    await page.goto(`/instances?project=${project}`);
    await expect(page.getByTestId('instance-row')).toHaveCount(20);
    // Select all is not on the phone; ticking the boxes one by one would only repeat what the bulk spec does.
    await page.locator('input[name="ids[]"]').evaluateAll((boxes, n) =>
      boxes.slice(0, n).forEach((box) => {
        (box as HTMLInputElement).checked = true;
        box.dispatchEvent(new Event('change', { bubbles: true }));
      }),
    count);
    await expect(page.getByTestId('bulk-bar')).toContainText(`${count} selected`);
    await submitAndWait(page, page.getByTestId('bulk-bar').getByRole('button', { name: action, exact: true }));
    await expect(page.getByTestId('flashes')).toContainText(`${count} jobs queued.`);
  }
}

test.describe('job history: admin', () => {
  test.beforeEach(async ({ context, baseURL }) => {
    await setStandInRole(context, 'admin', baseURL!);
  });

  async function rowsOf(page: Page, query: string): Promise<string[]> {
    await page.goto(`/admin/jobs?${query}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Jobs' })).toBeVisible();
    return page.getByTestId('job-row').evaluateAll((rows) => rows.map((row) => (row as HTMLElement).dataset.job as string));
  }

  test('every filter, alone and together, newest first', async ({ page }) => {
    const { project, id } = await ownInstance(page);
    await runFakeJob(id, { lines: 1, delay: 0 });
    await runFakeJob(id, { lines: 1, delay: 0, fail: true });
    // The timeline is newest first: the failed fake job, the succeeded one, the deploy.
    await page.goto(`/instances/${id}`);
    const hrefs = await page.getByTestId('timeline-job').getByRole('link').evaluateAll((links) => links.map((link) => link.getAttribute('href') as string));
    const [failed, ok, deploy] = hrefs.map((href) => /\/jobs\/(\d+)$/.exec(href)?.[1] as string);
    expect(hrefs).toHaveLength(3);

    expect(await rowsOf(page, `project=${project}`)).toEqual([failed, ok, deploy]);
    expect(await rowsOf(page, `instance=${id}`)).toEqual([failed, ok, deploy]);
    expect(await rowsOf(page, `q=${id}`)).toEqual([failed, ok, deploy]);
    expect(await rowsOf(page, `q=${project}`)).toEqual([failed, ok, deploy]);
    // A number also matches the digits inside other ids, so the job is among the rows, not alone.
    expect(await rowsOf(page, `q=${deploy}`)).toContain(deploy);
    expect(await rowsOf(page, `project=${project}&status=failed`)).toEqual([failed]);
    expect(await rowsOf(page, `project=${project}&status=succeeded`)).toEqual([ok, deploy]);
    expect(await rowsOf(page, `project=${project}&trigger=ui`)).toEqual([deploy]);
    expect(await rowsOf(page, `project=${project}&trigger=cli`)).toEqual([failed, ok]);
    expect(await rowsOf(page, `project=${project}&trigger=cli&status=succeeded`)).toEqual([ok]);
    expect(await rowsOf(page, `project=${project}&type=deploy`)).toEqual([deploy]);
    expect(await rowsOf(page, `instance=${id}&type=deploy&trigger=ui&status=succeeded`)).toEqual([deploy]);
    expect(await rowsOf(page, `instance=${id}&type=deploy&trigger=cli`)).toEqual([]);
    // Nothing of the other kinds happened.
    for (const none of ['status=queued', 'status=running', 'status=interrupted', 'trigger=webhook', 'trigger=schedule', 'type=start', 'type=stop', 'type=redeploy', 'type=destroy']) {
      expect(await rowsOf(page, `project=${project}&${none}`), none).toEqual([]);
    }
    // A value that names nothing matches nothing (the "fake" type is not on the list either); the search is trimmed and not case sensitive.
    for (const nothing of ['status=bogus', 'trigger=bogus', 'type=bogus', 'type=fake', 'type=DEPLOY', 'status=Failed']) {
      expect(await rowsOf(page, `project=${project}&${nothing}`), nothing).toEqual([]);
    }
    expect(await rowsOf(page, `q=${encodeURIComponent(`  ${project.toUpperCase()} `)}`)).toEqual([failed, ok, deploy]);
    expect(await rowsOf(page, `project=${project.toUpperCase()}`)).toEqual([]);
    expect(await rowsOf(page, `instance=${id}-x`)).toEqual([]);
  });

  test('the search treats % and _ as plain characters and cuts the text at 100 characters', async ({ page }) => {
    const { project, id } = await ownInstance(page);
    expect((await rowsOf(page, `project=${project}&q=${encodeURIComponent('%')}`)).length).toBe(0);
    expect((await rowsOf(page, `project=${project}&q=${encodeURIComponent('_')}`)).length).toBe(0);
    expect((await rowsOf(page, `project=${project}&q=${encodeURIComponent(project.slice(0, 6) + '%')}`)).length).toBe(0);
    expect((await rowsOf(page, `q=${encodeURIComponent(project.slice(0, 6) + '_')}`)).length).toBe(0);
    // 100 characters of the project name's own text still match; 150 are cut to 100 and are no longer a part of any id.
    expect((await rowsOf(page, `q=${encodeURIComponent(`${id}${'z'.repeat(150)}`)}`)).length).toBe(0);
    expect((await rowsOf(page, `q=${id}`)).length).toBe(1);
  });

  test('the form filters, keeps its choices and the hidden project and instance', async ({ page }) => {
    const { project, id } = await ownInstance(page);
    await runFakeJob(id, { lines: 1, delay: 0, fail: true });
    await page.goto(`/admin/jobs?project=${project}&instance=${id}`);
    const filters = page.getByTestId('job-filters');
    await expect(filters.getByLabel('Status').locator('option')).toHaveText(['Any status', 'Queued', 'Running', 'Succeeded', 'Failed', 'Interrupted']);
    await expect(filters.getByLabel('Trigger').locator('option')).toHaveText(['Any trigger', 'Ui', 'Cli', 'Webhook', 'Schedule']);
    await expect(filters.getByLabel('Type').locator('option')).toHaveText(['Any type', 'Deploy', 'Start', 'Stop', 'Redeploy', 'Destroy']);
    await expect(page.getByTestId('job-row')).toHaveCount(2);

    await filters.getByLabel('Status').selectOption('failed');
    await filters.getByLabel('Trigger').selectOption('cli');
    await filters.getByLabel('Search jobs').fill(id);
    await follow(page, filters.getByRole('button', { name: 'Filter' }));
    await expect(page).toHaveURL(new RegExp(`status=failed&trigger=cli&type=&q=${id}&project=${project}&instance=${id}$`));
    await expect(page.getByTestId('job-row')).toHaveCount(1);
    await expect(page.getByTestId('job-row').getByTestId('job-status')).toHaveAttribute('data-status', 'failed');
    await expect(filters.getByLabel('Status')).toHaveValue('failed');
    await expect(filters.getByLabel('Trigger')).toHaveValue('cli');
    await expect(filters.getByLabel('Type')).toHaveValue('');
    await expect(filters.getByLabel('Search jobs')).toHaveValue(id);

    await filters.getByLabel('Type').selectOption('deploy');
    await follow(page, filters.getByRole('button', { name: 'Filter' }));
    await expect(page.getByTestId('jobs-empty')).toContainText('No jobs');
    await expect(page.getByTestId('jobs-empty')).toContainText('Nothing has run yet, or no job matches the filters.');
    await expect(page.getByTestId('job-table')).toHaveCount(0);
    await expectNoBlockingViolations(page, 'the job history with no match');
  });

  test('a row links to its job and to nothing else it should not, with the facts of the job', async ({ page }) => {
    const { project, id } = await ownInstance(page);
    const number = await newestJob(page, id, 'deploy');
    await page.goto(`/admin/jobs?project=${project}`);
    const row = page.locator(`[data-testid="job-row"][data-job="${number}"]`);
    await expect(row).toContainText(`#${number} deploy`);
    await expect(row).toContainText(id);
    await expect(row).toContainText('ui');
    await expect(row.getByTestId('job-status')).toHaveText('succeeded');
    await expect(row).toContainText(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/);
    await expectNoBlockingViolations(page, 'the job history');
    await follow(page, row.getByRole('link', { name: `#${number} deploy` }));
    await expect(page).toHaveURL(new RegExp(`/jobs/${number}$`));
    await expect(page.getByRole('heading', { level: 1, name: `Job #${number}` })).toBeVisible();
  });

  test('50 jobs a page (51 jobs): Next, Previous, the filters kept, a page past the end, a bad page number', async ({ page }) => {
    const project = uniqueName('pg');
    await createDeployableProject(project);
    await jobsForProject(page, project);

    expect((await rowsOf(page, `project=${project}`)).length).toBe(50);
    await expect(page.getByTestId('jobs-previous')).toHaveCount(0);
    await expect(page.getByRole('navigation', { name: 'Pages' })).toContainText('Page 1');
    await expectNoBlockingViolations(page, 'the first page of the job history');

    await follow(page, page.getByTestId('jobs-next'));
    await expect(page).toHaveURL(new RegExp(`project=${project}&.*page=2$`));
    await expect(page.getByTestId('job-row')).toHaveCount(1);
    await expect(page.getByTestId('jobs-next')).toHaveCount(0);
    await expect(page.getByRole('navigation', { name: 'Pages' })).toContainText('Page 2');
    await follow(page, page.getByTestId('jobs-previous'));
    await expect(page).toHaveURL(new RegExp(`project=${project}&.*page=1$`));
    await expect(page.getByTestId('job-row')).toHaveCount(50);

    // A filter that leaves 20 jobs has one page; the paging links keep the filters.
    expect((await rowsOf(page, `project=${project}&type=deploy`)).length).toBe(20);
    await expect(page.getByRole('navigation', { name: 'Pages' })).toHaveCount(0);
    const page1 = await rowsOf(page, `project=${project}&status=succeeded`);
    expect(page1.length).toBe(50);
    expect(await page.getByTestId('jobs-next').getAttribute('href')).toContain(`project=${project}`);
    expect(await page.getByTestId('jobs-next').getAttribute('href')).toContain('status=succeeded');
    const page2 = await rowsOf(page, `project=${project}&status=succeeded&page=2`);
    expect(page2.length).toBe(1);
    expect(page2.filter((job) => page1.includes(job))).toEqual([]);

    // Past the end: an empty page that still offers the way back. Not a number, zero, negative or too long: page 1.
    expect(await rowsOf(page, `project=${project}&page=3`)).toEqual([]);
    await expect(page.getByTestId('jobs-empty')).toContainText('There are no jobs on this page.');
    await expect(page.getByTestId('jobs-previous')).toBeVisible();
    for (const bad of ['abc', '0', '-2', '1.5', '1234567']) {
      expect((await rowsOf(page, `project=${project}&page=${bad}`)).length, `page=${bad}`).toBe(50);
    }
  });

  test('fits the phone width with the longest id and shows the filters as stacked controls', async ({ page }) => {
    test.skip(test.info().project.name !== 'phone', 'phone width only');
    const project = uniqueName('jw').padEnd(40, 'x').slice(0, 40);
    await createDeployableProject(project);
    const id = await deployOne(page, project, 'l'.repeat(21));
    await page.goto(`/admin/jobs?project=${project}`);
    await expect(page.getByTestId('job-row')).toHaveCount(1);
    await expectNoOverflow(page, 'the job history');
    for (const control of [page.getByLabel('Status'), page.getByLabel('Trigger'), page.getByLabel('Type'), page.getByLabel('Search jobs'), page.getByRole('button', { name: 'Filter' })]) {
      const box = await control.boundingBox();
      expect(box?.height ?? 0, 'a control is at least 44 px high').toBeGreaterThanOrEqual(43.5);
    }
    await expect(page.getByTestId('job-row')).toContainText(id);
    await expectNoBlockingViolations(page, 'phone, the job history');
  });
});
