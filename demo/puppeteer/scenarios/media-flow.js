import fs from 'fs';
import path from 'path';
import { navigateToUrl, waitForPageReady } from '../helpers/navigation.js';
import { showCaption } from '../helpers/highlight.js';
import { fillField, submitForm } from '../helpers/form-utils.js';
import { generatePlaceholderImage } from '../helpers/uploads.js';
import { TIMEOUTS } from '../config/demo.config.js';
import productData from '../fixtures/product.json' with { type: 'json' };

export async function uploadImages(page, productUuid, report, recordStep) {
  const uploaded = [];

  for (let i = 0; i < productData.images.length; i++) {
    const f = productData.images[i];
    try {
      showCaption(`Uploading image ${i + 1}/${productData.images.length}: ${f}`);
      await navigateToUrl(page, `/catalog/products/${productUuid}/media`);
      await waitForPageReady(page);

      let fp = path.resolve('./demo/puppeteer/fixtures/images', f);
      if (!fs.existsSync(fp)) {
        fp = path.resolve('./demo/puppeteer/output/downloads', f);
        await generatePlaceholderImage(fp, f.replace('.jpg', ''), '#3498db');
      }

      const inp = await page.waitForSelector('input[type="file"]', { visible: true, timeout: TIMEOUTS.default });
      await inp.uploadFile(fp);

      const alt = await page.$('#alt_text');
      if (alt) await fillField(page, '#alt_text', `NordiSafe — ${f.replace('.jpg', '')}`);

      await submitForm(page);
      uploaded.push({ filename: f });
      recordStep(report, `Upload image ${i + 1}/${productData.images.length}`, 'passed', { createdItem: 'image', filename: f });
    } catch (error) {
      console.error(`Image "${f}" failed: ${error.message}`);
      recordStep(report, `Upload image ${i + 1}/${productData.images.length}`, 'failed', { error: error.message });
    }
  }

  try {
    showCaption('Setting primary image');
    await navigateToUrl(page, `/catalog/products/${productUuid}/media`);
    await waitForPageReady(page);
    const cb = await page.$('input[name="make_primary"]');
    if (cb) await cb.click();
    recordStep(report, 'Set primary image', 'passed', { primaryImage: uploaded[0]?.filename });
  } catch (error) {
    console.warn(`Set primary failed: ${error.message}`);
    recordStep(report, 'Set primary image', 'failed', { error: error.message });
  }

  return { count: uploaded.length, primaryImage: uploaded[0]?.filename };
}
