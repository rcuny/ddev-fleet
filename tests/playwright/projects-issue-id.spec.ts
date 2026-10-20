import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { fillProject, readProject, submitSave } from './support/projectForm';
import { createProjectViaUi, deleteProjectViaUi, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// The issue id tester on a project's General tab (a live component): every state it can show, every error the pattern
// rules return, the samples' limits, and that nothing typed there is saved.

interface Tester {
  box: Locator;
  pattern: Locator;
  label: Locator;
  branch: Locator;
  result: Locator;
}

function tester(page: Page): Tester {
  const box = page.getByTestId('issue-id-preview');
  return { box, pattern: box.getByLabel('Pattern'), label: box.getByLabel('Sample instance label'), branch: box.getByLabel('Sample branch'), result: box.getByTestId('issue-id-result') };
}

/**
 * The live controller loads lazily: typing is repeated until the first re-render request proves it is running, then the
 * field is left holding `first`.
 */
async function warm(page: Page, t: Tester, first: string): Promise<void> {
  await expect(async () => {
    const rerender = page.waitForResponse((response) => response.url().includes('/_components/') && response.status() === 200, { timeout: 4_000 });
    rerender.catch(() => undefined);
    await t.pattern.fill('');
    await t.pattern.fill(first);
    await rerender;
  }).toPass({ timeout: 60_000 });
}

/** Sets a field the browser would cut at its maxlength (a pasted or scripted value) and lets the component see it. */
async function setRaw(field: Locator, value: string): Promise<void> {
  await field.evaluate((node, text) => {
    const input = node as HTMLInputElement;
    input.value = text;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }, value);
}

test.describe('issue id tester', () => {
  test('an empty tester asks for a pattern, then for a sample, then finds the id', async ({ page }) => {
    test.setTimeout(150_000);
    const errors = collectBrowserErrors(page);
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      const t = tester(page);
      await expect(t.result).toHaveAttribute('data-state', 'empty');
      await expect(t.box.getByTestId('issue-id-message')).toHaveText('Enter a pattern to test.');
      await expect(t.pattern).toHaveValue('');

      await warm(page, t, 'OAKS-[0-9]+');
      await expect(t.result).toHaveAttribute('data-state', 'empty');
      await expect(t.box.getByTestId('issue-id-message')).toHaveText('Enter a sample instance label or branch.');

      await t.label.fill('oaks-1781');
      await expect(t.result).toHaveAttribute('data-state', 'match');
      await expect(t.box.getByTestId('issue-id-extracted')).toHaveText('OAKS-1781');
      await expectNoBlockingViolations(page, 'the tester with a match');

      // A label that holds no id, then the branch sample that does.
      await t.label.fill('nothing-here');
      await expect(t.result).toHaveAttribute('data-state', 'none');
      await expect(t.box.getByTestId('issue-id-message')).toHaveText('No issue id found in these samples.');
      await t.branch.fill('feature/oaks-42-search');
      await expect(t.box.getByTestId('issue-id-extracted')).toHaveText('OAKS-42');

      // Clearing the pattern goes back to the first state.
      await t.pattern.fill('');
      await expect(t.result).toHaveAttribute('data-state', 'empty');
      await expect(t.box.getByTestId('issue-id-message')).toHaveText('Enter a pattern to test.');
      expect(errors).toEqual([]);
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('a capture group gives the id, the label wins over the branch, the id is upper-cased', async ({ page }) => {
    test.setTimeout(150_000);
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      const t = tester(page);
      await warm(page, t, 'x');
      await t.pattern.fill('^feature/([a-z]+-\\d+)');
      await t.branch.fill('feature/abc-123-fix');
      await expect(t.box.getByTestId('issue-id-extracted')).toHaveText('ABC-123');
      await t.pattern.fill('[a-z]+-\\d+');
      await t.label.fill('lbl-1');
      await t.branch.fill('feature/brn-2');
      await expect(t.box.getByTestId('issue-id-extracted')).toHaveText('LBL-1');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  const patternErrors: Array<[string, string, string]> = [
    ['a tilde', 'AB~C', 'The pattern must not contain "~".'],
    ['an unclosed group', '(ABC', 'invalid pattern:'],
    ['literal quoting', '\\QABC\\E', 'literal quoting (\\Q...\\E) is not allowed in the pattern'],
    ['a backreference', '(AB)\\1', 'backreferences are not allowed in the pattern'],
    ['recursion', '(a)(?1)', 'the pattern uses syntax that is not allowed'],
    ['a nested quantifier', '(a+)+$', 'nested quantifier: a repeated group must not contain a quantifier or an alternation'],
    ['a repeated alternation', '(a|aa)*', 'nested quantifier'],
    ['an expensive pattern', '\\w*\\w*\\w*\\w*\\d', 'pattern is too expensive'],
  ];
  test('each pattern error is shown in the result box, in red, with a sample typed or not', async ({ page }) => {
    test.setTimeout(240_000);
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      const t = tester(page);
      await warm(page, t, 'x');
      for (const [title, value, message] of patternErrors) {
        await t.pattern.fill(value);
        await expect(t.result, title).toHaveAttribute('data-state', 'error');
        await expect(t.box.getByTestId('issue-id-message'), title).toContainText(message);
      }
      await t.pattern.fill('a'.repeat(201));
      await setRaw(t.pattern, 'a'.repeat(201));
      await expect(t.box.getByTestId('issue-id-message')).toHaveText('The pattern must be at most 200 characters.');
      await expectNoBlockingViolations(page, 'the tester with an error');
      // The form's own pattern field was never touched.
      await expect(page.getByLabel('Issue id pattern')).toHaveValue('');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the samples are limited to 63 and 200 characters', async ({ page }) => {
    test.setTimeout(150_000);
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      const t = tester(page);
      await warm(page, t, 'ABC-[0-9]+');
      await setRaw(t.label, 'l'.repeat(64));
      await expect(t.box.getByTestId('issue-id-message')).toHaveText('The label must be at most 63 characters.');
      await t.label.fill('');
      await setRaw(t.branch, 'b'.repeat(201));
      await expect(t.box.getByTestId('issue-id-message')).toHaveText('The branch name must be at most 200 characters.');
      await expect(t.result).toHaveAttribute('data-state', 'error');
      if (test.info().project.name === 'phone') {
        expect(await hasHorizontalOverflow(page), 'overflow with the tester error').toBe(false);
      }
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });

  test('the tester starts from the saved pattern, trying another one saves nothing, saving the field updates the tester', async ({ page }) => {
    test.setTimeout(150_000);
    const name = uniqueName();
    try {
      await createProjectViaUi(page, name);
      await fillProject(page, { pattern: 'OAKS-[0-9]+' });
      await submitSave(page);
      await expect(page.getByTestId('flashes')).toContainText('Project saved.');

      const t = tester(page);
      await expect(t.pattern).toHaveValue('OAKS-[0-9]+');
      await warm(page, t, 'OTHER-[0-9]+');
      await t.label.fill('other-9');
      await expect(t.box.getByTestId('issue-id-extracted')).toHaveText('OTHER-9');

      // Reload: the saved pattern is back and the tried one is gone.
      await page.goto(`/admin/projects/${name}`);
      expect(await readProject(page)).toMatchObject({ pattern: 'OAKS-[0-9]+' });
      await expect(tester(page).pattern).toHaveValue('OAKS-[0-9]+');
      await expect(tester(page).label).toHaveValue('');
    } finally {
      await deleteProjectViaUi(page, name);
    }
  });
});
