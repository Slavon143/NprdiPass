import fs from 'fs';
import path from 'path';
import puppeteer from 'puppeteer';
import { login } from './helpers/authentication.js';

const APP_URL = process.env.NORDIPASS_APP_URL || 'http://127.0.0.1:8765';
const OUT_DIR = path.resolve('demo/puppeteer/output/r3_3_acceptance');
const PRODUCT_NAME = 'Industrial LED Work Lamp 40 W';

const viewports = [
  { name: 'mobile', width: 375, height: 667 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1280, height: 720 },
];

fs.mkdirSync(OUT_DIR, { recursive: true });

function safeName(value) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

async function goto(page, url) {
  const consoleEntries = [];
  const failedRequests = [];

  const onConsole = (msg) => {
    if (['warning', 'error'].includes(msg.type())) {
      consoleEntries.push({ type: msg.type(), text: msg.text() });
    }
  };
  const onRequestFailed = (request) => {
    failedRequests.push({
      url: request.url(),
      failure: request.failure()?.errorText || 'request failed',
    });
  };

  page.on('console', onConsole);
  page.on('requestfailed', onRequestFailed);

  const response = await page.goto(url, { waitUntil: 'networkidle2', timeout: 20000 });
  await page.waitForSelector('body', { timeout: 10000 });

  page.off('console', onConsole);
  page.off('requestfailed', onRequestFailed);

  return {
    status: response?.status() || null,
    consoleWarnings: consoleEntries.filter((entry) => entry.type === 'warning'),
    consoleErrors: consoleEntries.filter((entry) => entry.type === 'error'),
    failedRequests,
    overflow: await page.evaluate(() => ({
      horizontal: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    })),
    title: await page.title(),
    h1: await page.$eval('h1', (el) => el.innerText.trim()).catch(() => ''),
  };
}

async function discoverProduct(page) {
  await page.goto(`${APP_URL}/catalog/products`, { waitUntil: 'networkidle2' });

  return await page.evaluate((productName) => {
    const rows = Array.from(document.querySelectorAll('tr'));
    const row = rows.find((candidate) => candidate.innerText.includes(productName));
    if (!row) return null;

    const open = Array.from(row.querySelectorAll('a'))
      .find((link) => link.href.includes('/catalog/products/') && !link.href.includes('/edit'));

    const match = open?.href.match(/\/catalog\/products\/([^/?]+)/);
    return match?.[1] || null;
  }, PRODUCT_NAME);
}

