import { expect, type Locator, type Page } from '@playwright/test';
import { navigate, submitAndWait } from './projects';

/** The fields of the project form (create, and the Source tab without `hostnames` and `typesense`, which only the create form has). Only the keys given are touched. */
export interface ProjectFields {
  name?: string;
  git?: string;
  branch?: string;
  hostnames?: string;
  mode?: 'Use the host default' | 'Do not set a git identity' | 'Custom identity';
  identityName?: string;
  identityEmail?: string;
  pattern?: string;
  submodule?: string;
  typesense?: boolean;
}

export const GIT_MODES = ['Use the host default', 'Do not set a git identity', 'Custom identity'] as const;

export const projectForm = (page: Page): Locator => page.getByTestId('project-form');

/**
 * Types a value; a value longer than the field's maxlength (the browser cuts typed text) is set directly, which is what
 * a script or a pasted request does and what the server rules must still refuse.
 */
async function put(field: Locator, value: string): Promise<void> {
  if (value.length <= 500) {
    await field.fill(value);
  }
  if (value.length > 500 || (await field.inputValue()) !== value) {
    await field.evaluate((node, text) => {
      (node as HTMLInputElement | HTMLTextAreaElement).value = text;
    }, value);
  }
}

/** Fills the form (create or edit). The name field only exists on the create form. */
export async function fillProject(page: Page, fields: ProjectFields): Promise<void> {
  if (fields.name !== undefined) await put(page.getByLabel('Name', { exact: true }), fields.name);
  if (fields.git !== undefined) await put(page.getByLabel('Git URL'), fields.git);
  if (fields.branch !== undefined) await put(page.getByLabel('Default branch'), fields.branch);
  if (fields.hostnames !== undefined) await put(page.getByLabel('Additional hostnames'), fields.hostnames);
  if (fields.mode !== undefined) {
    // The radio itself is visually hidden under its row: a person clicks the row.
    await page.locator('[data-slot=radio-row]').filter({ hasText: fields.mode }).click();
    await expect(page.getByLabel(fields.mode, { exact: true })).toBeChecked();
  }
  if (fields.identityName !== undefined) await put(page.getByLabel('Identity name'), fields.identityName);
  if (fields.identityEmail !== undefined) await put(page.getByLabel('Identity email'), fields.identityEmail);
  if (fields.pattern !== undefined) await put(page.getByLabel('Issue id pattern'), fields.pattern);
  if (fields.submodule !== undefined) await put(page.getByLabel('Submodule whose branch is shown'), fields.submodule);
  if (fields.typesense !== undefined) {
    const box = page.getByLabel('Run a Typesense search server for each instance');
    if ((await box.isChecked()) !== fields.typesense) {
      await page.locator('[data-slot=checkbox-row]').filter({ hasText: 'Typesense' }).click();
    }
    await expect(box).toBeChecked({ checked: fields.typesense });
  }
}

/** The fields of the Network tab's own part of the form. */
export interface NetworkFields {
  hostnames?: string;
  typesense?: boolean;
}

/** Fills the hostnames and the Typesense toggle of the Network tab. */
export async function fillNetwork(page: Page, fields: NetworkFields): Promise<void> {
  if (fields.hostnames !== undefined) await put(page.getByLabel('Additional hostnames'), fields.hostnames);
  if (fields.typesense !== undefined) {
    const box = page.getByLabel('Run a Typesense search server for each instance');
    if ((await box.isChecked()) !== fields.typesense) {
      await page.locator('[data-slot=checkbox-row]').filter({ hasText: 'Typesense' }).click();
    }
    await expect(box).toBeChecked({ checked: fields.typesense });
  }
}

/** What the Network tab shows for the hostnames and the Typesense toggle. */
export async function readNetwork(page: Page): Promise<Required<NetworkFields>> {
  return {
    hostnames: await page.getByLabel('Additional hostnames').inputValue(),
    typesense: await page.getByLabel('Run a Typesense search server for each instance').isChecked(),
  };
}

/** Clicks Save on the Network tab and waits for the response (a redirect or the confirmation / error page). */
export function submitNetwork(page: Page): Promise<void> {
  return submitAndWait(page, page.getByRole('button', { name: 'Save network settings' }));
}

/** Saves the Network tab and, when the confirmation dialog comes up, confirms it. Ends on the saved page. */
export async function saveNetworkConfirmed(page: Page): Promise<void> {
  await submitNetwork(page);
  const confirm = page.getByTestId('network-confirm-save');
  if (await confirm.isVisible()) {
    await submitAndWait(page, confirm);
  }
}

/** Opens the New project form. */
export async function openNewProject(page: Page): Promise<void> {
  await page.goto('/admin/projects/new');
  await expect(page.getByRole('heading', { level: 1, name: 'New project' })).toBeVisible();
}

/** Submits the create form and waits for the response to be on screen. */
export function submitCreate(page: Page): Promise<void> {
  return submitAndWait(page, page.getByRole('button', { name: 'Create project' }));
}

/** Submits the General tab and waits for the response to be on screen. */
export function submitSave(page: Page): Promise<void> {
  return submitAndWait(page, page.getByRole('button', { name: 'Save', exact: true }));
}

/** The text of every input of the form, to compare "what was typed is kept" or "what was saved is shown". */
export async function readProject(page: Page): Promise<Required<Omit<ProjectFields, 'name' | 'mode' | 'hostnames' | 'typesense'>> & { mode: string }> {
  const checked = await Promise.all(
    ['Use the host default', 'Do not set a git identity', 'Custom identity'].map(async (label) => ((await page.getByLabel(label, { exact: true }).isChecked()) ? label : null)),
  );
  return {
    git: await page.getByLabel('Git URL').inputValue(),
    branch: await page.getByLabel('Default branch').inputValue(),
    mode: checked.find((label) => label !== null) ?? '',
    identityName: await page.getByLabel('Identity name').inputValue(),
    identityEmail: await page.getByLabel('Identity email').inputValue(),
    pattern: await page.getByLabel('Issue id pattern').inputValue(),
    submodule: await page.getByLabel('Submodule whose branch is shown').inputValue(),
  };
}

/** Follows a link, see {@link navigate}; re-exported so the specs import one place. */
export { navigate };
