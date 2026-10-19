import { expect, test, type Locator, type Page } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { blockingViolations } from './support/axe';
import { createProjectViaUi, deleteProjectViaUi, follow, GIT_URL, openDialog, openSection, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';
import { setStandInRole } from './support/standIn';

// Projects and templates in a real browser, at 1280 px (desktop project) and 390 px (phone project). The spec has no
// .desktop/.phone suffix: it runs on both. Every test owns a unique project (support/projects.ts: uniqueName) and removes
// it in `finally`, so the suite is repeatable against the shared dev database and safe in parallel.

const isPhone = (): boolean => test.info().project.name === 'phone';

/** The element is at least `min` px tall: the tap-target size of the phone design (rule: 44 px). */
async function expectTall(target: Locator, min = 44): Promise<void> {
  await expect(target).toBeVisible();
  const box = await target.boundingBox();
  expect(box, 'element has a box').not.toBeNull();
  expect(box!.height, `height of ${await target.evaluate((node) => node.outerHTML.slice(0, 80))}`).toBeGreaterThanOrEqual(min - 0.5);
}

async function expectNoOverflow(page: Page, where: string): Promise<void> {
  expect(await hasHorizontalOverflow(page), `horizontal overflow on ${where}`).toBe(false);
}

/**
 * Runs one part of a long walk-through as a named step and prints how long it took, so a CI timeout of the whole test
 * shows in the log (and in the Playwright report) which part was still running.
 */
async function timed(name: string, body: () => Promise<void>): Promise<void> {
  const started = Date.now();
  try {
    await test.step(name, body);
  } finally {
    console.log(`[step] ${test.info().project.name}: ${name} took ${((Date.now() - started) / 1000).toFixed(1)} s`);
  }
}

test.describe('projects and templates', () => {
  test('admin creates, edits and deletes a project and its template', async ({ page }) => {
    // About 35 page loads; a busy shared host (the companion's php-fpm next to other workers) needs minutes, not seconds.
    test.setTimeout(240_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();

    try {
      // Create: from the list through "New project".
      await timed('create from the list', async () => {
        await page.goto('/admin/projects');
        await expect(page.getByRole('heading', { level: 1, name: 'Projects' })).toBeVisible();
        await follow(page, page.getByRole('link', { name: 'New project' }).first());
        await expect(page.getByRole('heading', { level: 1, name: 'New project' })).toBeVisible();
        await page.getByLabel('Name', { exact: true }).fill(name);
        await page.getByLabel('Git URL').fill(GIT_URL);
        await page.getByLabel('Additional hostnames').fill('www');
        await page.getByRole('button', { name: 'Create project' }).click();
        await expect(page.getByTestId('flashes')).toContainText('Project created.');
        await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
        await expect(page.getByLabel('Additional hostnames')).toHaveValue('www');
      });

      // Edit: the default branch.
      await timed('edit the default branch', async () => {
        await page.getByLabel('Default branch').fill('develop');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByTestId('flashes')).toContainText('Project saved.');
        await expect(page.getByLabel('Default branch')).toHaveValue('develop');
      });

      // The edit page offers the other sections, the danger zone and the issue id tester.
      await timed('edit page parts', async () => {
        await expect(page.getByTestId('issue-id-preview')).toBeVisible();
        await expect(page.getByTestId('danger-zone')).toBeVisible();
      });

      // Templates: none yet, then one with two post-deploy lines, one tty1 and one tty2 line.
      await timed('create and reopen a template', async () => {
        await openSection(page, 'Templates');
        await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/templates$`));
        await expect(page.getByTestId('templates-empty')).toBeVisible();
        await follow(page, page.getByRole('link', { name: 'New template' }));
        await expect(page.getByTestId('template-heading')).toHaveText('New template');
        await page.getByLabel('Name', { exact: true }).fill('web');
        await page.getByLabel('Drupal environment').fill('dev');
        await page.getByLabel('Post-deploy commands').fill('composer install\ndrush deploy');
        await page.getByLabel('Terminal 1 commands').fill('npm run watch');
        await page.getByLabel('Terminal 2 commands').fill('tail -f var/log/dev.log');
        await page.getByRole('button', { name: 'Create template' }).click();
        await expect(page.getByTestId('flashes')).toContainText('Template created.');

        const row = page.getByTestId('template-row').filter({ hasText: 'web' });
        await expect(row).toHaveCount(1);
        await expect(row.getByTestId('template-env')).toHaveText('dev');
        await expect(row.getByTestId('template-post-deploy')).toHaveText('2');
        await expect(row.getByTestId('template-tty1')).toHaveText('1');
        await expect(row.getByTestId('template-tty2')).toHaveText('1');

        // Reopening shows the exact lines.
        await follow(page, row.getByRole('link', { name: 'Edit web' }));
        await expect(page.getByTestId('template-heading')).toContainText('web');
        await expect(page.getByLabel('Drupal environment')).toHaveValue('dev');
        await expect(page.getByLabel('Post-deploy commands')).toHaveValue('composer install\ndrush deploy');
        await expect(page.getByLabel('Terminal 1 commands')).toHaveValue('npm run watch');
        await expect(page.getByLabel('Terminal 2 commands')).toHaveValue('tail -f var/log/dev.log');
      });

      // The list shows the project with its one template.
      await timed('project list', async () => {
        await page.goto('/admin/projects');
        const projectRow = page.getByTestId('project-row').filter({ hasText: name });
        await expect(projectRow).toContainText('1 template');
        await expect(projectRow).toContainText(GIT_URL);
      });

      // The ports section is read only until M0.07.
      await timed('ports tab', async () => {
        await follow(page, page.getByTestId('project-row').filter({ hasText: name }).getByRole('link'));
        await openSection(page, 'Ports');
        await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}/ports$`));
        await expect(page.getByTestId('ports-note')).toContainText('M0.07');
      });

      // Make web the default template (General tab).
      await timed('make web the default', async () => {
        await openSection(page, 'General');
        await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
        await page.getByLabel('Default template').selectOption('web');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByTestId('flashes')).toContainText('Project saved.');
        await expect(page.getByLabel('Default template')).toHaveValue('web');
      });

      // Deleting the default template is refused, and the template is still there.
      await timed('default template delete refused', async () => {
        await openSection(page, 'Templates');
        await expect(page.getByTestId('template-default')).toBeVisible();
        await follow(page, page.getByRole('link', { name: 'Edit web' }));
        const templateDialog = await openDialog(page, page.getByRole('button', { name: 'Delete this template' }));
        await templateDialog.getByRole('button', { name: 'Delete template' }).click();
        await expect(page.getByTestId('flashes')).toContainText('The template was not deleted.');
        await expect(page.getByTestId('flashes')).toContainText('default template');
        await expect(page.getByTestId('template-row').filter({ hasText: 'web' })).toHaveCount(1);
      });

      // Clear the default, then the template can be deleted.
      await timed('clear default, delete template', async () => {
        await openSection(page, 'General');
        await page.getByLabel('Default template').selectOption({ label: 'None' });
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByTestId('flashes')).toContainText('Project saved.');
        await openSection(page, 'Templates');
        await expect(page.getByTestId('template-default')).toHaveCount(0);
        await follow(page, page.getByRole('link', { name: 'Edit web' }));
        const deleteDialog = await openDialog(page, page.getByRole('button', { name: 'Delete this template' }));
        await deleteDialog.getByRole('button', { name: 'Delete template' }).click();
        await expect(page.getByTestId('flashes')).toContainText('Template deleted.');
        await expect(page.getByTestId('templates-empty')).toBeVisible();
      });

      // Delete the project through its dialog: Cancel keeps it, the confirmation removes it.
      await timed('delete the project', async () => {
        await openSection(page, 'General');
        const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
        await expect(dialog).toContainText(`Delete ${name}?`);
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden();
        await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
        const confirm = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
        await confirm.getByRole('button', { name: 'Delete project' }).click();
        await expect(page.getByTestId('flashes')).toContainText(`Project "${name}" deleted.`);
        await expect(page).toHaveURL(/\/admin\/projects$/);
        await expect(page.getByTestId('project-row').filter({ hasText: name })).toHaveCount(0);
      });

      // Everything above ran without browser errors; the 404 below is logged by the browser on purpose.
      expect(errors).toEqual([]);
      const gone = await page.goto(`/admin/projects/${name}`);
      expect(gone?.status()).toBe(404);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('bad input shows field-level errors and keeps what was typed', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();

    try {
      // New project: a non-https URL and a name with a space and capitals.
      await page.goto('/admin/projects/new');
      await page.getByLabel('Name', { exact: true }).fill('Bad Name');
      await page.getByLabel('Git URL').fill('http://example.com/x.git');
      const [refused] = await Promise.all([
        page.waitForResponse((response) => response.request().method() === 'POST'),
        page.getByRole('button', { name: 'Create project' }).click(),
      ]);
      expect(refused.status()).toBe(422);
      const form = page.getByTestId('project-form');
      await expect(form).toContainText('Use lowercase letters, digits and single dashes only');
      await expect(form).toContainText('Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.');
      await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Bad Name');
      await expect(page.getByLabel('Git URL')).toHaveValue('http://example.com/x.git');
      await expect(page).toHaveURL(/\/admin\/projects\/new$/);

      // Fix both fields: the same form now works.
      await page.getByLabel('Name', { exact: true }).fill(name);
      await page.getByLabel('Git URL').fill(GIT_URL);
      await page.getByRole('button', { name: 'Create project' }).click();
      await expect(page.getByTestId('flashes')).toContainText('Project created.');

      // New template: a Drupal environment with a space, a terminal line of 2001 characters.
      const longLine = 'x'.repeat(2001);
      await openSection(page, 'Templates');
      await follow(page, page.getByRole('link', { name: 'New template' }));
      await page.getByLabel('Name', { exact: true }).fill('web');
      await page.getByLabel('Drupal environment').fill('a b');
      await page.getByLabel('Terminal 1 commands').fill(longLine);
      const [refusedTemplate] = await Promise.all([
        page.waitForResponse((response) => response.request().method() === 'POST'),
        page.getByRole('button', { name: 'Create template' }).click(),
      ]);
      expect(refusedTemplate.status()).toBe(422);
      const templateForm = page.getByTestId('template-form');
      await expect(templateForm).toContainText('Use 1 to 64 letters, digits, dots, underscores or dashes.');
      await expect(templateForm).toContainText('Command line 1 is longer than 2000 characters.');
      await expect(page.getByLabel('Name', { exact: true })).toHaveValue('web');
      await expect(page.getByLabel('Drupal environment')).toHaveValue('a b');
      await expect(page.getByLabel('Terminal 1 commands')).toHaveValue(longLine);

      // The browser logs each refused form as a console error ("status of 422"): the two forms above, nothing else.
      expect(errors.filter((error) => !error.includes('status of 422'))).toEqual([]);
      expect(errors).toHaveLength(2);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('a member cannot open project settings', async ({ page, context, baseURL }) => {
    await setStandInRole(context, 'member', baseURL!);

    for (const path of ['/admin/projects', '/admin/projects/new', '/admin/projects/anything', '/admin/projects/anything/templates']) {
      const response = await page.goto(path);
      expect(response?.status(), path).toBe(403);
      await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible();
      await expect(page.getByText('You do not have access to this page')).toBeVisible();
      // No Projects entry in any navigation (sidebar, bottom bar, More sheet).
      await expect(page.locator('a[href="/admin/projects"]')).toHaveCount(0);
    }

    // The issue id tester is admin only too: the component class decides, not the route rule. A bare GET never reaches
    // the access check (the live component route answers 404 first), so it must be exactly a denial (403 or 404, D10) and
    // serve nothing; ComponentsAccessTest pins the 403 for a signed request.
    const live = await context.request.get('/_components/IssueIdPreview');
    expect([403, 404]).toContain(live.status());
    expect(await live.text()).not.toContain('issue-id');
  });

  test('issue id tester shows the key', async ({ page }) => {
    test.setTimeout(150_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();

    try {
      await createProjectViaUi(page, name);
      const tester = page.getByTestId('issue-id-preview');
      await expect(tester).toBeVisible();
      await expect(tester).toHaveAttribute('data-controller', /live/);
      const pattern = tester.getByLabel('Pattern');
      const label = tester.getByLabel('Sample instance label');

      // The script is loaded when typing makes a request to the component endpoint.
      // The live controller is loaded lazily: typing is repeated until the first re-render request proves it is running.
      await expect(async () => {
        const rerender = page.waitForResponse((response) => response.url().includes('/_components/') && response.status() === 200, { timeout: 4_000 });
        rerender.catch(() => undefined);
        await pattern.fill('');
        await pattern.fill('OAKS-[0-9]+');
        await rerender;
      }).toPass({ timeout: 60_000 });
      await label.fill('oaks-1781');
      await expect(tester.getByTestId('issue-id-extracted')).toHaveText('OAKS-1781');
      await expect(tester.getByTestId('issue-id-result')).toHaveAttribute('data-state', 'match');
      await expect(tester).toContainText('OAKS-1781');

      // A pattern that can backtrack catastrophically is refused with a reason, not run.
      await pattern.fill('(a+)+$');
      await expect(tester.getByTestId('issue-id-result')).toHaveAttribute('data-state', 'error');
      await expect(tester.getByTestId('issue-id-message')).toContainText('nested quantifier');

      // The tester saves nothing: the project's own pattern field is still empty.
      await expect(page.getByLabel('Issue id pattern')).toHaveValue('');

      if (isPhone()) {
        for (const field of [pattern, label, tester.getByLabel('Sample branch')]) {
          await expectTall(field);
        }
        await expectNoOverflow(page, 'the tester with an error message');
      }

      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('a form row toggles from its edge, over the full 44 px row', async ({ page }) => {
    test.setTimeout(150_000);
    const name = uniqueName();

    try {
      await createProjectViaUi(page, name);

      // Radio row: the far right edge of the row, far from the 16 px circle and from the text.
      const radioRows = page.locator('[data-slot=radio-row]');
      await expect(radioRows).toHaveCount(3);
      const second = radioRows.nth(1);
      const radioBox = (await second.boundingBox())!;
      expect(radioBox.height).toBeGreaterThanOrEqual(43.5);
      await second.click({ position: { x: radioBox.width - 3, y: radioBox.height - 3 } });
      await expect(page.getByLabel('Do not set a git identity')).toBeChecked();
      await radioRows.nth(2).click({ position: { x: radioBox.width - 3, y: 3 } });
      await expect(page.getByLabel('Custom identity')).toBeChecked();
      await expect(page.getByLabel('Do not set a git identity')).not.toBeChecked();

      // Checkbox row: same, bottom right corner.
      const checkboxRow = page.locator('[data-slot=checkbox-row]');
      const checkboxBox = (await checkboxRow.boundingBox())!;
      if (isPhone()) {
        expect(checkboxBox.height).toBeGreaterThanOrEqual(43.5);
      }
      const typesense = page.getByLabel('Run a Typesense search server for each instance');
      await expect(typesense).not.toBeChecked();
      await checkboxRow.click({ position: { x: checkboxBox.width - 3, y: checkboxBox.height - 2 } });
      await expect(typesense).toBeChecked();

      // Both survive a save (a custom identity needs a name or an email).
      await page.getByLabel('Identity name').fill('Acme Bot');
      await page.getByRole('button', { name: 'Save', exact: true }).click();
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');
      await expect(page.getByLabel('Custom identity')).toBeChecked();
      await expect(typesense).toBeChecked();
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});

test.describe('phone layout of the project pages (390 px)', () => {
  test.skip(() => !isPhone(), 'phone only');

  // The worst case the form fields allow: a 40-char project name, a 200-char git URL, a 40-char template name and a
  // 64-char Drupal environment. One project is shared by the tests below, created once.
  const NAME = `pw-${randomBytes(4).toString('hex')}-`.padEnd(40, 'p');
  const GIT = `https://git.example.com/acme/${'r'.repeat(200 - 'https://git.example.com/acme/'.length - 4)}.git`;
  const TEMPLATE = `tpl-${'t'.repeat(36)}`;
  const ENV = 'e'.repeat(64);

  test.describe.configure({ mode: 'serial', timeout: 180_000 });

  test.beforeAll(async ({ browser, baseURL }) => {
    expect(NAME).toHaveLength(40);
    expect(GIT).toHaveLength(200);
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, extraHTTPHeaders: { 'X-Fleet-Profiler-Toolbar': '0' } });
    const page = await context.newPage();
    await createProjectViaUi(page, NAME, GIT);
    await openSection(page, 'Templates');
    await follow(page, page.getByRole('link', { name: 'New template' }));
    await page.getByLabel('Name', { exact: true }).fill(TEMPLATE);
    await page.getByLabel('Drupal environment').fill(ENV);
    await page.getByLabel('Post-deploy commands').fill('composer install');
    await page.getByRole('button', { name: 'Create template' }).click();
    await expect(page.getByTestId('flashes')).toContainText('Template created.');
    await context.close();
  });

  test.afterAll(async ({ browser, baseURL }) => {
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, extraHTTPHeaders: { 'X-Fleet-Profiler-Toolbar': '0' } });
    const page = await context.newPage();
    await deleteProjectViaUi(page, NAME);
    await context.close();
  });

  test('no horizontal overflow on list, new, edit, templates, template form and ports', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    const pages: Array<[string, string]> = [
      ['/admin/projects', 'the list'],
      ['/admin/projects/new', 'the create form'],
      [`/admin/projects/${NAME}`, 'the edit form'],
      [`/admin/projects/${NAME}/templates`, 'the template list'],
      [`/admin/projects/${NAME}/templates/new`, 'the new template form'],
      [`/admin/projects/${NAME}/templates/${TEMPLATE}`, 'the template form'],
      [`/admin/projects/${NAME}/ports`, 'the ports tab'],
    ];
    for (const [path, where] of pages) {
      await page.goto(path);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      await expectNoOverflow(page, `${where} (${path})`);
    }
    expect(errors).toEqual([]);
  });

  test('the list row of the long project fits, and its link is a 44 px target', async ({ page }) => {
    await page.goto('/admin/projects');
    const row = page.getByTestId('project-row').filter({ hasText: NAME });
    await expectTall(row.getByRole('link'));
    await expectTall(page.getByRole('link', { name: 'New project' }).first());
    const link = (await row.getByRole('link').boundingBox())!;
    expect(link.x + link.width).toBeLessThanOrEqual(page.viewportSize()!.width + 0.5);
  });

  test('primary buttons are 44 px tall and the section tabs switch pages', async ({ page }) => {
    await page.goto(`/admin/projects/${NAME}`);
    await expectTall(page.getByRole('button', { name: 'Save', exact: true }));
    await expectTall(page.getByRole('button', { name: 'Delete this project' }));
    await expect(page.getByRole('combobox', { name: 'Section' })).toHaveCount(0);
    const tabs = page.getByRole('navigation', { name: 'Project sections' });
    for (const section of ['General', 'Templates', 'Ports']) {
      await expectTall(tabs.getByRole('link', { name: section }));
    }
    await expectNoOverflow(page, 'the section tabs');
    const strip = (await tabs.boundingBox())!;
    expect(strip.x + strip.width).toBeLessThanOrEqual(page.viewportSize()!.width + 0.5);

    await openSection(page, 'Templates');
    await expect(page).toHaveURL(new RegExp(`/admin/projects/${NAME}/templates$`));
    await expectTall(page.getByRole('link', { name: 'New template' }));
    await expect(tabs.getByRole('link', { name: 'Templates' })).toHaveAttribute('aria-current', 'page');

    await openSection(page, 'Ports');
    await expect(page).toHaveURL(new RegExp(`/admin/projects/${NAME}/ports$`));

    await openSection(page, 'General');
    await expect(page).toHaveURL(new RegExp(`/admin/projects/${NAME}$`));
  });

  test('the template list scrolls in its container and the Edit button is reachable', async ({ page }) => {
    await page.goto(`/admin/projects/${NAME}/templates`);
    const row = page.getByTestId('template-row').filter({ hasText: TEMPLATE });
    await expect(row.getByTestId('template-env')).toHaveText(ENV);
    await expectNoOverflow(page, 'the template list');
    // The wide table scrolls inside its own container (the page does not): the container is wider inside than outside.
    const container = page.locator('[data-slot=table-container]');
    const scroll = await container.evaluate((node) => ({ overflowX: getComputedStyle(node).overflowX, inner: node.scrollWidth, outer: node.clientWidth }));
    expect(scroll.overflowX).toBe('auto');
    expect(scroll.inner, 'the 6-column table is wider than a 390 px screen').toBeGreaterThan(scroll.outer);
    const edit = row.getByRole('link', { name: `Edit ${TEMPLATE}` });
    await expectTall(edit);
    await follow(page, edit);
    await expect(page).toHaveURL(new RegExp(`/templates/${TEMPLATE}$`));
    await expect(page.getByTestId('template-heading')).toContainText(TEMPLATE);
    await expectTall(page.getByRole('button', { name: 'Save', exact: true }));
    await expectTall(page.getByRole('link', { name: 'Cancel' }));
    await expectTall(page.getByRole('button', { name: 'Delete this template' }));
  });

  test('the delete dialog fits, its buttons are 44 px, Cancel closes it', async ({ page }) => {
    await page.goto(`/admin/projects/${NAME}`);
    const dialog = await openDialog(page, page.getByRole('button', { name: 'Delete this project' }));
    await expect(dialog).toContainText(`Delete ${NAME}?`);
    await expectNoOverflow(page, 'the delete dialog');
    const box = (await dialog.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(-0.5);
    expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize()!.width + 0.5);
    await expectTall(dialog.getByRole('button', { name: 'Cancel' }));
    await expectTall(dialog.getByRole('button', { name: 'Delete project' }));
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
  });

  for (const scheme of ['light', 'dark'] as const) {
    test.describe(`axe, ${scheme} theme`, () => {
      test.use({ colorScheme: scheme });

      for (const [label, path] of [
        ['edit', `/admin/projects/${NAME}`],
        ['template list', `/admin/projects/${NAME}/templates`],
        ['template form', `/admin/projects/${NAME}/templates/${TEMPLATE}`],
        ['ports', `/admin/projects/${NAME}/ports`],
      ] as const) {
        test(`no serious or critical violations on the ${label} page`, async ({ page }) => {
          await page.goto(path);
          await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
          if (scheme === 'dark') {
            await expect(page.locator('html')).toHaveClass(/\bdark\b/);
          }
          expect(await blockingViolations(page)).toEqual([]);
        });
      }
    });
  }
});
