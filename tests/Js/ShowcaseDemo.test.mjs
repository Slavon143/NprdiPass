import test from 'node:test';
import assert from 'node:assert/strict';
import { createDemoState, transitionDemo, demoProducts, demoPreview } from '../../resources/js/showcase-demo.js';

test('a demo version cannot be created before resolving the blocker', () => {
    const initial = createDemoState();
    assert.equal(transitionDemo(initial, 'publish').version, false);
    assert.equal(demoProducts(initial)[0].blockers, 1);
    const ready = transitionDemo(initial, 'fix');
    assert.equal(transitionDemo(ready, 'publish').version, true);
    assert.equal(demoProducts(ready)[0].blockers, 0);
    assert.equal(initial.assessment, false);
});

test('fixed sample imports require preview, are idempotent, and never accept caller data', () => {
    const initial = createDemoState();
    assert.equal(demoProducts(transitionDemo(initial, 'apply')).length, 5);
    const preview = transitionDemo(initial, 'previewFortnox');
    assert.equal(demoProducts(preview).length, 5);
    assert.equal(demoPreview(preview).length, 2);
    const imported = transitionDemo(preview, 'apply');
    assert.equal(demoProducts(imported).length, 7);
    assert.equal(demoProducts(transitionDemo(imported, 'apply')).length, 7);
    assert.equal(transitionDemo(initial, { type: 'apply', company_id: 1, rows: [{ name: 'private' }] }), initial);
});

test('reset and a second visitor have no prior publication or import state', () => {
    const changed = transitionDemo(transitionDemo(transitionDemo(createDemoState(), 'previewCsv'), 'apply'), 'fix');
    const published = transitionDemo(changed, 'publish');
    assert.equal(published.version, true);
    assert.deepEqual(transitionDemo(published, 'reset'), createDemoState());
    assert.equal(demoProducts(createDemoState()).length, 5);
    assert.equal(createDemoState().version, false);
});

test('changing returned sample rows cannot modify another visitor or source example', () => {
    const products = demoProducts(createDemoState());
    products[0].name = 'private';
    const preview = transitionDemo(createDemoState(), 'previewCsv');
    demoPreview(preview)[0].name = 'private';
    assert.equal(demoProducts(createDemoState())[0].name, 'Industrial Storage Case');
    assert.equal(demoPreview(preview)[0].name, 'Sample CSV Workbench');
});
