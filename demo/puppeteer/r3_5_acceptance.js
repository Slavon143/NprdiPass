import fs from 'fs';
import path from 'path';
import { execFileSync, spawn } from 'child_process';
import puppeteer from 'puppeteer';

const ROOT = process.cwd();
const APP_URL = process.env.NORDIPASS_APP_URL || 'http://127.0.0.1:8767';
const CATEGORY_UUID = '00000000-0000-4000-8000-000000003501';
const PRODUCT_UUID = '00000000-0000-4000-8000-000000003502';
const ATTRIBUTE_UUID = '00000000-0000-4000-8000-000000003503';
const EVIDENCE_DIR = path.resolve(ROOT, 'docs/testing/evidence/r3_5_browser_acceptance');
const REPORT_PATH = path.resolve(ROOT, 'docs/testing/evidence/r3_5_browser_acceptance.json');
const ENV = {
  ...process.env,
  APP_ENV: 'acceptance',
  APP_URL,
  NORDIPASS_APP_URL: APP_URL,
  SESSION_SECURE_COOKIE: 'false',
};

fs.mkdirSync(EVIDENCE_DIR, { recursive: true });

function run(command, args) {
  const startedAt = new Date().toISOString();
  try {
    const output = execFileSync(command, args, { cwd: ROOT, env: ENV, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    return { command: [command, ...args].join(' '), exitCode: 0, startedAt, finishedAt: new Date().toISOString(), output: output.trim().slice(-2000) };
  } catch (error) {
    return { command: [command, ...args].join(' '), exitCode: error.status ?? 1, startedAt, finishedAt: new Date().toISOString(), error: String(error.stderr || error.message).slice(-2000) };
  }
}

function mustRun(report, command, args) {
  const result = run(command, args);
  report.commands.push(result);
  if (result.exitCode !== 0) throw new Error(`${result.command} failed: ${result.error}`);
  return result.output;
}

function browserExecutable() {
  const candidates = [
    process.env.PUPPETEER_EXECUTABLE_PATH,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  ].filter(Boolean);
  return candidates.find((candidate) => fs.existsSync(candidate));
}

async function waitForServer() {
  const deadline = Date.now() + 30000;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${APP_URL}/login`, { signal: AbortSignal.timeout(3000) });
      if (response.status < 500) return response.status;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 400));
  }
  throw new Error('Acceptance server did not become ready.');
}

async function login(page) {
  await page.goto(`${APP_URL}/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.type('input[name="email"]', 'owner@nordipass.local');
  await page.type('input[name="password"]', 'password');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
    page.$eval('form[action$="/login"]', (form) => form.requestSubmit()),
  ]);
  if (page.url().includes('/login')) throw new Error('Owner authentication failed.');
}

async function inspectPage(page, report, name, url, viewport) {
  await page.setViewport(viewport);
  const consoleErrors = [];
  const serverErrors = [];
  const onConsole = (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text().slice(0, 500));
  };
  const onResponse = (response) => {
    if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
  };
  page.on('console', onConsole);
  page.on('response', onResponse);
  const response = await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
  const metrics = await page.evaluate(() => ({
    title: document.title,
    text: document.body.innerText,
    overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  }));
  const screenshot = path.resolve(EVIDENCE_DIR, `${viewport.width}-${name}.png`);
  await page.screenshot({ path: screenshot, fullPage: true });
  page.off('console', onConsole);
  page.off('response', onResponse);
  const passed = response?.status() === 200 && !metrics.overflow && consoleErrors.length === 0 && serverErrors.length === 0;
  report.pages.push({ name, viewport, url, status: response?.status(), overflow: metrics.overflow, consoleErrors, serverErrors, screenshot, result: passed ? 'passed' : 'failed' });
  return metrics;
}

function check(report, name, passed, actual) {
  report.workflow.push({ name, result: passed ? 'passed' : 'failed', actual });
}

