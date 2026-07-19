import fs from 'fs';
import path from 'path';
import { navigateToUrl, waitForPageReady } from '../helpers/navigation.js';
import { showCaption } from '../helpers/highlight.js';
import { fillField, submitForm } from '../helpers/form-utils.js';
import { TIMEOUTS } from '../config/demo.config.js';
import productData from '../fixtures/product.json' with { type: 'json' };

const MIN_PDF = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n4 0 obj\n<< /Length 44 >>\nstream\nBT /F1 24 Tf 100 700 Td (Demo) Tj ET\nendstream\nendobj\n5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\nxref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000266 00000 n \n0000000360 00000 n \ntrailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n426\n%%EOF', 'utf-8');

export async function uploadDocuments(page, productUuid, report, recordStep) {
  const uploaded = [];

  for (const doc of productData.documents) {
    try {
      showCaption(`Uploading document: ${doc.title}`);
      await navigateToUrl(page, `/catalog/products/${productUuid}/documents/create`);
      await waitForPageReady(page);

      await fillField(page, '#title', doc.title);

      const ts = await page.$('#document_type');
      if (ts) {
        try { await page.select('#document_type', doc.type); } catch { const o = await page.$$eval('#document_type option', (x) => x.map((y) => y.value)); if (o.length > 1) await page.select('#document_type', o[1]); }
      }

      const ls = await page.$('#language');
      if (ls) { try { await page.select('#language', doc.language); } catch {} }

      const vs = await page.$('#visibility');
      if (vs) { try { await page.select('#visibility', doc.visibility); } catch { const o = await page.$$eval('#visibility option', (x) => x.map((y) => y.value)); const p = o.find((y) => y.includes('public')); if (p) await page.select('#visibility', p); } }

      if (doc.issuerName) await fillField(page, '#issuer_name', doc.issuerName);
      if (doc.issueDate) await fillField(page, '#issue_date', doc.issueDate);
      if (doc.expiresAt) await fillField(page, '#expires_at', doc.expiresAt);

      let fp = path.resolve('./demo/puppeteer/fixtures/documents', doc.file);
      if (!fs.existsSync(fp)) { fp = path.resolve('./demo/puppeteer/output/downloads', doc.file); fs.mkdirSync(path.dirname(fp), { recursive: true }); fs.writeFileSync(fp, MIN_PDF); }

      const inp = await page.waitForSelector('input#file, input[type="file"]', { visible: true, timeout: TIMEOUTS.default });
      await inp.uploadFile(fp);
      await submitForm(page);

      const documentUuid = page.url().match(/\/documents\/([0-9a-f-]{36})(?:$|[/?#])/i)?.[1];
      if (!documentUuid) {
        throw new Error(`Document UUID was not present in the post-upload URL: ${page.url()}`);
      }

      uploaded.push({ title: doc.title, type: doc.type, uuid: documentUuid });
      recordStep(report, `Upload document: ${doc.title}`, 'passed', {
        createdItem: 'document',
        documentTitle: doc.title,
        documentUuid,
      });
    } catch (error) {
      console.error(`Document "${doc.title}" failed: ${error.message}`);
      recordStep(report, `Upload document: ${doc.title}`, 'failed', { error: error.message });
    }
  }
  return { count: uploaded.length, documents: uploaded };
}
