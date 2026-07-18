import fs from 'fs';
import path from 'path';
import { OUTPUT_DIRS, CI_MODE } from '../config/demo.config.js';

export async function takeScreenshot(page, stepName, outputDir) {
  const dir = outputDir || OUTPUT_DIRS.screenshots;
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
  const filePath = path.resolve(dir, `${stepName}.png`);
  await page.screenshot({ path: filePath, fullPage: true });
  return filePath;
}

export async function saveErrorState(page, stepName, outputDir, error) {
  const dir = outputDir || OUTPUT_DIRS.screenshots;
  const errorDir = path.resolve(dir, 'errors');
  if (!fs.existsSync(errorDir)) {
    fs.mkdirSync(errorDir, { recursive: true });
  }

  const screenshotPath = path.resolve(errorDir, `step-${stepName}.png`);
  const htmlPath = path.resolve(errorDir, `step-${stepName}.html`);
  const url = page.url();

  await page.screenshot({ path: screenshotPath, fullPage: true });

  const html = await page.content();
  fs.writeFileSync(htmlPath, html, 'utf-8');

  const errorReport = {
    step: stepName,
    url,
    error: error ? error.message : 'Unknown error',
    timestamp: new Date().toISOString(),
    screenshotPath,
    htmlPath,
  };

  const reportPath = path.resolve(errorDir, `step-${stepName}.json`);
  fs.writeFileSync(reportPath, JSON.stringify(errorReport, null, 2), 'utf-8');

  return errorReport;
}

export async function takeScreenshotWithOverlay(page, stepName, text) {
  if (!CI_MODE) {
    try {
      await page.evaluate((overlayText) => {
        const el = document.createElement('div');
        el.textContent = overlayText;
        el.style.cssText = `
          position: fixed;
          top: 20px;
          right: 20px;
          background: rgba(0, 0, 0, 0.75);
          color: #fff;
          padding: 10px 20px;
          border-radius: 6px;
          font-size: 16px;
          font-family: system-ui, sans-serif;
          z-index: 99999;
          pointer-events: none;
        `;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 3000);
      }, text);
      await new Promise((resolve) => setTimeout(resolve, 300));
    } catch {
    }
  }

  return takeScreenshot(page, stepName);
}
