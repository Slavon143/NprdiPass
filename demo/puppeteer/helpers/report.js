import fs from 'fs';
import path from 'path';
import { OUTPUT_DIRS, DEMO_COMPANY_NAME } from '../config/demo.config.js';

export function createReport(steps, runId) {
  const report = {
    status: 'in_progress',
    startedAt: new Date().toISOString(),
    finishedAt: null,
    runId: runId || 'N/A',
    company: DEMO_COMPANY_NAME,
    productName: null,
    steps: [],
    counts: {
      total: steps ? steps.length : 0,
      passed: 0,
      failed: 0,
      skipped: 0,
      categories: 0,
      attributes: 0,
      products: 0,
      variants: 0,
      images: 0,
      documents: 0,
    },
  };

  if (steps) {
    report.steps = steps.map((s) => ({
      name: s.name || s.stepName || '',
      status: s.status || 'pending',
      details: s.details || null,
    }));
  }

  return report;
}

export function recordStep(report, stepName, status, details = null) {
  const existing = report.steps.find((s) => s.name === stepName);
  if (existing) {
    existing.status = status;
    if (details) existing.details = { ...existing.details, ...details };
  } else {
    report.steps.push({ name: stepName, status, details });
  }

  report.counts.total = report.steps.length;
  report.counts.passed = report.steps.filter((s) => s.status === 'passed').length;
  report.counts.failed = report.steps.filter((s) => s.status === 'failed').length;
  report.counts.skipped = report.steps.filter((s) => s.status === 'skipped').length;

  if (details && details.createdItem) {
    const { createdItem } = details;
    if (createdItem === 'category' || createdItem === 'categories') report.counts.categories++;
    if (createdItem === 'attribute' || createdItem === 'attributes') report.counts.attributes++;
    if (createdItem === 'product' || createdItem === 'products') report.counts.products++;
    if (createdItem === 'variant' || createdItem === 'variants') report.counts.variants++;
    if (createdItem === 'image' || createdItem === 'images') report.counts.images++;
    if (createdItem === 'document' || createdItem === 'documents') report.counts.documents++;
  }

  return report;
}

export function finalizeReport(report, outputDir) {
  const hadFailure = report.steps.some((s) => s.status === 'failed');
  report.status = hadFailure ? 'failed' : 'passed';
  report.finishedAt = new Date().toISOString();

  const dir = outputDir || OUTPUT_DIRS.screenshots;
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }

  const jsonPath = path.resolve(dir, '..', 'report.json');
  fs.writeFileSync(jsonPath, JSON.stringify(report, null, 2), 'utf-8');

  const htmlRows = report.steps.map((s) => {
    const icon = s.status === 'passed' ? '✓' : s.status === 'failed' ? '✗' : '○';
    const color = s.status === 'passed' ? '#2ecc71' : s.status === 'failed' ? '#e74c3c' : '#95a5a6';
    return `<tr>
      <td style="color:${color}; font-weight:bold">${icon}</td>
      <td>${s.name}</td>
      <td style="color:${color}">${s.status}</td>
    </tr>`;
  }).join('');

  const html = `<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>NordiPass Demo Report — ${report.runId}</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
    .header { background: #2c3e50; color: #fff; padding: 24px 32px; border-radius: 8px; margin-bottom: 24px; }
    .header h1 { margin: 0 0 8px; font-size: 24px; }
    .header p { margin: 4px 0; opacity: 0.85; font-size: 14px; }
    .summary { display: flex; gap: 16px; margin-bottom: 24px; }
    .summary-card { flex: 1; background: #fff; padding: 16px 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
    .summary-card .count { font-size: 32px; font-weight: bold; }
    .summary-card .label { font-size: 12px; color: #666; text-transform: uppercase; }
    .passed { color: #2ecc71; }
    .failed { color: #e74c3c; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    th { background: #ecf0f1; padding: 12px 16px; text-align: left; font-size: 12px; text-transform: uppercase; color: #555; }
    td { padding: 10px 16px; border-top: 1px solid #eee; font-size: 14px; }
  </style>
</head>
<body>
  <div class="header">
    <h1>NordiPass Demo Report</h1>
    <p>Run ID: ${report.runId} | Company: ${report.company}</p>
    <p>Started: ${report.startedAt} | Finished: ${report.finishedAt}</p>
  </div>
  <div class="summary">
    <div class="summary-card">
      <div class="count passed">${report.counts.passed}</div>
      <div class="label">Passed</div>
    </div>
    <div class="summary-card">
      <div class="count failed">${report.counts.failed}</div>
      <div class="label">Failed</div>
    </div>
    <div class="summary-card">
      <div class="count">${report.counts.skipped}</div>
      <div class="label">Skipped</div>
    </div>
    <div class="summary-card">
      <div class="count">${report.counts.total}</div>
      <div class="label">Total</div>
    </div>
  </div>
  <table>
    <thead>
      <tr><th></th><th>Step</th><th>Status</th></tr>
    </thead>
    <tbody>${htmlRows}</tbody>
  </table>
</body>
</html>`;

  const htmlPath = path.resolve(dir, '..', 'report.html');
  fs.writeFileSync(htmlPath, html, 'utf-8');

  const summary = `Report: ${report.runId} — ${report.status.toUpperCase()}
  ${report.counts.passed} passed, ${report.counts.failed} failed, ${report.counts.skipped} skipped (${report.counts.total} total)
  Categories: ${report.counts.categories} | Attributes: ${report.counts.attributes} | Products: ${report.counts.products}
  Variants: ${report.counts.variants} | Images: ${report.counts.images} | Documents: ${report.counts.documents}`;

  console.log('');
  console.log('═'.repeat(60));
  console.log(summary);
  console.log('═'.repeat(60));
  console.log(`JSON: ${jsonPath}`);
  console.log(`HTML: ${htmlPath}`);

  return report;
}
