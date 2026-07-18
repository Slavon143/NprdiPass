import { navigateToUrl, waitForPageReady } from '../helpers/navigation.js';
import { showCaption } from '../helpers/highlight.js';
import { fillField, submitForm } from '../helpers/form-utils.js';
import { RUN_ID } from '../config/demo.config.js';
import categoryData from '../fixtures/category.json' with { type: 'json' };

export async function createCategories(page, report, recordStep) {
  const rootName = categoryData.workwear.name;
  const rootSlug = `workwear-demo-${RUN_ID}`.toLowerCase();
  const rootDesc = categoryData.workwear.description;
  const childName = categoryData.protectiveClothing.name;
  const childSlug = `protective-clothing-demo-${RUN_ID}`.toLowerCase();
  const childDesc = categoryData.protectiveClothing.description;

  try {
    showCaption('Creating root category: Workwear');
    await navigateToUrl(page, '/settings/catalog/categories/create');
    await waitForPageReady(page);
    await fillField(page, '#name', rootName);
    await fillField(page, '#slug', rootSlug);
    await fillField(page, '#description', rootDesc);
    await submitForm(page);

    recordStep(report, 'Create root category "Workwear"', 'passed', {
      createdItem: 'category', categoryName: rootName, categorySlug: rootSlug,
    });
  } catch (error) {
    console.error(`Root category failed: ${error.message}`);
    recordStep(report, 'Create root category "Workwear"', 'failed', { error: error.message });
    throw error;
  }

  try {
    showCaption('Creating child category: Protective Clothing');
    await navigateToUrl(page, '/settings/catalog/categories/create');
    await waitForPageReady(page);
    await fillField(page, '#name', childName);
    await fillField(page, '#slug', childSlug);
    await fillField(page, '#description', childDesc);

    const options = await page.$$eval('#parent_uuid option', (opts) =>
      opts.map((o) => ({ value: o.value, text: o.textContent.trim() }))
    );
    const wo = options.find((o) => o.text.toLowerCase().includes('workwear') && o.value !== '');
    if (wo) await page.select('#parent_uuid', wo.value);

    await submitForm(page);

    recordStep(report, 'Create child category "Protective Clothing"', 'passed', {
      createdItem: 'category', categoryName: childName, categorySlug: childSlug,
    });
  } catch (error) {
    console.error(`Child category failed: ${error.message}`);
    recordStep(report, 'Create child category "Protective Clothing"', 'failed', { error: error.message });
    throw error;
  }

  return { rootCategory: { name: rootName, slug: rootSlug }, childCategory: { name: childName, slug: childSlug } };
}
