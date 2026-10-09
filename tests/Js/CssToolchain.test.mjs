import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const postcss = require('postcss');
const tailwind = require('tailwindcss');
const forms = require('@tailwindcss/forms');
const nested = require('postcss-nested');

async function utilities(content) {
    const result = await postcss([tailwind({
        content: [{ raw: content, extension: 'html' }],
        corePlugins: { preflight: false },
        plugins: [forms({ strategy: 'class' })],
    })]).process('@tailwind components; @tailwind utilities;', { from: undefined });
    return result.root;
}

function rules(root, predicate) {
    const found = [];
    root.walkRules(rule => { if (predicate(rule)) found.push(rule); });
    return found;
}

function hasDeclaration(rule, property, value) {
    return rule.nodes.some(node => node.type === 'decl'
        && node.prop === property && node.value === value);
}

test('responsive layout and escaped arbitrary widths preserve their declarations', async () => {
    const root = await utilities('md:grid-cols-2 w-[calc(100%-1rem)]');
    const grid = rules(root, rule => rule.selector === '.md\\:grid-cols-2');
    assert.equal(grid.length, 1);
    assert.equal(grid[0].parent.type, 'atrule');
    assert.equal(grid[0].parent.params, '(min-width: 768px)');
    assert.ok(hasDeclaration(grid[0], 'grid-template-columns', 'repeat(2, minmax(0, 1fr))'));
    assert.ok(rules(root, rule => hasDeclaration(rule, 'width', 'calc(100% - 1rem)')).length);
});

test('group peer and arbitrary descendant variants keep their relationships', async () => {
    const root = await utilities('group-hover:block peer-checked:block [&>svg]:h-4');
    assert.ok(rules(root, rule => rule.selector === '.group:hover .group-hover\\:block'
        && hasDeclaration(rule, 'display', 'block')).length);
    assert.ok(rules(root, rule => rule.selector === '.peer:checked ~ .peer-checked\\:block'
        && hasDeclaration(rule, 'display', 'block')).length);
    assert.ok(rules(root, rule => rule.selector.endsWith('>svg')
        && hasDeclaration(rule, 'height', '1rem')).length);
});

test('form controls retain their reset and focus styles', async () => {
    const root = await utilities('form-input form-checkbox');
    assert.ok(rules(root, rule => rule.selector.split(',').map(s => s.trim()).includes('.form-input')
        && hasDeclaration(rule, 'appearance', 'none')).length);
    assert.ok(rules(root, rule => rule.selector.includes('.form-input:focus')
        && hasDeclaration(rule, 'border-color', '#2563eb')).length);
    assert.ok(rules(root, rule => rule.selector.includes('.form-checkbox')
        && hasDeclaration(rule, 'appearance', 'none')).length);
});

test('nested comma selectors expand every parent without lost or duplicate branches', async () => {
    const result = await postcss([nested]).process(
        '.a, .b { &:hover { color: red } > svg { height: 1rem } }', { from: undefined },
    );
    const branches = rules(result.root, () => true).flatMap(rule => rule.selectors);
    assert.deepEqual(branches.sort(), ['.a:hover', '.b:hover', '.a > svg', '.b > svg'].sort());
});
