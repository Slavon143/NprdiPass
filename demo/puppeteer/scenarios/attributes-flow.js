import { navigateToUrl, waitForPageReady } from '../helpers/navigation.js';
import { showCaption } from '../helpers/highlight.js';
import { fillField, submitForm } from '../helpers/form-utils.js';
import { RUN_ID } from '../config/demo.config.js';
import attributesData from '../fixtures/attributes.json' with { type: 'json' };

export async function createAttributes(page, report, recordStep) {
  const created = [];
  const suffix = RUN_ID.toLowerCase().replace(/-/g, '_');

  for (const [key, attr] of Object.entries(attributesData)) {
    const uniqueCode = `${attr.code}_${suffix}`;
    try {
      showCaption(`Creating attribute: ${attr.name}`);
      await navigateToUrl(page, '/catalog/attributes/create');
      await waitForPageReady(page);
      await fillField(page, '#name', attr.name);
      await fillField(page, '#code', uniqueCode);

      const typeSel = await page.$('#type');
      if (typeSel) {
        const m = { text: 'text', select: 'select', integer: 'integer', boolean: 'boolean' };
        await page.select('#type', m[attr.type] || attr.type);
      }

      await submitForm(page);
      created.push({ name: attr.name, code: uniqueCode });
      recordStep(report, `Create attribute "${attr.name}"`, 'passed', {
        createdItem: 'attribute', attributeName: attr.name, attributeCode: uniqueCode,
      });
    } catch (error) {
      console.error(`Attribute "${attr.name}" failed: ${error.message}`);
      recordStep(report, `Create attribute "${attr.name}"`, 'failed', { error: error.message });
    }
  }
  return { count: created.length, attributes: created };
}