async function main() {
  const report = { startedAt: new Date().toISOString(), appUrl: APP_URL, status: 'failed', commands: [], pages: [], workflow: [], limitations: [] };
  let server;
  let browser;
  try {
    const php = mustRun(report, 'php', ['-r', 'echo PHP_BINARY;']);
    mustRun(report, php, ['artisan', 'migrate:fresh', '--env=acceptance', '--force']);
    mustRun(report, php, ['artisan', 'db:seed', '--class=R35TaxonomyGovernanceAcceptanceSeeder', '--env=acceptance']);
    mustRun(report, php, ['artisan', 'optimize:clear', '--env=acceptance']);

    const stdout = fs.openSync(path.resolve(ROOT, 'storage/framework/r3_5_acceptance.out'), 'a');
    const stderr = fs.openSync(path.resolve(ROOT, 'storage/framework/r3_5_acceptance.err'), 'a');
    server = spawn(php, ['-S', '127.0.0.1:8767', '-t', 'public', 'demo/puppeteer/acceptance-router.php'], {
      cwd: ROOT,
      env: ENV,
      shell: false,
      windowsHide: true,
      stdio: ['ignore', stdout, stderr],
    });
    fs.closeSync(stdout);
    fs.closeSync(stderr);
    report.server = { pid: server.pid, loginStatus: await waitForServer() };

    const executablePath = browserExecutable();
    browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox', '--disable-setuid-sandbox'], ...(executablePath ? { executablePath } : {}) });
    const page = await browser.newPage();
    await login(page);

    const categoryUrl = `${APP_URL}/settings/catalog/categories/${CATEGORY_UUID}/template`;
    const productUrl = `${APP_URL}/catalog/products/${PRODUCT_UUID}`;
    const attributeUrl = `${APP_URL}/catalog/attributes/${ATTRIBUTE_UUID}`;
    for (const viewport of [{ width: 375, height: 667 }, { width: 1280, height: 800 }]) {
      const category = await inspectPage(page, report, 'category-template', categoryUrl, viewport);
      check(report, `Category template content ${viewport.width}`, category.text.includes('Apparel governance standard') && category.text.includes('Active template'), category.text.slice(0, 500));
      const product = await inspectPage(page, report, 'product-needs-review', productUrl, viewport);
      check(report, `Product Needs Review ${viewport.width}`, product.text.includes('Category taxonomy') && product.text.toLowerCase().includes('needs review') && product.text.includes('Primary material'), product.text.slice(0, 500));
      const attribute = await inspectPage(page, report, 'attribute-governance', attributeUrl, viewport);
      check(report, `Controlled vocabulary governance ${viewport.width}`, attribute.text.includes('Controlled vocabulary') && attribute.text.includes('Pending') && attribute.text.includes('Approve'), attribute.text.slice(0, 500));
    }

    await page.goto(categoryUrl, { waitUntil: 'networkidle2' });
    const draftForm = await page.$('form[action$="/templates"]');
    if (draftForm) {
      await page.$eval('input[name="name"]', (input) => { input.value = 'Apparel governance standard v2'; });
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), draftForm.evaluate((form) => form.requestSubmit())]);
    }
    const categoryText = await page.evaluate(() => document.body.innerText);
    check(report, 'Create next draft from active version', categoryText.includes('Draft v2') && categoryText.includes('Primary material'), categoryText.slice(0, 700));

    await page.goto(attributeUrl, { waitUntil: 'networkidle2' });
    const approveForm = await page.$('form[action$="/approve"]');
    if (approveForm) await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), approveForm.evaluate((form) => form.requestSubmit())]);
    const approvedText = await page.evaluate(() => document.body.innerText);
    check(report, 'Approve controlled vocabulary option', !approvedText.includes('Pending') && approvedText.includes('Approved'), approvedText.slice(0, 700));

    await page.goto(`${APP_URL}/settings/catalog/categories/00000000-0000-4000-8000-999999999999/template`, { waitUntil: 'domcontentloaded' });
    check(report, 'Unknown taxonomy resource is not disclosed', (await page.evaluate(() => document.body.innerText)).includes('404'), `url=${page.url()}`);

    report.status = report.pages.every((item) => item.result === 'passed') && report.workflow.every((item) => item.result === 'passed') ? 'passed' : 'failed';
  } catch (error) {
    report.error = String(error.stack || error.message || error);
  } finally {
    report.finishedAt = new Date().toISOString();
    if (browser) await browser.close();
    if (server?.pid) {
      server.kill();
      run('taskkill.exe', ['/PID', String(server.pid), '/T', '/F']);
    }
    fs.writeFileSync(REPORT_PATH, JSON.stringify(report, null, 2));
    console.log(JSON.stringify({ status: report.status, report: REPORT_PATH, pages: report.pages.length, workflow: report.workflow.length }, null, 2));
  }
  if (report.status !== 'passed') process.exitCode = 1;
}

main();
