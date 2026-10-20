import { expect, test, type Page } from '@playwright/test';
import { expectNoBlockingViolations } from './support/axe';
import { fillProject, openNewProject, projectForm, readProject, submitCreate, submitSave, type ProjectFields } from './support/projectForm';
import { createProjectViaUi, deleteProjectViaUi, GIT_URL, uniqueName } from './support/projects';
import { collectBrowserErrors, hasHorizontalOverflow } from './support/shell';

// Every validation message the project services return, field by field, through the New project form (and the
// General tab for the edit-only behaviour). Each case is its own test with a name of its own; a refused form creates
// nothing, and a project that a failing case did create is removed in afterEach.

let created: string | null = null;

test.afterEach(async ({ page }) => {
  if (created !== null) {
    const name = created;
    created = null;
    await deleteProjectViaUi(page, name);
  }
});

/** Fills a valid project with `fields` replacing the defaults, submits, and expects a 422 page that names `messages`. */
async function expectRefused(page: Page, fields: ProjectFields, messages: string[]): Promise<string> {
  const name = fields.name ?? uniqueName();
  created = name;
  await openNewProject(page);
  await fillProject(page, { name, git: GIT_URL, ...fields });
  await submitCreate(page);
  await expect(page).toHaveURL(/\/admin\/projects\/new$/);
  for (const message of messages) {
    await expect(projectForm(page)).toContainText(message);
  }
  await expect(page.getByTestId('flashes')).toHaveCount(0);
  created = null;
  return name;
}

const ONLY_SCHEMES = 'Only https://, ssh:// and scp-like (git@host:path) URLs are accepted.';

test.describe('name', () => {
  const cases: Array<[string, string, string]> = [
    ['empty', '', 'Enter a name.'],
    ['uppercase and a space', 'Bad Name', 'Use lowercase letters, digits and single dashes only'],
    ['starts with a dash', '-abc', 'Use lowercase letters, digits and single dashes only'],
    ['ends with a dash', 'abc-', 'Use lowercase letters, digits and single dashes only'],
    ['an underscore', 'a_b', 'Use lowercase letters, digits and single dashes only'],
    ['a dot', 'a.b', 'Use lowercase letters, digits and single dashes only'],
    ['two dashes in a row', 'ab--cd', 'The name must not contain two dashes in a row'],
    ['reserved word', 'new', 'This name is reserved.'],
    ['41 characters', 'a'.repeat(41), 'The name must be at most 40 characters.'],
  ];
  for (const [title, value, message] of cases) {
    test(`${title} is refused`, async ({ page }) => {
      await expectRefused(page, { name: value }, [message]);
      // What was typed stays in the field.
      await expect(page.getByLabel('Name', { exact: true })).toHaveValue(value);
    });
  }

  test('40 characters, digits and single dashes are accepted', async ({ page }) => {
    const name = `${uniqueName('a1-b2').slice(0, 20)}-`.padEnd(40, '9').slice(0, 40);
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: GIT_URL });
    await submitCreate(page);
    await expect(page.getByTestId('flashes')).toContainText('Project created.');
    await expect(page).toHaveURL(new RegExp(`/admin/projects/${name}$`));
  });

  test('a name that exists is refused, and the first project is untouched', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await createProjectViaUi(page, name);
    await openNewProject(page);
    await fillProject(page, { name, git: 'https://git.example.com/other/repo.git' });
    await submitCreate(page);
    await expect(projectForm(page)).toContainText('A project with this name already exists.');
    await page.goto(`/admin/projects/${name}`);
    await expect(page.getByLabel('Git URL')).toHaveValue(GIT_URL);
  });

  test('the name is not editable on an existing project', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await createProjectViaUi(page, name);
    await expect(page.getByLabel('Name', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
  });
});

