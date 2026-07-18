import { navigateToUrl, waitForPageReady } from '../helpers/navigation.js';
import { showCaption } from '../helpers/highlight.js';
import { fillField, submitForm } from '../helpers/form-utils.js';
import { RUN_ID } from '../config/demo.config.js';
import productData from '../fixtures/product.json' with { type: 'json' };

export async function createProduct(page, report, recordStep) {
  try {
    showCaption('Creating product: NordiSafe Work Jacket Pro');
    await navigateToUrl(page, '/catalog/products/create');
    await waitForPageReady(page);
    await fillField(page, '#name', productData.name);
    await fillField(page, '#short_description', productData.shortDescription);

    const desc = await page.$('#description');
    if (desc) await fillField(page, '#description', productData.description);
    const brand = await page.$('#brand');
    if (brand) await fillField(page, '#brand', productData.brand);
    const mfr = await page.$('#manufacturer');
    if (mfr) await fillField(page, '#manufacturer', 'NordiSafe AB');

    const cat = await page.$('#primary_category_uuid');
    if (cat) {
      const opts = await page.$$eval('#primary_category_uuid option', (o) =>
        o.map((x) => ({ value: x.value, text: x.textContent.trim() }))
      );
      const p = opts.find((x) => ['protective', 'clothing'].some((w) => x.text.toLowerCase().includes(w)));
      if (p && p.value) await page.select('#primary_category_uuid', p.value);
    }

    await submitForm(page);

    const url = page.url();
    console.log(`  Product URL: ${url}`);
    const m = url.match(/\/products\/([^/?#]+)/);
    const uuid = m ? m[1] : null;
    if (!uuid) throw new Error(`No UUID in URL: ${url}`);

    recordStep(report, 'Create product', 'passed', { createdItem: 'product', productName: productData.name, productUuid: uuid });
    return { productUuid: uuid, productName: productData.name };
  } catch (error) {
    console.error(`Product failed: ${error.message}`);
    recordStep(report, 'Create product', 'failed', { error: error.message });
    throw error;
  }
}
