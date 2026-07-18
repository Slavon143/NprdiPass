import fs from 'fs';
import path from 'path';
import { APP_URL, TIMEOUTS } from '../config/demo.config.js';
import { buttons, feedback } from '../config/selectors.js';

export async function uploadImage(page, imagePath, productUuid) {
  const inputHandle = await page.waitForSelector('input[type="file"]', {
    visible: true,
    timeout: TIMEOUTS.default,
  });

  await inputHandle.uploadFile(imagePath);

  const uploadBtn = await page.$(buttons.addImage);
  if (uploadBtn) {
    await uploadBtn.click();
  }

  try {
    await page.waitForFunction(
      () => {
        const thumb = document.querySelector('img[src*="media"], img[src*="storage"]');
        const toast = document.querySelector('[role="alert"]');
        return thumb || toast;
      },
      { timeout: TIMEOUTS.default }
    );
  } catch {
  }

  return {
    filePath: imagePath,
    filename: path.basename(imagePath),
    uploadedAt: new Date().toISOString(),
  };
}

export async function uploadDocument(page, filePath, formData) {
  const { title, type, language, visibility } = formData;

  if (title) {
    const titleField = await page.waitForSelector('#title, input[name="title"]', {
      visible: true,
      timeout: TIMEOUTS.default,
    });
    await titleField.type(title);
  }

  if (type) {
    await page.select('select[name="type"], #type', type);
  }

  if (language) {
    await page.select('select[name="language"], #language', language);
  }

  if (visibility) {
    await page.select('select[name="visibility"], #visibility', visibility);
  }

  const fileInput = await page.waitForSelector('input[type="file"]', {
    visible: true,
    timeout: TIMEOUTS.default,
  });
  await fileInput.uploadFile(filePath);

  const submitBtn = await page.$(buttons.save);
  if (submitBtn) {
    await submitBtn.click();
  }

  try {
    await page.waitForFunction(
      () => {
        const toast = document.querySelector('[role="alert"]');
        const redirect = !window.location.href.includes('create');
        return toast || redirect;
      },
      { timeout: TIMEOUTS.default }
    );
  } catch {
  }

  return {
    title: title || path.basename(filePath),
    filePath,
    uploadedAt: new Date().toISOString(),
  };
}

export async function setPrimaryImage(page, imageIndex) {
  const images = await page.$$('img[src*="media"], img[src*="storage"], .image-thumbnail, .media-item');
  if (images.length === 0) {
    throw new Error('No images found to set as primary');
  }

  if (imageIndex >= images.length) {
    throw new Error(`Image index ${imageIndex} out of bounds (found ${images.length} images)`);
  }

  const target = images[imageIndex];
  await target.click();

  try {
    const makePrimaryBtn = await page.waitForSelector(
      'button::-p-text("Make primary"), button::-p-text("Set as primary"), a::-p-text("Set as primary")',
      { visible: true, timeout: 5000 }
    );
    await makePrimaryBtn.click();
  } catch {
    const checkbox = await page.$('input[type="checkbox"][value*="primary"], input[type="radio"][value*="primary"]');
    if (checkbox) {
      await checkbox.click();
    }
  }

  await new Promise((resolve) => setTimeout(resolve, 1000));
}

export async function generatePlaceholderImage(filePath, text, color) {
  const dir = path.dirname(filePath);
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }

  const minimalJpeg = Buffer.from(
    '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAAoACgDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYI4Q/SFhSRFJiMio/SR2UvKCk0M0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oADAMBAAIRAxEAPwA=',
    'base64'
  );
  fs.writeFileSync(filePath, minimalJpeg);

  return filePath;
}