test.describe('git URL', () => {
  const long = `https://git.example.com/${'a'.repeat(2030)}`;
  const cases: Array<[string, string, string]> = [
    ['empty', '', 'Enter the repository URL.'],
    ['longer than 2048 characters', long, 'The URL must be at most 2048 characters.'],
    ['a space inside', 'https://git.example.com/a b.git', 'The URL must be plain ASCII without spaces or control characters.'],
    ['non-ASCII', 'https://git.example.com/café.git', 'The URL must be plain ASCII without spaces or control characters.'],
    ['starts with a dash', '--upload-pack=x', 'The URL must not start with a dash.'],
    ['http', 'http://git.example.com/a.git', ONLY_SCHEMES],
    ['ftp', 'ftp://git.example.com/a.git', ONLY_SCHEMES],
    ['file', 'file:///srv/repo.git', ONLY_SCHEMES],
    ['git://', 'git://git.example.com/a.git', ONLY_SCHEMES],
    ['no scheme and no colon', 'git.example.com/acme/blog.git', ONLY_SCHEMES],
    ['a mistyped scheme', 'https:/git.example.com/a.git', ONLY_SCHEMES],
    ['credentials in https', 'https://user:token@git.example.com/a.git', 'Do not put credentials in the URL: use the deploy key.'],
    ['a port above 65535', 'https://git.example.com:70000/a.git', 'The port must be a number between 1 and 65535.'],
    ['port 0', 'https://git.example.com:0/a.git', 'The port must be a number between 1 and 65535.'],
    ['a port that is not a number', 'https://git.example.com:abc/a.git', 'The port must be a number between 1 and 65535.'],
    ['a host starting with a dash', 'https://-bad.example.com/a.git', 'The host name is not valid.'],
    ['an underscore in the host', 'https://bad_host.example.com/a.git', 'The host name is not valid.'],
    ['a numeric top level that is not an address', 'https://git.example.1234/a.git', 'The host name is not valid.'],
    ['an address with a part above 255', 'https://10.0.0.256/a.git', 'The host name is not valid.'],
    ['an empty host', 'https:///a.git', 'The host name is not valid.'],
    ['no repository path', 'https://git.example.com', 'The repository path is missing or contains characters that are not allowed.'],
    ['only a slash as path', 'https://git.example.com/', 'The repository path is missing or contains characters that are not allowed.'],
    ['a query string', 'https://git.example.com/a.git?x=1', 'The repository path is missing or contains characters that are not allowed.'],
    ['a fragment', 'https://git.example.com/a.git#x', 'The repository path is missing or contains characters that are not allowed.'],
    ['a backslash', 'https://git.example.com/a\\b.git', 'The repository path is missing or contains characters that are not allowed.'],
    ['a bad percent escape', 'https://git.example.com/a%zz', 'The repository path contains an invalid percent escape.'],
    ['an encoded control character', 'https://git.example.com/a%0Ab', 'The repository path contains characters that are not allowed.'],
    ['an encoded question mark', 'https://git.example.com/a%3Fb', 'The repository path contains characters that are not allowed.'],
    ['a dot-dot segment', 'https://git.example.com/a/../b.git', 'The repository path must not contain "..".'],
    ['an encoded dot-dot segment', 'https://git.example.com/a/%2e%2e/b.git', 'The repository path must not contain "..".'],
    ['ssh with a password', 'ssh://user:pw@git.example.com/a.git', 'The ssh user is not valid (no password in the URL; use the deploy key).'],
    ['ssh with two @', 'ssh://a@b@git.example.com/a.git', 'The URL is not valid.'],
    ['ssh with a bad port', 'ssh://git@git.example.com:99999/a.git', 'The port must be a number between 1 and 65535.'],
    ['ssh without a path', 'ssh://git@git.example.com', 'The repository path is missing or contains characters that are not allowed.'],
    ['scp with a bad user', 'us$er@git.example.com:acme/a.git', 'The ssh user is not valid.'],
    ['scp with two @', 'a@b@git.example.com:acme/a.git', 'The URL is not valid.'],
    ['scp with a bad host', 'git@-bad:acme/a.git', 'The host name is not valid.'],
    ['scp with a path that starts with a dash', 'git@git.example.com:-x/a.git', 'The repository path must not start with a dash.'],
    ['scp with an empty path', 'git@git.example.com:', 'The repository path is missing or contains characters that are not allowed.'],
  ];
  for (const [title, value, message] of cases) {
    test(`${title} is refused`, async ({ page }) => {
      await expectRefused(page, { git: value }, [message]);
      await expect(page.getByLabel('Git URL')).toHaveValue(value);
    });
  }

  const valid: Array<[string, string]> = [
    ['https', 'https://git.example.com/acme/blog.git'],
    ['https with a port and a nested path', 'https://git.example.com:8443/group/sub/blog'],
    ['https with an address', 'https://192.0.2.10/acme/blog.git'],
    ['ssh with a user and a port', 'ssh://git@git.example.com:2222/acme/blog.git'],
    ['ssh without a user', 'ssh://git.example.com/acme/blog.git'],
    ['scp-like', 'git@git.example.com:acme/blog.git'],
    ['scp-like without a user', 'git.example.com:acme/blog.git'],
    ['a percent-encoded path', 'https://git.example.com/%7Euser/blog.git'],
  ];
  for (const [title, value] of valid) {
    test(`${title} is accepted and shown on the edit page`, async ({ page }) => {
      const name = uniqueName();
      created = name;
      await openNewProject(page);
      await fillProject(page, { name, git: value });
      await submitCreate(page);
      await expect(page.getByTestId('flashes')).toContainText('Project created.');
      await expect(page.getByLabel('Git URL')).toHaveValue(value);
    });
  }

  test('surrounding whitespace is trimmed', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: `  ${GIT_URL}\n` });
    await submitCreate(page);
    await expect(page.getByTestId('flashes')).toContainText('Project created.');
    await expect(page.getByLabel('Git URL')).toHaveValue(GIT_URL);
  });
});

