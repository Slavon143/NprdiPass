import fs from 'fs';
import path from 'path';
import { execFileSync, spawn } from 'child_process';
import puppeteer from 'puppeteer';

const PROJECT_ROOT = process.cwd();
const APP_URL = process.env.NORDIPASS_APP_URL || 'http://127.0.0.1:8766';
const PRODUCT_UUID = '00000000-0000-4000-8000-000000003401';
const PRODUCT_NAME = 'R3.4 Documents Compliance Acceptance';
const EVIDENCE_DIR = path.resolve(PROJECT_ROOT, 'docs/testing/evidence/r3_4_browser_acceptance');
const REPORT_PATH = path.resolve(PROJECT_ROOT, 'docs/testing/evidence/r3_4_browser_acceptance.json');
const FIXTURE_PDF = path.resolve(PROJECT_ROOT, 'demo/puppeteer/fixtures/documents/declaration-of-conformity.pdf');
const ACCEPTANCE_ENV = {
  ...process.env,
  APP_ENV: 'acceptance',
  APP_URL,
  NORDIPASS_APP_URL: APP_URL,
  SESSION_SECURE_COOKIE: 'false',
};

const viewports = [
  { name: 'mobile', width: 375, height: 667 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1280, height: 720 },
];

fs.mkdirSync(EVIDENCE_DIR, { recursive: true });

function progress(message) {
  console.error(`[r3.4 acceptance] ${message}`);
}

function command(commandName, args, options = {}) {
  const startedAt = new Date().toISOString();
  try {
    const output = execFileSync(commandName, args, {
      cwd: PROJECT_ROOT,
      env: ACCEPTANCE_ENV,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      ...options,
    });

    return { command: [commandName, ...args].join(' '), exitCode: 0, startedAt, finishedAt: new Date().toISOString(), output };
  } catch (error) {
    return {
      command: [commandName, ...args].join(' '),
      exitCode: error.status ?? 1,
      startedAt,
      finishedAt: new Date().toISOString(),
      output: String(error.stdout || ''),
      error: String(error.stderr || error.message || ''),
    };
  }
}

function mustRun(report, commandName, args) {
  const result = command(commandName, args);
  report.commands.push(redactCommandResult(result));
  if (result.exitCode !== 0) {
    throw new Error(`${result.command} failed: ${result.error || result.output}`.slice(0, 1000));
  }

  return result.output;
}

async function waitForHttpStatus(url, timeoutMs = 30000) {
  const deadline = Date.now() + timeoutMs;
  let lastError = null;

  while (Date.now() < deadline) {
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(5000) });
      return response.status;
    } catch (error) {
      lastError = error;
      await new Promise((resolve) => setTimeout(resolve, 500));
    }
  }

  throw new Error(`Timed out waiting for ${url}: ${lastError?.message || 'no response'}`);
}

async function startAcceptanceServer(report, phpBinary) {
  const pidPath = path.resolve(PROJECT_ROOT, 'storage/framework/r3_4_acceptance_server.pid');
  const stdout = path.resolve(PROJECT_ROOT, 'storage/framework/r3_4_acceptance_server.out');
  const stderr = path.resolve(PROJECT_ROOT, 'storage/framework/r3_4_acceptance_server.err');

  for (const file of [pidPath, stdout, stderr]) {
    fs.rmSync(file, { force: true });
  }

  mustRun(report, phpBinary, ['artisan', 'optimize:clear', '--env=acceptance']);
  mustRun(report, phpBinary, ['artisan', 'cache:clear', '--env=acceptance']);

  const outHandle = fs.openSync(stdout, 'a');
  const errHandle = fs.openSync(stderr, 'a');
  const child = spawn(phpBinary, [
    '-S',
    '127.0.0.1:8766',
    '-t',
    'public',
    'demo/puppeteer/acceptance-router.php',
  ], {
    cwd: PROJECT_ROOT,
    env: ACCEPTANCE_ENV,
    shell: false,
    stdio: ['ignore', outHandle, errHandle],
    windowsHide: true,
  });

  fs.closeSync(outHandle);
  fs.closeSync(errHandle);
  fs.writeFileSync(pidPath, String(child.pid), 'ascii');

  let rejectEarlyExit;
  const earlyExit = new Promise((_, reject) => {
    rejectEarlyExit = reject;
  });
  const onExit = (code, signal) => {
    rejectEarlyExit(new Error(`Acceptance server exited early: code=${code} signal=${signal}`));
  };
  const onError = (error) => {
    rejectEarlyExit(error);
  };
  child.once('exit', onExit);
  child.once('error', onError);

  const ready = (async () => {
    const readyStatus = await waitForHttpStatus(`${APP_URL}/ready`);
    const loginStatus = await waitForHttpStatus(`${APP_URL}/login`);
    if (readyStatus >= 500 || loginStatus >= 500) {
      throw new Error(`Acceptance server readiness failed: /ready=${readyStatus} /login=${loginStatus}`);
    }

    return {
      phpBinary,
      port: 8766,
      pid: child.pid,
      readyStatus,
      loginStatus,
      pidPath,
      stdout,
      stderr,
      process: child,
    };
  })();

  const result = await Promise.race([ready, earlyExit]);
  child.off('exit', onExit);
  child.off('error', onError);

  return result;
}

