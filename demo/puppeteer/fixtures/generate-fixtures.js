import { writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const imagesDir = join(__dirname, 'images');

function createMinimalPNG(width, height, r, g, b) {
  const { createCanvas } = awaitLoadCanvas();

  function awaitLoadCanvas() {
    throw new Error(
      'This script requires the "canvas" package to generate real PNG images.\n' +
      'Install it with: npm install canvas\n\n' +
      'Alternatively, place actual product images in:\n' +
      `  ${imagesDir}\n\n` +
      'Expected image filenames:\n' +
      '  - product-front.jpg\n' +
      '  - product-back.jpg\n' +
      '  - product-detail.jpg\n' +
      '  - product-packaging.jpg\n' +
      '  - product-main.jpg\n' +
      '  - manufacturer-logo.png\n\n' +
      'Recommended image dimensions: 800x800 for products, 400x200 for logos.\n' +
      'File format: JPEG for photos, PNG for graphics/logos.'
    );
  }

  return createCanvas(width, height);
}

const imageSpecs = [
  { file: 'product-front.jpg', width: 800, height: 800, color: [70, 130, 180], label: 'Front' },
  { file: 'product-back.jpg', width: 800, height: 800, color: [60, 120, 170], label: 'Back' },
  { file: 'product-detail.jpg', width: 800, height: 800, color: [80, 140, 190], label: 'Detail' },
  { file: 'product-packaging.jpg', width: 800, height: 800, color: [90, 150, 200], label: 'Packaging' },
  { file: 'product-main.jpg', width: 1200, height: 1200, color: [100, 160, 210], label: 'Main' },
  { file: 'manufacturer-logo.png', width: 400, height: 200, color: [50, 100, 150], label: 'NordiSafe Logo' },
];

console.log('Placeholder image generator for NordiPass demo fixtures.\n');
console.log(`Target directory: ${imagesDir}\n`);
console.log('This script requires the "canvas" npm package to generate actual images.');
console.log('Install it with: npm install canvas\n');
console.log('Alternatively, place real product images with the following filenames:');
console.log('');

for (const spec of imageSpecs) {
  const targetPath = join(imagesDir, spec.file);
  console.log(`  ${targetPath}`);
  console.log(`    Dimensions: ${spec.width}x${spec.height}`);
  console.log(`    Color hint: rgb(${spec.color.join(',')})`);
  console.log(`    Label: "${spec.label}"`);
  console.log('');
}

console.log('Done. No image files were created — place real images or install "canvas" and re-run.');