test.describe('default branch', () => {
  const cases: Array<[string, string, string]> = [
    ['longer than 200 characters', 'a'.repeat(201), 'The branch name must be at most 200 characters.'],
    ['starts with a dash', '-main', 'A branch name uses letters, digits, dot, underscore, dash and slash, and starts with a letter or digit.'],
    ['starts with a slash', '/main', 'A branch name uses letters, digits, dot, underscore, dash and slash, and starts with a letter or digit.'],
    ['a space', 'my branch', 'A branch name uses letters, digits, dot, underscore, dash and slash, and starts with a letter or digit.'],
    ['a colon', 'a:b', 'A branch name uses letters, digits, dot, underscore, dash and slash, and starts with a letter or digit.'],
    ['two dots', 'a..b', 'This is not a valid git branch name (no "..", "//", "/.", trailing "/" or ".", or ".lock" ending).'],
    ['a double slash', 'a//b', 'This is not a valid git branch name'],
    ['a segment starting with a dot', 'a/.b', 'This is not a valid git branch name'],
    ['a trailing slash', 'a/', 'This is not a valid git branch name'],
    ['a trailing dot', 'a.', 'This is not a valid git branch name'],
    ['a .lock ending', 'a.lock', 'This is not a valid git branch name'],
    ['a .lock segment', 'a.lock/b', 'This is not a valid git branch name'],
    ['HEAD', 'HEAD', 'This is not a branch name: it is the name git gives the current checkout, or a full reference path.'],
    ['a full reference path', 'refs/heads/main', 'This is not a branch name: it is the name git gives the current checkout, or a full reference path.'],
  ];
  for (const [title, value, message] of cases) {
    test(`${title} is refused`, async ({ page }) => {
      await expectRefused(page, { branch: value }, [message]);
      await expect(page.getByLabel('Default branch')).toHaveValue(value);
    });
  }

  test('a blank branch is optional, a normal one is kept, a blank one on edit clears it', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: GIT_URL, branch: 'release/1.2_x-y' });
    await submitCreate(page);
    await expect(page.getByLabel('Default branch')).toHaveValue('release/1.2_x-y');
    await page.getByLabel('Default branch').fill('   ');
    await submitSave(page);
    await expect(page.getByTestId('flashes')).toContainText('Project saved.');
    await expect(page.getByLabel('Default branch')).toHaveValue('');
  });
});

