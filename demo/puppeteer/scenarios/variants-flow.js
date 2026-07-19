import { navigateToUrl, waitForPageReady } from '../helpers/navigation.js';
import { showCaption } from '../helpers/highlight.js';
import { fillField, submitForm } from '../helpers/form-utils.js';
import { RUN_ID } from '../config/demo.config.js';
import productData from '../fixtures/product.json' with { type: 'json' };

export async function createVariants(page, productUuid, report, recordStep) {
  const created = [];

  for (const v of productData.variants) {
    try {
      showCaption(`Creating variant: ${v.size}`);
      await navigateToUrl(page, `/catalog/products/${productUuid}/variants/create`);
      await waitForPageReady(page);

      const url = page.url();
      console.log(`  Variant page: ${url} | UUID: ${productUuid}`);

      await fillField(page, '#name', v.name);

      const skuEl = await page.$('#sku');
      if (skuEl) await fillField(page, '#sku', `NS-WJ-DEMO-${v.size}-${RUN_ID}`);

      await submitForm(page);

      if (created.length === 0) {
        const setDefaultBtn = await page.$('button::-p-text("Set as default")');
        if (!setDefaultBtn) {
          throw new Error('The first created variant cannot be selected as the default variant');
        }

        await Promise.all([
          page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
          setDefaultBtn.click(),
        ]);
        await waitForPageReady(page);
      }

      created.push({ name: v.name, size: v.size });
      recordStep(report, `Create variant "${v.size}"`, 'passed', {
        createdItem: 'variant', variantName: v.name, variantSize: v.size,
      });
    } catch (error) {
      console.error(`Variant "${v.size}" failed: ${error.message}`);
      recordStep(report, `Create variant "${v.size}"`, 'failed', { error: error.message });
    }
  }
  return { count: created.length, variants: created };
}