function redactCommandResult(result) {
  return {
    command: result.command,
    exitCode: result.exitCode,
    startedAt: result.startedAt,
    finishedAt: result.finishedAt,
    outputExcerpt: (result.output || '').split(/\r?\n/).slice(-20).join('\n'),
    errorExcerpt: (result.error || '').split(/\r?\n/).slice(-20).join('\n'),
  };
}

function safeName(value) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

async function login(page, email, password = 'password') {
  progress(`login start: ${email}`);
  await page.goto(`${APP_URL}/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForSelector('[data-testid="login-email-input"], input[name="email"]', { visible: true, timeout: 15000 });
  await page.type('[data-testid="login-email-input"], input[name="email"]', email);
  await page.type('[data-testid="login-password-input"], input[name="password"]', password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => null),
    page.$eval('[data-testid="login-form"], form[action$="/login"]', (form) => form.requestSubmit()),
  ]);
  await new Promise((resolve) => setTimeout(resolve, 1000));

  if (page.url().includes('/login')) {
    const body = await pageText(page).catch(() => '');
    throw new Error(`Login failed for ${email}; still on login page: ${body.slice(0, 500)}`);
  }

  progress(`login complete: ${email}`);
}

async function collectPage(page, report, label, url, viewport, expected = 'Page renders without HTTP 500, critical console errors, failed requests, or horizontal overflow.') {
  progress(`page check: ${viewport.name} ${label}`);
  const consoleEntries = [];
  const failedRequests = [];
  const responses = [];
  const onConsole = (message) => {
    if (['warning', 'error'].includes(message.type())) {
      consoleEntries.push({ type: message.type(), text: message.text().slice(0, 500) });
    }
  };
  const onRequestFailed = (request) => {
    failedRequests.push({ url: request.url(), failure: request.failure()?.errorText || 'request failed' });
  };
  const onResponse = (response) => {
    if (response.status() >= 400) {
      responses.push({ url: response.url(), status: response.status() });
    }
  };

  page.on('console', onConsole);
  page.on('requestfailed', onRequestFailed);
  page.on('response', onResponse);

  await page.setViewport({ width: viewport.width, height: viewport.height });
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForSelector('body', { timeout: 15000 });

  page.off('console', onConsole);
  page.off('requestfailed', onRequestFailed);
  page.off('response', onResponse);

  const overflow = await page.evaluate(() => ({
    horizontal: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  const h1 = await page.$eval('h1', (element) => element.textContent.trim()).catch(() => '');
  const screenshot = path.join(EVIDENCE_DIR, `${viewport.name}-${safeName(label)}.png`);
  await page.screenshot({ path: screenshot, fullPage: true });

  const status = response?.status() ?? null;
  const passed = status !== null
    && status < 500
    && consoleEntries.filter((entry) => entry.type === 'error').length === 0
    && failedRequests.length === 0
    && !overflow.horizontal;

  const entry = {
    step: label,
    page: label,
    url,
    viewport: `${viewport.width}x${viewport.height}`,
    expected,
    actual: status === null ? 'No HTTP response' : `HTTP ${status}; h1=${h1 || 'n/a'}`,
    httpResult: status,
    consoleWarnings: consoleEntries.filter((entry) => entry.type === 'warning'),
    consoleErrors: consoleEntries.filter((entry) => entry.type === 'error'),
    failedRequests,
    httpErrors: responses,
    horizontalOverflow: overflow.horizontal,
    overflowDetail: overflow,
    accessibilityNotes: [],
    screenshot,
    result: passed ? 'passed' : 'failed',
  };
  report.browser.pages.push(entry);

  return entry;
}

function recordStep(report, step, result, detail = {}) {
  report.browser.workflow.push({
    step,
    result,
    ...detail,
    recordedAt: new Date().toISOString(),
  });
}

async function pageText(page) {
  return page.$eval('body', (body) => body.innerText);
}

async function submitFormByAction(page, actionPart, values = {}) {
  await page.evaluate(({ actionPart, values }) => {
    const form = Array.from(document.querySelectorAll('form')).find((candidate) => candidate.action.includes(actionPart));
    if (!form) {
      throw new Error(`Form action containing ${actionPart} was not found.`);
    }
    for (const [name, value] of Object.entries(values)) {
      const field = form.querySelector(`[name="${name}"]`);
      if (field) {
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    form.requestSubmit();
  }, { actionPart, values });
  await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 });
}

async function postWithCsrf(page, url, fields = {}) {
  return page.evaluate(async ({ url, fields }) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const body = new URLSearchParams(fields);
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'text/html,application/xhtml+xml,application/json',
      },
      body,
      redirect: 'manual',
    });
    return { status: response.status, location: response.headers.get('location'), text: (await response.text()).slice(0, 500) };
  }, { url, fields });
}

async function discoverDocuments(page) {
  return page.evaluate(() => Array.from(document.querySelectorAll('tbody tr')).map((row) => {
    const link = row.querySelector('a[href*="/documents/"]');
    return {
      title: link?.textContent.trim() || '',
      href: link?.href || '',
      text: row.innerText,
    };
  }).filter((row) => row.href));
}

async function currentVersionUuid(page) {
  const href = await page.$eval('a[href*="/versions/"][href$="/download"]', (link) => link.href);
  const match = href.match(/\/versions\/([0-9a-f-]{36})\/download/i);
  if (!match) {
    throw new Error(`Could not discover current version UUID from ${href}`);
  }

  return match[1];
}

async function createDocument(page, report) {
  progress('workflow: upload document');
  const title = `R3.4 Browser Upload ${Date.now()}`;
  await page.goto(`${APP_URL}/catalog/products/${PRODUCT_UUID}/documents/create`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForSelector('#title', { visible: true, timeout: 15000 });
  await page.select('#document_type', 'certificate');
  await page.type('#title', title);
  await page.type('#description', 'Browser acceptance upload fixture.');
  await page.select('#language', 'en');
  await page.select('#visibility', 'passport_public');
  await page.type('#issuer_name', 'Browser Acceptance Lab');
  await page.type('#issue_date', '2026-07-01');
  await page.type('#expires_at', '2027-07-01');
  const fileInput = await page.waitForSelector('#file', { visible: true, timeout: 15000 });
  await fileInput.uploadFile(FIXTURE_PDF);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }),
    page.$eval('form[enctype="multipart/form-data"]', (form) => form.requestSubmit()),
  ]);

  const documentUuid = page.url().match(/\/documents\/([0-9a-f-]{36})(?:$|[/?#])/i)?.[1];
  if (!documentUuid) {
    throw new Error(`Upload did not redirect to a document detail URL. Current URL: ${page.url()}`);
  }

  const versionUuid = await currentVersionUuid(page);
  const text = await pageText(page);
  recordStep(report, 'Upload PDF document through browser form', text.includes(title) ? 'passed' : 'failed', {
    expected: 'Uploaded PDF is shown as the current document version.',
    actual: text.includes(title) ? 'Uploaded title visible on detail page.' : 'Uploaded title not visible.',
    documentUuid,
    versionUuid,
    title,
  });

  return { title, documentUuid, versionUuid, detailUrl: page.url() };
}

async function runReviewWorkflow(editorPage, ownerPage, report, uploaded) {
  progress('workflow: review submit/cancel/approve');
  await editorPage.goto(uploaded.detailUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await submitFormByAction(editorPage, '/submit-review', { comment: 'Browser submit review.' });
  let text = await pageText(editorPage);
  recordStep(report, 'Submit document review', text.includes('Pending Review') ? 'passed' : 'failed', {
    expected: 'Review state becomes Pending Review.',
    actual: text.match(/Review\s+(.+)/)?.[1]?.slice(0, 80) || 'State not parsed.',
  });

  const unauthorizedApproveUrl = `${APP_URL}/catalog/products/${PRODUCT_UUID}/documents/${uploaded.documentUuid}/versions/${uploaded.versionUuid}/approve`;
  const unauthorized = await postWithCsrf(editorPage, unauthorizedApproveUrl, { comment: 'Unauthorized approval attempt' });
  recordStep(report, 'Direct unauthorized approval request', unauthorized.status === 403 ? 'passed' : 'failed', {
    expected: '403',
    actual: String(unauthorized.status),
    exposure: unauthorized.status === 403 ? 'No approval granted.' : 'Unexpected status.',
  });

  await editorPage.goto(uploaded.detailUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await submitFormByAction(editorPage, '/cancel-review', { comment: 'Browser cancel review.' });
  text = await pageText(editorPage);
  recordStep(report, 'Cancel document review', text.includes('Cancelled') ? 'passed' : 'failed', {
    expected: 'Review state becomes Cancelled.',
    actual: text.includes('Cancelled') ? 'Cancelled visible.' : 'Cancelled not visible.',
  });

  await submitFormByAction(editorPage, '/submit-review', { comment: 'Browser submit review again.' });
  text = await pageText(editorPage);
  recordStep(report, 'Submit document review after cancellation', text.includes('Pending Review') ? 'passed' : 'failed', {
    expected: 'Cancelled version can be resubmitted.',
    actual: text.includes('Pending Review') ? 'Pending Review visible.' : 'Pending Review not visible.',
  });

  await ownerPage.goto(uploaded.detailUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await submitFormByAction(ownerPage, '/approve', { comment: 'Browser owner approval.' });
  text = await pageText(ownerPage);
  recordStep(report, 'Approve document review with separate actor', text.includes('Approved') ? 'passed' : 'failed', {
    expected: 'Review and approval states become Approved.',
    actual: text.includes('Approved') ? 'Approved visible.' : 'Approved not visible.',
  });
}

async function runReplacementReject(editorPage, ownerPage, report, uploaded) {
  progress('workflow: replacement reject');
  await editorPage.goto(uploaded.detailUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await editorPage.waitForSelector('#new_title', { visible: true, timeout: 15000 });
  await editorPage.type('#new_title', `${uploaded.title} Replacement`);
  await editorPage.select('#new_document_type', 'certificate');
  await editorPage.select('#new_language', 'en');
  await editorPage.select('#new_visibility', 'passport_public');
  const fileInput = await editorPage.waitForSelector('#new_file', { visible: true, timeout: 15000 });
  await fileInput.uploadFile(FIXTURE_PDF);
  await Promise.all([
    editorPage.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }),
    editorPage.click('form[action$="/versions"] button[type="submit"]'),
  ]);

  const replacement = await editorPage.evaluate((title) => {
    const rows = Array.from(document.querySelectorAll('tbody tr'));
    const row = rows.find((candidate) => candidate.innerText.includes(`${title} Replacement`));
    const href = row?.querySelector('a[href*="/versions/"][href$="/download"]')?.href || '';
    const uuid = href.match(/\/versions\/([0-9a-f-]{36})\/download/i)?.[1] || null;
    return { uuid, rowText: row?.innerText || '' };
  }, uploaded.title);

  if (!replacement.uuid) {
    recordStep(report, 'Create replacement version', 'failed', {
      expected: 'Replacement version appears in history.',
      actual: 'Replacement version UUID not discovered.',
    });
    return null;
  }

  recordStep(report, 'Create replacement version', 'passed', {
    expected: 'Replacement version appears in version history.',
    actual: replacement.rowText,
    versionUuid: replacement.uuid,
  });

  const submitUrl = `${APP_URL}/catalog/products/${PRODUCT_UUID}/documents/${uploaded.documentUuid}/versions/${replacement.uuid}/submit-review`;
  const rejectUrl = `${APP_URL}/catalog/products/${PRODUCT_UUID}/documents/${uploaded.documentUuid}/versions/${replacement.uuid}/reject`;
  const submit = await postWithCsrf(editorPage, submitUrl, { comment: 'Submit replacement.' });
  await ownerPage.goto(uploaded.detailUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const reject = await postWithCsrf(ownerPage, rejectUrl, { reason: 'Browser rejection reason' });

  recordStep(report, 'Reject replacement version', submit.status < 400 && reject.status < 400 ? 'passed' : 'failed', {
    expected: 'Replacement can be submitted and rejected with a reason.',
    actual: `submit=${submit.status}; reject=${reject.status}`,
    note: 'The replacement review action is posted from the browser context because the current detail page only renders review controls for the current version.',
  });

  return replacement;
}

async function runAccessibilityPass(page, report) {
  progress('accessibility smoke');
  await page.setViewport({ width: 1280, height: 720 });
  await page.goto(`${APP_URL}/catalog/products/${PRODUCT_UUID}/documents/create`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const formLabels = await page.evaluate(() => Array.from(document.querySelectorAll('input, select, textarea')).map((field) => {
    if (field.type === 'hidden') return null;
    const id = field.getAttribute('id');
    const label = id ? document.querySelector(`label[for="${id}"]`)?.textContent.trim() : '';
    return { id, name: field.getAttribute('name'), label: label || field.getAttribute('aria-label') || '' };
  }).filter(Boolean));

  const tabOrder = [];
  await page.keyboard.press('Tab');
  for (let i = 0; i < 18; i += 1) {
    tabOrder.push(await page.evaluate(() => ({
      tag: document.activeElement?.tagName || '',
      name: document.activeElement?.getAttribute('name') || '',
      text: document.activeElement?.textContent?.trim().slice(0, 80) || '',
      visibleFocus: !!document.activeElement && document.activeElement.matches(':focus, :focus-visible'),
    })));
    await page.keyboard.press('Tab');
  }

  await page.goto(`${APP_URL}/catalog/products/${PRODUCT_UUID}/documents`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const statusTexts = await page.$$eval('tbody tr', (rows) => rows.map((row) => row.innerText).filter((text) => /Active|Archived|Expired|Expiring soon/i.test(text)));

  const labelFailures = formLabels.filter((field) => !field.label);
  const keyboardFailures = tabOrder.filter((entry) => !entry.visibleFocus);
  const passed = labelFailures.length === 0 && keyboardFailures.length === 0 && statusTexts.length > 0;

  report.accessibility.push({
    check: 'Keyboard, focus, form labels, status text semantics',
    component: 'Document create form and document list',
    result: passed ? 'passed' : 'failed',
    defectOrFix: passed ? 'No release-blocking issue found in automated accessibility smoke pass.' : 'One or more fields/focus steps/status semantics need review.',
    details: {
      labelFailures,
      keyboardFailures,
      statusTexts: statusTexts.slice(0, 10),
    },
  });
}

async function runSecurityChecks(page, report, uploaded) {
  progress('security smoke');
  const scenarios = [
    {
      scenario: 'Arbitrary document UUID substitution',
      url: `${APP_URL}/catalog/products/${PRODUCT_UUID}/documents/00000000-0000-4000-8000-999999999999`,
      expectedStatus: '404',
    },
    {
      scenario: 'Arbitrary version UUID substitution',
      url: `${APP_URL}/catalog/products/${PRODUCT_UUID}/documents/${uploaded.documentUuid}/versions/00000000-0000-4000-8000-999999999999/download`,
      expectedStatus: '404',
    },
    {
      scenario: 'Direct storage path is not web-served',
      url: `${APP_URL}/storage/framework/testing/disks/product_documents/not-real.pdf`,
      expectedStatus: '403 or 404',
    },
  ];

  for (const scenario of scenarios) {
    const response = await page.goto(scenario.url, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch((error) => ({ status: () => `error: ${error.message}` }));
    const actual = String(typeof response.status === 'function' ? response.status() : response.status);
    const passed = scenario.expectedStatus === '403 or 404'
      ? ['403', '404'].includes(actual)
      : actual === scenario.expectedStatus;
    report.security.push({
      ...scenario,
      actualStatus: actual,
      exposure: passed ? 'No tenant or storage detail exposed.' : 'Unexpected status; review required.',
      result: passed ? 'passed' : 'failed',
    });
  }
}

async function runPublicationSmoke(page, report) {
  progress('publication smoke');
  const publishConfirm = `${APP_URL}/catalog/products/${PRODUCT_UUID}/passport/publish-confirm`;
  await page.goto(publishConfirm, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const publishAvailable = await page.$('form[action*="/passport/publish"] button[type="submit"]') !== null;
  const text = await pageText(page);
  recordStep(report, 'Readiness publish gate', publishAvailable ? 'passed' : 'failed', {
    expected: 'Publish form is available when readiness is not not_ready.',
    actual: publishAvailable ? 'Publish form available.' : text.match(/Cannot publish:.*/)?.[0] || 'Publish form unavailable.',
  });

  if (!publishAvailable) {
    return null;
  }

  const checkbox = await page.$('input[name="acknowledge_warnings"]');
  if (checkbox) {
    await checkbox.click();
  }
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }),
    page.click('form[action*="/passport/publish"] button[type="submit"]'),
  ]);

  const showText = await pageText(page);
  const publicUrl = await page.$$eval('a', (links) => links.find((link) => link.textContent.includes('Open Public Page'))?.href || null).catch(() => null);
  recordStep(report, 'Publish Version 1 browser smoke', showText.includes('Version 1') || showText.includes('published as Version 1') ? 'passed' : 'failed', {
    expected: 'Version 1 is published.',
    actual: showText.includes('Version 1') || showText.includes('published as Version 1') ? 'Version 1 text visible.' : 'Version 1 text not visible.',
    publicUrl,
  });

  if (publicUrl) {
    await page.goto(publicUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const publicText = await pageText(page);
    const publicDocumentLink = await page.$eval('a[href*="/documents/"]', (link) => link.href).catch(() => null);
    recordStep(report, 'Public Passport document visibility', publicText.includes(PRODUCT_NAME) && publicDocumentLink ? 'passed' : 'failed', {
      expected: 'Public Passport renders and exposes only public document download links.',
      actual: `product=${publicText.includes(PRODUCT_NAME)}; documentLink=${publicDocumentLink || 'none'}`,
    });
  }

  return publicUrl;
}

async function main() {
  const report = {
    startedAt: new Date().toISOString(),
    appUrl: APP_URL,
    productUuid: PRODUCT_UUID,
    productName: PRODUCT_NAME,
    status: 'failed',
    commands: [],
    server: null,
    browser: { pages: [], workflow: [] },
    accessibility: [],
    security: [],
    residualRisks: [],
  };

  let server = null;
  let browser = null;

  try {
    progress('resolve PHP');
    const phpBinary = mustRun(report, 'php', ['-r', 'echo PHP_BINARY;']).trim();
    report.phpBinary = phpBinary;
    report.phpVersion = mustRun(report, phpBinary, ['--version']).split(/\r?\n/)[0];
    report.phpIni = mustRun(report, phpBinary, ['--ini']);
    mustRun(report, phpBinary, ['-r', "echo extension_loaded('pdo_mysql') ? 'pdo_mysql=yes' : 'pdo_mysql=no';"]);
    mustRun(report, phpBinary, ['-r', "echo extension_loaded('openssl') ? 'openssl=yes' : 'openssl=no';"]);
    progress('fresh acceptance database');
    mustRun(report, phpBinary, ['artisan', 'migrate:fresh', '--env=acceptance', '--force']);
    mustRun(report, phpBinary, ['artisan', 'db:seed', '--class=R34DocumentsComplianceAcceptanceSeeder', '--env=acceptance']);
    mustRun(report, phpBinary, ['artisan', 'db:seed', '--class=R34DocumentsComplianceAcceptanceSeeder', '--env=acceptance']);

    progress('start server');
    server = await startAcceptanceServer(report, phpBinary);
    report.server = {
      phpBinary: server.phpBinary,
      port: server.port,
      pid: server.pid,
      readyStatus: server.readyStatus,
      loginStatus: server.loginStatus,
      pidPath: server.pidPath,
      stdout: server.stdout,
      stderr: server.stderr,
    };
    progress(`server ready: pid=${server.pid}`);

    for (const probePath of ['/ready', '/login']) {
      const startedAt = new Date().toISOString();
      const response = await fetch(`${APP_URL}${probePath}`, { signal: AbortSignal.timeout(10000) });
      report.commands.push({
        command: `fetch ${probePath}`,
        exitCode: response.status < 500 ? 0 : 1,
        startedAt,
        finishedAt: new Date().toISOString(),
        outputExcerpt: `HTTP ${response.status}`,
        errorExcerpt: '',
      });
      if (response.status >= 500) {
        throw new Error(`${probePath} returned HTTP ${response.status}`);
      }
    }

    progress('launch browser');
    browser = await puppeteer.launch({
      headless: 'new',
      args: ['--no-sandbox', '--disable-setuid-sandbox'],
      timeout: 30000,
    });

    progress('open pages');
    const ownerContext = await browser.createBrowserContext();
    const editorContext = await browser.createBrowserContext();
    const ownerPage = await ownerContext.newPage();
    const editorPage = await editorContext.newPage();
    await login(ownerPage, 'owner@nordipass.local');
    await login(editorPage, 'editor@nordipass.local');
    recordStep(report, 'Authentication and company context', 'passed', {
      expected: 'Owner and editor can authenticate into the seeded acceptance company.',
      actual: `owner=${ownerPage.url()}; editor=${editorPage.url()}`,
    });

    for (const viewport of viewports) {
      await collectPage(ownerPage, report, 'Document list', `${APP_URL}/catalog/products/${PRODUCT_UUID}/documents`, viewport);
      const documents = await discoverDocuments(ownerPage);
      const certificate = documents.find((document) => document.title.includes('R3.4 Certificate')) || documents[0];
      if (!certificate) {
        throw new Error('No seeded document link found.');
      }
      await collectPage(ownerPage, report, 'Document detail and version history', certificate.href, viewport);
      await collectPage(ownerPage, report, 'Upload form', `${APP_URL}/catalog/products/${PRODUCT_UUID}/documents/create`, viewport);
      await collectPage(ownerPage, report, 'Readiness', `${APP_URL}/catalog/products/${PRODUCT_UUID}/passport/readiness`, viewport);
      await collectPage(ownerPage, report, 'Preview', `${APP_URL}/catalog/products/${PRODUCT_UUID}/passport/preview`, viewport);
      await collectPage(ownerPage, report, 'Publish confirmation', `${APP_URL}/catalog/products/${PRODUCT_UUID}/passport/publish-confirm`, viewport);
    }

    const publicUrl = await runPublicationSmoke(ownerPage, report);
    if (publicUrl) {
      for (const viewport of viewports) {
        await collectPage(ownerPage, report, 'Public Passport', publicUrl, viewport);
      }
    }

    const uploaded = await createDocument(editorPage, report);
    await runReviewWorkflow(editorPage, ownerPage, report, uploaded);
    await runReplacementReject(editorPage, ownerPage, report, uploaded);
    await runSecurityChecks(ownerPage, report, uploaded);
    await runAccessibilityPass(ownerPage, report);

    const pageFailures = report.browser.pages.filter((entry) => entry.result !== 'passed');
    const workflowFailures = report.browser.workflow.filter((entry) => entry.result !== 'passed');
    const accessibilityFailures = report.accessibility.filter((entry) => entry.result !== 'passed');
    const securityFailures = report.security.filter((entry) => entry.result !== 'passed');

    report.status = pageFailures.length === 0
      && workflowFailures.length === 0
      && accessibilityFailures.length === 0
      && securityFailures.length === 0
      ? 'passed'
      : 'failed';

    if (report.status !== 'passed') {
      report.residualRisks.push({
        risk: 'One or more browser, security, or accessibility acceptance checks failed.',
        disposition: 'R3.4 remains rejected until remediated and rerun.',
      });
    }
  } finally {
    report.finishedAt = new Date().toISOString();
    if (browser) {
      await browser.close();
    }
    if (server?.pid) {
      if (server.process && !server.process.killed) {
        server.process.kill();
        await new Promise((resolve) => {
          server.process.once('exit', resolve);
          setTimeout(resolve, 2000);
        });
      }

      const stop = command('taskkill.exe', ['/PID', String(server.pid), '/T', '/F']);
      report.commands.push(redactCommandResult(stop));
      for (const file of [server.pidPath, server.stdout, server.stderr]) {
        if (file && fs.existsSync(file)) {
          fs.rmSync(file, { force: true });
        }
      }
    }
    fs.writeFileSync(REPORT_PATH, JSON.stringify(report, null, 2));
    console.log(JSON.stringify({
      status: report.status,
      report: REPORT_PATH,
      screenshots: EVIDENCE_DIR,
      pages: report.browser.pages.length,
      workflowSteps: report.browser.workflow.length,
      accessibilityChecks: report.accessibility.length,
      securityChecks: report.security.length,
    }, null, 2));
  }

  if (report.status !== 'passed') {
    process.exitCode = 1;
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