test.describe('additional hostnames', () => {
  test('a label with a dot or a capital letter is refused with its line number', async ({ page }) => {
    await expectRefused(page, { hostnames: 'www\nbad.label' }, ['Hostname 2: A hostname label uses lowercase letters, digits and single dashes only (no dots).']);
    await expect(page.getByLabel('Additional hostnames')).toHaveValue('www\nbad.label');
    await expectRefused(page, { hostnames: 'Upper' }, ['Hostname 1: A hostname label uses lowercase letters, digits and single dashes only']);
    await expectRefused(page, { hostnames: 'a--b' }, ['Hostname 1: A hostname label uses lowercase letters, digits and single dashes only']);
  });

  test('a label too long for the project name is refused', async ({ page }) => {
    await expectRefused(page, { hostnames: 'x'.repeat(60) }, ['Hostname 1: This hostname label is too long for this project name']);
  });

  test('a repeated label is refused', async ({ page }) => {
    await expectRefused(page, { hostnames: 'www\nshop\nwww' }, ['Each hostname can be listed once.']);
  });

  test('more than fifty labels are refused, fifty are accepted', async ({ page }) => {
    const tooMany = Array.from({ length: 51 }, (_, i) => `h${i}`).join('\n');
    await expectRefused(page, { hostnames: tooMany }, ['At most 50 additional hostnames are allowed.']);
    const name = uniqueName();
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: GIT_URL, hostnames: tooMany.split('\n').slice(0, 50).join('\n') });
    await submitCreate(page);
    await expect(page.getByTestId('flashes')).toContainText('Project created.');
    await expect(page.getByLabel('Additional hostnames')).toHaveValue(tooMany.split('\n').slice(0, 50).join('\n'));
  });

  test('a text longer than 10000 characters is refused', async ({ page }) => {
    await expectRefused(page, { hostnames: 'a\n'.repeat(5001) }, ['The text must be at most 10000 characters.']);
  });

  test('blank lines and padding are dropped, labels are kept in order', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: GIT_URL, hostnames: '\n  www  \n\n\tshop\n' });
    await submitCreate(page);
    await expect(page.getByLabel('Additional hostnames')).toHaveValue('www\nshop');
    await page.getByLabel('Additional hostnames').fill('');
    await submitSave(page);
    await expect(page.getByTestId('flashes')).toContainText('Project saved.');
    await expect(page.getByLabel('Additional hostnames')).toHaveValue('');
  });
});

