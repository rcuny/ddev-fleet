import { expect, type Locator, type Page } from '@playwright/test';
import { follow, submitAndWait } from './projects';

export type TemplateFields = { name?: string; env?: string; postDeploy?: string; tty1?: string; tty2?: string };

/** Fills the template form (only the given fields; the Name field exists on the create form only). */
export async function fillTemplate(page: Page, fields: TemplateFields): Promise<void> {
  // The inputs carry maxlength, which the browser enforces while typing and in fill(); the specs test the server's
  // rules, so the attribute is lifted first (templates-validation.spec.ts asserts the attribute separately).
  await page.locator('[data-testid="template-form"] [maxlength]').evaluateAll((nodes) => nodes.forEach((node) => node.removeAttribute('maxlength')));
  if (fields.name !== undefined) {
    await page.getByLabel('Name', { exact: true }).fill(fields.name);
  }
  if (fields.env !== undefined) {
    await page.getByLabel('Drupal environment').fill(fields.env);
  }
  if (fields.postDeploy !== undefined) {
    await page.getByLabel('Post-deploy commands').fill(fields.postDeploy);
  }
  if (fields.tty1 !== undefined) {
    await page.getByLabel('Terminal 1 commands').fill(fields.tty1);
  }
  if (fields.tty2 !== undefined) {
    await page.getByLabel('Terminal 2 commands').fill(fields.tty2);
  }
}

/** Opens the New template form of a project from the list and submits it; the caller asserts the outcome. */
export async function submitNewTemplate(page: Page, project: string, fields: TemplateFields): Promise<void> {
  await page.goto(`/admin/projects/${project}/templates/new`);
  await fillTemplate(page, fields);
  await submitAndWait(page, page.getByRole('button', { name: 'Create template' }));
}

/** Creates a template through the browser and checks the confirmation; leaves the page on the template list. */
export async function createTemplateViaUi(page: Page, project: string, fields: TemplateFields & { name: string }): Promise<void> {
  await submitNewTemplate(page, project, fields);
  await expect(page.getByTestId('flashes')).toContainText('Template created.');
  await expect(page).toHaveURL(new RegExp(`/admin/projects/${project}/templates$`));
}

export const templateRow = (page: Page, name: string): Locator =>
  page.getByTestId('template-row').filter({ has: page.getByTestId('template-name').getByText(name, { exact: true }) });

export async function editTemplate(page: Page, project: string, name: string): Promise<void> {
  await follow(page, templateRow(page, name).getByRole('link', { name: `Edit ${name}` }));
}
