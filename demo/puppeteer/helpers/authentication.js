import { APP_URL, DEMO_EMAIL, DEMO_PASSWORD, DEMO_COMPANY_NAME, TIMEOUTS } from '../config/demo.config.js';
import { formFields, common } from '../config/selectors.js';
import { highlightElement } from './highlight.js';

export async function login(page, email, password) {
  const loginEmail = email || DEMO_EMAIL;
  const loginPassword = password || DEMO_PASSWORD;

  await page.goto(`${APP_URL}/login`, {
    waitUntil: 'networkidle2',
    timeout: TIMEOUTS.navigation,
  });

  await page.waitForSelector(
    `${common.loginForm}, ${formFields.email}`,
    { visible: true, timeout: TIMEOUTS.default }
  );

  await page.type(formFields.email, loginEmail, { delay: 20 });
  await page.type(formFields.password, loginPassword, { delay: 20 });

  const submitButton = await page.waitForSelector(
    'button[type="submit"]',
    { visible: true, timeout: TIMEOUTS.default }
  );
  await highlightElement(page, 'button[type="submit"]');

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: TIMEOUTS.navigation }),
    submitButton.click(),
  ]);

  await new Promise((resolve) => setTimeout(resolve, TIMEOUTS.waitAfterAction || 1500));

  const currentUrl = page.url();
  if (currentUrl.includes('/login')) {
    throw new Error('Login failed — still on login page');
  }

  try {
    const companyLinks = await page.$$('a[href*="company"], a[href*="select"]');
    for (const link of companyLinks) {
      const text = await page.evaluate((el) => el.textContent.trim(), link);
      if (text.toLowerCase().includes(DEMO_COMPANY_NAME.toLowerCase())) {
        await link.click();
        await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: TIMEOUTS.navigation });
        break;
      }
    }
  } catch {
  }

  return true;
}

export async function ensureAuthenticated(page) {
  const currentUrl = page.url();

  if (currentUrl.includes('/login')) {
    return await login(page);
  }

  try {
    const companyLinks = await page.$$('a[href*="company"], a[href*="select"]');
    for (const link of companyLinks) {
      const text = await page.evaluate((el) => el.textContent.trim(), link);
      if (text.toLowerCase().includes(DEMO_COMPANY_NAME.toLowerCase())) {
        await link.click();
        await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: TIMEOUTS.navigation });
        break;
      }
    }
  } catch {
  }

  return true;
}

