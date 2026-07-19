import { waitForPageReady } from '../helpers/navigation.js';
import { showCaption } from '../helpers/highlight.js';
import { APP_URL, TIMEOUTS } from '../config/demo.config.js';

export async function verifyPublicPage(page, publicUrl, report, recordStep) {
  const contentFound = [];

  try {
    showCaption('Verifying public passport page');

    const targetUrl = publicUrl.startsWith('http') ? publicUrl : `${APP_URL}${publicUrl}`;
    await page.goto(targetUrl, {
      waitUntil: 'networkidle2',
      timeout: TIMEOUTS.navigation,
    });

    await waitForPageReady(page);

    const pageText = await page.evaluate(() => document.body.textContent || '');

    if (pageText.toLowerCase().includes('nordisafe')) {
      contentFound.push('name');
    }

    const hasImage = await page.$('img');
    if (hasImage) {
      contentFound.push('image');
    }

    const hasDescription = pageText.length > 100;
    if (hasDescription) {
      contentFound.push('description');
    }

    const manufacturerKeywords = ['nordisafe', 'sweden', 'manufacturer', 'made by', 'produced'];
    const hasManufacturer = manufacturerKeywords.some((kw) =>
      pageText.toLowerCase().includes(kw)
    );
    if (hasManufacturer) {
      contentFound.push('manufacturer');
    }

    const adminElements = await page.$$(
      'a[href*="edit"], a[href*="create"], button::-p-text("Edit"), button::-p-text("Delete"), ' +
        'a[href*="/catalog"], [data-testid*="navigation"], nav[aria-label], .admin-bar'
    );
    const noAdminElements = adminElements.length === 0;
    if (noAdminElements) {
      contentFound.push('no_admin_elements');
    }

    const hasDocuments = await page.$(
      'a[href*="/documents/"], a[href*="pdf"], .document-list, [class*="document"]'
    );
    if (hasDocuments) {
      contentFound.push('documents');
    }

    const requiresAuth =
      pageText.toLowerCase().includes('login') ||
      pageText.toLowerCase().includes('sign in') ||
      pageText.toLowerCase().includes('unauthorized') ||
      page.url().includes('/login');

    if (requiresAuth) {
      throw new Error('Public page requires authentication');
    }

    const requiredContent = ['name', 'image', 'description', 'manufacturer', 'no_admin_elements', 'documents'];
    const verified = requiredContent.every((item) => contentFound.includes(item));

    await recordStep(report, 'Verify public passport page', verified ? 'passed' : 'failed', {
      verified,
      contentFound,
      publicUrl,
      requiresAuth,
    });

    if (!verified) {
      throw new Error(`Public passport verification found only: ${contentFound.join(', ')}`);
    }

    return { verified, contentFound };
  } catch (error) {
    console.error(`Failed to verify public page: ${error.message}`);
    await recordStep(report, 'Verify public passport page', 'failed', {
      error: error.message,
      contentFound,
    });
    throw error;
  }
}


