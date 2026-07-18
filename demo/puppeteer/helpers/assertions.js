import { TIMEOUTS } from '../config/demo.config.js';

export async function assertElementVisible(page, selector, description) {
  try {
    const element = await page.waitForSelector(selector, {
      visible: true,
      timeout: TIMEOUTS.default,
    });
    return element;
  } catch {
    throw new Error(`Expected element "${description}" (${selector}) to be visible, but it was not found`);
  }
}

export async function assertPageUrl(page, expected) {
  const url = page.url();
  if (!url.includes(expected)) {
    throw new Error(`Expected URL to contain "${expected}", but got "${url}"`);
  }
}

export async function assertNoError(page) {
  const errorElements = await page.$$(
    '.error, .alert-danger, [class*="error"], [class*="alert-danger"]'
  );
  if (errorElements.length > 0) {
    const messages = [];
    for (const el of errorElements) {
      const text = await page.evaluate((node) => node.textContent.trim(), el);
      if (text) messages.push(text);
    }
    throw new Error(`Error indicators found on page: ${messages.join('; ')}`);
  }
  return true;
}

export async function assertPageTitle(page, expected) {
  const h1 = await page.$('h1');
  if (!h1) {
    throw new Error(`Expected page title "${expected}" but no h1 found`);
  }
  const text = await page.evaluate((el) => el.textContent.trim(), h1);
  if (!text.includes(expected)) {
    throw new Error(`Expected h1 to contain "${expected}", but got "${text}"`);
  }
  return true;
}