test.describe('git identity', () => {
  test('the three modes are offered, the host default is preselected on a new project', async ({ page }) => {
    await openNewProject(page);
    await expect(page.locator('[data-slot=radio-row]')).toHaveCount(3);
    await expect(page.getByLabel('Use the host default', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Identity name')).toHaveValue('');
    await expect(page.getByLabel('Identity email')).toHaveValue('');
  });

  test('a custom identity with nothing entered is refused', async ({ page }) => {
    await expectRefused(page, { mode: 'Custom identity' }, ['Enter a name, an email or both, or choose another git identity mode.']);
    await expect(page.getByLabel('Custom identity')).toBeChecked();
  });

  const nameCases: Array<[string, string, string]> = [
    ['longer than 100 characters', 'n'.repeat(101), 'The name must be at most 100 characters.'],
    ['an angle bracket', 'Bot <x>', 'The name must not contain control characters, "<" or ">".'],
    ['only spaces and no email', '   ', 'Enter a name, an email or both, or choose another git identity mode.'],
  ];
  for (const [title, value, message] of nameCases) {
    test(`identity name ${title} is refused`, async ({ page }) => {
      await expectRefused(page, { mode: 'Custom identity', identityName: value }, [message]);
    });
  }

  const emailCases: Array<[string, string]> = [
    ['without an at sign', 'bot.example.com'],
    ['without a domain dot', 'bot@localhost'],
    ['with a space', 'bot @example.com'],
    ['with two at signs', 'a@b@example.com'],
    ['with a one-letter top level', 'bot@example.c'],
  ];
  for (const [title, value] of emailCases) {
    test(`identity email ${title} is refused`, async ({ page }) => {
      await expectRefused(page, { mode: 'Custom identity', identityEmail: value }, ['Enter a valid email address.']);
      await expect(page.getByLabel('Identity email')).toHaveValue(value);
    });
  }

  test('name only, email only and both are each accepted and re-read', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: GIT_URL, mode: 'Custom identity', identityName: 'Acme Bot' });
    await submitCreate(page);
    await expect(page.getByTestId('flashes')).toContainText('Project created.');
    expect(await readProject(page)).toMatchObject({ mode: 'Custom identity', identityName: 'Acme Bot', identityEmail: '' });

    await fillProject(page, { identityName: '', identityEmail: 'bot@example.com' });
    await submitSave(page);
    await expect(page.getByTestId('flashes')).toContainText('Project saved.');
    await page.goto(`/admin/projects/${name}`);
    expect(await readProject(page)).toMatchObject({ mode: 'Custom identity', identityName: '', identityEmail: 'bot@example.com' });

    await fillProject(page, { identityName: 'Zoë Bot', identityEmail: 'zoe+bot@git.example.com' });
    await submitSave(page);
    await page.goto(`/admin/projects/${name}`);
    expect(await readProject(page)).toMatchObject({ identityName: 'Zoë Bot', identityEmail: 'zoe+bot@git.example.com' });
  });

  test('leaving Custom identity forgets the name and email, and junk typed under another mode is ignored', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: GIT_URL, mode: 'Custom identity', identityName: 'Acme Bot', identityEmail: 'bot@example.com' });
    await submitCreate(page);
    await expect(page.getByTestId('flashes')).toContainText('Project created.');

    await fillProject(page, { mode: 'Do not set a git identity' });
    await submitSave(page);
    await page.goto(`/admin/projects/${name}`);
    expect(await readProject(page)).toMatchObject({ mode: 'Do not set a git identity', identityName: '', identityEmail: '' });

    await fillProject(page, { mode: 'Use the host default', identityEmail: 'not an email', identityName: '<<' });
    await submitSave(page);
    await expect(page.getByTestId('flashes')).toContainText('Project saved.');
    await page.goto(`/admin/projects/${name}`);
    expect(await readProject(page)).toMatchObject({ mode: 'Use the host default', identityName: '', identityEmail: '' });
  });
});

test.describe('issue id pattern', () => {
  const cases: Array<[string, string, string]> = [
    ['longer than 200 characters', `A${'b'.repeat(200)}`, 'The pattern must be at most 200 characters.'],
    ['a control character', 'AB\u0001C', 'The pattern must not contain control characters.'],
    ['a tilde', 'AB~C', 'The pattern must not contain "~".'],
    ['an unclosed group', '(ABC', 'invalid pattern:'],
    ['an unclosed class', '[ABC', 'invalid pattern:'],
    ['a quantifier with nothing to repeat', '*ABC', 'invalid pattern:'],
    ['literal quoting', '\\QABC\\E', 'literal quoting (\\Q...\\E) is not allowed in the pattern'],
    ['a numbered backreference', '(AB)\\1', 'backreferences are not allowed in the pattern'],
    ['a named backreference', '(?<n>AB)\\k<n>', 'backreferences are not allowed in the pattern'],
    ['recursion', '(a)(?1)', 'the pattern uses syntax that is not allowed (recursion, callouts, conditionals, comments, control verbs)'],
    ['a comment group', 'AB(?#note)', 'the pattern uses syntax that is not allowed'],
    ['a callout', 'AB(?C1)', 'the pattern uses syntax that is not allowed'],
    ['a nested quantifier', '(a+)+', 'nested quantifier: a repeated group must not contain a quantifier or an alternation'],
    ['a repeated alternation', '(a|aa)*', 'nested quantifier: a repeated group must not contain a quantifier or an alternation'],
    ['a counted group with a quantifier inside', '(\\d+){2,}', 'nested quantifier'],
    ['a pattern that backtracks too much', '\\w*\\w*\\w*\\w*\\d', 'pattern is too expensive'],
  ];
  for (const [title, value, message] of cases) {
    test(`${title} is refused`, async ({ page }) => {
      await expectRefused(page, { pattern: value }, [message]);
      await expect(page.getByLabel('Issue id pattern')).toHaveValue(value);
    });
  }

  test('a refused pattern on the edit page is not saved', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await createProjectViaUi(page, name);
    await fillProject(page, { pattern: '(a+)+' });
    await submitSave(page);
    await expect(projectForm(page)).toContainText('nested quantifier');
    await expect(page.getByTestId('flashes')).toHaveCount(0);
    await page.goto(`/admin/projects/${name}`);
    await expect(page.getByLabel('Issue id pattern')).toHaveValue('');
  });

  test('valid patterns are saved and shown again, a blank one clears the pattern', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: GIT_URL, pattern: '(?:ABC|XYZ)-\\d{1,6}' });
    await submitCreate(page);
    await expect(page.getByTestId('flashes')).toContainText('Project created.');
    await expect(page.getByLabel('Issue id pattern')).toHaveValue('(?:ABC|XYZ)-\\d{1,6}');
    // The tester starts from the saved pattern.
    await expect(page.getByTestId('issue-id-preview').getByLabel('Pattern')).toHaveValue('(?:ABC|XYZ)-\\d{1,6}');
    await fillProject(page, { pattern: '' });
    await submitSave(page);
    await page.goto(`/admin/projects/${name}`);
    await expect(page.getByLabel('Issue id pattern')).toHaveValue('');
  });
});

