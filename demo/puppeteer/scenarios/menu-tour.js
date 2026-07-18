import menuItems from '../config/menu.config.js';
import { APP_URL, TIMEOUTS } from '../config/demo.config.js';
import { navigateTo, scrollPageSlowly, scrollToTop, waitForPageReady, navigateToUrl } from '../helpers/navigation.js';
import { showCaption } from '../helpers/highlight.js';
import { assertPageTitle, assertNoError } from '../helpers/assertions.js';
import { CAPTIONS } from '../helpers/captions.js';

/**
 * @param {Page} page - Authenticated Puppeteer page
 * @param {object} report - Report object from createReport()
 * @param {function} recordStep - Function to record step results
 * @returns {Promise<void>}
 */
export async function runMenuTour(page, report, recordStep) {
  showCaption(CAPTIONS.MENU_TOUR_START);

  await navigateToUrl(page, '/dashboard');
  await waitForPageReady(page);

  for (const menuItem of menuItems) {
    const stepName = `Menu: ${menuItem.name}`;

    try {
      await navigateTo(page, menuItem);
      await waitForPageReady(page);

      try {
        await assertNoError(page);
      } catch (softErr) {
        console.warn(`  ⚠ Soft error on "${menuItem.name}": ${softErr.message}`);
      }

      await scrollPageSlowly(page);
      await scrollToTop(page);

      recordStep(report, stepName, 'passed');
      console.log(`  ✓ ${menuItem.name}`);
    } catch (err) {
      console.error(`  ✗ "${menuItem.name}" navigation failed: ${err.message}`);
      recordStep(report, stepName, 'failed', { error: err.message });
    }

    await new Promise((resolve) => setTimeout(resolve, TIMEOUTS.waitAfterAction));
  }

  await navigateToUrl(page, '/dashboard');
  await waitForPageReady(page);

  showCaption(CAPTIONS.MENU_TOUR_DONE);
  recordStep(report, 'Menu Tour', 'passed');
}