async function collectRoutes(page, productUuid) {
  const editor = `${APP_URL}/catalog/products/${productUuid}/passport/edit`;
  const readiness = `${APP_URL}/catalog/products/${productUuid}/passport/readiness`;
  const preview = `${APP_URL}/catalog/products/${productUuid}/passport/preview`;
  const publishConfirm = `${APP_URL}/catalog/products/${productUuid}/passport/publish-confirm`;
  const versionsIndex = `${APP_URL}/catalog/products/${productUuid}/passport/versions`;

  await page.goto(editor, { waitUntil: 'networkidle2' });
  const publicUrl = await page.$$eval('a', (links) => {
    const found = links.find((link) => link.innerText.includes('Open Public Page'));
    return found?.href || null;
  });

  await page.goto(versionsIndex, { waitUntil: 'networkidle2' });
  const publishedVersion = await page.$$eval('a', (links) => {
    const found = links.find((link) => /\/passport\/versions\//.test(link.href));
    return found?.href || null;
  });

  return [
    { key: 'advanced-editor', label: 'Advanced DPP editor', url: editor, authenticated: true },
    { key: 'readiness', label: 'Readiness page', url: readiness, authenticated: true },
    { key: 'preview', label: 'Preview', url: preview, authenticated: true },
    { key: 'publish-confirmation', label: 'Publish confirmation', url: publishConfirm, authenticated: true },
    { key: 'published-version', label: 'Published Passport version', url: publishedVersion, authenticated: true },
    { key: 'public-passport', label: 'Public Passport', url: publicUrl, authenticated: false },
  ].filter((route) => route.url);
}

async function runAccessibilityChecks(page, productUuid) {
  const editorUrl = `${APP_URL}/catalog/products/${productUuid}/passport/edit`;
  await page.setViewport({ width: 1280, height: 720 });
  await page.goto(editorUrl, { waitUntil: 'networkidle2' });

  const labels = await page.$$eval('.section-input', (inputs) => inputs.map((input) => ({
    field: input.getAttribute('data-field') || input.getAttribute('data-material-field') || input.name || input.id,
    label: input.getAttribute('aria-label')
      || document.querySelector(`label[for="${input.id}"]`)?.textContent.trim()
      || input.closest('label')?.textContent.trim()
      || '',
  })));

  await page.keyboard.press('Tab');
  const tabOrder = [];
  for (let i = 0; i < 25; i += 1) {
    tabOrder.push(await page.evaluate(() => ({
      tag: document.activeElement?.tagName,
      text: document.activeElement?.innerText?.trim().slice(0, 80) || document.activeElement?.getAttribute('aria-label') || document.activeElement?.getAttribute('name') || '',
      visibleFocus: !!document.activeElement && document.activeElement.matches(':focus-visible, :focus'),
    })));
    await page.keyboard.press('Tab');
  }

  await page.click('.add-material-row');
  const addFocus = await page.evaluate(() => document.activeElement?.getAttribute('aria-label') || '');
  const removeLabel = await page.$eval('.material-row:last-child .remove-material-row', (button) => button.getAttribute('aria-label') || button.innerText.trim());
  await page.click('.material-row:last-child .remove-material-row');
  const removeFocus = await page.evaluate(() => document.activeElement?.getAttribute('aria-label') || document.activeElement?.innerText?.trim() || '');

  await page.focus('[data-section="materials_and_composition"] .material-row:first-child [data-material-field="percentage"]');
  await page.keyboard.down('Control');
  await page.keyboard.press('A');
  await page.keyboard.up('Control');
  await page.keyboard.type('101');
  await page.click('.save-section[data-section="materials_and_composition"]');
  await page.waitForSelector('[data-section="materials_and_composition"] .section-error-summary:not(.hidden)', { timeout: 10000 });

  const errorSummary = await page.evaluate(() => {
    const summary = document.querySelector('[data-section="materials_and_composition"] .section-error-summary');
    return {
      focused: summary === document.activeElement,
      text: summary?.innerText.trim() || '',
      links: Array.from(summary?.querySelectorAll('a') || []).map((link) => link.getAttribute('href')),
      invalidCount: document.querySelectorAll('[data-section="materials_and_composition"] [aria-invalid="true"]').length,
    };
  });

  return {
    labelsMissing: labels.filter((item) => !item.label),
    tabOrder,
    dynamicRows: {
      addFocus,
      removeLabel,
      removeFocus,
    },
    errorSummary,
  };
}

async function main() {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });

  const report = {
    startedAt: new Date().toISOString(),
    appUrl: APP_URL,
    productName: PRODUCT_NAME,
    productUuid: null,
    pages: [],
    accessibility: null,
    status: 'failed',
  };

  try {
    const authPage = await browser.newPage();
    await login(authPage, process.env.NORDIPASS_DEMO_EMAIL, process.env.NORDIPASS_DEMO_PASSWORD);

    const productUuid = await discoverProduct(authPage);
    if (!productUuid) {
      throw new Error(`Could not find ${PRODUCT_NAME}`);
    }
    report.productUuid = productUuid;

    const routes = await collectRoutes(authPage, productUuid);

    for (const viewport of viewports) {
      await authPage.setViewport(viewport);
      for (const route of routes) {
        const page = route.authenticated ? authPage : await browser.newPage();
        await page.setViewport(viewport);

        const result = await goto(page, route.url);
        const screenshot = path.join(OUT_DIR, `${viewport.name}-${route.key}.png`);
        await page.screenshot({ path: screenshot, fullPage: true });

        report.pages.push({
          page: route.label,
          viewport: `${viewport.width}x${viewport.height}`,
          url: route.url,
          expected: 'Page renders without critical console errors, failed requests, or horizontal overflow.',
          actual: result.status && result.status < 400 ? 'Rendered' : `HTTP ${result.status}`,
          consoleWarnings: result.consoleWarnings,
          consoleErrors: result.consoleErrors,
          failedRequests: result.failedRequests,
          horizontalOverflow: result.overflow.horizontal,
          overflowDetail: result.overflow,
          screenshot,
          h1: result.h1,
        });

        if (!route.authenticated) {
          await page.close();
        }
      }
    }

    report.accessibility = await runAccessibilityChecks(authPage, productUuid);
    report.status = report.pages.every((page) => page.actual === 'Rendered'
      && page.consoleErrors.length === 0
      && page.failedRequests.length === 0
      && page.horizontalOverflow === false)
      && report.accessibility.labelsMissing.length === 0
      && report.accessibility.errorSummary.focused
      ? 'passed'
      : 'failed';

    await authPage.close();
  } finally {
    report.finishedAt = new Date().toISOString();
    fs.writeFileSync(path.join(OUT_DIR, 'report.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
    await browser.close();
  }

  if (report.status !== 'passed') {
    process.exitCode = 1;
  }
}

main();