test.describe('submodule whose branch is shown', () => {
  const message = 'Use a relative path such as web/themes/custom: segments of letters, digits, dot, underscore and dash, each starting with a letter or digit.';
  const cases: Array<[string, string, string]> = [
    ['longer than 200 characters', 'a'.repeat(201), 'The path must be at most 200 characters.'],
    ['absolute', '/web/themes', message],
    ['with a trailing slash', 'web/themes/', message],
    ['with a double slash', 'web//themes', message],
    ['with a dot-dot', 'web/../x', message],
    ['starting with a dot', '.hidden/x', message],
    ['with a space', 'web/my theme', message],
    ['with a backslash', 'web\\themes', message],
  ];
  for (const [title, value, text] of cases) {
    test(`${title} is refused`, async ({ page }) => {
      await expectRefused(page, { submodule: value }, [text]);
      await expect(page.getByLabel('Submodule whose branch is shown')).toHaveValue(value);
    });
  }

  test('a nested path is saved, re-read and cleared again', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: GIT_URL, submodule: 'web/modules/custom/blog_2.x' });
    await submitCreate(page);
    await expect(page.getByLabel('Submodule whose branch is shown')).toHaveValue('web/modules/custom/blog_2.x');
    await fillProject(page, { submodule: '' });
    await submitSave(page);
    await page.goto(`/admin/projects/${name}`);
    await expect(page.getByLabel('Submodule whose branch is shown')).toHaveValue('');
  });
});

test.describe('required and optional fields', () => {
  test('an empty form shows the two required-field messages together and creates nothing', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    await openNewProject(page);
    await submitCreate(page);
    await expect(page).toHaveURL(/\/admin\/projects\/new$/);
    await expect(projectForm(page)).toContainText('Enter a name.');
    await expect(projectForm(page)).toContainText('Enter the repository URL.');
    await expect(projectForm(page)).not.toContainText('Enter a branch name.');
    await expectNoBlockingViolations(page, 'the refused create form');
    expect(errors.filter((error) => !error.includes('status of 422'))).toEqual([]);
  });

  test('every optional field can be left empty: only name and Git URL are needed', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: GIT_URL });
    await submitCreate(page);
    await expect(page.getByTestId('flashes')).toContainText('Project created.');
    expect(await readProject(page)).toEqual({
      git: GIT_URL,
      branch: '',
      hostnames: '',
      mode: 'Use the host default',
      identityName: '',
      identityEmail: '',
      pattern: '',
      submodule: '',
      typesense: false,
    });
  });

  test('several errors are shown at once, each next to its own field, and every typed value stays', async ({ page }) => {
    const fields = { name: 'Bad Name', git: 'ftp://x', branch: 'a..b', hostnames: 'a.b', pattern: '(a+)+', submodule: '/x' };
    await expectRefused(page, fields, [
      'Use lowercase letters, digits and single dashes only',
      ONLY_SCHEMES,
      'This is not a valid git branch name',
      'Hostname 1: A hostname label',
      'nested quantifier',
      'Use a relative path such as web/themes/custom',
    ]);
    await expect(page.getByLabel('Name', { exact: true })).toHaveValue(fields.name);
    await expect(page.getByLabel('Git URL')).toHaveValue(fields.git);
    await expect(page.getByLabel('Default branch')).toHaveValue(fields.branch);
    await expect(page.getByLabel('Additional hostnames')).toHaveValue(fields.hostnames);
    await expect(page.getByLabel('Issue id pattern')).toHaveValue(fields.pattern);
    await expect(page.getByLabel('Submodule whose branch is shown')).toHaveValue(fields.submodule);
  });
});

