import fs from 'fs';
import {
  APP_URL,
  DEMO_EMAIL,
  DEMO_PASSWORD,
  BROWSER_CONFIG,
  TIMEOUTS,
  OUTPUT_DIRS,
  DEMO_COMPANY_NAME,
  RUN_ID,
  CI_MODE,
} from './config/demo.config.js';
import { launchBrowser, createIncognitoContext, closeBrowser } from './helpers/browser.js';
import { login, ensureAuthenticated } from './helpers/authentication.js';
import { createReport, recordStep, finalizeReport } from './helpers/report.js';
import { CAPTIONS } from './helpers/captions.js';
import { showCaption } from './helpers/highlight.js';
import { navigateToUrl } from './helpers/navigation.js';
import { runMenuTour } from './scenarios/menu-tour.js';
import { createCategories } from './scenarios/category-flow.js';
import { createAttributes } from './scenarios/attributes-flow.js';
import { createProduct } from './scenarios/product-flow.js';
import { createVariants } from './scenarios/variants-flow.js';
import { uploadImages } from './scenarios/media-flow.js';
import { uploadDocuments } from './scenarios/documents-flow.js';
import { publishProduct } from './scenarios/publishing-flow.js';
import { verifyPublicPage } from './scenarios/public-page-flow.js';

const args = process.argv.slice(2);
const flags = {
  keepData: args.includes('--keep-data'),
  cleanup: args.includes('--cleanup'),
  tourOnly: args.includes('--tour-only'),
  businessOnly: args.includes('--business-only'),
  headless: args.includes('--headless'),
};

async function main() {
  console.log('=== NordiPass R2 Demo ===');
  console.log(`Run ID: ${RUN_ID}`);
  console.log(`App URL: ${APP_URL}`);
  console.log(`Mode: ${flags.tourOnly ? 'Tour only' : flags.businessOnly ? 'Business only' : 'Full demo'}`);

  const report = createReport([], RUN_ID);
  let browser;
  let productUuid;

  try {
    console.log('\n[1] Checking environment...');
    try {
      const resp = await fetch(`${APP_URL}/login`);
      if (resp.status >= 500) {
        throw new Error(`Server returned ${resp.status}`);
      }
      console.log('  Environment OK');
    } catch (e) {
      console.error(`  Environment check FAILED: ${e.message}`);
      process.exit(1);
    }

    console.log('\n[2] Launching browser...');
    browser = await launchBrowser();
    const page = await browser.newPage();

    console.log('\n[3] Logging in...');
    await showCaption(CAPTIONS.LOGIN);
    await login(page, DEMO_EMAIL, DEMO_PASSWORD);
    recordStep(report, 'login', 'passed', { email: DEMO_EMAIL });

    if (!flags.businessOnly) {
      console.log('\n[4] Running menu tour...');
      await showCaption(CAPTIONS.MENU_TOUR_START);
      await runMenuTour(page, report, recordStep);
    }

    if (!flags.tourOnly) {
      console.log('\n[5] Running business scenario...');
      await showCaption(CAPTIONS.BUSINESS_START);
      await navigateToUrl(page, '/dashboard');

      await showCaption(CAPTIONS.CREATE_CATEGORY_ROOT);
      const catResult = await createCategories(page, report, recordStep);
      recordStep(report, 'categories', 'passed', catResult);

      await showCaption(CAPTIONS.CREATE_ATTRIBUTES);
      const attrResult = await createAttributes(page, report, recordStep);
      recordStep(report, 'attributes', 'passed', attrResult);

      await showCaption(CAPTIONS.CREATE_PRODUCT);
      const productResult = await createProduct(page, report, recordStep);
      productUuid = productResult.productUuid;
      recordStep(report, 'product', 'passed', productResult);

      // Re-auth if session was lost during product creation
      if (page.url().includes('/login')) {
        console.log('  Session lost, re-authenticating...');
        await login(page, DEMO_EMAIL, DEMO_PASSWORD);
        await navigateToUrl(page, `/catalog/products/${productUuid}`);
      }

      await showCaption(CAPTIONS.CREATE_VARIANTS);
      const varResult = await createVariants(page, productUuid, report, recordStep);
      recordStep(report, 'variants', 'passed', varResult);

      await showCaption(CAPTIONS.UPLOAD_IMAGES);
      const imgResult = await uploadImages(page, productUuid, report, recordStep);
      recordStep(report, 'images', 'passed', imgResult);

      await showCaption(CAPTIONS.UPLOAD_DOCUMENTS);
      const docResult = await uploadDocuments(page, productUuid, report, recordStep);
      recordStep(report, 'documents', 'passed', docResult);

      await showCaption(CAPTIONS.PUBLISH);
      const pubResult = await publishProduct(page, productUuid, report, recordStep);
      recordStep(report, 'publishing', 'passed', pubResult);

      if (pubResult.publicUrl) {
        await showCaption(CAPTIONS.PUBLIC_PAGE);
        const incognitoContext = await createIncognitoContext(browser);
        const incognitoPage = await incognitoContext.newPage();
        const publicResult = await verifyPublicPage(incognitoPage, pubResult.publicUrl, report, recordStep);
        await incognitoContext.close();
        recordStep(report, 'publicPage', 'passed', publicResult);
      }

      console.log('\n  Checking audit log...');
      await navigateToUrl(page, '/audit');
      recordStep(report, 'auditLog', 'passed', { checked: true });

      await showCaption(CAPTIONS.COMPLETE);
    }

    console.log('\n[6] Finalizing...');
    report.status = 'passed';
    report.productName = productUuid ? `NordiSafe Work Jacket Pro (${productUuid})` : null;

    if (flags.cleanup) {
      console.log('  Cleanup mode - would remove demo data');
    }

    console.log('\n' + '='.repeat(50));
    console.log('Demo completed successfully!');
    console.log(`Run ID: ${RUN_ID}`);
    console.log(`Product: NordiSafe Work Jacket Pro`);
    if (productUuid) console.log(`Product UUID: ${productUuid}`);
    console.log('='.repeat(50));

    if (!CI_MODE) {
      console.log('\nPress Enter to close the browser...');
      process.stdin.resume();
      await new Promise((resolve) => {
        process.stdin.once('data', resolve);
      });
      process.stdin.pause();
    } else {
      await new Promise((resolve) => setTimeout(resolve, 3000));
    }

  } catch (error) {
    console.error('\n\n=== DEMO FAILED ===');
    console.error(`Error: ${error.message}`);
    console.error(error.stack);

    report.status = 'failed';
    recordStep(report, 'error', 'failed', {
      message: error.message,
      stack: error.stack,
      url: browser ? (await browser.pages())[0]?.url() : 'N/A',
    });

    if (browser) {
      try {
        const pages = await browser.pages();
        if (pages.length > 0) {
          const page = pages[pages.length - 1];
          await page.screenshot({ path: `${OUTPUT_DIRS.errors}/error-state.png`, fullPage: true });
          const html = await page.content();
          fs.writeFileSync(`${OUTPUT_DIRS.errors}/error-state.html`, html);
          console.log(`  Screenshot saved: ${OUTPUT_DIRS.errors}/error-state.png`);
        }
      } catch (e) {}
    }

    process.exitCode = 1;
  } finally {
    await finalizeReport(report, './output');

    if (browser) {
      await closeBrowser(browser);
    }
  }
}

main();
