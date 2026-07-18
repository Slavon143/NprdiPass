import puppeteer from 'puppeteer';
import { BROWSER_CONFIG } from '../config/demo.config.js';

export async function launchBrowser() {
  const browser = await puppeteer.launch(BROWSER_CONFIG);
  return browser;
}

export async function createIncognitoContext(browser) {
  const context = await browser.createBrowserContext();
  return context;
}

export async function closeBrowser(browser) {
  await browser.close();
}
