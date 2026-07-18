import { APP_URL, DEMO_EMAIL, DEMO_PASSWORD, DEMO_COMPANY_NAME, TIMEOUTS } from '../config/demo.config.js';
import menuItems from '../config/menu.config.js';
import { highlightElement, showCaption } from './highlight.js';

async function reLogin(page) {
  console.log('  Session lost, re-authenticating...');
  await page.goto(`${APP_URL}/login`, { waitUntil: 'domcontentloaded', timeout: TIMEOUTS.navigation });
  await page.waitForSelector('#email', { visible: true, timeout: 10000 });
  await page.type('#email', DEMO_EMAIL, { delay: 20 });
  await page.type('#password', DEMO_PASSWORD, { delay: 20 });

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: TIMEOUTS.navigation }).catch(() => {}),
    page.evaluate(() => {
      const forms = document.querySelectorAll('form');
      for (const form of forms) {
        const btn = form.querySelector('button[type="submit"]');
        if (btn && btn.offsetParent !== null) { btn.click(); return; }
      }
    }),
  ]);

  await new Promise((r) => setTimeout(r, 500));

  const currentUrl = page.url();
  if (currentUrl.includes('/login')) {
    throw new Error('Re-authentication failed');
  }
}

export async function navigateTo(page, menuItem) {
  const selector = `[data-testid="${menuItem.selector}"]`;

  await highlightElement(page, selector);
  await showCaption(menuItem.caption);

  const link = await page.waitForSelector(selector, {
    visible: true,
    timeout: TIMEOUTS.default,
  });

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: TIMEOUTS.navigation }).catch(() => {}),
    link.click(),
  ]);

  if (menuItem.pageSelector) {
    try {
      await page.waitForSelector(`[data-testid="${menuItem.pageSelector}"]`, {
        visible: true,
        timeout: TIMEOUTS.default,
      });
    } catch {}
  }

  return true;
}

export async function navigateToUrl(page, url) {
  const fullUrl = url.startsWith('http') ? url : `${APP_URL}${url}`;
  await page.goto(fullUrl, {
    waitUntil: 'domcontentloaded',
    timeout: TIMEOUTS.navigation,
  });

  if (page.url().includes('/login') && !url.includes('/login')) {
    await reLogin(page);
    await page.goto(fullUrl, {
      waitUntil: 'domcontentloaded',
      timeout: TIMEOUTS.navigation,
    });
  }
  return page;
}

export async function scrollPageSlowly(page) {
  await page.evaluate(async () => {
    const totalHeight = document.body.scrollHeight;
    let current = 0;
    while (current < totalHeight) {
      current += 300;
      window.scrollTo(0, current);
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
  });
}

export async function scrollToTop(page) {
  await page.evaluate(() => window.scrollTo(0, 0));
}

export async function waitForPageReady(page) {
  try {
    await page.waitForNetworkIdle({ timeout: 5000 });
  } catch {}

  await new Promise((resolve) => setTimeout(resolve, 250));
}
