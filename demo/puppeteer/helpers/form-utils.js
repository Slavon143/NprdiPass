import { TIMEOUTS } from '../config/demo.config.js';

export async function fillField(page, selector, value) {
  await page.waitForSelector(selector, { visible: true, timeout: TIMEOUTS.default });
  await page.$eval(selector, (el, val) => { el.value = val; el.dispatchEvent(new Event('input', { bubbles: true })); }, value);
}

export async function submitForm(page) {
  await new Promise((r) => setTimeout(r, 200));

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: TIMEOUTS.navigation }).catch(() => {}),
    page.evaluate(() => {
      const forms = document.querySelectorAll('form');
      for (const form of forms) {
        const btn = form.querySelector('button[type="submit"]');
        if (btn && btn.offsetParent !== null) {
          btn.scrollIntoView({ block: 'center' });
          btn.click();
          return;
        }
      }
      throw new Error('No visible submit button found');
    }),
  ]);

  await new Promise((r) => setTimeout(r, 500));
}