test.describe('refused edit', () => {
  test('a refused Save keeps what was typed, shows no success flash and saves nothing', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await openNewProject(page);
    await fillProject(page, { name, git: GIT_URL, branch: 'main', hostnames: 'www' });
    await submitCreate(page);
    await expect(page.getByTestId('flashes')).toContainText('Project created.');

    await fillProject(page, { git: 'http://x', branch: 'dev', hostnames: 'bad.label' });
    await submitSave(page);
    await expect(projectForm(page)).toContainText(ONLY_SCHEMES);
    await expect(projectForm(page)).toContainText('Hostname 1:');
    await expect(page.getByTestId('flashes')).toHaveCount(0);
    await expect(page.getByLabel('Git URL')).toHaveValue('http://x');
    await expect(page.getByLabel('Default branch')).toHaveValue('dev');
    await expectNoBlockingViolations(page, 'the refused edit form');

    // Nothing was saved, not even the valid field.
    await page.goto(`/admin/projects/${name}`);
    expect(await readProject(page)).toMatchObject({ git: GIT_URL, branch: 'main', hostnames: 'www' });
  });

  test('a project without templates says so next to the default template', async ({ page }) => {
    const name = uniqueName();
    created = name;
    await createProjectViaUi(page, name);
    await expect(page.getByLabel('Default template')).toHaveValue('');
    await expect(page.getByText('Add a template first, then choose which one is the default.')).toBeVisible();
  });
});

test.describe('long values', () => {
  test('long refused values and messages do not widen the page', async ({ page }) => {
    const fields = { name: 'n'.repeat(41), git: `https://git.example.com/${'a'.repeat(2030)}`, branch: 'b'.repeat(201), hostnames: 'h'.repeat(70), pattern: `A${'b'.repeat(200)}`, submodule: 's'.repeat(201) };
    await expectRefused(page, fields, ['The name must be at most 40 characters.', 'The URL must be at most 2048 characters.', 'The branch name must be at most 200 characters.', 'The pattern must be at most 200 characters.', 'The path must be at most 200 characters.']);
    expect(await hasHorizontalOverflow(page), 'overflow on the refused form with long values').toBe(false);
  });

  test('a 40-character name and a 200-character URL fit the list, the edit page and the flashes', async ({ page }) => {
    const name = `pw-${Date.now().toString(36)}-`.padEnd(40, 'p');
    const git = `https://git.example.com/acme/${'r'.repeat(200 - 'https://git.example.com/acme/'.length - 4)}.git`;
    created = name;
    await createProjectViaUi(page, name, git);
    expect(await hasHorizontalOverflow(page), 'overflow after create').toBe(false);
    await page.goto(`/admin/projects/${name}`);
    expect(await hasHorizontalOverflow(page), 'overflow on the edit page').toBe(false);
    await page.goto('/admin/projects');
    await expect(page.getByTestId('project-row').filter({ hasText: name })).toContainText(git);
    expect(await hasHorizontalOverflow(page), 'overflow on the list').toBe(false);
  });
});
