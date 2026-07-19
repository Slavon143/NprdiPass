import { CI_MODE } from '../config/demo.config.js';

export async function highlightElement(page, selectorOrElement) {
  const element = typeof selectorOrElement === 'string'
    ? await page.$(selectorOrElement)
    : selectorOrElement;
  if (!element) {
    return null;
  }
  await page.evaluate((el) => {
    el.style.outline = '3px solid #ff3b30';
    el.style.outlineOffset = '3px';
  }, element);
  await new Promise((resolve) => setTimeout(resolve, 800));
  await page.evaluate((el) => {
    el.style.outline = '';
    el.style.outlineOffset = '';
  }, element);
  return element;
}

export async function showCaption(caption) {
  const line = '═'.repeat(Math.min(caption.length + 6, 80));
  console.log('');
  console.log(line);
  console.log(`  ${caption}`);
  console.log(line);
  console.log('');

  if (!CI_MODE) {
    try {
      await page.evaluate((text) => {
        const overlay = document.createElement('div');
        overlay.textContent = text;
        overlay.style.cssText = `
          position: fixed;
          bottom: 40px;
          left: 50%;
          transform: translateX(-50%);
          background: rgba(0, 0, 0, 0.82);
          color: #fff;
          padding: 14px 28px;
          border-radius: 10px;
          font-size: 18px;
          font-family: system-ui, sans-serif;
          z-index: 99999;
          pointer-events: none;
          white-space: nowrap;
        `;
        document.body.appendChild(overlay);
        setTimeout(() => overlay.remove(), 2500);
      }, caption);
    } catch {
    }
  }
}
